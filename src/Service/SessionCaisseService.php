<?php

namespace App\Service;

use App\Entity\MouvementCaisse;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Enum\CategorieDepense;
use App\Enum\TypeMouvementCaisse;
use App\Repository\SessionCaisseRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cycle de vie d'une session de caisse : ouverture (fond de caisse), dépenses et
 * sorties d'espèces, clôture Z avec contrôle de l'écart.
 *
 * Toutes les règles refusées lèvent une \DomainException dont le message est
 * directement affichable au caissier.
 */
class SessionCaisseService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SessionCaisseRepository $sessions,
        private readonly RapportCaisseService $rapports,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Ouvre une session avec le fond de caisse compté par le caissier.
     *
     * @param int $fondCaisse en centimes de FCFA
     *
     * @throws \DomainException si une session est déjà ouverte pour ce caissier
     */
    public function ouvrir(Utilisateur $utilisateur, int $fondCaisse): SessionCaisse
    {
        if (null !== $this->sessions->ouvertePour($utilisateur)) {
            throw new \DomainException('Une session de caisse est déjà ouverte pour ce caissier : clôturez-la avant d\'en ouvrir une nouvelle.');
        }

        $session = new SessionCaisse($utilisateur, $fondCaisse);
        $this->em->persist($session);
        $this->em->flush();

        return $session;
    }

    public function sessionOuverte(Utilisateur $utilisateur): ?SessionCaisse
    {
        return $this->sessions->ouvertePour($utilisateur);
    }

    /**
     * @throws \DomainException si aucune session n'est ouverte
     */
    public function exigerSessionOuverte(Utilisateur $utilisateur): SessionCaisse
    {
        return $this->sessions->ouvertePour($utilisateur)
            ?? throw new \DomainException('Aucune session de caisse ouverte : saisissez votre fond de caisse pour commencer.');
    }

    /**
     * Enregistre une dépense réglée en espèces ou une sortie de caisse.
     * Refusé si la session est clôturée (garde-fou porté par l'entité).
     *
     * @param int $montant en centimes de FCFA
     */
    public function enregistrerMouvement(
        SessionCaisse $session,
        Utilisateur $utilisateur,
        TypeMouvementCaisse $type,
        int $montant,
        ?CategorieDepense $categorie = null,
        ?string $commentaire = null,
    ): MouvementCaisse {
        $commentaire = trim((string) $commentaire);

        $mouvement = new MouvementCaisse(
            $session,
            $utilisateur,
            $type,
            $montant,
            $categorie,
            '' !== $commentaire ? $commentaire : null,
        );

        $this->em->persist($mouvement);
        $this->em->flush();

        return $mouvement;
    }

    /**
     * Ticket X : synthèse intermédiaire. Ne clôture rien, n'écrit rien.
     */
    public function ticketX(SessionCaisse $session): RapportCaisse
    {
        return $this->rapports->construire($session, false);
    }

    /**
     * Clôture Z : le caissier saisit le montant physiquement compté, le système
     * calcule le théorique et fige l'écart. Un commentaire est obligatoire dès
     * que l'écart n'est pas nul.
     *
     * @param int $montantCompte espèces comptées dans le tiroir, en centimes
     *
     * @throws \DomainException si la session est déjà clôturée ou l'écart injustifié
     */
    public function cloturer(SessionCaisse $session, int $montantCompte, ?string $commentaire = null): RapportCaisse
    {
        $session->garantirOuverte();

        $session->cloturer($this->rapports->theorique($session), $montantCompte, $commentaire);
        $this->em->flush();

        // Trace la clôture ; un écart non nul produit en plus une entrée dédiée.
        $this->audit->caisseCloturee($session);

        return $this->rapports->construire($session, true);
    }

    /**
     * Rapport Z d'une session déjà clôturée (réimpression, consultation gérant).
     */
    public function rapportZ(SessionCaisse $session): RapportCaisse
    {
        return $this->rapports->construire($session, !$session->estOuverte());
    }
}
