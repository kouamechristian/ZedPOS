<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\Inventaire;
use App\Entity\LigneInventaire;
use App\Entity\MatierePremiere;
use App\Entity\Utilisateur;
use App\Enum\TypeMouvementStock;
use App\Repository\ArticleRepository;
use App\Repository\InventaireRepository;
use App\Repository\MatierePremiereRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ouverture, saisie et validation d'une feuille d'inventaire.
 *
 * C'est le seul chemin par lequel un stock se corrige : la modification directe
 * de `stockActuel` ne créait aucun mouvement et n'était pas auditée, si bien que
 * l'historique divergeait du stock affiché sans que rien ne le signale.
 *
 * L'inventaire compte le **dépôt principal** : les écarts y sont reportés en
 * AJUSTEMENT, et refusés en bloc si l'un d'eux laissait un stock négatif.
 */
class InventaireService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InventaireRepository $inventaires,
        private readonly MatierePremiereRepository $matieres,
        private readonly ArticleRepository $articles,
        private readonly AuditLogger $audit,
        private readonly StockManager $stock,
    ) {
    }

    /**
     * Ouvre une feuille et y fige l'état théorique de tout ce qui est suivi en
     * stock : les matières premières, et les articles revendus tels quels
     * (`suiviStock`) — une boisson dérive autant qu'un sac de farine.
     *
     * @throws \DomainException si une feuille est déjà ouverte
     */
    public function ouvrir(Utilisateur $auteur): Inventaire
    {
        // Deux feuilles ouvertes en parallèle figeraient le même théorique, et la
        // seconde validation écraserait les écarts de la première.
        if (null !== $this->inventaires->enCours()) {
            throw new \DomainException('Un inventaire est déjà en cours : validez-le ou supprimez-le avant d\'en ouvrir un autre.');
        }

        $inventaire = new Inventaire($auteur);

        foreach ($this->matieres->findBy([], ['nom' => 'ASC']) as $matiere) {
            (new LigneInventaire(
                $inventaire,
                $matiere->getNom(),
                $matiere->getUniteStock(),
                $matiere->getStockActuel(),
                $matiere->getCoutMoyenPondere(),
            ))->setMatierePremiere($matiere);
        }

        foreach ($this->articles->findBy(['suiviStock' => true], ['nom' => 'ASC']) as $article) {
            (new LigneInventaire(
                $inventaire,
                $article->getNom(),
                $article->getUnite(),
                $article->getStockActuel(),
                // Un article revendu en l'état n'a pas de coût de revient calculé :
                // son prix de vente sert de repère, faute de mieux. L'écart valorisé
                // dit alors « ce que ça représente en chiffre d'affaires perdu ».
                $article->getPrixVenteTtc(),
            ))->setArticle($article);
        }

        if ([] === $inventaire->getLignes()->toArray()) {
            throw new \DomainException('Rien n\'est suivi en stock : il n\'y a pas d\'inventaire à faire.');
        }

        $this->em->persist($inventaire);
        $this->em->flush();

        return $inventaire;
    }

    /**
     * Enregistre les quantités comptées. Une valeur `null` remet la ligne à « non
     * comptée » — la feuille se remplit en plusieurs fois, on doit pouvoir revenir
     * sur une saisie.
     *
     * @param array<int, ?int> $comptages quantités en millièmes, par id de ligne
     */
    public function saisir(Inventaire $inventaire, array $comptages): void
    {
        $inventaire->garantirEnCours();

        foreach ($inventaire->getLignes() as $ligne) {
            $id = $ligne->getId();
            if (\array_key_exists($id, $comptages)) {
                $ligne->compter($comptages[$id]);
            }
        }

        $this->em->flush();
    }

    /**
     * Valide la feuille : les écarts deviennent des mouvements de stock.
     *
     * L'écart est appliqué **en delta**, jamais en écrasant `stockActuel` avec la
     * quantité comptée. Entre le comptage et la validation, des ventes ont pu
     * déstocker : poser la quantité comptée telle quelle les effacerait. Le
     * comptage constate un écart à un instant donné, c'est cet écart qu'on reporte.
     *
     * @throws \DomainException si la feuille est déjà validée, si rien n'a été
     *                          compté, si un écart est constaté sans commentaire,
     *                          ou si un écart laisserait un stock négatif
     *                          ({@see StockInsuffisantException})
     */
    public function valider(Inventaire $inventaire, Utilisateur $validePar, ?string $commentaire = null): Inventaire
    {
        // `valider()` porte les règles qui ne dépendent d'aucun appelant et lève
        // avant toute écriture : rien n'est appliqué si la feuille est refusée.
        $inventaire->valider($validePar, $commentaire);

        $lignes = $inventaire->lignesAvecEcart();
        $depot = $this->stock->depotPrincipal();
        $demandes = [];

        foreach ($lignes as $ligne) {
            $demandes[] = new DemandeMouvementStock(
                $this->produit($ligne),
                $depot,
                $ligne->ecart(),
                TypeMouvementStock::AJUSTEMENT,
                'inventaire',
                $inventaire->getId(),
                'Inventaire n° '.$inventaire->getId(),
            );
        }

        try {
            // Un seul lot : toute la feuille passe, ou rien. Le passage de la
            // feuille à « validée » part dans la même écriture.
            if ([] === $demandes) {
                $this->em->flush();
            } else {
                $this->stock->enregistrerMouvements($demandes, $validePar);
            }
        } catch (\DomainException $e) {
            // Refus (stock insuffisant) : rien n'a été écrit, mais la feuille est
            // marquée validée en mémoire. On la relit, sans quoi l'écran afficherait
            // validée une feuille qui ne l'est pas.
            $this->em->refresh($inventaire);

            throw $e;
        }

        foreach ($lignes as $ligne) {
            $produit = $this->produit($ligne);
            $apres = $produit->getStockActuel();

            $this->audit->ecartInventaire(
                $produit instanceof MatierePremiere ? 'MatierePremiere' : 'Article',
                $produit->getId(),
                $ligne->getLibelle(),
                $apres - $ligne->ecart(),
                $apres,
                (string) $inventaire->getCommentaire(),
                $inventaire->getId(),
            );
        }

        return $inventaire;
    }

    private function produit(LigneInventaire $ligne): Article|MatierePremiere
    {
        return $ligne->getMatierePremiere() ?? $ligne->getArticle()
            ?? throw new \LogicException('Ligne d\'inventaire sans produit.');
    }

    /**
     * Abandonne une feuille non validée. Aucune écriture de stock n'a eu lieu :
     * il n'y a rien à défaire, et garder des brouillons abandonnés ne rendrait
     * service à personne.
     */
    public function abandonner(Inventaire $inventaire): void
    {
        $inventaire->garantirEnCours();

        $this->em->remove($inventaire);
        $this->em->flush();
    }
}
