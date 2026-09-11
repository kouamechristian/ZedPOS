<?php

namespace App\Service;

use App\Entity\Arrete;
use App\Entity\Article;
use App\Entity\BonDotation;
use App\Entity\BonRetour;
use App\Entity\Emplacement;
use App\Entity\LigneDotation;
use App\Entity\Utilisateur;
use App\Entity\Vendeur;
use App\Enum\ModeRemuneration;
use App\Enum\MotifRetour;
use App\Enum\TraitementEcart;
use App\Enum\TypeMouvementStock;
use App\Repository\ArreteRepository;
use App\Repository\ArticleRepository;
use App\Repository\BonDotationRepository;
use App\Repository\BonRetourRepository;
use App\Repository\DetteVendeurRepository;
use App\Repository\MouvementStockRepository;
use App\Service\Point\DotationPoint;
use App\Service\Point\PeriodeArrete;
use App\Service\Point\PointCalculator;
use App\Service\Point\RepartitionVentes;
use App\Service\Point\ResultatPoint;
use App\Service\Point\RetourPoint;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le point d'un stand : période, saisie des retours, validation, annulation.
 *
 * **Règle de la période** : la date de début n'est jamais saisie. Elle vaut le
 * lendemain de la fin du dernier arrêté validé du stand, ou la date de sa première
 * dotation, et elle est **recalculée à chaque enregistrement et à la validation**.
 * Côté dotations, {@see BonDotationService} refuse toute date déjà arrêtée : aucune
 * journée n'est ainsi arrêtée deux fois, ni oubliée.
 *
 * **Validation**, en une transaction, dans cet ordre :
 * 1. retours de la saisie écrits au stock — invendus en RETOUR (stand → dépôt),
 *    casse / périmé / autre en PERTE ;
 * 2. le vendu sort du stock du stand (VENTE_STAND) : le stand retombe sur ce qu'il
 *    détient réellement ;
 * 3. dotations et retours de la période rattachés à l'arrêté ;
 * 4. montants figés, statut VALIDE, trace d'audit (et entrée d'écart s'il y en a) ;
 * 5. si la gérante a imputé le manquant au vendeur, sa dette est ouverte
 *    ({@see DetteService}) — jamais sans ce choix explicite.
 *
 * Tous les contrôles — brouillons de dotation, retours au-delà du confié, espèces
 * saisies, justification d'écart, sort du manquant — tombent **avant** la première
 * écriture.
 */
class ArreteService
{
    public const DOCUMENT_ARRETE = 'arrete';
    public const DOCUMENT_RETOUR = 'bon_retour';

