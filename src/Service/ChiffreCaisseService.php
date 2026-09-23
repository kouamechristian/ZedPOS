<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Identité et chiffre d'une caisse (session) pour les tableaux de bord.
 *
 * Une seule caisse est ouverte à la fois (voir `SessionCaisseService::ouvrir()`) :
 * il n'y a donc pas d'ambiguïté sur « la caisse en cours ». Sans caisse ouverte,
 * on retombe sur la dernière clôturée, signalée comme telle — un tableau de bord
 * vide en dehors des heures d'ouverture n'apprendrait rien.
 *
 * Les ventes annulées sont exclues des montants, comme partout ailleurs.
 */
class ChiffreCaisseService
{
    public function __construct(private readonly Connection $connexion)
    {
    }

    /**
     * La caisse ouverte, à défaut la dernière clôturée ; ou la session demandée.
     */
    public function courant(?int $sessionId = null): ?ChiffreCaisse
    {
        $colonnes = 'SELECT s.id, s.statut, s.fond_caisse, s.ouverture_at, s.cloture_at, u.nom
             FROM session_caisse s JOIN utilisateur u ON u.id = s.utilisateur_id';

        $session = null !== $sessionId
            ? $this->connexion->fetchAssociative($colonnes.' WHERE s.id = ?', [$sessionId])
            : null;

        $session = $session ?: $this->connexion->fetchAssociative(
            $colonnes." WHERE s.statut = 'OUVERTE' ORDER BY s.ouverture_at ASC, s.id ASC LIMIT 1",
        ) ?: $this->connexion->fetchAssociative(
            $colonnes." WHERE s.statut = 'CLOTUREE' ORDER BY s.cloture_at DESC, s.id DESC LIMIT 1",
        );

        if (!$session) {
            return null;
        }

        $ventes = $this->connexion->fetchAssociative(
            "SELECT COUNT(*) AS nombre, COALESCE(SUM(total_ttc), 0) AS ca
             FROM vente WHERE session_caisse_id = ? AND statut = 'VALIDEE'",
            [$session['id']],
        ) ?: [];

        return new ChiffreCaisse(
            sessionId: (int) $session['id'],
            caissier: (string) $session['nom'],
            ouverte: 'OUVERTE' === $session['statut'],
            ouvertureAt: new \DateTimeImmutable($session['ouverture_at']),
            clotureAt: $session['cloture_at'] ? new \DateTimeImmutable($session['cloture_at']) : null,
            fondCaisse: (int) $session['fond_caisse'],
            ca: (int) ($ventes['ca'] ?? 0),
            tickets: (int) ($ventes['nombre'] ?? 0),
        );
    }

    /**
     * Les dernières caisses, la plus récente d'abord — de quoi relire une caisse
     * déjà clôturée sans calendrier.
     *
     * @return list<array{id: int, caissier: string, ouverte: bool, ouvertureAt: \DateTimeImmutable}>
     */
    public function recentes(int $nombre = 15): array
    {
        return array_map(
            static fn (array $l): array => [
                'id' => (int) $l['id'],
                'caissier' => (string) $l['nom'],
                'ouverte' => 'OUVERTE' === $l['statut'],
                'ouvertureAt' => new \DateTimeImmutable($l['ouverture_at']),
            ],
            $this->connexion->fetchAllAssociative(
                'SELECT s.id, s.statut, s.ouverture_at, u.nom
                 FROM session_caisse s JOIN utilisateur u ON u.id = s.utilisateur_id
                 ORDER BY s.ouverture_at DESC, s.id DESC LIMIT '.max(1, $nombre),
            ),
        );
    }
}
