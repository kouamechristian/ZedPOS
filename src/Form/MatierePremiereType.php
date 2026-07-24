<?php

namespace App\Form;

use App\Entity\Fournisseur;
use App\Entity\MatierePremiere;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MatierePremiereType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, ['label' => 'Nom'])
            ->add('uniteStock', TextType::class, ['label' => 'Unité de stock (kg, litre, pièce…)'])
            ->add('coutMoyenPondere', IntegerType::class, ['label' => "Coût moyen pondéré (FCFA / unité)"])
            ->add('stockActuel', NumberType::class, ['label' => 'Stock actuel', 'scale' => 3])
            ->add('stockMini', NumberType::class, ['label' => "Seuil d'alerte", 'scale' => 3])
            ->add('fournisseur', EntityType::class, [
                'class' => Fournisseur::class,
                'choice_label' => 'nom',
                'label' => 'Fournisseur',
                'placeholder' => '— Aucun —',
                'required' => false,
            ]);

        // Coût stocké en centimes ; saisie en FCFA.
        $builder->get('coutMoyenPondere')->addModelTransformer(new CallbackTransformer(
            static fn (?int $centimes): ?int => null === $centimes ? null : intdiv($centimes, 100),
            static fn (?int $fcfa): int => (int) $fcfa * 100,
        ));

        // Stock stocké en millièmes d'unité ; saisie en unités.
        foreach (['stockActuel', 'stockMini'] as $champ) {
            $builder->get($champ)->addModelTransformer(new CallbackTransformer(
                static fn (?int $millimes): ?float => null === $millimes ? null : $millimes / 1000,
                static fn (int|float|null $unites): int => (int) round(((float) $unites) * 1000),
            ));
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MatierePremiere::class]);
    }
}
