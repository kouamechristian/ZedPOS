<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\MatierePremiere;
use App\Entity\MouvementStock;
use App\Entity\Perte;
use App\Enum\MotifPerte;
use App\Enum\TypeMouvementStock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enregistre une perte : crée la Perte valorisée automatiquement, un MouvementStock
 * de type PERTE, et décrémente le stock du support (matière ou article suivi).
 */
class PerteService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValorisationService $valorisation,
    ) {
    }

    public function enregistrer(
        MotifPerte $motif,
        ?MatierePremiere $matiere,
        ?Article $article,
        int $quantiteMillimes,
        ?string $commentaire = null,
    ): Perte {
        if ((null === $matiere) === (null === $article)) {
            throw new \InvalidArgumentException('Sélectionnez une matière première OU un article.');
        }
        if ($quantiteMillimes <= 0) {
            throw new \InvalidArgumentException('La quantité doit être positive.');
        }

        $valorisation = $this->valorisation->valoriser($matiere, $article, $quantiteMillimes);

        $perte = new Perte($motif, $quantiteMillimes, $valorisation);
        $perte->setMatierePremiere($matiere)->setArticle($article);
        $this->em->persist($perte);
        $this->em->flush(); // pour disposer de l'id (source du mouvement)

        $mouvement = new MouvementStock(TypeMouvementStock::PERTE, -$quantiteMillimes);
        $mouvement
            ->setMatierePremiere($matiere)
            ->setArticle($article)
            ->setMotif($this->texteMotif($motif, $commentaire))
            ->setSource('perte', $perte->getId());
        $this->em->persist($mouvement);

        // Décrément du stock : matière toujours, article seulement s'il est suivi.
        if (null !== $matiere) {
            $matiere->setStockActuel($matiere->getStockActuel() - $quantiteMillimes);
        } elseif (null !== $article && $article->isSuiviStock()) {
            $article->setStockActuel($article->getStockActuel() - $quantiteMillimes);
        }

        $this->em->flush();

        return $perte;
    }

    private function texteMotif(MotifPerte $motif, ?string $commentaire): string
    {
        $texte = 'Perte ('.$motif->libelle().')';
        $commentaire = trim((string) $commentaire);

        return '' !== $commentaire ? $texte.' — '.$commentaire : $texte;
    }
}
