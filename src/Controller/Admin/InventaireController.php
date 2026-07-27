<?php

namespace App\Controller\Admin;

use App\Entity\Inventaire;
use App\Repository\InventaireRepository;
use App\Service\InventaireService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Inventaire : feuille de comptage, saisie, validation.
 *
 * La saisie ne passe pas par un type de formulaire Symfony : une feuille compte
 * plusieurs dizaines de lignes de même nature, et une `CollectionType` coûterait
 * plus qu'elle ne rapporterait ici. Les quantités sont donc lues directement dans
 * la requête, avec jeton CSRF — et un **422** en cas de refus, sans quoi Turbo
 * laisserait l'écran figé sans message.
 */
#[Route('/admin/inventaires')]
#[IsGranted('ROLE_GERANT')]
class InventaireController extends AbstractController
{
    public function __construct(
        private readonly InventaireService $service,
        private readonly InventaireRepository $inventaires,
    ) {
    }

    #[Route('', name: 'admin_inventaires', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('admin/inventaire/index.html.twig', [
            'inventaires' => $this->inventaires->paginees(
                $request->query->getInt('page', 1),
                $request->query->get('q'),
            ),
            'en_cours' => $this->inventaires->enCours(),
        ]);
    }

    #[Route('/nouveau', name: 'admin_inventaire_new', methods: ['POST'])]
    public function nouveau(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('inventaire_nouveau', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_inventaires', [], Response::HTTP_SEE_OTHER);
        }

        try {
            $inventaire = $this->service->ouvrir($this->utilisateur());
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_inventaires', [], Response::HTTP_SEE_OTHER);
        }

        $this->addFlash('success', 'Feuille de comptage ouverte : saisissez les quantités relevées.');

        return $this->redirectToRoute('admin_inventaire_show', ['id' => $inventaire->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}', name: 'admin_inventaire_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        return $this->render('admin/inventaire/feuille.html.twig', [
            'inventaire' => $this->trouver($id),
        ]);
    }

    /**
     * Feuille imprimable, à emporter dans la réserve : les quantités théoriques
     * sont masquées, seules les cases à remplir restent. On ne compte pas
     * honnêtement avec la réponse sous les yeux.
     */
    #[Route('/{id}/feuille', name: 'admin_inventaire_feuille', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function feuille(int $id): Response
    {
        return $this->render('admin/inventaire/impression.html.twig', [
            'inventaire' => $this->trouver($id),
        ]);
    }

    #[Route('/{id}/saisir', name: 'admin_inventaire_saisir', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function saisir(Request $request, int $id): Response
    {
        $inventaire = $this->trouver($id);

        if (!$this->isCsrfTokenValid('inventaire_'.$id, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_inventaire_show', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        try {
            $this->service->saisir($inventaire, $this->lireComptages($request));
        } catch (\DomainException $e) {
            return $this->refuser($inventaire, $e->getMessage());
        }

        $this->addFlash('success', 'Comptage enregistré.');

        return $this->redirectToRoute('admin_inventaire_show', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/valider', name: 'admin_inventaire_valider', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function valider(Request $request, int $id): Response
    {
        $inventaire = $this->trouver($id);

        if (!$this->isCsrfTokenValid('inventaire_'.$id, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_inventaire_show', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        try {
            // La saisie en cours est enregistrée avant la validation : sinon un
            // dernier chiffre tapé puis « Valider » serait perdu en silence.
            $this->service->saisir($inventaire, $this->lireComptages($request));
            $this->service->valider(
                $inventaire,
                $this->utilisateur(),
                (string) $request->request->get('commentaire'),
            );
        } catch (\DomainException $e) {
            return $this->refuser($inventaire, $e->getMessage());
        }

        $this->addFlash('success', \sprintf(
            'Inventaire n° %d validé — %d écart(s) reporté(s) au stock.',
            $inventaire->getId(),
            \count($inventaire->lignesAvecEcart()),
        ));

        return $this->redirectToRoute('admin_inventaire_show', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/abandonner', name: 'admin_inventaire_abandonner', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function abandonner(Request $request, int $id): Response
    {
        $inventaire = $this->trouver($id);

        if (!$this->isCsrfTokenValid('inventaire_'.$id, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_inventaire_show', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        try {
            $this->service->abandonner($inventaire);
        } catch (\DomainException $e) {
            return $this->refuser($inventaire, $e->getMessage());
        }

        $this->addFlash('success', 'Feuille de comptage abandonnée : aucun stock n\'a été modifié.');

        return $this->redirectToRoute('admin_inventaires', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Quantités saisies, converties d'unités en **millièmes**.
     *
     * Une case laissée vide vaut `null` — « pas comptée » — et non zéro : c'est
     * toute la différence entre une ligne qu'on n'a pas eu le temps de relever et
     * un produit dont il ne reste rien.
     *
     * @return array<int, ?int>
     */
    private function lireComptages(Request $request): array
    {
        $comptages = [];

        foreach ($request->request->all('comptee') as $ligneId => $valeur) {
            $valeur = trim((string) $valeur);

            if ('' === $valeur) {
                $comptages[(int) $ligneId] = null;

                continue;
            }

            if (!is_numeric(str_replace(',', '.', $valeur))) {
                throw new \DomainException(\sprintf('« %s » n\'est pas une quantité.', $valeur));
            }

            $comptages[(int) $ligneId] = (int) round((float) str_replace(',', '.', $valeur) * 1000);
        }

        return $comptages;
    }

    /**
     * Réaffiche la feuille avec le message d'erreur, en **422** : Turbo ne
     * remplace pas la page sur un 200, l'utilisateur resterait devant un écran
     * figé sans savoir ce qui a échoué.
     */
    private function refuser(Inventaire $inventaire, string $message): Response
    {
        return $this->render('admin/inventaire/feuille.html.twig', [
            'inventaire' => $inventaire,
            'erreur' => $message,
        ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function trouver(int $id): Inventaire
    {
        return $this->inventaires->avecLignes($id)
            ?? throw $this->createNotFoundException('Inventaire introuvable.');
    }

    private function utilisateur(): \App\Entity\Utilisateur
    {
        $utilisateur = $this->getUser();
        \assert($utilisateur instanceof \App\Entity\Utilisateur);

        return $utilisateur;
    }
}
