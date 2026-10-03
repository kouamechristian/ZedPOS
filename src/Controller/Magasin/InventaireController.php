<?php

namespace App\Controller\Magasin;

use App\Entity\Magasin\MagasinInventaire;
use App\Entity\Utilisateur;
use App\Repository\Magasin\MagasinEmplacementRepository;
use App\Repository\Magasin\MagasinInventaireRepository;
use App\Security\Permission;
use App\Service\Magasin\InventaireMagasinService;
use App\Service\Magasin\StockMagasinInsuffisantException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Inventaires du magasin : ouverture, comptage au doigt, feuille de comptage à
 * l'aveugle, validation, abandon.
 *
 * La gérante ouvre, compte et valide un inventaire **juste** ; dès qu'un écart
 * est constaté, la validation revient à la dirigeante (MAGASIN_VALIDER_ECART) —
 * le comptage de la gérante est enregistré quand même, la dirigeante n'a plus
 * qu'à relire et valider. Le magasinier consulte.
 *
 * « Valider » enregistre d'abord le comptage, puis tente la validation : un refus
 * (écart sans le droit, stock qui deviendrait négatif) ne perd rien de ce qui a
 * été tapé.
 */
#[Route('/magasin/inventaires')]
#[IsGranted(Permission::MAGASIN_VOIR)]
class InventaireController extends AbstractController
{
    public function __construct(
        private readonly MagasinInventaireRepository $inventaires,
        private readonly MagasinEmplacementRepository $emplacements,
        private readonly InventaireMagasinService $service,
    ) {
    }

    #[Route('', name: 'magasin_inventaire_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('magasin/inventaire/index.html.twig', [
            'inventaires' => $this->inventaires->pagines(max(1, $request->query->getInt('page', 1))),
            'enCours' => $this->inventaires->enCours(),
            'emplacements' => $this->emplacements->actifs(),
        ]);
    }

    #[Route('/ouvrir', name: 'magasin_inventaire_ouvrir', methods: ['POST'])]
    #[IsGranted(Permission::MAGASIN_GERER)]
    public function ouvrir(Request $request): Response
    {
        $this->verifierJeton($request, 'inventaire_ouvrir');
        $id = (int) (ctype_digit($request->request->getString('emplacement')) ? $request->request->getString('emplacement') : 0);
        $emplacement = $id > 0 ? $this->emplacements->find($id) : null;

        try {
            $inventaire = $this->service->ouvrir($emplacement, $this->auteur());
            $this->addFlash('success', \sprintf('Inventaire %s ouvert (%s) : imprimez la feuille, comptez, puis saisissez.', $inventaire->getNumero(), $inventaire->getPortee()));

            return $this->redirectToRoute('magasin_inventaire_show', ['id' => $inventaire->getId()], Response::HTTP_SEE_OTHER);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('magasin_inventaire_index', [], Response::HTTP_SEE_OTHER);
        }
    }

    #[Route('/{id}', name: 'magasin_inventaire_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        return $this->ecran($this->charger($id));
    }

    #[Route('/{id}', name: 'magasin_inventaire_compter', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Permission::MAGASIN_GERER)]
    public function compter(Request $request, int $id): Response
    {
        $inventaire = $this->charger($id);
        $this->verifierJeton($request, 'inventaire_'.$id);

        try {
            $comptages = $this->service->lireComptages($inventaire, $this->tableau($request, 'comptes'));
            $this->service->enregistrerComptage($inventaire, $comptages, $request->request->getString('commentaire'));
        } catch (\DomainException $e) {
            return $this->ecran($inventaire, $e->getMessage(), $this->tableau($request, 'comptes'), $request->request->getString('commentaire'));
        }

        if ('valider' !== $request->request->getString('action')) {
            $this->addFlash('success', 'Comptage enregistré : rien n\'a encore bougé dans le stock.');

            return $this->redirectToRoute('magasin_inventaire_show', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        try {
            $this->service->valider($inventaire, $this->isGranted(Permission::MAGASIN_VALIDER_ECART), $this->auteur());
            $this->addFlash('success', $inventaire->aDesEcarts()
                ? \sprintf('Inventaire %s validé : %d écart(s) reporté(s) au stock.', $inventaire->getNumero(), \count($inventaire->lignesEnEcart()))
                : \sprintf('Inventaire %s validé : le stock est juste.', $inventaire->getNumero()));
        } catch (StockMagasinInsuffisantException $e) {
            $this->addFlash('error', 'Validation refusée, comptage enregistré. '.$e->getMessage());
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('magasin_inventaire_show', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/feuille', name: 'magasin_inventaire_feuille', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function feuille(int $id): Response
    {
        return $this->render('magasin/inventaire/feuille.html.twig', ['inventaire' => $this->charger($id)]);
    }

    #[Route('/{id}/abandonner', name: 'magasin_inventaire_abandonner', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Permission::MAGASIN_GERER)]
    public function abandonner(Request $request, int $id): Response
    {
        $inventaire = $this->charger($id);
        $this->verifierJeton($request, 'inventaire_'.$id);

        try {
            $this->service->abandonner($inventaire, $this->auteur());
            $this->addFlash('success', \sprintf('Inventaire %s abandonné : le stock n\'a pas bougé.', $inventaire->getNumero()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('magasin_inventaire_index', [], Response::HTTP_SEE_OTHER);
    }

    /** @param ?array<int|string, mixed> $saisie la saisie à réafficher après une erreur */
    private function ecran(MagasinInventaire $inventaire, ?string $erreur = null, ?array $saisie = null, ?string $commentaire = null): Response
    {
        $lignes = $inventaire->getLignes()->toArray();
        usort($lignes, static fn ($a, $b): int => [$a->getEmplacement()->getLibelle(), $a->getProduit()->getNom()] <=> [$b->getEmplacement()->getLibelle(), $b->getProduit()->getNom()]);

        return $this->render('magasin/inventaire/show.html.twig', [
            'inventaire' => $inventaire,
            'lignes' => $lignes,
            'erreur' => $erreur,
            'saisie' => $saisie,
            'commentaire' => $commentaire ?? $inventaire->getCommentaire(),
        ], new Response(status: null !== $erreur ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** @return array<int|string, mixed> */
    private function tableau(Request $request, string $cle): array
    {
        $valeur = $request->request->all()[$cle] ?? [];

        return \is_array($valeur) ? $valeur : [];
    }

    private function charger(int $id): MagasinInventaire
    {
        return $this->inventaires->avecLignes($id) ?? throw new NotFoundHttpException('Inventaire introuvable.');
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
