<?php

namespace App\Controller;

use App\Security\RoleRedirectionHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages d'accueil des différents espaces (protégées par access_control dans security.yaml).
 * Ce sont pour l'instant des points d'atterrissage minimaux après connexion.
 */
class DashboardController extends AbstractController
{
    /**
     * Racine : redirige vers l'espace du rôle, ou vers la connexion si anonyme.
     */
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(RoleRedirectionHandler $redirection): RedirectResponse
    {
        if (null === $this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        return new RedirectResponse($redirection->urlPour($this->getUser()));
    }

    // L'espace de pilotage est servi par App\Controller\Pilotage\TableauDeBordController,
    // l'espace comptable par App\Controller\Comptabilite\ExportController.
}
