<?php

namespace App\Controller;

use App\Controller\Trait\ReponseFormulaire;
use App\Enum\RoleUtilisateur;
use App\Form\InstallationType;
use App\Repository\UtilisateurRepository;
use App\Service\CreationUtilisateur;
use App\Service\CreationUtilisateurException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Amorçage : création du premier compte de l'application.
 *
 * Sur une base vierge, personne ne peut se connecter — et personne ne peut donc
 * créer de compte, puisque `/admin/utilisateurs` exige d'être déjà gérant. C'est
 * l'œuf et la poule ; cet écran est la seule porte d'entrée.
 *
 * **Il se referme dès qu'un compte existe.** La route est publique par nécessité :
 * si elle restait ouverte, n'importe qui s'ouvrirait un accès dirigeante à tout
 * moment — la caisse, les prix, les comptes de l'établissement.
 */
class InstallationController extends AbstractController
{
    use ReponseFormulaire;

    #[Route('/installation', name: 'app_installation', methods: ['GET', 'POST'])]
    public function installer(
        Request $request,
        UtilisateurRepository $utilisateurs,
        CreationUtilisateur $creation,
    ): Response {
        // 404 plutôt que 403 : inutile d'annoncer à un visiteur qu'il existe ici
        // un écran de création de compte, même fermé.
        if (!$utilisateurs->aucunCompte()) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(InstallationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $donnees = $form->getData();

            try {
                // Même service que partout ailleurs : unicité, hachage et trace
                // d'audit ne se réimplémentent pas pour le premier compte.
                $utilisateur = $creation->creer(
                    (string) $donnees['email'],
                    (string) $donnees['nom'],
                    RoleUtilisateur::DIRIGEANTE,
                    (string) $donnees['motDePasse'],
                );
            } catch (CreationUtilisateurException $e) {
                $form->addError(new FormError($e->getMessage()));

                return $this->rendreFormulaire('installation.html.twig', $form);
            }

            $this->addFlash('success', \sprintf(
                'Compte dirigeante « %s » créé. Connectez-vous pour commencer.',
                $utilisateur->getNom(),
            ));

            // Pas de connexion automatique : mieux vaut que le mot de passe soit
            // vérifié tout de suite, tant qu'on l'a encore en tête, plutôt qu'au
            // premier retour sur la caisse.
            return $this->redirectToRoute('app_login', [], Response::HTTP_SEE_OTHER);
        }

        return $this->rendreFormulaire('installation.html.twig', $form);
    }
}
