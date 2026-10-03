<?php

namespace App\DataFixtures;

use App\Entity\Fournisseur;
use App\Entity\Magasin\MagasinEmplacement;
use App\Entity\Magasin\MagasinProduit;
use App\Entity\Magasin\MagasinReception;
use App\Entity\Utilisateur;
use App\Enum\Magasin\CategorieProduitMagasin;
use App\Enum\Magasin\DestinationSortieMagasin;
use App\Enum\Magasin\MotifRejetMagasin;
use App\Enum\Magasin\MotifSortieMagasin;
use App\Enum\RoleUtilisateur;
use App\Service\Magasin\InventaireMagasinService;
use App\Service\Magasin\MagasinStockService;
use App\Service\Magasin\ReceptionMagasinService;
use App\Service\Magasin\SortieMagasinService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Sept jours de magasin pour la démonstration, rejoués **par les vrais services
 * du module** : chaque mouvement passe les contrôles de stock, chaque réception
 * met le coût moyen à jour, chaque geste est au journal d'audit.
 *
 *  - J−7 : premier inventaire, qui fait le stock de départ (validé par la dirigeante) ;
 *  - J−6 : réception des Grands Moulins, au prix (coût moyen recalculé) ;
 *  - J−4 : réception de la laiterie avec un écart au comptage et une plaquette
 *          d'œufs rejetée « cassé », rangée en chambre froide ;
 *  - J−6 à J−1 : sorties quotidiennes vers l'atelier, une perte, une sortie boutique ;
 *  - aujourd'hui : une réception à contrôler, une à inspecter, une sortie en
 *    brouillon — le tableau de bord a de quoi montrer chaque étape — et la levure
 *    au seuil d'alerte, pour la bannière.
 *
 * Les dates sont reportées après coup en SQL sur les documents et leurs
 * mouvements : les services datent tout de « maintenant ». Le journal d'audit,
 * lui, garde l'heure réelle du chargement — il ne se réécrit pas.
 *
 * Un compte **magasinier** (consultation seule) est créé sur une base de
 * démonstration fraîche, jamais par-dessus des comptes conservés
 * (`app:demo:reset --garder-utilisateurs`).
 */
class MagasinFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private readonly ReceptionMagasinService $receptions,
        private readonly SortieMagasinService $sorties,
        private readonly InventaireMagasinService $inventaires,
        private readonly MagasinStockService $stock,
        private readonly Connection $connexion,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function getDependencies(): array
    {
        return [AppFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        [$dirigeante, $gerante] = $this->responsables($manager);
        $this->creerMagasinier($manager);

        $reserve = $this->stock->reserveParDefaut();
        $froid = new MagasinEmplacement('FROID', 'Chambre froide');
        $manager->persist($froid);

        $moulins = new Fournisseur('Grands Moulins d\'Abidjan');
        $laiterie = new Fournisseur('Laiterie du Plateau');
        $emballages = new Fournisseur('Distrib Emballages CI');
        foreach ([$moulins, $laiterie, $emballages] as $fournisseur) {
            $manager->persist($fournisseur);
        }

        $p = $this->creerProduits($manager, $moulins, $laiterie, $emballages);
        $manager->flush();

        $jour = static fn (int $decalage, int $heure = 8): \DateTimeImmutable => (new \DateTimeImmutable('today'))->modify(\sprintf('%+d days', $decalage))->setTime($heure, 0);
        $lieu = static fn (string $cle): MagasinEmplacement => \in_array($cle, ['beurre', 'lait', 'oeufs'], true) ? $froid : $reserve;

        // J−7 : premier inventaire = stock de départ (en unité de stock).
        $inventaire = $this->inventaires->ouvrir(null, $gerante);
        $departs = ['farine' => 500, 'sucre' => 100, 'levure' => 8, 'sel' => 50, 'beurre' => 30, 'lait' => 24,
            'oeufs' => 300, 'huile' => 60, 'sachets' => 1000, 'boites' => 150, 'javel' => 10];
        $comptages = [];
        foreach ($inventaire->getLignes() as $ligne) {
            $cle = array_search($ligne->getProduit(), $p, true);
            if (false === $cle) {
                continue;
            }
            $quantite = $lieu($cle) === $ligne->getEmplacement() ? $departs[$cle] * 1000 : 0;
            $comptages[(int) $ligne->getId()] = ['quantite' => $quantite, 'enUniteAchat' => false];
        }
        $this->inventaires->enregistrerComptage($inventaire, $comptages, 'Stock de départ du magasin.');
        $this->inventaires->valider($inventaire, true, $dirigeante);
        $this->dater('inventaire', (int) $inventaire->getId(), $jour(-7, 7), ['created_at', 'valide_at']);

        // J−6 : farine et sucre, au prix.
        $this->recevoir($moulins, $jour(-6, 9), 'BL-GMA-4471', [
            [$p['farine'], 20, 2500000],
            [$p['sucre'], 4, 3200000],
        ], $gerante, $dirigeante, $reserve);

        // J−4 : laiterie — 10 plaquettes annoncées, 9 comptées, 1 cassée.
        $this->recevoir($laiterie, $jour(-4, 10), 'BL-LDP-0932', [
            [$p['beurre'], 6, 4200000],
            [$p['lait'], 4, 1100000],
            [$p['oeufs'], 10, 300000, 9, 1],
        ], $gerante, $dirigeante, $froid);

        // J−6 à J−1 : ce que l'atelier vient chercher chaque matin.
        for ($j = -6; $j <= -1; ++$j) {
            $lignes = [
                [$p['farine'], 3000, true, $reserve],
                [$p['sucre'], 15000, false, $reserve],
                [$p['levure'], 1000, false, $reserve],
                [$p['oeufs'], 2000, true, $froid],
                [$p['beurre'], 5000, false, $froid],
                [$p['lait'], 6000, false, $froid],
            ];
            if (0 === $j % 2) {
                $lignes[] = [$p['huile'], 5000, false, $reserve];
                $lignes[] = [$p['sel'], 2000, false, $reserve];
            }
            $this->sortir(DestinationSortieMagasin::ATELIER, MotifSortieMagasin::PRODUCTION, 'Boulanger de garde', $lignes, $jour($j, 6), $gerante);
        }
        $this->sortir(DestinationSortieMagasin::BOUTIQUE, MotifSortieMagasin::PRODUCTION, 'Vendeuses', [[$p['sachets'], 3000, true, $reserve], [$p['boites'], 1000, true, $reserve]], $jour(-3, 14), $gerante);
        $this->sortir(DestinationSortieMagasin::AUTRE, MotifSortieMagasin::PERTE_AVARIE, null, [[$p['oeufs'], 1000, true, $froid]], $jour(-2, 16), $gerante, 'Plaquette tombée en rangeant la chambre froide.');

        // Aujourd'hui : de quoi montrer chaque étape en attente.
        $aControler = $this->receptions->creer($emballages, new \DateTimeImmutable('today'), 'BL-DEC-118', null, [
            ['produit' => $p['sachets'], 'annoncee' => 10000],
            ['produit' => $p['boites'], 'annoncee' => 4000],
        ], $gerante);
        $this->dater('reception', (int) $aControler->getId(), $jour(0, 9), ['created_at']);

        $aInspecter = $this->receptions->creer($emballages, new \DateTimeImmutable('today'), 'BL-DEC-119', null, [['produit' => $p['javel'], 'annoncee' => 4000]], $gerante);
        $this->receptions->controler($aInspecter, [(int) $aInspecter->getLignes()->first()->getId() => 4000], null, $gerante);
        $this->dater('reception', (int) $aInspecter->getId(), $jour(0, 9), ['created_at', 'controlee_at']);

        $brouillon = $this->sorties->creer(new \DateTimeImmutable('today'), DestinationSortieMagasin::ATELIER, MotifSortieMagasin::PRODUCTION, 'Pâtissier', null, [
            ['produit' => $p['farine'], 'emplacement' => $reserve, 'quantite' => 2000, 'enUniteAchat' => true],
        ], $gerante);
        $this->dater('sortie', (int) $brouillon->getId(), $jour(0, 7), ['created_at']);
    }

    /**
     * @return array<string, MagasinProduit>
     */
    private function creerProduits(ObjectManager $manager, Fournisseur $moulins, Fournisseur $laiterie, Fournisseur $emballages): array
    {
        // clé => [nom, catégorie, unité de stock, unité d'achat, contenance (millièmes d'unité de stock),
        //         seuil (unités de stock), coût FCFA / unité de stock, fournisseur]
        $definitions = [
            'farine' => ['Farine de blé', CategorieProduitMagasin::MATIERE, 'kg', 'sac', 50000, 500, 480, $moulins],
            'sucre' => ['Sucre', CategorieProduitMagasin::MATIERE, 'kg', 'sac', 50000, 50, 650, $moulins],
            'levure' => ['Levure boulangère', CategorieProduitMagasin::MATIERE, 'kg', 'paquet', 500, 5, 3000, $moulins],
            'sel' => ['Sel', CategorieProduitMagasin::MATIERE, 'kg', 'sac', 25000, 10, 300, $moulins],
            'beurre' => ['Beurre', CategorieProduitMagasin::MATIERE, 'kg', 'carton', 10000, 20, 4000, $laiterie],
            'lait' => ['Lait', CategorieProduitMagasin::MATIERE, 'L', 'carton', 12000, 12, 900, $laiterie],
            'oeufs' => ['Œufs', CategorieProduitMagasin::MATIERE, 'pièce', 'plaquette', 30000, 90, 100, $laiterie],
            'huile' => ['Huile', CategorieProduitMagasin::MATIERE, 'L', 'bidon', 20000, 20, 1200, null],
            'sachets' => ['Sachets kraft', CategorieProduitMagasin::EMBALLAGE, 'pièce', 'paquet', 100000, 200, 15, $emballages],
            'boites' => ['Boîtes à pâtisserie', CategorieProduitMagasin::EMBALLAGE, 'pièce', 'carton', 50000, 50, 120, $emballages],
            'javel' => ['Eau de javel', CategorieProduitMagasin::ENTRETIEN, 'L', 'bidon', 5000, 5, 500, $emballages],
        ];

        $produits = [];
        foreach ($definitions as $cle => [$nom, $categorie, $uniteStock, $uniteAchat, $contenance, $seuil, $cout, $fournisseur]) {
            $produit = (new MagasinProduit($nom, $categorie, $uniteStock))
                ->setUniteAchat($uniteAchat)
                ->setContenanceAchat($contenance)
                ->setSeuilAlerte($seuil * 1000)
                ->setFournisseurHabituel($fournisseur);
            // Coût d'ouverture : celui du stock de départ, que le premier
            // inventaire fait entrer sans prix. Les réceptions le pondèrent ensuite.
            $produit->definirCoutMoyen($cout * 100);
            $manager->persist($produit);
            $produits[$cle] = $produit;
        }

        return $produits;
    }

    /**
     * Une réception menée jusqu'au stockage. Ligne : [produit, annoncé, prix (centimes/unité d'achat), compté ?, rejeté ?].
     *
     * @param list<array{0: MagasinProduit, 1: int, 2: int, 3?: int, 4?: int}> $lignes quantités en unités d'achat
     */
    private function recevoir(Fournisseur $fournisseur, \DateTimeImmutable $quand, string $bl, array $lignes, Utilisateur $gerante, Utilisateur $dirigeante, MagasinEmplacement $ou): MagasinReception
    {
        $reception = $this->receptions->creer($fournisseur, $quand->setTime(0, 0), $bl, null, array_map(
            static fn (array $l): array => ['produit' => $l[0], 'annoncee' => $l[1] * 1000],
            $lignes,
        ), $gerante);

        $comptees = $decisions = $prix = $rangement = [];
        $ecart = false;
        foreach ($reception->getLignes() as $i => $ligne) {
            $l = $lignes[$i];
            $comptee = ($l[3] ?? $l[1]) * 1000;
            $rejetee = ($l[4] ?? 0) * 1000;
            $ecart = $ecart || $comptee !== $l[1] * 1000;
            $id = (int) $ligne->getId();
            $comptees[$id] = $comptee;
            $decisions[$id] = ['acceptee' => $comptee - $rejetee, 'rejetee' => $rejetee, 'motif' => $rejetee > 0 ? MotifRejetMagasin::CASSE : null];
            $prix[$id] = $l[2];
            $rangement[$id] = $ou;
        }

        $this->receptions->controler($reception, $comptees, $ecart ? 'Une plaquette manquait à la livraison, signalé au livreur.' : null, $gerante);
        $this->receptions->inspecter($reception, $decisions, $gerante);
        $this->receptions->fixerPrix($reception, $prix);
        $this->receptions->stocker($reception, $rangement, $dirigeante);

        $this->dater('reception', (int) $reception->getId(), $quand, ['created_at', 'controlee_at', 'inspectee_at', 'stockee_at']);

        return $reception;
    }

    /**
     * Une sortie validée. Ligne : [produit, quantité en millièmes de l'unité choisie, en unité d'achat ?, emplacement].
     *
     * @param list<array{0: MagasinProduit, 1: int, 2: bool, 3: MagasinEmplacement}> $lignes
     */
    private function sortir(DestinationSortieMagasin $destination, MotifSortieMagasin $motif, ?string $demandePar, array $lignes, \DateTimeImmutable $quand, Utilisateur $gerante, ?string $commentaire = null): void
    {
        $sortie = $this->sorties->creer($quand->setTime(0, 0), $destination, $motif, $demandePar, $commentaire, array_map(
            static fn (array $l): array => ['produit' => $l[0], 'quantite' => $l[1], 'enUniteAchat' => $l[2], 'emplacement' => $l[3]],
            $lignes,
        ), $gerante);
        $this->sorties->valider($sortie, $gerante);
        $this->dater('sortie', (int) $sortie->getId(), $quand, ['created_at', 'validee_at']);
    }

    /**
     * Reporte la date d'un document et de ses mouvements. Les colonnes sont
     * choisies dans le code de la fixture, jamais saisies.
     *
     * @param list<string> $colonnes
     */
    private function dater(string $document, int $id, \DateTimeImmutable $quand, array $colonnes): void
    {
        $table = 'magasin_'.$document;
        $valeur = $quand->format('Y-m-d H:i:s');
        $affectations = implode(', ', array_map(static fn (string $c): string => "$c = :quand", $colonnes));
        if ('reception' === $document) {
            $affectations .= ', date_reception = :jour';
        } elseif ('sortie' === $document) {
            $affectations .= ', date_sortie = :jour';
        }

        $this->connexion->executeStatement("UPDATE $table SET $affectations WHERE id = :id", ['quand' => $valeur, 'jour' => $quand->format('Y-m-d'), 'id' => $id]);
        $this->connexion->executeStatement(
            'UPDATE magasin_mouvement SET created_at = :quand WHERE document_type = :type AND document_id = :id',
            ['quand' => $valeur, 'type' => $document, 'id' => $id],
        );
    }

    /**
     * La dirigeante et la gérante qui signent les documents — par rôle, pour
     * marcher aussi sur une base dont les comptes ont été conservés. À défaut,
     * n'importe quel compte signe : des comptes conservés sans dirigeante ne
     * doivent pas faire échouer tout le chargement de la démonstration.
     *
     * @return array{0: Utilisateur, 1: Utilisateur}
     */
    private function responsables(ObjectManager $manager): array
    {
        $tous = $manager->getRepository(Utilisateur::class)->findAll();
        $avecRole = static fn (string $role): ?Utilisateur => array_values(array_filter($tous, static fn (Utilisateur $u): bool => \in_array($role, $u->getRoles(), true)))[0] ?? null;

        $gerante = $avecRole(RoleUtilisateur::GERANT->value);
        $dirigeante = $avecRole(RoleUtilisateur::DIRIGEANTE->value) ?? $gerante ?? $tous[0]
            ?? throw new \LogicException('Les données du magasin demandent au moins un compte.');

        return [$dirigeante, $gerante ?? $dirigeante];
    }

    /** Le compte magasinier de démonstration — seulement s'il n'existe pas déjà. */
    private function creerMagasinier(ObjectManager $manager): void
    {
        $email = 'adama.magasin@zedpos.ci';
        if (null !== $manager->getRepository(Utilisateur::class)->findOneBy(['email' => $email])) {
            return;
        }
        // Base aux comptes conservés : on n'ajoute pas un accès que l'exploitant n'a
        // pas créé. Le compte de démonstration de la dirigeante signe une base de démo.
        if (null === $manager->getRepository(Utilisateur::class)->findOneBy(['email' => 'aya.kone@zedpos.ci'])) {
            return;
        }

        $magasinier = new Utilisateur($email, 'Adama Ouattara (magasin)');
        $magasinier->setRoles([RoleUtilisateur::MAGASIN->value]);
        $magasinier->setMotDePasse($this->hasher->hashPassword($magasinier, 'magasin123'));
        $manager->persist($magasinier);
        $manager->flush();
    }
}
