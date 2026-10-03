<?php

namespace App\Controller\Magasin;

use App\Entity\Magasin\MagasinProduit;
use App\Entity\Utilisateur;
use App\Enum\Magasin\CategorieProduitMagasin;
use App\Enum\Magasin\MotifSortieMagasin;
use App\Repository\Magasin\MagasinEmplacementRepository;
use App\Security\Permission;
use App\Service\GenerateurPdf;
use App\Service\Magasin\FiltreAnalyseMagasin;
use App\Service\Magasin\MagasinAnalyseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Analyse des mouvements du magasin et fiche de stock d'un produit, à l'écran et
 * en PDF. **Lecture seule** (MAGASIN_VOIR) : ouverte au magasinier.
 *
 * L'écran et le PDF consomment le **même** résultat de {@see MagasinAnalyseService},
 * pour le même filtre : un document imprimé qui ne correspondrait pas à l'écran
 * ferait perdre confiance dans les deux. Les valeurs n'apparaissent qu'avec
 * MAGASIN_VOIR_PRIX, dans les deux.
 */
#[IsGranted(Permission::MAGASIN_VOIR)]
class AnalyseController extends AbstractController
{
    public function __construct(
        private readonly MagasinAnalyseService $analyse,
        private readonly MagasinEmplacementRepository $emplacements,
    ) {
    }

    #[Route('/magasin/analyse', name: 'magasin_analyse', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filtre = $this->filtre($request);

        return $this->render('magasin/analyse/index.html.twig', $this->donneesAnalyse($filtre));
    }

    #[Route('/magasin/analyse.pdf', name: 'magasin_analyse_pdf', methods: ['GET'])]
    public function pdf(Request $request, GenerateurPdf $pdf): Response
    {
        $filtre = $this->filtre($request);
        $html = $this->renderView('magasin/analyse/pdf.html.twig', $this->donneesAnalyse($filtre) + $this->edition());

        return $pdf->reponse($html, \sprintf('analyse-magasin_%s_%s.pdf', $filtre->du->format('Y-m-d'), $filtre->au->format('Y-m-d')), telecharger: false);
    }

    #[Route('/magasin/stock/{id}/fiche', name: 'magasin_fiche_stock', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function fiche(Request $request, MagasinProduit $produit): Response
    {
        $filtre = $this->filtre($request);

        return $this->render('magasin/analyse/fiche.html.twig', $this->donneesFiche($produit, $filtre));
    }

    #[Route('/magasin/stock/{id}/fiche.pdf', name: 'magasin_fiche_stock_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function fichePdf(Request $request, MagasinProduit $produit, GenerateurPdf $pdf): Response
    {
        $filtre = $this->filtre($request);
        $html = $this->renderView('magasin/analyse/fiche_pdf.html.twig', $this->donneesFiche($produit, $filtre) + $this->edition());
        $nom = (new AsciiSlugger('fr'))->slug($produit->getNom())->lower()->toString();

        return $pdf->reponse($html, \sprintf('fiche-stock_%s_%s_%s.pdf', $nom, $filtre->du->format('Y-m-d'), $filtre->au->format('Y-m-d')), telecharger: false);
    }

    private function filtre(Request $request): FiltreAnalyseMagasin
    {
        return FiltreAnalyseMagasin::depuis($request, $this->emplacements->find(...));
    }

    /** @return array<string, mixed> */
    private function donneesAnalyse(FiltreAnalyseMagasin $filtre): array
    {
        $lignes = $this->analyse->analyser($filtre);

        return [
            'filtre' => $filtre,
            'lignes' => $lignes,
            'valeurTotale' => array_sum(array_column($lignes, 'valeur')),
            // Une colonne de motif n'apparaît que si elle a servi dans la période.
            'motifs' => array_values(array_filter(
                MotifSortieMagasin::cases(),
                static fn (MotifSortieMagasin $m): bool => [] !== array_filter($lignes, static fn (array $l): bool => isset($l['sortiesParMotif'][$m->value])),
            )),
            'categories' => CategorieProduitMagasin::cases(),
            'emplacements' => $this->emplacements->actifs(),
        ];
    }

    /** @return array<string, mixed> */
    private function donneesFiche(MagasinProduit $produit, FiltreAnalyseMagasin $filtre): array
    {
        return [
            'produit' => $produit,
            'filtre' => $filtre,
            'fiche' => $this->analyse->fiche($produit, $filtre),
            'emplacements' => $this->emplacements->actifs(),
        ];
    }

    /** @return array{edite_le: \DateTimeImmutable, edite_par: string} */
    private function edition(): array
    {
        $utilisateur = $this->getUser();

        return ['edite_le' => new \DateTimeImmutable(), 'edite_par' => $utilisateur instanceof Utilisateur ? $utilisateur->getNom() : ''];
    }
}
