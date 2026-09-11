<?php

namespace App\Controller\Admin;

use App\Entity\BonDotation;
use App\Entity\Emplacement;
use App\Entity\Utilisateur;
use App\Entity\Vendeur;
use App\Repository\ArticleRepository;
use App\Repository\BonDotationRepository;
use App\Repository\DetteVendeurRepository;
use App\Repository\EmplacementRepository;
use App\Repository\VendeurRepository;
use App\Security\Permission;
use App\Service\BonDotationService;
use App\Service\ParametresBoutique;
use App\Service\Rapport\RapportStands;
use App\Service\StockManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bons de dotation : la marchandise confiée chaque matin aux vendeurs des stands.
 *
 * L'écran de saisie est fait pour être rempli **au doigt, en quelques minutes** :
 * stand, vendeur (celui du stand d'office), vignettes produits et pavé numérique,
 * « reprendre la dernière dotation ». Il n'emploie pas de formulaire Symfony — une
 * grille de quarante quantités s'y prête mal — et lit la requête comme l'inventaire.
 *
 * Une erreur de saisie réaffiche l'écran **en 422 avec ce qui a été tapé** : perdre
 * quarante quantités pour une date mal formée ferait tout recommencer.
 */
#[Route('/admin/dotations')]
#[IsGranted(Permission::STAND_GERER)]
class DotationController extends AbstractController
{
    private const JETON = 'dotation_saisie';

    public function __construct(
        private readonly BonDotationService $service,
        private readonly BonDotationRepository $bons,
        private readonly EmplacementRepository $emplacements,
        private readonly VendeurRepository $vendeurs,
        private readonly ArticleRepository $articles,
        private readonly StockManager $stock,
        private readonly DetteVendeurRepository $dettes,
        private readonly ParametresBoutique $parametres,
        private readonly RapportStands $rapports,
    ) {
    }

    #[Route('', name: 'admin_dotation_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('admin/dotation/index.html.twig', [
            'bons' => $this->bons->pagines($request->query->getInt('page', 1), $request->query->get('q')),
        ]);
    }

    #[Route('/nouvelle', name: 'admin_dotation_new', methods: ['GET', 'POST'])]
    public function nouvelle(Request $request): Response
    {
        $stand = $this->emplacements->standActif($this->entier($request->query->get('stand') ?? $request->request->get('stand')));

        // Première étape : le stand. Une tuile par stand actif, un appui.
        if (null === $stand) {
            return $this->render('admin/dotation/choix_stand.html.twig', [
                'stands' => $this->emplacements->standsActifs(),
            ]);
        }

        if (!$request->isMethod('POST')) {
            return $this->saisie($stand, null, []);
        }

        if (!$this->isCsrfTokenValid(self::JETON, (string) $request->request->get('_token'))) {
            return $this->saisie($stand, null, $this->valeurs($request), 'La page a expiré : validez à nouveau.');
        }

        try {
            $bon = $this->service->creer($stand, $this->vendeur($request), $this->date($request), $this->quantites($request), $this->utilisateur());
        } catch (\DomainException $e) {
            return $this->saisie($stand, null, $this->valeurs($request), $e->getMessage());
        }

        return $this->apresEnregistrement($request, $bon);
    }

    #[Route('/{id}', name: 'admin_dotation_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        return $this->render('admin/dotation/show.html.twig', ['bon' => $this->trouver($id)]);
    }

    #[Route('/{id}/modifier', name: 'admin_dotation_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function modifier(Request $request, int $id): Response
    {
        $bon = $this->trouver($id);

        if (!$bon->estBrouillon()) {
            $this->addFlash('error', \sprintf('Le bon %s est %s : il ne se modifie plus.', $bon->getNumero(), mb_strtolower($bon->getStatut()->libelle())));

            return $this->redirectToRoute('admin_dotation_show', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        if (!$request->isMethod('POST')) {
            return $this->saisie($bon->getStand(), $bon, []);
        }

        if (!$this->isCsrfTokenValid(self::JETON, (string) $request->request->get('_token'))) {
            return $this->saisie($bon->getStand(), $bon, $this->valeurs($request), 'La page a expiré : validez à nouveau.');
        }

        try {
            $this->service->modifier($bon, $this->vendeur($request), $this->date($request), $this->quantites($request));
        } catch (\DomainException $e) {
            return $this->saisie($bon->getStand(), $bon, $this->valeurs($request), $e->getMessage());
        }

        return $this->apresEnregistrement($request, $bon);
    }

    #[Route('/{id}/valider', name: 'admin_dotation_valider', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function valider(Request $request, int $id): Response
    {
        $bon = $this->trouver($id);

        if ($this->isCsrfTokenValid('dotation_'.$id, (string) $request->request->get('_token'))) {
            try {
                $this->service->valider($bon, $this->utilisateur());
                $this->addFlash('success', \sprintf('Bon %s validé : la marchandise est au stand.', $bon->getNumero()));
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('admin_dotation_show', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/annuler', name: 'admin_dotation_annuler', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function annuler(Request $request, int $id): Response
    {
        $bon = $this->trouver($id);

        if ($this->isCsrfTokenValid('dotation_'.$id, (string) $request->request->get('_token'))) {
            try {
                $this->service->annuler($bon, (string) $request->request->get('motif'), $this->utilisateur());
                $this->addFlash('success', \sprintf('Bon %s annulé.', $bon->getNumero()));
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('admin_dotation_show', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    /** Bon de sortie imprimable, A4, à signer par la gérante et le vendeur. */
    #[Route('/{id}/bon', name: 'admin_dotation_impression', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function impression(int $id): Response
    {
        return $this->render('admin/dotation/impression.html.twig', ['bon' => $this->trouver($id)]);
    }

    // ------------------------------------------------------------------ Saisie

    /**
     * « Enregistrer » garde le brouillon ; « Valider » l'enchaîne. Si la validation
     * est refusée (stock insuffisant, prix manquant), le brouillon est **déjà
     * enregistré** : on y retourne avec le message, rien n'est perdu.
     */
    private function apresEnregistrement(Request $request, BonDotation $bon): Response
    {
        if ('valider' !== $request->request->get('action')) {
            $this->addFlash('success', \sprintf('Brouillon %s enregistré.', $bon->getNumero()));

            return $this->redirectToRoute('admin_dotation_show', ['id' => $bon->getId()], Response::HTTP_SEE_OTHER);
        }

        try {
            $this->service->valider($bon, $this->utilisateur());
        } catch (\DomainException $e) {
            $this->addFlash('error', \sprintf('Brouillon %s enregistré, mais non validé : %s', $bon->getNumero(), $e->getMessage()));

            return $this->redirectToRoute('admin_dotation_edit', ['id' => $bon->getId()], Response::HTTP_SEE_OTHER);
        }

        $this->addFlash('success', \sprintf('Bon %s validé : la marchandise est au stand.', $bon->getNumero()));

        return $this->redirectToRoute('admin_dotation_show', ['id' => $bon->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array{vendeur?: ?int, date?: string, quantites?: array<int|string, mixed>} $valeurs saisie à réafficher
     */
    private function saisie(Emplacement $stand, ?BonDotation $bon, array $valeurs, ?string $erreur = null): Response
    {
        $articles = $this->articles->pourDotation();
        $depot = $this->stock->depotPrincipal();
        $derniere = $this->bons->derniereValidee($stand);

        $quantites = $valeurs['quantites'] ?? null;
        if (null === $quantites) {
            $quantites = [];
            foreach ($bon?->getLignes() ?? [] as $ligne) {
                $quantites[$ligne->getProduit()->getId()] = intdiv($ligne->getQuantite(), 1000);
            }
        }

        $reprise = [];
        foreach ($derniere?->getLignes() ?? [] as $ligne) {
            $reprise[(string) $ligne->getProduit()->getId()] = intdiv($ligne->getQuantite(), 1000);
        }

        $vendeurs = $this->vendeurs->actifs();
        $seuilDette = $this->parametres->seuilAlerteDette();
        $date = $bon?->getDateDotation() ?? new \DateTimeImmutable('today');

        return $this->render('admin/dotation/saisie.html.twig', [
            'stand' => $stand,
            'bon' => $bon,
            'articles' => $articles,
            'stocks' => $this->stock->stocksArticles($depot, $articles),
            'vendeurs' => $vendeurs,
            // Seuls les vendeurs au-delà du seuil : l'alerte n'a rien à dire des autres.
            'dettes' => array_filter($this->dettes->soldes($vendeurs), static fn (int $solde): bool => $solde > $seuilDette),
            'seuil_dette' => $seuilDette,
            'vendeur_id' => $valeurs['vendeur'] ?? $bon?->getVendeur()->getId() ?? $stand->getVendeurHabituel()?->getId(),
            'date' => $valeurs['date'] ?? $date->format('Y-m-d'),
            'quantites' => $quantites,
            'derniere' => $derniere,
            'reprise' => $reprise,
            // Moyenne des quatre derniers mêmes jours de semaine, pour la date du bon.
            'suggestion' => $this->rapports->suggestion((int) $stand->getId(), $date),
            'jour_suggestion' => $date,
            'erreur' => $erreur,
            'jeton' => self::JETON,
        ], new Response(null, null !== $erreur ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** @return array{vendeur: ?int, date: string, quantites: array<int|string, mixed>} */
    private function valeurs(Request $request): array
    {
        return [
            'vendeur' => $this->entier($request->request->get('vendeur')) ?: null,
            'date' => (string) $request->request->get('date'),
            'quantites' => $this->quantites($request),
        ];
    }

    /** @return array<int|string, mixed> */
    private function quantites(Request $request): array
    {
        return $request->request->all('quantites');
    }

    private function vendeur(Request $request): Vendeur
    {
        $id = $this->entier($request->request->get('vendeur'));
        $vendeur = $id > 0 ? $this->vendeurs->find($id) : null;
        if (null === $vendeur || !$vendeur->isActif()) {
            throw new \DomainException('Choisissez le vendeur à qui la marchandise est confiée.');
        }

        return $vendeur;
    }

    private function date(Request $request): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->request->get('date'));
        if (false === $date) {
            throw new \DomainException('Date de dotation invalide.');
        }

        return $date;
    }

    /**
     * Identifiant lu dans la requête, 0 s'il est vide ou illisible.
     *
     * Pas `getInt()` : sous Symfony 7, il répond **400** sur un champ vide — un
     * vendeur non choisi aurait donné une page d'erreur au lieu de l'écran de saisie
     * avec son message.
     */
    private function entier(mixed $valeur): int
    {
        return \is_string($valeur) && ctype_digit($valeur) ? (int) $valeur : 0;
    }

    private function trouver(int $id): BonDotation
    {
        return $this->bons->avecLignes($id) ?? throw $this->createNotFoundException();
    }

    private function utilisateur(): Utilisateur
    {
        $utilisateur = $this->getUser();
        \assert($utilisateur instanceof Utilisateur);

        return $utilisateur;
    }
}
