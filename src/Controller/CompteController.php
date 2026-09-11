<?php

namespace App\Controller;

use App\Controller\Trait\ReponseFormulaire;
use App\Entity\Utilisateur;
use App\Form\ChangerCodePinType;
use App\Form\ChangerMotDePasseType;
use App\Security\RoleRedirectionHandler;
use App\Service\CreationUtilisateur;
use App\Service\CreationUtilisateurException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Changement de **son propre** secret — l'utilisateur connecté, et lui seul.
 *
 * Deux écrans parce qu'il y a deux portes : le mot de passe de `/login`
 * (dirigeante, gérant, comptable) et le code PIN du pavé de caisse. Chacun
 * renvoie vers l'autre si le compte n'utilise pas ce moyen de connexion : un
 * lien partagé ne mène jamais à un formulaire inutilisable.
 *
 * Réinitialiser le secret **d'un autre** reste l'affaire de
 * `/admin/utilisateurs/{id}/modifier`, qui ne demande pas l'ancien.
 *
 * **La session en cours survit au changement de mot de passe, les autres non.**
 * Symfony compare à chaque requête le hachage gardé en session à celui de la
 * base : les autres appareils sont déconnectés d'office — c'est ce qu'on attend
 * après un mot de passe compromis. La session courante, elle, sérialise l'entité
 * même qui vient d'être modifiée, et reste donc ouverte.
 */
#[IsGranted('IS_AUTHENTICATED')]
class CompteController extends AbstractController
{
    use ReponseFormulaire;

    #[Route('/compte/mot-de-passe', name: 'app_compte_mot_de_passe', methods: ['GET', 'POST'])]
    public function motDePasse(Request $request, CreationUtilisateur $comptes, RoleRedirectionHandler $accueil): Response
    {
        $utilisateur = $this->utilisateur();
        if (null === $utilisateur->getMotDePasse()) {
            return $this->redirectToRoute('app_caisse_code_pin');
        }

        $form = $this->createForm(ChangerMotDePasseType::class);
        $form->handleRequest($request);
        $vue = ['accueil' => $accueil->urlPour($utilisateur)];

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $comptes->changerMotDePasse($utilisateur, (string) $form->get('actuel')->getData(), (string) $form->get('nouveau')->getData());
            } catch (CreationUtilisateurException $e) {
                $form->addError(new FormError($e->getMessage()));

                return $this->rendreFormulaire('compte/mot_de_passe.html.twig', $form, $vue);
            }

            $this->addFlash('success', 'Mot de passe modifié. Vos autres appareils devront se reconnecter.');

            return $this->redirect($vue['accueil'], Response::HTTP_SEE_OTHER);
        }

        return $this->rendreFormulaire('compte/mot_de_passe.html.twig', $form, $vue);
    }

    #[Route('/caisse/code-pin', name: 'app_caisse_code_pin', methods: ['GET', 'POST'])]
    public function codePin(Request $request, CreationUtilisateur $comptes): Response
    {
        $utilisateur = $this->utilisateur();
        if (null === $utilisateur->getCodePin()) {
            return $this->redirectToRoute('app_compte_mot_de_passe');
        }

        $form = $this->createForm(ChangerCodePinType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $comptes->changerCodePin($utilisateur, (string) $form->get('actuel')->getData(), (string) $form->get('nouveau')->getData());
            } catch (CreationUtilisateurException $e) {
                $form->addError(new FormError($e->getMessage()));

                return $this->rendreFormulaire('caisse/code_pin.html.twig', $form);
            }

            // Retour sur cet écran et non sur `/caisse` : l'écran de vente
            // n'affiche pas les messages, et la caissière doit lire que c'est fait.
            $this->addFlash('success', 'Code PIN modifié. Utilisez le nouveau à votre prochaine connexion.');

            return $this->redirectToRoute('app_caisse_code_pin', [], Response::HTTP_SEE_OTHER);
        }

        return $this->rendreFormulaire('caisse/code_pin.html.twig', $form);
    }

    private function utilisateur(): Utilisateur
    {
        $utilisateur = $this->getUser();
        \assert($utilisateur instanceof Utilisateur);

        return $utilisateur;
    }
}
