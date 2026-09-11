<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changement de **son propre** code PIN (caissier).
 *
 * Trois champs de quatre chiffres, remplis au pavé tactile
 * (`pave_pin_controller.js`) : `inputmode="none"`, le clavier du système
 * recouvrirait le pavé sur la tablette du comptoir.
 *
 * Le nouveau code est saisi deux fois : une caissière qui se trompe d'un chiffre
 * resterait à la porte au prochain changement d'équipe, gérant absent.
 */
class ChangerCodePinType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $pin = static fn (string $label, array $attr = []): array => [
            'label' => $label,
            'attr' => $attr + [
                'inputmode' => 'none',
                'maxlength' => 4,
                'autocomplete' => 'off',
                'data-pave-pin-target' => 'champ',
                'data-action' => 'focus->pave-pin#activer input->pave-pin#saisir',
            ],
        ];

        $quatreChiffres = new Assert\Regex(pattern: '/^\d{4}$/', message: 'Le code PIN doit comporter exactement 4 chiffres.');

        $builder
            ->add('actuel', PasswordType::class, $pin('Code PIN actuel', ['autofocus' => true]) + [
                'constraints' => [new Assert\NotBlank(message: 'Saisissez votre code PIN actuel.'), $quatreChiffres],
            ])
            ->add('nouveau', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'Les deux nouveaux codes PIN ne correspondent pas.',
                // Erreurs renvoyées sur le premier champ : le gabarit ne rend pas
                // le parent, elles ne s'afficheraient pas.
                'error_mapping' => ['.' => 'first'],
                'first_options' => $pin('Nouveau code PIN'),
                'second_options' => $pin('Confirmez le nouveau code PIN'),
                'constraints' => [new Assert\NotBlank(message: 'Saisissez le nouveau code PIN.'), $quatreChiffres],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
