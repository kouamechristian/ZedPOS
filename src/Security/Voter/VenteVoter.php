<?php

namespace App\Security\Voter;

use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Security\Permission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Habilitations portant sur une vente.
 *
 * Règle de cloisonnement : **un caissier ne voit que ses propres ventes**, celles
 * encaissées dans une session de caisse qui lui appartient. Les uuid de ticket
 * étant imprévisibles, le risque pratique est faible — mais la matrice
 * d'habilitations ne s'appuie pas sur l'imprévisibilité d'un identifiant.
 *
 * L'annulation d'une vente encaissée est réservée au gérant (et, par hiérarchie,
 * à la dirigeante) ; elle notifie la dirigeante.
 */
class VenteVoter extends Voter
{
    private const ATTRIBUTS = [Permission::VENTE_VOIR, Permission::VENTE_ANNULER];

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, self::ATTRIBUTS, true) && $subject instanceof Vente;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $utilisateur = $token->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }

        /** @var Vente $vente */
        $vente = $subject;

        return match ($attribute) {
            Permission::VENTE_VOIR => $this->peutVoir($vente, $utilisateur),

            // Écriture : le comptable, en lecture seule, n'a pas ROLE_GERANT.
            Permission::VENTE_ANNULER => $this->security->isGranted('ROLE_GERANT'),

            default => false,
        };
    }

    private function peutVoir(Vente $vente, Utilisateur $utilisateur): bool
    {
        // Encadrement et comptabilité voient l'intégralité des ventes.
        if ($this->security->isGranted('ROLE_GERANT') || $this->security->isGranted('ROLE_COMPTABLE')) {
            return true;
        }

        // Un caissier est limité aux ventes de ses propres sessions de caisse.
        return $vente->getSessionCaisse()->getUtilisateur()->getId() === $utilisateur->getId();
    }
}
