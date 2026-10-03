<?php

namespace App\Controller\Magasin;

use App\Controller\Trait\ReponseFormulaire;
use App\Entity\Magasin\MagasinProduit;
use App\Enum\ActionAudit;
use App\Enum\Magasin\CategorieProduitMagasin;
use App\Form\Magasin\ProduitType;
use App\Repository\Magasin\MagasinProduitRepository;
use App\Security\Permission;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Référentiel des produits du magasin.
 *
 * Un produit ne se supprime pas : il a, ou aura, un historique de mouvements. Il
 * se **désactive** — il disparaît alors des listes de saisie, mais son stock et
 * ses mouvements restent lisibles.
 */
#[Route('/magasin/produits')]
#[IsGranted(Permission::MAGASIN_VOIR)]
class ProduitController extends AbstractController
{
    use ReponseFormulaire;

    public function __construct(
        private readonly MagasinProduitRepository $produits,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'magasin_produit_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $categorie = CategorieProduitMagasin::tryFrom((string) $request->query->get('categorie'));

        return $this->render('magasin/produit/index.html.twig', [
            'produits' => $this->produits->pagines($request->query->getInt('page', 1), $request->query->get('q'), $categorie),
            'categorie' => $categorie,
            'categories' => CategorieProduitMagasin::cases(),
        ]);
    }

    #[Route('/nouveau', name: 'magasin_produit_new', methods: ['GET', 'POST'])]
    #[IsGranted(Permission::MAGASIN_REFERENTIEL)]
    public function nouveau(Request $request): Response
    {
        $produit = new MagasinProduit('', CategorieProduitMagasin::MATIERE, '');
        $form = $this->createForm(ProduitType::class, $produit);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->nomLibre($form, $produit)) {
            $this->em->persist($produit);
            $this->em->flush();
            $this->audit->enregistrer(ActionAudit::MAGASIN_PRODUIT_ENREGISTRE, 'MagasinProduit', $produit->getId(), null, $this->etat($produit));

            $this->addFlash('success', \sprintf('Produit « %s » créé.', $produit->getNom()));

            return $this->redirectToRoute('magasin_produit_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->rendreFormulaire('magasin/produit/form.html.twig', $form, ['titre' => 'Nouveau produit', 'produit' => null]);
    }

    #[Route('/{id}/modifier', name: 'magasin_produit_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Permission::MAGASIN_REFERENTIEL)]
    public function modifier(Request $request, MagasinProduit $produit): Response
    {
        $avant = $this->etat($produit);
        $form = $this->createForm(ProduitType::class, $produit);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->nomLibre($form, $produit)) {
            $this->em->flush();
            if ($avant !== $this->etat($produit)) {
                $this->audit->enregistrer(ActionAudit::MAGASIN_PRODUIT_ENREGISTRE, 'MagasinProduit', $produit->getId(), $avant, $this->etat($produit));
            }

            $this->addFlash('success', \sprintf('Produit « %s » mis à jour.', $produit->getNom()));

            return $this->redirectToRoute('magasin_produit_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->rendreFormulaire('magasin/produit/form.html.twig', $form, ['titre' => 'Modifier le produit', 'produit' => $produit]);
    }

    /**
     * Deux produits du même nom seraient indiscernables dans une liste de saisie :
     * comparé sans égard à la casse. Le refus devient une erreur du champ (422).
     */
    private function nomLibre(FormInterface $form, MagasinProduit $produit): bool
    {
        $qb = $this->produits->createQueryBuilder('p')
            ->andWhere('LOWER(p.nom) = LOWER(:nom)')->setParameter('nom', $produit->getNom())
            ->setMaxResults(1);
        if (null !== $produit->getId()) {
            $qb->andWhere('p.id <> :id')->setParameter('id', $produit->getId());
        }
        $existant = $qb->getQuery()->getOneOrNullResult();

        if (null === $existant) {
            return true;
        }

        $form->get('nom')->addError(new FormError('Un produit porte déjà ce nom.'));

        return false;
    }

    /** @return array<string, mixed> l'état tracé au journal ; le coût moyen n'y figure pas, il ne se saisit pas ici */
    private function etat(MagasinProduit $produit): array
    {
        return [
            'nom' => $produit->getNom(),
            'categorie' => $produit->getCategorie()->value,
            'uniteStock' => $produit->getUniteStock(),
            'uniteAchat' => $produit->getUniteAchat(),
            'contenanceAchat' => $produit->getContenanceAchat(),
            'seuilAlerte' => $produit->getSeuilAlerte(),
            'fournisseurHabituel' => $produit->getFournisseurHabituel()?->getNom(),
            'actif' => $produit->isActif(),
        ];
    }
}
