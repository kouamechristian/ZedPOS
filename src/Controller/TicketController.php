<?php

namespace App\Controller;

use App\Repository\VenteRepository;
use App\Security\Permission;
use App\Service\ImpressionService;
use App\Service\TicketBuilder;
use App\Service\TicketMateriel;
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
     * Ticket au format 58 mm, imprimable via window.print() (?auto=1 pour l'impression
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
     * Aperçu du ticket sans page hôte : le fragment 58 mm seul, injecté dans
     * l'écran de caisse juste après l'encaissement. Même gabarit que le ticket
     * imprimé, donc aucun risque d'écart entre ce que voit la caissière et ce
     * qui sort de l'imprimante.
     */
    #[Route('/{uuid}/apercu', name: 'app_caisse_ticket_apercu', methods: ['GET'])]
    public function apercu(string $uuid, VenteRepository $ventes, TicketBuilder $builder): Response
    {
        return $this->render('ticket/_contenu.html.twig', [
            'ticket' => $builder->construire($this->vente($uuid, $ventes)),
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

    /**
     * Ticket au format attendu par la route `/print` de l'agent matériel local.
     *
     * Sert deux appelants : l'écran de caisse juste après un encaissement, et le
     * bouton « Réimprimer » de `/admin/ventes`. La réponse est identique à la clé
     * `ticket` de `POST /api/vente` — même service, donc aucun risque qu'un ticket
     * réimprimé diffère de celui qui est sorti à la vente.
     *
     * **`openDrawer` est toujours faux ici.** Une réimpression ne fait pas entrer
     * d'argent : le tiroir s'est ouvert quand le client a payé, le rouvrir à la
     * demande depuis un écran de gestion serait un moyen commode de le faire
     * s'ouvrir sans vente. L'ouverture reste attachée au seul encaissement.
     */
    #[Route('/{uuid}/materiel', name: 'app_caisse_ticket_materiel', methods: ['GET'])]
    public function materiel(string $uuid, VenteRepository $ventes, TicketMateriel $materiel): JsonResponse
    {
        return $this->json([
            'ok' => true,
            'ticket' => $materiel->pour($this->vente($uuid, $ventes), ouvrirTiroir: false),
        ]);
    }

    /**
     * Charge la vente et vérifie que l'utilisateur a le droit de la consulter :
     * un caissier ne doit pas pouvoir ouvrir le ticket d'un collègue, même en
     * connaissant son uuid.
     */
    private function vente(string $uuid, VenteRepository $ventes): \App\Entity\Vente
    {
        try {
            $identifiant = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Ticket introuvable.');
        }

        $vente = $ventes->findOneBy(['uuid' => $identifiant]) ?? throw $this->createNotFoundException('Ticket introuvable.');
        $this->denyAccessUnlessGranted(Permission::VENTE_VOIR, $vente);

        return $vente;
    }
}
