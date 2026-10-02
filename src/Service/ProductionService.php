<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\FicheProduction;
use App\Entity\SaisieProduction;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Enum\Atelier;
use App\Enum\TypeSaisieProduction;
use App\Repository\ArticleRepository;
use App\Repository\FicheProductionRepository;
use App\Repository\SaisieProductionRepository;
use App\Repository\SessionCaisseRepository;
use App\Service\Production\LigneFiche;
use App\Service\Production\RapportProduction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fiche de production de la caisse ouverte, et déclarations qui la remplissent.
 *
 * - **À l'ouverture de la caisse**, la fiche naît avec tous les articles d'atelier
 *   ({@see self::ouvrirFiche()}, appelée par `SessionCaisseService`) : produit et
 *   mis en vitrine à zéro, et **ce qui restait en vitrine à la clôture de la
 *   caisse précédente repris en vitrine**.
 * - Le boulanger, le pâtissier et la gérante y **ajoutent** : chaque déclaration
 *   est une fournée ou un réassort, tracée ({@see SaisieProduction}) et
 *   annulable, et s'additionne à la ligne de la fiche.
 * - **Le vendu se lit dans les ventes de la caisse**, en direct : rien n'empêche
 *   de vendre un article qui n'est pas encore déclaré.
 * - Après le Z, plus rien ne s'y déclare ; la caisse suivante ouvre sa fiche
 *   en reprenant les restes.
 *
 * Chaque écriture se fait sous verrou de la ligne de caisse (`FOR UPDATE`) : deux
 * déclarations simultanées ne se marchent pas dessus, et aucune ne se glisse dans
 * une caisse en train de passer au Z.
 */
