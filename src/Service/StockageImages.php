<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Dépôt d'images téléversées : écriture sur disque, réduction, suppression.
 *
 * Socle commun aux images de l'application — touches produits
 * ({@see ImageArticle}) et logo de l'établissement ({@see LogoBoutique}). Chaque
 * usage n'a que trois choses à dire : **où** les fichiers vivent, sous **quelle
 * URL** ils sont servis, et à quelle **taille** ils sont ramenés.
 *
 * Extrait plutôt que recopié : le traitement GD tient en une centaine de lignes
 * dont chacune porte une précaution (transparence préservée, nom tiré au sort,
 * ancien fichier effacé après le nouveau). Une seconde copie divergerait, et
 * c'est justement l'oubli d'une de ces précautions qui ne se voit pas à l'œil.
 *
 * **Les images sont réduites à l'enregistrement.** Une photo prise au téléphone
 * fait 4 000 px de large pour une touche qui en occupe 92 : servie telle quelle,
 * elle chargerait la tablette du comptoir pour rien, et surtout elle gonflerait
 * le cache du Service Worker — dont dépend la caisse hors ligne.
 */
abstract class StockageImages
{
    private const QUALITE_JPEG = 82;

    /** Formats acceptés, et l'extension retenue pour chacun. */
    private const FORMATS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @param string $repertoire    répertoire de dépôt, sans barre oblique finale
     * @param string $prefixePublic préfixe d'URL sous lequel les fichiers sont servis
     * @param int    $coteMax       côté maximal en pixels après réduction
     */
    public function __construct(
        private readonly string $repertoire,
        private readonly string $prefixePublic,
        private readonly int $coteMax,
    ) {
    }

    /**
     * Chemin public d'une image, tel qu'il s'écrit dans un `src`.
     *
     * Composé ici et nulle part ailleurs : c'est ce qui permet de déplacer le
     * répertoire de stockage sans toucher à la base ni aux gabarits.
     */
    public function chemin(?string $image): ?string
    {
        return null !== $image && '' !== $image ? $this->prefixePublic.$image : null;
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
     * manquante ne doit pas empêcher d'enregistrer le reste.
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
     * Réduit l'image pour que son plus grand côté tienne dans le côté maximal.
     * Une image déjà petite est renvoyée telle quelle — l'agrandir ne créerait
     * que du flou et du poids.
     */
    private function reduire(\GdImage $source): \GdImage
    {
        $largeur = imagesx($source);
        $hauteur = imagesy($source);
        $cote = max($largeur, $hauteur);

        if ($cote <= $this->coteMax) {
            return $source;
        }

        $nouvelleLargeur = (int) round($largeur * $this->coteMax / $cote);
        $nouvelleHauteur = (int) round($hauteur * $this->coteMax / $cote);

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
