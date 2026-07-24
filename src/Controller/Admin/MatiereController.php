<?php

namespace App\Controller\Admin;

use App\Entity\MatierePremiere;
use App\Form\MatierePremiereType;
use App\Repository\MatierePremiereRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/stock')]
#[IsGranted('ROLE_GERANT')]
class MatiereController extends AbstractController
{
    #[Route('', name: 'admin_matiere_index', methods: ['GET'])]
    public function index(MatierePremiereRepository $matieres): Response
    {
        return $this->render('admin/matiere/index.html.twig', [
            'matieres' => $matieres->findBy([], ['nom' => 'ASC']),
        ]);
    }

    #[Route('/nouvelle', name: 'admin_matiere_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $matiere = new MatierePremiere();
        $form = $this->createForm(MatierePremiereType::class, $matiere);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($matiere);
            $em->flush();
            $this->addFlash('success', 'Matière première « '.$matiere->getNom().' » créée.');

            return $this->redirectToRoute('admin_matiere_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/matiere/form.html.twig', [
            'form' => $form,
            'titre' => 'Nouvelle matière première',
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_matiere_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, MatierePremiere $matiere, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(MatierePremiereType::class, $matiere);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Matière première mise à jour.');

            return $this->redirectToRoute('admin_matiere_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/matiere/form.html.twig', [
            'form' => $form,
            'titre' => 'Modifier la matière première',
        ]);
    }

    #[Route('/{id}/supprimer', name: 'admin_matiere_delete', methods: ['POST'])]
    public function delete(Request $request, MatierePremiere $matiere, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('supprimer_matiere_'.$matiere->getId(), (string) $request->request->get('_token'))) {
            $em->remove($matiere);
            $em->flush();
            $this->addFlash('success', 'Matière première supprimée.');
        }

        return $this->redirectToRoute('admin_matiere_index', [], Response::HTTP_SEE_OTHER);
    }
}
