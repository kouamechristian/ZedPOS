<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Clôture Z : le caissier saisit uniquement le montant physiquement compté.
 * Le théorique et l'écart sont calculés par le serveur, jamais saisis.
 *
 * Le caractère obligatoire du commentaire en cas d'écart est une règle métier
 * portée par SessionCaisse::cloturer() — non contournable depuis le formulaire.
 */
class ClotureCaisseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('montantCompte', IntegerType::class, [
                'label' => 'Montant physiquement compté (FCFA)',
                'attr' => ['autofocus' => true, 'inputmode' => 'numeric', 'min' => 0],
                'constraints' => [
                    new Assert\NotNull(message: 'Saisissez le montant compté dans le tiroir.'),
                    new Assert\GreaterThanOrEqual(value: 0, message: 'Le montant compté ne peut pas être négatif.'),
                ],
            ])
            ->add('commentaire', TextareaType::class, [
                'label' => 'Commentaire (obligatoire en cas d\'écart)',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'Explication de l\'écart constaté…'],
            ]);

        $builder->get('montantCompte')->addModelTransformer(new CallbackTransformer(
            static fn (?int $centimes): ?int => null === $centimes ? null : intdiv($centimes, 100),
            static fn (?int $fcfa): ?int => null === $fcfa ? null : $fcfa * 100,
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
