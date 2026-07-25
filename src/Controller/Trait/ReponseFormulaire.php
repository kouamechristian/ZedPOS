<?php

namespace App\Controller\Trait;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rend un formulaire avec le bon code HTTP pour Turbo.
 *
 * Turbo Drive intercepte les soumissions de formulaire. Il exige qu'une réponse
 * qui **n'est pas une redirection** porte un statut d'erreur — sinon il refuse de
 * remplacer la page et l'utilisateur voit son formulaire figé, sans message
 * d'erreur, sans rien.
 *
 * Un formulaire soumis mais invalide doit donc répondre **422 Unprocessable
 * Entity** au lieu de 200. Sans Turbo, le comportement est strictement identique
 * pour l'utilisateur : le navigateur affiche le corps de la réponse dans les deux cas.
 */
trait ReponseFormulaire
{
    /**
     * @param array<string, mixed> $parametres
     */
    private function rendreFormulaire(string $gabarit, FormInterface $formulaire, array $parametres = []): Response
    {
        $invalide = $formulaire->isSubmitted() && !$formulaire->isValid();

        return $this->render(
            $gabarit,
            $parametres + ['form' => $formulaire],
            new Response(null, $invalide ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK),
        );
    }
}
