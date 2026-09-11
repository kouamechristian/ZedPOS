<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\MatierePremiere;
use App\Entity\Perte;
use App\Enum\MotifPerte;
use App\Enum\TypeMouvementStock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enregistre une perte : crée la Perte valorisée automatiquement et, pour ce qui
 * est suivi en stock, un MouvementStock PERTE au dépôt principal.
 */
class PerteService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValorisationService $valorisation,
        private readonly AuditLogger $audit,
        private readonly StockManager $stock,
    ) {
    }

    /**
     * @throws StockInsuffisantException si la perte dépasse le stock du dépôt
     */
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

        // Matière toujours ; article seulement s'il est suivi en stock. Un pain
        // fabriqué n'a pas de stock à décrémenter : la perte est valorisée, sans
        // mouvement — un mouvement sans stock en face ferait mentir l'historique.
        $produit = $matiere ?? ($article->isSuiviStock() ? $article : null);

        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();

        try {
            // Le mouvement d'abord : s'il est refusé pour stock insuffisant, la perte
            // n'a pas encore été écrite et il n'y a rien à défaire.
            $mouvement = null !== $produit
                ? $this->stock->enregistrerMouvement(
                    $produit,
                    $this->stock->depotPrincipal(),
                    -$quantiteMillimes,
                    TypeMouvementStock::PERTE,
                    motif: $this->texteMotif($motif, $commentaire),
                )
                : null;

            $perte = new Perte($motif, $quantiteMillimes, $valorisation);
            $perte->setMatierePremiere($matiere)->setArticle($article);
            $this->em->persist($perte);
            $this->em->flush();

            $mouvement?->rattacherDocument('perte', (int) $perte->getId());
            $this->em->flush();

            $connexion->commit();
        } catch (\Throwable $e) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }

            throw $e;
        }

        $this->audit->perteSaisie($perte, $commentaire);

        return $perte;
    }

    private function texteMotif(MotifPerte $motif, ?string $commentaire): string
    {
        $texte = 'Perte ('.$motif->libelle().')';
        $commentaire = trim((string) $commentaire);

        return '' !== $commentaire ? $texte.' — '.$commentaire : $texte;
    }
}
