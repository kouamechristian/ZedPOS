<?php

namespace App\Service\Magasin;

use App\Entity\Magasin\MagasinLigneSortie;
use App\Entity\Magasin\MagasinSortie;
use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Enum\Magasin\DestinationSortieMagasin;
use App\Enum\Magasin\MotifSortieMagasin;
use App\Enum\Magasin\TypeMouvementMagasin;
use App\Repository\Magasin\MagasinEmplacementRepository;
use App\Repository\Magasin\MagasinMouvementRepository;
use App\Repository\Magasin\MagasinProduitRepository;
use App\Repository\Magasin\MagasinSortieRepository;
use App\Service\AuditLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les sorties du magasin : brouillon, validation, annulation.
 *
 * **La validation est la seule étape qui touche au stock.** Une transaction :
 * le bon est figé (coût moyen de chaque produit noté sur ses lignes), puis chaque
 * ligne sort de son emplacement par un mouvement SORTIE, en un lot. Le contrôle
 * du stock disponible est celui de {@see MagasinStockService} — sur le cumul du
 * lot, avant toute écriture : deux lignes du même produit qui ensemble dépassent
 * le stock sont refusées, et rien n'est écrit.
 *
 * Une sortie ne change pas le coût moyen : on sort au prix moyen, la moyenne
 * reste la même.
 *
 * **L'annulation** d'une sortie validée contre-passe ses mouvements (type
 * ANNULATION) : la marchandise rentre dans les emplacements d'où elle était sortie.
 * Un brouillon s'annule sans mouvement.
 */
class SortieMagasinService
{
    public const DOCUMENT = 'sortie';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MagasinSortieRepository $sorties,
        private readonly MagasinProduitRepository $produits,
        private readonly MagasinEmplacementRepository $emplacements,
        private readonly MagasinMouvementRepository $mouvements,
        private readonly MagasinStockService $stock,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param list<array{produit: \App\Entity\Magasin\MagasinProduit, emplacement: \App\Entity\Magasin\MagasinEmplacement, quantite: int, enUniteAchat: bool}> $lignes
     *
     * @throws \DomainException
     */
    public function creer(\DateTimeImmutable $date, DestinationSortieMagasin $destination, MotifSortieMagasin $motif, ?string $demandePar, ?string $commentaire, array $lignes, Utilisateur $auteur): MagasinSortie
    {
        return $this->transaction(null, function () use ($date, $destination, $motif, $demandePar, $commentaire, $lignes, $auteur): MagasinSortie {
            // Verrou sur la réserve : deux sorties créées à la même seconde ne
            // calculent pas le même numéro.
            $this->connexion()->fetchOne('SELECT id FROM magasin_emplacement WHERE id = ? FOR UPDATE', [$this->stock->reserveParDefaut()->getId()]);

            $sortie = new MagasinSortie($this->sorties->prochainNumero($date), $date, $destination, $motif, $auteur);
            $sortie->definir($date, $destination, $motif, $demandePar, $commentaire, $lignes);
            $this->em->persist($sortie);
            $this->em->flush();

            $this->tracer(ActionAudit::MAGASIN_SORTIE_ENREGISTREE, $sortie, null, $auteur);

            return $sortie;
        });
    }

    /**
     * @param list<array{produit: \App\Entity\Magasin\MagasinProduit, emplacement: \App\Entity\Magasin\MagasinEmplacement, quantite: int, enUniteAchat: bool}> $lignes
     *
     * @throws \DomainException sortie déjà validée ou annulée
     */
    public function modifier(MagasinSortie $sortie, \DateTimeImmutable $date, DestinationSortieMagasin $destination, MotifSortieMagasin $motif, ?string $demandePar, ?string $commentaire, array $lignes, Utilisateur $auteur): void
    {
        $avant = $this->etat($sortie);
        $this->transaction($sortie, function () use ($sortie, $date, $destination, $motif, $demandePar, $commentaire, $lignes, $avant, $auteur): void {
            $sortie->definir($date, $destination, $motif, $demandePar, $commentaire, $lignes);
            $this->em->flush();
            $this->tracer(ActionAudit::MAGASIN_SORTIE_ENREGISTREE, $sortie, $avant, $auteur);
        });
    }

    /**
     * @throws StockMagasinInsuffisantException une ligne dépasse le stock de son emplacement
     * @throws \DomainException                  sortie vide, déjà validée ou annulée
     */
    public function valider(MagasinSortie $sortie, Utilisateur $auteur): void
    {
        $avant = $this->etat($sortie);

        $this->transaction($sortie, function () use ($sortie, $auteur, $avant): void {
            $couts = [];
            foreach ($sortie->getLignes() as $ligne) {
                $couts[(int) $ligne->getProduit()->getId()] = $ligne->getProduit()->getCoutMoyen();
            }
            $sortie->valider($couts, $auteur, new \DateTimeImmutable());

            $this->stock->appliquer(array_values(array_map(
                fn (MagasinLigneSortie $ligne): DemandeMouvementMagasin => new DemandeMouvementMagasin(
                    $ligne->getProduit(),
                    $ligne->getEmplacement(),
                    -$ligne->getQuantite(),
                    TypeMouvementMagasin::SORTIE,
                    self::DOCUMENT,
                    (int) $sortie->getId(),
                    \sprintf('Sortie %s — %s, %s', $sortie->getNumero(), $sortie->getDestination()->libelle(), mb_strtolower($sortie->getMotif()->libelle())),
                ),
                $sortie->getLignes()->toArray(),
            )), $auteur);

            $this->em->flush();
            $this->tracer(ActionAudit::MAGASIN_SORTIE_VALIDEE, $sortie, $avant, $auteur);
        });
    }

