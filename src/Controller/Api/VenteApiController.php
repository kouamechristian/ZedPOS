<?php

namespace App\Controller\Api;

use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Repository\VenteRepository;
use App\Security\Permission;
use App\Service\EncaissementException;
use App\Service\EncaissementService;
use App\Service\TicketMateriel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api')]
class VenteApiController extends AbstractController
{
    public function __construct(
        private readonly EncaissementService $encaissement,
        private readonly TicketMateriel $materiel,
    ) {
    }

    #[Route('/vente', name: 'api_vente_creer', methods: ['POST'])]
    #[IsGranted('ROLE_CAISSIER')]
    public function creer(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];
        $remiseMaxBp = $this->isGranted('ROLE_GERANT') ? 1000 : 0; // gérant 10 %, caissier 0 %

        try {
            /** @var Utilisateur $utilisateur */
            $utilisateur = $this->getUser();
            $resultat = $this->encaissement->encaisser($utilisateur, $donnees, $remiseMaxBp);
        } catch (EncaissementException $e) {
            return $this->json(['ok' => false, 'erreur' => $e->getMessage()], $e->statut());
        }

        $code = $resultat->rejoue ? Response::HTTP_OK : Response::HTTP_CREATED;

        return $this->json($this->representer($resultat->vente, $resultat->rendu), $code);
    }

    /**
     * Modification du ticket qu'on vient d'encaisser : l'original est annulé et
     * remplacé par la version corrigée, **une seule fois**
     * ({@see \App\Security\Voter\VenteVoter}). Toujours notifiée à la dirigeante.
     *
     * Ne passe **pas** par la file de synchronisation hors ligne : rejouée au
     * retour du réseau, elle porterait sur un ticket que d'autres ventes auront
     * dépassé. L'écran la refuse franchement quand le réseau manque.
     */
    #[Route('/vente/{uuid}/modifier', name: 'api_vente_modifier', methods: ['POST'])]
    public function modifier(string $uuid, Request $request, VenteRepository $ventes): JsonResponse
    {
        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];

        try {
            $identifiant = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            return $this->json(['ok' => false, 'erreur' => 'UUID invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $originale = $ventes->findOneBy(['uuid' => $identifiant]);
        if (null === $originale) {
            return $this->json(['ok' => false, 'erreur' => 'Ticket introuvable — il n\'est peut-être pas encore transmis.'], Response::HTTP_NOT_FOUND);
        }

        // Rejeu d'une modification déjà faite (réponse perdue en route) :
        // l'original est désormais annulé et l'habilitation le refuserait. On
        // reconnaît le remplaçant avant de la consulter.
        $uuidRemplacant = $donnees['uuid'] ?? null;
        if (\is_string($uuidRemplacant) && Uuid::isValid($uuidRemplacant)) {
            $remplacante = $ventes->findOneBy(['uuid' => Uuid::fromString($uuidRemplacant)]);
            if (null !== $remplacante && $remplacante->getVenteRemplacee()?->getId() === $originale->getId()) {
                $this->denyAccessUnlessGranted(Permission::VENTE_VOIR, $remplacante);

                return $this->json($this->representer($remplacante, $remplacante->getRendu()), Response::HTTP_OK);
            }
        }

        $this->denyAccessUnlessGranted(Permission::VENTE_MODIFIER, $originale);

        try {
            /** @var Utilisateur $auteur */
            $auteur = $this->getUser();
            $remiseMaxBp = $this->isGranted('ROLE_GERANT') ? 1000 : 0;
            $resultat = $this->encaissement->modifier($originale, $auteur, $donnees, $remiseMaxBp);
        } catch (EncaissementException $e) {
            return $this->json(['ok' => false, 'erreur' => $e->getMessage()], $e->statut());
        }

        $code = $resultat->rejoue ? Response::HTTP_OK : Response::HTTP_CREATED;

        return $this->json($this->representer($resultat->vente, $resultat->rendu), $code);
    }

    /**
     * Annulation d'une vente encaissée : gérant et dirigeante seulement
     * ({@see \App\Security\Voter\VenteVoter}). Le caissier n'annule plus, il
     * modifie son dernier ticket ({@see self::modifier()}).
     * Toujours notifiée à la dirigeante, jamais de suppression.
     */
    #[Route('/vente/{uuid}/annuler', name: 'api_vente_annuler', methods: ['POST'])]
    public function annuler(string $uuid, Request $request, VenteRepository $ventes): JsonResponse
    {
        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];

        try {
            $identifiant = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            return $this->json(['ok' => false, 'erreur' => 'UUID invalide.'], Response::HTTP_BAD_REQUEST);
        }

        // L'habilitation est évaluée sur la vente elle-même, pas sur un rôle nu.
        $vente = $ventes->findOneBy(['uuid' => $identifiant]);
        if (null === $vente) {
            return $this->json(['ok' => false, 'erreur' => 'Vente introuvable.'], Response::HTTP_NOT_FOUND);
        }
        $this->denyAccessUnlessGranted(Permission::VENTE_ANNULER, $vente);

        try {
            /** @var Utilisateur $auteur */
            $auteur = $this->getUser();
            $vente = $this->encaissement->annuler($identifiant, (string) ($donnees['motif'] ?? ''), $auteur);
        } catch (EncaissementException $e) {
            return $this->json(['ok' => false, 'erreur' => $e->getMessage()], $e->statut());
        }

        return $this->json([
            'ok' => true,
            'uuid' => (string) $vente->getUuid(),
            'statut' => $vente->getStatut()->value,
            'motifAnnulation' => $vente->getMotifAnnulation(),
        ]);
    }

    /**
     * Représentation JSON d'une vente encaissée.
     *
     * `ticket` est la charge utile destinée à la route `/print` de l'agent
     * matériel local. Elle ne porte que ce qui s'imprime — identité de la
     * boutique, lignes, totaux, monnaie — et **aucune donnée de gestion** :
     * {@see \App\Tests\Functional\FuiteDonneesCaisseTest} fige la liste des clés
     * de cette réponse et interdit tout terme de coût, de marge ou de stock. L'y
     * ajouter était une décision, au même titre que `image` dans le catalogue.
     *
     * Montants en **FCFA entiers** dans `ticket`, en centimes partout ailleurs :
     * l'agent imprime ce qu'on lui donne, la conversion se fait donc au moment de
     * composer le ticket ({@see TicketMateriel}) et nulle part avant.
     *
     * @return array<string, mixed>
     */
    private function representer(Vente $vente, int $rendu): array
    {
        return [
            'ok' => true,
            'uuid' => (string) $vente->getUuid(),
            'numero' => $vente->getNumero(),
            'statut' => $vente->getStatut()->value,
            'totalHt' => $vente->getTotalHt(),
            'totalTva' => $vente->getTotalTva(),
            'totalTtc' => $vente->getTotalTtc(),
            'remise' => $vente->getRemise(),
            'rendu' => $rendu,
            'ticket' => $this->materiel->pour($vente),
        ];
    }
}
