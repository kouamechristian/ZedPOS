<?php

namespace App\Service\Magasin;

use App\Entity\Magasin\MagasinEmplacement;
use App\Enum\Magasin\CategorieProduitMagasin;
use Symfony\Component\HttpFoundation\Request;

/**
 * Période et filtres de l'analyse du magasin, lus dans l'adresse.
 *
 * Quatre façons de dire la période — `periode=jour|semaine|mois`, ou `du` / `au`
 * libres — ramenées à deux dates incluses. La semaine part du lundi, le mois du
 * 1er ; les deux s'arrêtent à aujourd'hui. Une plage illisible, inversée, future
 * ou de plus de 366 jours **retombe sur le mois en cours, en le disant**
 * (`avertissement`) : un lien périmé n'immobilise pas un écran de gestion, et on
 * ne laisse pas croire qu'un filtre est appliqué alors qu'il ne l'est pas.
 */
final readonly class FiltreAnalyseMagasin
{
    public const JOURS_MAX = 366;

    private function __construct(
        public \DateTimeImmutable $du,
        public \DateTimeImmutable $au,
        public string $periode,
        public ?CategorieProduitMagasin $categorie,
        public ?MagasinEmplacement $emplacement,
        public ?string $avertissement,
    ) {
    }

    /** @param callable(int): ?MagasinEmplacement $emplacement résout l'identifiant lu dans l'adresse */
    public static function depuis(Request $request, callable $emplacement, ?\DateTimeImmutable $aujourdhui = null): self
    {
        $aujourdhui = ($aujourdhui ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $categorie = CategorieProduitMagasin::tryFrom((string) $request->query->get('categorie'));
        $idEmplacement = (int) (ctype_digit((string) $request->query->get('emplacement')) ? $request->query->get('emplacement') : 0);
        $lieu = $idEmplacement > 0 ? $emplacement($idEmplacement) : null;

        $periode = (string) $request->query->get('periode', '');
        $avertissement = null;
        switch ($periode) {
            case 'jour':
                [$du, $au] = [$aujourdhui, $aujourdhui];
                break;
            case 'semaine':
                [$du, $au] = [$aujourdhui->modify('monday this week'), $aujourdhui];
                break;
            case 'libre':
                $du = self::date((string) $request->query->get('du'));
                $au = self::date((string) $request->query->get('au'));
                if (null === $du || null === $au || $du > $au || $au > $aujourdhui || $du->diff($au)->days >= self::JOURS_MAX) {
                    $avertissement = 'Période illisible, inversée, future ou de plus d\'un an : affichage du mois en cours.';
                    [$periode, $du, $au] = ['mois', $aujourdhui->modify('first day of this month'), $aujourdhui];
                }
                break;
            default:
                [$periode, $du, $au] = ['mois', $aujourdhui->modify('first day of this month'), $aujourdhui];
        }

        return new self($du, $au, $periode, $categorie, $lieu, $avertissement);
    }

    /** Pour la console et les tests. */
    public static function entre(\DateTimeImmutable $du, \DateTimeImmutable $au, ?CategorieProduitMagasin $categorie = null, ?MagasinEmplacement $emplacement = null): self
    {
        return new self($du->setTime(0, 0), $au->setTime(0, 0), 'libre', $categorie, $emplacement, null);
    }

    /** Premier instant de la période. */
    public function debut(): \DateTimeImmutable
    {
        return $this->du;
    }

    /** Premier instant **après** la période : le lendemain du dernier jour, à minuit. */
    public function fin(): \DateTimeImmutable
    {
        return $this->au->modify('+1 day');
    }

    /** Les paramètres d'adresse qui reproduisent ce filtre — pour le PDF et la fiche de stock. */
    public function parametres(): array
    {
        return array_filter([
            'periode' => 'libre',
            'du' => $this->du->format('Y-m-d'),
            'au' => $this->au->format('Y-m-d'),
            'categorie' => $this->categorie?->value,
            'emplacement' => $this->emplacement?->getId(),
        ], static fn ($v): bool => null !== $v);
    }

    /** « du 01/10/2026 au 03/10/2026 », ou « le 03/10/2026 » sur un seul jour. */
    public function libelle(): string
    {
        return $this->du == $this->au
            ? 'le '.$this->du->format('d/m/Y')
            : 'du '.$this->du->format('d/m/Y').' au '.$this->au->format('d/m/Y');
    }

    private static function date(string $texte): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $texte);

        return false !== $date && $date->format('Y-m-d') === $texte ? $date : null;
    }
}
