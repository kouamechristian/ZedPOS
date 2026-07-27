<?php

namespace App\Controller\Comptabilite;

use App\Comptabilite\FormatExport;
use App\Comptabilite\JeuEcritures;
use App\Security\Permission;
use App\Service\Comptabilite\ExportComptable;
use App\Service\Comptabilite\GenerateurEcrituresSyscohada;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace comptable : exports SYSCOHADA d'une période.
 *
 * **Lecture seule.** Aucune route d'écriture ici, et il ne doit jamais en être
 * ajoutée : la comptabilité lit ce que la caisse a produit, elle ne le corrige
 * pas. Une vente erronée s'annule en caisse, où la correction laisse une trace.
 */
#[Route('/comptabilite')]
#[IsGranted(Permission::EXPORTER_COMPTABILITE)]
class ExportController extends AbstractController
{
    public function __construct(
        private readonly GenerateurEcrituresSyscohada $generateur,
        private readonly ExportComptable $export,
    ) {
    }

    #[Route('', name: 'app_comptabilite', methods: ['GET'])]
    public function index(Request $request): Response
    {
        [$du, $au] = $this->periode($request);
        $jeu = $this->generateur->construire($du, $au);

        return $this->render('comptabilite/index.html.twig', [
            'jeu' => $jeu,
            'du' => $du,
            'au' => $au,
            'formats' => FormatExport::cases(),
            'raccourcis' => $this->raccourcis(),
        ]);
    }

    #[Route('/telecharger/{format}', name: 'comptabilite_telecharger', methods: ['GET'])]
    public function telecharger(Request $request, FormatExport $format): Response
    {
        [$du, $au] = $this->periode($request);
        $jeu = $this->generateur->construire($du, $au);

        return $this->fichier($jeu, $format);
    }

    private function fichier(JeuEcritures $jeu, FormatExport $format): Response
    {
        $reponse = new Response($this->export->rendre($jeu, $format));
        $reponse->headers->set('Content-Type', $format->typeMime());
        $reponse->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $this->export->nomFichier($jeu, $format),
        ));

        return $reponse;
    }

    /**
     * Période demandée. Par défaut le **mois en cours** : c'est la période qu'on
     * consulte pour suivre, celle qu'on exporte pour de bon (le mois clos) étant
     * à un clic dans les raccourcis.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function periode(Request $request): array
    {
        $aujourdhui = new \DateTimeImmutable('today');

        $du = $this->lireDate($request->query->get('du')) ?? $aujourdhui->modify('first day of this month');
        $au = $this->lireDate($request->query->get('au')) ?? $aujourdhui;

        return $au < $du ? [$au, $du] : [$du, $au];
    }

    private function lireDate(mixed $valeur): ?\DateTimeImmutable
    {
        if (!\is_string($valeur) || '' === $valeur) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);

        return false !== $date ? $date : null;
    }

    /**
     * Périodes usuelles, en un clic : c'est ainsi qu'on exporte en pratique
     * (« le mois dernier », « le trimestre »), pas en saisissant deux dates.
     *
     * @return list<array{label: string, du: string, au: string}>
     */
    private function raccourcis(): array
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $moisCourant = $aujourdhui->modify('first day of this month');
        $moisPrecedent = $moisCourant->modify('-1 month');

        return [
            [
                'label' => 'Mois en cours',
                'du' => $moisCourant->format('Y-m-d'),
                'au' => $aujourdhui->format('Y-m-d'),
            ],
            [
                'label' => 'Mois précédent',
                'du' => $moisPrecedent->format('Y-m-d'),
                'au' => $moisPrecedent->modify('last day of this month')->format('Y-m-d'),
            ],
            [
                'label' => 'Depuis le 1er janvier',
                'du' => $aujourdhui->modify('first day of January this year')->format('Y-m-d'),
                'au' => $aujourdhui->format('Y-m-d'),
            ],
        ];
    }
}
