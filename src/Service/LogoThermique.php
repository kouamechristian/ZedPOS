<?php

namespace App\Service;

use App\Enum\CleParametre;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Logo de l'établissement, prêt pour la tête thermique de l'agent matériel.
 *
 * Le ticket HTML affiche le fichier téléversé tel quel ; l'agent, lui, ne sait
 * imprimer que ce qu'on lui donne, et sa tête **chauffe un point ou ne le chauffe
 * pas** — elle ne connaît ni la couleur, ni le gris, ni la transparence. Ce
 * service fait donc le travail en amont, et l'agent n'a plus qu'à pousser les
 * points :
 *
 * - **384 points de large exactement**, soit toute la largeur imprimable d'une
 *   tête 58 mm à 203 dpi, logo **centré** dedans : l'agent n'a ni à le
 *   redimensionner ni à le centrer ;
 * - dessin ramené dans **352 × 128 points** (44 × 16 mm), la même boîte que
 *   `.ticket .logo` du ticket HTML ;
 * - **noir et blanc pur**, en PNG à deux couleurs.
 *
 * **Seuil, et non trame.** Un logo est un dessin en aplats, pas une photo. Une
 * trame (Floyd-Steinberg) a été essayée d'abord : sur un logo posé sur un fond
 * orange, elle changeait le fond en grisaille de points qui noyait le dessin. Le
 * seuil est calculé pour chaque image (méthode d'Otsu) : il sépare le fond du
 * dessin quelles que soient leurs couleurs — orange et brun, blanc et ambre.
 *
 * **Recadré sur le dessin avant d'être réduit.** Un logo carré de 500 px sur fond
 * coloré, réduit tel quel à 128 points de haut, ne laissait au dessin qu'une
 * fraction de la hauteur ; le fond, parti en blanc, ne coûtait que du papier.
 *
 * **Le fond est toujours blanc sur le papier.** Si le bord de l'image sort noir
 * après seuillage — logo clair sur fond sombre —, l'image est inversée : sans
 * cela, la tête imprimerait un pavé noir plein à chaque vente.
 *
 * **Deux sorties, une seule conversion.** Le même dessin en noir et blanc sort
 * sous deux formes, calculées ensemble et mises en cache ensemble — deux
 * conversions séparées finiraient par diverger d'un point :
 *
 * | Méthode | Format | Pour |
 * |---|---|---|
 * | {@see self::pourImpression()} | `data:image/png;base64,…` | l'agent qui sait imprimer une image, le ticket hors ligne, un `<img>` |
 * | {@see self::pourEscPos()} | trame `GS v 0` en base64 | l'agent qui n'a **aucune** bibliothèque d'image : il pousse les octets tels quels vers la tête |
 *
 * La seconde existe parce que la première demandait à l'agent de décoder un PNG,
 * ce qui suppose une dépendance qu'un pont d'impression de deux cents lignes n'a
 * pas. `GS v 0` est ce que la tête comprend nativement : l'agent n'a plus rien à
 * calculer, plus rien à redimensionner, plus rien à centrer — il écrit.
 *
 * **Un logo ne doit jamais empêcher un ticket de sortir** : fichier disparu,
 * format illisible ou image uniforme donnent `null`, et l'agent imprime le
 * ticket sans logo.
 */
class LogoThermique
{
    /** Largeur imprimable de la tête : 48 mm à 203 dpi. */
    public const LARGEUR_TETE = 384;

    /** Boîte du logo, identique à celle du ticket HTML (44 × 16 mm). */
    public const LARGEUR_MAX = 352;
    public const HAUTEUR_MAX = 128;

    /**
     * Octets de la commande ESC/POS `GS v 0`, nommés plutôt que semés dans une
     * concaténation : à l'endroit où la commande se construit, on lit `GS` `v`
     * `0` — c'est-à-dire ce que dit la spécification. Une faute de frappe sur ces
     * octets ne se verrait qu'au comptoir, sur du papier déjà sorti.
     */
    private const GS = "\x1D";                 // Group Separator, préfixe de la commande
    private const TAILLE_NORMALE = "\x30\x00"; // « 0 », puis m = 0 : ni doublé en largeur, ni en hauteur
    private const SAUT_DE_LIGNE = "\x0A";

    /** À incrémenter si la conversion change : les images en cache sont alors recalculées. */
    private const VERSION = 3;

    public function __construct(
        private readonly ParametresBoutique $parametres,
        private readonly LogoBoutique $logos,
        private readonly CacheInterface $cache,
    ) {
    }

    /** Le logo en PNG noir et blanc, sous forme d'URL `data:`, ou `null`. */
    public function pourImpression(): ?string
    {
        return $this->converti()['png'] ?? null;
    }

    /**
     * Le même logo en **trame ESC/POS `GS v 0`**, encodée en base64, ou `null`.
     *
     * L'agent n'a qu'à écrire ces octets sur la tête, avant l'en-tête du ticket :
     * la commande porte déjà ses dimensions, le dessin fait la largeur exacte de
     * la tête et il est centré. Aucun décodage d'image, aucune mise à l'échelle,
     * donc aucune occasion de se tromper.
     */
    public function pourEscPos(): ?string
    {
        return $this->converti()['escpos'] ?? null;
    }

    /**
     * Le logo converti, ou `null`. Les deux formes sont calculées et mises en
     * cache **ensemble** : séparées, elles finiraient par ne plus représenter le
     * même dessin, et le ticket papier ne dirait plus la même chose selon l'agent.
     *
     * @return array{png: string, escpos: string}|null
     */
    private function converti(): ?array
    {
        $fichier = $this->logos->fichier($this->parametres->valeur(CleParametre::LOGO));
        if (null === $fichier) {
            return null;
        }

        // Le nom de fichier est tiré au sort à chaque téléversement : un nouveau
        // logo change donc la clé, et le cache ne peut pas servir l'ancien.
        $cle = 'logo_thermique_'.hash('xxh128', $fichier.'|'.filemtime($fichier).'|'.self::VERSION);

        try {
            return $this->cache->get($cle, fn (): ?array => $this->convertir($fichier));
        } catch (\RuntimeException) {
            return null;
        }
    }

    /** @return array{png: string, escpos: string}|null */
    private function convertir(string $fichier): ?array
    {
        $source = @imagecreatefromstring((string) file_get_contents($fichier));
        if (false === $source) {
            throw new \RuntimeException('Logo illisible.');
        }

        // 1. À pleine résolution : fond blanc, seuil, sens du fond, emprise du dessin.
        $pleine = $this->surFondBlanc($source, imagesx($source), imagesy($source));
        imagedestroy($source);

        $luminance = $this->luminance($pleine);
        $seuil = $this->seuilOtsu($luminance);
        $inverse = $this->fondSombre($luminance, imagesx($pleine), imagesy($pleine), $seuil);

        $emprise = $this->emprise($luminance, imagesx($pleine), imagesy($pleine), $seuil, $inverse);
        if (null === $emprise) {
            imagedestroy($pleine);

            return null;
        }

        // 2. Le dessin seul, ramené dans sa boîte, puis passé au même seuil.
        [$x, $y, $largeur, $hauteur] = $emprise;
        $echelle = min(self::LARGEUR_MAX / $largeur, self::HAUTEUR_MAX / $hauteur);
        $cibleLargeur = max(1, (int) floor($largeur * $echelle));
        $cibleHauteur = max(1, (int) floor($hauteur * $echelle));

        $reduite = imagecreatetruecolor($cibleLargeur, $cibleHauteur);
        imagecopyresampled($reduite, $pleine, 0, 0, $x, $y, $cibleLargeur, $cibleHauteur, $largeur, $hauteur);
        imagedestroy($pleine);

        $luminanceReduite = $this->luminance($reduite);
        imagedestroy($reduite);

        return $this->encoder($luminanceReduite, $cibleLargeur, $cibleHauteur, $seuil, $inverse);
    }

    /**
     * Copie l'image sur un fond blanc. La transparence n'existe pas sur le papier :
     * laissée telle quelle, elle sortirait noire.
     */
    private function surFondBlanc(\GdImage $source, int $largeur, int $hauteur): \GdImage
    {
        $toile = imagecreatetruecolor($largeur, $hauteur);
        imagefill($toile, 0, 0, imagecolorallocate($toile, 255, 255, 255));
        imagealphablending($toile, true);
        imagecopy($toile, $source, 0, 0, 0, 0, $largeur, $hauteur);

        return $toile;
    }

    /** @return list<int> luminance 0–255 de chaque pixel, ligne par ligne */
    private function luminance(\GdImage $image): array
    {
        $largeur = imagesx($image);
        $hauteur = imagesy($image);
        $valeurs = [];

        for ($y = 0; $y < $hauteur; ++$y) {
            for ($x = 0; $x < $largeur; ++$x) {
                $rgb = imagecolorat($image, $x, $y);
                $valeurs[] = intdiv(299 * (($rgb >> 16) & 0xFF) + 587 * (($rgb >> 8) & 0xFF) + 114 * ($rgb & 0xFF), 1000);
            }
        }

        return $valeurs;
    }

    /**
     * Seuil d'Otsu : la valeur qui sépare le mieux les pixels en deux familles
     * (celle qui maximise la variance entre elles). Un pixel est « sombre » s'il
     * est inférieur ou égal au seuil. Image uniforme : 127, par défaut.
     *
     * @param list<int> $luminance
     */
    private function seuilOtsu(array $luminance): int
    {
        $histogramme = array_fill(0, 256, 0);
        foreach ($luminance as $valeur) {
            ++$histogramme[$valeur];
        }

        $total = \count($luminance);
        $sommeTotale = 0;
        foreach ($histogramme as $valeur => $effectif) {
            $sommeTotale += $valeur * $effectif;
        }

        $meilleure = 0;
        $premier = null;
        $dernier = null;
        $poidsSombre = 0;
        $sommeSombre = 0;

        for ($t = 0; $t < 255; ++$t) {
            $poidsSombre += $histogramme[$t];
            $sommeSombre += $t * $histogramme[$t];
            $poidsClair = $total - $poidsSombre;
            if (0 === $poidsSombre || 0 === $poidsClair) {
                continue;
            }

            // Variance inter-classes, au facteur 1/total² près — comparée d'un
            // seuil à l'autre, le facteur commun ne change rien.
            $ecartMoyennes = $sommeSombre * $poidsClair - ($sommeTotale - $sommeSombre) * $poidsSombre;
            $variance = $ecartMoyennes * $ecartMoyennes / ($poidsSombre * $poidsClair);

            if ($variance > $meilleure) {
                $meilleure = $variance;
                $premier = $dernier = $t;
            } elseif ($variance === $meilleure && null !== $premier) {
                $dernier = $t;
            }
        }

        // Entre deux familles bien séparées, tous les seuils de l'intervalle se
        // valent. Le milieu, et non le premier : sinon le bord adouci d'un trait
        // — mi-dessin, mi-fond — tomberait systématiquement du côté du fond, et
        // les traits fins s'amincissaient à l'impression.
        return null === $premier ? 127 : intdiv($premier + $dernier, 2);
    }

    /**
     * Le fond est-il sombre ? Lu sur le **pourtour** de l'image : c'est là que se
     * trouve le fond de presque tous les logos, quelle que soit la taille du dessin.
     *
     * @param list<int> $luminance
     */
    private function fondSombre(array $luminance, int $largeur, int $hauteur, int $seuil): bool
    {
        $sombres = 0;
        $bord = 0;

        for ($x = 0; $x < $largeur; ++$x) {
            foreach ([0, $hauteur - 1] as $y) {
                ++$bord;
                $sombres += $luminance[$y * $largeur + $x] <= $seuil ? 1 : 0;
            }
        }
        for ($y = 1; $y < $hauteur - 1; ++$y) {
            foreach ([0, $largeur - 1] as $x) {
                ++$bord;
                $sombres += $luminance[$y * $largeur + $x] <= $seuil ? 1 : 0;
            }
        }

        return $sombres * 2 > $bord;
    }

    /**
     * Rectangle qui contient tous les points à imprimer.
     *
     * @param list<int> $luminance
     *
     * @return array{int, int, int, int}|null x, y, largeur, hauteur — `null` si rien à imprimer
     */
    private function emprise(array $luminance, int $largeur, int $hauteur, int $seuil, bool $inverse): ?array
    {
        $gauche = $largeur;
        $haut = $hauteur;
        $droite = -1;
        $bas = -1;

        foreach ($luminance as $i => $valeur) {
            if (($valeur <= $seuil) === $inverse) {
                continue;
            }
            $x = $i % $largeur;
            $y = intdiv($i, $largeur);
            $gauche = min($gauche, $x);
            $droite = max($droite, $x);
            $haut = min($haut, $y);
            $bas = max($bas, $y);
        }

        return $droite < 0 ? null : [$gauche, $haut, $droite - $gauche + 1, $bas - $haut + 1];
    }

    /**
     * Assemble le dessin dans la largeur de la tête et en sort les deux formes.
     *
     * Une seule boucle allume le point dans l'image GD **et** dans la trame
     * ESC/POS : c'est ce qui garantit que le PNG et la trame représentent le même
     * dessin, au point près.
     *
     * @param list<int> $luminance
     *
     * @return array{png: string, escpos: string}|null
     */
    private function encoder(array $luminance, int $largeur, int $hauteur, int $seuil, bool $inverse): ?array
    {
        $image = imagecreate(self::LARGEUR_TETE, $hauteur);
        // Première couleur allouée = fond de l'image : le blanc, donc.
        imagecolorallocate($image, 255, 255, 255);
        $noir = imagecolorallocate($image, 0, 0, 0);

        // Trame ESC/POS : un bit par point, huit points par octet, le point le
        // plus à gauche dans le bit de poids fort. 384 points font donc 48 octets
        // par ligne, sans reste — la largeur de la tête est un multiple de 8.
        $octetsParLigne = intdiv(self::LARGEUR_TETE, 8);
        $trame = array_fill(0, $octetsParLigne * $hauteur, 0);

        // Centrage dans la largeur de la tête : fait ici une fois pour toutes.
        $decalage = intdiv(self::LARGEUR_TETE - $largeur, 2);
        $points = 0;

        foreach ($luminance as $i => $valeur) {
            if (($valeur <= $seuil) === $inverse) {
                continue;
            }

            $x = $decalage + $i % $largeur;
            $y = intdiv($i, $largeur);
            imagesetpixel($image, $x, $y, $noir);
            $trame[$y * $octetsParLigne + ($x >> 3)] |= 0x80 >> ($x & 7);
            ++$points;
        }

        if (0 === $points) {
            imagedestroy($image);

            return null;
        }

        ob_start();
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return [
            'png' => 'data:image/png;base64,'.base64_encode($png),
            'escpos' => base64_encode($this->rasterEscPos($trame, $octetsParLigne, $hauteur)),
        ];
    }

    /**
     * Commande ESC/POS « imprimer une image en mode point » — `GS v 0`.
     *
     * `GS` `v` `0` `m` `xL` `xH` `yL` `yH` puis les octets de la trame :
     * `m = 0` (taille normale, ni doublée en largeur ni en hauteur), `x` en
     * **octets** par ligne et `y` en **lignes de points**, tous deux sur deux
     * octets, poids faible d'abord.
     *
     * Un saut de ligne ferme la commande : sans lui, la première ligne de
     * l'en-tête viendrait se coller au bas du logo.
     *
     * @param list<int> $trame
     */
    private function rasterEscPos(array $trame, int $octetsParLigne, int $hauteur): string
    {
        $entete = self::GS.'v'.self::TAILLE_NORMALE
            .\chr($octetsParLigne & 0xFF).\chr($octetsParLigne >> 8)
            .\chr($hauteur & 0xFF).\chr($hauteur >> 8);

        return $entete.implode('', array_map('\chr', $trame)).self::SAUT_DE_LIGNE;
    }
}
