<?php

namespace App\Service\Point;

use App\Enum\ModeRemuneration;

/**
 * Calcul du point d'un stand sur une période. **Calcul pur** : aucune base, aucune
 * persistance, les données lui sont données.
 *
 * Par produit :
 *
 *     qteConfiee   = somme des lignes de dotation de la période
 *     qteRetournee = retours INVENDU
 *     qtePerdue    = casse, périmé, autre
 *     qteVendue    = qteConfiee − qteRetournee − qtePerdue
 *
 * puis en total :
 *
 *     montantAttendu = somme(qteVendue × prixVente figé de la ligne)
 *     remuneration   = taux × montantAttendu                      (COMMISSION)
 *                    | somme(qteVendue × (prixVente − prixCession)) (MARGE)
 *     netARemettre   = montantAttendu − remuneration
 *     ecart          = montantRemis − netARemettre
 *
 * **Prix figés.** Chaque ligne de dotation garde le prix copié à la validation de
 * son bon ; rien n'est relu sur le produit. Quand un même produit a été doté à
 * plusieurs prix, le vendu est imputé **aux dotations les plus anciennes
 * d'abord** : invendus et pertes sont ceux des dotations les plus récentes. C'est
 * ce que fait la marchandise — le vendeur écoule d'abord ce qu'il a depuis le plus
 * longtemps.
 *
 * **Arrondi.** Les prix sont en francs entiers : montants et marges tombent juste.
 * Seule la commission produit des fractions de franc ; elle est arrondie **au
 * franc le plus proche** (demi-franc vers le haut), et calculée sur le montant
 * total — additionner des commissions déjà arrondies ligne à ligne décalerait le
 * total de quelques francs à chaque point.
 */
final class PointCalculator
{
    /**
     * @param list<DotationPoint> $dotations    lignes des bons VALIDE de la période
     * @param list<RetourPoint>   $retours
     * @param int                 $tauxBp       taux de commission en points de base (750 = 7,5 %)
     * @param ?int                $montantRemis espèces remises, en centimes ; null tant qu'elles ne sont pas saisies
     *
     * @throws \DomainException retour sans dotation, ou retourné + perdu au-delà du confié
     */
    public function calculer(array $dotations, array $retours, ModeRemuneration $mode, int $tauxBp, ?int $montantRemis = null): ResultatPoint
    {
        if ($tauxBp < 0 || $tauxBp > 10000) {
            throw new \DomainException('Le taux de commission doit être compris entre 0 et 100 %.');
        }

        $parProduit = [];
        foreach ($dotations as $dotation) {
            $parProduit[$dotation->produitId][] = $dotation;
        }

        [$retournes, $perdus] = $this->cumulerRetours($retours, $parProduit);

        $lignes = [];
        $totaux = ['confiee' => 0, 'retournee' => 0, 'perdue' => 0, 'vendue' => 0, 'montant' => 0, 'marge' => 0];

        foreach ($parProduit as $produitId => $lignesProduit) {
            usort($lignesProduit, static fn (DotationPoint $a, DotationPoint $b): int => [$a->date, $a->ordre] <=> [$b->date, $b->ordre]);

            $premiere = $lignesProduit[0];
            $confiee = array_sum(array_map(static fn (DotationPoint $d): int => $d->quantite, $lignesProduit));
            $retournee = $retournes[$produitId] ?? 0;
            $perdue = $perdus[$produitId] ?? 0;
            $vendue = $confiee - $retournee - $perdue;

            if ($vendue < 0) {
                throw new \DomainException(\sprintf(
                    '« %s » : %s retourné(s) ou perdu(s) pour %s confié(s) sur la période.',
                    $premiere->produit,
                    self::unites($retournee + $perdue),
                    self::unites($confiee),
                ));
            }

            // Plus anciennes dotations vendues d'abord.
            $aImputer = $vendue;
            $tranches = [];
            foreach ($lignesProduit as $dotation) {
                $vendu = min($dotation->quantite, $aImputer);
                $aImputer -= $vendu;

                $montant = intdiv($vendu * $dotation->prixVente, 1000);
                $tranches[] = new TranchePoint(
                    $dotation->date,
                    $dotation->quantite,
                    $vendu,
                    $dotation->prixVente,
                    $dotation->prixCession,
                    $montant,
                    $montant - intdiv($vendu * $dotation->prixCession, 1000),
                    $dotation->ordre,
                );
            }

            $montantLigne = array_sum(array_map(static fn (TranchePoint $t): int => $t->montantAttendu, $tranches));
            $margeLigne = array_sum(array_map(static fn (TranchePoint $t): int => $t->marge, $tranches));

            $lignes[] = new LignePoint(
                $produitId,
                $premiere->produit,
                $premiere->unite,
                $confiee,
                $retournee,
                $perdue,
                $vendue,
                $montantLigne,
                ModeRemuneration::MARGE === $mode ? $margeLigne : self::commission($montantLigne, $tauxBp),
                $tranches,
            );

            $totaux['confiee'] += $confiee;
            $totaux['retournee'] += $retournee;
            $totaux['perdue'] += $perdue;
            $totaux['vendue'] += $vendue;
            $totaux['montant'] += $montantLigne;
            $totaux['marge'] += $margeLigne;
        }

        usort($lignes, static fn (LignePoint $a, LignePoint $b): int => strcmp($a->produit, $b->produit));

        $remuneration = ModeRemuneration::MARGE === $mode ? $totaux['marge'] : self::commission($totaux['montant'], $tauxBp);
        $net = $totaux['montant'] - $remuneration;

        return new ResultatPoint(
            $lignes,
            $totaux['confiee'],
            $totaux['retournee'],
            $totaux['perdue'],
            $totaux['vendue'],
            $totaux['montant'],
            $remuneration,
            $net,
            $montantRemis,
            null === $montantRemis ? null : $montantRemis - $net,
        );
    }

    /**
     * Commission arrondie au franc le plus proche : centimes × points de base, puis
     * division entière avec demi-franc vers le haut.
     */
    public static function commission(int $montantCentimes, int $tauxBp): int
    {
        // montant × taux / 10 000 = commission en centimes ; / 100 de plus pour le
        // franc, d'où 1 000 000, et la moitié ajoutée avant la division.
        return intdiv($montantCentimes * $tauxBp + 500000, 1000000) * 100;
    }

    /**
     * @param list<RetourPoint>                 $retours
     * @param array<int, list<DotationPoint>>   $parProduit
     *
     * @return array{0: array<int, int>, 1: array<int, int>} [invendus, pertes] par produit
     */
    private function cumulerRetours(array $retours, array $parProduit): array
    {
        $retournes = [];
        $perdus = [];

        foreach ($retours as $retour) {
            if ($retour->quantite < 0) {
                throw new \DomainException('Une quantité retournée ne peut pas être négative.');
            }
            if (0 === $retour->quantite) {
                continue;
            }
            if (!isset($parProduit[$retour->produitId])) {
                throw new \DomainException('Retour d\'un produit qui n\'a pas été confié sur la période.');
            }

            if ($retour->motif->estPerte()) {
                $perdus[$retour->produitId] = ($perdus[$retour->produitId] ?? 0) + $retour->quantite;
            } else {
                $retournes[$retour->produitId] = ($retournes[$retour->produitId] ?? 0) + $retour->quantite;
            }
        }

        return [$retournes, $perdus];
    }

    private static function unites(int $millimes): string
    {
        $decimales = rtrim(\sprintf('%03d', $millimes % 1000), '0');

        return intdiv($millimes, 1000).('' !== $decimales ? ','.$decimales : '');
    }
}
