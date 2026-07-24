<?php

namespace App\Service;

use App\Entity\LigneVente;
use App\Entity\Reglement;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\ModeReglement;
use App\Enum\ModeVente;
use App\Enum\StatutSessionCaisse;
use App\Enum\StatutVente;
use App\Repository\ArticleRepository;
use App\Repository\SessionCaisseRepository;
use App\Repository\VenteRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VenteRepository $ventes,
        private readonly ArticleRepository $articles,
        private readonly SessionCaisseRepository $sessions,
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

        [$preparees, $brutHt, $brutTva, $brutTtc] = $this->preparerLignes($donnees['lignes'] ?? []);
        [$remise, $motifRemise] = $this->calculerRemise($donnees['remise'] ?? null, $brutTtc, $remiseMaxBp);

        $netTtc = $brutTtc - $remise;
        $remiseTva = $brutTtc > 0 ? intdiv($remise * $brutTva, $brutTtc) : 0;
        $netTva = $brutTva - $remiseTva;
        $netHt = $netTtc - $netTva;

        [$reglements, $rendu] = $this->preparerReglements($donnees['reglements'] ?? [], $netTtc);

        try {
            $vente = $this->em->wrapInTransaction(function () use (
                $utilisateur, $mode, $uuid, $netHt, $netTva, $netTtc, $remise, $motifRemise, $rendu, $preparees, $reglements
            ): Vente {
                $vente = new Vente($this->sessionOuverte($utilisateur), $mode, $this->genererNumero(), $netHt, $netTva, $netTtc, $uuid);
                $vente->enregistrerRemiseEtRendu($remise, $motifRemise, $rendu);

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

        return new ResultatEncaissement($vente, $rendu, false);
    }

    /**
     * Annule une vente (jamais de suppression). Réservée au gérant (contrôlé au contrôleur).
     */
    public function annuler(Uuid $uuid, string $motif): Vente
    {
        $vente = $this->ventes->findOneBy(['uuid' => $uuid])
            ?? throw new EncaissementException('Vente introuvable.', 404);

        if ('' === trim($motif)) {
            throw new EncaissementException("Le motif d'annulation est obligatoire.");
        }
        if (StatutVente::ANNULEE === $vente->getStatut()) {
            throw new EncaissementException('Cette vente est déjà annulée.', 409);
        }

        $vente->annuler(trim($motif));
        $this->em->flush();

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
     * @param mixed $lignes
     *
     * @return array{0: list<array{article: \App\Entity\Article, quantiteMillimes: int, prix: int, commentaire: ?string}>, 1: int, 2: int, 3: int}
     */
    private function preparerLignes(mixed $lignes): array
    {
        if (!\is_array($lignes) || [] === $lignes) {
            throw new EncaissementException('Le ticket est vide.');
        }

        $preparees = [];
        $brutTtc = 0;
        $brutTva = 0;

        foreach ($lignes as $ligne) {
            $article = $this->articles->find((int) ($ligne['articleId'] ?? 0));
            if (null === $article || !$article->isActif()) {
                throw new EncaissementException('Article indisponible.');
            }

            $quantite = max(1, (int) ($ligne['quantite'] ?? 1));
            $prix = $article->getPrixVenteTtc();
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

    private function sessionOuverte(Utilisateur $utilisateur): SessionCaisse
    {
        $session = $this->sessions->findOneBy(
            ['utilisateur' => $utilisateur, 'statut' => StatutSessionCaisse::OUVERTE],
            ['ouvertureAt' => 'DESC'],
        );

        if (null === $session) {
            $session = new SessionCaisse($utilisateur, 0);
            $this->em->persist($session);
        }

        return $session;
    }

    private function genererNumero(): string
    {
        $jour = (new \DateTimeImmutable())->format('ymd');
        $nombre = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM vente WHERE numero LIKE ?',
            ['V'.$jour.'-%'],
        );

        return \sprintf('V%s-%05d', $jour, $nombre + 1);
    }
}
