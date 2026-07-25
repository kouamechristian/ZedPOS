<?php

namespace App\EventListener;

use App\Entity\Article;
use App\Entity\MatierePremiere;
use App\Entity\MouvementStock;
use App\Entity\Vente;
use App\Enum\StatutVente;
use App\Enum\TypeMouvementStock;
use App\Repository\MouvementStockRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Déstockage automatique lié aux ventes.
 *
 * - À la création d'une vente : pour chaque ligne, si l'article a une fiche
 *   technique on décrémente chaque matière première de
 *   (quantité vendue × quantité de la fiche × coefficient de perte) ; sinon, si
 *   l'article est suivi en stock (boissons), on décrémente son stock directement.
 *   Un MouvementStock SORTIE_VENTE est créé pour chaque décrément.
 * - À l'annulation : les mouvements inverses sont générés.
 *
 * Le stock peut devenir négatif (on ne bloque jamais une vente) mais déclenche
 * une alerte (journalisée). Comme l'id de la vente n'existe qu'après l'INSERT, le
 * travail est collecté en postPersist/preUpdate puis exécuté en postFlush.
 */
#[AsDoctrineListener(Events::postPersist)]
#[AsDoctrineListener(Events::preUpdate)]
#[AsDoctrineListener(Events::postFlush)]
class DestockageVenteListener
{
    /** @var Vente[] */
    private array $aDestocker = [];
    /** @var Vente[] */
    private array $aRestocker = [];
    private bool $enCours = false;

    public function __construct(
        private readonly MouvementStockRepository $mouvements,
        private readonly LoggerInterface $logger,
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
        if ($this->enCours || ([] === $this->aDestocker && [] === $this->aRestocker)) {
            return;
        }

        $this->enCours = true;
        $destocker = $this->aDestocker;
        $restocker = $this->aRestocker;
        $this->aDestocker = [];
        $this->aRestocker = [];

        try {
            $em = $args->getObjectManager();
            foreach ($destocker as $vente) {
                $this->destocker($vente, $em);
            }
            foreach ($restocker as $vente) {
                $this->restocker($vente, $em);
            }
            $em->flush();
        } finally {
            $this->enCours = false;
        }
    }

    private function destocker(Vente $vente, \Doctrine\ORM\EntityManagerInterface $em): void
    {
        foreach ($vente->getLignes() as $ligne) {
            $article = $ligne->getArticle();
            $venduMillimes = $ligne->getQuantite();
            $fiche = $article->getFicheTechnique();

            if (null !== $fiche) {
                foreach ($fiche->getLignes() as $composant) {
                    $consommation = $this->consommation($venduMillimes, $composant->getQuantite(), $composant->getPourcentagePerte());
                    $this->appliquer($em, $vente, $composant->getMatierePremiere(), null, -$consommation, TypeMouvementStock::SORTIE_VENTE, 'Sortie vente '.$vente->getNumero());
                }
            } elseif ($article->isSuiviStock()) {
                $this->appliquer($em, $vente, null, $article, -$venduMillimes, TypeMouvementStock::SORTIE_VENTE, 'Sortie vente '.$vente->getNumero());
            }
        }
    }

    private function restocker(Vente $vente, \Doctrine\ORM\EntityManagerInterface $em): void
    {
        $sorties = $this->mouvements->findBy([
            'sourceType' => 'vente',
            'sourceId' => $vente->getId(),
            'type' => TypeMouvementStock::SORTIE_VENTE,
        ]);

        foreach ($sorties as $sortie) {
            $inverse = -$sortie->getQuantite();
            $this->appliquer(
                $em,
                $vente,
                $sortie->getMatierePremiere(),
                $sortie->getArticle(),
                $inverse,
                TypeMouvementStock::ENTREE,
                'Annulation vente '.$vente->getNumero(),
            );
        }
    }

    private function appliquer(
        \Doctrine\ORM\EntityManagerInterface $em,
        Vente $vente,
        ?MatierePremiere $matiere,
        ?Article $article,
        int $quantiteSignee,
        TypeMouvementStock $type,
        string $motif,
    ): void {
        $mouvement = new MouvementStock($type, $quantiteSignee);
        $mouvement->setMotif($motif)->setSource('vente', $vente->getId());

        if (null !== $matiere) {
            $mouvement->setMatierePremiere($matiere);
            $nouveau = $matiere->getStockActuel() + $quantiteSignee;
            $matiere->setStockActuel($nouveau);
            $this->alerter($nouveau, $matiere->getStockMini(), $matiere->getNom(), $vente);
        } elseif (null !== $article) {
            $mouvement->setArticle($article);
            $nouveau = $article->getStockActuel() + $quantiteSignee;
            $article->setStockActuel($nouveau);
            $this->alerter($nouveau, $article->getStockMini(), $article->getNom(), $vente);
        }

        $em->persist($mouvement);
    }

    private function alerter(int $stock, int $stockMini, string $nom, Vente $vente): void
    {
        if ($stock < 0) {
            $this->logger->warning('Stock négatif sur « {article} » ({stock} millièmes) après la vente {vente}.', [
                'article' => $nom,
                'stock' => $stock,
                'vente' => $vente->getNumero(),
            ]);
        } elseif ($stock < $stockMini) {
            $this->logger->notice('Stock de « {article} » sous le seuil d\'alerte ({stock} millièmes).', [
                'article' => $nom,
                'stock' => $stock,
            ]);
        }
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