    private const UNITES_MAX = 99999;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArreteRepository $arretes,
        private readonly BonDotationRepository $dotations,
        private readonly BonRetourRepository $retours,
        private readonly ArticleRepository $articles,
        private readonly MouvementStockRepository $mouvements,
        private readonly StockManager $stock,
        private readonly PointCalculator $calcul,
        private readonly AuditLogger $audit,
        private readonly DetteService $dettes,
        private readonly DetteVendeurRepository $dettesVendeurs,
    ) {
    }

    /**
     * Début obligé de la prochaine période du stand. `null` : aucune dotation, rien
     * à arrêter.
     */
    public function debutPeriode(Emplacement $stand): ?\DateTimeImmutable
    {
        $dernier = $this->arretes->dernierValide($stand);
        if (null !== $dernier) {
            return $dernier->getDateFin()->modify('+1 day');
        }

        return $this->dotations->premiereDate($stand);
    }

    /**
     * Point d'un stand sur une période, avec la saisie en cours. Aucune écriture.
     *
     * @param list<array{0: Article, 1: int, 2: MotifRetour}> $saisie
     *
     * @throws \DomainException retours au-delà du confié
     */
    public function calculer(Emplacement $stand, \DateTimeImmutable $debut, \DateTimeImmutable $fin, array $saisie, ?int $montantRemis): ResultatPoint
    {
        $retours = $this->retoursPoint($this->retours->aEmbarquer($stand, $debut, $fin));
        foreach ($saisie as [$produit, $quantite, $motif]) {
            $retours[] = new RetourPoint((int) $produit->getId(), $quantite, $motif);
        }

        return $this->calcul->calculer(
            $this->dotationsPoint($this->dotations->aEmbarquer($stand, $debut, $fin)),
            $retours,
            $stand->getModeRemuneration(),
            $stand->getTauxCommission(),
            $montantRemis,
        );
    }

    /**
     * Le point d'un arrêté enregistré. Validé : recalculé depuis ses seuls documents
     * rattachés et ses taux figés — le résultat retombe sur les montants stockés.
     * Brouillon : depuis la période et la saisie en cours. Annulé : `null`, ses
     * dotations ont été rendues à la période.
     */
    public function resultat(Arrete $arrete): ?ResultatPoint
    {
        if ($arrete->estAnnule()) {
            return null;
        }

        if ($arrete->estBrouillon()) {
            return $this->calculer($arrete->getStand(), $arrete->getDateDebut(), $arrete->getDateFin(), $this->saisieDu($arrete), $arrete->getMontantRemis());
        }

        return $this->calcul->calculer(
            $this->dotationsPoint($this->dotations->duArrete($arrete)),
            $this->retoursPoint($this->retours->validesDe($arrete)),
            $arrete->getModeRemuneration(),
            $arrete->getTauxCommission(),
            $arrete->getMontantRemis(),
        );
    }

    /** @return list<array{0: Article, 1: int, 2: MotifRetour}> la saisie du brouillon */
    public function saisieDu(Arrete $arrete): array
    {
        $bon = null !== $arrete->getId() ? $this->retours->brouillonDe($arrete) : null;

        return array_map(
            static fn ($ligne): array => [$ligne->getProduit(), $ligne->getQuantite(), $ligne->getMotif()],
            $bon?->getLignes()->toArray() ?? [],
        );
    }

    /**
     * Saisie brute de l'écran → lignes de retour.
     *
     * @param array<int|string, mixed> $saisie [produitId => ['invendu' => '3', 'perdu' => '1', 'motif' => 'CASSE']]
     *
     * @return list<array{0: Article, 1: int, 2: MotifRetour}> produit, millièmes, motif
     */
    public function lireSaisie(array $saisie): array
    {
        $voulues = [];
        foreach ($saisie as $produitId => $valeurs) {
            if (!\is_array($valeurs)) {
                continue;
            }

            $motifPerte = MotifRetour::tryFrom((string) ($valeurs['motif'] ?? MotifRetour::CASSE->value));
            if (null === $motifPerte || !$motifPerte->estPerte()) {
                throw new \DomainException('Motif de perte invalide.');
            }

            foreach (['invendu' => MotifRetour::INVENDU, 'perdu' => $motifPerte] as $champ => $motif) {
                $unites = $this->unites($valeurs[$champ] ?? '');
                if ($unites > 0) {
                    $voulues[] = [(int) $produitId, $unites * 1000, $motif];
                }
            }
        }

        if ([] === $voulues) {
            return [];
        }

        $articles = [];
        foreach ($this->articles->findBy(['id' => array_unique(array_column($voulues, 0))]) as $article) {
            $articles[$article->getId()] = $article;
        }

        return array_map(
            static fn (array $v): array => [$articles[$v[0]] ?? throw new \DomainException('Un produit saisi n\'existe plus : rechargez l\'écran.'), $v[1], $v[2]],
            $voulues,
        );
    }

    /**
     * Enregistre le brouillon du point du stand — le crée s'il n'existe pas. Rien ne
     * touche au stock.
     *
     * @param list<array{0: Article, 1: int, 2: MotifRetour}> $saisie
     *
     * @throws \DomainException période invalide, retours au-delà du confié
     */
    public function enregistrer(
        Emplacement $stand,
        \DateTimeImmutable $fin,
        Vendeur $vendeur,
        array $saisie,
        ?int $montantRemis,
        ?string $commentaireEcart,
        ?TraitementEcart $traitementEcart,
        Utilisateur $auteur,
        ?\DateTimeImmutable $aujourdhui = null,
    ): Arrete {
        $aujourdhui = ($aujourdhui ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $debut = $this->debutPeriode($stand) ?? throw new \DomainException('Aucune dotation sur ce stand : il n\'y a rien à arrêter.');
        PeriodeArrete::verifierFin($debut, $fin, $aujourdhui);

        // Calculé avant toute écriture : un retour au-delà du confié est refusé ici.
        $resultat = $this->calculer($stand, $debut, $fin, $saisie, $montantRemis);

        $arrete = $this->arretes->brouillonDe($stand);
        if (null === $arrete) {
            $arrete = new Arrete($this->arretes->prochainNumero($aujourdhui), $stand, $vendeur, $debut, $fin, $auteur);
            $this->em->persist($arrete);
        } else {
            $arrete->definirPeriode($debut, $fin);
            $arrete->changerVendeur($vendeur);
        }

        $arrete->enregistrerSaisie($montantRemis, $commentaireEcart, $traitementEcart);
        $arrete->reporterCalcul($resultat);

        $bon = null !== $arrete->getId() ? $this->retours->brouillonDe($arrete) : null;
        if ([] !== $saisie) {
            if (null === $bon) {
                $bon = new BonRetour($this->retours->prochainNumero($fin), $stand, $vendeur, $fin, $auteur);
                $bon->rattacherArrete($arrete);
                $this->em->persist($bon);
            }
            $bon->definirLignes($saisie, $vendeur, $fin);
        } elseif (null !== $bon) {
            // Brouillon vidé, jamais écrit au stock : rien à garder.
            $this->em->remove($bon);
        }

        $this->em->flush();

        return $arrete;
    }

    /**
     * @throws DotationsEnBrouillonException des dotations de la période sont en brouillon
     * @throws StockInsuffisantException    le stand ou le dépôt n'a pas ce qu'on écrit
     * @throws \DomainException             période, espèces, justification, retours
     */
    public function valider(Arrete $arrete, Utilisateur $auteur, ?\DateTimeImmutable $aujourdhui = null): ResultatPoint
    {
        $arrete->garantirBrouillon();
        $aujourdhui = ($aujourdhui ?? new \DateTimeImmutable('today'))->setTime(0, 0);

        $stand = $arrete->getStand();
        $fin = $arrete->getDateFin();
        $debut = $this->debutPeriode($stand) ?? throw new \DomainException('Aucune dotation sur ce stand : il n\'y a rien à arrêter.');
        PeriodeArrete::verifierFin($debut, $fin, $aujourdhui);

        $brouillons = $this->dotations->brouillonsDansPeriode($stand, $debut, $fin);
        if ([] !== $brouillons) {
            throw new DotationsEnBrouillonException($brouillons);
        }

        $bonRetour = $this->retours->brouillonDe($arrete);
        $bonsDotation = $this->dotations->aEmbarquer($stand, $debut, $fin);
        $bonsRetour = $this->retours->aEmbarquer($stand, $debut, $fin);
        $resultat = $this->calculer($stand, $debut, $fin, $this->saisieDu($arrete), $arrete->getMontantRemis());

        if ([] === $resultat->lignes) {
            throw new \DomainException('Aucune dotation validée sur la période : il n\'y a rien à arrêter.');
        }

        $arrete->verifierValidable($resultat);
        $avant = $this->audit->etatArrete($arrete);

        $produits = [];
        foreach ($bonsDotation as $bon) {
            foreach ($bon->getLignes() as $ligne) {
                $produits[$ligne->getProduit()->getId()] = $ligne->getProduit();
            }
        }

        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();
        $dette = null;

        try {
            if ($arrete->getDateDebut() != $debut) {
                $arrete->definirPeriode($debut, $fin);
            }

            $depot = $this->stock->depotPrincipal();

            // 1. Retours de la saisie.
            if (null !== $bonRetour) {
                $invendus = [];
                $pertes = [];
                foreach ($bonRetour->getLignes() as $ligne) {
                    if (MotifRetour::INVENDU === $ligne->getMotif()) {
                        $invendus[] = [$ligne->getProduit(), $ligne->getQuantite()];
                    } else {
                        $pertes[] = new DemandeMouvementStock($ligne->getProduit(), $stand, -$ligne->getQuantite(), TypeMouvementStock::PERTE, self::DOCUMENT_RETOUR, $bonRetour->getId(), \sprintf('Retour %s — %s', $bonRetour->getNumero(), $ligne->getMotif()->libelle()));
                    }
                }

                if ([] !== $invendus) {
                    $this->stock->transfererLot($invendus, $stand, $depot, TypeMouvementStock::RETOUR, self::DOCUMENT_RETOUR, $bonRetour->getId(), 'Invendus '.$bonRetour->getNumero(), $auteur);
                }
                if ([] !== $pertes) {
                    $this->stock->enregistrerMouvements($pertes, $auteur);
                }

                $bonRetour->valider();
            }

            // 2. Le vendu sort du stand.
            $ventes = [];
            foreach ($resultat->lignes as $ligne) {
                if ($ligne->qteVendue > 0) {
                    $ventes[] = new DemandeMouvementStock($produits[$ligne->produitId], $stand, -$ligne->qteVendue, TypeMouvementStock::VENTE_STAND, self::DOCUMENT_ARRETE, $arrete->getId(), 'Point '.$arrete->getNumero());
                }
            }
            if ([] !== $ventes) {
                $this->stock->enregistrerMouvements($ventes, $auteur);
            }

            // 3. Rattachements, et la part de chaque ligne dans le point — ce que
            //    lisent les rapports, à la date de la dotation.
            foreach ($bonsDotation as $bon) {
                $bon->rattacherArrete($arrete);
            }
            $this->reporterVentes($resultat, $bonsDotation, $stand->getModeRemuneration());
            foreach ($bonsRetour as $bon) {
                $bon->rattacherArrete($arrete);
            }

            // 4. Montants figés, trace.
            $arrete->valider($resultat, new \DateTimeImmutable());
            $this->em->flush();
            $this->audit->arreteValide($arrete, $avant, $resultat, $auteur);

            // 5. Le manquant imputé au vendeur, s'il l'a été — jamais par défaut.
            if (TraitementEcart::DETTE === $arrete->getTraitementEcart()) {
                $dette = $this->dettes->ouvrirDepuisArrete($arrete, $auteur);
            }

            $connexion->commit();
        } catch (\Throwable $e) {
            if (null !== $dette && $this->em->isOpen() && $this->em->contains($dette)) {
                $this->em->detach($dette);
            }
            // Les bons ont pu être rattachés et leurs lignes soldées en mémoire : relus avec l'arrêté.
            $this->annulerTransaction($e, $arrete, $bonRetour, ...$bonsDotation, ...$bonsRetour, ...self::lignesDe($bonsDotation));

            throw $e;
        }

        return $resultat;
    }

    /**
     * Défait un arrêté validé : mouvements contre-passés, retours annulés, dotations
     * rendues à la période, dette ouverte sur son manquant annulée. **Seulement le
     * dernier arrêté validé du stand** — défaire un arrêté plus ancien laisserait un
     * trou entre deux périodes arrêtées.
     *
     * @throws StockInsuffisantException ce qui est revenu au dépôt en est déjà reparti
     * @throws \DomainException          pas validé, pas le dernier, motif manquant,
     *                                   dette déjà remboursée en partie
     */
    public function annuler(Arrete $arrete, ?string $motif, Utilisateur $auteur): void
    {
        $arrete->verifierAnnulable($motif);

        $dernier = $this->arretes->dernierValide($arrete->getStand());
        if ($dernier !== $arrete) {
            throw new \DomainException(\sprintf(
                'Seul le dernier arrêté du stand peut être annulé : %s, arrêté jusqu\'au %s.',
                $dernier?->getNumero() ?? '—',
                $dernier?->getDateFin()->format('d/m/Y') ?? '—',
            ));
        }

        // La dette qu'il avait ouverte part avec lui — sauf si le vendeur a déjà
        // commencé à la rembourser : on ne défait pas un argent reçu.
        $dette = $this->dettesVendeurs->deArrete($arrete);
        if (null !== $dette && $dette->getMontantRembourse() > 0) {
            throw new \DomainException(\sprintf(
                'Ce point a ouvert une dette de %s FCFA dont %s FCFA ont déjà été remboursés : il ne s\'annule plus.',
                number_format(intdiv($dette->getMontant(), 100), 0, ',', ' '),
                number_format(intdiv($dette->getMontantRembourse(), 100), 0, ',', ' '),
            ));
        }

        $avant = $this->audit->etatArrete($arrete, $this->resultat($arrete));
        $bonsRetour = $this->retours->validesDe($arrete);
        $bonsDotation = $this->dotations->duArrete($arrete);

        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();

        try {
            if (null !== $dette && $dette->estDue()) {
                $this->dettes->annulerDepuisArrete($dette, $auteur);
            }

            $mouvements = $this->mouvements->duDocument(self::DOCUMENT_ARRETE, (int) $arrete->getId());
            foreach ($bonsRetour as $bon) {
                array_push($mouvements, ...$this->mouvements->duDocument(self::DOCUMENT_RETOUR, (int) $bon->getId()));
            }
            if ([] !== $mouvements) {
                $this->stock->contrepasser($mouvements, self::DOCUMENT_ARRETE, $arrete->getId(), 'Annulation point '.$arrete->getNumero(), $auteur);
            }

            foreach ($bonsRetour as $bon) {
                $bon->annuler();
            }

            $arrete->annuler((string) $motif, new \DateTimeImmutable());
            foreach ($bonsDotation as $bon) {
                $bon->detacherArrete($arrete);
            }

            $this->em->flush();
            $this->audit->arreteAnnule($arrete, $avant, $auteur);

            $connexion->commit();
        } catch (\Throwable $e) {
            $this->annulerTransaction($e, $arrete, $dette, ...$bonsRetour, ...$bonsDotation, ...self::lignesDe($bonsDotation));

            throw $e;
        }
    }

    /**
     * Reporte sur les lignes d'un point **déjà validé** la part de chacune — pour les
     * points validés avant que les rapports n'existent (`app:stands:figer-ventes`).
     * Recalcul depuis les documents rattachés et les taux figés ; le flush revient à
     * l'appelant.
     */
    public function figerVentes(Arrete $arrete): void
    {
        $resultat = $this->resultat($arrete);
        if (!$arrete->estValide() || null === $resultat) {
            throw new \DomainException(\sprintf('Le point %s n\'est pas validé.', $arrete->getNumero()));
        }

        $this->reporterVentes($resultat, $this->dotations->duArrete($arrete), $arrete->getModeRemuneration());
    }

    // ------------------------------------------------------------------------

    /** @param list<BonDotation> $bons */
    private function reporterVentes(ResultatPoint $resultat, array $bons, ModeRemuneration $mode): void
    {
        $parts = RepartitionVentes::repartir($resultat, $mode);

        foreach ($bons as $bon) {
            foreach ($bon->getLignes() as $ligne) {
                $cle = RepartitionVentes::cle((int) $bon->getId(), (int) $ligne->getProduit()->getId());
                $ligne->figerVente($parts[$cle] ?? throw new \LogicException(\sprintf('Ligne %s du bon %s absente du point.', $ligne->getProduit()->getNom(), $bon->getNumero())));
            }
        }
    }

    /**
     * @param list<BonDotation> $bons
     *
     * @return list<LigneDotation>
     */
    private static function lignesDe(array $bons): array
    {
        $lignes = [];
        foreach ($bons as $bon) {
            array_push($lignes, ...$bon->getLignes()->toArray());
        }

        return $lignes;
    }

    /**
     * @param list<BonDotation> $bons
     *
     * @return list<DotationPoint>
     */
    private function dotationsPoint(array $bons): array
    {
        $dotations = [];
        foreach ($bons as $bon) {
            foreach ($bon->getLignes() as $ligne) {
                $produit = $ligne->getProduit();
                $dotations[] = new DotationPoint(
                    (int) $produit->getId(),
                    $produit->getNom(),
                    $produit->getUnite(),
                    $bon->getDateDotation(),
                    (int) $bon->getId(),
                    $ligne->getQuantite(),
                    // Prix figés du bon validé, jamais ceux du produit courant.
                    $ligne->getPrixVenteUnitaire() ?? throw new \LogicException(\sprintf('Bon %s validé sans prix figé.', $bon->getNumero())),
                    $ligne->getPrixCessionUnitaire() ?? 0,
                );
            }
        }

        return $dotations;
    }

    /**
     * @param list<BonRetour> $bons
     *
     * @return list<RetourPoint>
     */
    private function retoursPoint(array $bons): array
    {
        $retours = [];
        foreach ($bons as $bon) {
            foreach ($bon->getLignes() as $ligne) {
                $retours[] = new RetourPoint((int) $ligne->getProduit()->getId(), $ligne->getQuantite(), $ligne->getMotif());
            }
        }

        return $retours;
    }

    private function unites(mixed $valeur): int
    {
        $saisie = trim((string) $valeur);
        if ('' === $saisie) {
            return 0;
        }
        if (!ctype_digit($saisie) || (int) $saisie > self::UNITES_MAX) {
            throw new \DomainException(\sprintf('Quantité invalide : « %s ».', $saisie));
        }

        return (int) $saisie;
    }

    private function annulerTransaction(\Throwable $e, ?object ...$entites): void
    {
        $connexion = $this->em->getConnection();
        if ($connexion->isTransactionActive()) {
            $connexion->rollBack();
        }

        // Les statuts ont pu changer en mémoire avant l'échec : on les relit, sans
        // quoi l'écran afficherait validé ce qui ne l'est pas.
        if ($this->em->isOpen()) {
            foreach ($entites as $entite) {
                if (null !== $entite && $this->em->contains($entite)) {
                    $this->em->refresh($entite);
                }
            }
        }
    }
}
