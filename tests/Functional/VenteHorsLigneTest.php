<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Repository\VenteRepository;
use App\Service\AuditLogger;
use App\Service\SessionCaisseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Ce que le serveur fait des ventes encaissées hors ligne : les dater de leur
 * heure réelle, les enregistrer au prix qui était en vigueur, et garder la trace de
 * celles qu'il refuse. Trois causes d'écart de caisse, toutes muettes sans cela.
 */
class VenteHorsLigneTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $caissier;
    private Article $article;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'notification', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->caissier = new Utilisateur('caissier@test.ci', 'Caissier');
        $this->caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($this->caissier);

        $famille = new FamilleProduit('Divers');
        $this->em->persist($famille);

        // 1 000 FCFA aujourd'hui, comme dans VenteApiTest.
        $this->article = new Article('Article A', 100000, 'pièce');
        $this->article->setFamilleProduit($famille)->setTauxTva(0);
        $this->em->persist($this->article);
        $this->em->flush();

        static::getContainer()->get(SessionCaisseService::class)->ouvrir($this->caissier, 3000000);
        $this->client->loginUser($this->caissier);
    }

    /**
     * @param array<string, mixed> $charge
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function poster(string $url, array $charge): array
    {
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($charge));
        $reponse = $this->client->getResponse();

        return [$reponse->getStatusCode(), json_decode($reponse->getContent(), true) ?? []];
    }

    /** @return array<string, mixed> */
    private function charge(?string $venduA, ?int $prixAffiche, int $regle = 100000): array
    {
        $ligne = ['articleId' => $this->article->getId(), 'quantite' => 1];
        if (null !== $prixAffiche) {
            $ligne['prix'] = $prixAffiche;
        }

        $charge = [
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [$ligne],
            'reglements' => [['mode' => 'ESPECES', 'montant' => $regle]],
        ];
        if (null !== $venduA) {
            $charge['venduA'] = $venduA;
        }

        return $charge;
    }

    private function ilYa(string $delai): string
    {
        return (new \DateTimeImmutable('-'.$delai))->format(\DateTimeInterface::ATOM);
    }

    /**
     * Le prix passe de 1 000 à 1 200 FCFA il y a dix minutes, comme le journal
     * d'audit le consignerait au moment où la dirigeante l'a modifié.
     */
    private function prixPasseDe1000a1200IlYaDixMinutes(): void
    {
        $this->article->setPrixVenteTtc(120000);
        $this->em->flush();

        static::getContainer()->get(AuditLogger::class)->prixModifie($this->article, 100000, 120000);
        $this->em->getConnection()->executeStatement(
            'UPDATE journal_audit SET created_at = ? WHERE action = ?',
            [(new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s'), ActionAudit::PRIX_MODIFIE->value],
        );
    }

    private function ventes(): VenteRepository
    {
        return static::getContainer()->get(VenteRepository::class);
    }

    // ------------------------------------------------------------------- Prix

    public function testUneVenteHorsLigneAvantLeChangementDePrixGardeLAncienPrix(): void
    {
        $this->prixPasseDe1000a1200IlYaDixMinutes();

        // Encaissée il y a 30 minutes, à 1 000 FCFA : le client a payé 1 000.
        [$code, $data] = $this->poster('/api/vente', $this->charge($this->ilYa('30 minutes'), 100000));

        $this->assertSame(201, $code, json_encode($data));
        $this->assertSame(100000, $data['totalTtc'], 'Le total est celui payé au comptoir, pas le prix du jour.');
        $this->assertSame(0, $data['rendu'], 'Aucun rendu fantôme : c\'est lui qui faussait les espèces du Z.');
    }

    public function testUneVenteApresLeChangementDePrixPaieLeNouveauPrix(): void
    {
        $this->prixPasseDe1000a1200IlYaDixMinutes();

        // Encaissée il y a 2 minutes, après la modification (il y a 10) : 1 200 FCFA.
        [$code, $data] = $this->poster('/api/vente', $this->charge($this->ilYa('2 minutes'), 120000, 120000));

        $this->assertSame(201, $code, json_encode($data));
        $this->assertSame(120000, $data['totalTtc']);
    }

    public function testUnPrixAfficheQuiNEstPasCeluiEnVigueurEstRefuse(): void
    {
        // La tablette affichait 900 FCFA, l'article en vaut 1 000 : le client a
        // payé 900. Enregistrer 1 000 ferait un écart que rien ne signale.
        [$code, $data] = $this->poster('/api/vente', $this->charge($this->ilYa('1 second'), 90000, 90000));

        $this->assertSame(422, $code);
        $this->assertFalse($data['ok']);
        $this->assertStringContainsString('a changé', $data['erreur']);
        $this->assertStringContainsString('900', $data['erreur']);
        $this->assertCount(0, $this->ventes()->findAll(), 'Rien n\'est enregistré à un prix que personne n\'a réglé.');
    }

    public function testSansPrixAfficheLePrixDuServeurFaitFoi(): void
    {
        // Les clients qui ne le disent pas (ancienne tablette) gardent le comportement d'origine.
        [$code, $data] = $this->poster('/api/vente', $this->charge(null, null));

        $this->assertSame(201, $code);
        $this->assertSame(100000, $data['totalTtc']);
    }

    // -------------------------------------------------------- Heure de la vente

    public function testLaVenteEstDateeDeSonHeureReelle(): void
    {
        $reelle = new \DateTimeImmutable('-3 hours');
        [$code, $data] = $this->poster('/api/vente', $this->charge($reelle->format(\DateTimeInterface::ATOM), 100000));

        $this->assertSame(201, $code, json_encode($data));
        $vente = $this->ventes()->findOneBy(['numero' => $data['numero']]);
        $this->assertEqualsWithDelta($reelle->getTimestamp(), $vente->getCreatedAt()->getTimestamp(), 2);
        $this->assertStringStartsWith('V'.$reelle->format('ymd').'-', $data['numero'], 'Le numéro porte le jour de la vente.');
    }

    public function testUneHeureAbsurdeEstIgnoree(): void
    {
        $absurdes = [
            'demain' => (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM),
            'il y a un mois' => (new \DateTimeImmutable('-30 days'))->format(\DateTimeInterface::ATOM),
            'illisible' => 'n\'importe quoi',
        ];

        foreach ($absurdes as $nom => $venduA) {
            [$code, $data] = $this->poster('/api/vente', $this->charge($venduA, 100000));

            $this->assertSame(201, $code, $nom);
            $vente = $this->ventes()->findOneBy(['numero' => $data['numero']]);
            $this->assertEqualsWithDelta(time(), $vente->getCreatedAt()->getTimestamp(), 5, \sprintf('« %s » n\'est pas crédible : heure d\'arrivée.', $nom));
        }
    }

    // ------------------------------------------------------- Article désactivé

    public function testUnArticleDesactiveDepuisLaVenteHorsLigneEstTolereAuRejeu(): void
    {
        $this->article->setActif(false);
        $this->em->flush();

        [$code, $data] = $this->poster('/api/vente', $this->charge($this->ilYa('10 minutes'), 100000));

        $this->assertSame(201, $code, 'Vendu au comptoir avant sa désactivation : refuser laisserait l\'argent sans vente.');
        $this->assertSame(100000, $data['totalTtc']);
    }

    public function testUnArticleDesactiveResteRefuseEnDirect(): void
    {
        $this->article->setActif(false);
        $this->em->flush();

        [$code, $data] = $this->poster('/api/vente', $this->charge($this->ilYa('1 second'), 100000));
        $this->assertSame(400, $code, json_encode($data));
        $this->assertFalse($data['ok']);

        [$sansHeure] = $this->poster('/api/vente', $this->charge(null, null));
        $this->assertSame(400, $sansHeure, 'Sans heure déclarée, la règle d\'origine s\'applique.');
    }

    public function testUnArticleSansPrixNeSeVendJamaisMemeAuRejeu(): void
    {
        $this->article->setActif(false)->setPrixVenteTtc(0);
        $this->em->flush();

        [$code] = $this->poster('/api/vente', $this->charge($this->ilYa('10 minutes'), null));

        $this->assertSame(400, $code, 'Les articles créés sans prix sont forcés inactifs : ils ne sortent pas par la porte des rejeux.');
    }

    // -------------------------------------------------- Déclaration d'un refus

    public function testUneVenteRefuseeSeDeclareAuJournalEtNeSeDeclareQuUneFois(): void
    {
        $uuid = (string) Uuid::v4();
        $corps = [
            'uuid' => $uuid,
            'erreur' => 'Le prix de « Baguette » a changé.',
            'totalFcfa' => 450,
            'reglementFcfa' => 500,
            'venduA' => $this->ilYa('1 hour'),
            'lignes' => [['nom' => 'Baguette', 'quantite' => 3, 'montant' => 450]],
        ];

        [$code, $data] = $this->poster('/api/vente-refusee', $corps);
        $this->assertSame(200, $code);
        $this->assertTrue($data['ok']);

        // Réponse perdue, la tablette redéclare : pas de seconde trace.
        [$code] = $this->poster('/api/vente-refusee', $corps);
        $this->assertSame(200, $code);

        $entrees = $this->em->getConnection()->fetchAllAssociative(
            'SELECT apres, utilisateur_id FROM journal_audit WHERE action = ?',
            [ActionAudit::VENTE_NON_ENREGISTREE->value],
        );
        $this->assertCount(1, $entrees);
        $apres = json_decode($entrees[0]['apres'], true);
        $this->assertSame($uuid, $apres['uuid']);
        $this->assertSame(450, $apres['totalFcfa']);
        $this->assertSame('Baguette', $apres['lignes'][0]['nom']);
        $this->assertSame($this->caissier->getId(), (int) $entrees[0]['utilisateur_id'], 'La trace porte qui l\'a déclarée.');
        $this->assertTrue(ActionAudit::VENTE_NON_ENREGISTREE->estSensible());
    }

    public function testUneVenteDejaEnregistreeNeSeRetirePas(): void
    {
        $charge = $this->charge(null, null);
        [$code] = $this->poster('/api/vente', $charge);
        $this->assertSame(201, $code);

        [$code, $data] = $this->poster('/api/vente-refusee', ['uuid' => $charge['uuid'], 'erreur' => 'x']);

        $this->assertSame(409, $code, 'Le serveur la connaît : elle n\'est pas perdue, la tablette doit la rejouer.');
        $this->assertFalse($data['ok']);
    }

    public function testUneDeclarationSansUuidValideEstRefusee(): void
    {
        [$code] = $this->poster('/api/vente-refusee', ['uuid' => 'pas-un-uuid']);

        $this->assertSame(400, $code);
    }

    public function testLesDonneesDeclareesSontBornees(): void
    {
        $lignes = array_fill(0, 200, ['nom' => str_repeat('x', 1000), 'quantite' => 1, 'montant' => 1]);
        [$code] = $this->poster('/api/vente-refusee', [
            'uuid' => (string) Uuid::v4(),
            'erreur' => str_repeat('e', 5000),
            'lignes' => $lignes,
        ]);
        $this->assertSame(200, $code);

        $apres = json_decode($this->em->getConnection()->fetchOne(
            'SELECT apres FROM journal_audit WHERE action = ?',
            [ActionAudit::VENTE_NON_ENREGISTREE->value],
        ), true);
        $this->assertCount(50, $apres['lignes']);
        $this->assertSame(120, mb_strlen($apres['lignes'][0]['nom']));
        $this->assertSame(300, mb_strlen($apres['erreur']));
    }

    public function testLaDeclarationExigeUneConnexion(): void
    {
        // Un client neuf : aucune session, donc aucun compte connecté.
        static::ensureKernelShutdown();
        $anonyme = static::createClient();
        $anonyme->request('POST', '/api/vente-refusee', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['uuid' => (string) Uuid::v4()]));

        $this->assertTrue($anonyme->getResponse()->isRedirect(), 'Redirigé vers la connexion, rien n\'est écrit.');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->assertSame(0, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM journal_audit WHERE action = ?',
            [ActionAudit::VENTE_NON_ENREGISTREE->value],
        ));
    }
}
