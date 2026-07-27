<?php

namespace App\Comptabilite;

/**
 * Comptes du **SYSCOHADA révisé** (Système Comptable OHADA, applicable en Côte
 * d'Ivoire) utilisés par les exports de ZedPOS.
 *
 * C'est le **catalogue de référence unique** : aucun code de compte ne doit être
 * écrit en dur ailleurs dans l'application. Un cabinet comptable qui tient un
 * plan légèrement différent ajuste ici, ou — pour les comptes de vente — famille
 * par famille depuis le back-office (`FamilleProduit::$compteVente`).
 *
 * Les codes sont des chaînes, jamais des entiers : un compte comptable est un
 * identifiant, pas une quantité (et « 605 » ne se compare pas à « 6056 »).
 */
enum PlanComptable: string
{
    // --- Classe 7 : produits ------------------------------------------------

    /** Articles revendus en l'état (boissons, produits achetés puis revendus). */
    case VENTES_MARCHANDISES = '7011';

    /** Articles fabriqués sur place (pain, viennoiseries, pâtisseries). */
    case VENTES_PRODUITS_FINIS = '7021';

    /** Prestations facturées. Disponible en surcharge de famille. */
    case VENTES_SERVICES = '7061';

    /** Rabais, remises et ristournes accordés par l'entreprise (compte débiteur). */
    case RRR_ACCORDES = '7019';

    /** Excédent de caisse constaté à la clôture Z. */
    case PRODUITS_DIVERS = '7588';

    /** Variation des stocks de produits finis (contrepartie d'une perte). */
    case VARIATION_STOCKS_PRODUITS_FINIS = '736';

    // --- Classe 6 : charges -------------------------------------------------

    /** Approvisionnement réglé en espèces depuis le tiroir. */
    case ACHATS_MATIERES = '6021';

    /** Eau et électricité (605 « Autres achats », non stockables). */
    case EAU_ELECTRICITE = '605';

    /** Achats de petit matériel et outillage. */
    case PETIT_EQUIPEMENT = '6056';

    /** Transports sur ventes (livraison). */
    case TRANSPORTS = '612';

    /** Entretien, réparations et maintenance. */
    case ENTRETIEN = '624';

    /** Charges diverses : dépenses non catégorisées, manquant de caisse. */
    case CHARGES_DIVERSES = '6588';

    /** Variation des stocks de marchandises (contrepartie d'une perte). */
    case VARIATION_STOCKS_MARCHANDISES = '6031';

    /** Variation des stocks de matières premières (contrepartie d'une perte). */
    case VARIATION_STOCKS_MATIERES = '6032';

    // --- Classe 4 : tiers ---------------------------------------------------

    /** État, TVA facturée sur ventes. */
    case TVA_FACTUREE = '4431';

    /** Clients (vente réglée à crédit). */
    case CLIENTS = '4111';

    /** Personnel, avances et acomptes — une créance, pas une charge. */
    case AVANCES_PERSONNEL = '4211';

    // --- Classe 5 : trésorerie ----------------------------------------------

    /** Caisse en unité monétaire légale : le tiroir-caisse. */
    case CAISSE = '5711';

    /** 55 « Instruments de monnaie électronique » — un sous-compte par opérateur. */
    case MONNAIE_ELECTRONIQUE_WAVE = '5521';
    case MONNAIE_ELECTRONIQUE_ORANGE = '5522';
    case MONNAIE_ELECTRONIQUE_MTN = '5523';
    case MONNAIE_ELECTRONIQUE_MOOV = '5524';

    /** Virements de fonds : sortie du tiroir vers le coffre ou la banque. */
    case VIREMENTS_INTERNES = '585';

    // --- Classe 3 : stocks --------------------------------------------------

    case STOCK_MARCHANDISES = '311';
    case STOCK_MATIERES = '321';
    case STOCK_PRODUITS_FINIS = '361';

    /** Intitulé normalisé du compte, repris tel quel dans les fichiers exportés. */
    public function libelle(): string
    {
        return match ($this) {
            self::VENTES_MARCHANDISES => 'Ventes de marchandises',
            self::VENTES_PRODUITS_FINIS => 'Ventes de produits finis',
            self::VENTES_SERVICES => 'Services vendus',
            self::RRR_ACCORDES => 'Rabais, remises et ristournes accordés',
            self::PRODUITS_DIVERS => 'Autres produits divers',
            self::VARIATION_STOCKS_PRODUITS_FINIS => 'Variation des stocks de produits finis',
            self::ACHATS_MATIERES => 'Achats de matières premières et fournitures liées',
            self::EAU_ELECTRICITE => 'Autres achats — eau et électricité',
            self::PETIT_EQUIPEMENT => 'Achats de petit matériel et outillage',
            self::TRANSPORTS => 'Transports sur ventes',
            self::ENTRETIEN => 'Entretien, réparations et maintenance',
            self::CHARGES_DIVERSES => 'Autres charges diverses',
            self::VARIATION_STOCKS_MARCHANDISES => 'Variation des stocks de marchandises',
            self::VARIATION_STOCKS_MATIERES => 'Variation des stocks de matières premières',
            self::TVA_FACTUREE => 'État, TVA facturée sur ventes',
            self::CLIENTS => 'Clients',
            self::AVANCES_PERSONNEL => 'Personnel, avances et acomptes',
            self::CAISSE => 'Caisse',
            self::MONNAIE_ELECTRONIQUE_WAVE => 'Monnaie électronique — Wave',
            self::MONNAIE_ELECTRONIQUE_ORANGE => 'Monnaie électronique — Orange Money',
            self::MONNAIE_ELECTRONIQUE_MTN => 'Monnaie électronique — MTN MoMo',
            self::MONNAIE_ELECTRONIQUE_MOOV => 'Monnaie électronique — Moov Money',
            self::VIREMENTS_INTERNES => 'Virements de fonds',
            self::STOCK_MARCHANDISES => 'Marchandises',
            self::STOCK_MATIERES => 'Matières premières et fournitures liées',
            self::STOCK_PRODUITS_FINIS => 'Produits finis',
        };
    }

    /**
     * Comptes proposés en surcharge de famille dans le back-office : seuls les
     * comptes de produits ont un sens pour ventiler un chiffre d'affaires.
     *
     * @return list<self>
     */
    public static function comptesDeVente(): array
    {
        return [self::VENTES_MARCHANDISES, self::VENTES_PRODUITS_FINIS, self::VENTES_SERVICES];
    }

    /**
     * Libellé d'un code de compte quelconque — y compris un code saisi à la main
     * dans une famille et absent du catalogue, auquel cas le code fait office
     * d'intitulé plutôt que de laisser une colonne vide dans le fichier exporté.
     */
    public static function libellePour(string $compte): string
    {
        return self::tryFrom($compte)?->libelle() ?? $compte;
    }
}
