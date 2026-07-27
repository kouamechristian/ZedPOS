<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Premier compte de l'application : la dirigeante.
 *
 * Pas de champ « rôle » : cet écran ne sert qu'une fois et ne crée qu'une chose.
 * Proposer un choix laisserait installer une caisse sans personne au-dessus
 * d'elle, et il n'y aurait alors plus aucun moyen de créer la dirigeante.
 *
 * **Le mot de passe est saisi deux fois.** C'est le seul du système : une faute de
 * frappe sur celui-là et l'installation est perdue, sans second compte pour la
 * rattraper. Ailleurs dans l'application la confirmation ne se justifie pas — la
 * dirigeante peut toujours réinitialiser un mot de passe oublié.
 */
class InstallationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Votre nom',
                'constraints' => [
                    new Assert\NotBlank(message: 'Le nom est obligatoire.'),
                    new Assert\Length(max: 120),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'help' => 'Elle vous servira d\'identifiant de connexion.',
                'constraints' => [
                    new Assert\NotBlank(message: 'L\'e-mail est obligatoire.'),
                    new Assert\Email(message: 'Cette adresse e-mail n\'est pas valide.'),
                    new Assert\Length(max: 180),
                ],
            ])
            ->add('motDePasse', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
                'first_options' => [
                    'label' => 'Mot de passe',
                    'help' => '6 caractères minimum.',
                    'always_empty' => true,
                ],
                'second_options' => [
                    'label' => 'Confirmez le mot de passe',
                    'always_empty' => true,
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'Le mot de passe est obligatoire.'),
                    new Assert\Length(min: 6, minMessage: 'Le mot de passe doit comporter au moins {{ limit }} caractères.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Formulaire non lié à l'entité : le hachage et la trace d'audit
        // appartiennent à App\Service\CreationUtilisateur.
        $resolver->setDefaults(['data_class' => null]);
    }
}
