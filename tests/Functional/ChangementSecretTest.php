<?php

namespace App\Tests\Functional;

use App\Entity\JournalAudit;
use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Service\SessionCaisseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Changement de **son propre** secret : mot de passe (`/compte/mot-de-passe`)
 * pour la dirigeante, le gérant et le comptable, code PIN (`/caisse/code-pin`)
 * pour le caissier.
 *
 * Trois enjeux : l'ancien secret est exigé, et on ne le devine pas à force
 * d'essais ; le nouveau obéit aux règles de la création (longueur, PIN unique) ;
 * la session en cours survit au changement.
 */
class ChangementSecretTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $dirigeante;
    private Utilisateur $gerant;
    private Utilisateur $comptable;
    private Utilisateur $caissier;
    private Utilisateur $collegue;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Les compteurs d'essais survivent au vidage des tables : sans purge, un
        // test qui échoue exprès léguerait sa dette au suivant.
        static::getContainer()->get('test.cache.rate_limiter')->clear();

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'notification', 'parametre', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->dirigeante = $this->compte('aya@test.ci', 'Aya', 'ROLE_DIRIGEANTE', motDePasse: 'dirigeante123');
        $this->gerant = $this->compte('koffi@test.ci', 'Koffi', 'ROLE_GERANT', motDePasse: 'gerant123');
        $this->comptable = $this->compte('cabinet@test.ci', 'Cabinet', 'ROLE_COMPTABLE', motDePasse: 'comptable123');
        $this->caissier = $this->compte('fatou@test.ci', 'Fatou', 'ROLE_CAISSIER', codePin: '1234');
        $this->collegue = $this->compte('yao@test.ci', 'Yao', 'ROLE_CAISSIER', codePin: '5678');

        $this->em->flush();
    }

    private function compte(string $email, string $nom, string $role, ?string $motDePasse = null, ?string $codePin = null): Utilisateur
    {
        $utilisateur = (new Utilisateur($email, $nom))->setRoles([$role]);
        if (null !== $motDePasse) {
            $utilisateur->setMotDePasse($this->hacher($motDePasse));
        }
        if (null !== $codePin) {
            $utilisateur->setCodePin($this->hacher($codePin));
        }
        $this->em->persist($utilisateur);

        return $utilisateur;
    }

    private function hacher(string $secret): string
    {
        return static::getContainer()->get(PasswordHasherFactoryInterface::class)
            ->getPasswordHasher(Utilisateur::class)->hash($secret);
    }

    private function verifie(?string $hash, string $secret): bool
    {
        return null !== $hash && static::getContainer()->get(PasswordHasherFactoryInterface::class)
            ->getPasswordHasher(Utilisateur::class)->verify($hash, $secret);
    }

    private function recharger(Utilisateur $utilisateur): Utilisateur
    {
        $this->em->clear();

        return $this->em->getRepository(Utilisateur::class)->find($utilisateur->getId());
    }

    private function changerMotDePasse(string $actuel, string $nouveau, ?string $confirmation = null): void
    {
        $crawler = $this->client->request('GET', '/compte/mot-de-passe');
        $this->assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'changer_mot_de_passe[actuel]' => $actuel,
            'changer_mot_de_passe[nouveau][first]' => $nouveau,
            'changer_mot_de_passe[nouveau][second]' => $confirmation ?? $nouveau,
        ]));
    }

    private function changerCodePin(string $actuel, string $nouveau, ?string $confirmation = null): void
    {
        $crawler = $this->client->request('GET', '/caisse/code-pin');
        $this->assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Enregistrer le nouveau code')->form([
            'changer_code_pin[actuel]' => $actuel,
            'changer_code_pin[nouveau][first]' => $nouveau,
            'changer_code_pin[nouveau][second]' => $confirmation ?? $nouveau,
        ]));
    }

    // ------------------------------------------------------------ Accès

    public function testChaqueEspaceMeneAuChangementDeSonSecret(): void
    {
        foreach (['/admin' => $this->gerant, '/pilotage' => $this->dirigeante, '/comptabilite' => $this->comptable] as $espace => $utilisateur) {
            $this->client->loginUser($utilisateur);
            $crawler = $this->client->request('GET', $espace);

            $this->assertResponseIsSuccessful();
            $this->assertGreaterThanOrEqual(1, $crawler->filter('a[href="/compte/mot-de-passe"]')->count(), $espace.' : lien vers le changement de mot de passe.');
        }

        // La caissière arrive d'abord sur l'ouverture : le lien doit y être.
        $this->client->loginUser($this->caissier);
        $crawler = $this->client->request('GET', '/caisse/session/ouverture');
        $this->assertCount(1, $crawler->filter('a[href="/caisse/code-pin"]'));

        // Le client a redémarré le noyau : le caissier doit venir du gestionnaire
        // d'entités du conteneur courant, celui qu'emploie le service.
        $caissier = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Utilisateur::class)->find($this->caissier->getId());
        static::getContainer()->get(SessionCaisseService::class)->ouvrir($caissier, 3000000);
        $crawler = $this->client->request('GET', '/caisse');
        $this->assertResponseIsSuccessful();
        $this->assertGreaterThanOrEqual(1, $crawler->filter('a[href="/caisse/code-pin"]')->count(), "L'écran de vente mène au code PIN.");
    }

    /** Un lien partagé ne mène jamais à un formulaire que le compte ne peut pas utiliser. */
    public function testChaqueCompteEstRenvoyeVersSonMoyenDeConnexion(): void
    {
        $this->client->request('GET', '/compte/mot-de-passe');
        $this->assertResponseRedirects('/login', null, 'Personne de connecté : la page de connexion.');

        $this->client->loginUser($this->caissier);
        $this->client->request('GET', '/compte/mot-de-passe');
        $this->assertResponseRedirects('/caisse/code-pin');

        $this->client->loginUser($this->gerant);
        $this->client->request('GET', '/caisse/code-pin');
        $this->assertResponseRedirects('/compte/mot-de-passe');
    }

    // ---------------------------------------------------- Mot de passe

    public function testChacunChangeSonMotDePasseEtResteConnecte(): void
    {
        $cas = [
            [$this->dirigeante, 'dirigeante123', '/pilotage'],
            [$this->gerant, 'gerant123', '/admin'],
            [$this->comptable, 'comptable123', '/comptabilite'],
        ];

        foreach ($cas as [$utilisateur, $ancien, $accueil]) {
            $this->client->loginUser($utilisateur);
            $this->changerMotDePasse($ancien, 'nouveau-secret');

            $this->assertResponseRedirects($accueil, 303, $utilisateur->getEmail().' retourne dans son espace.');

            $recharge = $this->recharger($utilisateur);
            $this->assertTrue($this->verifie($recharge->getMotDePasse(), 'nouveau-secret'), 'Le nouveau mot de passe est en place.');
            $this->assertFalse($this->verifie($recharge->getMotDePasse(), $ancien), "L'ancien ne vaut plus rien.");

            // La session qui a fait le changement n'est pas jetée dehors.
            $this->client->followRedirect();
            $this->assertResponseIsSuccessful($utilisateur->getEmail().' reste connecté après le changement.');
            $this->assertSelectorTextContains('body', 'Mot de passe modifié');
        }
    }

    public function testLeMotDePasseActuelEstExige(): void
    {
        $this->client->loginUser($this->gerant);
        $this->changerMotDePasse('pas-le-bon', 'nouveau-secret');

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'Mot de passe actuel incorrect.');
        $this->assertTrue($this->verifie($this->recharger($this->gerant)->getMotDePasse(), 'gerant123'));
    }

    public function testLeNouveauMotDePasseSuitLesReglesDeLaCreation(): void
    {
        $this->client->loginUser($this->gerant);

        $this->changerMotDePasse('gerant123', 'nouveau-secret', 'autre-chose');
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'ne correspondent pas', 'La non-concordance est affichée, pas avalée.');

        $this->changerMotDePasse('gerant123', 'court');
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'au moins 6 caractères');

        $this->changerMotDePasse('gerant123', 'gerant123');
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'différent de l\'actuel');

        $this->assertTrue($this->verifie($this->recharger($this->gerant)->getMotDePasse(), 'gerant123'), 'Rien n\'a changé.');
    }

    /**
     * Passé le quota, **même le bon** mot de passe actuel est refusé : sinon la
     * limite laisserait simplement continuer jusqu'à tomber juste.
     */
    public function testLeSecretActuelNeSeDevinePasAForceDEssais(): void
    {
        $this->client->loginUser($this->gerant);

        for ($i = 0; $i < 5; ++$i) {
            $this->changerMotDePasse('essai-'.$i, 'nouveau-secret');
            $this->assertResponseStatusCodeSame(422);
        }

        $this->changerMotDePasse('gerant123', 'nouveau-secret');
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'Trop d\'essais');
        $this->assertTrue($this->verifie($this->recharger($this->gerant)->getMotDePasse(), 'gerant123'));
    }

    // -------------------------------------------------------- Code PIN

    public function testLeCaissierChangeSonCodePinAuPave(): void
    {
        $this->client->loginUser($this->caissier);

        $crawler = $this->client->request('GET', '/caisse/code-pin');
        $champs = $crawler->filter('[data-controller="pave-pin"] input[data-pave-pin-target="champ"]');
        $this->assertCount(3, $champs, 'Actuel, nouveau et confirmation se tapent au pavé.');
        $champs->each(fn ($champ) => $this->assertSame('none', $champ->attr('inputmode')));
        $this->assertCount(10, $crawler->filter('button[data-action="pave-pin#appuyer"]'));

        $this->changerCodePin('1234', '4321');
        $this->assertResponseRedirects('/caisse/code-pin', 303);

        $recharge = $this->recharger($this->caissier);
        $this->assertTrue($this->verifie($recharge->getCodePin(), '4321'));
        $this->assertFalse($this->verifie($recharge->getCodePin(), '1234'));
        $this->assertNull($recharge->getMotDePasse(), 'Un caissier ne gagne pas de mot de passe au passage.');

        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Code PIN modifié');
    }

    public function testLeNouveauCodePinEstLibreEtBienForme(): void
    {
        $this->client->loginUser($this->caissier);

        // Celui du collègue : deux caissiers au même code seraient indistinguables.
        $this->changerCodePin('1234', '5678');
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'déjà utilisé');

        $this->changerCodePin('1234', '12a4');
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'exactement 4 chiffres');

        $this->changerCodePin('9999', '4321');
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'Code PIN actuel incorrect.');

        $this->assertTrue($this->verifie($this->recharger($this->caissier)->getCodePin(), '1234'), 'Rien n\'a changé.');
    }

    // ---------------------------------------------------------- Audit

    /** Tracé, mais sans le secret — ni l'ancien ni le nouveau, pas même haché. */
    public function testLeChangementEstTraceSansLeSecret(): void
    {
        $this->client->loginUser($this->gerant);
        $this->changerMotDePasse('gerant123', 'nouveau-secret');

        $this->client->loginUser($this->recharger($this->caissier));
        $this->changerCodePin('1234', '4321');

        $entrees = $this->em->getRepository(JournalAudit::class)->findBy(['action' => ActionAudit::SECRET_MODIFIE->value], ['id' => 'ASC']);
        $this->assertCount(2, $entrees);

        $this->assertSame($this->gerant->getId(), $entrees[0]->getUtilisateur()?->getId());
        $this->assertSame(['moyen' => 'mot_de_passe'], $entrees[0]->getApres());
        $this->assertSame(['moyen' => 'code_pin'], $entrees[1]->getApres());

        foreach ($entrees as $entree) {
            $trace = json_encode([$entree->getAvant(), $entree->getApres()]);
            foreach (['gerant123', 'nouveau-secret', '1234', '4321', '$2y$', '$argon'] as $interdit) {
                $this->assertStringNotContainsString($interdit, (string) $trace);
            }
        }
    }
}
