<?php

namespace App\Form;

use App\Comptabilite\PlanComptable;
use App\Entity\FamilleProduit;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FamilleProduitType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $comptes = [];
        foreach (PlanComptable::comptesDeVente() as $compte) {
            $comptes[$compte->value.' — '.$compte->libelle()] = $compte->value;
        }

        $builder
            ->add('nom', TextType::class, ['label' => 'Nom'])
            ->add('couleur', ColorType::class, ['label' => 'Couleur', 'required' => false])
            ->add('position', IntegerType::class, ['label' => 'Position dans la caisse'])
            ->add('actif', CheckboxType::class, ['label' => 'Active', 'required' => false])
            ->add('compteVente', ChoiceType::class, [
                'label' => 'Compte de vente (SYSCOHADA)',
                'required' => false,
                'choices' => $comptes,
                'placeholder' => 'Automatique (selon la nature de l\'article)',
                'help' => 'Compte crédité par les exports comptables. À laisser sur « automatique » '
                    .'sauf consigne du cabinet comptable : les articles avec fiche technique sont '
                    .'alors traités en produits finis, les autres en marchandises.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => FamilleProduit::class]);
    }
}
