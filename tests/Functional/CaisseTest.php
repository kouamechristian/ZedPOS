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
     * reçu s'étirait à la largeur du panneau au lieu de celle du papier. La
     * caissière comparait alors un reçu à l'écran qui ne ressemblait pas au ticket
     * qu'elle tendait au client.
     */
    public function testLeRecuEtLeTicketPartagentLeMemeFormat(): void
    {
        $this->client->request('GET', '/caisse');
        $ecranCaisse = (string) $this->client->getResponse()->getContent();

        // La largeur du papier doit être imposée sur l'écran de caisse aussi.
        $this->assertStringContainsString(
            'width: 58mm',
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
            '.recu-58mm',
            $ecranCaisse,
            'Les règles du ticket ne doivent pas être recopiées dans l\'écran de caisse.',
        );
    }

    /**
     * Le ticket sort sur une tête thermique, qui chauffe un point ou ne le chauffe
     * pas : elle ne connaît pas le gris.
     *
     * Courier New, qui avait l'air d'un ticket de caisse, en sortait pâle et haché
     * — ses traits font un pixel, le navigateur les lisse en gris, et chaque pixel
     * gris tombe au hasard d'un côté ou de l'autre. Le retour en arrière serait
     * invisible à l'écran, où Courier s'affiche parfaitement : il ne se verrait
     * qu'au comptoir, sur le papier déjà sorti.
     */
    public function testLeTicketEstDansUnePoliceQuiSortSurUneTeteThermique(): void
    {
        $this->client->request('GET', '/caisse');
        $ecranCaisse = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString(
            'font-family: Tahoma, Verdana',
            $ecranCaisse,
            'Le ticket doit être dans une police sans empattement à traits épais.',
        );

        $this->assertStringNotContainsString(
            "font-family: 'Courier New'",
            $ecranCaisse,
            'Courier New sort pâle et haché sur une imprimante thermique.',
        );

        // Les demi-teintes sont un semis de points sur du papier thermique : plus
        // sale que du noir franc, et pas plus discret.
        $this->assertStringContainsString(
            '-webkit-font-smoothing: none',
            $ecranCaisse,
            "Le lissage doit être coupé à l'impression.",
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
     * L'écran de caisse tient sur trois repères visuels : gris clair en fond,
     * blanc sur ce qui est retenu, vert sur l'argent.
     *
     * Ce que ce test protège vraiment, c'est la **séparation** : un état retenu
     * et le bouton qui engage l'argent ne doivent jamais porter la même couleur.
     * C'est le défaut de tout habillage à accent unique, et il coûte des
     * encaissements par mégarde au moment de la file du matin.
     */
    public function testLesTroisCouleursDeLaCaisseNeSeConfondentPas(): void
    {
        $this->client->request('GET', '/caisse');
        $corps = (string) $this->client->getResponse()->getContent();

        // Gris clair : le fond commun au projet.
        $this->assertStringContainsString('--nuit: #f3f4f6;', $corps, 'Le fond de page doit être gris clair.');

        // Blanc : ce qui est retenu, et rien d'autre. Un aplat plein, jamais un
        // simple changement de ton — invisible en plein jour derrière le comptoir.
        $this->assertStringContainsString('.onglet[data-actif]', $corps);
        $this->assertStringContainsString('background: #ffffff;', $corps);
        $this->assertStringContainsString('--texte: #1f2937;', $corps, 'La caisse doit utiliser une encre sombre.');

        // Vert : l'argent. Le bouton qui l'encaisse le porte, et le prend d'un
        // jeton partagé — pas d'une teinte écrite sur place, qui échapperait au
        // thème le jour où il change.
        $this->assertStringContainsString('--vert: #22c55e;', $corps, 'Encaisser doit rester vert.');
        $this->assertStringContainsString(
            '.caisse .encaisser {'."\n".'    background: var(--vert);',
            $corps,
            'Encaisser prend la couleur de validation, pas une teinte écrite sur place.',
        );

        // La séparation elle-même : l'état retenu n'est pas vert, le bouton
        // d'encaissement n'est pas blanc.
        $this->assertStringNotContainsString(
            '.onglet[data-actif] { background: var(--vert)',
            $corps,
            'Une famille retenue ne prend jamais le vert de l’argent.',
        );
        $this->assertStringNotContainsString(
            '.caisse .encaisser {'."\n".'    background: var(--blanc);',
            $corps,
            'Encaisser ne prend jamais le blanc des états retenus.',
        );

        // Le slate bleuté par défaut de Tailwind reste proscrit.
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
        $touches = $this->client->getCrawler()->filter('[data-action="ticket#appuyerMontant"]')->each(
            static fn ($touche) => $touche->attr('data-chiffre'),
        );
        sort($touches, \SORT_STRING);
        $this->assertSame(['0', '000', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $touches, 'Le pavé propose les dix chiffres et la touche « 000 ».');
        $this->assertSelectorExists('[data-action="ticket#effacerMontant"]', 'Le pavé doit permettre de vider le montant.');
        $this->assertSelectorExists('[data-action="ticket#reculerMontant"]', 'Le pavé doit permettre d’effacer le dernier chiffre.');

        // Le pavé est à l'écran : le clavier du système ne doit pas s'ouvrir par-dessus
        // et recouvrir Encaisser sur la tablette du comptoir.
        $champ = $this->client->getCrawler()->filter('[data-ticket-target="montantRecu"]');
        $this->assertSame('none', $champ->attr('inputmode'));
        $this->assertSame('Compte juste', $champ->attr('placeholder'), 'Le champ vide vaut compte juste.');
    }

    /**
     * L'annulation du ticket qui vient d'être encaissé se fait depuis le reçu, et
     * jamais d'un seul appui : elle est irréversible, tracée et notifiée à la
     * dirigeante. Le motif est obligatoire côté serveur — le bouton de
     * confirmation naît donc désactivé, c'est le choix du motif qui le libère.
     */
    public function testLeRecuOffreLAnnulationDuTicketDerriereUnMotif(): void
    {
        $this->client->request('GET', '/caisse');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-action="ticket#ouvrirAnnulation"]');
        $this->assertSelectorExists('[data-ticket-target="annulation"].hidden', "Le choix du motif reste replié tant qu'on ne l'ouvre pas.");

        // Motifs proposés d'un appui : au comptoir, taper coûte plus cher que le
        // geste qu'on corrige. Le champ libre reste, pour tout le reste.
        $this->assertGreaterThanOrEqual(
            3,
            $this->client->getCrawler()->filter('[data-ticket-target="motif"]')->count(),
            'Les motifs courants sont proposés sans passer par le clavier.',
        );
        $this->assertSelectorExists('[data-ticket-target="motifLibre"]');

        $confirmer = $this->client->getCrawler()->filter('[data-ticket-target="confirmerAnnulation"]');
        $this->assertNotNull($confirmer->attr('disabled'), 'Aucun motif retenu : rien ne part.');
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
