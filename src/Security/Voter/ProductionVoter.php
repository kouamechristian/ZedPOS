<?php

namespace App\Security\Voter;

use App\Entity\SaisieProduction;
use App\Entity\Utilisateur;
use App\Repository\SessionCaisseRepository;
use App\Security\Permission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Production et vitrine : qui déclare, qui annule.
 *
 * | Permission          | Atelier | Vitrine | Gérante |
 * |---------------------|---------|---------|---------|
 * | PRODUCTION_DECLARER | oui     | non     | oui     |
 * | VITRINE_DECLARER    | non     | oui     | oui     |
 * | PRODUCTION_ANNULER  | la sienne, caisse ouverte | idem | tant que la caisse suivante n'est pas ouverte |
 *
 * La gérante hérite d'ATELIER et de VITRINE (security.yaml) : elle déclare à
 * leur place quand personne n'a sa tablette.
 */
class ProductionVoter extends Voter
{
    public function __construct(
        private readonly Security $security,
        private readonly SessionCaisseRepository $sessions,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            Permission::PRODUCTION_DECLARER, Permission::VITRINE_DECLARER => null === $subject,
            Permission::PRODUCTION_ANNULER => $subject instanceof SaisieProduction,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $utilisateur = $token->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }

        return match ($attribute) {
            Permission::PRODUCTION_DECLARER => $this->security->isGranted('ROLE_ATELIER'),
            Permission::VITRINE_DECLARER => $this->security->isGranted('ROLE_VITRINE'),
            Permission::PRODUCTION_ANNULER => $this->peutAnnuler($subject, $utilisateur),
            default => false,
        };
    }

    private function peutAnnuler(SaisieProduction $saisie, Utilisateur $utilisateur): bool
    {
        if ($saisie->estAnnulee()) {
            return false;
        }

        // La caisse suivante a repris les restes de cette fiche : la modifier
        // ensuite ferait mentir sa reprise.
        if ($this->sessions->aUneSuivante($saisie->getSessionCaisse())) {
            return false;
        }

        if ($this->security->isGranted('ROLE_GERANT')) {
            return true;
        }

        // L'auteur corrige sa propre faute de frappe, le jour même. Après le Z,
        // la correction passe par la gérante.
        return $saisie->getCreatedBy()->getId() === $utilisateur->getId()
            && $saisie->getSessionCaisse()->estOuverte();
    }
}
