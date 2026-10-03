<?php

namespace App\Controller\Magasin;

use App\Entity\Magasin\MagasinSortie;
use App\Entity\Utilisateur;
use App\Enum\Magasin\DestinationSortieMagasin;
use App\Enum\Magasin\MotifSortieMagasin;
use App\Enum\Magasin\StatutSortieMagasin;
use App\Repository\Magasin\MagasinEmplacementRepository;
use App\Repository\Magasin\MagasinProduitRepository;
use App\Repository\Magasin\MagasinSortieRepository;
use App\Repository\Magasin\MagasinStockRepository;
use App\Security\Permission;
use App\Service\Magasin\AlertesStockMagasin;
use App\Service\Magasin\QuantiteMagasin;
use App\Service\Magasin\SaisieQuantite;
use App\Service\Magasin\SortieMagasinService;
use App\Service\Magasin\StockMagasinInsuffisantException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sorties du magasin, saisies au doigt sur la tablette.
 *
 * Un seul écran de saisie : destination en tuiles, motif, demandeur, puis une
 * ligne par produit avec la quantité **en sacs ou en kilos** — l'unité se choisit
 * ligne par ligne. Deux boutons : « Enregistrer le brouillon » et « Valider la
 * sortie » ; le second fait sortir le stock d'un geste. Une sortie refusée faute
 * de stock reste en **brouillon**, et l'écran y ramène avec le message — rien
 * n'est perdu de ce qui a été tapé.
 *
 * Pas de formulaire Symfony : champs indexés `lignes[i][…]`, lus en millièmes par
 * le service. Une saisie illisible réaffiche l'écran en 422, saisie conservée.
 */
#[Route('/magasin/sorties')]
#[IsGranted(Permission::MAGASIN_VOIR)]
class SortieController extends AbstractController
{
    public function __construct(
        private readonly MagasinSortieRepository $sorties,
        private readonly MagasinProduitRepository $produits,
        private readonly MagasinEmplacementRepository $emplacements,
        private readonly MagasinStockRepository $stocks,
        private readonly SortieMagasinService $service,
        private readonly AlertesStockMagasin $alertes,
    ) {
    }

    #[Route('', name: 'magasin_sortie_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $statut = StatutSortieMagasin::tryFrom((string) $request->query->get('statut'));

