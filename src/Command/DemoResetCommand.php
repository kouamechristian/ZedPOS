<?php

namespace App\Command;

use App\Entity\Article;
use App\Entity\MouvementStock;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\RoleUtilisateur;
use App\Enum\TypeMouvementStock;
use App\Repository\ArticleRepository;
use App\Repository\MatierePremiereRepository;
use App\Repository\SessionCaisseRepository;
use App\Repository\UtilisateurRepository;
use App\Service\EncaissementService;
use App\Service\NotificateurDirigeante;
use App\Service\RapportCaisseService;
use App\Service\SessionCaisseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Remet la base dans l'état de départ d'une démonstration client.
 *
 * **Commande destructive** : elle vide la base et la reconstruit. Elle refuse de
 * s'exécuter en environnement de production et demande confirmation ailleurs.
 *
 * État produit :
 *   - 30 jours d'historique de ventes (via AppFixtures, tirage déterministe) ;
 *   - stock réapprovisionné à un niveau plausible, au-dessus des seuils ;
 *   - 2 caissiers, 1 gérant, 1 dirigeante ;
 *   - une journée en cours : la caisse de Fatou est ouverte, avec les ventes de
 *     la matinée déjà passées ;
 *   - **deux anomalies volontaires**, visibles sur le tableau de bord dirigeante :
 *     un ticket annulé après encaissement, et un écart de caisse de −2 500 FCFA
 *     sur la session déjà clôturée du second caissier.
 *
 * Voir DEMO.md pour le déroulé de la démonstration.
 */
#[AsCommand(
    name: 'app:demo:reset',
    description: 'Remet la base dans un état de démonstration propre et cohérent (DESTRUCTIF).',
)]
class DemoResetCommand extends Command
{
    /** Écart de caisse injecté, en centimes : −2 500 FCFA. */
    private const ECART_DEMO = -250000;

    /** Multiplicateur du seuil d'alerte utilisé pour réapprovisionner. */
    private const FACTEUR_REAPPRO = 4;

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly EntityManagerInterface $em,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly ArticleRepository $articles,
        private readonly MatierePremiereRepository $matieres,
        private readonly SessionCaisseRepository $sessions,
        private readonly SessionCaisseService $caisses,
        private readonly RapportCaisseService $rapports,
        private readonly EncaissementService $encaissement,
        private readonly NotificateurDirigeante $notificateur,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Passe outre la confirmation et le garde-fou de production (à vos risques).')
            ->addOption('garder-utilisateurs', null, InputOption::VALUE_NONE, 'Conserve les comptes existants au lieu de les remplacer par ceux de la démonstration.')
            ->setHelp(<<<'AIDE'
                Reconstruit une base de démonstration complète et cohérente.

                <comment>Cette commande VIDE la base de données.</comment> Elle demande confirmation,
                et refuse de s'exécuter en production sauf avec --force.

                  <info>php %command.full_name%</info>            Avec confirmation
                  <info>php %command.full_name% --force</info>    Sans confirmation (scripts)

                <comment>--garder-utilisateurs</comment> épargne la table `utilisateur` : les comptes en
                place sont conservés et l'historique leur est attribué. C'est ce qu'il faut
                pour garnir une base déjà installée sans perdre ses accès.

                Deux anomalies sont injectées volontairement pour la démonstration :
                un ticket annulé après encaissement, et un écart de caisse de −2 500 FCFA.
                Le déroulé complet est décrit dans <info>DEMO.md</info>.
                AIDE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        if ('prod' === $this->kernel->getEnvironment() && !$force) {
            $io->error('Refus d\'exécution en production. Utilisez --force si c\'est réellement voulu.');

            return Command::FAILURE;
        }

        $garderUtilisateurs = (bool) $input->getOption('garder-utilisateurs');

        $io->title('Préparation de la démonstration ZedPOS');
        $io->warning(\sprintf(
            $garderUtilisateurs
                ? 'Toutes les données de la base « %s » vont être effacées, sauf les comptes utilisateurs.'
                : 'Toutes les données de la base « %s » vont être effacées.',
            $this->em->getConnection()->getDatabase() ?? '?',
        ));

        // Sans --force, la confirmation est obligatoire : en mode non interactif
        // `confirm()` renvoie son défaut (non), et la commande ne détruit donc rien
        // par accident dans un script.
        if (!$force && !$io->confirm('Continuer ?', false)) {
            $io->comment('Annulé, rien n\'a été touché.');

            return Command::SUCCESS;
        }

        // La génération de 30 jours d'historique est gourmande ; on s'assure d'avoir
        // la marge nécessaire quelle que soit la configuration CLI de la machine.
        ini_set('memory_limit', '1G');

        if (Command::SUCCESS !== $this->chargerFixtures($io, $garderUtilisateurs)) {
            return Command::FAILURE;
        }

        [$premier, $second] = $this->caissiers();
        if (null === $premier) {
            $io->error('Aucun compte caissier après le chargement : impossible d\'attribuer une caisse.');

            return Command::FAILURE;
        }

        $io->section('Injection des anomalies de démonstration');

        $ticketAnnule = $this->injecterAnnulation($premier, $io);

        // Les deux anomalies veulent deux caisses distinctes : la première reste
        // ouverte avec son ticket annulé, la seconde est clôturée sur un manquant.
        // Sur une base à un seul caissier, clôturer la sienne fermerait la caisse
        // du jour et effacerait la première anomalie de l'écran.
        $ecart = null;
        if (null !== $second) {
            $ecart = $this->injecterEcartDeCaisse($second, $io);
        } else {
            $io->text('Un seul caissier : écart de caisse non injecté, sa caisse du jour reste ouverte.');
        }

        $io->section('Remise à niveau du stock');
        $io->text(\sprintf('%d matières premières réapprovisionnées.', $this->reapprovisionner()));

        $this->recapituler($io, $ticketAnnule, $ecart, $garderUtilisateurs);

        return Command::SUCCESS;
    }

