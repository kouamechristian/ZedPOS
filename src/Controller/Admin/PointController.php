<?php

namespace App\Controller\Admin;

use App\Entity\Arrete;
use App\Entity\Emplacement;
use App\Entity\Utilisateur;
use App\Entity\Vendeur;
use App\Enum\MotifRetour;
use App\Enum\PeriodicitePoint;
use App\Enum\TraitementEcart;
use App\Repository\ArreteRepository;
use App\Repository\BonDotationRepository;
use App\Repository\DetteVendeurRepository;
use App\Repository\EmplacementRepository;
use App\Repository\VendeurRepository;
use App\Security\Permission;
use App\Service\ArreteService;
use App\Service\DotationsEnBrouillonException;
use App\Service\Point\PeriodeArrete;
use App\Service\Point\ResultatPoint;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le point avec le vendeur d'un stand, sur une page tactile.
 *
 * 1. Le stand choisi, l'écran annonce aussitôt « Période à arrêter : du … au … » :
 *    le début est imposé, la fin se choisit par trois raccourcis ou une date libre.
 * 2. Le tableau liste tout ce qui a été confié sur la période ; la gérante ne tape
 *    que les invendus et les pertes.
 * 3. Vendu, montants, rémunération, net et écart se recalculent à chaque appui
 *    (`point_controller.js`) ; le serveur recalcule tout à l'enregistrement.
 *
 * Pas de formulaire Symfony, comme la dotation : une grille de quantités s'y prête
 * mal. Une erreur réaffiche l'écran **en 422 avec la saisie**.
 */
#[Route('/admin/points')]
#[IsGranted(Permission::STAND_GERER)]
class PointController extends AbstractController
{
    private const JETON = 'point_saisie';

    public function __construct(
        private readonly ArreteService $service,
        private readonly ArreteRepository $arretes,
        private readonly EmplacementRepository $emplacements,
        private readonly VendeurRepository $vendeurs,
        private readonly BonDotationRepository $dotations,
        private readonly DetteVendeurRepository $dettes,
    ) {
    }

