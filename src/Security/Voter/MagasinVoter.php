<?php

namespace App\Security\Voter;

use App\Entity\Utilisateur;
use App\Security\Permission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Module Magasin.
 *
 * | Permission            | Magasinier | Gérante | Dirigeante |
 * |-----------------------|------------|---------|------------|
 * | MAGASIN_VOIR          | oui        | oui     | oui        |
 * | MAGASIN_GERER         | oui        | oui     | oui        |
 * | MAGASIN_PRODUIT_CREER | oui        | oui     | oui        |
 * | MAGASIN_PRODUIT_MODIFIER | oui     | oui     | oui        |
 * | MAGASIN_REFERENTIEL   | non        | oui     | oui        |
 * | MAGASIN_VOIR_PRIX     | non        | non     | oui        |
 * | MAGASIN_VALIDER_ECART | non        | non     | oui        |
 *
 * **Le magasinier enregistre** : réceptions, sorties, inventaires — c'est lui qui
 * a la marchandise sous les yeux —, crée un produit qui arrive pour la première
 * fois et modifie les produits existants (formulaire sans prix). Gérer les
 * emplacements reste à la gérante et à la dirigeante (MAGASIN_REFERENTIEL). Il n'est pas dans la
 * hiérarchie du gérant, et le gérant n'hérite pas de lui : les deux portes sont
 * distinctes. Les prix, le coût moyen et un inventaire qui corrige le stock
 * restent à la dirigeante — les écrans du magasinier n'affichent aucun montant.
 * Pas de sujet : les permissions portent sur le module.
 */
class MagasinVoter extends Voter
{
    private const PERMISSIONS = [
        Permission::MAGASIN_VOIR,
        Permission::MAGASIN_GERER,
        Permission::MAGASIN_PRODUIT_CREER,
        Permission::MAGASIN_PRODUIT_MODIFIER,
        Permission::MAGASIN_REFERENTIEL,
        Permission::MAGASIN_VOIR_PRIX,
        Permission::MAGASIN_VALIDER_ECART,
    ];

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, self::PERMISSIONS, true) && null === $subject;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if (!$token->getUser() instanceof Utilisateur) {
            return false;
        }

        return match ($attribute) {
            Permission::MAGASIN_VOIR, Permission::MAGASIN_GERER, Permission::MAGASIN_PRODUIT_CREER, Permission::MAGASIN_PRODUIT_MODIFIER => $this->security->isGranted('ROLE_GERANT') || $this->security->isGranted('ROLE_MAGASIN'),
            Permission::MAGASIN_VOIR_PRIX, Permission::MAGASIN_VALIDER_ECART => $this->security->isGranted('ROLE_DIRIGEANTE'),
            default => $this->security->isGranted('ROLE_GERANT'),
        };
    }
}
