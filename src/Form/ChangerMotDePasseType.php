<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changement de **son propre** mot de passe (dirigeante, gérant, comptable).
 *
 * Le secret actuel est exigé, et vérifié par {@see \App\Service\CreationUtilisateur}
 * — pas ici : c'est là que vivent le hachage et la limite d'essais.
 *
 * **Le nouveau est saisi deux fois.** Une faute de frappe ferme la porte à celui
 * qui la commet, et la dirigeante n'a personne au-dessus d'elle pour la lui
 * rouvrir : un gérant n'agit pas sur son compte.
 */
class ChangerMotDePasseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('actuel', PasswordType::class, [
                'label' => 'Mot de passe actuel',
                'attr' => ['autocomplete' => 'current-password', 'autofocus' => true],
                'constraints' => [new Assert\NotBlank(message: 'Saisissez votre mot de passe actuel.')],
            ])
            ->add('nouveau', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'Les deux nouveaux mots de passe ne correspondent pas.',
                // Les erreurs d'un RepeatedType restent sur le parent, que le
                // gabarit ne rend pas : sans ce renvoi, « ne correspondent pas »
                // ne s'afficherait nulle part et le formulaire paraîtrait figé.
                'error_mapping' => ['.' => 'first'],
                'first_options' => [
                    'label' => 'Nouveau mot de passe',
                    'help' => '6 caractères minimum.',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'second_options' => [
                    'label' => 'Confirmez le nouveau mot de passe',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'Saisissez le nouveau mot de passe.'),
                    new Assert\Length(min: 6, minMessage: 'Le mot de passe doit comporter au moins {{ limit }} caractères.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
