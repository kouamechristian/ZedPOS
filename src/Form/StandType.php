<?php

namespace App\Form;

use App\Entity\Vendeur;
use App\Enum\ModeRemuneration;
use App\Enum\PeriodicitePoint;
use App\Repository\VendeurRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fiche d'un stand — un `Emplacement` de type STAND.
 *
 * Non lié à l'entité : un emplacement naît avec son code, son libellé et son type,
 * le contrôleur le construit. Le **code n'est saisi qu'à la création** : c'est
 * l'identité de l'emplacement, il figure sur les mouvements de stock.
 */
class StandType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['creation']) {
            $builder->add('code', TextType::class, [
                'label' => 'Code',
                'help' => 'Court et définitif, par ex. STAND-GARE. Il ne se modifie plus ensuite.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Le code est obligatoire.'),
                    new Assert\Length(max: 20),
                    new Assert\Regex(pattern: '/^[A-Za-z0-9_-]+$/', message: 'Lettres, chiffres, tiret et souligné seulement.'),
                ],
            ]);
        }

        $builder
            ->add('libelle', TextType::class, [
                'label' => 'Nom du stand',
                'constraints' => [new Assert\NotBlank(message: 'Le nom est obligatoire.'), new Assert\Length(max: 100)],
            ])
            ->add('periodicitePoint', EnumType::class, [
                'class' => PeriodicitePoint::class,
                'label' => 'Point avec le vendeur',
                'choice_label' => static fn (PeriodicitePoint $p): string => $p->libelle(),
                'help' => 'Proposé par défaut à l\'arrêté. Rien n\'empêche de faire le point plus tôt ou plus tard.',
            ])
            ->add('vendeurHabituel', EntityType::class, [
                'class' => Vendeur::class,
                'label' => 'Vendeur habituel',
                'choice_label' => 'nom',
                'placeholder' => '— Aucun —',
                'required' => false,
                'query_builder' => static fn (VendeurRepository $r) => $r->createQueryBuilder('v')->andWhere('v.actif = true')->orderBy('v.nom', 'ASC'),
                'help' => 'Proposé d\'office sur chaque nouveau bon de ce stand.',
            ])
            ->add('seuilEcartAlerte', IntegerType::class, [
                'label' => 'Écart toléré au point (FCFA)',
                'help' => 'Au-delà, la gérante doit justifier l\'écart d\'espèces. 0 : tout écart se justifie.',
                'constraints' => [new Assert\NotNull(), new Assert\PositiveOrZero()],
            ])
            ->add('actif', CheckboxType::class, ['label' => 'Actif', 'required' => false]);

        // Centimes en base, francs à l'écran.
        $builder->get('seuilEcartAlerte')->addModelTransformer(new CallbackTransformer(
            static fn (?int $centimes): ?int => null === $centimes ? null : intdiv($centimes, 100),
            static fn (?int $fcfa): int => (int) $fcfa * 100,
        ));

        // Rémunération : un prix, fixé par la dirigeante seule. Champs **absents**
        // sinon — un champ absent ne se soumet pas, même en forgeant la requête.
        if (!$options['fixer_remuneration']) {
            return;
        }

        $builder
            ->add('modeRemuneration', EnumType::class, [
                'class' => ModeRemuneration::class,
                'label' => 'Rémunération du vendeur',
                'choice_label' => static fn (ModeRemuneration $m): string => $m->libelle(),
            ])
            ->add('tauxCommission', TextType::class, [
                'label' => 'Taux de commission (%)',
                'help' => 'Pour le mode commission. Par ex. 7,5.',
                'constraints' => [new Assert\NotNull(message: 'Saisissez un taux, même 0.')],
            ]);

        // Points de base en base, pourcentage à deux décimales à l'écran — lu sans
        // flottant : « 7,5 » donne 750.
        $builder->get('tauxCommission')->addModelTransformer(new CallbackTransformer(
            static fn (?int $bp): ?string => null === $bp ? null : intdiv($bp, 100).($bp % 100 ? ','.rtrim(\sprintf('%02d', $bp % 100), '0') : ''),
            static function (?string $saisie): ?int {
                $saisie = trim((string) $saisie);
                if ('' === $saisie) {
                    return null;
                }
                if (1 !== preg_match('/^(\d{1,3})(?:[.,](\d{1,2}))?$/', $saisie, $parties) || (int) $parties[1] > 100) {
                    throw new TransformationFailedException('Taux invalide : un pourcentage entre 0 et 100, deux décimales au plus.');
                }

                $bp = (int) $parties[1] * 100 + (int) str_pad($parties[2] ?? '0', 2, '0');
                if ($bp > 10000) {
                    throw new TransformationFailedException('Le taux ne peut pas dépasser 100 %.');
                }

                return $bp;
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => null, 'creation' => false, 'fixer_remuneration' => false])
            ->setAllowedTypes('creation', 'bool')
            ->setAllowedTypes('fixer_remuneration', 'bool');
    }
}
