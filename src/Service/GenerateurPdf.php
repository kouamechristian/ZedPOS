<?php

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rend un gabarit HTML en fichier PDF téléchargeable.
 *
 * Le seul endroit du projet où Dompdf est instancié : les écrans lui donnent du
 * HTML et récupèrent une réponse. Un second appelant qui recopierait ces
 * réglages finirait par en oublier un — et l'oubli est muet, le PDF sort
 * simplement mal.
 *
 * Trois précautions, dans cet ordre d'importance :
 *
 * - **`DejaVu Sans` par défaut.** C'est la police embarquée par Dompdf qui porte
 *   les accents. Avec Helvetica, « Pâté » sort « P?t? » — sur un document qu'on
 *   envoie au cabinet ou qu'on classe, et sans le moindre avertissement.
 * - **Aucune ressource distante** (`isRemoteEnabled` laissé à faux). Un PDF doit
 *   sortir quand Internet est coupé, comme le reste de ce logiciel ; le logo
 *   voyage donc en `data:` URI, à la charge de l'appelant.
 * - **PHP désactivé dans le gabarit** : le HTML qu'on rend contient des libellés
 *   d'articles et des commentaires saisis à l'écran. Rien de ce qui vient de la
 *   base ne doit pouvoir s'exécuter.
 */
class GenerateurPdf
{
    /**
     * @param string $html        document complet (`<!DOCTYPE html>…`)
     * @param string $nomFichier  nom proposé au téléchargement, extension comprise
     * @param bool   $telecharger vrai pour forcer l'enregistrement, faux pour
     *                            afficher dans la visionneuse du navigateur
     */
    public function reponse(string $html, string $nomFichier, bool $telecharger = true): Response
    {
        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultPaperSize('A4');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        $reponse = new Response((string) $dompdf->output());
        $reponse->headers->set('Content-Type', 'application/pdf');
        $reponse->headers->set('Content-Disposition', $reponse->headers->makeDisposition(
            $telecharger ? 'attachment' : 'inline',
            $nomFichier,
            // Repli ASCII : un nom accentué est illisible pour les navigateurs
            // qui ne lisent pas `filename*`, et le téléchargement échoue.
            $this->translitterer($nomFichier),
        ));

        return $reponse;
    }

    /**
     * Nom de fichier de secours, sans accent ni caractère qui demanderait à être
     * échappé dans un en-tête HTTP.
     */
    private function translitterer(string $nom): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $nom);

        return preg_replace('/[^A-Za-z0-9._-]+/', '-', false !== $ascii ? $ascii : $nom) ?? 'document.pdf';
    }
}
