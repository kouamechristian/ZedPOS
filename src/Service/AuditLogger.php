<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\JournalAudit;
use App\Entity\MatierePremiere;
use App\Entity\Perte;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\ActionAudit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Point d'entrée unique du journal d'audit inaltérable.
 *
 * Chaque écriture enregistre : l'utilisateur, l'action, l'entité concernée et son
 * id, les valeurs **avant / après** en JSON, l'adresse IP et l'horodatage.
 *
 * L'auteur et l'IP sont déduits de la requête courante ; en contexte console
 * (commandes) ils peuvent être passés explicitement.
 *
 * Le journal ne propose **aucune méthode de modification ou de suppression** —
 * l'entité {@see JournalAudit} est sans setter et
 * {@see \App\EventListener\JournalAuditImmuableListener} rejette tout UPDATE ou
 * DELETE au niveau de l'ORM.
 *
 * Note sur le flush : l'entrée est persistée puis flushée immédiatement, donc
 * **dans la transaction de l'appelant** s'il y en a une. Une action annulée par un
 * rollback ne laisse ainsi aucune trace fantôme.
 */
class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
    ) {
    }

    /**
     * Écriture générique. Les méthodes nommées ci-dessous sont à préférer :
     * elles garantissent un format de payload homogène par type d'action.
     *
     * @param array<string, mixed>|null $avant
     * @param array<string, mixed>|null $apres
     */
    public function enregistrer(
        ActionAudit $action,
        string $entite,
        ?int $entiteId = null,
        ?array $avant = null,
        ?array $apres = null,
        ?Utilisateur $utilisateur = null,
        ?Request $request = null,
    ): JournalAudit {
        $request ??= $this->requestStack->getCurrentRequest();

        $entree = new JournalAudit(
            action: $action->value,
            entite: $entite,
            entiteId: $entiteId,
            utilisateur: $utilisateur ?? $this->utilisateurCourant(),
            avant: $avant,
            apres: $apres,
            ip: $request?->getClientIp(),
        );

        $this->em->persist($entree);
        $this->em->flush();

        return $entree;
    }

    // ------------------------------------------------------------------ Ventes

    public function venteAnnulee(Vente $vente, string $motif): JournalAudit
    {
        return $this->enregistrer(
            ActionAudit::VENTE_ANNULEE,
            'Vente',
            $vente->getId(),
            ['statut' => 'VALIDEE', 'totalTtc' => $vente->getTotalTtc()],
            [
                'statut' => $vente->getStatut()->value,
                'totalTtc' => $vente->getTotalTtc(),
                'numero' => $vente->getNumero(),
                'motif' => $motif,
            ],
        );
    }

    /**
     * Remise accordée sur une vente. `avant` porte le montant plein, `apres` le
     * montant réellement encaissé — l'écart est la remise consentie.
     */
    public function remiseAccordee(Vente $vente): JournalAudit
    {
        $remise = $vente->getRemise();

        return $this->enregistrer(
            ActionAudit::REMISE_ACCORDEE,
            'Vente',
            $vente->getId(),
            ['totalTtc' => $vente->getTotalTtc() + $remise],
            [
                'totalTtc' => $vente->getTotalTtc(),
                'remise' => $remise,
                'motif' => $vente->getMotifRemise(),
                'numero' => $vente->getNumero(),
            ],
        );
    }

    // ------------------------------------------------------- Catalogue / stock

    public function prixModifie(Article $article, int $ancienPrix, int $nouveauPrix): JournalAudit
    {
        return $this->enregistrer(
            ActionAudit::PRIX_MODIFIE,
            'Article',
            $article->getId(),
            ['prixVenteTtc' => $ancienPrix],
            ['prixVenteTtc' => $nouveauPrix, 'nom' => $article->getNom()],
        );
    }

    public function perteSaisie(Perte $perte, ?string $commentaire = null): JournalAudit
    {
        $support = $perte->getMatierePremiere() ?? $perte->getArticle();

        return $this->enregistrer(
            ActionAudit::PERTE_SAISIE,
            'Perte',
            $perte->getId(),
            null,
            [
                'support' => $support?->getNom(),
                'quantite' => $perte->getQuantite(),
                'valorisation' => $perte->getValorisation(),
                'motif' => $perte->getMotif()->value,
                'commentaire' => $commentaire,
            ],
        );
    }

    /**
     * Validation d'un comptage d'inventaire sur une matière première : le stock
     * théorique est remplacé par le stock compté, l'écart est conservé.
     *
     * @param int $stockAvant stock théorique avant validation, en millièmes
     * @param int $stockApres stock physiquement compté, en millièmes
     */
    public function inventaireValide(
        MatierePremiere $matiere,
        int $stockAvant,
        int $stockApres,
        ?string $commentaire = null,
    ): JournalAudit {
        return $this->ecartInventaire(
            'MatierePremiere',
            $matiere->getId(),
            $matiere->getNom(),
            $stockAvant,
            $stockApres,
            $commentaire,
        );
    }

    /**
     * Écart d'inventaire constaté sur une ligne de feuille de comptage.
     *
     * Une entrée **par ligne corrigée**, et non une seule pour la feuille : le
     * journal se lit pour retrouver ce qui est arrivé à *un* produit, pas pour
     * savoir qu'un inventaire a eu lieu — cela, la feuille elle-même le dit.
     *
     * `$entite` vaut `MatierePremiere` ou `Article` : les deux sont suivis en
     * stock et les deux dérivent.
     */
    public function ecartInventaire(
        string $entite,
        ?int $id,
        string $libelle,
        int $stockAvant,
        int $stockApres,
        ?string $commentaire = null,
        ?int $inventaireId = null,
    ): JournalAudit {
        return $this->enregistrer(
            ActionAudit::INVENTAIRE_VALIDE,
            $entite,
            $id,
            ['stockActuel' => $stockAvant],
            [
                'stockActuel' => $stockApres,
                'ecart' => $stockApres - $stockAvant,
                'libelle' => $libelle,
                'commentaire' => $commentaire,
                'inventaire' => $inventaireId,
            ],
        );
    }

    // ------------------------------------------------------------------ Caisse

    /**
     * Clôture Z. Un écart non nul produit **une seconde entrée** dédiée
     * ({@see ActionAudit::ECART_CAISSE}) : les écarts sont ainsi filtrables seuls.
     *
     * @return list<JournalAudit>
     */
    public function caisseCloturee(SessionCaisse $session): array
    {
        $apres = [
            'fondCaisse' => $session->getFondCaisse(),
            'theorique' => $session->getTheorique(),
            'montantCompte' => $session->getMontantCompte(),
            'ecart' => $session->getEcart(),
            'commentaire' => $session->getCommentaireCloture(),
            'caissier' => $session->getUtilisateur()->getNom(),
        ];

        $entrees = [$this->enregistrer(
            ActionAudit::CAISSE_CLOTUREE,
            'SessionCaisse',
            $session->getId(),
            ['statut' => 'OUVERTE'],
            ['statut' => $session->getStatut()->value] + $apres,
        )];

        if (0 !== (int) $session->getEcart()) {
            $entrees[] = $this->enregistrer(
                ActionAudit::ECART_CAISSE,
                'SessionCaisse',
                $session->getId(),
                ['theorique' => $session->getTheorique()],
                $apres,
            );
        }

        return $entrees;
    }

    // ----------------------------------------------------------------- Comptes

    public function utilisateurCree(Utilisateur $cible, ?Utilisateur $auteur = null): JournalAudit
    {
        return $this->enregistrer(
            ActionAudit::UTILISATEUR_CREE,
            'Utilisateur',
            $cible->getId(),
            null,
            [
                'email' => $cible->getEmail(),
                'nom' => $cible->getNom(),
                'roles' => $cible->getRoles(),
                'actif' => $cible->isActif(),
            ],
            $auteur,
        );
    }

    /**
     * Modification d'un compte : nom, e-mail, rôle, réinitialisation du secret.
     *
     * Le secret lui-même n'est **jamais** journalisé, pas même haché — seul le
     * fait qu'il ait été remplacé l'est. Un journal d'audit se consulte, il ne
     * doit pas devenir un second endroit où traînent des identifiants.
     *
     * @param array{email: string, nom: string, roles: list<string>} $avant
     */
    public function utilisateurModifie(Utilisateur $cible, array $avant, bool $secretRemplace = false): JournalAudit
    {
        return $this->enregistrer(
            ActionAudit::UTILISATEUR_MODIFIE,
            'Utilisateur',
            $cible->getId(),
            $avant,
            [
                'email' => $cible->getEmail(),
                'nom' => $cible->getNom(),
                'roles' => $cible->getRoles(),
                'secret_remplace' => $secretRemplace,
            ],
        );
    }

    /**
     * Activation ou désactivation d'un compte, selon l'état d'arrivée.
     */
    public function utilisateurBascule(Utilisateur $cible, bool $actifAvant): JournalAudit
    {
        return $this->enregistrer(
            $cible->isActif() ? ActionAudit::UTILISATEUR_ACTIVE : ActionAudit::UTILISATEUR_DESACTIVE,
            'Utilisateur',
            $cible->getId(),
            ['actif' => $actifAvant],
            ['actif' => $cible->isActif(), 'email' => $cible->getEmail(), 'nom' => $cible->getNom()],
        );
    }

    // ---------------------------------------------------------------- Sécurité

    /**
     * @param array<string, mixed>|null $details
     */
    public function connexion(
        ActionAudit $action,
        ?Utilisateur $utilisateur,
        ?Request $request,
        ?array $details = null,
    ): JournalAudit {
        return $this->enregistrer(
            $action,
            'Utilisateur',
            $utilisateur?->getId(),
            null,
            $details,
            $utilisateur,
            $request,
        );
    }

    /**
     * Auteur de l'action, s'il y a un utilisateur authentifié (null en console).
     */
    private function utilisateurCourant(): ?Utilisateur
    {
        $utilisateur = $this->security->getUser();

        return $utilisateur instanceof Utilisateur ? $utilisateur : null;
    }
}