    /**
     * Recharge AppFixtures (purge comprise). La sortie est capturée : les
     * avertissements de stock négatif sont attendus et brouilleraient l'écran.
     */
    private function chargerFixtures(SymfonyStyle $io, bool $garderUtilisateurs = false): int
    {
        $io->section('Chargement de 30 jours d\'historique');
        $io->text([
            'Patientez, la génération prend une trentaine de secondes…',
            'Des avertissements « Stock négatif » vont défiler : c\'est normal, 30 jours',
            'de ventes sont rejoués sans réapprovisionnement. Le stock est remis à',
            'niveau à la fin de cette commande.',
        ]);

        $tampon = new BufferedOutput();
        $arguments = ['--no-interaction' => true];

        if ($garderUtilisateurs) {
            // La table est épargnée par la purge ; AppFixtures détecte alors les
            // comptes en place et leur attribue l'historique au lieu d'en créer.
            $arguments['--purge-exclusions'] = ['utilisateur'];
        }

        $entree = new ArrayInput($arguments);
        $entree->setInteractive(false);

        $code = $this->getApplication()->find('doctrine:fixtures:load')->run($entree, $tampon);

        if (Command::SUCCESS !== $code) {
            $io->error('Le chargement des fixtures a échoué :');
            $io->writeln($tampon->fetch());

            return Command::FAILURE;
        }

        $this->em->clear();
        $io->text('Historique généré.');

        return Command::SUCCESS;
    }

    /**
     * Les deux premiers caissiers, **par rôle** et non par adresse e-mail.
     *
     * Les chercher par `fatou.traore@zedpos.ci` ne marchait que sur une base de
     * démonstration fraîche ; avec `--garder-utilisateurs`, ce sont les comptes
     * réels de l'établissement qui tiennent la caisse.
     *
     * @return array{0: ?Utilisateur, 1: ?Utilisateur}
     */
    private function caissiers(): array
    {
        $caissiers = array_values(array_filter(
            $this->utilisateurs->findBy([], ['id' => 'ASC']),
            static fn (Utilisateur $u): bool => \in_array(RoleUtilisateur::CAISSIER->value, $u->getRoles(), true),
        ));

        return [$caissiers[0] ?? null, $caissiers[1] ?? null];
    }

