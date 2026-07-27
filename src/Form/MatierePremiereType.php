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

/**
 * Fiche d'une matière première.
 *
 * **`stockActuel` n'y figure qu'à la création**, pour saisir le stock de départ.
 * Passée cette étape, un stock ne se corrige plus à la main : il se compte, par
 * l'inventaire (`/admin/inventaires`), qui produit un `MouvementStock` et une
 * trace d'audit. Écrire directement dans le champ ne faisait ni l'un ni l'autre,
 * et l'historique des mouvements divergeait alors du stock affiché sans que rien
 * ne le signale.
 *
 * Le champ est **absent** du formulaire de modification, pas désactivé : un champ
 * absent ne peut pas être soumis, même en forgeant la requête.
 */
class MatierePremiereType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, ['label' => 'Nom'])
            ->add('uniteStock', TextType::class, ['label' => 'Unité de stock (kg, litre, pièce…)'])
            ->add('coutMoyenPondere', IntegerType::class, ['label' => "Coût moyen pondéré (FCFA / unité)"])
            ->add('stockMini', NumberType::class, ['label' => "Seuil d'alerte", 'scale' => 3])
            ->add('fournisseur', EntityType::class, [
                'class' => Fournisseur::class,
                'choice_label' => 'nom',
                'label' => 'Fournisseur',
                'placeholder' => '— Aucun —',
                'required' => false,
            ]);

        if ($options['stock_initial']) {
            $builder->add('stockActuel', NumberType::class, [
                'label' => 'Stock de départ',
                'help' => 'Ensuite, le stock ne se corrige que par un inventaire.',
                'scale' => 3,
            ]);
        }

        // Coût stocké en centimes ; saisie en FCFA.
        $builder->get('coutMoyenPondere')->addModelTransformer(new CallbackTransformer(
            static fn (?int $centimes): ?int => null === $centimes ? null : intdiv($centimes, 100),
            static fn (?int $fcfa): int => (int) $fcfa * 100,
        ));

        // Stock stocké en millièmes d'unité ; saisie en unités.
        $champs = $options['stock_initial'] ? ['stockActuel', 'stockMini'] : ['stockMini'];
        foreach ($champs as $champ) {
            $builder->get($champ)->addModelTransformer(new CallbackTransformer(
                static fn (?int $millimes): ?float => null === $millimes ? null : $millimes / 1000,
                static fn (int|float|null $unites): int => (int) round(((float) $unites) * 1000),
            ));
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MatierePremiere::class,
            // Vrai à la création seulement : voir le commentaire de classe.
            'stock_initial' => false,
        ]);
        $resolver->setAllowedTypes('stock_initial', 'bool');
    }
}
