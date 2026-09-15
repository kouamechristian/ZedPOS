<?php

namespace App\Security\Voter;

use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Repository\VenteRepository;
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
 * L'annulation d'une vente encaissée appartient au gérant (et, par hiérarchie, à
 * la dirigeante), **sans exception**.
 *
 * Le caissier, lui, **modifie** le ticket qu'il vient d'encaisser. L'erreur de
 * saisie se constate au comptoir dans les secondes qui suivent, et faire venir le
 * gérant pour deux baguettes de trop immobilise la file du matin. La modification
 * est bornée :
 * - sa propre caisse, encore ouverte ;
 * - le dernier ticket, et lui seul — un caissier qui pourrait remonter sa journée
 *   effacerait ses écarts au fil de l'eau, et le Z ne signalerait plus rien ;
 * - **une seule fois** : un ticket issu d'une modification ne se reprend plus.
 *
 * Elle reste tracée au journal d'audit et notifiée à la dirigeante : elle est
 * ouverte, pas silencieuse.
 */
class VenteVoter extends Voter
{
    private const ATTRIBUTS = [Permission::VENTE_VOIR, Permission::VENTE_ANNULER, Permission::VENTE_MODIFIER];

    public function __construct(
        private readonly Security $security,
        private readonly VenteRepository $ventes,
    ) {
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

            Permission::VENTE_MODIFIER => $this->peutModifier($vente, $utilisateur),

            default => false,
        };
    }

    private function peutModifier(Vente $vente, Utilisateur $utilisateur): bool
    {
        // Une modification, une seule : ni un ticket déjà repris (annulé), ni
        // le ticket né de la reprise.
        if (!$vente->estModifiable()) {
            return false;
        }

        $session = $vente->getSessionCaisse();

        // Sa propre caisse, et encore ouverte. Après le Z la journée est arrêtée :
        // `SessionCaisse::garantirOuverte()` refuserait de toute façon plus loin,
        // mais une habilitation ne se déduit pas d'une exception levée ailleurs.
        if ($session->getUtilisateur()->getId() !== $utilisateur->getId() || !$session->estOuverte()) {
            return false;
        }

        // Le dernier ticket, et lui seul : c'est ce qui borne l'exception.
        return $this->ventes->derniereDe($session)?->getId() === $vente->getId();
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
