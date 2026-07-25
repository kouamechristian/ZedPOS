<?php

namespace App\EventSubscriber;

use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Service\AuditLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Trace dans le journal d'audit chaque connexion, déconnexion et échec de connexion.
 *
 * L'utilisateur et la requête proviennent de l'événement de sécurité : au moment
 * de la connexion, le jeton n'est pas encore (ou plus) disponible dans le contexte
 * de sécurité que consulterait {@see AuditLogger} par défaut.
 */
class AuditConnexionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LogoutEvent::class => 'onLogout',
            LoginFailureEvent::class => 'onLoginFailure',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $utilisateur = $event->getUser();

        $this->audit->connexion(
            ActionAudit::CONNEXION,
            $utilisateur instanceof Utilisateur ? $utilisateur : null,
            $event->getRequest(),
            ['identifiant' => $utilisateur->getUserIdentifier(), 'firewall' => $event->getFirewallName()],
        );
    }

    public function onLogout(LogoutEvent $event): void
    {
        $utilisateur = $event->getToken()?->getUser();

        $this->audit->connexion(
            ActionAudit::DECONNEXION,
            $utilisateur instanceof Utilisateur ? $utilisateur : null,
            $event->getRequest(),
            $utilisateur instanceof Utilisateur ? ['identifiant' => $utilisateur->getUserIdentifier()] : null,
        );
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $badge = $event->getPassport()?->getBadge(UserBadge::class);
        $identifiant = $badge instanceof UserBadge ? $badge->getUserIdentifier() : null;

        $this->audit->connexion(
            ActionAudit::ECHEC_CONNEXION,
            null,
            $event->getRequest(),
            [
                'identifiant' => $identifiant,
                'raison' => $event->getException()->getMessageKey(),
            ],
        );
    }
}
