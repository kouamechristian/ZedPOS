<?php

namespace App\Twig;

use App\Service\ImageArticle;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `image_article(nom)` — chemin public d'une image de touche produit.
 *
 * Le gabarit ne connaît que le nom de fichier stocké en base ; le chemin se
 * compose dans {@see ImageArticle}, seul endroit à savoir où les images vivent.
 * Sans cette fonction, `/uploads/articles/` serait recopié dans chaque gabarit
 * et déplacer le répertoire deviendrait une chasse au préfixe.
 */
class ImageArticleExtension extends AbstractExtension
{
    public function __construct(private readonly ImageArticle $images)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('image_article', $this->images->chemin(...)),
        ];
    }
}
