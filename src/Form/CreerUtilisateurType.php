<?php

namespace App\Form;

use App\Enum\RoleUtilisateur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Création d'un compte depuis le back-office.
 *
 * Le secret attendu dépend du rôle : un caissier se connecte au **code PIN** sur
 * le pavé numérique de la caisse, les autres rôles avec un **mot de passe**. Les
 * deux champs sont donc présents, et un écouteur POST_SUBMIT ne valide que celui
 * qui correspond au rôle choisi — l'autre est ignoré.
 *
 * Le formulaire n'est pas lié à l'entité (`data_class` nul) : le hachage et les
 * règles d'unicité appartiennent à App\Service\CreationUtilisateur.
 */
class CreerUtilisateurType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom complet',
                'constraints' => [
                    new Assert\NotBlank(message: 'Le nom est obligatoire.'),
                    new Assert\Length(max: 120),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'help' => 'Sert d\'identifiant de connexion, y compris pour un caissier.',
                'constraints' => [
                    new Assert\NotBlank(message: 'L\'e-mail est obligatoire.'),
                    new Assert\Email(message: 'Cette adresse e-mail n\'est pas valide.'),
                    new Assert\Length(max: 180),
                ],
            ])
            ->add('role', EnumType::class, [
                'class' => RoleUtilisateur::class,
                'label' => 'Rôle',
                // Seuls les rôles que l'auteur a le droit d'attribuer sont
                // proposés. Ce n'est pas du confort d'affichage : un choix absent
                // de la liste est **rejeté à la soumission** par le ChoiceType,
                // même en forgeant la requête — même technique que le prix de
                // vente, et plus sûre que de désactiver l'option.
                'choices' => $options['roles_attribuables'],
                'choice_label' => static fn (RoleUtilisateur $role): string => $role->libelle(),
                'placeholder' => 'Choisir un rôle…',
                'constraints' => [new Assert\NotNull(message: 'Le rôle est obligatoire.')],
            ])
            ->add('motDePasse', PasswordType::class, [
                'label' => 'Mot de passe',
                'required' => false,
                'help' => $options['secret_requis']
                    ? 'Rôles dirigeante, gérant et comptable — 6 caractères minimum.'
                    : 'Laisser vide pour conserver le mot de passe actuel.',
                // Un mot de passe n'est jamais réaffiché après une soumission invalide.
                'always_empty' => true,
            ])
            ->add('codePin', PasswordType::class, [
                'label' => 'Code PIN (4 chiffres)',
                'required' => false,
                'help' => $options['secret_requis']
                    ? 'Caissier uniquement — saisi sur le pavé numérique de la caisse.'
                    : 'Laisser vide pour conserver le code PIN actuel.',
                'always_empty' => true,
                'attr' => ['inputmode' => 'numeric', 'maxlength' => 4, 'autocomplete' => 'off'],
            ]);

        $builder->addEventListener(
            FormEvents::POST_SUBMIT,
            fn (FormEvent $evenement) => $this->validerSecret($evenement, $options['secret_requis']),
        );
    }

    /**
     * Exige le secret correspondant au rôle choisi, et lui seul.
     *
     * L'erreur est attachée au champ concerné plutôt qu'au formulaire : elle
     * s'affiche ainsi juste sous la bonne saisie.
     */
    private function validerSecret(FormEvent $evenement, bool $requis): void
    {
        $formulaire = $evenement->getForm();
        $role = $formulaire->get('role')->getData();

        if (!$role instanceof RoleUtilisateur) {
            return; // Rôle absent ou invalide : déjà signalé par NotNull.
        }

        $champ = $role->utiliseCodePin() ? 'codePin' : 'motDePasse';

        // En modification, un champ laissé vide veut dire « ne change rien » : le
        // format n'a pas à être vérifié. Le service refusera en revanche le vide
        // si le compte n'a pas déjà le secret que son nouveau rôle réclame.
        if (!$requis && '' === trim((string) $formulaire->get($champ)->getData())) {
            return;
        }

        if ($role->utiliseCodePin()) {
            $pin = (string) $formulaire->get('codePin')->getData();
            if (1 !== preg_match('/^\d{4}$/', $pin)) {
                $formulaire->get('codePin')->addError(
                    new FormError('Le code PIN doit comporter exactement 4 chiffres.')
                );
            }

            return;
        }

        $motDePasse = (string) $formulaire->get('motDePasse')->getData();
        if (\strlen($motDePasse) < 6) {
            $formulaire->get('motDePasse')->addError(
                new FormError('Le mot de passe doit comporter au moins 6 caractères.')
            );
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            // Par défaut aucun rôle : l'appelant doit dire explicitement ce qu'il
            // a le droit d'attribuer. Un défaut permissif (`cases()`) ferait
            // silencieusement réapparaître « Dirigeante » chez quiconque
            // oublierait l'option.
            'roles_attribuables' => [],
            // Création : le secret est obligatoire, le compte n'en a pas encore.
            // Modification : facultatif, un champ vide conserve celui en place —
            // corriger un nom ne doit pas réinitialiser un identifiant.
            'secret_requis' => true,
        ]);
        $resolver->setAllowedTypes('roles_attribuables', 'array');
        $resolver->setAllowedTypes('secret_requis', 'bool');
    }
}