class ProductionService
{
    /** Au-delà, c'est une touche restée enfoncée, pas une fournée. */
    private const UNITES_MAX = 99999;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SaisieProductionRepository $saisies,
        private readonly FicheProductionRepository $fiches,
        private readonly SessionCaisseRepository $sessions,
        private readonly ArticleRepository $articles,
        private readonly AuditLogger $audit,
    ) {
    }

    public function caisseOuverte(): ?SessionCaisse
    {
        return $this->sessions->ouverteQuelconque();
    }

    /**
     * Crée la fiche de la caisse : tous les articles d'atelier actifs, produit et
     * mis en vitrine à zéro. Ce qui restait en vitrine à la clôture de la caisse
     * précédente (reste **positif** : vitrine − vendu) est repris en vitrine — les
     * croissants de la veille sont toujours sur le présentoir. Un reste négatif
     * (vendu sans déclaration) ne se reprend pas : il n'y a rien sur le présentoir.
     *
     * Persistée, pas écrite : l'appelant la flushe avec l'ouverture de la caisse.
     */
    public function ouvrirFiche(SessionCaisse $session): FicheProduction
    {
        $fiche = new FicheProduction($session, $this->articles->dAtelier());

        $precedente = $this->sessions->derniereCloturee();
        if (null !== $precedente && $precedente !== $session) {
            foreach ($this->lireFiche($precedente) as $ligne) {
                if ($ligne->reste() > 0) {
                    $fiche->ligneDe($ligne->article)->reprendre($ligne->reste());
                }
            }
        }

        $this->em->persist($fiche);

        return $fiche;
    }

    /**
     * La fiche telle qu'on la lit : une ligne par article, dans l'ordre de la
     * caisse, avec produit, vitrine (reprise comprise), vendu et montant rapporté.
     *
     * Y figurent les lignes de la fiche, les articles d'atelier vendus sans y
     * être (un article dont la famille a été rattachée en cours de journée) et,
     * tant que la caisse est ouverte, les articles d'atelier ajoutés au
     * catalogue depuis l'ouverture — à zéro. Une caisse ouverte avant l'existence
     * des fiches se lit donc aussi, sans rien écrire.
     *
     * @return list<LigneFiche>
     */
    public function lireFiche(SessionCaisse $session): array
    {
        $fiche = $this->fiches->deLaSession($session);
        $ventes = $this->fiches->ventesParArticle($session);

        $quantites = [];
        foreach ($fiche?->getLignes() ?? [] as $ligne) {
            $quantites[(int) $ligne->getArticle()->getId()] = [$ligne->getQteProduite(), $ligne->getQteReprise() + $ligne->getQteVitrine(), $ligne->getQteReprise()];
        }

        $ids = array_keys($quantites + $ventes);
        if ($session->estOuverte()) {
            foreach ($this->articles->dAtelier() as $article) {
                $ids[] = (int) $article->getId();
            }
        }

        $lignes = [];
        foreach ($this->articles->parIdsOrdreCaisse(array_values(array_unique($ids))) as $article) {
            $id = (int) $article->getId();
            $lignes[] = new LigneFiche(
                $article,
                $quantites[$id][0] ?? 0,
                $quantites[$id][1] ?? 0,
                $ventes[$id]['quantite'] ?? 0,
                $ventes[$id]['montant'] ?? 0,
                $quantites[$id][2] ?? 0,
            );
        }

        return $lignes;
    }

    /** Le rapport de production de la caisse : sa fiche, ses totaux, ses remises de ticket. */
    public function rapport(SessionCaisse $session): RapportProduction
    {
        return new RapportProduction($session, $this->lireFiche($session), $this->fiches->remisesTickets($session));
    }

    /**
     * @param array<int|string, mixed> $quantites unités entières par id d'article, telles que saisies
     *
     * @throws \DomainException aucune caisse ouverte, saisie invalide
     */
    public function declarer(TypeSaisieProduction $type, ?Atelier $atelier, array $quantites, Utilisateur $auteur): SaisieProduction
    {
        $session = $this->caisseOuverte()
            ?? throw new \DomainException('Aucune caisse n\'est ouverte : la production se déclare pendant une journée de caisse.');
        $lignes = $this->lireQuantites($quantites, $type, $atelier);

        return $this->transaction($session, function () use ($session, $type, $atelier, $lignes, $auteur): SaisieProduction {
            $saisie = new SaisieProduction(
                $this->saisies->prochainNumero($type, new \DateTimeImmutable()),
                $type,
                $session,
                $atelier,
                $lignes,
                $auteur,
            );
            $this->em->persist($saisie);
            $this->reporter($saisie, 1);
            $this->em->flush();

            $this->audit->productionDeclaree($saisie);

            return $saisie;
        });
    }

    /**
     * Annule une déclaration et la retire de la fiche. Possible après le Z : la
     * gérante corrige une fournée mal saisie ({@see \App\Security\Voter\ProductionVoter})
     * — **tant qu'aucune caisse n'a été ouverte ensuite** : celle-ci a repris les
     * restes de la fiche, les changer après coup ferait mentir sa reprise.
     *
     * @throws \DomainException motif manquant, caisse suivante déjà ouverte
     */
    public function annuler(SaisieProduction $saisie, ?string $motif, Utilisateur $auteur): void
    {
        $saisie->verifierAnnulable($motif);
        $session = $saisie->getSessionCaisse();
        $avant = $this->audit->etatSaisieProduction($saisie);

        $this->transaction($session, function () use ($saisie, $session, $motif, $auteur, $avant): void {
            if ($this->sessions->aUneSuivante($session)) {
                throw new \DomainException(\sprintf('Une caisse a été ouverte depuis et a repris les restes de celle-ci : la déclaration %s ne s\'annule plus.', $saisie->getNumero()));
            }

            $this->reporter($saisie, -1);
            $saisie->annuler($motif, $auteur, new \DateTimeImmutable());
            $this->em->flush();

            $this->audit->productionAnnulee($saisie, $avant, $auteur);
        });
    }

    /**
     * Quantités saisies à l'écran → lignes. Case vide ou zéro : article non pris.
     * Unités entières seulement — le pavé ne tape pas de virgule.
     *
     * @param array<int|string, mixed> $quantites
     *
     * @return list<array{0: Article, 1: int}> article et quantité en millièmes
     */
    public function lireQuantites(array $quantites, TypeSaisieProduction $type, ?Atelier $atelier): array
    {
        $unitesParId = [];
        foreach ($quantites as $id => $valeur) {
            $saisie = trim((string) (\is_scalar($valeur) ? $valeur : ''));
            if ('' === $saisie) {
                continue;
            }
            if (!ctype_digit($saisie) || (int) $saisie > self::UNITES_MAX) {
                throw new \DomainException(\sprintf('Quantité invalide : « %s ».', $saisie));
            }
            if ((int) $saisie > 0) {
                $unitesParId[(int) $id] = (int) $saisie;
            }
        }

        if ([] === $unitesParId) {
            throw new \DomainException('Rien à déclarer : saisissez au moins une quantité.');
        }

        $lignes = [];
        foreach ($this->articles->parIdsOrdreCaisse(array_keys($unitesParId)) as $article) {
            $famille = $article->getFamilleProduit();
            if (!$article->isActif() || null === $famille?->getAtelier()) {
                throw new \DomainException(\sprintf('« %s » ne se déclare pas en production.', $article->getNom()));
            }
            if (TypeSaisieProduction::PRODUCTION === $type && $famille->getAtelier() !== $atelier) {
                throw new \DomainException(\sprintf('« %s » ne relève pas de l\'atelier « %s ».', $article->getNom(), (string) $atelier?->libelle()));
            }
            $lignes[] = [$article, $unitesParId[$article->getId()] * 1000];
        }

        if (\count($lignes) !== \count($unitesParId)) {
            throw new \DomainException('Un article saisi n\'existe plus : rechargez l\'écran.');
        }

        return $lignes;
    }

    /**
     * Ajoute (sens 1) ou retire (sens −1) une déclaration de la fiche de sa caisse.
     * La fiche est créée si elle manque — caisse ouverte avant l'existence des fiches.
     */
    private function reporter(SaisieProduction $saisie, int $sens): void
    {
        $fiche = $this->fiches->deLaSession($saisie->getSessionCaisse()) ?? $this->ouvrirFiche($saisie->getSessionCaisse());

        foreach ($saisie->getLignes() as $ligne) {
            $ligneFiche = $fiche->ligneDe($ligne->getArticle());
            $saisie->estProduction()
                ? $ligneFiche->ajouterProduction($sens * $ligne->getQuantite())
                : $ligneFiche->ajouterVitrine($sens * $ligne->getQuantite());
        }
    }

    /**
     * Transaction DBAL tenue à la main, sous verrou de la ligne de caisse :
     * `wrapInTransaction()` fermerait l'EntityManager sur un refus métier, et
     * l'écran ne pourrait plus se réafficher avec le message.
     *
     * @template T
     *
     * @param callable(): T $travail
     *
     * @return T
     */
    private function transaction(SessionCaisse $session, callable $travail): mixed
    {
        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();

        try {
            if (false === $connexion->fetchOne('SELECT id FROM session_caisse WHERE id = ? FOR UPDATE', [$session->getId()])) {
                throw new \DomainException('Caisse introuvable.');
            }
            // Relue sous verrou : un Z passé entre-temps doit refuser la déclaration,
            // l'objet en mémoire la croirait encore ouverte.
            $this->em->refresh($session);

            $resultat = $travail();
            $connexion->commit();

            return $resultat;
        } catch (\Throwable $e) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }

            throw $e;
        }
    }
}
