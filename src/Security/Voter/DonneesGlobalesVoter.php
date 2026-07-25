<?php

namespace App\Security\Voter;

use App\Entity\Utilisateur;
use App\Security\Permission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Habilitations sur les données consolidées de l'établissement : chiffre
 * d'affaires global, panier moyen, tendances, liste de toutes les ventes.
 *
 * Un caissier encaisse ; il n'a pas à connaître le résultat de la boutique. Ces
 * permissions n'ont pas de sujet : elles portent sur l'agrégat, pas sur une entité.
 */
class DonneesGlobalesVoter extends Voter
{
    private const ATTRIBUTS = [Permission::VOIR_CA_GLOBAL, Permission::VOIR_TOUTES_VENTES];

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, self::ATTRIBUTS, true) && null === $subject;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if (!$token->getUser() instanceof Utilisateur) {
            return false;
        }

        // Consultation seulement : encadrement et comptabilité.
        return $this->security->isGranted('ROLE_GERANT') || $this->security->isGranted('ROLE_COMPTABLE');
    }
}
