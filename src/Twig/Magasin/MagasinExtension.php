<?php

namespace App\Twig\Magasin;

use App\Service\Magasin\AlertesStockMagasin;
use App\Service\Magasin\QuantiteMagasin;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `magasin_quantite(produit, millimes)` — « 20 sacs (1 000 kg) » ;
 * `magasin_unite(millimes, unite)` — « 3 sacs », pour une quantité saisie dans une unité choisie ;
 * `magasin_alertes()` — les produits au seuil d'alerte, pour la bannière et le
 * compteur du menu. Le gabarit vérifie `MAGASIN_VOIR` avant de l'appeler.
 *
 * La conversion vit dans le produit, la mise en forme dans {@see QuantiteMagasin} :
 * le gabarit n'a ni division ni pluriel à faire.
 */
class MagasinExtension extends AbstractExtension
{
    public function __construct(private readonly AlertesStockMagasin $alertes)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('magasin_quantite', QuantiteMagasin::formater(...)),
            new TwigFunction('magasin_unite', QuantiteMagasin::avecUnite(...)),
            new TwigFunction('magasin_alertes', $this->alertes->produitsAuSeuil(...)),
        ];
    }
}
