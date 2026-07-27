<?php

namespace App\Controller\Admin;

use App\Controller\Trait\ReponseFormulaire;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Form\CreerUtilisateurType;
use App\Security\Permission;
use App\Service\CreationUtilisateur;
use App\Service\CreationUtilisateurException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Création de comptes depuis le back-office.
 *
 * Ouvert au **gérant et à la dirigeante** — créer un compte fait partie de la
 * gestion d'une équipe qui tourne. La règle qui compte n'est donc pas qui entre
 * ici, mais **quel rôle chacun peut attribuer** : le gérant ne se voit jamais
 * proposer « Dirigeante », faute de quoi il s'octroierait ses droits en
 * s'ouvrant un second compte. Voir {@see RoleUtilisateur::attribuablesPar()}.
 */
#[Route('/admin/utilisateurs')]
#[IsGranted(Permission::UTILISATEUR_GERER)]
class UtilisateurController extends AbstractController
{
    use ReponseFormulaire;

    #[Route('/nouveau', name: 'admin_utilisateur_new', methods: ['GET', 'POST'])]
    public function new(Request $request, CreationUtilisateur $creation): Response
    {
        $form = $this->createForm(CreerUtilisateurType::class, null, [
            'roles_attribuables' => RoleUtilisateur::attribuablesPar($this->isGranted('ROLE_DIRIGEANTE')),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $donnees = $form->getData();
            $role = $donnees['role'];
            \assert($role instanceof RoleUtilisateur);

            // Le secret retenu suit le rôle : PIN pour un caissier, mot de passe sinon.
            $secret = (string) ($role->utiliseCodePin() ? $donnees['codePin'] : $donnees['motDePasse']);

            try {
                $utilisateur = $creation->creer((string) $donnees['email'], (string) $donnees['nom'], $role, $secret);
            } catch (CreationUtilisateurException $e) {
                // Erreur métier (e-mail pris, PIN en doublon) : on réaffiche le
                // formulaire avec le message, en 422 pour que Turbo le remplace.
                $form->addError(new FormError($e->getMessage()));

                return $this->rendreFormulaire('admin/utilisateur/form.html.twig', $form, [
                    'titre' => 'Nouvel utilisateur',
                    'bouton' => 'Créer le compte',
                ]);
            }

            $this->addFlash('success', \sprintf(
                'Compte « %s » créé (%s).',
                $utilisateur->getNom(),
                $role->libelle(),
            ));

            return $this->redirectToRoute('admin_utilisateurs', [], Response::HTTP_SEE_OTHER);
        }

        return $this->rendreFormulaire('admin/utilisateur/form.html.twig', $form, [
            'titre' => 'Nouvel utilisateur',
            'bouton' => 'Créer le compte',
        ]);
    }

    /**
     * Modification d'un compte : nom, e-mail, rôle, et remise à zéro du secret.
     *
     * La permission porte sur **le compte visé** (`UtilisateurVoter`) : un gérant
     * ne modifie pas une dirigeante, il n'aurait qu'à changer son e-mail pour
     * s'emparer de son accès.
     */
    #[Route('/{id}/modifier', name: 'admin_utilisateur_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Permission::UTILISATEUR_GERER, subject: 'utilisateur')]
    public function edit(Request $request, Utilisateur $utilisateur, CreationUtilisateur $creation): Response
    {
        $actuel = $this->roleActuel($utilisateur);

        $form = $this->createForm(CreerUtilisateurType::class, [
            'nom' => $utilisateur->getNom(),
            'email' => $utilisateur->getEmail(),
            'role' => $actuel,
        ], [
            'roles_attribuables' => $this->rolesAttribuables($utilisateur, $actuel),
            // Champ vide = secret inchangé : corriger un nom ne réinitialise pas
            // l'identifiant de quelqu'un.
            'secret_requis' => false,
        ]);
        $form->handleRequest($request);

        $vue = [
            'titre' => 'Modifier « '.$utilisateur->getNom().' »',
            'bouton' => 'Enregistrer',
            'utilisateur' => $utilisateur,
        ];

        if ($form->isSubmitted() && $form->isValid()) {
            $donnees = $form->getData();
            $role = $donnees['role'];
            \assert($role instanceof RoleUtilisateur);

            $secret = (string) ($role->utiliseCodePin() ? $donnees['codePin'] : $donnees['motDePasse']);

            try {
                $creation->modifier($utilisateur, (string) $donnees['email'], (string) $donnees['nom'], $role, $secret);
            } catch (CreationUtilisateurException $e) {
                $form->addError(new FormError($e->getMessage()));

                return $this->rendreFormulaire('admin/utilisateur/form.html.twig', $form, $vue);
            }

            $this->addFlash('success', \sprintf('Compte « %s » modifié.', $utilisateur->getNom()));

            return $this->redirectToRoute('admin_utilisateurs', [], Response::HTTP_SEE_OTHER);
        }

        return $this->rendreFormulaire('admin/utilisateur/form.html.twig', $form, $vue);
    }

    /**
     * Rôles proposés pour **ce** compte.
     *
     * Deux plafonds se cumulent :
     * - celui de l'auteur — un gérant n'attribue jamais « Dirigeante » ;
     * - **son propre compte** : on ne change pas son rôle soi-même. Un gérant qui
     *   se rétrograderait en caissier perdrait `/admin` séance tenante et il
     *   faudrait quelqu'un d'autre pour l'en sortir. Même esprit que
     *   l'interdiction de se désactiver soi-même, déjà en place.
     *
     * Le rôle interdit n'est pas *désactivé* mais **absent** de la liste : le
     * `ChoiceType` rejette alors une valeur forgée, sans contrôle supplémentaire.
     *
     * @return list<RoleUtilisateur>
     */
    private function rolesAttribuables(Utilisateur $cible, RoleUtilisateur $actuel): array
    {
        if ($cible === $this->getUser()) {
            return [$actuel];
        }

        // Le rôle en place figure toujours dans la liste, même hors de portée de
        // l'auteur : sans lui, le formulaire s'ouvrirait sur un choix vide et
        // n'importe quel enregistrement rétrograderait le compte.
        $attribuables = RoleUtilisateur::attribuablesPar($this->isGranted('ROLE_DIRIGEANTE'));

        return \in_array($actuel, $attribuables, true) ? $attribuables : [$actuel, ...$attribuables];
    }

    /**
     * Rôle applicatif d'un compte. `Utilisateur::getRoles()` ajoute `ROLE_USER`,
     * qui n'est pas un rôle métier ; on retient le premier qui en est un.
     */
    private function roleActuel(Utilisateur $utilisateur): RoleUtilisateur
    {
        foreach ($utilisateur->getRoles() as $role) {
            $connu = RoleUtilisateur::tryFrom($role);
            if (null !== $connu) {
                return $connu;
            }
        }

        return RoleUtilisateur::CAISSIER;
    }
}
