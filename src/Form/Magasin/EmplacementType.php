<?php

namespace App\Form\Magasin;

use App\Entity\Magasin\MagasinEmplacement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Zone de rangement du magasin.
 *
 * Le **code n'est saisi qu'à la création** (option `avec_code`) : c'est
 * l'identité de la zone, il ne change plus — absent du formulaire de
 * modification, donc non soumettable.
 */
class EmplacementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['avec_code']) {
            $builder->add('code', TextType::class, [
                'label' => 'Code',
                'help' => 'Court et définitif : FROID, ETAGERE-A… Il ne se modifie plus ensuite.',
                'mapped' => false,
                'constraints' => [
                    new NotBlank(message: 'Le code est obligatoire.'),
                    new Length(max: 20),
                    new Regex('/^[A-Za-z0-9_-]+$/', message: 'Lettres, chiffres, tiret et souligné seulement.'),
                ],
            ]);
        }

        $builder
            ->add('libelle', TextType::class, [
                'label' => 'Libellé',
                'empty_data' => '',
                'constraints' => [new NotBlank(message: 'Le libellé est obligatoire.'), new Length(max: 100)],
            ])
            ->add('actif', CheckboxType::class, [
                'label' => 'Actif',
                'help' => 'Une zone désactivée ne reçoit plus de marchandise.',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MagasinEmplacement::class,
            'avec_code' => false,
            'empty_data' => static fn (): MagasinEmplacement => new MagasinEmplacement('', ''),
        ]);
        $resolver->setAllowedTypes('avec_code', 'bool');
    }
}
