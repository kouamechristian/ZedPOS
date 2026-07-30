<?php

namespace App\Twig;

use App\Service\ParametresBoutique;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Identité de l'établissement dans les gabarits : `nom_boutique()` et
 * `logo_boutique()`.
 *
 * Ni l'un ni l'autre ne prend d'argument — il n'y a qu'un établissement, et le
 * gabarit n'a pas à savoir que ces valeurs viennent de la table `parametre`.
 * C'est ce qui permet d'afficher le nom de la boulangerie en tête du back-office et
 * du pilotage **sans le coder en dur** : renommer l'enseigne dans
 * `/admin/parametres` renomme tous les écrans, et une deuxième boutique n'obligerait
 * pas à repasser dans les gabarits.
 *
 * Les valeurs sont mémorisées pour la durée de la requête par
 * {@see ParametresBoutique} — les afficher dans un en-tête présent sur chaque écran
 * ne coûte donc pas une requête SQL de plus.
 */
class BoutiqueExtension extends AbstractExtension
{
    public function __construct(private readonly ParametresBoutique $parametres)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('nom_boutique', $this->parametres->nom(...)),
            new TwigFunction('logo_boutique', $this->parametres->cheminLogo(...)),
        ];
    }
}
