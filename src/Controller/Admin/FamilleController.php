<?php

namespace App\Controller\Admin;

use App\Entity\FamilleProduit;
use App\Form\FamilleProduitType;
use App\Controller\Trait\ReponseFormulaire;
use App\Repository\FamilleProduitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/familles')]
#[IsGranted('ROLE_GERANT')]
class FamilleController extends AbstractController
{
    use ReponseFormulaire;

    #[Route('', name: 'admin_famille_index', methods: ['GET'])]
    public function index(Request $request, FamilleProduitRepository $familles): Response
    {
        return $this->render('admin/famille/index.html.twig', [
            'familles' => $familles->paginees(
                $request->query->getInt('page', 1),
                $request->query->get('q'),
            ),
        ]);
    }

    #[Route('/nouvelle', name: 'admin_famille_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $famille = new FamilleProduit('');
        $form = $this->createForm(FamilleProduitType::class, $famille);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($famille);
            $em->flush();
            $this->addFlash('success', 'Famille « '.$famille->getNom().' » créée.');

            return $this->redirectToRoute('admin_famille_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->rendreFormulaire('admin/famille/form.html.twig', $form, [
            'famille' => $famille,
            'titre' => 'Nouvelle famille',
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_famille_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, FamilleProduit $famille, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(FamilleProduitType::class, $famille);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Famille mise à jour.');

            return $this->redirectToRoute('admin_famille_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->rendreFormulaire('admin/famille/form.html.twig', $form, [
            'famille' => $famille,
            'titre' => 'Modifier la famille',
        ]);
    }

    #[Route('/{id}/supprimer', name: 'admin_famille_delete', methods: ['POST'])]
    public function delete(Request $request, FamilleProduit $famille, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('supprimer_famille_'.$famille->getId(), (string) $request->request->get('_token'))) {
            if (!$famille->getArticles()->isEmpty()) {
                $this->addFlash('error', 'Impossible de supprimer une famille contenant des articles.');

                return $this->redirectToRoute('admin_famille_index', [], Response::HTTP_SEE_OTHER);
            }
            $em->remove($famille);
            $em->flush();
            $this->addFlash('success', 'Famille supprimée.');
        }

        return $this->redirectToRoute('admin_famille_index', [], Response::HTTP_SEE_OTHER);
    }
}
