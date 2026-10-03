<?php

namespace App\Service\Magasin;

use App\Entity\Fournisseur;
use App\Entity\Magasin\MagasinEmplacement;
use App\Entity\Magasin\MagasinLigneReception;
use App\Entity\Magasin\MagasinProduit;
use App\Entity\Magasin\MagasinReception;
use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Enum\Magasin\MotifRejetMagasin;
use App\Enum\Magasin\TypeMouvementMagasin;
use App\Repository\Magasin\MagasinEmplacementRepository;
use App\Repository\Magasin\MagasinMouvementRepository;
use App\Repository\Magasin\MagasinProduitRepository;
use App\Repository\Magasin\MagasinReceptionRepository;
use App\Service\AuditLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les quatre premières étapes du plan de traitement du magasin : réception,
 * contrôle, inspection, stockage — et l'annulation.
 *
 * Les règles d'enchaînement sont dans {@see MagasinReception} ; ce service
 * enregistre chaque étape, la trace au journal, et au **stockage** — seule étape
 * qui touche au stock — fait entrer les quantités acceptées par
 * {@see MagasinStockService} et met à jour le coût moyen pondéré.
 *
 * **Coût moyen pondéré** (centimes par unité de stock), recalculé au stockage
 * pour chaque produit dont une ligne porte un prix, sur le stock de tout le
 * magasin :
 *
 *     CMP = (stock × CMP + valeur acceptée) / (stock + quantité acceptée)
 *
 * en entiers, arrondi au centime. Stock nul ou négatif avant : le CMP devient le
 * prix d'achat ramené à l'unité de stock (25 000 F le sac de 50 kg → 500 F le kg).
 * Sans prix, le CMP ne bouge pas.
 *
 * **À l'annulation** d'une réception stockée, la livraison est retirée au prix où
 * elle est entrée — CMP = (stock × CMP − valeur) / (stock − quantité), borné à
 * zéro, inchangé s'il ne reste rien. Approximation assumée : si d'autres
 * livraisons ont suivi, on ne retombe pas exactement sur le CMP d'avant.
 */
