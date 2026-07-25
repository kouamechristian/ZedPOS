<?php

namespace App\Controller;

use App\Repository\VenteRepository;
use App\Service\ImpressionService;
use App\Service\TicketBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/caisse/ticket')]
#[IsGranted('ROLE_CAISSIER')]
class TicketController extends AbstractController
{
    /**
     * Ticket au format 80 mm, imprimable via window.print() (?auto=1 pour l'impression
     * automatique dans un iframe après encaissement).
     */
    #[Route('/{uuid}', name: 'app_caisse_ticket', methods: ['GET'])]
    public function afficher(string $uuid, Request $request, VenteRepository $ventes, TicketBuilder $builder): Response
    {
        return $this->render('ticket/ticket.html.twig', [
            'ticket' => $builder->construire($this->vente($uuid, $ventes)),
            'auto' => $request->query->getBoolean('auto'),
        ]);
    }

    /**
     * Commande ESC/POS (base64) destinée à un pont d'impression thermique local.
     */
    #[Route('/{uuid}/escpos', name: 'app_caisse_ticket_escpos', methods: ['GET'])]
    public function escpos(string $uuid, VenteRepository $ventes, TicketBuilder $builder, ImpressionService $impression): JsonResponse
    {
        $commande = $impression->commandeEscPos($builder->construire($this->vente($uuid, $ventes)));

        return $this->json([
            'ok' => true,
            'base64' => base64_encode($commande),
            'longueur' => \strlen($commande),
        ]);
    }

    private function vente(string $uuid, VenteRepository $ventes): \App\Entity\Vente
    {
        try {
            $identifiant = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Ticket introuvable.');
        }

        return $ventes->findOneBy(['uuid' => $identifiant]) ?? throw $this->createNotFoundException('Ticket introuvable.');
    }
}
