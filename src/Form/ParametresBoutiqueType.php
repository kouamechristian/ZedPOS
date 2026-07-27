<?php

namespace App\Form;

use App\Enum\CleParametre;
use Symfony\Component\Form\AbstractType;
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
 */
class ParametresBoutiqueType extends AbstractType
{
    /** Traduit une clé de paramètre en nom de champ de formulaire, et l'inverse. */
    public static function champ(CleParametre $cle): string
    {
        return str_replace('.', '_', $cle->value);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (CleParametre::cases() as $cle) {
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
