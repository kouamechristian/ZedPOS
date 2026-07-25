<?php

namespace App\Command;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Repository\UtilisateurRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:creer-utilisateur',
    description: 'Crée un compte utilisateur (mot de passe ou code PIN selon le rôle).',
)]
class CreerUtilisateurCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PasswordHasherFactoryInterface $hasherFactory,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'Adresse e-mail (identifiant de connexion)')
            ->addArgument('nom', InputArgument::OPTIONAL, 'Nom de l\'utilisateur')
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED, 'Rôle : DIRIGEANTE, GERANT, COMPTABLE ou CAISSIER')
            ->addOption('mot-de-passe', 'p', InputOption::VALUE_REQUIRED, 'Mot de passe (rôles hors caissier)')
            ->addOption('code-pin', 'c', InputOption::VALUE_REQUIRED, 'Code PIN à 4 chiffres (caissier)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email') ?? $io->ask('Adresse e-mail');
        $nom = $input->getArgument('nom') ?? $io->ask('Nom');

        $role = $this->resoudreRole($input, $io);
        if (null === $role) {
            $io->error('Rôle invalide. Valeurs attendues : DIRIGEANTE, GERANT, COMPTABLE, CAISSIER.');

            return Command::INVALID;
        }

        if (!is_string($email) || '' === trim($email) || !is_string($nom) || '' === trim($nom)) {
            $io->error('L\'e-mail et le nom sont obligatoires.');

            return Command::INVALID;
        }

        if (null !== $this->utilisateurs->findOneBy(['email' => $email])) {
            $io->error(\sprintf('Un utilisateur existe déjà avec l\'e-mail "%s".', $email));

            return Command::FAILURE;
        }

        $utilisateur = new Utilisateur($email, $nom);
        $utilisateur->setRoles([$role->value]);

        if ($role->utiliseCodePin()) {
            $pin = $input->getOption('code-pin') ?? $io->askHidden('Code PIN à 4 chiffres');
            if (!is_string($pin) || 1 !== preg_match('/^\d{4}$/', $pin)) {
                $io->error('Le code PIN doit comporter exactement 4 chiffres.');

                return Command::INVALID;
            }
            if ($this->pinDejaUtilise($pin)) {
                $io->error('Ce code PIN est déjà utilisé par un autre caissier actif.');

                return Command::FAILURE;
            }
            $utilisateur->setCodePin($this->hasher->hashPassword($utilisateur, $pin));
        } else {
            $motDePasse = $input->getOption('mot-de-passe') ?? $io->askHidden('Mot de passe');
            if (!is_string($motDePasse) || \strlen($motDePasse) < 6) {
                $io->error('Le mot de passe doit comporter au moins 6 caractères.');

                return Command::INVALID;
            }
            $utilisateur->setMotDePasse($this->hasher->hashPassword($utilisateur, $motDePasse));
        }

        $this->em->persist($utilisateur);
        $this->em->flush();

        // Création en console : aucun auteur authentifié ni IP à rattacher.
        $this->audit->utilisateurCree($utilisateur);

        $io->success(\sprintf('Utilisateur "%s" (%s) créé.', $email, $role->libelle()));

        return Command::SUCCESS;
    }

    private function resoudreRole(InputInterface $input, SymfonyStyle $io): ?RoleUtilisateur
    {
        $saisie = $input->getOption('role');

        if (null === $saisie) {
            $choix = $io->choice(
                'Rôle',
                array_map(static fn (RoleUtilisateur $r): string => $r->name, RoleUtilisateur::cases()),
                RoleUtilisateur::CAISSIER->name,
            );
            $saisie = $choix;
        }

        $normalise = strtoupper(trim((string) $saisie));
        $normalise = str_starts_with($normalise, 'ROLE_') ? $normalise : 'ROLE_'.$normalise;

        return RoleUtilisateur::tryFrom($normalise);
    }

    private function pinDejaUtilise(string $pin): bool
    {
        $hasher = $this->hasherFactory->getPasswordHasher(Utilisateur::class);

        foreach ($this->utilisateurs->findActifsAvecCodePin() as $utilisateur) {
            $hash = $utilisateur->getCodePin();
            if (null !== $hash && $hasher->verify($hash, $pin)) {
                return true;
            }
        }

        return false;
    }
}