    /**
     * @throws \DomainException déjà annulée, motif manquant
     */
    public function annuler(MagasinSortie $sortie, ?string $motif, Utilisateur $auteur): void
    {
        $sortie->verifierAnnulable($motif);
        $avant = $this->etat($sortie);

        $this->transaction($sortie, function () use ($sortie, $motif, $auteur, $avant): void {
            if ($sortie->estValidee()) {
                $origines = array_values(array_filter(
                    $this->mouvements->duDocument(self::DOCUMENT, (int) $sortie->getId()),
                    static fn ($m): bool => TypeMouvementMagasin::SORTIE === $m->getType(),
                ));
                $this->stock->contrepasser($origines, self::DOCUMENT, (int) $sortie->getId(), 'Annulation '.$sortie->getNumero(), $auteur);
            }

            $sortie->annuler($motif, $auteur, new \DateTimeImmutable());
            $this->em->flush();
            $this->tracer(ActionAudit::MAGASIN_SORTIE_ANNULEE, $sortie, $avant, $auteur);
        });
    }

    /**
     * Lignes saisies à l'écran → lignes du bon. Une ligne sans produit ni
     * quantité est ignorée (ligne vide de l'écran). Sans emplacement choisi — le
     * cas d'un magasin à une seule zone — la ligne sort de la réserve principale.
     *
     * @param array<int|string, mixed> $saisie lignes[i][produit|quantite|unite|emplacement], unite = 'achat' | 'stock'
     *
     * @return list<array{produit: \App\Entity\Magasin\MagasinProduit, emplacement: \App\Entity\Magasin\MagasinEmplacement, quantite: int, enUniteAchat: bool}>
     *
     * @throws \DomainException
     */
    public function lireLignes(array $saisie): array
    {
        $reserve = null;
        $lignes = [];
        foreach ($saisie as $valeurs) {
            if (!\is_array($valeurs)) {
                continue;
            }
            $id = (int) (\is_scalar($valeurs['produit'] ?? null) ? $valeurs['produit'] : 0);
            $texte = \is_scalar($valeurs['quantite'] ?? null) ? trim((string) $valeurs['quantite']) : '';
            if (0 === $id && '' === $texte) {
                continue;
            }
            $produit = (0 !== $id ? $this->produits->find($id) : null) ?? throw new \DomainException('Choisissez le produit de chaque ligne.');

            $idEmplacement = (int) (\is_scalar($valeurs['emplacement'] ?? null) ? $valeurs['emplacement'] : 0);
            $emplacement = 0 !== $idEmplacement ? $this->emplacements->find($idEmplacement) : null;
            $emplacement ??= $reserve ??= $this->stock->reserveParDefaut();

            $lignes[] = [
                'produit' => $produit,
                'emplacement' => $emplacement,
                'quantite' => SaisieQuantite::millimes($texte, $produit->getNom()) ?? throw new \DomainException(\sprintf('« %s » : saisissez la quantité.', $produit->getNom())),
                'enUniteAchat' => 'stock' !== ($valeurs['unite'] ?? 'achat'),
            ];
        }

        return $lignes;
    }

    private function tracer(ActionAudit $action, MagasinSortie $sortie, ?array $avant, Utilisateur $auteur): void
    {
        $this->audit->enregistrer($action, 'MagasinSortie', $sortie->getId(), $avant, $this->etat($sortie), $auteur);
    }

    /** @return array<string, mixed> l'état journalisé ; quantités en millièmes d'unité de stock */
    private function etat(MagasinSortie $sortie): array
    {
        return [
            'numero' => $sortie->getNumero(),
            'statut' => $sortie->getStatut()->value,
            'date' => $sortie->getDateSortie()->format('Y-m-d'),
            'destination' => $sortie->getDestination()->value,
            'motif' => $sortie->getMotif()->value,
            'demandePar' => $sortie->getDemandePar(),
            'commentaire' => $sortie->getCommentaire(),
            'lignes' => array_values(array_map(static fn (MagasinLigneSortie $l): array => [
                'produit' => $l->getProduit()->getNom(),
                'emplacement' => $l->getEmplacement()->getCode(),
                'saisie' => $l->getQteSaisie(),
                'unite' => $l->getUniteSaisie(),
                'quantite' => $l->getQuantite(),
                'cout' => $l->getCoutUnitaire(),
            ], $sortie->getLignes()->toArray())),
            'motifAnnulation' => $sortie->getMotifAnnulation(),
        ];
    }

    /**
     * Transaction DBAL tenue à la main, comme pour les réceptions : un refus
     * métier laisse l'EntityManager ouvert, et la sortie est relue — son statut a
     * pu changer en mémoire avant le refus.
     *
     * @template T
     *
     * @param callable(): T $travail
     *
     * @return T
     */
    private function transaction(?MagasinSortie $sortie, callable $travail): mixed
    {
        $connexion = $this->connexion();
        $connexion->beginTransaction();

        try {
            $resultat = $travail();
            $connexion->commit();

            return $resultat;
        } catch (\Throwable $e) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }
            if (null !== $sortie && $this->em->isOpen() && $this->em->contains($sortie)) {
                foreach ($sortie->getLignes() as $ligne) {
                    if ($this->em->contains($ligne)) {
                        $this->em->refresh($ligne);
                    }
                }
                $this->em->refresh($sortie);
            }

            throw $e;
        }
    }

    private function connexion(): Connection
    {
        return $this->em->getConnection();
    }
}
