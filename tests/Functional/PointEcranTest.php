<?php

namespace App\Tests\Functional;

use App\Entity\Arrete;
use App\Entity\Article;
use App\Entity\BonDotation;
use App\Entity\DetteVendeur;
use App\Entity\Emplacement;
use App\Entity\Utilisateur;
use App\Entity\Vendeur;
use App\Enum\ModeRemuneration;
use App\Enum\StatutDette;
use App\Enum\TraitementEcart;
use App\Enum\TypeDette;
use App\Enum\TypeEmplacement;
use App\Repository\ArreteRepository;
use App\Repository\DetteVendeurRepository;
use App\Service\BonDotationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Écran du point. Les dates sont relatives au jour réel : l'écran, lui, raisonne
 * sur aujourd'hui.
 */
class PointEcranTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $gerante;
    private Utilisateur $dirigeante;
    private Vendeur $awa;
    private Emplacement $stand;
    private Article $baguette;
    private \DateTimeImmutable $avantHier;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['remboursement_dette', 'dette_vendeur', 'ligne_retour', 'bon_retour', 'ligne_dotation', 'bon_dotation', 'arrete', 'vendeur', 'ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'stock_courant', 'perte', 'article', 'matiere_premiere', 'famille_produit', 'journal_audit', 'notification', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement("DELETE FROM emplacement WHERE code <> 'DEPOT'");
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->gerante = (new Utilisateur('mariam@test.ci', 'Mariam'))->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->dirigeante = (new Utilisateur('aya@test.ci', 'Aya'))->setRoles(['ROLE_DIRIGEANTE'])->setMotDePasse('x');
        $this->awa = new Vendeur('Awa');
        $this->stand = (new Emplacement('STAND-GARE', 'Stand de la gare', TypeEmplacement::STAND))
            ->setVendeurHabituel($this->awa)->setTauxCommission(1000)->setSeuilEcartAlerte(50000);
        $this->baguette = (new Article('Baguette', 15000, 'pièce'))->setPrixCession(12500);

        foreach ([$this->gerante, $this->dirigeante, $this->awa, $this->stand, $this->baguette] as $entite) {
            $this->em->persist($entite);
        }
        $this->em->flush();

        $this->avantHier = new \DateTimeImmutable('today -2 days');
    }

    private function doter(\DateTimeImmutable $date, int $baguettes, bool $valider = true): BonDotation
    {
        $service = static::getContainer()->get(BonDotationService::class);
        $bon = $service->creer($this->stand, $this->awa, $date, [$this->baguette->getId() => $baguettes], $this->gerante);
        if ($valider) {
            $service->valider($bon, $this->gerante);
        }

        return $bon;
    }

    private function url(): string
    {
        return '/admin/points/stand/'.$this->stand->getId();
    }

    /** @param array<string, string> $champs */
    private function soumettre(string $bouton, array $champs): void
    {
        $crawler = $this->client->request('GET', $this->url());
        $formulaire = $crawler->selectButton($bouton)->form();
        foreach ($champs as $nom => $valeur) {
            $formulaire[$nom] = $valeur;
        }
        $this->client->submit($formulaire);
    }

    private function arrete(): ?Arrete
    {
        $this->em->clear();

        return static::getContainer()->get(ArreteRepository::class)->findOneBy([], ['id' => 'DESC']);
    }

    // ------------------------------------------------------------ Accès

    public function testSeuleLaGeranteEtLaDirigeanteFontLePoint(): void
    {
        foreach (['/admin/points', '/admin/points/nouveau', $this->url()] as $url) {
            foreach ([$this->gerante, $this->dirigeante] as $autorise) {
                $this->client->loginUser($autorise);
                $this->client->request('GET', $url);
                $this->assertResponseIsSuccessful($autorise->getEmail().' sur '.$url);
            }

            foreach (['ROLE_CAISSIER', 'ROLE_COMPTABLE'] as $role) {
                $compte = (new Utilisateur(uniqid().'@test.ci', $role))->setRoles([$role]);
                'ROLE_CAISSIER' === $role ? $compte->setCodePin('x') : $compte->setMotDePasse('x');
                $this->em->persist($compte);
                $this->em->flush();
                $this->client->loginUser($compte);
                $this->client->request('GET', $url);
                $this->assertResponseStatusCodeSame(403, $role.' sur '.$url);
            }
        }
    }

    // ------------------------------------------------------------ Écran

    /** Le stand choisi, la période s'affiche aussitôt ; il n'existe aucun champ pour le début. */
    public function testLaPeriodeAArreterSAfficheEtLeDebutNeSeSaisitPas(): void
    {
        $this->doter($this->avantHier, 40);
        $this->client->loginUser($this->gerante);

        $crawler = $this->client->request('GET', '/admin/points/nouveau');
        $this->assertSelectorTextContains('body', 'À arrêter depuis le '.$this->avantHier->format('d/m/Y'));

        $crawler = $this->client->request('GET', $this->url());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Période à arrêter');
        $this->assertSelectorTextContains('body', 'du '.$this->avantHier->format('d/m').' au '.(new \DateTimeImmutable('today'))->format('d/m/Y'));

        $this->assertCount(0, $crawler->filter('[name="debut"], [name="dateDebut"]'), 'Le début ne se saisit jamais.');
        $this->assertCount(1, $crawler->filter('a[href*="fin='.(new \DateTimeImmutable('today'))->format('Y-m-d').'"]:contains("Aujourd\'hui")'));
        $this->assertSelectorTextContains('body', 'Fin de semaine');
        $this->assertSelectorTextContains('body', 'Fin de mois');

        // Tableau pré-rempli, une case invendus et une case pertes par produit.
        $this->assertCount(1, $crawler->filter('[data-point-target="ligne"]'));
        $this->assertCount(1, $crawler->filter('input[name="retours['.$this->baguette->getId().'][invendu]"]'));
        $this->assertCount(1, $crawler->filter('input[name="retours['.$this->baguette->getId().'][perdu]"]'));
        $this->assertSame('1000', $crawler->filter('[data-controller="point"]')->attr('data-point-taux-value'));
    }

    public function testValiderLePointDepuisLEcran(): void
    {
        $this->doter($this->avantHier, 40);
        $this->client->loginUser($this->gerante);

        // 34 vendues × 150 = 5 100 F − 10 % = 4 590 F.
        $this->soumettre('Valider le point', [
            'vendeur' => (string) $this->awa->getId(),
            'retours['.$this->baguette->getId().'][invendu]' => '4',
            'retours['.$this->baguette->getId().'][perdu]' => '2',
            'retours['.$this->baguette->getId().'][motif]' => 'PERIME',
            'remis' => '4 590',
        ]);

        $arrete = $this->arrete();
        $this->assertResponseRedirects('/admin/points/'.$arrete->getId(), 303);
        $this->assertTrue($arrete->estValide());
        $this->assertSame($this->avantHier->format('Y-m-d'), $arrete->getDateDebut()->format('Y-m-d'));
        $this->assertSame([459000, 0], [$arrete->getNetARemettre(), $arrete->getEcart()]);
    }

    /** Un « début » glissé dans la requête n'a aucun effet : le serveur le calcule. */
    public function testUnDebutForgeEstIgnore(): void
    {
        $this->doter($this->avantHier, 10);
        $this->client->loginUser($this->gerante);

        $crawler = $this->client->request('GET', $this->url());
        $valeurs = $crawler->selectButton('Enregistrer le brouillon')->form()->getPhpValues();
        $valeurs['vendeur'] = (string) $this->awa->getId();
        $valeurs['debut'] = '2020-01-01';
        $valeurs['dateDebut'] = '2020-01-01';
        $valeurs['action'] = 'brouillon';
        $this->client->request('POST', $this->url(), $valeurs);

        $this->assertResponseRedirects($this->url(), 303);
        $this->assertSame($this->avantHier->format('Y-m-d'), $this->arrete()->getDateDebut()->format('Y-m-d'));
    }

    /** Retourner plus que confié : écran réaffiché en 422, saisie conservée. */
    public function testUnRetourAuDelaDuConfieReafficheLEcranEn422(): void
    {
        $this->doter($this->avantHier, 10);
        $this->client->loginUser($this->gerante);

        $this->soumettre('Enregistrer le brouillon', [
            'vendeur' => (string) $this->awa->getId(),
            'retours['.$this->baguette->getId().'][invendu]' => '12',
            'remis' => '300',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('[role="alert"]', 'Baguette');
        $crawler = $this->client->getCrawler();
        $this->assertSame('12', $crawler->filter('input[name="retours['.$this->baguette->getId().'][invendu]"]')->attr('value'));
        $this->assertSame('300', $crawler->filter('input[name="remis"]')->attr('value'));
        $this->assertNull($this->arrete());
    }

    /** Une dotation en brouillon sur la période est affichée, et la validation renvoie à elle. */
    public function testUneDotationEnBrouillonEstSignaleeEtBloque(): void
    {
        $this->doter($this->avantHier, 10);
        $brouillon = $this->doter(new \DateTimeImmutable('yesterday'), 5, valider: false);
        $this->client->loginUser($this->gerante);

        $crawler = $this->client->request('GET', $this->url());
        $this->assertCount(1, $crawler->filter('a[href="/admin/dotations/'.$brouillon->getId().'"]'));
        $this->assertSame('true', $crawler->filter('[data-controller="point"]')->attr('data-point-brouillons-value'));

        $this->soumettre('Valider le point', ['vendeur' => (string) $this->awa->getId(), 'remis' => '1350']);

        $this->assertResponseRedirects($this->url(), 303);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', $brouillon->getNumero());
        $this->assertTrue($this->arrete()->estBrouillon(), 'La saisie est gardée en brouillon.');
    }

    /** Écart de 700 F pour un seuil de 500 : refusé sans justification, accepté avec. */
    public function testUnEcartAuDelaDuSeuilDemandeUneJustification(): void
    {
        $this->doter($this->avantHier, 10);   // net 1 350 F
        $this->client->loginUser($this->gerante);

        $this->soumettre('Valider le point', ['vendeur' => (string) $this->awa->getId(), 'remis' => '650']);
        $this->assertResponseRedirects($this->url(), 303);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'justification est obligatoire');

        $this->soumettre('Valider le point', ['vendeur' => (string) $this->awa->getId(), 'remis' => '650', 'commentaire' => 'Pièces refusées', 'traitement' => 'PERTE']);
        $arrete = $this->arrete();
        $this->assertTrue($arrete->estValide());
        $this->assertSame(-70000, $arrete->getEcart());
        $this->assertSame(TraitementEcart::PERTE, $arrete->getTraitementEcart());
        $this->assertNull(static::getContainer()->get(DetteVendeurRepository::class)->deArrete($arrete), 'Passé en perte : aucune dette.');
    }

    /**
     * Un manquant ne se traite jamais par défaut : rien n'est coché à l'écran, et le
     * serveur refuse la validation tant que la gérante n'a pas choisi.
     */
    public function testUnManquantExigeDeChoisirEntreDetteEtPerte(): void
    {
        $this->doter($this->avantHier, 10);   // net 1 350 F
        $this->client->loginUser($this->gerante);

        $crawler = $this->client->request('GET', $this->url());
        $this->assertCount(2, $crawler->filter('input[type="radio"][name="traitement"]'));
        $this->assertCount(0, $crawler->filter('input[name="traitement"][checked]'), 'Aucun choix d\'office.');

        $this->soumettre('Valider le point', ['vendeur' => (string) $this->awa->getId(), 'remis' => '1000']);   // manque 350 F
        $this->assertResponseRedirects($this->url(), 303);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Il manque 350 FCFA');
        $this->assertTrue($this->arrete()->estBrouillon());
        $this->assertSame([], $this->em->getRepository(DetteVendeur::class)->findAll(), 'Jamais de dette sans choix.');

        // Le choix revient avec le brouillon.
        $this->soumettre('Valider le point', ['vendeur' => (string) $this->awa->getId(), 'remis' => '1000', 'traitement' => 'DETTE']);
        $arrete = $this->arrete();
        $this->assertResponseRedirects('/admin/points/'.$arrete->getId(), 303);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Dette de 350 FCFA ouverte au nom de Awa');
        $this->assertSelectorTextContains('body', 'Imputé en dette du vendeur');

        $dette = static::getContainer()->get(DetteVendeurRepository::class)->deArrete($arrete);
        $this->assertSame([35000, TypeDette::ECART_CAISSE, StatutDette::OUVERTE], [$dette->getMontant(), $dette->getType(), $dette->getStatut()]);

        $fiche = $this->client->request('GET', '/admin/points/'.$arrete->getId().'/fiche')->filter('body')->text();
        $this->assertStringContainsString('imputé en dette de Awa, qui reconnaît devoir 350 FCFA', $fiche);
    }

    // ------------------------------------------------------ Fiche, annulation

    public function testLaFicheImprimableEtLAnnulation(): void
    {
        $this->doter($this->avantHier, 10);
        $this->client->loginUser($this->gerante);
        $this->soumettre('Valider le point', ['vendeur' => (string) $this->awa->getId(), 'remis' => '1350']);
        $arrete = $this->arrete();

        $crawler = $this->client->request('GET', '/admin/points/'.$arrete->getId().'/fiche');
        $this->assertResponseIsSuccessful();
        $texte = $crawler->filter('body')->text();
        foreach (['Période du '.$this->avantHier->format('d/m/Y'), 'Net à remettre', '1 350 FCFA', 'Espèces remises', 'Écart', 'La gérante', 'Le vendeur'] as $attendu) {
            $this->assertStringContainsString($attendu, $texte);
        }
        $this->assertStringNotContainsString('sans valeur', $texte);

        $crawler = $this->client->request('GET', '/admin/points/'.$arrete->getId());
        $this->client->submit($crawler->selectButton('Annuler ce point')->form(['motif' => 'Erreur de saisie']));
        $this->assertResponseRedirects('/admin/points/'.$arrete->getId(), 303);
        $this->assertTrue($this->arrete()->estAnnule());
    }

    // --------------------------------------------------------- Réglages

    /** Mode et taux : dirigeante seule. La gérante règle le seuil d'écart. */
    public function testLaRemunerationDuStandSeFixeParLaDirigeanteSeule(): void
    {
        $url = '/admin/stands/'.$this->stand->getId().'/modifier';

        $this->client->loginUser($this->gerante);
        $crawler = $this->client->request('GET', $url);
        $this->assertCount(0, $crawler->filter('[name="stand[modeRemuneration]"], [name="stand[tauxCommission]"]'));
        $this->assertCount(1, $crawler->filter('[name="stand[seuilEcartAlerte]"]'));
        $this->assertSelectorTextContains('body', 'Seule la dirigeante peut fixer la rémunération');

        $this->client->loginUser($this->dirigeante);
        $crawler = $this->client->request('GET', $url);
        $this->assertSame('10', $crawler->filter('[name="stand[tauxCommission]"]')->attr('value'));
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'stand[modeRemuneration]' => ModeRemuneration::MARGE->value,
            'stand[tauxCommission]' => '7,5',
            'stand[seuilEcartAlerte]' => '250',
        ]));
        $this->assertResponseRedirects('/admin/stands', 303);

        $this->em->clear();
        $stand = $this->em->getRepository(Emplacement::class)->find($this->stand->getId());
        $this->assertSame([ModeRemuneration::MARGE, 750, 25000], [$stand->getModeRemuneration(), $stand->getTauxCommission(), $stand->getSeuilEcartAlerte()]);
    }
}
