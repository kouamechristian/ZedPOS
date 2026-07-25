<?php

namespace App\DataFixtures;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\FicheTechnique;
use App\Entity\LigneFicheTechnique;
use App\Entity\LigneVente;
use App\Entity\MatierePremiere;
use App\Entity\MouvementCaisse;
use App\Entity\MouvementStock;
use App\Entity\Reglement;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\CategorieDepense;
use App\Enum\ModeReglement;
use App\Enum\ModeVente;
use App\Enum\RoleUtilisateur;
use App\Enum\TypeMouvementCaisse;
use App\Enum\TypeMouvementStock;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Jeu de données réaliste pour une boulangerie + fast-food à Abengourou.
 *
 * Prix en FCFA saisis en clair puis convertis en centimes (× 100). Les quantités
 * de vente et de stock sont en millièmes d'unité (× 1000). Les horodatages
 * immuables (createdAt, ouvertureAt…) sont fixés par réflexion pour rejouer
 * 30 jours d'historique sans altérer le modèle métier.
 */
class AppFixtures extends Fixture
{
    /** Répartition horaire du nombre de ventes (pics 5-9h et 18-21h). */
    private const POIDS_HORAIRE = [
        5 => 8, 6 => 14, 7 => 18, 8 => 16, 9 => 10, 10 => 6, 11 => 5,
        12 => 8, 13 => 8, 14 => 5, 15 => 4, 16 => 4, 17 => 6,
        18 => 12, 19 => 16, 20 => 14, 21 => 9, 22 => 3,
    ];

    /** Quantité maximale d'un article par ligne selon sa famille. */
    private const QTE_MAX = [
        'pains' => 4, 'viennoiseries' => 3, 'patisseries' => 2,
        'sandwichs' => 2, 'grillades' => 2, 'accompagnements' => 2, 'boissons' => 2,
    ];

    private int $numeroSeq = 0;

    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        mt_srand(20260724);

        $familles = $this->creerFamilles($manager);
        $articles = $this->creerArticles($manager, $familles);
        $matieres = $this->creerMatieres($manager);
        $this->creerFichesTechniques($manager, $articles, $matieres);
        $caissierIds = $this->creerUtilisateurs($manager);

        $manager->flush();

        $pools = $this->construirePools($articles);
        $manager->clear();

