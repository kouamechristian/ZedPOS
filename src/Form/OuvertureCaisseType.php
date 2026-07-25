<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ouverture de session : saisie du fond de caisse en FCFA (converti en centimes).
 */
class OuvertureCaisseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('fondCaisse', IntegerType::class, [
            'label' => 'Fond de caisse (FCFA)',
            'attr' => ['autofocus' => true, 'inputmode' => 'numeric', 'min' => 0],
            'constraints' => [
                new Assert\NotNull(message: 'Saisissez le fond de caisse.'),
                new Assert\GreaterThanOrEqual(value: 0, message: 'Le fond de caisse ne peut pas être négatif.'),
            ],
        ]);

        // FCFA saisis → centimes persistés (jamais de float pour l'argent).
        $builder->get('fondCaisse')->addModelTransformer(new CallbackTransformer(
            static fn (?int $centimes): ?int => null === $centimes ? null : intdiv($centimes, 100),
            static fn (?int $fcfa): ?int => null === $fcfa ? null : $fcfa * 100,
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
