<?php

namespace App\Service;

use App\Entity\LigneVente;
use App\Entity\Reglement;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Entity\Article;
use App\Enum\ActionAudit;
use App\Enum\ModeReglement;
use App\Enum\ModeVente;
use App\Enum\StatutVente;
use App\Repository\ArticleRepository;
use App\Repository\SessionCaisseRepository;
use App\Repository\VenteRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Logique d'encaissement : création transactionnelle d'une vente, idempotente sur
 * l'UUID, avec remise plafonnée, paiement mixte et rendu de monnaie.
 *
 * Tous les montants sont en centimes de FCFA (arithmétique entière).
 */
class EncaissementService
{
    /** Seuil au-delà duquel un motif de remise est obligatoire : 500 FCFA. */
    private const SEUIL_MOTIF_REMISE = 50000;

    /** Une vente plus vieille que cela n'est plus datée par le client : elle prend l'heure d'arrivée. */
    private const ANCIENNETE_MAX_VENTE = 'P7D';

    /** Tolérance sur l'horloge d'une tablette qui avance de quelques minutes. */
    private const DERIVE_HORLOGE = '+2 minutes';

    /**
     * En deçà, la vente est « en direct » : un article désactivé reste refusé.
     * Au-delà, c'est un rejeu hors ligne.
     */
    private const DELAI_REJEU = '-1 minute';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VenteRepository $ventes,
        private readonly ArticleRepository $articles,
        private readonly SessionCaisseRepository $sessions,
        private readonly AuditLogger $audit,
        private readonly NotificateurDirigeante $notificateur,
    ) {
    }

    /**
     * @param array<string, mixed> $donnees   Ticket reçu (uuid, mode, lignes, remise, reglements)
     * @param int                  $remiseMaxBp Plafond de remise autorisé pour le rôle, en points de base
     */
    public function encaisser(Utilisateur $utilisateur, array $donnees, int $remiseMaxBp): ResultatEncaissement
    {
        $uuid = $this->lireUuid($donnees['uuid'] ?? null);

        // Idempotence : la même vente n'est jamais créée deux fois.
        $existante = $this->ventes->findOneBy(['uuid' => $uuid]);
        if (null !== $existante) {
            return new ResultatEncaissement($existante, $existante->getRendu(), true);
        }

        $mode = ModeVente::tryFrom((string) ($donnees['mode'] ?? ''))
            ?? throw new EncaissementException('Mode de vente invalide.');

        // Heure à laquelle la vente a réellement eu lieu au comptoir (la tablette
        // l'écrit à l'encaissement). Elle diffère de l'heure d'arrivée dès que la
        // vente a attendu le retour du réseau.
        $venduA = $this->lireVenduA($donnees['venduA'] ?? null);

        [$preparees, $brutHt, $brutTva, $brutTtc] = $this->preparerLignes($donnees['lignes'] ?? [], $venduA);
        [$remise, $motifRemise] = $this->calculerRemise($donnees['remise'] ?? null, $brutTtc, $remiseMaxBp);

        $netTtc = $brutTtc - $remise;
        $remiseTva = $brutTtc > 0 ? intdiv($remise * $brutTva, $brutTtc) : 0;
        $netTva = $brutTva - $remiseTva;
        $netHt = $netTtc - $netTva;

        [$reglements, $rendu] = $this->preparerReglements($donnees['reglements'] ?? [], $netTtc);

        try {
            $vente = $this->em->wrapInTransaction(function () use (
                $utilisateur, $mode, $uuid, $netHt, $netTva, $netTtc, $remise, $motifRemise, $rendu, $preparees, $reglements, $venduA
            ): Vente {
                $vente = new Vente($this->sessionOuverte($utilisateur), $mode, $this->genererNumero($venduA), $netHt, $netTva, $netTtc, $uuid);
                $vente->enregistrerRemiseEtRendu($remise, $motifRemise, $rendu);
                if (null !== $venduA) {
                    $vente->dater($venduA);
                }

                foreach ($preparees as $p) {
                    new LigneVente($vente, $p['article'], $p['quantiteMillimes'], $p['prix'], 0, $p['commentaire']);
                }
                foreach ($reglements as $r) {
                    new Reglement($vente, $r['mode'], $r['montant'], $r['reference']);
                }

                $this->em->persist($vente);
                $this->em->flush();

                return $vente;
            });
        } catch (UniqueConstraintViolationException) {
            // Course entre deux requêtes identiques : réessayez (la relecture par UUID
            // réussira au prochain appel puisque la vente est désormais enregistrée).
            throw new EncaissementException("Conflit d'enregistrement, veuillez réessayer.", 409);
        }

        // Toute remise consentie est tracée, quel qu'en soit le montant.
        if ($remise > 0) {
            $this->audit->remiseAccordee($vente);
        }

        return new ResultatEncaissement($vente, $rendu, false);
    }

    /**
     * Modifie un ticket encaissé : l'original est **annulé** (jamais supprimé) et
     * une vente de remplacement est créée dans la même session, **dans la même
     * transaction** — un original annulé sans remplaçant perdrait la vente, un
     * remplaçant sans original annulé la compterait deux fois.
     *
     * Le remplaçant est recalculé côté serveur comme un encaissement ordinaire.
     * Idempotent sur son uuid : une réponse perdue se rejoue sans rien créer.
     * Qui peut modifier quoi est tranché par `VenteVoter`, au contrôleur.
     *
     * @param array<string, mixed> $donnees Ticket corrigé (uuid du remplaçant, mode, lignes, remise, reglements)
     */
    public function modifier(Vente $originale, Utilisateur $auteur, array $donnees, int $remiseMaxBp): ResultatEncaissement
    {
        $uuid = $this->lireUuid($donnees['uuid'] ?? null);

        $existante = $this->ventes->findOneBy(['uuid' => $uuid]);
        if (null !== $existante) {
            if ($existante->getVenteRemplacee()?->getId() === $originale->getId()) {
                return new ResultatEncaissement($existante, $existante->getRendu(), true);
            }

            throw new EncaissementException('UUID de ticket déjà utilisé.', 409);
        }

        if (!$originale->estModifiable()) {
            throw new EncaissementException('Ce ticket a déjà été modifié une fois : il ne se modifie plus.', 409);
        }

        $mode = ModeVente::tryFrom((string) ($donnees['mode'] ?? ''))
            ?? throw new EncaissementException('Mode de vente invalide.');

        [$preparees, , $brutTva, $brutTtc] = $this->preparerLignes($donnees['lignes'] ?? []);
        [$remise, $motifRemise] = $this->calculerRemise($donnees['remise'] ?? null, $brutTtc, $remiseMaxBp);

        $netTtc = $brutTtc - $remise;
        $remiseTva = $brutTtc > 0 ? intdiv($remise * $brutTva, $brutTtc) : 0;
        $netTva = $brutTva - $remiseTva;
        $netHt = $netTtc - $netTva;

        [$reglements, $rendu] = $this->preparerReglements($donnees['reglements'] ?? [], $netTtc);

        // Les règles du domaine sont vérifiées **avant** la transaction :
        // `wrapInTransaction()` fermerait l'EntityManager sur exception.
        try {
            $vente = new Vente($originale->getSessionCaisse(), $mode, $this->genererNumero(), $netHt, $netTva, $netTtc, $uuid);
            $vente->enregistrerRemiseEtRendu($remise, $motifRemise, $rendu);
            $vente->remplacer($originale);
        } catch (\DomainException $e) {
            throw new EncaissementException($e->getMessage(), 409);
        }

        try {
            $this->em->wrapInTransaction(function () use ($vente, $preparees, $reglements): void {
                foreach ($preparees as $p) {
                    new LigneVente($vente, $p['article'], $p['quantiteMillimes'], $p['prix'], 0, $p['commentaire']);
                }
                foreach ($reglements as $r) {
                    new Reglement($vente, $r['mode'], $r['montant'], $r['reference']);
                }

                // Un seul flush : le déstockage du remplaçant et le restockage de
                // l'original partent ensemble (`DestockageVenteListener`).
                $this->em->persist($vente);
                $this->em->flush();
            });
        } catch (UniqueConstraintViolationException) {
            // Deux modifications simultanées du même ticket : l'unicité de
            // `vente_remplacee_id` n'en laisse passer qu'une.
            throw new EncaissementException('Ce ticket vient déjà d\'être modifié.', 409);
        }

        $this->audit->venteModifiee($originale, $vente);
        if ($remise > 0) {
            $this->audit->remiseAccordee($vente);
        }
        $this->notificateur->venteModifiee($originale, $vente, $auteur);

        return new ResultatEncaissement($vente, $rendu, false);
    }

    /**
     * Annule une vente (jamais de suppression). Qui a le droit d'annuler est
     * tranché par `VenteVoter`, au contrôleur : le gérant et la dirigeante. Le
     * caissier, lui, modifie ({@see self::modifier()}).
     */
    public function annuler(Uuid $uuid, string $motif, ?Utilisateur $auteur = null): Vente
    {
        $vente = $this->ventes->findOneBy(['uuid' => $uuid])
            ?? throw new EncaissementException('Vente introuvable.', 404);

        if ('' === trim($motif)) {
            throw new EncaissementException("Le motif d'annulation est obligatoire.");
        }
        if (StatutVente::ANNULEE === $vente->getStatut()) {
            throw new EncaissementException('Cette vente est déjà annulée.', 409);
        }

        try {
            // Refusé si la session de caisse est clôturée : le Z a arrêté la journée.
            $vente->annuler(trim($motif));
        } catch (\DomainException $e) {
            throw new EncaissementException($e->getMessage(), 409);
        }

        $this->em->flush();

        $motif = trim($motif);
        $this->audit->venteAnnulee($vente, $motif);
        // Une annulation ne reste jamais dans la caisse où elle a eu lieu — c'est
        // ce qui permet d'en ouvrir une part au caissier sans la rendre invisible.
        $this->notificateur->venteAnnulee($vente, $motif, $auteur);

        return $vente;
    }

    private function lireUuid(mixed $valeur): Uuid
    {
        if (!\is_string($valeur) || !Uuid::isValid($valeur)) {
            throw new EncaissementException('UUID de ticket invalide.');
        }

        return Uuid::fromString($valeur);
    }

    /**
     * Le prix retenu est celui **en vigueur à l'heure de la vente**, pas celui
     * d'aujourd'hui : une vente encaissée hors ligne avant un changement de prix a
     * été payée à l'ancien, et la rejouer au nouveau fausserait le rendu de
     * monnaie — donc les espèces du Z.
     *
     * Quand la tablette dit quel prix elle a affiché (`prix`) et que ce n'est pas
     * celui en vigueur, la vente est refusée franchement (422) plutôt qu'enregistrée
     * à un montant que le client n'a pas payé : elle reste alors visible sur la
     * tablette (« ventes à vérifier ») au lieu de faire un écart muet.
     *
     * @param mixed $lignes
     *
     * @return array{0: list<array{article: \App\Entity\Article, quantiteMillimes: int, prix: int, commentaire: ?string}>, 1: int, 2: int, 3: int}
     */
    private function preparerLignes(mixed $lignes, ?\DateTimeImmutable $venduA = null): array
    {
        if (!\is_array($lignes) || [] === $lignes) {
            throw new EncaissementException('Le ticket est vide.');
        }

        $articles = [];
        foreach ($lignes as $ligne) {
            $article = $this->articles->find((int) ($ligne['articleId'] ?? 0));
            if (null === $article) {
                throw new EncaissementException('Article indisponible.');
            }
            $articles[$article->getId()] = $article;
        }
        $prixEnVigueur = $this->prixEnVigueur($articles, $venduA);
        $rejeu = null !== $venduA && $venduA < (new \DateTimeImmutable())->modify(self::DELAI_REJEU);

        $preparees = [];
        $brutTtc = 0;
        $brutTva = 0;

        foreach ($lignes as $ligne) {
            $article = $articles[(int) ($ligne['articleId'] ?? 0)];
            $prix = $prixEnVigueur[$article->getId()];

            // Un article désactivé depuis l'encaissement hors ligne a bel et bien
            // été vendu : le refuser laisserait l'argent en tiroir sans vente.
            // Toléré pour un vrai rejeu seulement, et jamais sans prix (les
            // articles créés sans prix sont forcés inactifs).
            if (!$article->isActif() && !($rejeu && $prix > 0)) {
                throw new EncaissementException('Article indisponible.');
            }

            $affiche = $ligne['prix'] ?? null;
            if (null !== $affiche && (int) $affiche !== $prix) {
                throw new EncaissementException(\sprintf(
                    'Le prix de « %s » a changé : affiché %d FCFA, en vigueur %d FCFA.',
                    $article->getNom(),
                    intdiv((int) $affiche, 100),
                    intdiv($prix, 100),
                ), 422);
            }

            $quantite = max(1, (int) ($ligne['quantite'] ?? 1));
            $montantTtc = $quantite * $prix;
            $montantHt = intdiv($montantTtc * 10000, 10000 + $article->getTauxTva());

            $brutTtc += $montantTtc;
            $brutTva += $montantTtc - $montantHt;

            $commentaire = trim((string) ($ligne['commentaire'] ?? ''));
            $preparees[] = [
                'article' => $article,
                'quantiteMillimes' => $quantite * 1000,
                'prix' => $prix,
                'commentaire' => '' !== $commentaire ? $commentaire : null,
            ];
        }

        return [$preparees, $brutTtc - $brutTva, $brutTva, $brutTtc];
    }

    /**
     * Heure de la vente déclarée par la tablette, ou null si elle n'est pas
     * crédible (illisible, dans le futur, ou vieille de plus de sept jours) : la
     * vente prend alors l'heure d'arrivée, comme avant.
     */
    private function lireVenduA(mixed $valeur): ?\DateTimeImmutable
    {
        if (!\is_string($valeur) || '' === $valeur) {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($valeur);
        } catch (\Exception) {
            return null;
        }

        $maintenant = new \DateTimeImmutable();
        if ($date > $maintenant->modify(self::DERIVE_HORLOGE)
            || $date < $maintenant->sub(new \DateInterval(self::ANCIENNETE_MAX_VENTE))) {
            return null;
        }

        return $date > $maintenant ? $maintenant : $date->setTimezone($maintenant->getTimezone());
    }

    /**
     * Prix de vente de chaque article **à l'instant `$venduA`**, en centimes.
     *
     * Le journal d'audit tient l'historique : chaque changement de prix y figure
     * avec l'ancien prix. Le prix à l'instant T est donc l'ancien prix du premier
     * changement postérieur à T, ou le prix actuel s'il n'y en a aucun. Une seule
     * requête pour tout le ticket, et aucune pour une vente « en direct ».
     *
     * @param array<int, Article> $articles
     *
     * @return array<int, int>
     */
    private function prixEnVigueur(array $articles, ?\DateTimeImmutable $venduA): array
    {
        $prix = [];
        foreach ($articles as $id => $article) {
            $prix[$id] = $article->getPrixVenteTtc();
        }

        if (null === $venduA || $venduA >= (new \DateTimeImmutable())->modify('-10 seconds')) {
            return $prix;
        }

        $changements = $this->em->getConnection()->fetchAllAssociative(
            "SELECT entite_id, avant FROM journal_audit
             WHERE action = ? AND entite = 'Article' AND entite_id IN (?) AND created_at > ?
             ORDER BY created_at ASC, id ASC",
            [ActionAudit::PRIX_MODIFIE->value, array_keys($articles), $venduA->format('Y-m-d H:i:s')],
            [ParameterType::STRING, ArrayParameterType::INTEGER, ParameterType::STRING],
        );

        $resolus = [];
        foreach ($changements as $changement) {
            $id = (int) $changement['entite_id'];
            $avant = json_decode((string) $changement['avant'], true);
            // Le prix de cession laisse la même trace : seul le prix de vente compte.
            if (isset($resolus[$id]) || !\is_array($avant) || !\array_key_exists('prixVenteTtc', $avant)) {
                continue;
            }
            $resolus[$id] = true;
            $prix[$id] = (int) $avant['prixVenteTtc'];
        }

        return $prix;
    }

    /**
     * @return array{0: int, 1: ?string} [remise en centimes, motif]
     */
    private function calculerRemise(mixed $remise, int $brutTtc, int $remiseMaxBp): array
    {
        if (null === $remise || [] === $remise) {
            return [0, null];
        }
        if (!\is_array($remise)) {
            throw new EncaissementException('Remise invalide.');
        }

        $type = (string) ($remise['type'] ?? '');
        $valeur = (float) ($remise['valeur'] ?? 0);
        $motif = trim((string) ($remise['motif'] ?? ''));

        $montant = match ($type) {
            'POURCENTAGE' => intdiv($brutTtc * (int) round($valeur * 100), 10000),
            'VALEUR' => (int) round($valeur),
            default => throw new EncaissementException('Type de remise invalide (POURCENTAGE ou VALEUR).'),
        };

        if ($montant < 0 || $montant > $brutTtc) {
            throw new EncaissementException('Montant de remise invalide.');
        }

        $tauxBp = $brutTtc > 0 ? (int) round($montant * 10000 / $brutTtc) : 0;
        if ($tauxBp > $remiseMaxBp) {
            throw new EncaissementException('Remise supérieure au plafond autorisé pour votre rôle.', 403);
        }
        if ($montant > self::SEUIL_MOTIF_REMISE && '' === $motif) {
            throw new EncaissementException('Un motif est obligatoire pour une remise supérieure à 500 FCFA.');
        }

        return [$montant, '' !== $motif ? $motif : null];
    }

    /**
     * Valide le paiement (éventuellement mixte) et calcule le rendu de monnaie.
     * Règles : le paiement électronique ne peut dépasser le net, le total réglé
     * doit le couvrir, et le rendu (excédent) n'est possible qu'en espèces.
     *
     * @return array{0: list<array{mode: ModeReglement, montant: int, reference: ?string}>, 1: int}
     */
    private function preparerReglements(mixed $reglements, int $netTtc): array
    {
        if (!\is_array($reglements) || [] === $reglements) {
            throw new EncaissementException('Aucun règlement fourni.');
        }

        $prepares = [];
        $sommeTotale = 0;
        $sommeElectronique = 0;

        foreach ($reglements as $reglement) {
            $mode = ModeReglement::tryFrom((string) ($reglement['mode'] ?? ''))
                ?? throw new EncaissementException('Mode de règlement invalide.');
            $montant = (int) ($reglement['montant'] ?? 0);
            if ($montant <= 0) {
                throw new EncaissementException('Montant de règlement invalide.');
            }

            $sommeTotale += $montant;
            if (ModeReglement::ESPECES !== $mode) {
                $sommeElectronique += $montant;
            }

            $reference = trim((string) ($reglement['reference'] ?? ''));
            $prepares[] = ['mode' => $mode, 'montant' => $montant, 'reference' => '' !== $reference ? $reference : null];
        }

        if ($sommeElectronique > $netTtc) {
            throw new EncaissementException('Le paiement électronique ne peut pas dépasser le total.');
        }
        if ($sommeTotale < $netTtc) {
            throw new EncaissementException('Paiement insuffisant.');
        }

        return [$prepares, $sommeTotale - $netTtc];
    }

    /**
     * Une vente exige une session de caisse ouverte : le fond de caisse doit avoir
     * été saisi. Aucune création implicite — sinon l'ouverture serait contournable.
     */
    private function sessionOuverte(Utilisateur $utilisateur): SessionCaisse
    {
        return $this->sessions->ouvertePour($utilisateur)
            ?? throw new EncaissementException('Aucune session de caisse ouverte : saisissez votre fond de caisse.', 409);
    }

    private function genererNumero(?\DateTimeImmutable $venduA = null): string
    {
        // Le numéro porte le jour de la vente, pas celui de son arrivée.
        $jour = ($venduA ?? new \DateTimeImmutable())->format('ymd');
        $nombre = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM vente WHERE numero LIKE ?',
            ['V'.$jour.'-%'],
        );

        return \sprintf('V%s-%05d', $jour, $nombre + 1);
    }
}
