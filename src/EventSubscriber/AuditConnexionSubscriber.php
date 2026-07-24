<?php

namespace App\EventSubscriber;

use App\Entity\JournalAudit;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Trace dans le JournalAudit chaque connexion, déconnexion et échec de connexion.
 */
class AuditConnexionSubscriber implements EventSubscriberInterface
{
    public const ACTION_CONNEXION = 'CONNEXION';
    public const ACTION_DECONNEXION = 'DECONNEXION';
    public const ACTION_ECHEC_CONNEXION = 'ECHEC_CONNEXION';

    public function __construct(private readonly EntityManagerInterface $em)
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

        $this->enregistrer(
            self::ACTION_CONNEXION,
            $utilisateur instanceof Utilisateur ? $utilisateur : null,
            $event->getRequest(),
            ['identifiant' => $utilisateur->getUserIdentifier(), 'firewall' => $event->getFirewallName()],
        );
    }

    public function onLogout(LogoutEvent $event): void
    {
        $utilisateur = $event->getToken()?->getUser();

        $this->enregistrer(
            self::ACTION_DECONNEXION,
            $utilisateur instanceof Utilisateur ? $utilisateur : null,
            $event->getRequest(),
            $utilisateur instanceof Utilisateur ? ['identifiant' => $utilisateur->getUserIdentifier()] : null,
        );
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $badge = $event->getPassport()?->getBadge(UserBadge::class);
        $identifiant = $badge instanceof UserBadge ? $badge->getUserIdentifier() : null;

        $this->enregistrer(
            self::ACTION_ECHEC_CONNEXION,
            null,
            $event->getRequest(),
            [
                'identifiant' => $identifiant,
                'raison' => $event->getException()->getMessageKey(),
            ],
        );
    }

    /**
     * @param array<string, mixed>|null $apres
     */
    private function enregistrer(string $action, ?Utilisateur $utilisateur, ?Request $request, ?array $apres): void
    {
        $entree = new JournalAudit(
            action: $action,
            entite: 'Utilisateur',
            entiteId: $utilisateur?->getId(),
            utilisateur: $utilisateur,
            avant: null,
            apres: $apres,
            ip: $request?->getClientIp(),
        );

        $this->em->persist($entree);
        $this->em->flush();
    }
}
