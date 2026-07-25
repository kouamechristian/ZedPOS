<?php

namespace App\Form;

use App\Entity\Article;
use App\Entity\MatierePremiere;
use App\Enum\MotifPerte;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Saisie rapide d'une perte (non liée à une entité : la Perte est construite par
 * le service à partir du tableau renvoyé).
 */
class PerteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('matierePremiere', EntityType::class, [
                'class' => MatierePremiere::class,
                'choice_label' => 'nom',
                'label' => 'Matière première',
                'placeholder' => '— Aucune —',
                'required' => false,
            ])
            ->add('article', EntityType::class, [
                'class' => Article::class,
                'choice_label' => 'nom',
                'label' => 'Article',
                'placeholder' => '— Aucun —',
                'required' => false,
            ])
            ->add('quantite', NumberType::class, ['label' => 'Quantité', 'scale' => 3])
            ->add('motif', EnumType::class, [
                'class' => MotifPerte::class,
                'label' => 'Motif',
                'choice_label' => fn (MotifPerte $motif): string => $motif->libelle(),
                'placeholder' => '— Choisir —',
            ])
            ->add('commentaire', TextareaType::class, ['label' => 'Commentaire', 'required' => false]);

        // Quantité saisie en unités, convertie en millièmes.
        $builder->get('quantite')->addModelTransformer(new CallbackTransformer(
            static fn (int|float|null $m): ?float => null === $m ? null : (float) $m / 1000,
            static fn (int|float|null $u): int => (int) round(((float) $u) * 1000),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