        $this->genererHistorique($manager, $caissierIds, $pools);
    }

    /**
     * @return array<string, FamilleProduit>
     */
    private function creerFamilles(ObjectManager $manager): array
    {
        $definitions = [
            'pains' => ['Pains', '#C68A4E'],
            'viennoiseries' => ['Viennoiseries', '#E0A458'],
            'patisseries' => ['Pâtisseries', '#D66BA0'],
            'sandwichs' => ['Sandwichs', '#E4572E'],
            'grillades' => ['Grillades', '#A62E1C'],
            'accompagnements' => ['Accompagnements', '#4C9F70'],
            'boissons' => ['Boissons', '#3A86FF'],
        ];

        $familles = [];
        $position = 0;
        foreach ($definitions as $cle => [$nom, $couleur]) {
            $famille = new FamilleProduit($nom);
            $famille->setCouleur($couleur)->setPosition(++$position);
            $manager->persist($famille);
            $familles[$cle] = $famille;
        }

        return $familles;
    }

    /**
     * @param array<string, FamilleProduit> $familles
     *
     * @return array<string, Article>
     */
    private function creerArticles(ObjectManager $manager, array $familles): array
    {
        $articles = [];
        $position = 0;
        foreach ($this->definitionsArticles() as $cle => [$nom, $familleCle, $prixFcfa, $unite, $tauxTva]) {
            $article = new Article($nom, $prixFcfa * 100, $unite);
            $article
                ->setFamilleProduit($familles[$familleCle])
                ->setTauxTva($tauxTva)
                ->setCouleur($familles[$familleCle]->getCouleur())
                ->setPositionCaisse(++$position);
            $manager->persist($article);
            $articles[$cle] = $article;
        }

        return $articles;
    }

    /**
     * clé => [nom, famille, prix FCFA, unité, taux TVA en points de base].
     *
     * @return array<string, array{0:string,1:string,2:int,3:string,4:int}>
     */
    private function definitionsArticles(): array
    {
        return [
            // Pains (produits de première nécessité : TVA 0).
            'baguette' => ['Baguette', 'pains', 150, 'pièce', 0],
            'pain_maison' => ['Pain maison', 'pains', 250, 'pièce', 0],
            'pain_lait' => ['Pain au lait', 'pains', 200, 'pièce', 0],
            'pain_complet' => ['Pain complet', 'pains', 300, 'pièce', 0],
            'petit_pain' => ['Petit pain', 'pains', 100, 'pièce', 0],
            'pain_mie' => ['Pain de mie', 'pains', 800, 'pièce', 0],
            // Viennoiseries (TVA 0).
            'croissant' => ['Croissant', 'viennoiseries', 300, 'pièce', 0],
            'pain_choco' => ['Pain au chocolat', 'viennoiseries', 300, 'pièce', 0],
            'pain_raisin' => ['Pain aux raisins', 'viennoiseries', 350, 'pièce', 0],
            'chausson_pomme' => ['Chausson aux pommes', 'viennoiseries', 400, 'pièce', 0],
            'beignet' => ['Beignet sucré', 'viennoiseries', 100, 'pièce', 0],
            'brioche' => ['Brioche', 'viennoiseries', 250, 'pièce', 0],
            // Pâtisseries (TVA 0).
            'gateau_part' => ['Gâteau (part)', 'patisseries', 500, 'part', 0],
            'eclair' => ['Éclair au chocolat', 'patisseries', 500, 'pièce', 0],
            'tartelette' => ['Tartelette aux fruits', 'patisseries', 600, 'pièce', 0],
            'cake' => ['Cake tranché', 'patisseries', 400, 'part', 0],
            'millefeuille' => ['Millefeuille', 'patisseries', 700, 'pièce', 0],
            'gateau_entier' => ["Gâteau d'anniversaire", 'patisseries', 10000, 'pièce', 0],
            // Sandwichs (TVA 18 %).
            'sandwich_poulet' => ['Sandwich poulet', 'sandwichs', 1500, 'pièce', 1800],
            'sandwich_thon' => ['Sandwich thon', 'sandwichs', 1250, 'pièce', 1800],
            'sandwich_oeuf' => ['Sandwich œuf', 'sandwichs', 1000, 'pièce', 1800],
            'burger_simple' => ['Burger simple', 'sandwichs', 2000, 'pièce', 1800],
            'burger_double' => ['Burger double', 'sandwichs', 2500, 'pièce', 1800],
            'hotdog' => ['Hot-dog', 'sandwichs', 1500, 'pièce', 1800],
            'shawarma' => ['Shawarma poulet', 'sandwichs', 2000, 'pièce', 1800],
            // Grillades (TVA 18 %).
            'poulet_quart' => ['Poulet braisé (¼)', 'grillades', 2000, 'portion', 1800],
            'poulet_demi' => ['Poulet braisé (½)', 'grillades', 3500, 'portion', 1800],
            'brochette' => ['Brochette de bœuf', 'grillades', 500, 'pièce', 1800],
            'poisson_braise' => ['Poisson braisé', 'grillades', 2500, 'portion', 1800],
            'alloco_poulet' => ['Alloco poulet', 'grillades', 2500, 'portion', 1800],
            'attieke_poisson' => ['Attiéké poisson', 'grillades', 2000, 'portion', 1800],
            // Accompagnements (TVA 18 %).
            'frites' => ['Frites', 'accompagnements', 1000, 'portion', 1800],
            'alloco' => ['Alloco', 'accompagnements', 500, 'portion', 1800],
            'attieke' => ['Attiéké', 'accompagnements', 500, 'portion', 1800],
            'riz_sauce' => ['Riz sauce', 'accompagnements', 1000, 'portion', 1800],
            'salade' => ['Salade', 'accompagnements', 500, 'portion', 1800],
            // Boissons (TVA 18 %).
            'bissap' => ['Jus de bissap', 'boissons', 500, 'pièce', 1800],
            'gingembre' => ['Jus de gingembre', 'boissons', 500, 'pièce', 1800],
            'tamarin' => ['Jus de tamarin', 'boissons', 500, 'pièce', 1800],
            'sucrerie' => ['Sucrerie', 'boissons', 500, 'pièce', 1800],
            'eau' => ['Eau minérale', 'boissons', 300, 'pièce', 1800],
            'cafe' => ['Café', 'boissons', 300, 'pièce', 1800],
            'the' => ['Thé', 'boissons', 300, 'pièce', 1800],
        ];
    }

    /**
     * @return array<string, MatierePremiere>
     */
    private function creerMatieres(ObjectManager $manager): array
    {
        // clé => [nom, unité, coût FCFA/unité, stock (unités), stock mini (unités)].
        $definitions = [
            'farine' => ['Farine de blé', 'kg', 450, 300, 50],
            'levure' => ['Levure boulangère', 'kg', 3000, 10, 2],
            'sucre' => ['Sucre', 'kg', 700, 100, 20],
            'beurre' => ['Beurre', 'kg', 3500, 40, 10],
            'oeuf' => ['Œufs', 'pièce', 100, 2000, 300],
            'huile' => ['Huile', 'litre', 1200, 100, 20],
            'poulet' => ['Poulet', 'kg', 2000, 80, 15],
            'viande' => ['Viande hachée', 'kg', 3000, 30, 8],
            'pain_burger' => ['Pain burger', 'pièce', 150, 500, 100],
            'pdt' => ['Pommes de terre', 'kg', 600, 150, 30],
            'tomate' => ['Tomate', 'kg', 500, 40, 10],
            'oignon' => ['Oignon', 'kg', 400, 50, 10],
            'bissap_fleur' => ["Fleur d'hibiscus (bissap)", 'kg', 2000, 15, 3],
            'gingembre_mp' => ['Gingembre', 'kg', 1000, 20, 5],
            'poisson' => ['Poisson', 'kg', 1500, 60, 10],
            'attieke_mp' => ['Attiéké', 'kg', 500, 80, 15],
            'banane' => ['Banane plantain', 'kg', 400, 60, 10],
            'sel' => ['Sel', 'kg', 300, 30, 5],
            'lait' => ['Lait en poudre', 'kg', 3000, 20, 5],
            'chocolat' => ['Pâte chocolat', 'kg', 3500, 15, 3],
            'riz' => ['Riz', 'kg', 500, 100, 20],
            'soda_mp' => ['Sucrerie (bouteille)', 'pièce', 300, 500, 100],
            'eau_mp' => ['Eau minérale (bouteille)', 'pièce', 150, 600, 100],
        ];

        $approvisionne = (new \DateTimeImmutable('today'))->modify('-30 days')->setTime(6, 0);

        $matieres = [];
        foreach ($definitions as $cle => [$nom, $unite, $coutFcfa, $stock, $mini]) {
            $matiere = new MatierePremiere($nom, $unite);
            $matiere
                ->setCoutMoyenPondere($coutFcfa * 100)
                ->setStockActuel($stock * 1000)
                ->setStockMini($mini * 1000);
            $manager->persist($matiere);
            $matieres[$cle] = $matiere;

            $mouvement = new MouvementStock(TypeMouvementStock::ENTREE, $stock * 1000);
            $mouvement->setMatierePremiere($matiere)
                ->setMotif('Approvisionnement initial')
                ->setSource('inventaire', null);
            $this->fixerDate($mouvement, 'createdAt', $approvisionne);
            $manager->persist($mouvement);
        }

        return $matieres;
    }

    /**
     * @param array<string, Article>         $articles
     * @param array<string, MatierePremiere> $matieres
     */
    private function creerFichesTechniques(ObjectManager $manager, array $articles, array $matieres): void
    {
        // article => [[matière, quantité en millièmes, perte en points de base], …].
        $recettes = [
            'baguette' => [['farine', 250, 300], ['levure', 5, 200], ['sel', 5, 100]],
            'pain_choco' => [['farine', 80, 300], ['beurre', 20, 200], ['chocolat', 15, 200], ['sucre', 10, 100], ['levure', 3, 200]],
            'croissant' => [['farine', 70, 300], ['beurre', 30, 200], ['sucre', 10, 100], ['levure', 3, 200]],
            'gateau_part' => [['farine', 60, 300], ['sucre', 40, 100], ['beurre', 30, 200], ['oeuf', 500, 200]],
            'beignet' => [['farine', 40, 400], ['sucre', 15, 100], ['huile', 10, 500], ['levure', 2, 200]],
            'sandwich_poulet' => [['pain_burger', 1000, 100], ['poulet', 100, 300], ['tomate', 30, 500], ['oignon', 20, 500], ['huile', 10, 300]],
            'burger_simple' => [['pain_burger', 1000, 100], ['viande', 120, 300], ['tomate', 30, 500], ['oignon', 20, 500], ['huile', 10, 300]],
            'poulet_quart' => [['poulet', 300, 400], ['huile', 20, 300], ['oignon', 30, 500], ['tomate', 20, 500]],
            'attieke_poisson' => [['attieke_mp', 200, 200], ['poisson', 200, 400], ['oignon', 30, 500], ['tomate', 40, 500], ['huile', 20, 300]],
            'frites' => [['pdt', 300, 600], ['huile', 50, 500], ['sel', 5, 100]],
            'alloco' => [['banane', 250, 600], ['huile', 40, 500], ['sel', 3, 100]],
            'bissap' => [['bissap_fleur', 30, 300], ['sucre', 50, 100]],
            'poisson_braise' => [['poisson', 350, 400], ['huile', 20, 300], ['oignon', 20, 500]],
            'riz_sauce' => [['riz', 150, 200], ['tomate', 40, 500], ['oignon', 30, 500], ['huile', 20, 300]],
        ];

        foreach ($recettes as $articleCle => $lignes) {
            $fiche = new FicheTechnique($articles[$articleCle]);
            foreach ($lignes as [$matiereCle, $quantite, $perte]) {
                new LigneFicheTechnique($fiche, $matieres[$matiereCle], $quantite, $perte);
            }
            $manager->persist($fiche);
        }
    }

    /**
     * @return int[] Identifiants des caissiers (renseignés après le flush)
     */
    private function creerUtilisateurs(ObjectManager $manager): array
    {
        $dirigeante = new Utilisateur('aya.kone@zedpos.ci', 'Aya Koné (Abidjan)');
        $dirigeante->setRoles([RoleUtilisateur::DIRIGEANTE->value]);
        $dirigeante->setMotDePasse($this->hasher->hashPassword($dirigeante, 'dirigeante123'));
        $manager->persist($dirigeante);

        $gerant = new Utilisateur('koffi.nguessan@zedpos.ci', 'Koffi N\'Guessan');
        $gerant->setRoles([RoleUtilisateur::GERANT->value]);
        $gerant->setMotDePasse($this->hasher->hashPassword($gerant, 'gerant123'));
        $manager->persist($gerant);

        $caissiers = [];
        foreach ([['fatou.traore@zedpos.ci', 'Fatou Traoré', '1234'], ['yao.kouassi@zedpos.ci', 'Yao Kouassi', '5678']] as [$email, $nom, $pin]) {
            $caissier = new Utilisateur($email, $nom);
            $caissier->setRoles([RoleUtilisateur::CAISSIER->value]);
            $caissier->setCodePin($this->hasher->hashPassword($caissier, $pin));
            $manager->persist($caissier);
            $caissiers[] = $caissier;
        }

        // Les identifiants ne sont disponibles qu'après le flush du load().
        return $caissiers;
    }

    /**
     * Construit les listes d'articles (id, prix, taux, famille) par contexte de vente.
     *
     * @param array<string, Article> $articles
     *
     * @return array<string, list<array{id:int,prix:int,taux:int,famille:string}>>
     */
    private function construirePools(array $articles): array
    {
        $pools = ['pains' => [], 'boulangerie' => [], 'mains' => [], 'accompagnements' => [], 'boissons' => []];

        foreach ($this->definitionsArticles() as $cle => $def) {
            $famille = $def[1];
            $article = $articles[$cle];
            $entree = [
                'id' => $article->getId() ?? 0,
                'prix' => $article->getPrixVenteTtc(),
                'taux' => $article->getTauxTva(),
                'famille' => $famille,
            ];

            if (\in_array($famille, ['pains', 'viennoiseries', 'patisseries', 'boissons'], true)) {
                $pools['boulangerie'][] = $entree;
            }
            if ('pains' === $famille) {
                $pools['pains'][] = $entree;
            }
            if (\in_array($famille, ['sandwichs', 'grillades'], true)) {
                $pools['mains'][] = $entree;
            }
            if ('accompagnements' === $famille) {
                $pools['accompagnements'][] = $entree;
            }
            if ('boissons' === $famille) {
                $pools['boissons'][] = $entree;
            }
        }

        return $pools;
    }

    /**
     * @param Utilisateur[]                                                       $caissiers
     * @param array<string, list<array{id:int,prix:int,taux:int,famille:string}>> $pools
     */
    private function genererHistorique(ObjectManager $manager, array $caissiers, array $pools): void
    {
        $caissierIds = array_map(static fn (Utilisateur $u): int => $u->getId() ?? 0, $caissiers);

        $aujourdhui = new \DateTimeImmutable('today');
        $maintenant = new \DateTimeImmutable();
        $heureCourante = (int) $maintenant->format('G');

        for ($jourOffset = 29; $jourOffset >= 0; --$jourOffset) {
            $jour = $aujourdhui->modify(\sprintf('-%d days', $jourOffset));
            $estAujourdhui = 0 === $jourOffset;

            // Une session de caisse par caissier et par jour. Elle reste ouverte
            // le temps de générer les ventes : une session clôturée les refuserait.
            $sessions = [];
            $especes = [];
            $sorties = [];
            foreach ($caissierIds as $index => $caissierId) {
                $session = new SessionCaisse($manager->getReference(Utilisateur::class, $caissierId), 30000 * 100);
                $this->fixerDate($session, 'createdAt', $jour->setTime(5, 0));
                $this->fixerDate($session, 'ouvertureAt', $jour->setTime(5, 0));
                $manager->persist($session);
                $sessions[$index] = $session;
                $especes[$index] = 0;
                $sorties[$index] = 0;
            }

            $facteur = mt_rand(80, 115) / 100;

            foreach (self::POIDS_HORAIRE as $heure => $poids) {
                if ($estAujourdhui && $heure > $heureCourante) {
                    continue;
                }

                $nbVentes = (int) round($poids * $facteur);
                for ($i = 0; $i < $nbVentes; ++$i) {
                    $moment = $jour->setTime($heure, mt_rand(0, 59), mt_rand(0, 59));
                    $mode = $this->choisirMode($heure);
                    $lignes = $this->composerPanier($mode, $pools);

                    [$totalHt, $totalTva, $totalTtc] = $this->calculerTotaux($lignes);
                    $numero = \sprintf('V%s-%05d', $jour->format('ymd'), ++$this->numeroSeq);
                    $indexSession = array_rand($sessions);
                    $session = $sessions[$indexSession];

                    $vente = new Vente($session, $mode, $numero, $totalHt, $totalTva, $totalTtc);
                    $this->fixerDate($vente, 'createdAt', $moment);

                    foreach ($lignes as $ligne) {
                        $ligneVente = new LigneVente(
                            $vente,
                            $manager->getReference(Article::class, $ligne['id']),
                            $ligne['qte'] * 1000,
                            $ligne['prix'],
                        );
                        $this->fixerDate($ligneVente, 'createdAt', $moment);
                    }

                    [$modeReglement, $reference] = $this->choisirReglement();
                    $reglement = new Reglement($vente, $modeReglement, $totalTtc, $reference);
                    $this->fixerDate($reglement, 'createdAt', $moment);
                    if (ModeReglement::ESPECES === $modeReglement) {
                        $especes[$indexSession] += $totalTtc;
                    }

                    $manager->persist($vente);
                }
            }

            // Quelques dépenses réglées en espèces depuis le tiroir.
            foreach ($sessions as $index => $session) {
                foreach ($this->depensesDuJour() as [$categorie, $montant, $libelle]) {
                    $mouvement = new MouvementCaisse(
                        $session,
                        $session->getUtilisateur(),
                        TypeMouvementCaisse::DEPENSE,
                        $montant,
                        $categorie,
                        $libelle,
                    );
                    $this->fixerDate($mouvement, 'createdAt', $jour->setTime(mt_rand(9, 17), mt_rand(0, 59)));
                    $manager->persist($mouvement);
                    $sorties[$index] += $montant;
                }
            }

            // Clôture Z en fin de journée, une fois toutes les écritures passées.
            if (!$estAujourdhui) {
                foreach ($sessions as $index => $session) {
                    $theorique = $session->getFondCaisse() + $especes[$index] - $sorties[$index];
                    // La caisse tombe juste 3 fois sur 4 ; sinon petit écart justifié.
                    $ecart = mt_rand(1, 4) > 3 ? mt_rand(-5, 5) * 10000 : 0;
                    $session->cloturer(
                        $theorique,
                        max(0, $theorique + $ecart),
                        0 !== $ecart ? ($ecart > 0 ? 'Excédent constaté au comptage' : 'Manquant constaté au comptage') : null,
                    );
                    $this->fixerDate($session, 'clotureAt', $jour->setTime(21, 45));
                }
            }

            $manager->flush();
            $manager->clear();
        }
    }

    /**
     * Dépenses de caisse plausibles pour une journée (0 à 2 lignes).
     *
     * @return list<array{0: CategorieDepense, 1: int, 2: string}>
     */
    private function depensesDuJour(): array
    {
        $catalogue = [
            [CategorieDepense::TRANSPORT, 200000, 'Course taxi livraison'],
            [CategorieDepense::APPROVISIONNEMENT, 750000, "Sacs d'emballage"],
            [CategorieDepense::ENTRETIEN, 150000, 'Produits de nettoyage'],
            [CategorieDepense::PETIT_EQUIPEMENT, 350000, 'Ustensiles'],
            [CategorieDepense::DIVERS, 100000, 'Divers'],
        ];

        shuffle($catalogue);

        return \array_slice($catalogue, 0, mt_rand(0, 2));
    }

    private function choisirMode(int $heure): ModeVente
    {
        if ($heure >= 5 && $heure <= 10) {
            return mt_rand(1, 100) <= 88 ? ModeVente::BOULANGERIE : ModeVente::FASTFOOD;
        }
        if ($heure >= 17) {
            return mt_rand(1, 100) <= 75 ? ModeVente::FASTFOOD : ModeVente::BOULANGERIE;
        }

        return mt_rand(1, 100) <= 50 ? ModeVente::BOULANGERIE : ModeVente::FASTFOOD;
    }

    /**
     * @param array<string, list<array{id:int,prix:int,taux:int,famille:string}>> $pools
     *
     * @return list<array{id:int,qte:int,prix:int,taux:int}>
     */
    private function composerPanier(ModeVente $mode, array $pools): array
    {
        $lignes = [];
        $utilises = [];

        $ajouter = function (array $entree, int $qte) use (&$lignes, &$utilises): void {
            if (isset($utilises[$entree['id']])) {
                return;
            }
            $utilises[$entree['id']] = true;
            $lignes[] = ['id' => $entree['id'], 'qte' => $qte, 'prix' => $entree['prix'], 'taux' => $entree['taux']];
        };

        if (ModeVente::BOULANGERIE === $mode) {
            // Panier boulangerie : faible, centré sur un pain, parfois quelques extras.
            $pain = $pools['pains'][array_rand($pools['pains'])];
            $ajouter($pain, mt_rand(1, self::QTE_MAX['pains']));

            $extras = mt_rand(0, 2);
            for ($e = 0; $e < $extras; ++$e) {
                $entree = $pools['boulangerie'][array_rand($pools['boulangerie'])];
                $ajouter($entree, mt_rand(1, self::QTE_MAX[$entree['famille']]));
            }

            return $lignes;
        }

        // Panier fast-food : un plat principal, souvent accompagnement et boisson.
        $main = $pools['mains'][array_rand($pools['mains'])];
        $ajouter($main, mt_rand(1, 2));

        if (mt_rand(1, 100) <= 30) {
            $ajouter($pools['mains'][array_rand($pools['mains'])], 1);
        }
        if (mt_rand(1, 100) <= 55) {
            $entree = $pools['accompagnements'][array_rand($pools['accompagnements'])];
            $ajouter($entree, mt_rand(1, 2));
        }
        if (mt_rand(1, 100) <= 65) {
            $entree = $pools['boissons'][array_rand($pools['boissons'])];
            $ajouter($entree, mt_rand(1, 2));
        }

        return $lignes;
    }

    /**
     * @param list<array{id:int,qte:int,prix:int,taux:int}> $lignes
     *
     * @return array{0:int,1:int,2:int} [totalHt, totalTva, totalTtc] en centimes
     */
    private function calculerTotaux(array $lignes): array
    {
        $totalTtc = 0;
        $totalTva = 0;

        foreach ($lignes as $ligne) {
            $montantTtc = $ligne['qte'] * $ligne['prix'];
            $montantHt = intdiv($montantTtc * 10000, 10000 + $ligne['taux']);
            $totalTtc += $montantTtc;
            $totalTva += $montantTtc - $montantHt;
        }

        return [$totalTtc - $totalTva, $totalTva, $totalTtc];
    }

    /**
     * Répartition : 55 % espèces, 20 % Wave, 15 % Orange Money, 10 % MTN MoMo.
     *
     * @return array{0:ModeReglement,1:?string}
     */
    private function choisirReglement(): array
    {
        $tirage = mt_rand(1, 100);

        return match (true) {
            $tirage <= 55 => [ModeReglement::ESPECES, null],
            $tirage <= 75 => [ModeReglement::WAVE, 'WAVE-'.mt_rand(100000, 999999)],
            $tirage <= 90 => [ModeReglement::ORANGE_MONEY, 'OM-'.mt_rand(100000, 999999)],
            default => [ModeReglement::MTN_MOMO, 'MTN-'.mt_rand(100000, 999999)],
        };
    }

    private function fixerDate(object $objet, string $propriete, \DateTimeImmutable $date): void
    {
        (new \ReflectionProperty($objet, $propriete))->setValue($objet, $date);
    }
}
