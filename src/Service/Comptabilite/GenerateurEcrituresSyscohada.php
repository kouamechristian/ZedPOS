<?php

namespace App\Service\Comptabilite;

use App\Comptabilite\Controle;
use App\Comptabilite\EcritureComptable;
use App\Comptabilite\JeuEcritures;
use App\Comptabilite\JournalComptable;
use App\Comptabilite\LigneEcriture;
use App\Comptabilite\PlanComptable;
use App\Enum\CategorieDepense;
use App\Enum\ModeReglement;
use App\Enum\TypeMouvementCaisse;
use Doctrine\DBAL\Connection;

/**
 * Traduit l'activité d'une période en écritures comptables SYSCOHADA.
 *
 * **Centralisation par rapport Z.** Le journal des ventes ne reprend pas les
 * tickets un par un — un mois de boulangerie en compte plusieurs milliers, aucun
 * cabinet ne les saisirait. Il produit **une écriture par session de caisse et
 * par journée**, dont la pièce justificative est le rapport Z correspondant :
 * c'est le document papier qui reste au classeur, et celui que le comptable
 * demandera en cas de contrôle.
 *
 * **Ce sur quoi les montants s'appuient.** Les totaux d'une écriture sont pris
 * dans les colonnes de la vente (`total_ttc`, `total_tva`, `total_ht`, `remise`),
 * qui font foi. Les lignes de vente ne servent qu'à **ventiler** ce chiffre
 * d'affaires entre les comptes de produits. Un taux de TVA modifié sur un article
 * après coup peut donc décaler la ventilation, jamais les totaux — et l'écriture
 * reste équilibrée dans tous les cas.
 *
 * **Ventes annulées exclues.** Elles ne sont ni un produit ni un encaissement ;
 * elles sont comptées à part et rappelées à l'écran, comme partout ailleurs dans
 * l'application.
 */
class GenerateurEcrituresSyscohada
{
    public function __construct(private readonly Connection $connexion)
    {
    }

    public function construire(\DateTimeImmutable $du, \DateTimeImmutable $au): JeuEcritures
    {
        $du = $du->setTime(0, 0);
        $au = $au->setTime(0, 0);

        if ($au < $du) {
            [$du, $au] = [$au, $du];
        }

        $bornes = [$du->format('Y-m-d H:i:s'), $au->modify('+1 day')->format('Y-m-d H:i:s')];

        $ecritures = [
            ...$this->journalDesVentes($bornes),
            ...$this->journalDeCaisse($bornes),
            ...$this->operationsDiverses($bornes),
        ];

        return new JeuEcritures($du, $au, $ecritures, $this->controles($bornes, $ecritures));
    }

    // -- Journal des ventes --------------------------------------------------

    /**
     * @param array{0: string, 1: string} $bornes
     *
     * @return list<EcritureComptable>
     */
    private function journalDesVentes(array $bornes): array
    {
        $ventilation = $this->ventilationParCompte($bornes);
        $reglements = $this->reglementsParMode($bornes);

        $ecritures = [];

        foreach ($this->ventesParSessionEtJour($bornes) as $cle => $groupe) {
            $lignes = [];

            // --- Encaissements (débit) : un compte de trésorerie par moyen de
            // paiement. Le rendu de monnaie sort du tiroir, il vient donc en
            // diminution des espèces — même convention que le rapport Z.
            foreach ($reglements[$cle] ?? [] as $mode => $montant) {
                $mode = ModeReglement::from($mode);
                if (ModeReglement::ESPECES === $mode) {
                    $montant -= $groupe['rendu'];
                }
                if (0 !== $montant) {
                    $lignes[] = LigneEcriture::signee($this->compteTresorerie($mode), $montant);
                }
            }

            // --- Remise accordée (débit) : un rabais diminue le produit, il ne
            // s'impute pas en charge. Seule sa part hors taxes passe en 7019,
            // la part de TVA étant déjà déduite de la TVA collectée ci-dessous.
            $brutTva = $ventilation[$cle]['tva'] ?? 0;
            $remiseTva = max(0, min($groupe['remise'], $brutTva - $groupe['tva']));
            $remiseHt = $groupe['remise'] - $remiseTva;

            if ($remiseHt > 0) {
                $lignes[] = LigneEcriture::debit(PlanComptable::RRR_ACCORDES, $remiseHt);
            }

            // --- Produits (crédit) : le chiffre d'affaires hors taxes, remise
            // réintégrée puisqu'elle est portée séparément au débit. La somme des
            // parts vaut exactement ce total, ce qui équilibre l'écriture :
            //   encaissé (netTTC) + remise HT  =  produits HT + TVA collectée
            $produitsHt = $groupe['ht'] + $remiseHt;

            foreach ($this->repartir($ventilation[$cle]['comptes'] ?? [], $produitsHt) as $compte => $montant) {
                if (0 !== $montant) {
                    $lignes[] = LigneEcriture::signee($compte, -$montant);
                }
            }

            if ($groupe['tva'] > 0) {
                $lignes[] = LigneEcriture::credit(PlanComptable::TVA_FACTUREE, $groupe['tva']);
            }

            if ([] === $lignes) {
                continue;
            }

            $ecritures[] = new EcritureComptable(
                journal: JournalComptable::VENTES,
                date: $groupe['jour'],
                piece: 'Z'.$groupe['sessionId'],
                libelle: \sprintf(
                    'Ventes du %s — %s (%d ticket%s)',
                    $groupe['jour']->format('d/m/Y'),
                    $groupe['caissier'],
                    $groupe['tickets'],
                    $groupe['tickets'] > 1 ? 's' : '',
                ),
                lignes: $lignes,
            );
        }

        return $ecritures;
    }

