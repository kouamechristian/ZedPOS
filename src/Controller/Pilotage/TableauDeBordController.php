<?php

namespace App\Controller\Pilotage;

use App\Entity\Notification;
use App\Enum\RoleUtilisateur;
use App\Repository\NotificationRepository;
use App\Repository\VenteRepository;
use App\Security\Permission;
use App\Service\SyntheseJourneeService;
use App\Service\TicketBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Espace de pilotage de la dirigeante — consulté depuis un téléphone, donc pensé
 * **mobile-first** : une seule colonne, chiffres lisibles à bout de bras, aucun
 * tableau large qui déborde.
 *
 * En lecture seule : le pilotage observe, il ne modifie rien.
 */
#[Route('/pilotage')]
#[IsGranted('ROLE_DIRIGEANTE')]
class TableauDeBordController extends AbstractController
{
    #[Route('', name: 'app_pilotage', methods: ['GET'])]
    #[IsGranted(Permission::VOIR_CA_GLOBAL)]
    public function index(
        Request $request,
        SyntheseJourneeService $syntheses,
        NotificationRepository $notifications,
    ): Response {
        $jour = $this->lireJour($request->query->get('jour'));
        $synthese = $syntheses->construire($jour);

        return $this->render('pilotage/tableau_de_bord.html.twig', [
            'synthese' => $synthese,
            'notifications' => $notifications->nonLuesPour(RoleUtilisateur::DIRIGEANTE->value),
            'courbe' => [
                'libelles' => array_map(
                    static fn (array $point): string => (new \DateTimeImmutable($point['jour']))->format('d/m'),
                    $synthese->serie30Jours,
                ),
                // Le graphique raisonne en FCFA entiers : les centimes n'ont aucun
                // sens à cette échelle et alourdiraient la lecture.
                'valeurs' => array_map(
                    static fn (array $point): int => intdiv($point['ca'], 100),
                    $synthese->serie30Jours,
                ),
            ],
        ]);
    }

    /**
     * Acquitte une alerte (annulation de vente, par exemple).
     */
    #[Route('/notifications/{id}/lue', name: 'pilotage_notification_lue', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function marquerLue(Notification $notification, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('notification_'.$notification->getId(), (string) $request->request->get('_token'))) {
            $notification->marquerLue();
            $em->flush();
        }

        return $this->redirectToRoute('app_pilotage', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Tickets de la journée, du plus récent au plus ancien.
     */
    #[Route('/ventes', name: 'pilotage_ventes', methods: ['GET'])]
    #[IsGranted(Permission::VOIR_TOUTES_VENTES)]
    public function ventes(Request $request, VenteRepository $ventes): Response
    {
        $jour = $this->lireJour($request->query->get('jour')) ?? new \DateTimeImmutable('today');

        return $this->render('pilotage/ventes.html.twig', [
            'jour' => $jour,
            'resultat' => $ventes->journee($jour, $request->query->getInt('page', 1)),
        ]);
    }

    /**
     * Détail d'un ticket : lignes, règlements, remise et **nom du caissier**.
     */
    #[Route('/ventes/{uuid}', name: 'pilotage_vente', methods: ['GET'])]
    public function vente(string $uuid, VenteRepository $ventes, TicketBuilder $builder): Response
    {
        try {
            $identifiant = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Ticket introuvable.');
        }

        $vente = $ventes->findOneBy(['uuid' => $identifiant])
            ?? throw $this->createNotFoundException('Ticket introuvable.');
        $this->denyAccessUnlessGranted(Permission::VENTE_VOIR, $vente);

        return $this->render('pilotage/vente.html.twig', [
            'vente' => $vente,
            'ticket' => $builder->construire($vente),
        ]);
    }

    private function lireJour(mixed $valeur): ?\DateTimeImmutable
    {
        if (!\is_string($valeur) || '' === trim($valeur)) {
            return null;
        }

        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);

        return $jour ?: null;
    }
}
