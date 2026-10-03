<?php

namespace App\Service\Magasin;

use App\Entity\Magasin\MagasinEmplacement;
use App\Entity\Magasin\MagasinInventaire;
use App\Entity\Magasin\MagasinLigneInventaire;
use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Enum\Magasin\TypeMouvementMagasin;
use App\Repository\Magasin\MagasinEmplacementRepository;
use App\Repository\Magasin\MagasinInventaireRepository;
use App\Repository\Magasin\MagasinProduitRepository;
use App\Service\AuditLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Inventaire du magasin : ouverture, comptage, validation, abandon.
 *
 * **L'écart est appliqué en delta, jamais en écrasant le stock par le compté.**
 * Entre le comptage en réserve et la validation à l'écran, une sortie a pu être
 * validée : poser le compté tel quel l'effacerait. Le comptage constate un écart
 * à l'instant de l'ouverture ; c'est cet écart qu'on reporte, en un mouvement
 * AJUSTEMENT_INVENTAIRE par ligne, tout ou rien — refusé si un écart laissait
 * un stock négatif.
 *
 * Le **premier inventaire** fait le stock de départ : sur une base vierge, le
 * théorique est nul partout et chaque compté devient un ajustement positif.
 *
 * Un ajustement ne change pas le coût moyen : il corrige une quantité, il n'est
 * pas un achat.
 */
class InventaireMagasinService
{
    public const DOCUMENT = 'inventaire';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MagasinInventaireRepository $inventaires,
        private readonly MagasinProduitRepository $produits,
        private readonly MagasinEmplacementRepository $emplacements,
        private readonly MagasinStockService $stock,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Ouvre une feuille : tout le magasin (emplacement nul) ou un seul emplacement.
     *
     * Lignes : chaque produit actif dans chaque emplacement actif de la portée —
     * y compris ceux qui n'y sont pas, pour qu'un premier inventaire puisse les y
     * trouver — plus tout produit, même inactif, qui y a du stock.
     *
     * @throws \DomainException une feuille en cours couvre déjà cette portée
     */
    public function ouvrir(?MagasinEmplacement $emplacement, Utilisateur $auteur): MagasinInventaire
    {
        $connexion = $this->connexion();
        $connexion->beginTransaction();

        try {
            // Sérialise les ouvertures : deux clics simultanés n'ouvrent pas deux feuilles.
            $connexion->fetchOne('SELECT id FROM magasin_emplacement WHERE id = ? FOR UPDATE', [$this->stock->reserveParDefaut()->getId()]);

            $conflit = $this->inventaires->conflit($emplacement);
            if (null !== $conflit) {
                throw new \DomainException(\sprintf('L\'inventaire %s (%s) est encore en cours : validez-le ou abandonnez-le d\'abord.', $conflit->getNumero(), $conflit->getPortee()));
            }
            if (null !== $emplacement && !$emplacement->isActif()) {
                throw new \DomainException(\sprintf('L\'emplacement « %s » est désactivé.', $emplacement->getLibelle()));
            }

            $inventaire = new MagasinInventaire($this->inventaires->prochainNumero(new \DateTimeImmutable()), $emplacement, $auteur);
            $portee = null !== $emplacement ? [$emplacement] : $this->emplacements->actifs();
            $stocks = $this->stocksDansLaPortee($portee);
            $produits = $this->produits->findBy([], ['nom' => 'ASC']);

            foreach ($portee as $lieu) {
                foreach ($produits as $produit) {
                    $quantite = $stocks[(int) $produit->getId()][(int) $lieu->getId()] ?? 0;
                    if ($produit->isActif() || 0 !== $quantite) {
                        $inventaire->ajouterLigne($produit, $lieu, $quantite);
                    }
                }
            }
            if ($inventaire->getLignes()->isEmpty()) {
                throw new \DomainException('Aucun produit à compter : créez d\'abord les produits du magasin.');
            }

            $this->em->persist($inventaire);
            $this->em->flush();
            $this->audit->enregistrer(ActionAudit::MAGASIN_INVENTAIRE_OUVERT, 'MagasinInventaire', $inventaire->getId(), null, [
                'numero' => $inventaire->getNumero(),
                'portee' => $inventaire->getPortee(),
                'lignes' => $inventaire->getLignes()->count(),
            ], $auteur);
            $connexion->commit();

            return $inventaire;
        } catch (\Throwable $e) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }

