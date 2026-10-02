<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Repository\FicheProductionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * La fiche de production d'une caisse : une ligne par article d'atelier, avec ce
 * qui a été produit et ce qui a été mis en vitrine.
 *
 * Elle naît **à l'ouverture de la caisse**, toutes les lignes à zéro, et vit
 * avec elle : le boulanger, le pâtissier et la gérante y ajoutent leurs
 * quantités au fil de la journée ({@see SaisieProduction}, une par fournée ou
 * par réassort). Le vendu n'y est pas écrit — il se lit dans les ventes de la
 * caisse, en direct, et ne bloque jamais une vente.
 *
 * Après le Z, plus rien ne s'y déclare : la caisse suivante ouvre sa propre
 * fiche, à zéro. Seule l'annulation d'une déclaration fautive y touche encore,
 * tant que le point de vitrine n'est pas fait ({@see \App\Service\ProductionService}).
 */
#[ORM\Entity(repositoryClass: FicheProductionRepository::class)]
#[ORM\Table(name: 'fiche_production')]
#[ORM\UniqueConstraint(name: 'uniq_fiche_production_session', columns: ['session_caisse_id'])]
class FicheProduction
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: SessionCaisse::class)]
    #[ORM\JoinColumn(nullable: false)]
    private SessionCaisse $sessionCaisse;

    /** @var Collection<int, LigneFicheProduction> */
    #[ORM\OneToMany(mappedBy: 'fiche', targetEntity: LigneFicheProduction::class, cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lignes;

    /**
     * @param iterable<Article> $articles les articles d'atelier, tous à zéro
     */
    public function __construct(SessionCaisse $sessionCaisse, iterable $articles)
    {
        $this->sessionCaisse = $sessionCaisse;
        $this->createdAt = new \DateTimeImmutable();
        $this->lignes = new ArrayCollection();

        foreach ($articles as $article) {
            $this->ligneDe($article);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSessionCaisse(): SessionCaisse
    {
        return $this->sessionCaisse;
    }

    /** @return Collection<int, LigneFicheProduction> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    /**
     * La ligne de l'article, créée à zéro si elle manque : un article ajouté au
     * catalogue après l'ouverture se déclare quand même.
     */
    public function ligneDe(Article $article): LigneFicheProduction
    {
        foreach ($this->lignes as $ligne) {
            if ($ligne->getArticle() === $article) {
                return $ligne;
            }
        }

        $ligne = new LigneFicheProduction($this, $article);
        $this->lignes->add($ligne);

        return $ligne;
    }
}