    /**
     * Totaux par session de caisse et par journée, pris dans les colonnes de la
     * vente : ce sont elles qui font foi.
     *
     * @param array{0: string, 1: string} $bornes
     *
     * @return array<string, array{sessionId: int, jour: \DateTimeImmutable, caissier: string, tickets: int, ttc: int, tva: int, ht: int, remise: int, rendu: int}>
     */
    private function ventesParSessionEtJour(array $bornes): array
    {
        $groupes = [];

        foreach ($this->connexion->fetchAllAssociative(
            "SELECT v.session_caisse_id AS session_id,
                    DATE(v.created_at) AS jour,
                    u.nom AS caissier,
                    COUNT(*) AS tickets,
                    COALESCE(SUM(v.total_ttc), 0) AS ttc,
                    COALESCE(SUM(v.total_tva), 0) AS tva,
                    COALESCE(SUM(v.total_ht), 0) AS ht,
                    COALESCE(SUM(v.remise), 0) AS remise,
                    COALESCE(SUM(v.rendu), 0) AS rendu
             FROM vente v
             JOIN session_caisse s ON s.id = v.session_caisse_id
             JOIN utilisateur u ON u.id = s.utilisateur_id
             WHERE v.statut = 'VALIDEE' AND v.created_at >= ? AND v.created_at < ?
             GROUP BY v.session_caisse_id, DATE(v.created_at), u.nom
             ORDER BY DATE(v.created_at), v.session_caisse_id",
            $bornes,
        ) as $ligne) {
            $groupes[$this->cle($ligne)] = [
                'sessionId' => (int) $ligne['session_id'],
                'jour' => new \DateTimeImmutable((string) $ligne['jour']),
                'caissier' => (string) $ligne['caissier'],
                'tickets' => (int) $ligne['tickets'],
                'ttc' => (int) $ligne['ttc'],
                'tva' => (int) $ligne['tva'],
                'ht' => (int) $ligne['ht'],
                'remise' => (int) $ligne['remise'],
                'rendu' => (int) $ligne['rendu'],
            ];
        }

        return $groupes;
    }

