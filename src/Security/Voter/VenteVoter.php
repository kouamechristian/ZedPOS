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
 * la dirigeante). **Une exception, et une seule** : le caissier peut annuler le
 * ticket qu'il vient d'encaisser. L'erreur de saisie se constate au comptoir dans
 * les secondes qui suivent, et faire venir le gérant pour deux baguettes de trop
 * immobilise la file du matin — c'est-à-dire exactement ce que cette caisse est
 * censée éviter.
 *
 * L'exception est bornée à ce ticket-là : dès qu'une vente suivante est
 * encaissée, l'annulation redevient l'affaire du gérant. Un caissier qui pourrait
 * remonter sa journée pourrait aussi effacer ses écarts au fil de l'eau, et le Z
 * ne signalerait plus rien. Dans tous les cas l'annulation reste tracée au journal
 * d'audit et notifiée à la dirigeante : elle est ouverte, pas silencieuse.
 */
class VenteVoter extends Voter
{
    private const ATTRIBUTS = [Permission::VENTE_VOIR, Permission::VENTE_ANNULER];

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
            Permission::VENTE_ANNULER => $this->peutAnnuler($vente, $utilisateur),

            default => false,
        };
    }

    private function peutAnnuler(Vente $vente, Utilisateur $utilisateur): bool
    {
        if ($this->security->isGranted('ROLE_GERANT')) {
            return true;
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
