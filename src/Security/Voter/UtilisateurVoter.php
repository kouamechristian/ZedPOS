<?php

namespace App\Security\Voter;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Security\Permission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Habilitation sur les comptes utilisateurs : création, activation, désactivation.
 *
 * Gérer les comptes est ouvert au **gérant et à la dirigeante**, mais gérer n'est
 * pas distribuer sans limite : un gérant n'a la main ni sur un compte dirigeante,
 * ni sur l'attribution du rôle dirigeante (voir
 * {@see RoleUtilisateur::attribuablesPar()}). Sans ce plafond, il lui suffirait de
 * s'octroyer un second compte pour obtenir les prix de vente, le pilotage et le
 * journal d'audit — la hiérarchie des rôles serait décorative.
 *
 * Le sujet peut être `null` : c'est la question générale (« puis-je gérer des
 * comptes ? »), celle qui ouvre l'écran et affiche le bouton de création.
 */
class UtilisateurVoter extends Voter
{
    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return Permission::UTILISATEUR_GERER === $attribute
            && (null === $subject || $subject instanceof Utilisateur);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if (!$token->getUser() instanceof Utilisateur) {
            return false;
        }

        // Un caissier n'administre rien, et un comptable n'est pas de la maison.
        if (!$this->security->isGranted('ROLE_GERANT')) {
            return false;
        }

        // Question générale : l'accès à l'écran suffit.
        if (null === $subject) {
            return true;
        }

        if ($this->security->isGranted('ROLE_DIRIGEANTE')) {
            return true;
        }

        // Reste le gérant, face à un compte précis : tout sauf une dirigeante.
        // Il pourrait sinon désactiver le sien et couper l'établissement de son
        // unique accès au pilotage et à l'audit.
        return !\in_array(RoleUtilisateur::DIRIGEANTE->value, $subject->getRoles(), true);
    }
}
