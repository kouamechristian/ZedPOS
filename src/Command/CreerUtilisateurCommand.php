<?php

namespace App\Command;

use App\Enum\RoleUtilisateur;
use App\Service\CreationUtilisateur;
use App\Service\CreationUtilisateurException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:creer-utilisateur',
    description: 'Crée un compte utilisateur (mot de passe ou code PIN selon le rôle).',
)]
class CreerUtilisateurCommand extends Command
{
    public function __construct(
        private readonly CreationUtilisateur $creation,
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

        $secret = $role->utiliseCodePin()
            ? $input->getOption('code-pin') ?? $io->askHidden('Code PIN à 4 chiffres')
            : $input->getOption('mot-de-passe') ?? $io->askHidden('Mot de passe');

        if (!is_string($secret)) {
            $io->error($role->utiliseCodePin() ? 'Code PIN manquant.' : 'Mot de passe manquant.');

            return Command::INVALID;
        }

        // Unicité de l'e-mail et du PIN, hachage et trace d'audit : tout est porté
        // par le service, partagé avec /admin/utilisateurs/nouveau.
        try {
            $this->creation->creer($email, $nom, $role, $secret);
        } catch (CreationUtilisateurException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

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
}
