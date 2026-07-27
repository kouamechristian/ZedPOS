<?php

namespace App\Controller\Pilotage;

use App\Entity\Notification;
use App\Enum\RoleUtilisateur;
use App\Repository\NotificationRepository;
use App\Repository\VenteRepository;
use App\Security\Permission;
use App\Service\RapportQuotidienTexte;
use App\Service\RapportVentesCsv;
use App\Service\SyntheseJournee;
use App\Service\SyntheseJourneeService;
use App\Service\TicketBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
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
            'raccourcis' => $this->raccourcis(),
            'caissieres' => $this->donneesCaissieres($synthese),
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
     * Synthèse de la journée en texte brut, prête à être transmise.
     *
     * Même service que `app:rapport-quotidien` : ce que la dirigeante télécharge
     * et ce que le cron envoie le soir sont le même message, au caractère près.
     */
    #[Route('/rapport.txt', name: 'pilotage_rapport_texte', methods: ['GET'])]
    #[IsGranted(Permission::VOIR_CA_GLOBAL)]
    public function rapportTexte(
        Request $request,
        SyntheseJourneeService $syntheses,
        RapportQuotidienTexte $miseEnForme,
    ): Response {
        $jour = $this->lireJour($request->query->get('jour')) ?? new \DateTimeImmutable('today');
        $synthese = $syntheses->construire($jour);

        return $this->fichier(
            $miseEnForme->construire($synthese)."\n",
            'text/plain; charset=UTF-8',
            'zedpos-rapport-'.$jour->format('Y-m-d').'.txt',
        );
    }

    /**
     * Ventes de la journée en CSV, une ligne par ticket, pour le tableur.
     *
     * À ne pas confondre avec `/comptabilite` : ici on trie et on additionne à la
     * main, là-bas on transmet des écritures équilibrées au cabinet.
     */
    #[Route('/rapport.csv', name: 'pilotage_rapport_csv', methods: ['GET'])]
    #[IsGranted(Permission::VOIR_TOUTES_VENTES)]
    public function rapportCsv(Request $request, VenteRepository $ventes, RapportVentesCsv $csv): Response
    {
        $jour = $this->lireJour($request->query->get('jour')) ?? new \DateTimeImmutable('today');

        return $this->fichier(
            $csv->construire($jour, $ventes->toutesDuJour($jour)),
            'text/csv; charset=UTF-8',
            $csv->nomFichier($jour),
        );
    }

    private function fichier(string $contenu, string $typeMime, string $nom): Response
    {
        $reponse = new Response($contenu);
        $reponse->headers->set('Content-Type', $typeMime);
        $reponse->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $nom,
        ));

        return $reponse;
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

    /**
     * Journées consultées en pratique. Sur un téléphone, trois pastilles valent
     * mieux qu'un calendrier à dérouler.
     *
     * @return list<array{label: string, jour: string}>
     */
    private function raccourcis(): array
    {
        $aujourdhui = new \DateTimeImmutable('today');

        return [
            ['label' => "Aujourd'hui", 'jour' => $aujourdhui->format('Y-m-d')],
            ['label' => 'Hier', 'jour' => $aujourdhui->modify('-1 day')->format('Y-m-d')],
            ['label' => 'Il y a 7 jours', 'jour' => $aujourdhui->modify('-7 days')->format('Y-m-d')],
        ];
    }

    /**
     * Données du graphique en barres du CA par caissière.
     *
     * Les montants sont convertis en **FCFA entiers** ici : les centimes n'ont
     * aucun sens à cette échelle, et le graphique ne doit jamais avoir à faire
     * d'arithmétique sur de l'argent.
     *
     * @return array{libelles: list<string>, valeurs: list<int>, couleurs: list<string>}
     */
    private function donneesCaissieres(SyntheseJournee $synthese): array
    {
        // Palette chaude fermée, dans l'esprit des touches de caisse : les
        // caissières se distinguent sans que l'écran vire au bariolé.
        $palette = ['#b45309', '#c2703a', '#a16207', '#9a3412', '#8a5a2b', '#7c2d12'];

        $libelles = [];
        $valeurs = [];
        $couleurs = [];

        foreach ($synthese->parCaissiere as $rang => $caissiere) {
            $libelles[] = $caissiere['nom'];
            $valeurs[] = intdiv($caissiere['ca'], 100);
            $couleurs[] = $palette[$rang % \count($palette)];
        }

        return ['libelles' => $libelles, 'valeurs' => $valeurs, 'couleurs' => $couleurs];
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
