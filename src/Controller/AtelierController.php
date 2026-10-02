<?php

namespace App\Controller;

use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Enum\Atelier;
use App\Enum\TypeSaisieProduction;
use App\Repository\ArticleRepository;
use App\Repository\SaisieProductionRepository;
use App\Security\Permission;
use App\Service\GenerateurPdf;
use App\Service\ParametresBoutique;
use App\Service\ProductionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace de l'atelier et de la vitrine : le boulanger, le pâtissier et les
 * vendeuses y déclarent, au doigt, ce qui sort du four et ce qui part en vitrine.
 *
 * Tout va à **la caisse ouverte** — il n'y en a qu'une à la fois, rien à choisir.
 * L'accueil montre sa **fiche de production** : produit, en vitrine, vendu et
 * reste (vitrine − vendu), article par article ; chaque déclaration s'y ajoute.
 * L'écran n'affiche **que des quantités** : ni prix, ni écart, ni chiffre
 * d'affaires — sauf pour la gérante, qui voit en plus ce que chaque article a
 * rapporté (`VOIR_CA_GLOBAL`), à l'écran comme dans le PDF du rapport. Le point, avec ses montants, est l'affaire de la gérante
 * (`/admin/vitrine`).
 *
 * Même mécanique que l'écran de dotation : pas de formulaire Symfony, un champ
 * caché `quantites[id]` par vignette, et une erreur réaffiche l'écran **en 422
 * avec ce qui a été tapé**.
 */
#[Route('/atelier')]
class AtelierController extends AbstractController
{
    private const JETON = 'atelier_declaration';

    public function __construct(
        private readonly ProductionService $service,
        private readonly SaisieProductionRepository $saisies,
        private readonly ArticleRepository $articles,
    ) {
    }

    #[Route('', name: 'app_atelier', methods: ['GET'])]
    public function index(): Response
    {
        $session = $this->service->caisseOuverte();

        return $this->render('atelier/index.html.twig', [
            'session' => $session,
            'rapport' => null !== $session ? $this->service->rapport($session) : null,
            'saisies' => null !== $session ? $this->saisies->deLaSession($session) : [],
            // Un atelier sans famille rattachée n'a rien à déclarer : pas de bouton.
            'ateliers' => array_values(array_filter(Atelier::cases(), fn (Atelier $a): bool => [] !== $this->articles->dAtelier($a))),
        ]);
    }

    /**
     * Le rapport de production de la caisse ouverte en PDF — le même document que
     * celui du pilotage. Les montants n'y figurent que pour qui a le droit de voir
     * le chiffre d'affaires : le boulanger et la vendeuse impriment des quantités.
     */
    #[Route('/rapport.pdf', name: 'app_atelier_rapport_pdf', methods: ['GET'])]
    public function rapportPdf(GenerateurPdf $pdf, ParametresBoutique $boutique): Response
    {
        $session = $this->service->caisseOuverte();
        if (null === $session) {
            $this->addFlash('error', 'Aucune caisse n\'est ouverte : il n\'y a pas de fiche à imprimer.');

            return $this->redirectToRoute('app_atelier', [], Response::HTTP_SEE_OTHER);
        }

        $rapport = $this->service->rapport($session);

        $html = $this->renderView('production/rapport_pdf.html.twig', [
            'session' => $session,
            'lignes' => $rapport->lignes,
            'totaux' => $rapport->totaux,
            'remises' => $rapport->remises,
            'avec_montants' => $this->isGranted(Permission::VOIR_CA_GLOBAL),
            'signatures' => ["L'atelier", 'La gérante'],
            'boutique' => $boutique->nom(),
            'logo' => $boutique->logoDataUri(),
            'edite_le' => new \DateTimeImmutable(),
            'edite_par' => $this->utilisateur()->getNom(),
        ]);

        return $pdf->reponse($html, $rapport->nomFichier());
    }

