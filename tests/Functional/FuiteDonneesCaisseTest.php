<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\FicheTechnique;
use App\Entity\LigneFicheTechnique;
use App\Entity\MatierePremiere;
use App\Entity\Utilisateur;
use App\Service\SessionCaisseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Étanchéité des réponses servies à la caisse.
 *
 * Un caissier a légitimement accès à ces points d'entrée : la protection ne peut
 * donc pas venir d'un contrôle d'accès, elle doit venir de ce que les réponses
 * **ne contiennent pas**. On vérifie ici qu'aucun coût de revient, aucune marge,
 * aucun agrégat de chiffre d'affaires ne transite par elles.
 */
class FuiteDonneesCaisseTest extends WebTestCase
{
    /**
     * Clés et fragments qui trahiraient une donnée de gestion dans une réponse
     * destinée à la caisse.
     */
    private const TERMES_INTERDITS = [
        'cout', 'coût', 'coutRevient', 'cout_revient', 'coutMoyenPondere', 'cout_moyen_pondere',
        'marge', 'tauxMarge', 'taux_marge', 'benefice', 'bénéfice',
        'chiffreAffaires', 'chiffre_affaires', 'caJour', 'ca_jour', 'panierMoyen', 'panier_moyen',
        'fondCaisse', 'fond_caisse', 'ecart', 'écart', 'theorique', 'théorique',
        'prixAchat', 'prix_achat', 'stockActuel', 'stock_actuel', 'fournisseur',
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $caissier;
    private int $articleId;

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

        $this->caissier = new Utilisateur('fatou@test.ci', 'Fatou');
        $this->caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($this->caissier);

        $famille = new FamilleProduit('Pains');
        $this->em->persist($famille);

        $article = new Article('Baguette', 15000, 'pièce');
        $article->setFamilleProduit($famille)->setTauxTva(0);
        $this->em->persist($article);

        // Article doté d'une fiche technique : il a donc un coût de revient et une
        // marge calculables — exactement ce qui ne doit pas sortir vers la caisse.
        $farine = (new MatierePremiere('Farine', 'kg'))->setCoutMoyenPondere(45000)->setStockActuel(100000);
        $this->em->persist($farine);

        $fiche = new FicheTechnique($article);
        $this->em->persist($fiche);
        new LigneFicheTechnique($fiche, $farine, 250, 500);

        $this->em->flush();
        $this->articleId = $article->getId();

        static::getContainer()->get(SessionCaisseService::class)->ouvrir($this->caissier, 3000000);
        $this->client->loginUser($this->caissier);
    }

    /**
     * Recherche insensible à la casse d'un terme de gestion dans une charge utile.
     */
    private function assertAucunTermeSensible(string $charge, string $contexte): void
    {
        $normalise = mb_strtolower($charge);

        foreach (self::TERMES_INTERDITS as $terme) {
            $this->assertStringNotContainsString(
                mb_strtolower($terme),
                $normalise,
                \sprintf('« %s » ne doit pas apparaître dans %s.', $terme, $contexte),
            );
        }
    }

    public function testCatalogueJsonNExposeQueCeQuiSertAVendre(): void
    {
        $this->client->request('GET', '/caisse/catalogue.json');
        $this->assertResponseIsSuccessful();

        $charge = $this->client->getResponse()->getContent();
        $this->assertAucunTermeSensible($charge, 'le catalogue de caisse');

        // Liste blanche stricte des clés d'un article.
        $donnees = json_decode($charge, true);
        $article = $donnees['familles'][0]['articles'][0];

        $this->assertSame(
            // `image` : chemin public de la photo de touche, ou null. C'est une
            // donnée d'affichage, au même titre que `couleur` — elle ne dit rien
            // du coût, de la marge ni du stock.
            ['id', 'nom', 'prix', 'tva', 'couleur', 'image'],
            array_keys($article),
            'Le catalogue expose exactement ces clés, pas une de plus.',
        );
    }

    public function testReponseDEncaissementNExposeAucuneDonneeDeGestion(): void
    {
        $this->client->request('POST', '/api/vente', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleId, 'quantite' => 2]],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 30000]],
        ]));

        $this->assertResponseStatusCodeSame(201);

        $charge = $this->client->getResponse()->getContent();
        $this->assertAucunTermeSensible($charge, "la réponse d'encaissement");

        $donnees = json_decode($charge, true);
        $this->assertSame(
            ['ok', 'uuid', 'numero', 'statut', 'totalHt', 'totalTva', 'totalTtc', 'remise', 'rendu'],
            array_keys($donnees),
            "La réponse d'encaissement expose exactement ces clés.",
        );
    }

    public function testReponseDErreurNExposePasLInterneDuServeur(): void
    {
        $this->client->request('POST', '/api/vente', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => 999999, 'quantite' => 1]],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 30000]],
        ]));

        $this->assertResponseStatusCodeSame(400);

        $charge = $this->client->getResponse()->getContent();
        $this->assertAucunTermeSensible($charge, "un message d'erreur");
        $this->assertStringNotContainsString('App\\', $charge, 'Aucune classe PHP dans un message métier.');
        $this->assertSame(['ok', 'erreur'], array_keys(json_decode($charge, true)));
    }

    public function testTicketDeCaisseNAfficheNiCoutNiMarge(): void
    {
        $this->client->request('POST', '/api/vente', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'uuid' => $uuid = (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleId, 'quantite' => 1]],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 15000]],
        ]));

        // Vue HTML 80 mm.
        $this->client->request('GET', '/caisse/ticket/'.$uuid);
        $this->assertResponseIsSuccessful();
        $this->assertAucunTermeSensible($this->client->getResponse()->getContent(), 'le ticket de caisse');

        // Flux ESC/POS, qui contient les mêmes données sous une autre forme.
        $this->client->request('GET', '/caisse/ticket/'.$uuid.'/escpos');
        $this->assertResponseIsSuccessful();

        $donnees = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertAucunTermeSensible(base64_decode($donnees['base64']), 'le flux ESC/POS');
    }

    public function testEcranDeCaisseNAfficheAucunAgregat(): void
    {
        $this->client->request('GET', '/caisse');
        $this->assertResponseIsSuccessful();

        $corps = $this->client->getResponse()->getContent();

        // Le bandeau d'alerte stock nomme des matières : on tolère « stock », mais
        // aucune donnée chiffrée de gestion ne doit apparaître.
        foreach (['marge', 'coût de revient', 'cout de revient', 'panier moyen', "chiffre d'affaires"] as $terme) {
            $this->assertStringNotContainsString(
                $terme,
                mb_strtolower($corps),
                \sprintf('« %s » ne doit pas apparaître sur l\'écran de caisse.', $terme),
            );
        }
    }

    public function testLeCaissierNObtientPasLeCatalogueDUnAutreEtablissementParErreur(): void
    {
        // Garde-fou de régression : le catalogue reste limité aux articles actifs.
        $inactif = new Article('Produit retiré', 99900, 'pièce');
        $inactif->setActif(false);
        $this->em->persist($inactif);
        $this->em->flush();

        $this->client->request('GET', '/caisse/catalogue.json');

        $this->assertStringNotContainsString('Produit retiré', $this->client->getResponse()->getContent());
    }
}
