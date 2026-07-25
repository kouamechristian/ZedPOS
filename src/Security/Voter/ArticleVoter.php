<?php

namespace App\Security\Voter;

use App\Entity\Article;
use App\Entity\Utilisateur;
use App\Security\Permission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Habilitations portant sur le catalogue.
 *
 * Deux règles structurantes :
 *  - le **coût de revient et la marge** ne sortent jamais vers un caissier ;
 *  - le **prix de vente** n'est modifiable que par la dirigeante — un gérant peut
 *    tout changer sur un article sauf son prix.
 *
 * Le sujet peut être un Article ou `null` : `null` répond à la question générale
 * (« cet utilisateur a-t-il accès aux coûts ? »), utile pour masquer une colonne
 * entière sans disposer d'une ligne particulière.
 */
class ArticleVoter extends Voter
{
    private const ATTRIBUTS = [
        Permission::ARTICLE_VOIR_COUT,
        Permission::ARTICLE_MODIFIER_PRIX,
        Permission::ARTICLE_MODIFIER,
    ];

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, self::ATTRIBUTS, true)
            && (null === $subject || $subject instanceof Article);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if (!$token->getUser() instanceof Utilisateur) {
            return false;
        }

        return match ($attribute) {
            // Donnée de gestion : jamais un caissier. Le comptable la lit.
            Permission::ARTICLE_VOIR_COUT => $this->security->isGranted('ROLE_GERANT')
                || $this->security->isGranted('ROLE_COMPTABLE'),

            // Décision commerciale réservée à la dirigeante.
            Permission::ARTICLE_MODIFIER_PRIX => $this->security->isGranted('ROLE_DIRIGEANTE'),

            // Écriture : exclue pour le comptable, qui est en lecture seule.
            Permission::ARTICLE_MODIFIER => $this->security->isGranted('ROLE_GERANT'),

            default => false,
        };
    }
}
