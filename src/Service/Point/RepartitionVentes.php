<?php

namespace App\Service\Point;

use App\Enum\ModeRemuneration;

/**
 * Ramène le résultat d'un point **à chaque ligne de dotation** : ce qui en a été
 * vendu, retourné, perdu, le chiffre et la rémunération qui lui reviennent.
 *
 * C'est ce qui rend les rapports justes à la journée même sous un arrêté mensuel :
 * figé sur la ligne à la validation, le vendu se lit à la **date de la dotation**,
 * pas à la fin de la période.
 *
 * Règles, toutes en entiers :
 *
 * - **vendu** : celui du calcul du point, plus anciennes dotations d'abord
 *   ({@see PointCalculator}) ;
 * - **invendus puis pertes** : le non-vendu de chaque ligne, **des plus récentes aux
 *   plus anciennes**, couvre d'abord les invendus, puis les pertes — une ligne ne
 *   revient jamais pour plus qu'elle n'a laissé ;
 * - **chiffre** : le montant de la tranche, à son prix figé ;
 * - **rémunération** : à la marge, la marge de la tranche ; à la commission, la
 *   commission **totale** du point répartie au prorata des montants par la méthode
 *   du plus fort reste — la somme des lignes retombe au centime sur l'arrêté.
 *
 * Calcul pur, sans base.
 */
final class RepartitionVentes
{
    /**
     * @return array<string, array{vendue: int, retournee: int, perdue: int, montant: int, remuneration: int}>
     *                                                                                                      indexé par {@see self::cle()}
     */
    public static function repartir(ResultatPoint $resultat, ModeRemuneration $mode): array
    {
        $parts = [];
        $montants = [];

        foreach ($resultat->lignes as $ligne) {
            $invendus = $ligne->qteRetournee;
            $pertes = $ligne->qtePerdue;

            // Les plus récentes d'abord pour le non-vendu.
            foreach (array_reverse($ligne->tranches, true) as $tranche) {
                $nonVendu = $tranche->quantiteConfiee - $tranche->quantiteVendue;
                $retournee = min($nonVendu, $invendus);
                $perdue = min($nonVendu - $retournee, $pertes);
                $invendus -= $retournee;
                $pertes -= $perdue;

                $cle = self::cle($tranche->ordre, $ligne->produitId);
                $parts[$cle] = [
                    'vendue' => $tranche->quantiteVendue,
                    'retournee' => $retournee,
                    'perdue' => $perdue,
                    'montant' => $tranche->montantAttendu,
                    'remuneration' => ModeRemuneration::MARGE === $mode ? $tranche->marge : 0,
                ];
                $montants[$cle] = $tranche->montantAttendu;
            }

            if (0 !== $invendus || 0 !== $pertes) {
                throw new \LogicException(\sprintf('« %s » : retours non répartis sur les lignes.', $ligne->produit));
            }
        }

        if (ModeRemuneration::COMMISSION === $mode) {
            foreach (self::auProrata($resultat->remuneration, $montants) as $cle => $commission) {
                $parts[$cle]['remuneration'] = $commission;
            }
        }

        return $parts;
    }

    /** Une ligne de dotation : un bon ne porte un produit qu'une fois. */
    public static function cle(int $bonId, int $produitId): string
    {
        return $bonId.'-'.$produitId;
    }

    /**
     * Répartit `$total` au prorata des `$poids`, méthode du plus fort reste : les
     * parts sont entières et leur somme vaut exactement `$total`. À reste égal, la
     * première clé l'emporte.
     *
     * @param array<string, int> $poids
     *
     * @return array<string, int>
     */
    public static function auProrata(int $total, array $poids): array
    {
        $somme = array_sum($poids);
        if (0 === $somme) {
            return array_map(static fn (): int => 0, $poids);
        }

        $parts = [];
        $restes = [];
        foreach ($poids as $cle => $poid) {
            $parts[$cle] = intdiv($total * $poid, $somme);
            $restes[$cle] = ($total * $poid) % $somme;
        }

        $aDistribuer = $total - array_sum($parts);
        $ordre = array_keys($restes);
        $rang = array_flip($ordre);
        usort($ordre, static fn (string|int $a, string|int $b): int => [$restes[$b], $rang[$a]] <=> [$restes[$a], $rang[$b]]);

        foreach (\array_slice($ordre, 0, $aDistribuer) as $cle) {
            ++$parts[$cle];
        }

        return $parts;
    }
}
