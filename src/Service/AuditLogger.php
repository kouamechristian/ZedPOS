<?php

namespace App\Service;

use App\Entity\Arrete;
use App\Entity\Article;
use App\Entity\BonDotation;
use App\Entity\BonRetour;
use App\Entity\DetteVendeur;
use App\Entity\RemboursementDette;
use App\Entity\Vendeur;
use App\Service\Point\ResultatPoint;
use App\Entity\JournalAudit;
use App\Entity\LigneDotation;
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

    /**
     * @param string $champ `prixVenteTtc` ou `prixCession` : les deux prix d'un
     *                      article obéissent à la même règle et laissent la même trace
     */
    public function prixModifie(Article $article, int $ancienPrix, int $nouveauPrix, string $champ = 'prixVenteTtc'): JournalAudit
    {
        return $this->enregistrer(
            ActionAudit::PRIX_MODIFIE,
            'Article',
            $article->getId(),
            [$champ => $ancienPrix],
            [$champ => $nouveauPrix, 'nom' => $article->getNom()],
        );
    }

    // ------------------------------------------------------ Stands / dotations

    /**
     * Validation d'un bon de dotation. Pas de double validation dans ce module :
     * cette entrée est la seule sécurité, elle porte donc l'état complet —
     * quantités, prix figés, montants — avant et après.
     *
     * L'auteur est passé explicitement : c'est lui qui a validé, pas nécessairement
     * « l'utilisateur de la session » — une commande ou un traitement n'en a pas.
     *
     * @param array<string, mixed> $avant {@see self::etatDotation()} relevé avant
     */
    public function dotationValidee(BonDotation $bon, array $avant, ?Utilisateur $auteur = null): JournalAudit
    {
        return $this->enregistrer(ActionAudit::DOTATION_VALIDEE, 'BonDotation', $bon->getId(), $avant, $this->etatDotation($bon), $auteur);
    }

    /** @param array<string, mixed> $avant {@see self::etatDotation()} relevé avant */
    public function dotationAnnulee(BonDotation $bon, array $avant, ?Utilisateur $auteur = null): JournalAudit
    {
        return $this->enregistrer(ActionAudit::DOTATION_ANNULEE, 'BonDotation', $bon->getId(), $avant, $this->etatDotation($bon), $auteur);
    }

    /** @param array<string, mixed> $avant {@see self::etatArrete()} relevé avant */
    public function arreteValide(Arrete $arrete, array $avant, ?ResultatPoint $resultat, ?Utilisateur $auteur = null): JournalAudit
    {
        $entree = $this->enregistrer(ActionAudit::ARRETE_VALIDE, 'Arrete', $arrete->getId(), $avant, $this->etatArrete($arrete, $resultat), $auteur);

        if (null !== $arrete->getEcart() && 0 !== $arrete->getEcart()) {
            $this->enregistrer(
                ActionAudit::ECART_POINT,
                'Arrete',
                $arrete->getId(),
                ['netARemettre' => $arrete->getNetARemettre()],
                [
                    'numero' => $arrete->getNumero(),
                    'stand' => $arrete->getStand()->getCode(),
                    'vendeur' => $arrete->getVendeur()->getNom(),
                    'montantRemis' => $arrete->getMontantRemis(),
                    'ecart' => $arrete->getEcart(),
                    'commentaireEcart' => $arrete->getCommentaireEcart(),
                    'traitementEcart' => $arrete->getTraitementEcart()?->value,
                ],
                $auteur,
            );
        }

        return $entree;
    }

    /**
     * Ouverture d'une dette — manquant imputé au point, avance ou autre. Il n'y a
     * pas d'« avant » : la dette n'existait pas.
     */
    public function detteCreee(DetteVendeur $dette, ?Utilisateur $auteur = null): JournalAudit
    {
        return $this->enregistrer(ActionAudit::DETTE_CREEE, 'DetteVendeur', $dette->getId(), null, $this->etatDette($dette), $auteur);
    }

    /**
     * Un versement du vendeur, avec sa répartition sur les dettes touchées (les plus
     * anciennes d'abord) et le solde avant / après.
     *
     * @param list<RemboursementDette> $remboursements
     */
    public function detteRemboursee(Vendeur $vendeur, array $remboursements, int $soldeAvant, int $soldeApres, ?Utilisateur $auteur = null): JournalAudit
    {
        $premier = $remboursements[0] ?? throw new \LogicException('Un remboursement sans ligne ne se trace pas.');

        return $this->enregistrer(
            ActionAudit::DETTE_REMBOURSEE,
            'Vendeur',
            $vendeur->getId(),
            ['solde' => $soldeAvant],
            [
                'vendeur' => $vendeur->getNom(),
                'solde' => $soldeApres,
                'montant' => array_sum(array_map(static fn (RemboursementDette $r): int => $r->getMontant(), $remboursements)),
                'moyen' => $premier->getMoyen()->value,
                'date' => $premier->getDate()->format('Y-m-d'),
                'repartition' => array_map(static fn (RemboursementDette $r): array => [
                    'dette' => $r->getDette()->getId(),
                    'montant' => $r->getMontant(),
                    'reste' => $r->getDette()->reste(),
                    'statut' => $r->getDette()->getStatut()->value,
                ], $remboursements),
            ],
            $auteur,
        );
    }

    /** @param array<string, mixed> $avant {@see self::etatDette()} relevé avant */
    public function detteAnnulee(DetteVendeur $dette, array $avant, ?Utilisateur $auteur = null): JournalAudit
    {
        return $this->enregistrer(ActionAudit::DETTE_ANNULEE, 'DetteVendeur', $dette->getId(), $avant, $this->etatDette($dette), $auteur);
    }

    /** @return array<string, mixed> montants en centimes */
    public function etatDette(DetteVendeur $dette): array
    {
        return [
            'vendeur' => $dette->getVendeur()->getNom(),
            'type' => $dette->getType()->value,
            'statut' => $dette->getStatut()->value,
            'montant' => $dette->getMontant(),
            'montantRembourse' => $dette->getMontantRembourse(),
            'arrete' => $dette->getArrete()?->getNumero(),
            'commentaire' => $dette->getCommentaire(),
        ];
    }

    /** @param array<string, mixed> $avant {@see self::etatArrete()} relevé avant */
    public function arreteAnnule(Arrete $arrete, array $avant, ?Utilisateur $auteur = null): JournalAudit
    {
        return $this->enregistrer(ActionAudit::ARRETE_ANNULE, 'Arrete', $arrete->getId(), $avant, $this->etatArrete($arrete), $auteur);
    }

    /**
     * Photographie d'un arrêté : période, montants, et, si le calcul est fourni, le
     * détail par produit. Montants en centimes, quantités en millièmes.
     *
     * @return array<string, mixed>
     */
    public function etatArrete(Arrete $arrete, ?ResultatPoint $resultat = null): array
    {
        $etat = [
            'numero' => $arrete->getNumero(),
            'statut' => $arrete->getStatut()->value,
            'stand' => $arrete->getStand()->getCode(),
            'vendeur' => $arrete->getVendeur()->getNom(),
            'dateDebut' => $arrete->getDateDebut()->format('Y-m-d'),
            'dateFin' => $arrete->getDateFin()->format('Y-m-d'),
            'granularite' => $arrete->getGranularite()->value,
            'modeRemuneration' => $arrete->getModeRemuneration()->value,
            'tauxCommission' => $arrete->getTauxCommission(),
            'montantAttendu' => $arrete->getMontantAttendu(),
            'remunerationVendeur' => $arrete->getRemunerationVendeur(),
            'netARemettre' => $arrete->getNetARemettre(),
            'montantRemis' => $arrete->getMontantRemis(),
            'ecart' => $arrete->getEcart(),
            'commentaireEcart' => $arrete->getCommentaireEcart(),
            'traitementEcart' => $arrete->getTraitementEcart()?->value,
            'motifAnnulation' => $arrete->getMotifAnnulation(),
            'bonsDotation' => array_map(static fn (BonDotation $b): string => $b->getNumero(), $arrete->getBonsDotation()->toArray()),
            'bonsRetour' => array_map(static fn (BonRetour $b): string => $b->getNumero(), $arrete->getBonsRetour()->toArray()),
        ];

        if (null !== $resultat) {
            $etat['produits'] = array_map(static fn ($ligne): array => [
                'produit' => $ligne->produit,
                'confiee' => $ligne->qteConfiee,
                'retournee' => $ligne->qteRetournee,
                'perdue' => $ligne->qtePerdue,
                'vendue' => $ligne->qteVendue,
                'montantAttendu' => $ligne->montantAttendu,
            ], $resultat->lignes);
        }

        return $etat;
    }

    /**
     * Photographie d'un bon pour le journal. Montants en centimes ; pour un
     * brouillon, estimés sur les prix courants et les prix unitaires restent nuls.
     *
     * @return array<string, mixed>
     */
    public function etatDotation(BonDotation $bon): array
    {
        return [
            'numero' => $bon->getNumero(),
            'statut' => $bon->getStatut()->value,
            'stand' => $bon->getStand()->getCode(),
            'vendeur' => $bon->getVendeur()->getNom(),
            'dateDotation' => $bon->getDateDotation()->format('Y-m-d'),
            'totalVente' => $bon->totalVente(),
            'totalCession' => $bon->totalCession(),
            'lignes' => array_map(static fn (LigneDotation $ligne): array => [
                'produit' => $ligne->getProduit()->getNom(),
                'quantite' => $ligne->getQuantite(),
                'prixVenteUnitaire' => $ligne->getPrixVenteUnitaire(),
                'prixCessionUnitaire' => $ligne->getPrixCessionUnitaire(),
            ], $bon->getLignes()->toArray()),
            'motifAnnulation' => $bon->getMotifAnnulation(),
        ];
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
     * Un utilisateur a changé **son propre** mot de passe ou code PIN.
     *
     * Même règle que pour une modification : ni l'ancien secret ni le nouveau,
     * même hachés. Seul le moyen de connexion concerné figure au journal.
     *
     * @param 'mot_de_passe'|'code_pin' $moyen
     */
    public function secretModifie(Utilisateur $utilisateur, string $moyen): JournalAudit
    {
        return $this->enregistrer(
            ActionAudit::SECRET_MODIFIE,
            'Utilisateur',
            $utilisateur->getId(),
            null,
            ['moyen' => $moyen],
            $utilisateur,
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
