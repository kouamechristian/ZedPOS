<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    /**
     * Connexion classique email / mot de passe (dirigeante, gérant, comptable).
     */
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        return $this->render('security/login.html.twig', [
            'derniere_saisie' => $authenticationUtils->getLastUsername(),
            'erreur' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Connexion rapide caissier par code PIN (pavé numérique plein écran).
     * Le POST est intercepté par le CaisseAuthenticator.
     */
    #[Route('/caisse/login', name: 'app_caisse_login', methods: ['GET', 'POST'])]
    public function caisseLogin(): Response
    {
        return $this->render('security/caisse_login.html.twig');
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Cette méthode est interceptée par la clé "logout" du pare-feu.');
    }
}