    #[Route('', name: 'admin_point_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('admin/point/index.html.twig', [
            'arretes' => $this->arretes->pagines($request->query->getInt('page', 1), $request->query->get('q')),
        ]);
    }

    #[Route('/nouveau', name: 'admin_point_new', methods: ['GET'])]
    public function nouveau(): Response
    {
        $stands = [];
        foreach ($this->emplacements->standsActifs() as $stand) {
            $stands[] = [
                'stand' => $stand,
                'debut' => $this->service->debutPeriode($stand),
                'brouillon' => $this->arretes->brouillonDe($stand),
                'dernier' => $this->arretes->dernierValide($stand),
            ];
        }

        return $this->render('admin/point/choix_stand.html.twig', [
            'stands' => $stands,
            'aujourdhui' => new \DateTimeImmutable('today'),
        ]);
    }

    #[Route('/stand/{id}', name: 'admin_point_stand', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function saisie(Request $request, int $id): Response
    {
        $stand = $this->emplacements->standActif($id) ?? throw $this->createNotFoundException();

        if (!$request->isMethod('POST')) {
            return $this->ecran($stand, $this->valeursDepuisRequete($request, $stand));
        }

        $valeurs = $this->valeursPostees($request);

        if (!$this->isCsrfTokenValid(self::JETON, (string) $request->request->get('_token'))) {
            return $this->ecran($stand, $valeurs, 'La page a expiré : enregistrez à nouveau.');
        }

        try {
            $arrete = $this->service->enregistrer(
                $stand,
                $this->date($valeurs['fin']),
                $this->vendeur($valeurs['vendeur']),
                $this->service->lireSaisie($valeurs['retours']),
                $this->montant($valeurs['remis']),
                $valeurs['commentaire'],
                TraitementEcart::tryFrom((string) $valeurs['traitement']),
                $this->utilisateur(),
            );
        } catch (\DomainException $e) {
            return $this->ecran($stand, $valeurs, $e->getMessage());
        }

        if ('valider' !== $request->request->get('action')) {
            $this->addFlash('success', \sprintf('Point %s enregistré en brouillon.', $arrete->getNumero()));

            return $this->redirectToRoute('admin_point_stand', ['id' => $stand->getId()], Response::HTTP_SEE_OTHER);
        }

        try {
            $this->service->valider($arrete, $this->utilisateur());
        } catch (\DomainException $e) {
            // La saisie est déjà enregistrée en brouillon : on y revient, rien n'est perdu.
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_point_stand', ['id' => $stand->getId()], Response::HTTP_SEE_OTHER);
        }

        $dette = $this->dettes->deArrete($arrete);
        $this->addFlash('success', null === $dette
            ? \sprintf('Point %s validé.', $arrete->getNumero())
            : \sprintf('Point %s validé. Dette de %s FCFA ouverte au nom de %s.', $arrete->getNumero(), number_format(intdiv($dette->getMontant(), 100), 0, ',', ' '), $dette->getVendeur()->getNom()));

        return $this->redirectToRoute('admin_point_show', ['id' => $arrete->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}', name: 'admin_point_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $arrete = $this->trouver($id);

        return $this->render('admin/point/show.html.twig', [
            'arrete' => $arrete,
            'resultat' => $this->service->resultat($arrete),
            'annulable' => $arrete->estValide() && $this->arretes->dernierValide($arrete->getStand()) === $arrete,
            'dette' => $this->dettes->deArrete($arrete),
        ]);
    }

    #[Route('/{id}/annuler', name: 'admin_point_annuler', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function annuler(Request $request, int $id): Response
    {
        $arrete = $this->trouver($id);

        if ($this->isCsrfTokenValid('point_'.$id, (string) $request->request->get('_token'))) {
            try {
                $this->service->annuler($arrete, (string) $request->request->get('motif'), $this->utilisateur());
                $this->addFlash('success', \sprintf('Point %s annulé : sa période est de nouveau à arrêter.', $arrete->getNumero()));
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('admin_point_show', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    /** Fiche de point imprimable, A4, à signer par la gérante et le vendeur. */
    #[Route('/{id}/fiche', name: 'admin_point_fiche', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function fiche(int $id): Response
    {
        $arrete = $this->trouver($id);

        return $this->render('admin/point/fiche.html.twig', [
            'arrete' => $arrete,
            'resultat' => $this->service->resultat($arrete),
            'dette' => $this->dettes->deArrete($arrete),
        ]);
    }

    // ------------------------------------------------------------------ Écran

    /**
     * @param array{fin: string, vendeur: ?int, retours: array<int|string, mixed>, remis: string, commentaire: ?string, traitement: ?string} $valeurs
     */
    private function ecran(Emplacement $stand, array $valeurs, ?string $erreur = null): Response
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $debut = $this->service->debutPeriode($stand);
        $brouillon = $this->arretes->brouillonDe($stand);

        $vue = [
            'stand' => $stand,
            'brouillon' => $brouillon,
            'debut' => $debut,
            'aujourdhui' => $aujourdhui,
            'dernier' => $this->arretes->dernierValide($stand),
            'erreur' => $erreur,
            'jeton' => self::JETON,
            'valeurs' => $valeurs,
            'vendeurs' => $this->vendeurs->actifs(),
            'motifs_perte' => MotifRetour::pertes(),
            'traitements' => TraitementEcart::cases(),
        ];

        // Rien à arrêter : aucune dotation, ou période déjà arrêtée jusqu'à aujourd'hui.
        if (null === $debut || $debut > $aujourdhui) {
            return $this->render('admin/point/saisie.html.twig', $vue + ['fin' => null, 'base' => null, 'raccourcis' => [], 'brouillons' => []], $this->statut($erreur));
        }

        $fin = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeurs['fin']) ?: $aujourdhui;
        if ($fin < $debut || $fin > $aujourdhui) {
            $erreur ??= \sprintf('Fin de période hors limites : entre le %s et aujourd\'hui.', $debut->format('d/m/Y'));
            $fin = $aujourdhui;
        }

        // Le tableau et ses prix figés viennent du calcul **sans** la saisie : il ne
        // dépend pas de ce qui est tapé, et reste affichable même si la saisie est
        // refusée. Le script refait le calcul avec les quantités à chaque appui.
        $base = $this->service->calculer($stand, $debut, $fin, [], null);

        return $this->render('admin/point/saisie.html.twig', $vue + [
            'fin' => $fin,
            'base' => $base,
            'produits' => $this->produitsPourEcran($base),
            'raccourcis' => PeriodeArrete::raccourcis($debut, $aujourdhui),
            'granularite' => PeriodeArrete::granularite($debut, $fin),
            'brouillons' => $this->dotations->brouillonsDansPeriode($stand, $debut, $fin),
        ], $this->statut($erreur));
    }

    /** @return list<array<string, mixed>> ce que le script doit savoir de chaque produit */
    private function produitsPourEcran(ResultatPoint $base): array
    {
        return array_map(static fn ($ligne): array => [
            'id' => $ligne->produitId,
            'nom' => $ligne->produit,
            'confiee' => $ligne->qteConfiee,
            // Retours déjà écrits sur la période (hors saisie de ce point).
            'dejaRetournee' => $ligne->qteRetournee,
            'dejaPerdue' => $ligne->qtePerdue,
            'tranches' => array_map(static fn ($t): array => ['quantite' => $t->quantiteConfiee, 'prixVente' => $t->prixVente, 'prixCession' => $t->prixCession], $ligne->tranches),
        ], $base->lignes);
    }

    /**
     * GET : fin demandée par un raccourci, sinon celle du brouillon, sinon celle que
     * suggère la périodicité du stand. La saisie peut voyager dans l'URL quand la
     * gérante change de période en cours de frappe.
     *
     * @return array{fin: string, vendeur: ?int, retours: array<int|string, mixed>, remis: string, commentaire: ?string, traitement: ?string}
     */
    private function valeursDepuisRequete(Request $request, Emplacement $stand): array
    {
        $brouillon = $this->arretes->brouillonDe($stand);
        $fin = (string) $request->query->get('fin', '');

        if ('' === $fin) {
            $fin = ($brouillon?->getDateFin() ?? $this->finSuggeree($stand))?->format('Y-m-d') ?? '';
        }

        if ($request->query->has('retours') || $request->query->has('remis')) {
            return [
                'fin' => $fin,
                'vendeur' => $this->entier($request->query->get('vendeur')) ?: null,
                'retours' => $request->query->all('retours'),
                'remis' => (string) $request->query->get('remis', ''),
                'commentaire' => $request->query->get('commentaire'),
                'traitement' => $request->query->get('traitement'),
            ];
        }

        $retours = [];
        foreach (null !== $brouillon ? $this->service->saisieDu($brouillon) : [] as [$produit, $quantite, $motif]) {
            $champ = MotifRetour::INVENDU === $motif ? 'invendu' : 'perdu';
            $retours[$produit->getId()][$champ] = (string) intdiv($quantite, 1000);
            if ($motif->estPerte()) {
                $retours[$produit->getId()]['motif'] = $motif->value;
            }
        }

        return [
            'fin' => $fin,
            'vendeur' => $brouillon?->getVendeur()->getId() ?? $this->vendeurParDefaut($stand)?->getId(),
            'retours' => $retours,
            'remis' => null !== $brouillon?->getMontantRemis() ? (string) intdiv($brouillon->getMontantRemis(), 100) : '',
            'commentaire' => $brouillon?->getCommentaireEcart(),
            'traitement' => $brouillon?->getTraitementEcart()?->value,
        ];
    }

    /** @return array{fin: string, vendeur: ?int, retours: array<int|string, mixed>, remis: string, commentaire: ?string, traitement: ?string} */
    private function valeursPostees(Request $request): array
    {
        return [
            'fin' => (string) $request->request->get('fin', ''),
            'vendeur' => $this->entier($request->request->get('vendeur')) ?: null,
            'retours' => $request->request->all('retours'),
            'remis' => (string) $request->request->get('remis', ''),
            'commentaire' => $request->request->get('commentaire'),
            'traitement' => $request->request->get('traitement'),
        ];
    }

    /** La périodicité du stand choisit le raccourci proposé d'office — une suggestion, rien de plus. */
    private function finSuggeree(Emplacement $stand): ?\DateTimeImmutable
    {
        $debut = $this->service->debutPeriode($stand);
        if (null === $debut) {
            return null;
        }

        $raccourcis = PeriodeArrete::raccourcis($debut, new \DateTimeImmutable('today'));

        return match ($stand->getPeriodicitePoint()) {
            PeriodicitePoint::SEMAINE => $raccourcis[PeriodeArrete::FIN_SEMAINE] ?? $raccourcis[PeriodeArrete::AUJOURDHUI],
            PeriodicitePoint::MOIS => $raccourcis[PeriodeArrete::FIN_MOIS] ?? $raccourcis[PeriodeArrete::AUJOURDHUI],
            PeriodicitePoint::JOUR => $raccourcis[PeriodeArrete::AUJOURDHUI],
        };
    }

    /** Le vendeur de la dotation la plus récente de la période, à défaut le vendeur habituel. */
    private function vendeurParDefaut(Emplacement $stand): ?Vendeur
    {
        $debut = $this->service->debutPeriode($stand);
        if (null !== $debut) {
            $bons = $this->dotations->aEmbarquer($stand, $debut, new \DateTimeImmutable('today'));
            if ([] !== $bons) {
                return end($bons)->getVendeur();
            }
        }

        return $stand->getVendeurHabituel();
    }

    private function statut(?string $erreur): Response
    {
        return new Response(null, null !== $erreur ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    private function vendeur(?int $id): Vendeur
    {
        $vendeur = null !== $id ? $this->vendeurs->find($id) : null;
        if (null === $vendeur || !$vendeur->isActif()) {
            throw new \DomainException('Choisissez le vendeur qui fait le point.');
        }

        return $vendeur;
    }

    private function date(string $valeur): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur) ?: throw new \DomainException('Choisissez la fin de la période.');
    }

    /** Francs saisis → centimes ; vide → non saisi. Espaces de milliers tolérés. */
    private function montant(string $valeur): ?int
    {
        $chiffres = str_replace([' ', "\u{202f}", "\u{00a0}"], '', trim($valeur));
        if ('' === $chiffres) {
            return null;
        }
        if (!ctype_digit($chiffres) || \strlen($chiffres) > 9) {
            throw new \DomainException('Montant remis invalide : des francs entiers.');
        }

        return (int) $chiffres * 100;
    }

    /** Pas `getInt()` : sous Symfony 7, il répond 400 sur un champ vide. */
    private function entier(mixed $valeur): int
    {
        return \is_string($valeur) && ctype_digit($valeur) ? (int) $valeur : 0;
    }

    private function trouver(int $id): Arrete
    {
        return $this->arretes->avecDetails($id) ?? throw $this->createNotFoundException();
    }

    private function utilisateur(): Utilisateur
    {
        $utilisateur = $this->getUser();
        \assert($utilisateur instanceof Utilisateur);

        return $utilisateur;
    }
}
