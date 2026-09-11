<?php

namespace App\Service\Rapport;

use App\Enum\GranulariteRapport;

/**
 * Filtre commun à tous les rapports des stands : une plage de dates et un pas
 * d'agrégation.
 *
 * Une saisie invalide ne casse pas l'écran : elle retombe sur le mois en cours, et
 * l'erreur est dite ({@see self::$erreur}). Plage bornée à {@see self::JOURS_MAX}
 * jours — un rapport journalier sur trois ans ferait mille lignes et un graphique
 * illisible.
 */
final readonly class FiltreRapport
{
    public const JOURS_MAX = 366;

    public const AUJOURDHUI = 'aujourdhui';
    public const SEPT_JOURS = '7jours';
    public const MOIS_EN_COURS = 'mois';
    public const MOIS_PRECEDENT = 'mois-precedent';

    private function __construct(
        public \DateTimeImmutable $du,
        public \DateTimeImmutable $au,
        public GranulariteRapport $granularite,
        public ?string $erreur = null,
    ) {
    }

    public static function creer(\DateTimeImmutable $du, \DateTimeImmutable $au, GranulariteRapport $granularite): self
    {
        $du = $du->setTime(0, 0);
        $au = $au->setTime(0, 0);

        if ($au < $du) {
            throw new \DomainException('La fin de la période précède son début.');
        }
        if ((int) $du->diff($au)->days + 1 > self::JOURS_MAX) {
            throw new \DomainException(\sprintf('Période trop longue : %d jours au plus.', self::JOURS_MAX));
        }

        return new self($du, $au, $granularite);
    }

    /**
     * Lit `du`, `au`, `granularite` et le raccourci `periode` dans la requête.
     *
     * @param array<string, mixed> $parametres
     */
    public static function depuis(array $parametres, \DateTimeImmutable $aujourdhui): self
    {
        $aujourdhui = $aujourdhui->setTime(0, 0);
        $granularite = GranulariteRapport::tryFrom((string) ($parametres['granularite'] ?? '')) ?? GranulariteRapport::JOUR;
        $raccourcis = self::raccourcis($aujourdhui);

        $periode = (string) ($parametres['periode'] ?? '');
        if (isset($raccourcis[$periode])) {
            [$du, $au] = $raccourcis[$periode];

            return new self($du, $au, $granularite);
        }

        $du = self::date($parametres['du'] ?? null);
        $au = self::date($parametres['au'] ?? null);
        [$defautDu, $defautAu] = $raccourcis[self::MOIS_EN_COURS];

        if (null === $du && null === $au && !isset($parametres['du']) && !isset($parametres['au'])) {
            return new self($defautDu, $defautAu, $granularite);
        }

        try {
            return self::creer(
                $du ?? throw new \DomainException('Date de début illisible.'),
                $au ?? throw new \DomainException('Date de fin illisible.'),
                $granularite,
            );
        } catch (\DomainException $e) {
            return new self($defautDu, $defautAu, $granularite, $e->getMessage().' Mois en cours affiché.');
        }
    }

    /** @return array<string, array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> */
    public static function raccourcis(\DateTimeImmutable $aujourdhui): array
    {
        $aujourdhui = $aujourdhui->setTime(0, 0);
        $debutMois = $aujourdhui->modify('first day of this month');

        return [
            self::AUJOURDHUI => [$aujourdhui, $aujourdhui],
            self::SEPT_JOURS => [$aujourdhui->modify('-6 days'), $aujourdhui],
            self::MOIS_EN_COURS => [$debutMois, $aujourdhui],
            self::MOIS_PRECEDENT => [$debutMois->modify('-1 month'), $debutMois->modify('-1 day')],
        ];
    }

    /** @return array<string, string> les libellés des raccourcis */
    public static function libellesRaccourcis(): array
    {
        return [
            self::AUJOURDHUI => 'Aujourd\'hui',
            self::SEPT_JOURS => '7 derniers jours',
            self::MOIS_EN_COURS => 'Mois en cours',
            self::MOIS_PRECEDENT => 'Mois précédent',
        ];
    }

    /** Le raccourci qui correspond exactement à la plage, s'il y en a un. */
    public function raccourci(\DateTimeImmutable $aujourdhui): ?string
    {
        foreach (self::raccourcis($aujourdhui) as $cle => [$du, $au]) {
            if ($du == $this->du && $au == $this->au) {
                return $cle;
            }
        }

        return null;
    }

    /**
     * Tranches de la plage, dans l'ordre : clé (premier jour) → libellé. La première
     * et la dernière peuvent déborder la plage (semaine, mois) : leur libellé reste
     * celui de la tranche entière, les chiffres ne portent que sur la plage.
     *
     * @return array<string, string>
     */
    public function tranches(): array
    {
        $tranches = [];
        for ($debut = $this->granularite->debutTranche($this->du); $debut <= $this->au; $debut = $this->granularite->suivante($debut)) {
            $tranches[$debut->format('Y-m-d')] = $this->granularite->libelleTranche($debut);
        }

        return $tranches;
    }

    public function cle(\DateTimeImmutable|string $jour): string
    {
        return $this->granularite->cle(\is_string($jour) ? new \DateTimeImmutable($jour) : $jour);
    }

    /** @return array{du: string, au: string, granularite: string} pour les liens */
    public function parametres(): array
    {
        return ['du' => $this->du->format('Y-m-d'), 'au' => $this->au->format('Y-m-d'), 'granularite' => $this->granularite->value];
    }

    /** `stands_2026-09-01_2026-09-30_jour` : le fichier dit ce qu'il contient. */
    public function nomFichier(string $rapport): string
    {
        return \sprintf('rapport-%s_%s_%s_%s.csv', $rapport, $this->du->format('Y-m-d'), $this->au->format('Y-m-d'), $this->granularite->value);
    }

    private static function date(mixed $valeur): ?\DateTimeImmutable
    {
        return \is_string($valeur) ? (\DateTimeImmutable::createFromFormat('!Y-m-d', $valeur) ?: null) : null;
    }
}
