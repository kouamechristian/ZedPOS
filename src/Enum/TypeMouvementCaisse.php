<?php

namespace App\Enum;

/**
 * Nature d'un mouvement d'espèces sortant du tiroir, hors ventes.
 *
 * Les deux diminuent le fond théorique, mais ne se lisent pas de la même façon :
 * une DEPENSE est une charge de l'établissement, une SORTIE est un simple
 * déplacement d'espèces (remise au coffre, dépôt en banque).
 */
enum TypeMouvementCaisse: string
{
    case DEPENSE = 'DEPENSE';
    case SORTIE = 'SORTIE';

    public function libelle(): string
    {
        return match ($this) {
            self::DEPENSE => 'Dépense',
            self::SORTIE => 'Sortie de caisse',
        };
    }
}
