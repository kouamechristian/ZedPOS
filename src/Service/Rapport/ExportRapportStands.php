<?php

namespace App\Service\Rapport;

/**
 * Les rapports des stands en CSV : `;`, BOM UTF-8 (sans lui, Excel sous Windows lit
 * de l'ANSI et massacre les accents), fin de ligne `\r\n`. Même format de nombres
 * que `RapportVentesCsv` : montants `1500,00`, quantités `12` ou `12,5`, taux `8,33`.
 *
 * Les colonnes reprennent celles de l'écran ; le CSV consomme **les mêmes données**
 * que la page, les chiffres ne peuvent pas diverger.
 */
class ExportRapportStands
{
    private const BOM_UTF8 = "\u{FEFF}";
    private const FIN_LIGNE = "\r\n";

    /** @param array<mixed> $donnees ce que rend {@see RapportStands} pour ce rapport */
    public function csv(string $rapport, array $donnees): string
    {
        $lignes = match ($rapport) {
            'vendeurs' => $this->groupes($donnees, 'vendeur', 'Vendeur', true),
            'comparatif' => $this->comparatif($donnees),
            'produits' => $this->produits($donnees),
            default => $this->groupes($donnees, 'stand', 'Stand', false),
        };

        return self::BOM_UTF8.implode(self::FIN_LIGNE, array_map($this->ligne(...), $lignes)).self::FIN_LIGNE;
    }

    /**
     * Détail par tranche, puis le total de chaque groupe sur la plage, puis le total.
     *
     * @param array<mixed> $donnees
     *
     * @return list<list<string>>
     */
    private function groupes(array $donnees, string $nature, string $titre, bool $dette): array
    {
        $colonnes = ['Tranche', $titre, 'Confié', 'En attente de point', 'Vendu', 'Retourné', 'Taux d\'invendus (%)', 'Perdu', 'Valeur des pertes (FCFA)', 'CA (FCFA)', 'Rémunération (FCFA)', 'Net boutique (FCFA)', 'Manquants (FCFA)', 'Trop-perçus (FCFA)', 'Points'];
        if ($dette) {
            $colonnes[] = 'Dette actuelle (FCFA)';
        }

        $ligne = fn (string $tranche, string $nom, array $c): array => [
            $tranche,
            $nom,
            $this->quantite($c['confiee']),
            $this->quantite($c['enAttente']),
            $this->quantite($c['vendue']),
            $this->quantite($c['retournee']),
            $this->taux($c['tauxInvendus']),
            $this->quantite($c['perdue']),
            $this->montant($c['valeurPertes']),
            $this->montant($c['ca']),
            $this->montant($c['remuneration']),
            $this->montant($c['net']),
            $this->montant($c['manquants']),
            $this->montant($c['tropPercus']),
            (string) $c['points'],
            ...($dette ? [isset($c['dette']) ? $this->montant($c['dette']) : ''] : []),
        ];

        $lignes = [$colonnes];
        foreach ($donnees['detail'] as $chiffres) {
            $lignes[] = $ligne($chiffres['tranche'], $chiffres[$nature], $chiffres);
        }
        foreach ($donnees['groupes'] as $chiffres) {
            $lignes[] = $ligne('Toute la période', $chiffres[$nature], $chiffres);
        }
        $lignes[] = $ligne('Toute la période', 'Total', $donnees['total']);

        return $lignes;
    }

    /**
     * @param array<mixed> $donnees
     *
     * @return list<list<string>>
     */
    private function comparatif(array $donnees): array
    {
        $lignes = [['Tranche', 'CA caisse (FCFA)', 'Tickets', 'CA stands (FCFA)', 'Net stands (FCFA)', 'CA global (FCFA)', 'Part des stands (%)', 'Confié en attente de point']];
        foreach ([...$donnees['lignes'], $donnees['total']] as $l) {
            $lignes[] = [$l['tranche'], $this->montant($l['caCaisse']), (string) $l['tickets'], $this->montant($l['caStands']), $this->montant($l['netStands']), $this->montant($l['caGlobal']), $this->taux($l['partStands']), $this->quantite($l['enAttente'])];
        }

        return $lignes;
    }

    /**
     * @param list<array{stand: string, produits: list<array<string, mixed>>}> $donnees
     *
     * @return list<list<string>>
     */
    private function produits(array $donnees): array
    {
        $lignes = [['Stand', 'Rang', 'Produit', 'Unité', 'Vendu', 'Retourné', 'Perdu', 'Taux d\'invendus (%)', 'CA (FCFA)']];
        foreach ($donnees as $stand) {
            foreach ($stand['produits'] as $p) {
                $lignes[] = [$stand['stand'], (string) $p['rang'], $p['produit'], $p['unite'], $this->quantite($p['vendue']), $this->quantite($p['retournee']), $this->quantite($p['perdue']), $this->taux($p['tauxInvendus']), $this->montant($p['ca'])];
            }
        }

        return $lignes;
    }

    private function montant(int $centimes): string
    {
        $absolu = abs($centimes);

        return \sprintf('%s%d,%02d', $centimes < 0 ? '-' : '', intdiv($absolu, 100), $absolu % 100);
    }

    private function quantite(int $millimes): string
    {
        $absolu = abs($millimes);
        $decimales = rtrim(\sprintf('%03d', $absolu % 1000), '0');

        return ($millimes < 0 ? '-' : '').intdiv($absolu, 1000).('' !== $decimales ? ','.$decimales : '');
    }

    private function taux(?int $bp): string
    {
        return null === $bp ? '' : \sprintf('%d,%02d', intdiv($bp, 100), $bp % 100);
    }

    /** @param list<string> $valeurs */
    private function ligne(array $valeurs): string
    {
        return implode(';', array_map(
            static fn (string $valeur): string => 1 === preg_match('/[";\r\n]/', $valeur) ? '"'.str_replace('"', '""', $valeur).'"' : $valeur,
            $valeurs,
        ));
    }
}
