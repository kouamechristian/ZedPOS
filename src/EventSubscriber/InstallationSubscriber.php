<?php

namespace App\EventSubscriber;

use App\Repository\UtilisateurRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Tant que l'application n'a aucun compte, tout mène à l'écran d'installation.
 *
 * Sans cela, une base vierge n'offrirait qu'un écran de connexion sur lequel
 * aucun identifiant ne marche : l'exploitant se retrouverait devant une porte
 * close, sans indication de la marche à suivre.
 */
class InstallationSubscriber implements EventSubscriberInterface
{
    private const ROUTE = 'app_installation';

    public function __construct(
        private readonly UtilisateurRepository $utilisateurs,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priorité 20 : après le routeur (32), qui renseigne `_route`, mais
        // **avant** le pare-feu (8). Sinon un accès à /admin partirait d'abord
        // vers /login, et l'exploitant ferait un détour par un écran de connexion
        // inutilisable avant d'arriver à l'installation.
        return [KernelEvents::REQUEST => ['onKernelRequest', 20]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requete = $event->getRequest();

        // Profileur, barre de débogage, assets servis par AssetMapper : rien à
        // installer là, et les intercepter casserait l'écran d'installation
        // lui-même.
        if (str_starts_with($requete->getPathInfo(), '/_') || str_starts_with($requete->getPathInfo(), '/assets')) {
            return;
        }

        // Déjà sur place : ne pas boucler.
        if (self::ROUTE === $requete->attributes->get('_route')) {
            return;
        }

        // Une requête par page tant que l'application est vide, et **une de plus
        // par page ensuite** : c'est le prix de l'amorçage. La requête est bornée
        // à une ligne sur une clé primaire, négligeable devant l'amorçage du
        // noyau — la mesurer avant de l'optimiser (voir « Performance » dans
        // CLAUDE.md).
        if (!$this->utilisateurs->aucunCompte()) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urls->generate(self::ROUTE)));
    }
}
