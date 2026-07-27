<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Repository\VenteRepository;
use App\Service\SessionCaisseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CaisseTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $articleId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $caissier = new Utilisateur('caissier@test.ci', 'Caissier Test');
        $caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($caissier);

        $famille = (new FamilleProduit('Pains'))->setActif(true);
        $this->em->persist($famille);

        $article = new Article('Baguette', 15000, 'pièce'); // 150 FCFA, TVA 0
        $article->setFamilleProduit($famille)->setActif(true)->setTauxTva(0);
        $this->em->persist($article);

        $this->em->flush();
        $this->articleId = $article->getId();

        // Aucune vente n'est possible sans session de caisse ouverte.
        static::getContainer()->get(SessionCaisseService::class)->ouvrir($caissier, 3000000);

        $this->client->loginUser($caissier);
    }

    /**
     * L'écran de caisse enregistre ses ventes via POST /api/vente, seul point
     * d'entrée idempotent (il n'existe plus de second chemin d'écriture).
     */
    private function encaisser(array $charge): array
    {
        $this->client->request('POST', '/api/vente', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($charge));

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testPageCaisseAfficheLeCatalogue(): void
    {
        $this->client->request('GET', '/caisse');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Baguette');
        $this->assertSelectorExists('[data-controller="ticket"]');
    }

    /**
     * Le reçu montré à la caissière et le ticket imprimé partagent leur feuille de
     * style (`ticket/_styles.html.twig`).
     *
     * Les deux gabarits ont déjà eu chacun leur copie des mêmes règles, et elles
     * avaient divergé : la caisse avait oublié `.ticket` lui-même, si bien que le
     * reçu s'étirait à la largeur du panneau au lieu des 80 mm du papier. La
     * caissière comparait alors un reçu à l'écran qui ne ressemblait pas au ticket
     * qu'elle tendait au client.
     */
    public function testLeRecuEtLeTicketPartagentLeMemeFormat(): void
    {
        $this->client->request('GET', '/caisse');
        $ecranCaisse = (string) $this->client->getResponse()->getContent();

        // La largeur du papier doit être imposée sur l'écran de caisse aussi.
        $this->assertStringContainsString(
            'width: 80mm',
            $ecranCaisse,
            'L\'écran de caisse doit inclure ticket/_styles.html.twig.',
        );

        // Marqueur propre au fichier partagé : s'il disparaît, quelqu'un a
        // recopié les règles au lieu de les inclure.
        foreach (['.ticket .row .d', '.ticket .sep', '.ticket .total .d'] as $regle) {
            $this->assertStringContainsString($regle, $ecranCaisse, 'Règle partagée absente : '.$regle);
        }

        // Aucune redéfinition locale : c'est ce qui avait provoqué la divergence.
        $this->assertStringNotContainsString(
            '.recu-80mm',
            $ecranCaisse,
            'Les règles du ticket ne doivent pas être recopiées dans l\'écran de caisse.',
        );
    }

    /**
     * Les teintes des touches produits sont écrites deux fois — dans le gabarit Twig
     * (premier affichage) et dans le contrôleur Stimulus (rendu depuis IndexedDB).
     * Elles doivent rester identiques, sinon les produits changent de couleur au
     * rechargement de la page, ou hors ligne.
     */
    public function testLesTeintesProduitsSontIdentiquesCoteTwigEtCoteJs(): void
    {
        $racine = \dirname(__DIR__, 2);
        $motif = "/fond:\s*'(#[0-9a-fA-F]{6})',\s*texte:\s*'(#[0-9a-fA-F]{6})'/";

        preg_match_all($motif, (string) file_get_contents($racine.'/templates/caisse/index.html.twig'), $twig);
        preg_match_all($motif, (string) file_get_contents($racine.'/assets/controllers/ticket_controller.js'), $js);

        $this->assertNotEmpty($twig[0], 'Palette introuvable dans le gabarit de caisse.');
        $this->assertSame(
            array_map(null, $twig[1], $twig[2]),
            array_map(null, $js[1], $js[2]),
            'La palette du gabarit Twig et celle de ticket_controller.js ont divergé.',
        );
    }

    /**
     * L'écran de caisse suit l'identité documentée : ambre sur la famille active
     * et fond crème. Un écran monochrome ne permet pas de repérer la sélection en
     * plein jour derrière le comptoir.
     */
    public function testLesEtatsActifsSontEnAmbre(): void
    {
        $this->client->request('GET', '/caisse');
        $corps = (string) $this->client->getResponse()->getContent();

        // amber-700 : la couleur des actions et des états actifs.
        $this->assertStringContainsString('.onglet[data-actif]', $corps);
        $this->assertStringContainsString('#b45309', $corps, 'La sélection doit être en ambre (amber-700).');
        $this->assertStringContainsString('bg-amber-50', $corps, 'Le fond de page est le crème amber-50.');

        // Le bouton d'encaissement reste vert : convention forte en caisse.
        // green-800 depuis que les règlements portent les couleurs des réseaux.
        $this->assertStringContainsString('bg-green-800', $corps, 'Encaisser doit rester vert.');
        $this->assertStringNotContainsString('bg-amber-700 py-4', $corps, "Encaisser n'est jamais en ambre.");

        // Le slate bleuté est proscrit par l'identité visuelle.
        $this->assertStringNotContainsString('slate-', $corps);
    }

    /**
     * Les moyens de paiement portent la couleur de leur opérateur : la caissière
     * reconnaît un bleu Wave ou un jaune MTN avant d'avoir lu le libellé.
     *
     * Ces teintes sont des **logos**, pas un choix esthétique : les repeindre aux
     * couleurs de l'application supprimerait précisément ce qui les rend
     * identifiables d'un coup d'œil.
     */
    public function testLesReglementsPortentLesCouleursDesReseaux(): void
    {
        $this->client->request('GET', '/caisse');
        $corps = (string) $this->client->getResponse()->getContent();

        foreach ([
            'WAVE' => '#1dc8ff',
            'ORANGE_MONEY' => '#ff7900',
            'MTN_MOMO' => '#ffcc00',
            'MOOV_MONEY' => '#0a4ea3',
            'ESPECES' => '#44403c',
        ] as $mode => $teinte) {
            $this->assertStringContainsString(
                \sprintf('.reglement[data-mode="%s"] { --teinte: %s;', $mode, $teinte),
                $corps,
                $mode.' doit porter la couleur de son réseau.',
            );
        }

        // Sélection : aplat plein de la couleur du réseau, jamais un simple liseré.
        $this->assertStringContainsString('.reglement[data-actif]', $corps);
        $this->assertStringContainsString('background: var(--teinte);', $corps);
    }

    public function testBandeauDeSynchronisationPresent(): void
    {
        $this->client->request('GET', '/caisse');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-controller="synchronisation"]', "Le bandeau d'état est permanent.");
    }

    public function testEncaissementCreeUneVente(): void
    {
        $reponse = $this->encaisser([
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleId, 'quantite' => 2, 'commentaire' => '']],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 30000]],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertTrue($reponse['ok'], 'Encaissement attendu réussi.');
        $this->assertSame(30000, $reponse['totalTtc']); // 2 × 150 FCFA

        $vente = static::getContainer()->get(VenteRepository::class)->findOneBy(['numero' => $reponse['numero']]);
        $this->assertInstanceOf(Vente::class, $vente);
        $this->assertSame(30000, $vente->getTotalTtc());
        $this->assertCount(1, $vente->getLignes());
        $this->assertSame(2000, $vente->getLignes()->first()->getQuantite()); // 2 unités en millièmes
        $this->assertCount(1, $vente->getReglements());
        $this->assertSame(30000, $vente->getReglements()->first()->getMontant());
    }

    /**
     * La caissière doit pouvoir saisir ce que le client lui a tendu, et lire la
     * monnaie sans quitter l'écran ni attendre le réseau.
     *
     * Le champ est **facultatif** : laissé vide il vaut « compte juste », sinon
     * la contrainte de vitesse de la file du matin serait perdue pour un champ à
     * remplir à chaque baguette.
     */
    public function testLEcranDeCaissePermetDeSaisirLeMontantRecu(): void
    {
        $this->client->request('GET', '/caisse');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-ticket-target="montantRecu"]');
        $this->assertSelectorExists('[data-ticket-target="renduMontant"]', 'La monnaie à rendre doit être affichée.');
        $this->assertSelectorExists('[data-ticket-target="suggestions"]', 'Les coupures courantes sont proposées.');

        // Pavé numérique sur la tablette du comptoir, pas de clavier alphabétique.
        $champ = $this->client->getCrawler()->filter('[data-ticket-target="montantRecu"]');
        $this->assertSame('numeric', $champ->attr('inputmode'));
        $this->assertSame('Compte juste', $champ->attr('placeholder'), 'Le champ vide vaut compte juste.');
    }

    /**
     * Le montant transmis est ce que le client a **tendu** ; le serveur en déduit
     * le rendu. Il n'est jamais calculé par le navigateur : l'écran renseigne la
     * caissière, il ne fait foi pour personne.
     */
    public function testLeServeurCalculeLaMonnaieAPartirDuMontantRecu(): void
    {
        $reponse = $this->encaisser([
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleId, 'quantite' => 2, 'commentaire' => '']],
            // Ticket de 300 FCFA, le client tend 1 000 FCFA.
            'reglements' => [['mode' => 'ESPECES', 'montant' => 100000]],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(30000, $reponse['totalTtc']);
        $this->assertSame(70000, $reponse['rendu'], 'Monnaie attendue : 700 FCFA.');

        $vente = static::getContainer()->get(VenteRepository::class)->findOneBy(['numero' => $reponse['numero']]);
        $this->assertSame(70000, $vente->getRendu());
        // Le règlement conserve la somme tendue : c'est elle que le Z retranche du
        // rendu pour retrouver les espèces réellement en tiroir.
        $this->assertSame(100000, $vente->getReglements()->first()->getMontant());
    }

    /**
     * La somme tendue et la monnaie figurent toutes deux sur le ticket remis au
     * client : c'est ce qui lui permet de vérifier son rendu après coup.
     */
    public function testLeTicketPorteLaSommeTendueEtLaMonnaieRendue(): void
    {
        $reponse = $this->encaisser([
            'uuid' => $uuid = (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleId, 'quantite' => 2, 'commentaire' => '']],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 100000]],
        ]);
        $this->assertSame(70000, $reponse['rendu']);

        $this->client->request('GET', '/caisse/ticket/'.$uuid);
        $this->assertResponseIsSuccessful();

        $ticket = $this->client->getCrawler()->filter('.ticket')->text();
        $this->assertStringContainsString('1 000 FCFA', $ticket, 'La somme tendue doit figurer sur le ticket.');
        $this->assertStringContainsString('700 FCFA', $ticket, 'La monnaie rendue doit figurer sur le ticket.');
        $this->assertSelectorExists('.ticket .rendu', 'Le rendu est mis en avant, comme le total.');
    }

    /**
     * Un paiement insuffisant est refusé : la vente ne peut pas être enregistrée
     * pour une somme que le client n'a pas donnée.
     */
    public function testUnPaiementInsuffisantEstRefuse(): void
    {
        $reponse = $this->encaisser([
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleId, 'quantite' => 2, 'commentaire' => '']],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 20000]], // 200 FCFA pour 300
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertFalse($reponse['ok']);
    }

    /**
     * Rendre la monnaie n'a de sens qu'en espèces : un paiement mobile ne peut pas
     * dépasser le total, sinon la caisse rendrait en billets un excédent qu'elle
     * n'a jamais reçu en tiroir.
     */
    public function testUnPaiementMobileNePeutPasDepasserLeTotal(): void
    {
        $reponse = $this->encaisser([
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleId, 'quantite' => 2, 'commentaire' => '']],
            'reglements' => [['mode' => 'WAVE', 'montant' => 100000]],
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertFalse($reponse['ok']);
    }

    public function testEncaissementRefuseUnTicketVide(): void
    {
        $reponse = $this->encaisser([
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 30000]],
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertFalse($reponse['ok']);
    }

    public function testAncienPointDEntreeNonIdempotentSupprime(): void
    {
        // Un second chemin d'écriture ruinerait la garantie « aucun doublon ».
        $this->client->request('POST', '/caisse/encaisser', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCaisseInaccessibleSansRoleCaissier(): void
    {
        $comptable = new Utilisateur('compta@test.ci', 'Comptable Test');
        $comptable->setRoles(['ROLE_COMPTABLE'])->setMotDePasse('x');
        $this->em->persist($comptable);
        $this->em->flush();

        $this->client->loginUser($comptable);
        $this->client->request('GET', '/caisse');
        $this->assertResponseStatusCodeSame(403);
    }
}
