<?php

namespace App\Controller\Admin;

use App\Enum\GranulariteRapport;
use App\Security\Permission;
use App\Service\Rapport\ExportRapportStands;
use App\Service\Rapport\FiltreRapport;
use App\Service\Rapport\RapportStands;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Rapports des stands, en lecture seule : par stand, par vendeur, caisse et stands
 * côte à côte, meilleurs produits. Un filtre commun — plage de dates et pas
 * d'agrégation — et un export CSV par rapport, **avec les mêmes filtres**, qui
 * figurent dans le nom du fichier.
 *
 * Aucune route d'écriture, et il ne doit pas en être ajouté.
 */
#[Route('/admin/rapports-stands')]
#[IsGranted(Permission::STAND_GERER)]
class RapportStandController extends AbstractController
{
    public const RAPPORTS = [
        'stands' => 'Par stand',
        'vendeurs' => 'Par vendeur',
        'comparatif' => 'Caisse et stands',
        'produits' => 'Top produits',
    ];

    public function __construct(
        private readonly RapportStands $rapports,
        private readonly ExportRapportStands $export,
    ) {
    }

    #[Route('/{rapport}.csv', name: 'admin_rapport_stand_csv', methods: ['GET'], requirements: ['rapport' => 'stands|vendeurs|comparatif|produits'])]
    public function csv(Request $request, string $rapport): Response
    {
        $filtre = $this->filtre($request);

        $reponse = new Response($this->export->csv($rapport, $this->donnees($rapport, $filtre)));
        $reponse->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $reponse->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filtre->nomFichier($rapport)));

        return $reponse;
    }

    #[Route('/{rapport}', name: 'admin_rapport_stand', methods: ['GET'], requirements: ['rapport' => 'stands|vendeurs|comparatif|produits'], defaults: ['rapport' => 'stands'])]
    public function index(Request $request, string $rapport): Response
    {
        $filtre = $this->filtre($request);
        $aujourdhui = new \DateTimeImmutable('today');

        return $this->render('admin/rapport_stand/index.html.twig', [
            'rapport' => $rapport,
            'rapports' => self::RAPPORTS,
            'filtre' => $filtre,
            'donnees' => $this->donnees($rapport, $filtre),
            'granularites' => GranulariteRapport::cases(),
            'raccourcis' => FiltreRapport::libellesRaccourcis(),
            'raccourci' => $filtre->raccourci($aujourdhui),
        ]);
    }

    /** @return array<mixed> */
    private function donnees(string $rapport, FiltreRapport $filtre): array
    {
        return match ($rapport) {
            'vendeurs' => $this->rapports->parVendeur($filtre),
            'comparatif' => $this->rapports->comparatif($filtre),
            'produits' => $this->rapports->topProduits($filtre),
            default => $this->rapports->parStand($filtre),
        };
    }

    private function filtre(Request $request): FiltreRapport
    {
        return FiltreRapport::depuis($request->query->all(), new \DateTimeImmutable('today'));
    }
}
