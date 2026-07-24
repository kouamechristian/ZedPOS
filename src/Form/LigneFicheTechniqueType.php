<?php

namespace App\Form;

use App\Entity\MatierePremiere;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'ajout d'une ligne de fiche technique (non lié à une entité :
 * la LigneFicheTechnique est construite dans le contrôleur).
 */
class LigneFicheTechniqueType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('matierePremiere', EntityType::class, [
                'class' => MatierePremiere::class,
                'choice_label' => 'nom',
                'label' => 'Matière première',
                'placeholder' => '— Choisir —',
            ])
            ->add('quantite', NumberType::class, ['label' => 'Quantité (unité de stock)', 'scale' => 3])
            ->add('pourcentagePerte', NumberType::class, ['label' => 'Perte (%)', 'scale' => 2, 'required' => false]);

        // Quantité en millièmes d'unité.
        $builder->get('quantite')->addModelTransformer(new CallbackTransformer(
            static fn (int|float|null $m): ?float => null === $m ? null : (float) $m / 1000,
            static fn (int|float|null $u): int => (int) round(((float) $u) * 1000),
        ));

        // Perte stockée en points de base (5 % => 500).
        $builder->get('pourcentagePerte')->addModelTransformer(new CallbackTransformer(
            static fn (int|float|null $bp): float => null === $bp ? 0.0 : (float) $bp / 100,
            static fn (int|float|null $p): int => (int) round(((float) $p) * 100),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Pas de data_class : le formulaire renvoie un tableau associatif.
        $resolver->setDefaults(['data_class' => null]);
    }
}