    /**
     * Anomalie n° 1 — un ticket encaissé puis annulé par le gérant.
     *
     * Passe par le vrai chemin métier : la vente est encaissée par l'API interne,
     * puis annulée par `EncaissementService`, ce qui produit la trace d'audit **et**
     * la notification à la dirigeante. Rien n'est fabriqué à la main en base.
     */
    private function injecterAnnulation(Utilisateur $fatou, SymfonyStyle $io): ?Vente
    {
        $session = $this->sessions->ouvertePour($fatou);
        if (null === $session) {
            $io->warning('Aucune session ouverte pour Fatou : annulation non injectée.');

            return null;
        }

        $article = $this->articleDeDemonstration();
        if (null === $article) {
            $io->warning('Aucun article actif : annulation non injectée.');

            return null;
        }

        $quantite = 4;
        $total = $quantite * $article->getPrixVenteTtc();
        $uuid = Uuid::v4();

        $resultat = $this->encaissement->encaisser($fatou, [
            'uuid' => (string) $uuid,
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $article->getId(), 'quantite' => $quantite]],
            'reglements' => [['mode' => 'ESPECES', 'montant' => $total]],
        ], 0);

        $gerant = $this->utilisateurs->findOneBy(['email' => 'koffi.nguessan@zedpos.ci']);
        $vente = $this->encaissement->annuler($uuid, 'Client s\'est ravisé après encaissement', $gerant);

        $io->text(\sprintf(
            'Ticket %s (%s FCFA) encaissé puis annulé — la dirigeante est notifiée.',
            $vente->getNumero(),
            number_format(intdiv($resultat->vente->getTotalTtc(), 100), 0, ',', ' '),
        ));

        return $vente;
    }

    /**
     * Anomalie n° 2 — la caisse du second caissier est clôturée avec un manquant
     * de 2 500 FCFA. Le théorique est calculé par le serveur ; on ne fabrique que
     * le montant compté, comme le ferait un caissier au comptage.
     */
    private function injecterEcartDeCaisse(Utilisateur $yao, SymfonyStyle $io): ?int
    {
        $session = $this->sessions->ouvertePour($yao);
        if (null === $session) {
            $io->warning('Aucune session ouverte pour Yao : écart non injecté.');

            return null;
        }

        $theorique = $this->rapports->theorique($session);
        $compte = max(0, $theorique + self::ECART_DEMO);

        $this->caisses->cloturer(
            $session,
            $compte,
            'Manquant constaté au comptage du soir, à revoir avec le caissier',
        );

        $io->text(\sprintf(
            'Caisse de %s clôturée : théorique %s FCFA, compté %s FCFA, écart %s FCFA.',
            $yao->getNom(),
            number_format(intdiv($theorique, 100), 0, ',', ' '),
            number_format(intdiv($compte, 100), 0, ',', ' '),
            number_format(intdiv($session->getEcart() ?? 0, 100), 0, ',', ' '),
        ));

        return $session->getEcart();
    }

    /**
     * Trente jours de ventes vident le stock jusqu'au négatif : on le remet à un
     * niveau crédible, au-dessus des seuils d'alerte, en traçant la régularisation
     * par un mouvement d'inventaire — un stock qui change sans mouvement ne serait
     * pas cohérent avec le reste de l'application.
     */
    private function reapprovisionner(): int
    {
        $horodatage = new \DateTimeImmutable('today 05:30');
        $nombre = 0;

        foreach ($this->matieres->findAll() as $matiere) {
            $seuil = $matiere->getStockMini();
            $cible = $seuil > 0 ? $seuil * self::FACTEUR_REAPPRO : 50000;
            $delta = $cible - $matiere->getStockActuel();

            if (0 === $delta) {
                continue;
            }

            $matiere->setStockActuel($cible);

            $mouvement = new MouvementStock(TypeMouvementStock::INVENTAIRE, $delta);
            $mouvement
                ->setMatierePremiere($matiere)
                ->setMotif('Régularisation d\'inventaire (préparation de la démonstration)')
                ->setSource('inventaire', null);
            (new \ReflectionProperty($mouvement, 'createdAt'))->setValue($mouvement, $horodatage);
            $this->em->persist($mouvement);

            ++$nombre;
        }

        $this->em->flush();

        return $nombre;
    }

    private function articleDeDemonstration(): ?Article
    {
        return $this->articles->findOneBy(['nom' => 'Baguette', 'actif' => true])
            ?? $this->articles->findOneBy(['actif' => true], ['positionCaisse' => 'ASC']);
    }

    private function recapituler(SymfonyStyle $io, ?Vente $ticketAnnule, ?int $ecart, bool $garderUtilisateurs): void
    {
        $io->success('Base de démonstration prête.');

        $io->section('Comptes');

        if ($garderUtilisateurs) {
            // Annoncer les identifiants de démonstration alors qu'ils n'ont pas été
            // créés enverrait l'exploitant se connecter avec des comptes qui
            // n'existent pas. On liste ceux qui sont réellement en base, sans
            // prétendre en connaître les mots de passe : ce sont les siens.
            $io->text('Comptes conservés — connectez-vous avec vos identifiants habituels.');
            $io->table(
                ['Rôle', 'Identifiant', 'Nom'],
                array_map(
                    static fn (Utilisateur $u): array => [
                        implode(', ', array_map(
                            static fn (string $role): string => RoleUtilisateur::tryFrom($role)?->libelle() ?? $role,
                            array_filter($u->getRoles(), static fn (string $r): bool => 'ROLE_USER' !== $r),
                        )),
                        $u->getEmail(),
                        $u->getNom(),
                    ],
                    $this->utilisateurs->findBy([], ['id' => 'ASC']),
                ),
            );

            return;
        }

        $io->table(
            ['Rôle', 'Identifiant', 'Secret', 'Atterrissage'],
            [
                ['Dirigeante', 'aya.kone@zedpos.ci', 'dirigeante123', '/pilotage'],
                ['Gérant', 'koffi.nguessan@zedpos.ci', 'gerant123', '/admin'],
                ['Caissière (caisse ouverte)', 'Fatou Traoré', 'PIN 1234', '/caisse'],
                ['Caissier (caisse clôturée)', 'Yao Kouassi', 'PIN 5678', '/caisse'],
            ],
        );

        $io->section('Anomalies à faire constater sur /pilotage');
        $io->listing([
            null !== $ticketAnnule
                ? \sprintf('Ticket %s annulé après encaissement (alerte rouge + bloc « Points de vigilance »)', $ticketAnnule->getNumero())
                : 'Annulation non injectée (voir avertissements ci-dessus)',
            null !== $ecart
                ? \sprintf('Écart de caisse de %s FCFA sur une session clôturée', number_format(intdiv($ecart, 100), 0, ',', ' '))
                : 'Écart non injecté (voir avertissements ci-dessus)',
        ]);

        $io->comment('Déroulé de la démonstration : voir DEMO.md');
    }
}
