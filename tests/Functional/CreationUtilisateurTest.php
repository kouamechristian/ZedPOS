<?php

namespace App\Tests\Functional;

use App\Entity\JournalAudit;
use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Enum\RoleUtilisateur;
use App\Service\CreationUtilisateur;
use App\Service\CreationUtilisateurException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Gestion des comptes depuis le back-office : création (/admin/utilisateurs/nouveau)
 * et modification (/admin/utilisateurs/{id}/modifier).
 *
 * Trois enjeux : le bon secret selon le rôle (mot de passe ou code PIN) ; le fait
 * que **nul ne distribue un accès au-dessus du sien** — création comme promotion ;
 * et qu'une modification ne casse ni l'identifiant en place ni la connexion du
 * compte qu'elle touche.
 */
class CreationUtilisateurTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $gerant;
    private Utilisateur $dirigeante;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'notification', 'parametre', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->gerant = new Utilisateur('koffi@test.ci', 'Koffi');
        $this->gerant->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->em->persist($this->gerant);

        $this->dirigeante = new Utilisateur('aya@test.ci', 'Aya');
        $this->dirigeante->setRoles(['ROLE_DIRIGEANTE'])->setMotDePasse('x');
        $this->em->persist($this->dirigeante);

        $this->em->flush();
    }

    private function service(): CreationUtilisateur
    {
        return static::getContainer()->get(CreationUtilisateur::class);
    }

    private function trouver(string $email): ?Utilisateur
    {
        return $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    // ------------------------------------------------------- Habilitation

    public function testLeGerantEtLaDirigeanteCreentDesComptes(): void
    {
        foreach ([$this->gerant, $this->dirigeante] as $utilisateur) {
            $this->client->loginUser($utilisateur);

            $this->client->request('GET', '/admin/utilisateurs/nouveau');
            $this->assertResponseIsSuccessful();

            $crawler = $this->client->request('GET', '/admin/utilisateurs');
            $this->assertCount(1, $crawler->filter('a:contains("Nouvel utilisateur")'));
        }
    }

    public function testUnCaissierNAccedePasALaGestionDesComptes(): void
    {
        $caissier = new Utilisateur('fatou@test.ci', 'Fatou');
        $caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($caissier);
        $this->em->flush();

        $this->client->loginUser($caissier);
        $this->client->request('GET', '/admin/utilisateurs/nouveau');

        $this->assertResponseStatusCodeSame(403);
    }

    // ------------------------------------- Le gérant ne distribue pas au-dessus de lui

    /**
     * Le plafond de la hiérarchie : un gérant crée des caissiers et des gérants,
     * jamais une dirigeante. Sans cela, il s'ouvrirait un second compte et
     * récupérerait les prix de vente, le pilotage et le journal d'audit.
     */
    public function testLeGerantNeSeVoitPasProposerLeRoleDirigeante(): void
    {
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/utilisateurs/nouveau');

        $proposes = $crawler->filter('#creer_utilisateur_role option')
            ->each(static fn ($option): string => (string) $option->attr('value'));

        $this->assertContains(RoleUtilisateur::CAISSIER->value, $proposes);
        $this->assertContains(RoleUtilisateur::GERANT->value, $proposes);
        $this->assertNotContains(RoleUtilisateur::DIRIGEANTE->value, $proposes);
        // Ouvrir l'accès au cabinet extérieur relève du contrat, pas du magasin.
        $this->assertNotContains(RoleUtilisateur::COMPTABLE->value, $proposes);
    }

    public function testLaDirigeanteSeVoitProposerTousLesRoles(): void
    {
        $this->client->loginUser($this->dirigeante);
        $crawler = $this->client->request('GET', '/admin/utilisateurs/nouveau');

        $proposes = $crawler->filter('#creer_utilisateur_role option')
            ->each(static fn ($option): string => (string) $option->attr('value'));

        foreach (RoleUtilisateur::cases() as $role) {
            $this->assertContains($role->value, $proposes);
        }
    }

    /**
     * Le champ absent est la vraie protection : un rôle hors liste est **rejeté à
     * la soumission**, même en forgeant la requête. Un contrôle qui ne tiendrait
     * qu'à l'affichage ne protégerait rien.
     */
    public function testUnGerantQuiForgeLeRoleDirigeanteEstRefuse(): void
    {
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/utilisateurs/nouveau');

        $form = $crawler->selectButton('Créer le compte')->form();
        $this->client->request('POST', '/admin/utilisateurs/nouveau', [
            'creer_utilisateur' => [
                'nom' => 'Compte pirate',
                'email' => 'pirate@test.ci',
                'role' => RoleUtilisateur::DIRIGEANTE->value,
                'motDePasse' => 'secret123',
                '_token' => $form->get('creer_utilisateur[_token]')->getValue(),
            ],
        ]);

        // 422 : formulaire invalide, que Turbo peut remplacer.
        $this->assertResponseStatusCodeSame(422);
        $this->assertNull($this->trouver('pirate@test.ci'), 'Aucun compte ne doit être créé.');
    }

    /**
     * Désactiver une dirigeante couperait l'établissement de son seul accès au
     * pilotage et à l'audit : le gérant n'a pas la main sur ce compte-là.
     */
    public function testUnGerantNeBasculePasUnCompteDirigeante(): void
    {
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/utilisateurs');

        // Le bouton n'est pas rendu…
        $ligne = $crawler->filter('tr:contains("aya@test.ci")');
        $this->assertCount(0, $ligne->filter('button'), 'Aucun bouton sur la ligne de la dirigeante.');

        // …et la route refuse tout autant, jeton CSRF valide ou non.
        $this->client->request('POST', '/admin/utilisateurs/'.$this->dirigeante->getId().'/basculer');
        $this->assertResponseStatusCodeSame(403);
        $this->assertTrue($this->trouver('aya@test.ci')->isActif());
    }

    // ------------------------------------------------------- La modification

    /**
     * Modifier un compte pour corriger un nom ne doit **pas** réinitialiser son
     * identifiant : le secret laissé vide est conservé. C'est le geste le plus
     * courant, et le plus facile à casser sans s'en apercevoir — personne ne
     * reteste sa connexion après avoir corrigé une faute de frappe.
     */
    public function testModifierSansToucherAuSecretLeConserve(): void
    {
        $caissier = $this->creerCaissier('yao@test.ci', 'Yao', '4321');
        $pinAvant = $caissier->getCodePin();

        $this->client->loginUser($this->dirigeante);
        $this->soumettreModification($caissier, ['nom' => 'Yao Kouassi']);

        $this->assertResponseRedirects('/admin/utilisateurs');

        $this->em->clear();
        $modifie = $this->trouver('yao@test.ci');
        $this->assertSame('Yao Kouassi', $modifie->getNom());
        $this->assertSame($pinAvant, $modifie->getCodePin(), 'Le code PIN ne devait pas bouger.');
    }

    public function testModifierLeSecretLeRemplace(): void
    {
        $caissier = $this->creerCaissier('yao@test.ci', 'Yao', '4321');
        $pinAvant = $caissier->getCodePin();

        $this->client->loginUser($this->dirigeante);
        $this->soumettreModification($caissier, ['codePin' => '8765']);

        $this->em->clear();
        $this->assertNotSame($pinAvant, $this->trouver('yao@test.ci')->getCodePin());
    }

    /**
     * Le compte doit rester connectable. Promouvoir un caissier en gérant sans lui
     * donner de mot de passe l'enfermerait dehors : il n'a qu'un code PIN, et le
     * pavé de la caisse ne lui sera plus ouvert.
     */
    public function testChangerDeRoleSansNouveauSecretEstRefuse(): void
    {
        $caissier = $this->creerCaissier('yao@test.ci', 'Yao', '4321');

        $this->client->loginUser($this->dirigeante);
        $this->soumettreModification($caissier, ['role' => RoleUtilisateur::GERANT->value]);

        $this->assertResponseStatusCodeSame(422);

        $this->em->clear();
        $this->assertContains('ROLE_CAISSIER', $this->trouver('yao@test.ci')->getRoles(), 'Le rôle ne doit pas avoir changé.');
    }

    /**
     * Le piège : `CaisseAuthenticator` accepte **tout compte actif porteur d'un
     * code PIN**. Un caissier promu gérant qui garderait le sien continuerait
     * d'ouvrir la caisse au pavé numérique, sans que rien ne le signale.
     */
    public function testUnCaissierPromuGerantPerdSonCodePin(): void
    {
        $caissier = $this->creerCaissier('yao@test.ci', 'Yao', '4321');

        $this->client->loginUser($this->dirigeante);
        $this->soumettreModification($caissier, [
            'role' => RoleUtilisateur::GERANT->value,
            'motDePasse' => 'secret123',
        ]);
        $this->assertResponseRedirects('/admin/utilisateurs');

        $this->em->clear();
        $promu = $this->trouver('yao@test.ci');

        $this->assertContains('ROLE_GERANT', $promu->getRoles());
        $this->assertNotNull($promu->getMotDePasse());
        $this->assertNull($promu->getCodePin(), 'Le code PIN devenu sans objet doit être effacé.');
    }

    /** Symétrique : un gérant rétrogradé en caissier ne garde pas son mot de passe. */
    public function testUnGerantRetrogradeEnCaissierPerdSonMotDePasse(): void
    {
        $this->client->loginUser($this->dirigeante);
        $this->soumettreModification($this->gerant, [
            'role' => RoleUtilisateur::CAISSIER->value,
            'codePin' => '2468',
        ]);
        $this->assertResponseRedirects('/admin/utilisateurs');

        $this->em->clear();
        $retrograde = $this->trouver('koffi@test.ci');

        $this->assertNull($retrograde->getMotDePasse());
        $this->assertNotNull($retrograde->getCodePin());
    }

    /**
     * Reconduire à l'identique le code PIN d'un caissier ne doit pas se heurter à
     * son propre hachage — il serait « déjà utilisé » par lui-même.
     */
    public function testReconduireSonProprePinNestPasUnDoublon(): void
    {
        $caissier = $this->creerCaissier('yao@test.ci', 'Yao', '4321');

        $this->client->loginUser($this->dirigeante);
        $this->soumettreModification($caissier, ['codePin' => '4321']);

        $this->assertResponseRedirects('/admin/utilisateurs');
    }

    public function testUnPinDejaPrisParUnAutreCaissierEstRefuse(): void
    {
        $this->creerCaissier('fatou@test.ci', 'Fatou', '1111');
        $yao = $this->creerCaissier('yao@test.ci', 'Yao', '2222');

        $this->client->loginUser($this->dirigeante);
        $this->soumettreModification($yao, ['codePin' => '1111']);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testUnEmailDejaPrisParUnAutreCompteEstRefuse(): void
    {
        $yao = $this->creerCaissier('yao@test.ci', 'Yao', '4321');

        $this->client->loginUser($this->dirigeante);
        $this->soumettreModification($yao, ['email' => 'aya@test.ci']);

        $this->assertResponseStatusCodeSame(422);
    }

    // ------------------------------------ La modification ne contourne pas les rôles

    public function testUnGerantNeModifiePasUneDirigeante(): void
    {
        $this->client->loginUser($this->gerant);
        $this->client->request('GET', '/admin/utilisateurs/'.$this->dirigeante->getId().'/modifier');

        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * L'échappatoire évidente : plutôt que de *créer* une dirigeante, en promouvoir
     * une. Le plafond de `attribuablesPar()` vaut donc aussi en modification.
     */
    public function testUnGerantNePeutPasPromouvoirQuelquUnEnDirigeante(): void
    {
        $caissier = $this->creerCaissier('yao@test.ci', 'Yao', '4321');

        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/utilisateurs/'.$caissier->getId().'/modifier');
        $this->assertResponseIsSuccessful();

        $proposes = $crawler->filter('#creer_utilisateur_role option')
            ->each(static fn ($option): string => (string) $option->attr('value'));
        $this->assertNotContains(RoleUtilisateur::DIRIGEANTE->value, $proposes);

        // Le choix étant absent du formulaire, on forge la requête directement :
        // c'est la seule façon de vérifier que le refus tient côté serveur et pas
        // seulement à l'affichage.
        $this->forger('/admin/utilisateurs/'.$caissier->getId().'/modifier', $crawler, [
            'nom' => 'Yao',
            'email' => 'yao@test.ci',
            'role' => RoleUtilisateur::DIRIGEANTE->value,
            'motDePasse' => 'secret123',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->em->clear();
        $this->assertNotContains('ROLE_DIRIGEANTE', $this->trouver('yao@test.ci')->getRoles());
    }

    /**
     * On ne change pas son propre rôle : un gérant qui se rétrograderait perdrait
     * `/admin` séance tenante et il faudrait quelqu'un d'autre pour l'en sortir.
     * Même esprit que l'interdiction de se désactiver soi-même.
     */
    public function testOnNeChangePasSonProprerole(): void
    {
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/utilisateurs/'.$this->gerant->getId().'/modifier');
        $this->assertResponseIsSuccessful();

        $proposes = $crawler->filter('#creer_utilisateur_role option')
            ->each(static fn ($option): string => (string) $option->attr('value'));

        // Seul son rôle actuel, en plus du libellé « Choisir un rôle… » (valeur vide).
        $this->assertSame([RoleUtilisateur::GERANT->value], array_values(array_filter($proposes)));

        $this->forger('/admin/utilisateurs/'.$this->gerant->getId().'/modifier', $crawler, [
            'nom' => 'Koffi',
            'email' => 'koffi@test.ci',
            'role' => RoleUtilisateur::CAISSIER->value,
            'codePin' => '1357',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->em->clear();
        $this->assertContains('ROLE_GERANT', $this->trouver('koffi@test.ci')->getRoles());
    }

    /**
     * Le rôle en place figure toujours dans la liste, même hors de portée de
     * l'auteur : sans lui le formulaire s'ouvrirait sur un choix vide et le simple
     * fait d'enregistrer rétrograderait le compte.
     */
    public function testLeRoleEnPlaceResteProposeMemeHorsDePorteeDeLAuteur(): void
    {
        $comptable = new Utilisateur('cabinet@test.ci', 'Cabinet');
        $comptable->setRoles(['ROLE_COMPTABLE'])->setMotDePasse('x');
        $this->em->persist($comptable);
        $this->em->flush();

        // Le gérant ne peut pas *attribuer* COMPTABLE, mais il doit pouvoir
        // corriger le nom d'un comptable sans le déclasser au passage.
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/utilisateurs/'.$comptable->getId().'/modifier');

        $proposes = $crawler->filter('#creer_utilisateur_role option')
            ->each(static fn ($option): string => (string) $option->attr('value'));

        $this->assertContains(RoleUtilisateur::COMPTABLE->value, $proposes);
    }

    public function testLaModificationEstTraceeAuJournalDAudit(): void
    {
        $caissier = $this->creerCaissier('yao@test.ci', 'Yao', '4321');

        $this->client->loginUser($this->dirigeante);
        $this->soumettreModification($caissier, ['nom' => 'Yao Kouassi', 'codePin' => '8765']);
        $this->assertResponseRedirects('/admin/utilisateurs');

        $entrees = $this->em->getRepository(JournalAudit::class)
            ->findBy(['action' => ActionAudit::UTILISATEUR_MODIFIE]);

        $this->assertCount(1, $entrees);
        $this->assertSame('Yao', $entrees[0]->getAvant()['nom']);
        $this->assertSame('Yao Kouassi', $entrees[0]->getApres()['nom']);
        $this->assertTrue($entrees[0]->getApres()['secret_remplace']);

        // Le secret lui-même n'a rien à faire dans un journal, pas même haché.
        $this->assertStringNotContainsString('8765', json_encode($entrees[0]->getApres()));
    }

    // ------------------------------------------------------------- Utilitaires

    private function creerCaissier(string $email, string $nom, string $pin): Utilisateur
    {
        return $this->service()->creer($email, $nom, RoleUtilisateur::CAISSIER, $pin);
    }

    /**
     * POST direct, hors du formulaire rendu : sert à soumettre une valeur que le
     * formulaire **ne propose pas**. DomCrawler refuserait de la saisir — ce qui
     * prouve l'absence du choix, mais pas que le serveur la rejette.
     *
     * @param array<string, string> $champs
     */
    private function forger(string $url, Crawler $crawler, array $champs): void
    {
        $champs['_token'] = $crawler->filter('#creer_utilisateur__token')->attr('value');

        $this->client->request('POST', $url, ['creer_utilisateur' => $champs]);
    }

    /**
     * Soumet le formulaire de modification en ne changeant que les champs donnés :
     * le reste part tel qu'il est affiché, comme le ferait un vrai navigateur.
     *
     * @param array<string, string> $champs
     */
    private function soumettreModification(Utilisateur $cible, array $champs): void
    {
        $crawler = $this->client->request('GET', '/admin/utilisateurs/'.$cible->getId().'/modifier');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enregistrer')->form();
        foreach ($champs as $nom => $valeur) {
            $form['creer_utilisateur['.$nom.']'] = $valeur;
        }

        $this->client->submit($form);
    }

    public function testUnGerantBasculeUnCompteCaissier(): void
    {
        $caissier = new Utilisateur('yao@test.ci', 'Yao');
        $caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('y');
        $this->em->persist($caissier);
        $this->em->flush();

        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/utilisateurs');

        $this->assertGreaterThan(
            0,
            $crawler->filter('tr:contains("yao@test.ci") button')->count(),
            'Le gérant doit pouvoir désactiver un caissier.',
        );
    }

    // ------------------------------------------------------- Le formulaire

    public function testCreationDunGerantAvecMotDePasse(): void
    {
        $this->client->loginUser($this->dirigeante);
        $crawler = $this->client->request('GET', '/admin/utilisateurs/nouveau');

        $form = $crawler->selectButton('Créer le compte')->form([
            'creer_utilisateur[nom]' => 'Awa Diallo',
            'creer_utilisateur[email]' => 'awa@test.ci',
            'creer_utilisateur[role]' => RoleUtilisateur::GERANT->value,
            'creer_utilisateur[motDePasse]' => 'secret123',
        ]);
        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/utilisateurs');

        $cree = $this->trouver('awa@test.ci');
        $this->assertNotNull($cree);
        $this->assertContains('ROLE_GERANT', $cree->getRoles());
        // Le mot de passe est haché, jamais stocké en clair.
        $this->assertNotSame('secret123', $cree->getMotDePasse());
        $this->assertTrue(
            static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($cree, 'secret123'),
        );
        $this->assertNull($cree->getCodePin());
    }

    public function testCreationDunCaissierAvecCodePin(): void
    {
        $this->client->loginUser($this->dirigeante);
        $crawler = $this->client->request('GET', '/admin/utilisateurs/nouveau');

        $form = $crawler->selectButton('Créer le compte')->form([
            'creer_utilisateur[nom]' => 'Yao Kouassi',
            'creer_utilisateur[email]' => 'yao@test.ci',
            'creer_utilisateur[role]' => RoleUtilisateur::CAISSIER->value,
            'creer_utilisateur[codePin]' => '4321',
        ]);
        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/utilisateurs');

        $cree = $this->trouver('yao@test.ci');
        $this->assertNotNull($cree);
        $this->assertContains('ROLE_CAISSIER', $cree->getRoles());
        $this->assertNotNull($cree->getCodePin());
        $this->assertNotSame('4321', $cree->getCodePin());
        // Un caissier n'a pas de mot de passe : il ne se connecte qu'au pavé numérique.
        $this->assertNull($cree->getMotDePasse());
    }

    /**
     * Un formulaire invalide doit répondre 422, sinon Turbo laisse l'écran figé.
     */
    public function testUnCaissierSansCodePinEstRefuseEn422(): void
    {
        $this->client->loginUser($this->dirigeante);
        $crawler = $this->client->request('GET', '/admin/utilisateurs/nouveau');

        $form = $crawler->selectButton('Créer le compte')->form([
            'creer_utilisateur[nom]' => 'Sans PIN',
            'creer_utilisateur[email]' => 'sanspin@test.ci',
            'creer_utilisateur[role]' => RoleUtilisateur::CAISSIER->value,
            'creer_utilisateur[motDePasse]' => 'motdepasse123',
        ]);
        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(422);
        $this->assertNull($this->trouver('sanspin@test.ci'));
    }

    public function testUnEmailDejaPrisEstRefuseEn422(): void
    {
        $this->client->loginUser($this->dirigeante);
        $crawler = $this->client->request('GET', '/admin/utilisateurs/nouveau');

        $form = $crawler->selectButton('Créer le compte')->form([
            'creer_utilisateur[nom]' => 'Doublon',
            'creer_utilisateur[email]' => 'koffi@test.ci', // déjà celui du gérant
            'creer_utilisateur[role]' => RoleUtilisateur::COMPTABLE->value,
            'creer_utilisateur[motDePasse]' => 'secret123',
        ]);
        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'existe déjà');
    }

    // ------------------------------------------------------- Le service

    public function testUnCodePinEnDoublonEstRefuse(): void
    {
        $this->service()->creer('premier@test.ci', 'Premier', RoleUtilisateur::CAISSIER, '1234');

        // Deux caissiers avec le même PIN seraient indistinguables à la connexion.
        $this->expectException(CreationUtilisateurException::class);
        $this->service()->creer('second@test.ci', 'Second', RoleUtilisateur::CAISSIER, '1234');
    }

    public function testUnMotDePasseTropCourtEstRefuse(): void
    {
        $this->expectException(CreationUtilisateurException::class);
        $this->service()->creer('court@test.ci', 'Court', RoleUtilisateur::GERANT, '12345');
    }

    public function testUnCodePinNonNumeriqueEstRefuse(): void
    {
        $this->expectException(CreationUtilisateurException::class);
        $this->service()->creer('abcd@test.ci', 'Abcd', RoleUtilisateur::CAISSIER, 'abcd');
    }

    public function testLaCreationEstTraceeAuJournalDaudit(): void
    {
        $this->service()->creer('trace@test.ci', 'Tracee', RoleUtilisateur::GERANT, 'secret123');

        $action = $this->em->getConnection()->fetchOne(
            'SELECT action FROM journal_audit WHERE entite = ? ORDER BY id DESC LIMIT 1',
            ['Utilisateur'],
        );

        $this->assertSame(ActionAudit::UTILISATEUR_CREE->value, $action);
    }
}
