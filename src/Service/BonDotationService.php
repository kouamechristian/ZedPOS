<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\BonDotation;
use App\Entity\Emplacement;
use App\Entity\Utilisateur;
use App\Entity\Vendeur;
use App\Enum\TypeMouvementStock;
use App\Repository\ArreteRepository;
use App\Repository\ArticleRepository;
use App\Repository\BonDotationRepository;
use App\Repository\MouvementStockRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cycle d'un bon de dotation : saisie, validation, annulation.
 *
 * Validation et annulation suivent le même déroulé, **dans une seule transaction** :
 * contrôles du bon (avant toute écriture) → mouvements de stock par
 * {@see StockManager} → changement de statut → trace d'audit. Un refus à n'importe
 * quelle étape n'en laisse aucune : pas de stock déplacé pour un bon resté
 * brouillon, pas d'entrée d'audit pour une annulation qui n'a pas eu lieu.
 */
class BonDotationService
{
    /** Type de document des mouvements de stock d'un bon. */
    public const DOCUMENT = 'bon_dotation';

    /** Au-delà, c'est une touche restée enfoncée, pas une dotation du matin. */
    private const UNITES_MAX = 99999;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BonDotationRepository $bons,
        private readonly ArticleRepository $articles,
        private readonly MouvementStockRepository $mouvements,
        private readonly StockManager $stock,
        private readonly AuditLogger $audit,
        private readonly ArreteRepository $arretes,
    ) {
    }

    /**
     * @param array<int|string, mixed> $quantites unités entières par id d'article, telles que saisies
     *
     * @throws \DomainException saisie invalide, stand ou vendeur désactivé, date déjà arrêtée
     */
    public function creer(Emplacement $stand, Vendeur $vendeur, \DateTimeImmutable $date, array $quantites, Utilisateur $auteur): BonDotation
    {
        $this->verifierDateOuverte($stand, $date);
        $lignes = $this->lireQuantites($quantites);

        $bon = new BonDotation($this->bons->prochainNumero($date), $stand, $vendeur, $date, $auteur);
        $bon->definirQuantites($lignes);

        $this->em->persist($bon);
        $this->em->flush();

        return $bon;
    }

    /**
     * @param array<int|string, mixed> $quantites
     *
     * @throws \DomainException si le bon n'est plus un brouillon
     */
    public function modifier(BonDotation $bon, Vendeur $vendeur, \DateTimeImmutable $date, array $quantites): void
    {
        $this->verifierDateOuverte($bon->getStand(), $date);
        $lignes = $this->lireQuantites($quantites);

        $bon->garantirBrouillon();
        $bon->changerVendeur($vendeur);
        $bon->changerDate($date);
        $bon->definirQuantites($lignes);

        $this->em->flush();
    }

    /**
     * Valide le bon : transfert dépôt → stand, prix figés, trace d'audit.
     *
     * @throws StockInsuffisantException si le dépôt n'a pas de quoi doter
     * @throws \DomainException          bon vide, déjà validé, prix de cession manquant
     */
    public function valider(BonDotation $bon, Utilisateur $auteur): void
    {
        $bon->verifierValidable();
        $this->verifierDateOuverte($bon->getStand(), $bon->getDateDotation());
        $avant = $this->audit->etatDotation($bon);

        $lignes = [];
        foreach ($bon->getLignes() as $ligne) {
            $lignes[] = [$ligne->getProduit(), $ligne->getQuantite()];
        }

        $this->transaction($bon, function () use ($bon, $auteur, $lignes, $avant): void {
            $this->stock->transfererLot(
                $lignes,
                $this->stock->depotPrincipal(),
                $bon->getStand(),
                TypeMouvementStock::DOTATION,
                self::DOCUMENT,
                $bon->getId(),
                'Dotation '.$bon->getNumero(),
                $auteur,
            );

            $bon->valider(new \DateTimeImmutable());
            $this->em->flush();

            $this->audit->dotationValidee($bon, $avant, $auteur);
        });
    }

    /**
     * Annule le bon. Validé : ses mouvements sont contre-passés, et c'est refusé si
     * le stand a déjà écoulé une partie de la marchandise. Brouillon : simple
     * changement de statut, il n'a rien déplacé.
     *
     * @throws StockInsuffisantException si le stand n'a plus ce qu'on lui a confié
     * @throws \DomainException          déjà annulé, rattaché à un arrêté validé, motif manquant
     */
    public function annuler(BonDotation $bon, ?string $motif, Utilisateur $auteur): void
    {
        $bon->verifierAnnulable($motif);
        $avant = $this->audit->etatDotation($bon);

        $this->transaction($bon, function () use ($bon, $motif, $auteur, $avant): void {
            if ($bon->estValide()) {
                $origines = array_values(array_filter(
                    $this->mouvements->duDocument(self::DOCUMENT, (int) $bon->getId()),
                    static fn ($mouvement): bool => TypeMouvementStock::DOTATION === $mouvement->getType(),
                ));

                if ([] !== $origines) {
                    $this->stock->contrepasser($origines, self::DOCUMENT, $bon->getId(), 'Annulation dotation '.$bon->getNumero(), $auteur);
                }
            }

            $bon->annuler($motif, new \DateTimeImmutable());
            $this->em->flush();

            $this->audit->dotationAnnulee($bon, $avant, $auteur);
        });
    }

    /**
     * Une dotation ne se date pas dans une période déjà arrêtée.
     *
     * C'est l'autre moitié de la règle des arrêtés : le début d'un point vaut le
     * lendemain du précédent, et un bon daté avant n'y entrerait jamais — il ne
     * serait arrêté nulle part, sa marchandise ne serait jamais payée.
     *
     * @throws \DomainException
     */
    public function verifierDateOuverte(Emplacement $stand, \DateTimeImmutable $date): void
    {
        $dernier = $this->arretes->dernierValide($stand);

        if (null !== $dernier && $date->setTime(0, 0) <= $dernier->getDateFin()) {
            throw new \DomainException(\sprintf(
                'Le stand est arrêté jusqu\'au %s (%s) : une dotation doit être datée du %s ou après.',
                $dernier->getDateFin()->format('d/m/Y'),
                $dernier->getNumero(),
                $dernier->getDateFin()->modify('+1 day')->format('d/m/Y'),
            ));
        }
    }

    /**
     * Quantités saisies à l'écran → lignes du bon. Case vide ou zéro : produit non
     * pris. Unités entières seulement — le pavé ne tape pas de virgule.
     *
     * @param array<int|string, mixed> $quantites
     *
     * @return list<array{0: Article, 1: int}> produit et quantité en millièmes
     */
    public function lireQuantites(array $quantites): array
    {
        $unitesParId = [];
        foreach ($quantites as $id => $valeur) {
            $saisie = trim((string) $valeur);
            if ('' === $saisie) {
                continue;
            }
            if (!ctype_digit($saisie) || (int) $saisie > self::UNITES_MAX) {
                throw new \DomainException(\sprintf('Quantité invalide : « %s ».', $saisie));
            }
            if ((int) $saisie > 0) {
                $unitesParId[(int) $id] = (int) $saisie;
            }
        }

        if ([] === $unitesParId) {
            return [];
        }

        $lignes = [];
        foreach ($this->articles->findBy(['id' => array_keys($unitesParId)]) as $article) {
            if (!$article->isActif()) {
                throw new \DomainException(\sprintf('« %s » n\'est plus disponible.', $article->getNom()));
            }
            $lignes[] = [$article, $unitesParId[$article->getId()] * 1000];
        }

        if (\count($lignes) !== \count($unitesParId)) {
            throw new \DomainException('Un produit saisi n\'existe plus : rechargez l\'écran.');
        }

        return $lignes;
    }

    /**
     * Transaction DBAL tenue à la main : `wrapInTransaction()` fermerait
     * l'EntityManager sur un refus métier, et l'écran ne pourrait plus se
     * réafficher avec le message.
     */
    private function transaction(BonDotation $bon, callable $travail): void
    {
        $connexion = $this->connexion();
        $statut = $bon->getStatut();
        $connexion->beginTransaction();

        try {
            $travail();
            $connexion->commit();
        } catch (\Throwable $e) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }
            // Le bon a pu changer de statut en mémoire avant l'échec : on le relit,
            // sans quoi l'écran l'afficherait validé ou annulé.
            if ($bon->getStatut() !== $statut && $this->em->isOpen()) {
                $this->em->refresh($bon);
            }

            throw $e;
        }
    }

    private function connexion(): Connection
    {
        return $this->em->getConnection();
    }
}
