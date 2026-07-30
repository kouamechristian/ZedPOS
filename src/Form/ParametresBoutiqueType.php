<?php

namespace App\Form;

use App\Enum\CleParametre;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulaire des paramètres de l'établissement, **engendré depuis le catalogue**
 * {@see CleParametre} : ajouter un paramètre à l'énumération l'ajoute ici, sans
 * toucher au formulaire ni au gabarit.
 *
 * Les noms de champ sont les clés persistées, avec les points remplacés par des
 * underscores (un nom de champ HTML ne peut pas contenir de point).
 *
 * **Seule exception : les paramètres-fichier** ({@see CleParametre::estFichier()}).
 * Leur valeur persistée est un nom de fichier sur le disque, qui ne se saisit pas
 * au clavier ; ils sont donc servis par un couple « téléverser / retirer », sur le
 * modèle de la photo de touche produit.
 */
class ParametresBoutiqueType extends AbstractType
{
    /** Champ de téléversement du logo. Non mappé : la valeur persistée est un nom. */
    public const CHAMP_LOGO = 'logo_fichier';

    /** Case de retrait du logo. */
    public const CHAMP_LOGO_RETRAIT = 'logo_retirer';

    /** Traduit une clé de paramètre en nom de champ de formulaire, et l'inverse. */
    public static function champ(CleParametre $cle): string
    {
        return str_replace('.', '_', $cle->value);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (CleParametre::cases() as $cle) {
            if ($cle->estFichier()) {
                continue;
            }

            $builder->add(self::champ($cle), $cle->estLong() ? TextareaType::class : TextType::class, [
                'label' => $cle->libelle(),
                'help' => $cle->aide(),
                'required' => CleParametre::RAISON_SOCIALE === $cle,
                'attr' => $cle->estLong() ? ['rows' => 2] : [],
                'constraints' => CleParametre::RAISON_SOCIALE === $cle
                    ? [new Assert\NotBlank(message: 'La raison sociale est imprimée sur chaque ticket : elle est obligatoire.')]
                    : [],
            ]);
        }

        // Champs **non mappés** : la table ne garde qu'un nom de fichier, le dépôt
        // sur disque appartient à App\Service\LogoBoutique.
        $builder
            ->add(self::CHAMP_LOGO, FileType::class, [
                'label' => CleParametre::LOGO->libelle(),
                'help' => CleParametre::LOGO->aide(),
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new Assert\Image(
                        maxSize: '5M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        mimeTypesMessage: 'Choisissez une image JPEG, PNG ou WebP.',
                        maxSizeMessage: 'Le logo ne doit pas dépasser {{ limit }} {{ suffix }}.',
                    ),
                ],
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp'],
            ])
            // Retirer le logo est une action à part : téléverser un fichier vide ne
            // veut rien dire, et sans case dédiée on ne pourrait jamais revenir à
            // un ticket sans logo.
            ->add(self::CHAMP_LOGO_RETRAIT, CheckboxType::class, [
                'label' => 'Retirer le logo',
                'mapped' => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
