<?php

namespace App\Form;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulaire d'article.
 *
 * Le champ **prix de vente n'est ajouté que si l'utilisateur est habilité**
 * (`Permission::ARTICLE_MODIFIER_PRIX`, dirigeante uniquement). Ne pas le déclarer
 * est plus sûr que le désactiver : un champ absent du formulaire ne peut pas être
 * soumis, même en forgeant la requête.
 */
class ArticleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, ['label' => 'Nom'])
            ->add('familleProduit', EntityType::class, [
                'class' => FamilleProduit::class,
                'choice_label' => 'nom',
                'label' => 'Famille',
                'placeholder' => '— Aucune —',
                'required' => false,
            ])
            ->add('unite', TextType::class, ['label' => 'Unité (pièce, portion…)'])
            ->add('tauxTva', ChoiceType::class, [
                'label' => 'TVA',
                'choices' => ['Exonéré (0 %)' => 0, '18 %' => 1800],
            ])
            ->add('couleur', ColorType::class, ['label' => 'Couleur touche', 'required' => false])
            // Champ **non mappé** : l'entité ne porte qu'un nom de fichier, le
            // dépôt sur disque appartient à App\Service\ImageArticle.
            ->add('imageFichier', FileType::class, [
                'label' => 'Photo de la touche',
                'mapped' => false,
                'required' => false,
                'help' => 'JPEG, PNG ou WebP. L\'image est réduite automatiquement.',
                'constraints' => [
                    new Assert\Image(
                        maxSize: '5M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        mimeTypesMessage: 'Choisissez une image JPEG, PNG ou WebP.',
                        maxSizeMessage: 'L\'image ne doit pas dépasser {{ limit }} {{ suffix }}.',
                    ),
                ],
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp'],
            ])
            // Retirer une photo est une action à part : téléverser un fichier vide
            // ne veut rien dire, et sans case dédiée on ne pourrait jamais revenir
            // à la touche colorée.
            ->add('supprimerImage', CheckboxType::class, [
                'label' => 'Retirer la photo',
                'mapped' => false,
                'required' => false,
            ])
            ->add('positionCaisse', IntegerType::class, ['label' => 'Position dans la caisse'])
            ->add('actif', CheckboxType::class, ['label' => 'Actif', 'required' => false]);

        if (!$options['modifier_prix']) {
            return;
        }

        $builder->add('prixVenteTtc', IntegerType::class, [
            'label' => 'Prix de vente TTC (FCFA)',
        ]);

        // Le prix est stocké en centimes de FCFA ; l'utilisateur saisit des FCFA.
        $builder->get('prixVenteTtc')->addModelTransformer(new CallbackTransformer(
            static fn (?int $centimes): ?int => null === $centimes ? null : intdiv($centimes, 100),
            static fn (?int $fcfa): int => (int) $fcfa * 100,
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => Article::class, 'modifier_prix' => false])
            ->setAllowedTypes('modifier_prix', 'bool');
    }
}
