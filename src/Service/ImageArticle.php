<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stockage des images de touches produits.
 *
 * Point d'entrée unique : le dépôt sur disque, le redimensionnement et la
 * suppression vivent ici. Les contrôleurs ne manipulent ni chemin ni `move()`.
 *
 * **Les images sont redimensionnées à l'enregistrement.** Une photo prise au
 * téléphone fait 4 000 px de large pour une touche qui en occupe 92 : servie
 * telle quelle, elle chargerait la tablette du comptoir pour rien, et surtout
 * elle gonflerait le cache du Service Worker — dont dépend la caisse hors ligne.
 */
class ImageArticle
{
    /**
     * Côté maximal, en pixels.
     *
     * Une touche fait ~92 px de large ; 400 laisse la marge nécessaire aux écrans
     * à forte densité (×3) et aux grilles plus larges, sans stocker de photo
     * d'imprimerie.
     */
    private const COTE_MAX = 400;

    private const QUALITE_JPEG = 82;

    /** Formats acceptés, et l'extension retenue pour chacun. */
    private const FORMATS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly string $repertoire)
    {
    }

    /**
     * Chemin public d'une image, tel qu'il s'écrit dans un `src`.
     *
     * Composé ici et nulle part ailleurs : c'est ce qui permet de déplacer le
     * répertoire de stockage sans toucher à la base ni aux gabarits.
     */
    public function chemin(?string $image): ?string
    {
        return null !== $image && '' !== $image ? '/uploads/articles/'.$image : null;
    }

    /**
     * Enregistre une image téléversée et renvoie son nom de fichier.
     *
     * @throws \RuntimeException si le format n'est pas exploitable
     */
    public function enregistrer(UploadedFile $fichier): string
    {
        $type = (string) $fichier->getMimeType();
        $extension = self::FORMATS[$type] ?? throw new \RuntimeException(
            'Format d\'image non pris en charge : utilisez du JPEG, du PNG ou du WebP.'
        );

        $source = $this->lire($fichier->getPathname(), $type);
        $reduite = $this->reduire($source);

        // Nom aléatoire : deux articles nommés « Pain » ne se marchent pas dessus,
        // et remplacer une image change son URL — ce qui **importe** pour le cache
        // du Service Worker, qui sert les images « cache d'abord ».
        $nom = bin2hex(random_bytes(8)).'.'.$extension;

        $this->creerRepertoire();
        $this->ecrire($reduite, $this->repertoire.'/'.$nom, $type);

        imagedestroy($reduite);
        if ($reduite !== $source) {
            imagedestroy($source);
        }

        return $nom;
    }

    /**
     * Supprime le fichier d'une image. Silencieux s'il a déjà disparu : une image
     * manquante ne doit pas empêcher de modifier un article.
     */
    public function supprimer(?string $image): void
    {
        if (null === $image || '' === $image) {
            return;
        }

        $chemin = $this->repertoire.'/'.basename($image);
        if (is_file($chemin)) {
            @unlink($chemin);
        }
    }

    private function lire(string $chemin, string $type): \GdImage
    {
        $image = match ($type) {
            'image/jpeg' => @imagecreatefromjpeg($chemin),
            'image/png' => @imagecreatefrompng($chemin),
            'image/webp' => @imagecreatefromwebp($chemin),
            default => false,
        };

        return $image ?: throw new \RuntimeException('Cette image n\'a pas pu être lue.');
    }

    /**
     * Réduit l'image pour que son plus grand côté tienne dans {@see COTE_MAX}.
     * Une image déjà petite est renvoyée telle quelle — l'agrandir ne créerait
     * que du flou et du poids.
     */
    private function reduire(\GdImage $source): \GdImage
    {
        $largeur = imagesx($source);
        $hauteur = imagesy($source);
        $cote = max($largeur, $hauteur);

        if ($cote <= self::COTE_MAX) {
            return $source;
        }

        $nouvelleLargeur = (int) round($largeur * self::COTE_MAX / $cote);
        $nouvelleHauteur = (int) round($hauteur * self::COTE_MAX / $cote);

        $reduite = imagecreatetruecolor($nouvelleLargeur, $nouvelleHauteur);

        // Sans ces deux lignes, une image à fond transparent ressortirait sur du
        // noir : `imagecreatetruecolor` remplit son canevas de noir opaque.
        imagealphablending($reduite, false);
        imagesavealpha($reduite, true);

        imagecopyresampled($reduite, $source, 0, 0, 0, 0, $nouvelleLargeur, $nouvelleHauteur, $largeur, $hauteur);

        return $reduite;
    }

    private function ecrire(\GdImage $image, string $chemin, string $type): void
    {
        $ecrit = match ($type) {
            'image/jpeg' => imagejpeg($image, $chemin, self::QUALITE_JPEG),
            'image/png' => imagepng($image, $chemin),
            'image/webp' => imagewebp($image, $chemin, self::QUALITE_JPEG),
            default => false,
        };

        if (!$ecrit) {
            throw new \RuntimeException('L\'image n\'a pas pu être enregistrée.');
        }
    }

    private function creerRepertoire(): void
    {
        if (!is_dir($this->repertoire) && !mkdir($this->repertoire, 0o775, true) && !is_dir($this->repertoire)) {
            throw new \RuntimeException('Le répertoire des images est introuvable et n\'a pas pu être créé.');
        }
    }
}
