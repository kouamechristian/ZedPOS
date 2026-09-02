<?php

namespace App\Controller\Api;

use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Repository\VenteRepository;
use App\Security\Permission;
use App\Service\EncaissementException;
use App\Service\EncaissementService;
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
    public function __construct(private readonly EncaissementService $encaissement)
    {
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
     * Annulation d'une vente encaissée : gérant et dirigeante sans restriction, et
     * le caissier sur le seul ticket qu'il vient d'encaisser
     * ({@see \App\Security\Voter\VenteVoter}).
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
        ];
    }
}
