<?php

namespace App\Form;

use App\Enum\CategorieDepense;
use App\Enum\TypeMouvementCaisse;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Saisie d'une dépense de caisse (catégorie, montant, commentaire) ou d'une
 * sortie d'espèces. La catégorie n'est exigée que pour une dépense — règle
 * portée par l'entité MouvementCaisse.
 */
class MouvementCaisseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', EnumType::class, [
                'class' => TypeMouvementCaisse::class,
                'label' => 'Nature',
                'choice_label' => fn (TypeMouvementCaisse $type): string => $type->libelle(),
                'data' => TypeMouvementCaisse::DEPENSE,
            ])
            ->add('categorie', EnumType::class, [
                'class' => CategorieDepense::class,
                'label' => 'Catégorie',
                'choice_label' => fn (CategorieDepense $categorie): string => $categorie->libelle(),
                'placeholder' => '— Choisir —',
                'required' => false,
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
                'label' => 'Commentaire',
                'required' => false,
                'attr' => ['rows' => 2, 'placeholder' => 'Objet de la dépense, bénéficiaire…'],
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
