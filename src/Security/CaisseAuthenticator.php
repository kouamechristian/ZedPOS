<?php

namespace App\Security;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Repository\UtilisateurRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
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
 *
 * **La limitation du nombre d'essais est portée ici**, et non par le
 * `login_throttling` du pare-feu, pour deux raisons :
 *
 *   - il compte par identifiant saisi, or un PIN n'en porte aucun. Il ne reste
 *     que l'adresse IP ;
 *   - surtout, il s'accroche à `CheckPassportEvent`. Sur un PIN inconnu, cette
 *     méthode lève **avant** de construire le passeport : l'événement ne part
 *     jamais, et pas un seul échec ne serait compté. La porte à 10 000
 *     combinaisons serait restée la seule sans serrure.
 *
 * Le compte est tenu **des seuls échecs**, et il est consulté avant de comparer
 * quoi que ce soit. Deux conséquences voulues :
 *
 *   - une caissière qui tape juste ne consomme rien, même après s'être trompée
 *     la veille — la caisse ne se bloque pas d'elle-même ;
 *   - un attaquant est écarté sans que le serveur ait à hacher son essai contre
 *     chaque caissier. Une tentative coûte autant de vérifications de hachage
 *     qu'il y a de comptes de caisse : les compter d'abord évite d'en faire une
 *     voie d'épuisement du processeur.
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
        private readonly RateLimiterFactoryInterface $connexionCaisseLimiter,
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

        // Le quota est consulté sans être consommé : c'est l'échec qui coûte un
        // jeton, plus bas, jamais la tentative elle-même.
        //
        // On lit les jetons restants, et non `isAccepted()` : consommer zéro
        // jeton est toujours accepté, par construction — le limiteur répond
        // « oui, je peux ne rien te donner ». Se fier à cette réponse laissait
        // passer toutes les tentatives, et la limite ne servait à rien.
        $limiteur = $this->connexionCaisseLimiter->create($request->getClientIp());
        if ($limiteur->consume(0)->getRemainingTokens() <= 0) {
            throw new CustomUserMessageAuthenticationException('Trop de codes erronés. Patientez quelques minutes ou appelez le gérant.');
        }

        if (1 !== preg_match('/^\d{4}$/', $pin)) {
            // Une saisie qui n'est même pas un PIN ne consomme rien : c'est un
            // appui malheureux sur « Valider », pas un essai.
            throw new CustomUserMessageAuthenticationException('Le code PIN doit comporter 4 chiffres.');
        }

        $utilisateur = $this->trouverCaissierParPin($pin);

        if (null === $utilisateur) {
            $limiteur->consume();

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
