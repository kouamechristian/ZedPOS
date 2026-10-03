<?php

namespace App\Controller\Magasin;

use App\Enum\Magasin\CategorieProduitMagasin;
use App\Repository\Magasin\MagasinProduitRepository;
use App\Repository\Magasin\MagasinStockRepository;
use App\Security\Permission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le stock actuel du magasin : par produit, détail par emplacement, produits
 * sous leur seuil d'alerte mis en évidence.
 *
 * Lecture seule. Totaux et détail chargés en deux requêtes pour toute la page —
 * jamais une requête par ligne. La valeur (CMP) n'apparaît qu'avec
 * `MAGASIN_VOIR_PRIX`.
 */
#[Route('/magasin/stock')]
#[IsGranted(Permission::MAGASIN_VOIR)]
class StockController extends AbstractController
{
    #[Route('', name: 'magasin_stock_index', methods: ['GET'])]
    public function index(Request $request, MagasinProduitRepository $produits, MagasinStockRepository $stocks): Response
    {
        $categorie = CategorieProduitMagasin::tryFrom((string) $request->query->get('categorie'));
        $page = $produits->pagines($request->query->getInt('page', 1), $request->query->get('q'), $categorie);

        $ids = array_map(static fn ($p): int => (int) $p->getId(), $page->items);

        return $this->render('magasin/stock/index.html.twig', [
            'produits' => $page,
            'totaux' => $stocks->totauxParProduit($ids),
            'detail' => $stocks->detailParProduit($ids),
            'categorie' => $categorie,
            'categories' => CategorieProduitMagasin::cases(),
        ]);
    }
}
