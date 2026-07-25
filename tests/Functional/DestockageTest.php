<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\FicheTechnique;
use App\Entity\LigneFicheTechnique;
use App\Entity\LigneVente;
use App\Entity\MatierePremiere;
use App\Entity\MouvementStock;
use App\Entity\Reglement;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\ModeReglement;
use App\Enum\ModeVente;
use App\Enum\StatutVente;
use App\Enum\TypeMouvementStock;
use App\Repository\MouvementStockRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DestockageTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Utilisateur $caissier;
    private SessionCaisse $session;
    private MatierePremiere $pain;
    private MatierePremiere $poulet;
    private MatierePremiere $huile;
    private Article $sandwich;
    private int $numeroSeq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'session_caisse', 'mouvement_stock', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->caissier = new Utilisateur('caissier@test.ci', 'Caissier');
        $this->caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($this->caissier);

        $famille = new FamilleProduit('Fast-food');
        $this->em->persist($famille);

        // Matières premières (stock en millièmes d'unité).
        $this->pain = (new MatierePremiere('Pain burger', 'pièce'))->setStockActuel(100000);   // 100 pièces
        $this->poulet = (new MatierePremiere('Poulet', 'kg'))->setStockActuel(50000);          // 50 kg
        $this->huile = (new MatierePremiere('Huile', 'litre'))->setStockActuel(20000);         // 20 L
        $this->em->persist($this->pain);
        $this->em->persist($this->poulet);
        $this->em->persist($this->huile);

        // Article « Sandwich » avec fiche technique (par sandwich).
        $this->sandwich = new Article('Sandwich poulet', 150000, 'pièce');
        $this->sandwich->setFamilleProduit($famille)->setTauxTva(1800);
        $this->em->persist($this->sandwich);

        $fiche = new FicheTechnique($this->sandwich);
        new LigneFicheTechnique($fiche, $this->pain, 1000, 0);    // 1 pain, 0 % perte
        new LigneFicheTechnique($fiche, $this->poulet, 100, 0);   // 0,1 kg, 0 % perte
        new LigneFicheTechnique($fiche, $this->huile, 10, 5000);  // 0,01 L, 50 % perte
        $this->em->persist($fiche);

        $this->session = new SessionCaisse($this->caissier, 0);
        $this->em->persist($this->session);

        $this->em->flush(); // aucune vente ici : le listener n'agit pas encore.
    }

    private function vendre(Article $article, int $unites, int $prix): Vente
    {
        $ttc = $unites * $prix;
        $vente = new Vente($this->session, ModeVente::FASTFOOD, \sprintf('VT-%05d', ++$this->numeroSeq), $ttc, 0, $ttc);
        new LigneVente($vente, $article, $unites * 1000, $prix, 0, null);
        new Reglement($vente, ModeReglement::ESPECES, $ttc, null);
        $this->em->persist($vente);
        $this->em->flush();

        return $vente;
    }

    private function mouvements(): MouvementStockRepository
    {
        return static::getContainer()->get(MouvementStockRepository::class);
    }

    public function testVenteDeDixSandwichsDecrementeLesMatieres(): void
    {
        $this->vendre($this->sandwich, 10, 150000);

        // 10 × 1 pain = 10 → 100 − 10 = 90 pièces
        $this->assertSame(90000, $this->pain->getStockActuel());
        // 10 × 0,1 kg = 1 kg → 50 − 1 = 49 kg
        $this->assertSame(49000, $this->poulet->getStockActuel());
        // 10 × 0,01 L ÷ (1 − 0,5) = 0,2 L → 20 − 0,2 = 19,8 L
        $this->assertSame(19800, $this->huile->getStockActuel());

        // Un MouvementStock SORTIE_VENTE par matière.
        $sorties = $this->mouvements()->findBy(['type' => TypeMouvementStock::SORTIE_VENTE]);
        $this->assertCount(3, $sorties);
        foreach ($sorties as $sortie) {
            $this->assertLessThan(0, $sortie->getQuantite(), 'Une sortie de stock doit être négative.');
            $this->assertSame('vente', $sortie->getSourceType());
        }
    }

    public function testAnnulationRestaureLeStock(): void
    {
        $vente = $this->vendre($this->sandwich, 10, 150000);
        $this->assertSame(90000, $this->pain->getStockActuel());

        $vente->annuler('Erreur de saisie');
        $this->em->flush();

        // Mouvements inverses générés : stock restauré.
        $this->assertSame(100000, $this->pain->getStockActuel());
        $this->assertSame(50000, $this->poulet->getStockActuel());
        $this->assertSame(20000, $this->huile->getStockActuel());

        $this->assertCount(3, $this->mouvements()->findBy(['type' => TypeMouvementStock::ENTREE]));
        $this->assertSame(StatutVente::ANNULEE, $vente->getStatut());
    }

    public function testStockPeutDevenirNegatifSansBloquer(): void
    {
        $this->huile->setStockActuel(100); // 0,1 L seulement
        $this->em->flush();

        $this->vendre($this->sandwich, 10, 150000); // besoin de 0,2 L

        $this->assertSame(-100, $this->huile->getStockActuel(), 'La vente n\'est jamais bloquée : le stock passe en négatif.');
    }

    public function testArticleSuiviEnStockEstDecrementeDirectement(): void
    {
        $famille = $this->sandwich->getFamilleProduit();
        $eau = new Article('Eau minérale', 30000, 'pièce');
        $eau->setFamilleProduit($famille)->setTauxTva(1800)->setSuiviStock(true)->setStockActuel(10000); // 10 bouteilles
        $this->em->persist($eau);
        $this->em->flush();

        $this->vendre($eau, 3, 30000);

        $this->assertSame(7000, $eau->getStockActuel()); // 10 − 3 = 7 bouteilles
        $sortie = $this->mouvements()->findOneBy(['article' => $eau, 'type' => TypeMouvementStock::SORTIE_VENTE]);
        $this->assertInstanceOf(MouvementStock::class, $sortie);
        $this->assertSame(-3000, $sortie->getQuantite());
    }
}
