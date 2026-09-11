<?php

namespace App\Security\Voter;

use App\Entity\Utilisateur;
use App\Security\Permission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Module des revendeurs : stands, vendeurs, bons de dotation.
 *
 * Une seule audience, la gérante (ROLE_GERANT) — la dirigeante en hérite. Le
 * comptable, rôle autonome, n'y a pas accès : il lira les arrêtés, il ne confie
 * pas de marchandise. Pas de sujet : la permission porte sur le module.
 */
class StandVoter extends Voter
{
    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [Permission::STAND_GERER, Permission::REMUNERATION_FIXER], true) && null === $subject;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if (!$token->getUser() instanceof Utilisateur) {
            return false;
        }

        // La rémunération est un prix : même audience que le prix de vente.
        return Permission::REMUNERATION_FIXER === $attribute
            ? $this->security->isGranted('ROLE_DIRIGEANTE')
            : $this->security->isGranted('ROLE_GERANT');
    }
}
