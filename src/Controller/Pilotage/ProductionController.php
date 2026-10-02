<?php

namespace App\Controller\Pilotage;

use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Security\Permission;
use App\Service\ChiffreCaisseService;
use App\Service\GenerateurPdf;
use App\Service\ParametresBoutique;
use App\Service\ProductionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Rapport de production de la dirigeante : la fiche de production d'une caisse,
 * article par article — produit, en vitrine, vendu, reste — et ce que chaque
 * article a rapporté. À l'écran et en PDF.
 *
 * Comme le tableau de bord, il se lit **par caisse** : la caisse ouverte d'office,
 * à défaut la dernière clôturée, ou celle qu'on choisit (`?session=`). Écran, PDF
 * et rapport de l'atelier consomment le même `RapportProduction`.
 *
 * Lecture seule, comme tout le pilotage.
 */
#[Route('/pilotage/production')]
#[IsGranted('ROLE_DIRIGEANTE')]
#[IsGranted(Permission::VOIR_CA_GLOBAL)]
class ProductionController extends AbstractController
{
    public function __construct(
        private readonly ChiffreCaisseService $chiffreCaisse,
        private readonly ProductionService $production,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'pilotage_production', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $rapport = null !== ($session = $this->session($request)) ? $this->production->rapport($session) : null;

        return $this->render('pilotage/production.html.twig', [
            'session' => $session,
            'lignes' => $rapport?->lignes ?? [],
            'totaux' => $rapport?->totaux ?? [],
            'remises' => $rapport?->remises ?? 0,
            'sessions' => $this->chiffreCaisse->recentes(),
        ]);
    }

    /**
     * Le même rapport en PDF, rendu par Dompdf. Téléchargé : c'est un fichier
     * qu'on classe ou qu'on transmet.
     */
    #[Route('.pdf', name: 'pilotage_production_pdf', methods: ['GET'])]
    public function pdf(Request $request, GenerateurPdf $pdf, ParametresBoutique $boutique): Response
    {
        $session = $this->session($request) ?? throw $this->createNotFoundException('Aucune caisse.');
        $rapport = $this->production->rapport($session);

        $html = $this->renderView('production/rapport_pdf.html.twig', [
            'session' => $session,
            'lignes' => $rapport->lignes,
            'totaux' => $rapport->totaux,
            'remises' => $rapport->remises,
            'avec_montants' => true,
            'signatures' => ['La gérante', 'La dirigeante'],
            'boutique' => $boutique->nom(),
            // Dompdf n'a pas de navigateur pour aller chercher une URL : le logo
            // voyage dans le document.
            'logo' => $boutique->logoDataUri(),
            'edite_le' => new \DateTimeImmutable(),
            'edite_par' => $this->getUser() instanceof Utilisateur ? $this->getUser()->getNom() : '',
        ]);

        return $pdf->reponse($html, $rapport->nomFichier());
    }

    private function session(Request $request): ?SessionCaisse
    {
        $caisse = $this->chiffreCaisse->courant($request->query->getInt('session') ?: null);

        return null !== $caisse ? $this->em->find(SessionCaisse::class, $caisse->sessionId) : null;
    }
}