    /**
     * Ventilation du chiffre d'affaires entre comptes de produits, calculée
     * ligne à ligne.
     *
     * Le compte retenu est celui de la famille lorsqu'il est renseigné ; à défaut
     * la nature de l'article tranche : un article doté d'une fiche technique est
     * **fabriqué** sur place (produit fini), les autres sont **revendus en l'état**
     * (marchandises).
     *
     * @param array{0: string, 1: string} $bornes
     *
     * @return array<string, array{comptes: array<string, int>, tva: int}>
     */
    private function ventilationParCompte(array $bornes): array
    {
        $ventilation = [];

        foreach ($this->connexion->fetchAllAssociative(
            "SELECT v.session_caisse_id AS session_id,
                    DATE(v.created_at) AS jour,
                    COALESCE(NULLIF(f.compte_vente, ''), CASE WHEN ft.id IS NULL THEN ? ELSE ? END) AS compte,
                    COALESCE(SUM(
                        ((lv.quantite * lv.prix_unitaire + 500) DIV 1000 - lv.remise) * 10000
                        DIV (10000 + a.taux_tva)
                    ), 0) AS ht,
                    COALESCE(SUM(
                        ((lv.quantite * lv.prix_unitaire + 500) DIV 1000 - lv.remise)
                        - ((lv.quantite * lv.prix_unitaire + 500) DIV 1000 - lv.remise) * 10000
                          DIV (10000 + a.taux_tva)
                    ), 0) AS tva
             FROM ligne_vente lv
             JOIN vente v ON v.id = lv.vente_id
             JOIN article a ON a.id = lv.article_id
             LEFT JOIN famille_produit f ON f.id = a.famille_produit_id
             LEFT JOIN fiche_technique ft ON ft.article_id = a.id
             WHERE v.statut = 'VALIDEE' AND v.created_at >= ? AND v.created_at < ?
             GROUP BY v.session_caisse_id, DATE(v.created_at), compte",
            [
                PlanComptable::VENTES_MARCHANDISES->value,
                PlanComptable::VENTES_PRODUITS_FINIS->value,
                ...$bornes,
            ],
        ) as $ligne) {
            $cle = $this->cle($ligne);
            $ventilation[$cle] ??= ['comptes' => [], 'tva' => 0];
            $ventilation[$cle]['comptes'][(string) $ligne['compte']] = (int) $ligne['ht'];
            $ventilation[$cle]['tva'] += (int) $ligne['tva'];
        }

        return $ventilation;
    }

    /**
     * @param array{0: string, 1: string} $bornes
     *
     * @return array<string, array<string, int>> clé de groupe => [mode => montant]
     */
    private function reglementsParMode(array $bornes): array
    {
        $reglements = [];

        foreach ($this->connexion->fetchAllAssociative(
            "SELECT v.session_caisse_id AS session_id,
                    DATE(v.created_at) AS jour,
                    r.mode,
                    COALESCE(SUM(r.montant), 0) AS montant
             FROM reglement r
             JOIN vente v ON v.id = r.vente_id
             WHERE v.statut = 'VALIDEE' AND v.created_at >= ? AND v.created_at < ?
             GROUP BY v.session_caisse_id, DATE(v.created_at), r.mode",
            $bornes,
        ) as $ligne) {
            $reglements[$this->cle($ligne)][(string) $ligne['mode']] = (int) $ligne['montant'];
        }

        return $reglements;
    }

    // -- Journal de caisse ---------------------------------------------------

