<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Dépôt du fichier d'import du catalogue (nom, prix de vente).
 *
 * Un seul champ : le fichier. Le format, les doublons et les prix illisibles sont
 * l'affaire de {@see \App\Service\ImportArticles}, qui rend un compte rendu ligne
 * par ligne — un contrôle de formulaire ne saurait dire « la ligne 12 a un prix à
 * virgule », et c'est pourtant la seule chose utile à savoir.
 */
class ImportArticlesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('fichier', FileType::class, [
            'label' => 'Fichier CSV',
            'help' => 'Deux colonnes : le nom de l\'article, puis son prix de vente en FCFA.',
            'required' => true,
            'constraints' => [
                new Assert\NotNull(message: 'Choisissez un fichier à importer.'),
                new Assert\File(
                    maxSize: '2M',
                    // Liste large **volontairement** : selon le poste et le tableur
                    // d'origine, un même .csv est annoncé text/csv, text/plain ou
                    // application/vnd.ms-excel. Refuser sur ce critère rejetterait
                    // des fichiers parfaitement valables, et le message serait
                    // incompréhensible. C'est la lecture du contenu qui tranche.
                    mimeTypes: [
                        'text/csv',
                        'text/plain',
                        'application/csv',
                        'application/vnd.ms-excel',
                        'application/octet-stream',
                    ],
                    mimeTypesMessage: 'Attendu un fichier texte CSV. Depuis Excel : « Enregistrer sous » → CSV.',
                    maxSizeMessage: 'Le fichier ne doit pas dépasser {{ limit }} {{ suffix }}.',
                ),
            ],
            'attr' => ['accept' => '.csv,.txt,text/csv,text/plain'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
