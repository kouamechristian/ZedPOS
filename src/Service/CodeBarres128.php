<?php

namespace App\Service;

/**
 * Encodeur **Code 128, jeu B** — la symbologie retenue pour le numéro de ticket.
 *
 * Écrit à la main plutôt qu'apporté par une dépendance : la spécification tient
 * en une table de motifs et une somme de contrôle, elle ne bouge pas, et le
 * projet fabrique déjà ses commandes ESC/POS sans bibliothèque. Une dépendance
 * n'aurait de toute façon servi qu'au rendu HTML — sur l'imprimante thermique,
 * c'est le firmware qui dessine le code.
 *
 * **Jeu B** parce qu'il couvre l'ASCII imprimable : un numéro comme
 * `V260725-00001` mêle lettres, chiffres et tiret. Le jeu C serait deux fois plus
 * compact sur les chiffres, mais imposerait de basculer de jeu en cours de
 * chaîne pour un gain qui ne se voit pas sur 58 mm de papier.
 */
class CodeBarres128
{
    /**
     * Zone de silence : marge blanche obligatoire de part et d'autre du symbole,
     * sans laquelle un lecteur ne trouve pas le début du code. La norme demande
     * au moins 10 modules ; c'est le point le plus souvent oublié, et il rend le
     * code muet sans qu'on voie quoi que ce soit d'anormal à l'impression.
     */
    private const SILENCE = 10;

    private const START_B = 104;
    private const STOP = 106;

    /**
     * Largeurs des 107 motifs, un chiffre par élément, en alternant **barre puis
     * espace** à partir de la première. Table normative : ne pas la « corriger ».
     */
    private const MOTIFS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312',
        '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
        '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131',
        '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
        '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
        '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321',
        '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
        '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114',
        '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112',
        '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
        '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412',
        '211214', '211232', '2331112',
    ];

    /**
     * Le jeu B code l'ASCII 32 (espace) à 126 (tilde). Hors de cette plage, il
     * faudrait changer de jeu : autant le dire franchement que produire un code
     * illisible.
     */
    public function supporte(string $valeur): bool
    {
        return '' !== $valeur && 1 === preg_match('/^[\x20-\x7E]+$/', $valeur);
    }

    /**
     * @throws \DomainException si la chaîne sort du jeu B
     */
    public function encoder(string $valeur): CodeBarres
    {
        if (!$this->supporte($valeur)) {
            throw new \DomainException(\sprintf('« %s » n\'est pas encodable en Code 128 jeu B.', $valeur));
        }

        $codes = [self::START_B];
        $controle = self::START_B;

        foreach (str_split($valeur) as $position => $caractere) {
            // Jeu B : la valeur d'un caractère est son code ASCII moins 32.
            $code = \ord($caractere) - 32;
            $codes[] = $code;
            // Chaque caractère pèse son rang dans la somme de contrôle, ce qui
            // fait qu'une permutation de deux caractères ne passe pas inaperçue.
            $controle += $code * ($position + 1);
        }

        $codes[] = $controle % 103;
        $codes[] = self::STOP;

        return new CodeBarres($valeur, ...$this->barres($codes));
    }

    /**
     * Déroule les motifs en barres noires. Chaque motif commence par une barre et
     * alterne ; comme tous comptent six éléments (sept pour le STOP, dont la
     * dernière est une barre), l'alternance se poursuit d'un motif à l'autre sans
     * qu'on ait à la suivre globalement.
     *
     * @param list<int> $codes
     *
     * @return array{0: list<array{x: int, largeur: int}>, 1: int}
     */
    private function barres(array $codes): array
    {
        $barres = [];
        $x = self::SILENCE;

        foreach ($codes as $code) {
            foreach (str_split(self::MOTIFS[$code]) as $rang => $largeur) {
                $largeur = (int) $largeur;
                if (0 === $rang % 2) {
                    $barres[] = ['x' => $x, 'largeur' => $largeur];
                }
                $x += $largeur;
            }
        }

        return [$barres, $x + self::SILENCE];
    }
}