            throw $e;
        }
    }

    /**
     * @param array<int, array{quantite: ?int, enUniteAchat: bool}> $comptages par id de ligne
     */
    public function enregistrerComptage(MagasinInventaire $inventaire, array $comptages, ?string $commentaire): void
    {
        $inventaire->compter($comptages, $commentaire);
        $this->em->flush();
    }

    /**
     * Applique les écarts et fige la feuille. Une transaction, tout ou rien.
     *
     * @throws \DomainException                  rien de compté, écart sans commentaire, écart sans le droit
     * @throws StockMagasinInsuffisantException un écart laisserait un stock négatif
     */
    public function valider(MagasinInventaire $inventaire, bool $peutValiderUnEcart, Utilisateur $auteur): void
    {
        $inventaire->verifierValidable($peutValiderUnEcart);
        $connexion = $this->connexion();
        $connexion->beginTransaction();

        try {
            $ecarts = $inventaire->lignesEnEcart();
            $this->stock->appliquer(array_map(
                fn (MagasinLigneInventaire $ligne): DemandeMouvementMagasin => new DemandeMouvementMagasin(
                    $ligne->getProduit(),
                    $ligne->getEmplacement(),
                    (int) $ligne->getEcart(),
                    TypeMouvementMagasin::AJUSTEMENT_INVENTAIRE,
                    self::DOCUMENT,
                    (int) $inventaire->getId(),
                    'Inventaire '.$inventaire->getNumero(),
                ),
                $ecarts,
            ), $auteur);

            $inventaire->valider($peutValiderUnEcart, $auteur, new \DateTimeImmutable());
            $this->em->flush();

            $etat = [
                'numero' => $inventaire->getNumero(),
                'portee' => $inventaire->getPortee(),
                'lignesComptees' => \count($inventaire->lignesComptees()),
                'commentaire' => $inventaire->getCommentaire(),
            ];
            $this->audit->enregistrer(ActionAudit::MAGASIN_INVENTAIRE_VALIDE, 'MagasinInventaire', $inventaire->getId(), null, $etat, $auteur);
            // Comme une clôture de caisse : l'écart a sa propre entrée, pour se
            // filtrer seul au journal.
            if ([] !== $ecarts) {
                $this->audit->enregistrer(ActionAudit::MAGASIN_ECART_INVENTAIRE, 'MagasinInventaire', $inventaire->getId(), null, $etat + [
                    'valeurEcarts' => $inventaire->getValeurEcarts(),
                    'ecarts' => array_map(static fn (MagasinLigneInventaire $l): array => [
                        'produit' => $l->getProduit()->getNom(),
                        'emplacement' => $l->getEmplacement()->getCode(),
                        'theorique' => $l->getQteTheorique(),
                        'compte' => $l->getQteComptee(),
                        'ecart' => $l->getEcart(),
                    ], $ecarts),
                ], $auteur);
            }
            $connexion->commit();
        } catch (\Throwable $e) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }
            if ($this->em->isOpen() && $this->em->contains($inventaire)) {
                $this->em->refresh($inventaire);
            }

            throw $e;
        }
    }

    /** @throws \DomainException feuille déjà validée ou abandonnée */
    public function abandonner(MagasinInventaire $inventaire, Utilisateur $auteur): void
    {
        $inventaire->abandonner($auteur, new \DateTimeImmutable());
        $this->em->flush();
        $this->audit->enregistrer(ActionAudit::MAGASIN_INVENTAIRE_ABANDONNE, 'MagasinInventaire', $inventaire->getId(), null, [
            'numero' => $inventaire->getNumero(),
            'portee' => $inventaire->getPortee(),
        ], $auteur);
    }

    /**
     * Comptages saisis à l'écran. Case vide = pas compté.
     *
     * @param array<int|string, mixed> $saisie par id de ligne : [quantite, unite]
     *
     * @return array<int, array{quantite: ?int, enUniteAchat: bool}>
     *
     * @throws \DomainException saisie illisible
     */
    public function lireComptages(MagasinInventaire $inventaire, array $saisie): array
    {
        $comptages = [];
        foreach ($inventaire->getLignes() as $ligne) {
            $valeurs = $saisie[$ligne->getId()] ?? null;
            if (!\is_array($valeurs)) {
                continue;
            }
            $comptages[(int) $ligne->getId()] = [
                'quantite' => SaisieQuantite::millimes($valeurs['quantite'] ?? '', $ligne->getProduit()->getNom()),
                'enUniteAchat' => 'stock' !== ($valeurs['unite'] ?? 'achat'),
            ];
        }

        return $comptages;
    }

    /**
     * @param list<MagasinEmplacement> $portee
     *
     * @return array<int, array<int, int>> [produit][emplacement] = millièmes
     */
    private function stocksDansLaPortee(array $portee): array
    {
        $ids = array_map(static fn (MagasinEmplacement $e): int => (int) $e->getId(), $portee);
        $stocks = [];
        foreach ($this->connexion()->fetchAllAssociative(
            'SELECT produit_id, emplacement_id, quantite FROM magasin_stock WHERE emplacement_id IN (?)',
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        ) as $ligne) {
            $stocks[(int) $ligne['produit_id']][(int) $ligne['emplacement_id']] = (int) $ligne['quantite'];
        }

        return $stocks;
    }

    private function connexion(): Connection
    {
        return $this->em->getConnection();
    }
}
