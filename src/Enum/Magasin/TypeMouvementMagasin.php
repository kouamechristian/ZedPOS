<?php

namespace App\Enum\Magasin;

/**
 * Nature d'un mouvement du stock du magasin.
 *
 * - ENTREE_RECEPTION : quantités acceptées d'une réception, au stockage ;
 * - SORTIE : bon de sortie validé (atelier, cuisine, perte, retour fournisseur…) ;
 * - AJUSTEMENT_INVENTAIRE : écart constaté par un inventaire, en delta ;
 * - ANNULATION : contrepassation d'un document annulé — le mouvement d'origine
 *   reste, celui-ci l'annule, l'historique garde les deux.
 */
enum TypeMouvementMagasin: string
{
    case ENTREE_RECEPTION = 'ENTREE_RECEPTION';
    case SORTIE = 'SORTIE';
    case AJUSTEMENT_INVENTAIRE = 'AJUSTEMENT_INVENTAIRE';
    case ANNULATION = 'ANNULATION';

    public function libelle(): string
    {
        return match ($this) {
            self::ENTREE_RECEPTION => 'Entrée réception',
            self::SORTIE => 'Sortie',
            self::AJUSTEMENT_INVENTAIRE => 'Ajustement d\'inventaire',
            self::ANNULATION => 'Annulation',
        };
    }
}
