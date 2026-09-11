<?php

namespace App\EventListener;

use App\Entity\Vente;
use App\Enum\StatutVente;
use App\Enum\TypeMouvementStock;
use App\Repository\MouvementStockRepository;
use App\Service\DemandeMouvementStock;
use App\Service\StockManager;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Déstockage automatique lié aux ventes caisse, au dépôt principal.
 *
 * - À la création d'une vente : pour chaque ligne, si l'article a une fiche
 *   technique on décrémente chaque matière première de
 *   (quantité vendue × quantité de la fiche × coefficient de perte) ; sinon, si
 *   l'article est suivi en stock (boissons), on décrémente son stock directement.
 * - À l'annulation : les sorties sont inversées.
 *
 * Tout passe par {@see StockManager}, en **un lot par vente** : une vente qui
 * touche cinq matières est déstockée d'un bloc. Les mouvements sont de type
 * VENTE_CAISSE, le seul qui tolère un stock négatif — on ne bloque jamais une
 * vente. Comme l'id de la vente n'existe qu'après l'INSERT, le travail est
 * collecté en postPersist/preUpdate puis exécuté en postFlush.
 */
#[AsDoctrineListener(Events::postPersist)]
#[AsDoctrineListener(Events::preUpdate)]
#[AsDoctrineListener(Events::postFlush)]
class DestockageVenteListener
{
    public const DOCUMENT = 'vente';

    /** @var Vente[] */
    private array $aDestocker = [];
    /** @var Vente[] */
    private array $aRestocker = [];
    private bool $enCours = false;

    public function __construct(
        private readonly MouvementStockRepository $mouvements,
        private readonly StockManager $stock,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entite = $args->getObject();
        if ($entite instanceof Vente && StatutVente::VALIDEE === $entite->getStatut()) {
            $this->aDestocker[] = $entite;
        }
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entite = $args->getObject();
        if ($entite instanceof Vente
            && $args->hasChangedField('statut')
            && StatutVente::ANNULEE === $this->versStatut($args->getNewValue('statut'))
        ) {
            $this->aRestocker[] = $entite;
        }
    }

    /**
     * Normalise la valeur du changeset (enum ou chaîne selon le contexte Doctrine).
     */
    private function versStatut(mixed $valeur): ?StatutVente
    {
        return $valeur instanceof StatutVente ? $valeur : StatutVente::tryFrom((string) $valeur);
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        // StockManager flushe à son tour : la garde évite de retraiter en boucle.
        if ($this->enCours || ([] === $this->aDestocker && [] === $this->aRestocker)) {
            return;
        }

        $this->enCours = true;
        $destocker = $this->aDestocker;
        $restocker = $this->aRestocker;
        $this->aDestocker = [];
        $this->aRestocker = [];

        try {
            foreach ($destocker as $vente) {
                $this->destocker($vente);
            }
            foreach ($restocker as $vente) {
                $this->restocker($vente);
            }
        } finally {
            $this->enCours = false;
        }
    }

    private function destocker(Vente $vente): void
    {
        $depot = $this->stock->depotPrincipal();
        $motif = 'Sortie vente '.$vente->getNumero();
        $demandes = [];

        foreach ($vente->getLignes() as $ligne) {
            $article = $ligne->getArticle();
            $venduMillimes = $ligne->getQuantite();
            $fiche = $article->getFicheTechnique();

            if (null !== $fiche) {
                foreach ($fiche->getLignes() as $composant) {
                    $consommation = $this->consommation($venduMillimes, $composant->getQuantite(), $composant->getPourcentagePerte());
                    if (0 !== $consommation) {
                        $demandes[] = new DemandeMouvementStock($composant->getMatierePremiere(), $depot, -$consommation, TypeMouvementStock::VENTE_CAISSE, self::DOCUMENT, $vente->getId(), $motif);
                    }
                }
            } elseif ($article->isSuiviStock()) {
                $demandes[] = new DemandeMouvementStock($article, $depot, -$venduMillimes, TypeMouvementStock::VENTE_CAISSE, self::DOCUMENT, $vente->getId(), $motif);
            }
        }

        // L'auteur est le caissier de la session, et non l'utilisateur connecté :
        // les fixtures et la synchronisation n'en ont pas toujours un.
        $this->stock->enregistrerMouvements($demandes, $vente->getSessionCaisse()->getUtilisateur());
    }

    /**
     * Inverse les sorties de la vente, emplacement par emplacement. L'auteur est
     * l'utilisateur connecté : celui qui annule.
     */
    private function restocker(Vente $vente): void
    {
        $motif = 'Annulation vente '.$vente->getNumero();
        $demandes = [];

        foreach ($this->mouvements->sortiesDeVente((int) $vente->getId()) as $sortie) {
            $demandes[] = new DemandeMouvementStock(
                $sortie->getProduit(),
                $sortie->getEmplacement(),
                -$sortie->getQuantite(),
                TypeMouvementStock::VENTE_CAISSE,
                self::DOCUMENT,
                $vente->getId(),
                $motif,
            );
        }

        $this->stock->enregistrerMouvements($demandes);
    }

    /**
     * Consommation d'une matière en millièmes : quantité vendue × quantité fiche,
     * majorée du coefficient de perte 1/(1 − perte). Arithmétique entière.
     */
    private function consommation(int $venduMillimes, int $quantiteFiche, int $perteBp): int
    {
        $perte = max(0, min($perteBp, 9999));
        $numerateur = $venduMillimes * $quantiteFiche * 10;
        $denominateur = 10000 - $perte;

        return intdiv($numerateur + intdiv($denominateur, 2), $denominateur);
    }
}