class ReceptionMagasinService
{
    public const DOCUMENT = 'reception';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MagasinReceptionRepository $receptions,
        private readonly MagasinProduitRepository $produits,
        private readonly MagasinEmplacementRepository $emplacements,
        private readonly MagasinMouvementRepository $mouvements,
        private readonly MagasinStockService $stock,
        private readonly AuditLogger $audit,
    ) {
    }

    // ------------------------------------------------------------------ Étapes

    /**
     * Étape 1 — la réception est créée avec ce qu'annonce le bon de livraison.
     *
     * @param list<array{produit: MagasinProduit, annoncee: int, prix?: ?int}> $lignes
     */
    public function creer(Fournisseur $fournisseur, \DateTimeImmutable $date, ?string $bonLivraison, ?string $commentaire, array $lignes, Utilisateur $auteur): MagasinReception
    {
        return $this->transaction(null, function () use ($fournisseur, $date, $bonLivraison, $commentaire, $lignes, $auteur): MagasinReception {
            // Verrou de table léger : deux réceptions créées à la même seconde ne
            // calculent pas le même numéro.
            $this->connexion()->fetchOne('SELECT id FROM magasin_emplacement WHERE id = ? FOR UPDATE', [$this->stock->reserveParDefaut()->getId()]);

            $reception = new MagasinReception($this->receptions->prochainNumero($date), $fournisseur, $date, $auteur);
            $reception->annoncer($fournisseur, $date, $bonLivraison, $commentaire, $lignes);
            $this->em->persist($reception);
            $this->em->flush();

            $this->tracer(ActionAudit::MAGASIN_RECEPTION_RECUE, $reception, null, $auteur);

            return $reception;
        });
    }

    /**
     * @param list<array{produit: MagasinProduit, annoncee: int, prix?: ?int}> $lignes
     *
     * @throws \DomainException contrôle déjà fait
     */
    public function corrigerAnnonce(MagasinReception $reception, Fournisseur $fournisseur, \DateTimeImmutable $date, ?string $bonLivraison, ?string $commentaire, array $lignes, Utilisateur $auteur): void
    {
        $avant = $this->etat($reception);
        $this->transaction($reception, function () use ($reception, $fournisseur, $date, $bonLivraison, $commentaire, $lignes, $avant, $auteur): void {
            $reception->annoncer($fournisseur, $date, $bonLivraison, $commentaire, $lignes);
            $this->em->flush();
            $this->tracer(ActionAudit::MAGASIN_RECEPTION_RECUE, $reception, $avant, $auteur);
        });
    }

    /**
     * Étape 2 — le comptage.
     *
     * @param array<int, int> $comptees par id de ligne, millièmes d'unité de saisie
     *
     * @throws \DomainException ligne non comptée, écart sans commentaire, étape déjà dépassée
     */
    public function controler(MagasinReception $reception, array $comptees, ?string $commentaire, Utilisateur $auteur): void
    {
        $avant = $this->etat($reception);
        $this->transaction($reception, function () use ($reception, $comptees, $commentaire, $auteur, $avant): void {
            $reception->controler($comptees, $commentaire, $auteur, new \DateTimeImmutable());
            $this->em->flush();
            $this->tracer(ActionAudit::MAGASIN_RECEPTION_CONTROLEE, $reception, $avant, $auteur);
        });
    }

    /**
     * Étape 3 — l'inspection.
     *
     * @param array<int, array{acceptee: int, rejetee: int, motif: ?MotifRejetMagasin}> $decisions par id de ligne
     *
     * @throws \DomainException
     */
    public function inspecter(MagasinReception $reception, array $decisions, Utilisateur $auteur): void
    {
        $avant = $this->etat($reception);
        $this->transaction($reception, function () use ($reception, $decisions, $auteur, $avant): void {
            $reception->inspecter($decisions, $auteur, new \DateTimeImmutable());
            $this->em->flush();
            $this->tracer(ActionAudit::MAGASIN_RECEPTION_INSPECTEE, $reception, $avant, $auteur);
        });
    }

    /**
     * Prix d'achat des lignes, avant le stockage. Le contrôleur ne l'appelle
     * qu'avec MAGASIN_VOIR_PRIX (dirigeante).
     *
     * @param array<int, ?int> $prix par id de ligne, centimes par unité de saisie
     */
    public function fixerPrix(MagasinReception $reception, array $prix): void
    {
        $reception->fixerPrix($prix);
        $this->em->flush();
    }

    /**
     * Étape 4 — le stockage : rangement, entrée en stock des quantités acceptées,
     * coût moyen pondéré. Une seule transaction, tout ou rien.
     *
     * @param array<int, MagasinEmplacement> $emplacements par id de ligne
     *
     * @throws \DomainException emplacement manquant ou désactivé, étape non atteinte
     */
    public function stocker(MagasinReception $reception, array $emplacements, Utilisateur $auteur): void
    {
        $avant = $this->etat($reception);

        $this->transaction($reception, function () use ($reception, $emplacements, $auteur, $avant): void {
            $reception->stocker($emplacements, $auteur, new \DateTimeImmutable());

            $entrees = $this->entreesParProduit($reception);
            $this->verrouillerProduits(array_keys($entrees));

            // CMP calculé sur le stock d'avant l'entrée, lu sous verrou.
            $nouveauxCmp = [];
            foreach ($entrees as $id => [$produit, $quantite, $valeur]) {
                if (null !== $valeur) {
                    $nouveauxCmp[$id] = self::cmpApresEntree($this->stock->stockTotal($produit), $produit->getCoutMoyen(), $quantite, $valeur);
                }
            }

            $demandes = [];
            foreach ($reception->getLignes() as $ligne) {
                if ($ligne->qteAccepteeEnStock() > 0) {
                    $demandes[] = new DemandeMouvementMagasin(
                        $ligne->getProduit(),
                        $ligne->getEmplacement() ?? throw new \LogicException('Ligne acceptée sans emplacement.'),
                        $ligne->qteAccepteeEnStock(),
                        TypeMouvementMagasin::ENTREE_RECEPTION,
                        self::DOCUMENT,
                        (int) $reception->getId(),
                        'Réception '.$reception->getNumero(),
                    );
                }
            }
            $this->stock->appliquer($demandes, $auteur);

            foreach ($nouveauxCmp as $id => $cmp) {
                $entrees[$id][0]->definirCoutMoyen($cmp);
            }

            $this->em->flush();
            $this->tracer(ActionAudit::MAGASIN_RECEPTION_STOCKEE, $reception, $avant, $auteur);
        });
    }

    /**
     * Annule une réception. Stockée : ses entrées sont contre-passées — refusé si
     * le stock passait sous zéro (marchandise déjà sortie) — et la livraison est
     * retirée du CMP. Avant le stockage : rien n'était entré, simple annulation.
     *
     * @throws StockMagasinInsuffisantException
     * @throws \DomainException déjà annulée, motif manquant
     */
    public function annuler(MagasinReception $reception, ?string $motif, Utilisateur $auteur): void
    {
        $reception->verifierAnnulable($motif);
        $avant = $this->etat($reception);

        $this->transaction($reception, function () use ($reception, $motif, $auteur, $avant): void {
            if ($reception->estStockee()) {
                $entrees = $this->entreesParProduit($reception);
                $this->verrouillerProduits(array_keys($entrees));

                $nouveauxCmp = [];
                foreach ($entrees as $id => [$produit, $quantite, $valeur]) {
                    if (null !== $valeur) {
                        $nouveauxCmp[$id] = self::cmpApresRetrait($this->stock->stockTotal($produit), $produit->getCoutMoyen(), $quantite, $valeur);
                    }
                }

                $origines = array_values(array_filter(
                    $this->mouvements->duDocument(self::DOCUMENT, (int) $reception->getId()),
                    static fn ($m): bool => TypeMouvementMagasin::ENTREE_RECEPTION === $m->getType(),
                ));
                $this->stock->contrepasser($origines, self::DOCUMENT, (int) $reception->getId(), 'Annulation '.$reception->getNumero(), $auteur);

                foreach ($nouveauxCmp as $id => $cmp) {
                    $entrees[$id][0]->definirCoutMoyen($cmp);
                }
            }

            $reception->annuler($motif, $auteur, new \DateTimeImmutable());
            $this->em->flush();
            $this->tracer(ActionAudit::MAGASIN_RECEPTION_ANNULEE, $reception, $avant, $auteur);
        });
    }

    // ------------------------------------------------------------ Coût moyen

    /**
     * @param int $stock    millièmes d'unité de stock avant l'entrée
     * @param int $cmp      centimes par unité de stock
     * @param int $quantite millièmes d'unité de stock entrés
     * @param int $valeur   centimes
     */
    public static function cmpApresEntree(int $stock, int $cmp, int $quantite, int $valeur): int
    {
        if ($quantite <= 0) {
            return $cmp;
        }
        if ($stock <= 0) {
            return self::diviserArrondi($valeur * 1000, $quantite);
        }

        return self::diviserArrondi($stock * $cmp + $valeur * 1000, $stock + $quantite);
    }

    /** Voir le commentaire de classe. */
    public static function cmpApresRetrait(int $stock, int $cmp, int $quantite, int $valeur): int
    {
        $reste = $stock - $quantite;
        if ($quantite <= 0 || $reste <= 0) {
            return $cmp;
        }

        return max(0, self::diviserArrondi($stock * $cmp - $valeur * 1000, $reste));
    }

    // ------------------------------------------------------------- Lecture saisie

    /**
     * Lignes saisies à l'étape 1 → lignes de la réception. Une ligne sans produit
     * ni quantité est ignorée (ligne vide de l'écran).
     *
     * @param array<int|string, mixed> $saisie  lignes[i][produit|annoncee|prix]
     * @param bool                     $avecPrix la saisie porte-t-elle les prix (dirigeante) ?
     *
     * @return list<array{produit: MagasinProduit, annoncee: int, prix?: ?int}>
     *
     * @throws \DomainException
     */
    public function lireAnnonce(array $saisie, bool $avecPrix): array
    {
        $lignes = [];
        foreach ($saisie as $valeurs) {
            if (!\is_array($valeurs)) {
                continue;
            }
            $id = (int) (\is_scalar($valeurs['produit'] ?? null) ? $valeurs['produit'] : 0);
            $texte = \is_scalar($valeurs['annoncee'] ?? null) ? trim((string) $valeurs['annoncee']) : '';
            if (0 === $id && '' === $texte) {
                continue;
            }
            $produit = (0 !== $id ? $this->produits->find($id) : null) ?? throw new \DomainException('Choisissez le produit de chaque ligne.');

            $ligne = [
                'produit' => $produit,
                'annoncee' => SaisieQuantite::millimes($texte, $produit->getNom()) ?? throw new \DomainException(\sprintf('« %s » : saisissez la quantité annoncée.', $produit->getNom())),
            ];
            if ($avecPrix) {
                $ligne['prix'] = SaisieQuantite::prix($valeurs['prix'] ?? '', $produit->getNom());
            }
            $lignes[] = $ligne;
        }

        return $lignes;
    }

    /**
     * Comptages saisis à l'étape 2.
     *
     * @param array<int|string, mixed> $saisie par id de ligne
     *
     * @return array<int, int>
     */
    public function lireComptages(MagasinReception $reception, array $saisie): array
    {
        $comptees = [];
        foreach ($reception->getLignes() as $ligne) {
            $valeur = SaisieQuantite::millimes($saisie[$ligne->getId()] ?? '', $ligne->getProduit()->getNom());
            if (null !== $valeur) {
                $comptees[(int) $ligne->getId()] = $valeur;
            }
        }

        return $comptees;
    }

    /**
     * Décisions saisies à l'étape 3. « Rejeté » laissé vide vaut zéro, « accepté »
     * laissé vide vaut « tout le compté moins le rejeté » : la livraison sans
     * incident s'inspecte d'un seul appui.
     *
     * @param array<int|string, mixed> $saisie par id de ligne : [acceptee, rejetee, motif]
     *
     * @return array<int, array{acceptee: int, rejetee: int, motif: ?MotifRejetMagasin}>
     */
    public function lireDecisions(MagasinReception $reception, array $saisie): array
    {
        $decisions = [];
        foreach ($reception->getLignes() as $ligne) {
            $valeurs = \is_array($saisie[$ligne->getId()] ?? null) ? $saisie[$ligne->getId()] : [];
            $nom = $ligne->getProduit()->getNom();
            $rejetee = SaisieQuantite::millimes($valeurs['rejetee'] ?? '', $nom) ?? 0;
            $acceptee = SaisieQuantite::millimes($valeurs['acceptee'] ?? '', $nom) ?? max(0, (int) $ligne->getQteComptee() - $rejetee);
            $decisions[(int) $ligne->getId()] = [
                'acceptee' => $acceptee,
                'rejetee' => $rejetee,
                'motif' => MotifRejetMagasin::tryFrom(\is_scalar($valeurs['motif'] ?? null) ? (string) $valeurs['motif'] : ''),
            ];
        }

        return $decisions;
    }

    /**
     * Emplacements choisis à l'étape 4. Sans choix — le cas d'un magasin à une
     * seule zone — tout va dans la réserve principale.
     *
     * @param array<int|string, mixed> $saisie par id de ligne : id d'emplacement
     *
     * @return array<int, MagasinEmplacement>
     */
    public function lireRangement(MagasinReception $reception, array $saisie): array
    {
        $reserve = $this->stock->reserveParDefaut();
        $rangement = [];
        foreach ($reception->getLignes() as $ligne) {
            $id = (int) (\is_scalar($saisie[$ligne->getId()] ?? null) ? $saisie[$ligne->getId()] : 0);
            $rangement[(int) $ligne->getId()] = (0 !== $id ? $this->emplacements->find($id) : null) ?? $reserve;
        }

        return $rangement;
    }

    /**
     * Prix saisis par ligne (dirigeante), en FCFA.
     *
     * @param array<int|string, mixed> $saisie par id de ligne
     *
     * @return array<int, ?int> centimes par unité de saisie
     */
    public function lirePrix(MagasinReception $reception, array $saisie): array
    {
        $prix = [];
        foreach ($reception->getLignes() as $ligne) {
            $prix[(int) $ligne->getId()] = SaisieQuantite::prix($saisie[$ligne->getId()] ?? '', $ligne->getProduit()->getNom());
        }

        return $prix;
    }

    // ------------------------------------------------------------------ Interne

    /**
     * Ce qui entre, produit par produit : [produit, millièmes d'unité de stock,
     * valeur en centimes ou nul si aucune ligne du produit n'a de prix].
     *
     * @return array<int, array{0: MagasinProduit, 1: int, 2: ?int}>
     */
    private function entreesParProduit(MagasinReception $reception): array
    {
        $entrees = [];
        foreach ($reception->getLignes() as $ligne) {
            $quantite = $ligne->qteAccepteeEnStock();
            if ($quantite <= 0) {
                continue;
            }
            $id = (int) $ligne->getProduit()->getId();
            $entrees[$id] ??= [$ligne->getProduit(), 0, null];
            $entrees[$id][1] += $quantite;
            if (null !== $ligne->valeurAcceptee()) {
                $entrees[$id][2] = ($entrees[$id][2] ?? 0) + $ligne->valeurAcceptee();
            }
        }
        ksort($entrees);

        return $entrees;
    }

    /** @param list<int> $ids */
    private function verrouillerProduits(array $ids): void
    {
        if ([] === $ids) {
            return;
        }
        $this->connexion()->fetchFirstColumn(
            'SELECT id FROM magasin_produit WHERE id IN (?) ORDER BY id FOR UPDATE',
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
        foreach ($ids as $id) {
            $produit = $this->em->find(MagasinProduit::class, $id);
            if (null !== $produit) {
                // Relu sous verrou : le CMP en mémoire peut dater du début de la requête.
                $this->em->refresh($produit);
            }
        }
    }

    private function tracer(ActionAudit $action, MagasinReception $reception, ?array $avant, Utilisateur $auteur): void
    {
        $this->audit->enregistrer($action, 'MagasinReception', $reception->getId(), $avant, $this->etat($reception), $auteur);
    }

    /**
     * État journalisé : quantités en millièmes d'unité de saisie, prix en centimes,
     * coût moyen du produit en centimes par unité de stock — le journal se lit à la
     * dirigeante, qui voit les prix.
     *
     * @return array<string, mixed>
     */
    private function etat(MagasinReception $reception): array
    {
        return [
            'numero' => $reception->getNumero(),
            'statut' => $reception->getStatut()->value,
            'fournisseur' => $reception->getFournisseur()->getNom(),
            'bonLivraison' => $reception->getBonLivraison(),
            'date' => $reception->getDateReception()->format('Y-m-d'),
            'commentaireControle' => $reception->getCommentaireControle(),
            'lignes' => array_values(array_map(static fn (MagasinLigneReception $l): array => [
                'produit' => $l->getProduit()->getNom(),
                'unite' => $l->getUniteSaisie(),
                'annoncee' => $l->getQteAnnoncee(),
                'comptee' => $l->getQteComptee(),
                'acceptee' => $l->getQteAcceptee(),
                'rejetee' => $l->getQteRejetee(),
                'motifRejet' => $l->getMotifRejet()?->value,
                'emplacement' => $l->getEmplacement()?->getCode(),
                'prix' => $l->getPrixUnitaire(),
                'cmp' => $l->getProduit()->getCoutMoyen(),
            ], $reception->getLignes()->toArray())),
            'motifAnnulation' => $reception->getMotifAnnulation(),
        ];
    }

    private static function diviserArrondi(int $numerateur, int $denominateur): int
    {
        $signe = ($numerateur < 0) !== ($denominateur < 0) ? -1 : 1;

        return $signe * intdiv(abs($numerateur) * 2 + abs($denominateur), abs($denominateur) * 2);
    }

    /**
     * Transaction DBAL tenue à la main : `wrapInTransaction()` fermerait
     * l'EntityManager sur un refus métier, et l'écran ne pourrait plus se
     * réafficher avec son message. Sur échec, la réception et ses produits sont
     * relus : statut et CMP ont pu changer en mémoire.
     *
     * @template T
     *
     * @param callable(): T $travail
     *
     * @return T
     */
    private function transaction(?MagasinReception $reception, callable $travail): mixed
    {
        $connexion = $this->connexion();
        $connexion->beginTransaction();

        try {
            $resultat = $travail();
            $connexion->commit();

            return $resultat;
        } catch (\Throwable $e) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }
            if (null !== $reception && $this->em->isOpen() && $this->em->contains($reception)) {
                $produits = [];
                foreach ($reception->getLignes() as $ligne) {
                    $produits[] = $ligne->getProduit();
                    if ($this->em->contains($ligne)) {
                        $this->em->refresh($ligne);
                    }
                }
                foreach ($produits as $produit) {
                    if ($this->em->contains($produit)) {
                        $this->em->refresh($produit);
                    }
                }
                $this->em->refresh($reception);
            }

            throw $e;
        }
    }

    private function connexion(): Connection
    {
        return $this->em->getConnection();
    }
}
