<?php

namespace App\Form;

use App\Enum\ModeReglement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Versement d'un vendeur sur ses dettes. Montant en francs, converti en centimes ;
 * la répartition (les plus anciennes d'abord) et le plafond au solde sont tranchés
 * par {@see \App\Service\DetteService::rembourser()}.
 *
 * Le crédit n'est pas proposé : on ne rembourse pas une dette par une autre.
 */
class RemboursementDetteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('montant', IntegerType::class, [
                'label' => 'Montant versé (FCFA)',
                'attr' => ['inputmode' => 'numeric', 'min' => 1],
                'constraints' => [
                    new Assert\NotNull(message: 'Saisissez le montant versé.'),
                    new Assert\Positive(message: 'Le montant doit être strictement positif.'),
                ],
            ])
            ->add('moyen', EnumType::class, [
                'class' => ModeReglement::class,
                'label' => 'Moyen',
                'choices' => array_values(array_filter(ModeReglement::cases(), static fn (ModeReglement $m): bool => ModeReglement::CREDIT !== $m)),
                'choice_label' => static fn (ModeReglement $m): string => $m->libelle(),
                'data' => ModeReglement::ESPECES,
            ])
            ->add('date', DateType::class, [
                'label' => 'Date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'data' => new \DateTimeImmutable('today'),
                'constraints' => [
                    new Assert\NotNull(message: 'Saisissez la date du versement.'),
                    new Assert\LessThanOrEqual(value: 'today', message: 'La date ne peut pas être dans le futur.'),
                ],
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