        return $this->render('magasin/sortie/index.html.twig', [
            'sorties' => $this->sorties->pagines(max(1, $request->query->getInt('page', 1)), $request->query->get('q'), $statut),
            'statut' => $statut,
            'statuts' => StatutSortieMagasin::cases(),
            'comptes' => $this->sorties->compteParStatut(),
        ]);
    }

    #[Route('/nouvelle', name: 'magasin_sortie_new', methods: ['GET', 'POST'])]
    #[IsGranted(Permission::MAGASIN_GERER)]
    public function nouvelle(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->ecranSaisie(null, $request);
        }

        $this->verifierJeton($request, 'sortie_nouvelle');
        try {
            [$date, $destination, $motif, $demandePar, $commentaire, $lignes] = $this->lireSaisie($request);
            $sortie = $this->service->creer($date, $destination, $motif, $demandePar, $commentaire, $lignes, $this->auteur());
        } catch (\DomainException $e) {
            return $this->ecranSaisie(null, $request, $e->getMessage());
        }

        return $this->apresEnregistrement($sortie, $request);
    }

    #[Route('/{id}/modifier', name: 'magasin_sortie_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Permission::MAGASIN_GERER)]
    public function modifier(Request $request, int $id): Response
    {
        $sortie = $this->charger($id);
        if (!$sortie->estBrouillon()) {
            $this->addFlash('error', 'Seul un brouillon se modifie : une sortie validée s\'annule.');

            return $this->redirectToRoute('magasin_sortie_show', ['id' => $id], Response::HTTP_SEE_OTHER);
        }
        if (!$request->isMethod('POST')) {
            return $this->ecranSaisie($sortie, $request);
        }

        $this->verifierJeton($request, 'sortie_'.$id);
        try {
            [$date, $destination, $motif, $demandePar, $commentaire, $lignes] = $this->lireSaisie($request);
            $this->service->modifier($sortie, $date, $destination, $motif, $demandePar, $commentaire, $lignes, $this->auteur());
        } catch (\DomainException $e) {
            return $this->ecranSaisie($sortie, $request, $e->getMessage());
        }

        return $this->apresEnregistrement($sortie, $request);
    }

    #[Route('/{id}', name: 'magasin_sortie_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        return $this->render('magasin/sortie/show.html.twig', ['sortie' => $this->charger($id)]);
    }

    #[Route('/{id}/valider', name: 'magasin_sortie_valider', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Permission::MAGASIN_GERER)]
    public function valider(Request $request, int $id): Response
    {
        $sortie = $this->charger($id);
        $this->verifierJeton($request, 'sortie_'.$id);

        return $this->tenterValidation($sortie);
    }

    #[Route('/{id}/annuler', name: 'magasin_sortie_annuler', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Permission::MAGASIN_GERER)]
    public function annuler(Request $request, int $id): Response
    {
        $sortie = $this->charger($id);
        $this->verifierJeton($request, 'sortie_'.$id);

        try {
            $etaitValidee = $sortie->estValidee();
            $this->service->annuler($sortie, $request->request->getString('motif'), $this->auteur());
            $this->addFlash('success', $etaitValidee
                ? \sprintf('Sortie %s annulée : la marchandise est revenue en stock.', $sortie->getNumero())
                : \sprintf('Sortie %s annulée.', $sortie->getNumero()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('magasin_sortie_show', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/bon', name: 'magasin_sortie_bon', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function bon(int $id): Response
    {
        return $this->render('magasin/sortie/impression.html.twig', ['sortie' => $this->charger($id)]);
    }

    // ------------------------------------------------------------ Interne

    /** « Valider la sortie » enchaîne l'enregistrement et la validation ; sinon, retour à la fiche du brouillon. */
    private function apresEnregistrement(MagasinSortie $sortie, Request $request): Response
    {
        if ('valider' === $request->request->getString('action')) {
            return $this->tenterValidation($sortie);
        }
        $this->addFlash('success', \sprintf('Brouillon %s enregistré : le stock n\'a pas bougé.', $sortie->getNumero()));

        return $this->redirectToRoute('magasin_sortie_show', ['id' => $sortie->getId()], Response::HTTP_SEE_OTHER);
    }

    private function tenterValidation(MagasinSortie $sortie): Response
    {
        try {
            $this->service->valider($sortie, $this->auteur());
            $this->addFlash('success', \sprintf('Sortie %s validée : la marchandise est sortie du stock.', $sortie->getNumero()));
            $this->alerterSiSeuilAtteint($sortie);

            return $this->redirectToRoute('magasin_sortie_show', ['id' => $sortie->getId()], Response::HTTP_SEE_OTHER);
        } catch (StockMagasinInsuffisantException $e) {
            $this->addFlash('error', 'Sortie refusée, gardée en brouillon. '.$e->getMessage());
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute($sortie->estBrouillon() ? 'magasin_sortie_edit' : 'magasin_sortie_show', ['id' => $sortie->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * La sortie qui fait atteindre un seuil le dit tout de suite, en plus de la
     * bannière : c'est à ce moment qu'on sait qu'il faut commander.
     */
    private function alerterSiSeuilAtteint(MagasinSortie $sortie): void
    {
        $produits = array_map(static fn ($ligne) => $ligne->getProduit(), $sortie->getLignes()->toArray());
        foreach ($this->alertes->parmi(array_values($produits)) as $ligne) {
            $this->addFlash('error', \sprintf(
                'Seuil d\'alerte atteint : « %s » — il reste %s (seuil %s).',
                $ligne['produit']->getNom(),
                QuantiteMagasin::formater($ligne['produit'], $ligne['quantite']),
                QuantiteMagasin::formater($ligne['produit'], $ligne['produit']->getSeuilAlerte()),
            ));
        }
    }

    private function ecranSaisie(?MagasinSortie $sortie, Request $request, ?string $erreur = null): Response
    {
        if ($request->isMethod('POST')) {
            $lignes = array_values(array_filter($this->tableau($request, 'lignes'), 'is_array'));
            $entete = [
                'date' => $request->request->getString('date'),
                'destination' => $request->request->getString('destination'),
                'motif' => $request->request->getString('motif'),
                'demandePar' => $request->request->getString('demande_par'),
                'commentaire' => $request->request->getString('commentaire'),
            ];
        } else {
            $lignes = null === $sortie ? [] : array_map(static fn ($l): array => [
                'produit' => (string) $l->getProduit()->getId(),
                'quantite' => SaisieQuantite::texte($l->getQteSaisie()),
                'unite' => $l->isEnUniteAchat() ? 'achat' : 'stock',
                'emplacement' => (string) $l->getEmplacement()->getId(),
            ], $sortie->getLignes()->toArray());
            $entete = [
                'date' => ($sortie?->getDateSortie() ?? new \DateTimeImmutable('today'))->format('Y-m-d'),
                'destination' => $sortie?->getDestination()->value ?? DestinationSortieMagasin::ATELIER->value,
                'motif' => $sortie?->getMotif()->value ?? MotifSortieMagasin::PRODUCTION->value,
                'demandePar' => (string) $sortie?->getDemandePar(),
                'commentaire' => (string) $sortie?->getCommentaire(),
            ];
        }
        if ([] === $lignes) {
            $lignes = [['produit' => '', 'quantite' => '', 'unite' => 'achat', 'emplacement' => '']];
        }

        $produits = $this->produits->actifs();
        foreach ($sortie?->getLignes() ?? [] as $ligne) {
            if (!\in_array($ligne->getProduit(), $produits, true)) {
                $produits[] = $ligne->getProduit();
            }
        }
        $emplacements = $this->emplacements->actifs();

        // Ce qui est disponible, par produit et par emplacement, rappelé sous chaque
        // ligne : on voit avant de valider qu'il n'y a pas 30 sacs en réserve.
        $ids = array_map(static fn ($p): int => (int) $p->getId(), $produits);
        $disponibles = [];
        foreach ($this->stocks->detailParProduit($ids) as $idProduit => $parEmplacement) {
            foreach ($parEmplacement as $stock) {
                $disponibles[$idProduit][(int) $stock->getEmplacement()->getId()] = $stock->getQuantite();
            }
        }
        $infos = [];
        foreach ($produits as $p) {
            $parEmplacement = [];
            foreach ($emplacements as $e) {
                $parEmplacement[(int) $e->getId()] = QuantiteMagasin::formater($p, $disponibles[(int) $p->getId()][(int) $e->getId()] ?? 0);
            }
            $infos[(int) $p->getId()] = [
                'achat' => $p->aUneUniteAchat() ? (string) $p->getUniteAchat() : null,
                'stock' => $p->getUniteStock(),
                'disponible' => $parEmplacement,
            ];
        }

        return $this->render('magasin/sortie/saisie.html.twig', [
            'sortie' => $sortie,
            'erreur' => $erreur,
            'entete' => $entete,
            'lignes' => $lignes,
            'produits' => $produits,
            'emplacements' => $emplacements,
            'reserve' => $emplacements[0] ?? null,
            'infos' => $infos,
            'destinations' => DestinationSortieMagasin::cases(),
            'motifs' => MotifSortieMagasin::cases(),
        ], new Response(status: null !== $erreur ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: DestinationSortieMagasin, 2: MotifSortieMagasin, 3: string, 4: string, 5: list<array<string, mixed>>}
     *
     * @throws \DomainException
     */
    private function lireSaisie(Request $request): array
    {
        $texteDate = $request->request->getString('date');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $texteDate);
        if (false === $date || $date->format('Y-m-d') !== $texteDate) {
            throw new \DomainException('Date de sortie illisible.');
        }
        if ($date > new \DateTimeImmutable('today')) {
            throw new \DomainException('La date de sortie ne peut pas être dans le futur.');
        }

        $destination = DestinationSortieMagasin::tryFrom($request->request->getString('destination')) ?? throw new \DomainException('Choisissez la destination.');
        $motif = MotifSortieMagasin::tryFrom($request->request->getString('motif')) ?? throw new \DomainException('Choisissez le motif de la sortie.');

        return [$date, $destination, $motif, $request->request->getString('demande_par'), $request->request->getString('commentaire'), $this->service->lireLignes($this->tableau($request, 'lignes'))];
    }

    /** @return array<int|string, mixed> */
    private function tableau(Request $request, string $cle): array
    {
        $valeur = $request->request->all()[$cle] ?? [];

        return \is_array($valeur) ? $valeur : [];
    }

    private function charger(int $id): MagasinSortie
    {
        return $this->sorties->avecLignes($id) ?? throw new NotFoundHttpException('Sortie introuvable.');
    }

    private function verifierJeton(Request $request, string $intention): void
    {
        if (!$this->isCsrfTokenValid($intention, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide : rechargez la page.');
        }
    }

    private function auteur(): Utilisateur
    {
        $utilisateur = $this->getUser();
        \assert($utilisateur instanceof Utilisateur);

        return $utilisateur;
    }
}
