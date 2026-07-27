<?php

namespace App\Tests\Functional;

use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:demo:reset --garder-utilisateurs` — garnir une base **déjà installée**.
 *
 * Le cas d'usage : on a créé ses comptes par l'écran d'installation, et on veut
 * du contenu pour travailler sans perdre ses accès. La commande normale les
 * remplacerait par les cinq comptes de démonstration.
 *
 * Deux pièges se croisent ici et justifient ce fichier séparé :
 *   - `AppFixtures` crée un gérant à `koffi.nguessan@zedpos.ci` ; si un compte
 *     réel porte déjà cette adresse, le chargement échoue sur l'unicité ;
 *   - l'historique doit être attribué aux **caissiers en place**, pas à des
 *     comptes de démonstration qui ne seront jamais créés.
 */
class DemoResetGarderUtilisateursTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ligne_inventaire', 'inventaire', 'ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'notification', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function creer(string $email, string $nom, string $role): Utilisateur
    {
        $utilisateur = new Utilisateur($email, $nom);
        $utilisateur->setRoles([$role]);
        'ROLE_CAISSIER' === $role ? $utilisateur->setCodePin('x') : $utilisateur->setMotDePasse('x');
        $this->em->persist($utilisateur);
        $this->em->flush();

        return $utilisateur;
    }

    private function garnir(): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:demo:reset'));
        $tester->execute(['--garder-utilisateurs' => true, '--force' => true]);
        $this->em->clear();

        return $tester;
    }

    /** @return list<string> */
    private function emails(): array
    {
        return array_map(
            static fn (Utilisateur $u): string => $u->getEmail(),
            static::getContainer()->get(UtilisateurRepository::class)->findBy([], ['id' => 'ASC']),
        );
    }

    public function testLesComptesEnPlaceSurviventEtAucunCompteDeDemonstrationNestCree(): void
    {
        $this->creer('patronne@boulangerie.ci', 'La patronne', 'ROLE_DIRIGEANTE');
        $this->creer('caisse@boulangerie.ci', 'La caissière', 'ROLE_CAISSIER');

        $this->garnir()->assertCommandIsSuccessful();

        $this->assertSame(
            ['patronne@boulangerie.ci', 'caisse@boulangerie.ci'],
            $this->emails(),
            'Les comptes doivent survivre, et aucun compte de démonstration ne doit apparaître.',
        );
    }

    /**
     * Le piège de l'unicité : `AppFixtures` crée un gérant à cette adresse exacte.
     * Sans réutilisation des comptes en place, le chargement casserait ici.
     */
    public function testUnCompteReelPortantUnEmailDeDemonstrationNeCassePasLeChargement(): void
    {
        $this->creer('koffi.nguessan@zedpos.ci', 'Le vrai Koffi', 'ROLE_GERANT');
        $this->creer('caisse@boulangerie.ci', 'La caissière', 'ROLE_CAISSIER');

        $this->garnir()->assertCommandIsSuccessful();

        $koffi = static::getContainer()->get(UtilisateurRepository::class)
            ->findOneBy(['email' => 'koffi.nguessan@zedpos.ci']);

        $this->assertSame('Le vrai Koffi', $koffi->getNom(), 'Le compte réel ne doit pas être écrasé.');
    }

    public function testLaBaseEstGarnieDuCatalogueEtDeSonHistorique(): void
    {
        $this->creer('patronne@boulangerie.ci', 'La patronne', 'ROLE_DIRIGEANTE');
        $this->creer('caisse@boulangerie.ci', 'La caissière', 'ROLE_CAISSIER');

        $this->garnir()->assertCommandIsSuccessful();

        $connexion = $this->em->getConnection();
        $this->assertGreaterThan(30, (int) $connexion->fetchOne('SELECT COUNT(*) FROM article'));
        $this->assertGreaterThan(10, (int) $connexion->fetchOne('SELECT COUNT(*) FROM fiche_technique'));
        $this->assertGreaterThan(500, (int) $connexion->fetchOne('SELECT COUNT(*) FROM vente'));
    }

    /** L'historique appartient aux caissiers de l'établissement, pas à des inconnus. */
    public function testLHistoriqueEstAttribueAuxCaissiersEnPlace(): void
    {
        $this->creer('patronne@boulangerie.ci', 'La patronne', 'ROLE_DIRIGEANTE');
        $caissiere = $this->creer('caisse@boulangerie.ci', 'La caissière', 'ROLE_CAISSIER');
        $id = $caissiere->getId();

        $this->garnir()->assertCommandIsSuccessful();

        $auteurs = $this->em->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT s.utilisateur_id FROM vente v JOIN session_caisse s ON s.id = v.session_caisse_id'
        );

        $this->assertSame([$id], array_map('intval', $auteurs));
    }

    /**
     * Une base où seule la dirigeante s'est inscrite : trente jours de ventes ont
     * besoin de quelqu'un derrière la caisse, un caissier est donc créé — c'est la
     * seule exception à « aucun compte de démonstration ».
     */
    public function testSansAucunCaissierUnCompteDeCaisseEstCree(): void
    {
        $this->creer('patronne@boulangerie.ci', 'La patronne', 'ROLE_DIRIGEANTE');

        $this->garnir()->assertCommandIsSuccessful();

        $emails = $this->emails();
        $this->assertContains('patronne@boulangerie.ci', $emails);
        $this->assertContains('caisse@zedpos.ci', $emails);
        $this->assertCount(2, $emails);
    }

    /**
     * Avec un seul caissier, clôturer sa caisse pour y poser un écart fermerait la
     * journée en cours et effacerait le ticket annulé de l'écran. L'anomalie est
     * donc passée, et la caisse du jour reste ouverte.
     */
    public function testAvecUnSeulCaissierLaCaisseDuJourResteOuverte(): void
    {
        $this->creer('patronne@boulangerie.ci', 'La patronne', 'ROLE_DIRIGEANTE');
        $this->creer('caisse@boulangerie.ci', 'La caissière', 'ROLE_CAISSIER');

        $tester = $this->garnir();
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Un seul caissier', $tester->getDisplay());

        $ouvertes = $this->em->getRepository(SessionCaisse::class)->count(['statut' => 'OUVERTE']);
        $this->assertSame(1, $ouvertes, 'La journée en cours doit rester ouverte.');
    }

    /** Le ticket annulé, lui, est bien injecté : c'est l'anomalie principale. */
    public function testLAnomalieDuTicketAnnuleEstInjectee(): void
    {
        $this->creer('patronne@boulangerie.ci', 'La patronne', 'ROLE_DIRIGEANTE');
        $this->creer('caisse@boulangerie.ci', 'La caissière', 'ROLE_CAISSIER');

        $this->garnir()->assertCommandIsSuccessful();

        $annulees = $this->em->getRepository(Vente::class)->count(['statut' => 'ANNULEE']);
        $this->assertSame(1, $annulees);
    }

    /**
     * Le récapitulatif liste les comptes **réels**. Annoncer les identifiants de
     * démonstration enverrait l'exploitant se connecter avec des comptes qui
     * n'existent pas.
     */
    public function testLeRecapitulatifListeLesComptesReels(): void
    {
        $this->creer('patronne@boulangerie.ci', 'La patronne', 'ROLE_DIRIGEANTE');
        $this->creer('caisse@boulangerie.ci', 'La caissière', 'ROLE_CAISSIER');

        $sortie = $this->garnir()->getDisplay();

        $this->assertStringContainsString('patronne@boulangerie.ci', $sortie);
        $this->assertStringNotContainsString('dirigeante123', $sortie, 'Aucun mot de passe de démonstration ne doit être annoncé.');
        $this->assertStringNotContainsString('aya.kone@zedpos.ci', $sortie);
    }
}
