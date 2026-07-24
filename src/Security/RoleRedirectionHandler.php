<?php

namespace App\Security;

use App\Enum\RoleUtilisateur;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Redirige l'utilisateur après connexion vers l'espace correspondant à son rôle :
 *   caissier   -> /caisse
 *   gérant     -> /admin
 *   dirigeante -> /pilotage
 *   comptable  -> /comptabilite
 */
class RoleRedirectionHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        return new RedirectResponse($this->urlPour($token->getUser()));
    }

    /**
     * URL de destination selon le rôle le plus élevé de l'utilisateur.
     */
    public function urlPour(?UserInterface $user): string
    {
        $roles = null !== $user ? $user->getRoles() : [];

        $route = match (true) {
            \in_array(RoleUtilisateur::DIRIGEANTE->value, $roles, true) => 'app_pilotage',
            \in_array(RoleUtilisateur::GERANT->value, $roles, true) => 'admin_dashboard',
            \in_array(RoleUtilisateur::COMPTABLE->value, $roles, true) => 'app_comptabilite',
            \in_array(RoleUtilisateur::CAISSIER->value, $roles, true) => 'app_caisse',
            default => 'app_login',
        };

        return $this->urlGenerator->generate($route);
    }
}
