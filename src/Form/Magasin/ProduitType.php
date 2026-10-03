<?php

namespace App\Form\Magasin;

use App\Entity\Fournisseur;
use App\Entity\Magasin\MagasinProduit;
use App\Enum\Magasin\CategorieProduitMagasin;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Fiche d'un produit du magasin.
 *
 * **Pas de stock ni de coût moyen ici.** Le stock ne bouge que par les bons du
 * module (le premier inventaire sert de stock de départ) ; le coût moyen découle
 * des prix saisis à la réception. Les deux champs sont absents, pas désactivés :
 * un champ absent ne peut pas être soumis, même en forgeant la requête.
 *
 * Unité d'achat et contenance vont ensemble — l'une sans l'autre ne convertit
 * rien. La contenance se saisit dans l'unité de stock (50 pour un sac de 50 kg).
 */
class ProduitType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // `empty_data` à chaîne vide : un champ vidé arrive vide au contrôle
            // NotBlank (422), et non nul au setter typé `string` (500).
            ->add('nom', TextType::class, [
                'label' => 'Nom',
                'empty_data' => '',
                'constraints' => [new NotBlank(message: 'Le nom est obligatoire.'), new Length(max: 150)],
            ])
            ->add('categorie', EnumType::class, [
                'class' => CategorieProduitMagasin::class,
                'label' => 'Catégorie',
                'choice_label' => static fn (CategorieProduitMagasin $c): string => $c->libelle(),
            ])
            ->add('uniteStock', TextType::class, [
                'label' => 'Unité de stock (kg, L, pièce…)',
                'empty_data' => '',
                'constraints' => [new NotBlank(message: 'L\'unité de stock est obligatoire.'), new Length(max: 20)],
            ])
            ->add('uniteAchat', TextType::class, [
                'label' => 'Unité d\'achat (sac, plaquette, carton…)',
                'help' => 'Facultative. Laissez vide si le produit s\'achète dans son unité de stock.',
                'required' => false,
                'constraints' => [new Length(max: 30)],
            ])
            ->add('contenanceAchat', TextType::class, [
                'label' => 'Contenance d\'une unité d\'achat',
                'help' => 'Dans l\'unité de stock : 50 pour un sac de 50 kg, 30 pour une plaquette de 30 œufs.',
                'required' => false,
                'attr' => ['inputmode' => 'decimal'],
            ])
            ->add('seuilAlerte', TextType::class, [
                'label' => 'Seuil d\'alerte (unité de stock)',
                'help' => 'En dessous, le produit est signalé dans la liste du stock.',
                'required' => false,
                'empty_data' => '0',
                'attr' => ['inputmode' => 'decimal'],
            ])
            ->add('fournisseurHabituel', EntityType::class, [
                'class' => Fournisseur::class,
                'choice_label' => 'nom',
                'label' => 'Fournisseur habituel',
                'placeholder' => '— Aucun —',
                'required' => false,
            ])
            ->add('actif', CheckboxType::class, [
                'label' => 'Actif',
                'required' => false,
            ]);

        $builder->get('contenanceAchat')->addModelTransformer(new MillimesTransformer());
        $builder->get('seuilAlerte')->addModelTransformer(new MillimesTransformer());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MagasinProduit::class,
            'empty_data' => static fn (): MagasinProduit => new MagasinProduit('', CategorieProduitMagasin::MATIERE, ''),
            'constraints' => [new Callback(self::verifierUniteAchat(...))],
        ]);
    }

    /** Nom et contenance de l'unité d'achat vont ensemble, et la contenance est positive. */
    public static function verifierUniteAchat(MagasinProduit $produit, ExecutionContextInterface $contexte): void
    {
        $unite = $produit->getUniteAchat();
        $contenance = $produit->getContenanceAchat();

        if (null !== $unite && (null === $contenance || $contenance <= 0)) {
            $contexte->buildViolation('Indiquez ce que contient une unité d\'achat (par exemple 50 pour un sac de 50 kg).')
                ->atPath('contenanceAchat')
                ->addViolation();
        } elseif (null === $unite && null !== $contenance) {
            $contexte->buildViolation('Nommez l\'unité d\'achat (sac, plaquette…), ou videz la contenance.')
                ->atPath('uniteAchat')
                ->addViolation();
        }
    }
}