    #[Route('/production/{atelier}', name: 'app_atelier_production', methods: ['GET', 'POST'], requirements: ['atelier' => '[a-z-]+'])]
    #[IsGranted(Permission::PRODUCTION_DECLARER)]
    public function production(Request $request, string $atelier): Response
    {
        $atelier = Atelier::depuisSlug($atelier) ?? throw $this->createNotFoundException();

        return $this->declarer($request, TypeSaisieProduction::PRODUCTION, $atelier);
    }

    #[Route('/vitrine', name: 'app_atelier_vitrine', methods: ['GET', 'POST'])]
    #[IsGranted(Permission::VITRINE_DECLARER)]
    public function vitrine(Request $request): Response
    {
        return $this->declarer($request, TypeSaisieProduction::VITRINE, null);
    }

    #[Route('/declarations/{id}/annuler', name: 'app_atelier_annuler', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function annuler(Request $request, int $id): Response
    {
        $saisie = $this->saisies->avecLignes($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(Permission::PRODUCTION_ANNULER, $saisie);

        if ($this->isCsrfTokenValid('annuler_declaration_'.$id, (string) $request->request->get('_token'))) {
            try {
                $this->service->annuler($saisie, (string) $request->request->get('motif'), $this->utilisateur());
                $this->addFlash('success', \sprintf('Déclaration %s annulée.', $saisie->getNumero()));
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_atelier', [], Response::HTTP_SEE_OTHER);
    }

    // ------------------------------------------------------------------ Saisie

    private function declarer(Request $request, TypeSaisieProduction $type, ?Atelier $atelier): Response
    {
        $session = $this->service->caisseOuverte();

        if (null === $session) {
            $this->addFlash('error', 'Aucune caisse n\'est ouverte : la production se déclare pendant une journée de caisse.');

            return $this->redirectToRoute('app_atelier', [], Response::HTTP_SEE_OTHER);
        }

        if (!$request->isMethod('POST')) {
            return $this->saisie($session, $type, $atelier, []);
        }

        $quantites = $request->request->all('quantites');

        if (!$this->isCsrfTokenValid(self::JETON, (string) $request->request->get('_token'))) {
            return $this->saisie($session, $type, $atelier, $quantites, 'La page a expiré : validez à nouveau.');
        }

        try {
            $saisie = $this->service->declarer($type, $atelier, $quantites, $this->utilisateur());
        } catch (\DomainException $e) {
            return $this->saisie($session, $type, $atelier, $quantites, $e->getMessage());
        }

        $this->addFlash('success', \sprintf(
            '%s %s enregistrée : %d unité%s.',
            $type->libelle(),
            $saisie->getNumero(),
            intdiv($saisie->totalQuantite(), 1000),
            $saisie->totalQuantite() > 1000 ? 's' : '',
        ));

        return $this->redirectToRoute('app_atelier', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<int|string, mixed> $quantites saisie à réafficher, unités par id d'article
     */
    private function saisie(SessionCaisse $session, TypeSaisieProduction $type, ?Atelier $atelier, array $quantites, ?string $erreur = null): Response
    {
        // Où en est la fiche, par article : affiché sur chaque vignette.
        $fiche = [];
        foreach ($this->service->lireFiche($session) as $ligne) {
            $fiche[(int) $ligne->article->getId()] = $ligne;
        }

        return $this->render('atelier/saisie.html.twig', [
            'session' => $session,
            'type' => $type,
            'atelier' => $atelier,
            'articles' => $this->articles->dAtelier(TypeSaisieProduction::VITRINE === $type ? null : $atelier),
            'fiche' => $fiche,
            'quantites' => $quantites,
            'erreur' => $erreur,
            'jeton' => self::JETON,
        ], new Response(null, null !== $erreur ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function utilisateur(): Utilisateur
    {
        $utilisateur = $this->getUser();
        \assert($utilisateur instanceof Utilisateur);

        return $utilisateur;
    }
}
