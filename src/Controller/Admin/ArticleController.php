<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\FicheTechnique;
use App\Entity\LigneFicheTechnique;
use App\Controller\Trait\ReponseFormulaire;
use App\Form\ArticleType;
use App\Form\LigneFicheTechniqueType;
use App\Repository\ArticleRepository;
use App\Repository\FamilleProduitRepository;
use App\Security\Permission;
use App\Service\AuditLogger;
use App\Service\CalculateurCoutMatiere;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/articles')]
#[IsGranted('ROLE_GERANT')]
class ArticleController extends AbstractController
{
    use ReponseFormulaire;

    #[Route('', name: 'admin_article_index', methods: ['GET'])]
    public function index(
        Request $request,
        ArticleRepository $articles,
        FamilleProduitRepository $familles,
        CalculateurCoutMatiere $calculateur,
    ): Response {
        $familleId = $request->query->get('famille');
        $famille = $familleId ? $familles->find($familleId) : null;
        $recherche = $request->query->get('q');
        $statut = $request->query->get('statut');
        $actif = match ($statut) {
            'actifs' => true,
            'inactifs' => false,
            default => null,
        };

        $resultats = $articles->rechercher($famille, $recherche, $actif);

        // Les coûts ne sont même pas calculés pour qui n'a pas le droit de les voir :
        // rien ne peut alors fuiter par le gabarit.
        $couts = [];
        if ($this->isGranted(Permission::ARTICLE_VOIR_COUT)) {
            foreach ($resultats as $article) {
                $couts[$article->getId()] = $calculateur->calculer($article);
            }
        }

        return $this->render('admin/article/index.html.twig', [
            'articles' => $resultats,
            'couts' => $couts,
            'familles' => $familles->findBy([], ['position' => 'ASC']),
            'famille_active' => $famille,
            'recherche' => $recherche,
            'statut' => $statut,
        ]);
    }

    #[Route('/nouveau', name: 'admin_article_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $article = new Article('', 0, 'pièce');
        $peutFixerPrix = $this->isGranted(Permission::ARTICLE_MODIFIER_PRIX, $article);

        $form = $this->createForm(ArticleType::class, $article, ['modifier_prix' => $peutFixerPrix]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Sans habilitation sur le prix, l'article naît à 0 FCFA : on le force
            // inactif pour qu'il ne parte pas gratuitement en caisse. Créer puis
            // recréer un article ne permet donc pas de contourner la règle de prix.
            if (!$peutFixerPrix) {
                $article->setActif(false);
                $this->addFlash('success', 'Article créé sans prix : il reste inactif jusqu\'à ce que la dirigeante fixe son prix de vente.');
            } else {
                $this->addFlash('success', 'Article « '.$article->getNom().' » créé.');
            }

            $em->persist($article);
            $em->flush();

            return $this->redirectToRoute('admin_article_show', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->rendreFormulaire('admin/article/form.html.twig', $form, ['titre' => 'Nouvel article']);
    }

    #[Route('/{id}', name: 'admin_article_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Article $article): Response
    {
        return $this->render('admin/article/show.html.twig', [
            'article' => $article,
            'ligne_form' => $this->creerFicheForm($article)->createView(),
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_article_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Article $article, EntityManagerInterface $em, AuditLogger $audit): Response
    {
        // Relevé avant liaison du formulaire : ensuite l'entité porte le nouveau prix.
        $ancienPrix = $article->getPrixVenteTtc();

        $form = $this->createForm(ArticleType::class, $article, [
            'modifier_prix' => $this->isGranted(Permission::ARTICLE_MODIFIER_PRIX, $article),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            if ($article->getPrixVenteTtc() !== $ancienPrix) {
                $audit->prixModifie($article, $ancienPrix, $article->getPrixVenteTtc());
            }

            $this->addFlash('success', 'Article mis à jour.');

            return $this->redirectToRoute('admin_article_show', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->rendreFormulaire('admin/article/form.html.twig', $form, ['titre' => 'Modifier l\'article', 'article' => $article]);
    }

    #[Route('/{id}/basculer', name: 'admin_article_toggle', methods: ['POST'])]
    public function toggle(Request $request, Article $article, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('basculer_article_'.$article->getId(), (string) $request->request->get('_token'))) {
            $article->setActif(!$article->isActif());
            $em->flush();
            $this->addFlash('success', $article->isActif() ? 'Article activé.' : 'Article désactivé.');
        }

        return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('admin_article_index'));
    }

    #[Route('/{id}/supprimer', name: 'admin_article_delete', methods: ['POST'])]
    public function delete(Request $request, Article $article, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('supprimer_article_'.$article->getId(), (string) $request->request->get('_token'))) {
            $em->remove($article);
            $em->flush();
            $this->addFlash('success', 'Article supprimé.');
        }

        return $this->redirectToRoute('admin_article_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/fiche/lignes', name: 'admin_article_fiche_add', methods: ['POST'])]
    public function ficheAdd(Request $request, Article $article, EntityManagerInterface $em): Response
    {
        $fiche = $this->obtenirFiche($article, $em);
        $form = $this->creerFicheForm($article);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $donnees = $form->getData();
            new LigneFicheTechnique($fiche, $donnees['matierePremiere'], (int) $donnees['quantite'], (int) ($donnees['pourcentagePerte'] ?? 0));
            $em->flush();
            $this->addFlash('success', 'Matière première ajoutée à la fiche technique.');
            $form = $this->creerFicheForm($article);
        }

        return $this->rendreFiche($request, $article, $form);
    }

    #[Route('/{id}/fiche/lignes/{ligneId}/retirer', name: 'admin_article_fiche_remove', methods: ['POST'])]
    public function ficheRemove(
        Request $request,
        Article $article,
        #[MapEntity(id: 'ligneId')] LigneFicheTechnique $ligne,
        EntityManagerInterface $em,
    ): Response {
        if ($ligne->getFicheTechnique()->getArticle() !== $article) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('retirer_ligne_'.$ligne->getId(), (string) $request->request->get('_token'))) {
            $em->remove($ligne);
            $em->flush();
            $this->addFlash('success', 'Matière première retirée de la fiche technique.');
        }

        return $this->rendreFiche($request, $article, $this->creerFicheForm($article));
    }

    private function creerFicheForm(Article $article): FormInterface
    {
        return $this->createForm(LigneFicheTechniqueType::class, null, [
            'action' => $this->generateUrl('admin_article_fiche_add', ['id' => $article->getId()]),
        ]);
    }

    private function obtenirFiche(Article $article, EntityManagerInterface $em): FicheTechnique
    {
        $fiche = $article->getFicheTechnique();
        if (null === $fiche) {
            $fiche = new FicheTechnique($article);
            $em->persist($fiche);
        }

        return $fiche;
    }

    /**
     * Renvoie le fragment Turbo (frame) pour une requête Turbo, sinon redirige
     * vers la fiche article (dégradation gracieuse sans JavaScript).
     */
    private function rendreFiche(Request $request, Article $article, FormInterface $form): Response
    {
        if ($request->headers->has('Turbo-Frame')) {
            return $this->render('admin/article/_fiche_frame.html.twig', [
                'article' => $article,
                'ligne_form' => $form->createView(),
            ]);
        }

        return $this->redirectToRoute('admin_article_show', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
