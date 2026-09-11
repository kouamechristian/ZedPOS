<?php

namespace App\Form;

use App\Enum\TypeDette;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Avance ou autre dette saisie à la main. Le manquant au point n'est **pas
 * proposé** : il ne naît que de la validation d'un point.
 */
class DetteManuelleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', EnumType::class, [
                'class' => TypeDette::class,
                'label' => 'Nature',
                'choices' => TypeDette::manuelles(),
                'choice_label' => static fn (TypeDette $t): string => $t->libelle(),
                'data' => TypeDette::AVANCE,
            ])
            ->add('montant', IntegerType::class, [
                'label' => 'Montant (FCFA)',
                'attr' => ['inputmode' => 'numeric', 'min' => 1],
                'constraints' => [
                    new Assert\NotNull(message: 'Saisissez le montant.'),
                    new Assert\Positive(message: 'Le montant doit être strictement positif.'),
                ],
            ])
            ->add('commentaire', TextareaType::class, [
                'label' => 'Objet',
                'attr' => ['rows' => 2, 'placeholder' => 'Avance sur commission de la semaine, ustensile cassé…'],
                'constraints' => [new Assert\NotBlank(message: 'Précisez l\'objet de la dette.'), new Assert\Length(max: 500)],
            ]);

        $builder->get('montant')->addModelTransformer(new CallbackTransformer(
            static fn (?int $centimes): ?int => null === $centimes ? null : intdiv($centimes, 100),
            static fn (?int $fcfa): ?int => null === $fcfa ? null : $fcfa * 100,
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