    /**
     * Dépenses réglées en espèces, sorties de fonds et écarts constatés au Z.
     *
     * @param array{0: string, 1: string} $bornes
     *
     * @return list<EcritureComptable>
     */
    private function journalDeCaisse(array $bornes): array
    {
        $ecritures = [];

        foreach ($this->connexion->fetchAllAssociative(
            'SELECT m.id, m.type, m.categorie, m.montant, m.commentaire, m.created_at
             FROM mouvement_caisse m
             WHERE m.created_at >= ? AND m.created_at < ?
             ORDER BY m.created_at, m.id',
            $bornes,
        ) as $mouvement) {
            $type = TypeMouvementCaisse::from((string) $mouvement['type']);
            $categorie = CategorieDepense::tryFrom((string) $mouvement['categorie']);
            $montant = (int) $mouvement['montant'];

            $contrepartie = TypeMouvementCaisse::SORTIE === $type
                ? PlanComptable::VIREMENTS_INTERNES
                : $this->compteDepense($categorie);

            $libelle = TypeMouvementCaisse::SORTIE === $type
                ? 'Sortie de caisse'
                : ($categorie?->libelle() ?? 'Dépense de caisse');
            $commentaire = trim((string) ($mouvement['commentaire'] ?? ''));

            $ecritures[] = new EcritureComptable(
                journal: JournalComptable::CAISSE,
                date: new \DateTimeImmutable((string) $mouvement['created_at']),
                piece: 'CA'.$mouvement['id'],
                libelle: '' !== $commentaire ? $libelle.' — '.$commentaire : $libelle,
                lignes: [
                    LigneEcriture::debit($contrepartie, $montant),
                    LigneEcriture::credit(PlanComptable::CAISSE, $montant),
                ],
            );
        }

        foreach ($this->connexion->fetchAllAssociative(
            "SELECT s.id, s.ecart, s.cloture_at, s.commentaire_cloture, u.nom AS caissier
             FROM session_caisse s
             JOIN utilisateur u ON u.id = s.utilisateur_id
             WHERE s.statut = 'CLOTUREE' AND s.ecart <> 0
               AND s.cloture_at >= ? AND s.cloture_at < ?
             ORDER BY s.cloture_at, s.id",
            $bornes,
        ) as $session) {
            $ecart = (int) $session['ecart'];
            $commentaire = trim((string) ($session['commentaire_cloture'] ?? ''));

            // Manquant : une charge. Excédent : un produit. Dans les deux cas la
            // contrepartie ajuste le tiroir sur ce qui y a réellement été compté.
            $lignes = $ecart < 0
                ? [
                    LigneEcriture::debit(PlanComptable::CHARGES_DIVERSES, -$ecart),
                    LigneEcriture::credit(PlanComptable::CAISSE, -$ecart),
                ]
                : [
                    LigneEcriture::debit(PlanComptable::CAISSE, $ecart),
                    LigneEcriture::credit(PlanComptable::PRODUITS_DIVERS, $ecart),
                ];

            $ecritures[] = new EcritureComptable(
                journal: JournalComptable::CAISSE,
                date: new \DateTimeImmutable((string) $session['cloture_at']),
                piece: 'Z'.$session['id'],
                libelle: \sprintf(
                    'Écart de caisse (%s) — %s%s',
                    $ecart < 0 ? 'manquant' : 'excédent',
                    $session['caissier'],
                    '' !== $commentaire ? ' — '.$commentaire : '',
                ),
                lignes: $lignes,
            );
        }

        return $ecritures;
    }

    // -- Opérations diverses -------------------------------------------------

    /**
     * Pertes de stock valorisées : la marchandise est sortie du stock sans avoir
     * été vendue, la contrepartie est donc le compte de variation de stocks
     * correspondant à sa nature.
     *
     * @param array{0: string, 1: string} $bornes
     *
     * @return list<EcritureComptable>
     */
    private function operationsDiverses(array $bornes): array
    {
        $ecritures = [];

        foreach ($this->connexion->fetchAllAssociative(
            'SELECT p.id, p.motif, p.valorisation, p.created_at,
                    m.nom AS matiere, a.nom AS article, ft.id AS fiche
             FROM perte p
             LEFT JOIN matiere_premiere m ON m.id = p.matiere_premiere_id
             LEFT JOIN article a ON a.id = p.article_id
             LEFT JOIN fiche_technique ft ON ft.article_id = a.id
             WHERE p.valorisation > 0 AND p.created_at >= ? AND p.created_at < ?
             ORDER BY p.created_at, p.id',
            $bornes,
        ) as $perte) {
            $montant = (int) $perte['valorisation'];

            [$charge, $stock, $designation] = match (true) {
                null !== $perte['matiere'] => [
                    PlanComptable::VARIATION_STOCKS_MATIERES,
                    PlanComptable::STOCK_MATIERES,
                    (string) $perte['matiere'],
                ],
                null !== $perte['fiche'] => [
                    PlanComptable::VARIATION_STOCKS_PRODUITS_FINIS,
                    PlanComptable::STOCK_PRODUITS_FINIS,
                    (string) $perte['article'],
                ],
                default => [
                    PlanComptable::VARIATION_STOCKS_MARCHANDISES,
                    PlanComptable::STOCK_MARCHANDISES,
                    (string) ($perte['article'] ?? 'Article'),
                ],
            };

            $ecritures[] = new EcritureComptable(
                journal: JournalComptable::OPERATIONS_DIVERSES,
                date: new \DateTimeImmutable((string) $perte['created_at']),
                piece: 'PE'.$perte['id'],
                libelle: \sprintf('Perte %s — %s', strtolower(str_replace('_', ' ', (string) $perte['motif'])), $designation),
                lignes: [
                    LigneEcriture::debit($charge, $montant),
                    LigneEcriture::credit($stock, $montant),
                ],
            );
        }

        return $ecritures;
    }

