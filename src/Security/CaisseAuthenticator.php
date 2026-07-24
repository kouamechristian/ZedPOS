<?php

namespace App\Security;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Repository\UtilisateurRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authentifie un caissier à partir de son seul code PIN à 4 chiffres, saisi sur
 * le pavé numérique plein écran (POST /caisse/login).
 *
 * Le PIN étant haché, on ne peut pas retrouver l'utilisateur par requête directe :
 * on vérifie le PIN saisi contre le hash de chaque caissier actif (petit effectif).
 */
class CaisseAuthenticator extends AbstractAuthenticator
{
    public const CHECK_ROUTE = 'app_caisse_login';
    private const CHAMP_PIN = 'code_pin';

    public function __construct(
        private readonly UtilisateurRepository $utilisateurs,
        private readonly PasswordHasherFactoryInterface $hasherFactory,
        private readonly RoleRedirectionHandler $redirection,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->isMethod('POST') && '/caisse/login' === $request->getPathInfo();
    }

    public function authenticate(Request $request): Passport
    {
        $pin = (string) $request->request->get(self::CHAMP_PIN, '');
        $csrfToken = (string) $request->request->get('_csrf_token', '');

        if (1 !== preg_match('/^\d{4}$/', $pin)) {
            throw new CustomUserMessageAuthenticationException('Le code PIN doit comporter 4 chiffres.');
        }

        $utilisateur = $this->trouverCaissierParPin($pin);

        if (null === $utilisateur) {
            throw new CustomUserMessageAuthenticationException('Code PIN incorrect.');
        }

        return new SelfValidatingPassport(
            new UserBadge($utilisateur->getUserIdentifier(), static fn () => $utilisateur),
            [new CsrfTokenBadge('authenticate', $csrfToken)],
        );
    }

    private function trouverCaissierParPin(string $pin): ?Utilisateur
    {
        $hasher = $this->hasherFactory->getPasswordHasher(Utilisateur::class);

        foreach ($this->utilisateurs->findActifsAvecCodePin() as $utilisateur) {
            if (!\in_array(RoleUtilisateur::CAISSIER->value, $utilisateur->getRoles(), true)) {
                continue;
            }

            $hash = $utilisateur->getCodePin();
            if (null !== $hash && $hasher->verify($hash, $pin)) {
                return $utilisateur;
            }
        }

        return null;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return $this->redirection->onAuthenticationSuccess($request, $token);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add('caisse_erreur', $exception->getMessageKey());
        }

        return new RedirectResponse($this->urlGenerator->generate(self::CHECK_ROUTE));
    }
}
