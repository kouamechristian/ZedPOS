<?php

namespace App\Service\Point;

use App\Enum\GranulariteArrete;

/**
 * Règles de dates d'un arrêté, sans base de données.
 *
 * **La date de début ne se saisit jamais** : elle est fixée par l'historique du
 * stand ({@see \App\Service\ArreteService::debutPeriode()}). La gérante ne choisit
 * que la fin, par trois raccourcis ou une saisie libre, et c'est ce qui garantit
 * qu'aucune journée n'est arrêtée deux fois ni oubliée.
 *
 * Semaine du lundi au dimanche. Toutes les dates sont ramenées à minuit.
 */
final class PeriodeArrete
{
    public const AUJOURDHUI = 'aujourdhui';
    public const FIN_SEMAINE = 'semaine';
    public const FIN_MOIS = 'mois';

    /** Dimanche de la semaine du jour donné. */
    public static function finDeSemaine(\DateTimeImmutable $jour): \DateTimeImmutable
    {
        $jour = $jour->setTime(0, 0);

        return $jour->modify('+'.(7 - (int) $jour->format('N')).' days');
    }

    public static function finDeMois(\DateTimeImmutable $jour): \DateTimeImmutable
    {
        return $jour->setTime(0, 0)->modify('last day of this month');
    }

    /**
     * Les trois raccourcis de fin. Un raccourci qui tomberait avant le début ou
     * après aujourd'hui vaut `null` — le bouton est alors grisé : on n'arrête pas
     * une journée qui n'a pas encore eu lieu.
     *
     * « Fin de semaine » et « fin de mois » partent du **début** de la période : le
     * point suivant reprendra au lendemain, sans trou.
     *
     * @return array{aujourdhui: ?\DateTimeImmutable, semaine: ?\DateTimeImmutable, mois: ?\DateTimeImmutable}
     */
    public static function raccourcis(\DateTimeImmutable $debut, \DateTimeImmutable $aujourdhui): array
    {
        $debut = $debut->setTime(0, 0);
        $aujourdhui = $aujourdhui->setTime(0, 0);
        $garder = static fn (\DateTimeImmutable $fin): ?\DateTimeImmutable => $fin >= $debut && $fin <= $aujourdhui ? $fin : null;

        return [
            self::AUJOURDHUI => $garder($aujourdhui),
            self::FIN_SEMAINE => $garder(self::finDeSemaine($debut)),
            self::FIN_MOIS => $garder(self::finDeMois($debut)),
        ];
    }

    /** @throws \DomainException fin avant le début, ou dans le futur */
    public static function verifierFin(\DateTimeImmutable $debut, \DateTimeImmutable $fin, \DateTimeImmutable $aujourdhui): void
    {
        if ($fin->setTime(0, 0) < $debut->setTime(0, 0)) {
            throw new \DomainException(\sprintf('La période commence le %s : elle ne peut pas finir avant.', $debut->format('d/m/Y')));
        }
        if ($fin->setTime(0, 0) > $aujourdhui->setTime(0, 0)) {
            throw new \DomainException('On n\'arrête pas une journée qui n\'a pas encore eu lieu.');
        }
    }

    /** Déduite des dates, jamais déclarée : aucun libellé ne peut mentir sur la période. */
    public static function granularite(\DateTimeImmutable $debut, \DateTimeImmutable $fin): GranulariteArrete
    {
        $debut = $debut->setTime(0, 0);
        $fin = $fin->setTime(0, 0);

        return match (true) {
            $debut == $fin => GranulariteArrete::JOUR,
            $fin == self::finDeSemaine($debut) && $fin->diff($debut)->days < 7 => GranulariteArrete::SEMAINE,
            $fin == self::finDeMois($debut) => GranulariteArrete::MOIS,
            default => GranulariteArrete::LIBRE,
        };
    }
}
