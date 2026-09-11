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
 * Livré en `data:image/png;base64,…` : une seule chaîne, qui voyage dans le JSON
 * de `/print`, se range en IndexedDB avec le ticket hors ligne et s'affiche aussi
 * bien dans un `<img>`.
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

    /** À incrémenter si la conversion change : les images en cache sont alors recalculées. */
    private const VERSION = 2;

    public function __construct(
        private readonly ParametresBoutique $parametres,
        private readonly LogoBoutique $logos,
        private readonly CacheInterface $cache,
    ) {
    }

    /** Le logo en PNG noir et blanc, sous forme d'URL `data:`, ou `null`. */
    public function pourImpression(): ?string
    {
        $fichier = $this->logos->fichier($this->parametres->valeur(CleParametre::LOGO));
        if (null === $fichier) {
            return null;
        }

        // Le nom de fichier est tiré au sort à chaque téléversement : un nouveau
        // logo change donc la clé, et le cache ne peut pas servir l'ancien.
        $cle = 'logo_thermique_'.hash('xxh128', $fichier.'|'.filemtime($fichier).'|'.self::VERSION);

        try {
            return $this->cache->get($cle, fn (): ?string => $this->convertir($fichier));
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function convertir(string $fichier): ?string
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

    /** @param list<int> $luminance */
    private function encoder(array $luminance, int $largeur, int $hauteur, int $seuil, bool $inverse): ?string
    {
        $image = imagecreate(self::LARGEUR_TETE, $hauteur);
        // Première couleur allouée = fond de l'image : le blanc, donc.
        imagecolorallocate($image, 255, 255, 255);
        $noir = imagecolorallocate($image, 0, 0, 0);

        // Centrage dans la largeur de la tête : fait ici une fois pour toutes.
        $decalage = intdiv(self::LARGEUR_TETE - $largeur, 2);
        $points = 0;

        foreach ($luminance as $i => $valeur) {
            if (($valeur <= $seuil) !== $inverse) {
                imagesetpixel($image, $decalage + $i % $largeur, intdiv($i, $largeur), $noir);
                ++$points;
            }
        }

        if (0 === $points) {
            imagedestroy($image);

            return null;
        }

        ob_start();
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
