<?php

namespace App\Controller\Admin;

use App\Entity\Fournisseur;
use App\Form\FournisseurType;
use App\Controller\Trait\ReponseFormulaire;
use App\Repository\FournisseurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/fournisseurs')]
#[IsGranted('ROLE_GERANT')]
class FournisseurController extends AbstractController
{
    use ReponseFormulaire;

    #[Route('', name: 'admin_fournisseur_index', methods: ['GET'])]
    public function index(FournisseurRepository $fournisseurs): Response
    {
        return $this->render('admin/fournisseur/index.html.twig', [
            'fournisseurs' => $fournisseurs->findBy([], ['nom' => 'ASC']),
        ]);
    }

    #[Route('/nouveau', name: 'admin_fournisseur_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $fournisseur = new Fournisseur();
        $form = $this->createForm(FournisseurType::class, $fournisseur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($fournisseur);
            $em->flush();
            $this->addFlash('success', 'Fournisseur « '.$fournisseur->getNom().' » créé.');

            return $this->redirectToRoute('admin_fournisseur_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->rendreFormulaire('admin/fournisseur/form.html.twig', $form, [
            'titre' => 'Nouveau fournisseur',
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_fournisseur_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Fournisseur $fournisseur, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(FournisseurType::class, $fournisseur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Fournisseur mis à jour.');

            return $this->redirectToRoute('admin_fournisseur_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->rendreFormulaire('admin/fournisseur/form.html.twig', $form, [
            'titre' => 'Modifier le fournisseur',
        ]);
    }

    #[Route('/{id}/supprimer', name: 'admin_fournisseur_delete', methods: ['POST'])]
    public function delete(Request $request, Fournisseur $fournisseur, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('supprimer_fournisseur_'.$fournisseur->getId(), (string) $request->request->get('_token'))) {
            $em->remove($fournisseur);
            $em->flush();
            $this->addFlash('success', 'Fournisseur supprimé.');
        }

        return $this->redirectToRoute('admin_fournisseur_index', [], Response::HTTP_SEE_OTHER);
    }
}