    // -- Contrôles -----------------------------------------------------------

    /**
     * Rapprochements entre les chiffres de l'application et les écritures.
     *
     * Ils passent par construction : c'est précisément l'intérêt. Un contrôle qui
     * casse signale une régression du générateur, et le comptable a sous les yeux
     * la vérification qu'il aurait faite lui-même à la réception du fichier.
     *
     * @param array{0: string, 1: string} $bornes
     * @param list<EcritureComptable>     $ecritures
     *
     * @return list<Controle>
     */
    private function controles(array $bornes, array $ecritures): array
    {
        $ventes = $this->connexion->fetchAssociative(
            "SELECT COALESCE(SUM(total_ttc), 0) AS ttc, COALESCE(SUM(total_tva), 0) AS tva
             FROM vente WHERE statut = 'VALIDEE' AND created_at >= ? AND created_at < ?",
            $bornes,
        ) ?: [];

        $especes = (int) $this->connexion->fetchOne(
            "SELECT COALESCE(SUM(r.montant), 0) - COALESCE((
                        SELECT SUM(v2.rendu) FROM vente v2
                        WHERE v2.statut = 'VALIDEE' AND v2.created_at >= ? AND v2.created_at < ?
                    ), 0)
             FROM reglement r
             JOIN vente v ON v.id = r.vente_id
             WHERE v.statut = 'VALIDEE' AND r.mode = 'ESPECES'
               AND v.created_at >= ? AND v.created_at < ?",
            [...$bornes, ...$bornes],
        );

        $mouvements = (int) $this->connexion->fetchOne(
            'SELECT COALESCE(SUM(montant), 0) FROM mouvement_caisse WHERE created_at >= ? AND created_at < ?',
            $bornes,
        );

        $ecarts = (int) $this->connexion->fetchOne(
            "SELECT COALESCE(SUM(ecart), 0) FROM session_caisse
             WHERE statut = 'CLOTUREE' AND cloture_at >= ? AND cloture_at < ?",
            $bornes,
        );

        $caisse = PlanComptable::CAISSE->value;

        return [
            new Controle(
                'Chiffre d\'affaires TTC encaissé',
                (int) ($ventes['ttc'] ?? 0),
                $this->cumul($ecritures, JournalComptable::VENTES, $this->comptesDeTresorerie(), 'debit'),
                'Le total des encaissements du journal des ventes doit égaler le chiffre d\'affaires de la période.',
            ),
            new Controle(
                'TVA collectée',
                (int) ($ventes['tva'] ?? 0),
                $this->cumul($ecritures, JournalComptable::VENTES, [PlanComptable::TVA_FACTUREE->value], 'credit'),
                'La TVA portée au compte 4431 doit égaler la TVA des tickets.',
            ),
            new Controle(
                'Espèces encaissées (rendu de monnaie déduit)',
                $especes,
                $this->cumul($ecritures, JournalComptable::VENTES, [$caisse], 'debit'),
                'Les entrées de tiroir doivent égaler les règlements en espèces, nets du rendu.',
            ),
            new Controle(
                'Dépenses et sorties de caisse',
                $mouvements,
                // Pièces « CA » seulement : les écritures d'écart créditent aussi
                // le tiroir, sans être des sorties d'espèces.
                $this->cumul($ecritures, JournalComptable::CAISSE, [$caisse], 'credit', 'CA'),
                'Les sorties du tiroir doivent égaler les mouvements de caisse saisis.',
            ),
            new Controle(
                'Écarts de caisse constatés au Z',
                $ecarts,
                $this->cumul($ecritures, JournalComptable::CAISSE, [$caisse], 'debit', 'Z')
                    - $this->cumul($ecritures, JournalComptable::CAISSE, [$caisse], 'credit', 'Z'),
                'L\'ajustement du tiroir doit égaler la somme des écarts des clôtures de la période.',
            ),
        ];
    }

    /**
     * Cumul d'un sens sur une sélection de comptes, éventuellement restreint aux
     * pièces d'un préfixe donné.
     *
     * @param list<EcritureComptable> $ecritures
     * @param list<string>            $comptes
     * @param 'debit'|'credit'        $sens
     */
    private function cumul(
        array $ecritures,
        JournalComptable $journal,
        array $comptes,
        string $sens,
        ?string $prefixePiece = null,
    ): int {
        $total = 0;

        foreach ($ecritures as $ecriture) {
            if ($ecriture->journal !== $journal) {
                continue;
            }
            if (null !== $prefixePiece && !str_starts_with($ecriture->piece, $prefixePiece)) {
                continue;
            }
            foreach ($ecriture->lignes as $ligne) {
                if (\in_array($ligne->compte, $comptes, true)) {
                    $total += 'debit' === $sens ? $ligne->debit : $ligne->credit;
                }
            }
        }

        return $total;
    }

    // -- Correspondances -----------------------------------------------------

    private function compteTresorerie(ModeReglement $mode): PlanComptable
    {
        return match ($mode) {
            ModeReglement::ESPECES => PlanComptable::CAISSE,
            ModeReglement::WAVE => PlanComptable::MONNAIE_ELECTRONIQUE_WAVE,
            ModeReglement::ORANGE_MONEY => PlanComptable::MONNAIE_ELECTRONIQUE_ORANGE,
            ModeReglement::MTN_MOMO => PlanComptable::MONNAIE_ELECTRONIQUE_MTN,
            ModeReglement::MOOV_MONEY => PlanComptable::MONNAIE_ELECTRONIQUE_MOOV,
            // Une vente à crédit n'encaisse rien : elle ouvre une créance client.
            ModeReglement::CREDIT => PlanComptable::CLIENTS,
        };
    }

    /** @return list<string> */
    private function comptesDeTresorerie(): array
    {
        return array_map(
            fn (ModeReglement $mode): string => $this->compteTresorerie($mode)->value,
            ModeReglement::cases(),
        );
    }

    private function compteDepense(?CategorieDepense $categorie): PlanComptable
    {
        return match ($categorie) {
            CategorieDepense::APPROVISIONNEMENT => PlanComptable::ACHATS_MATIERES,
            CategorieDepense::TRANSPORT => PlanComptable::TRANSPORTS,
            CategorieDepense::ENTRETIEN => PlanComptable::ENTRETIEN,
            CategorieDepense::ELECTRICITE_EAU => PlanComptable::EAU_ELECTRICITE,
            CategorieDepense::PETIT_EQUIPEMENT => PlanComptable::PETIT_EQUIPEMENT,
            // Une avance au personnel n'est pas une charge : c'est une créance
            // sur le salarié, apurée à la paie.
            CategorieDepense::AVANCE_PERSONNEL => PlanComptable::AVANCES_PERSONNEL,
            CategorieDepense::DIVERS, null => PlanComptable::CHARGES_DIVERSES,
        };
    }

    // -- Utilitaires ---------------------------------------------------------

    /**
     * Répartit un total entre des comptes au prorata de montants calculés, en
     * arithmétique entière. Le reste de la division est attribué au plus gros
     * compte : la somme des parts égale donc **exactement** le total, sans quoi
     * l'écriture serait déséquilibrée d'un centime.
     *
     * @param array<string, int> $poids compte => montant calculé
     *
     * @return array<string, int> compte => montant réparti
     */
    private function repartir(array $poids, int $total): array
    {
        $base = array_sum($poids);

        if ([] === $poids) {
            return 0 === $total ? [] : [PlanComptable::VENTES_MARCHANDISES->value => $total];
        }

        if (0 === $base) {
            // Tout à zéro (articles à prix nul) : rien à répartir au prorata,
            // le total éventuel va sur le premier compte rencontré.
            $premier = array_key_first($poids);
            $parts = array_fill_keys(array_keys($poids), 0);
            $parts[$premier] = $total;

            return $parts;
        }

        $parts = [];
        foreach ($poids as $compte => $montant) {
            $parts[$compte] = intdiv($montant * $total, $base);
        }

        $reste = $total - array_sum($parts);
        if (0 !== $reste) {
            $plusGros = array_search(max($poids), $poids, true);
            $parts[$plusGros] += $reste;
        }

        return $parts;
    }

    /** @param array<string, mixed> $ligne */
    private function cle(array $ligne): string
    {
        return $ligne['session_id'].'|'.$ligne['jour'];
    }
}
