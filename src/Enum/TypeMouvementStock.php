<?php

namespace App\Enum;

/**
 * Nature d'un mouvement de stock, par emplacement.
 *
 * Remplace depuis le passage au stock par emplacement les anciens types
 * (`ENTREE`, `SORTIE_VENTE`, `SORTIE_PRODUCTION`, `INVENTAIRE`), convertis par
 * la migration : SORTIE_VENTE et l'ENTREE d'annulation d'une vente deviennent
 * VENTE_CAISSE, INVENTAIRE et les autres ENTREE deviennent AJUSTEMENT.
 */
enum TypeMouvementStock: string
{
    /** Dotation du matin : dépôt → stand. */
    case DOTATION = 'DOTATION';
    /** Réapprovisionnement en cours de journée : dépôt → stand. */
    case REAPPRO = 'REAPPRO';
    /** Retour des invendus : stand → dépôt. */
    case RETOUR = 'RETOUR';
    case PERTE = 'PERTE';
    /** Vente encaissée à la caisse (négative), ou son annulation (positive). */
    case VENTE_CAISSE = 'VENTE_CAISSE';
    case VENTE_STAND = 'VENTE_STAND';
    /** Correction d'inventaire, stock de départ, reprise de l'existant. */
    case AJUSTEMENT = 'AJUSTEMENT';

    public function libelle(): string
    {
        return match ($this) {
            self::DOTATION => 'Dotation',
            self::REAPPRO => 'Réapprovisionnement',
            self::RETOUR => 'Retour d\'invendus',
            self::PERTE => 'Perte',
            self::VENTE_CAISSE => 'Vente caisse',
            self::VENTE_STAND => 'Vente stand',
            self::AJUSTEMENT => 'Ajustement',
        };
    }

    /**
     * Ce mouvement peut-il laisser un stock négatif ?
     *
     * **La caisse seule.** Une vente n'est jamais bloquée pour une question de
     * stock (règle de CLAUDE.md) : la farine a été consommée, que la base le
     * sache ou non, et refuser l'encaissement ferait perdre la vente — hors ligne,
     * la file de synchronisation la marquerait « à vérifier ». Tout le reste est
     * refusé : on ne dote pas un stand de pains qu'on n'a pas.
     */
    public function tolereStockNegatif(): bool
    {
        return self::VENTE_CAISSE === $this;
    }

    /**
     * Mouvement qui déplace du stock d'un emplacement à un autre : il ne s'écrit
     * que par paire, via `StockManager::transferer()`. Seul, il ferait apparaître
     * ou disparaître de la marchandise.
     */
    public function estTransfert(): bool
    {
        return null !== $this->sens();
    }

    /**
     * Sens imposé d'un transfert.
     *
     * @return array{0: TypeEmplacement, 1: TypeEmplacement}|null [source, destination]
     */
    public function sens(): ?array
    {
        return match ($this) {
            self::DOTATION, self::REAPPRO => [TypeEmplacement::DEPOT, TypeEmplacement::STAND],
            self::RETOUR => [TypeEmplacement::STAND, TypeEmplacement::DEPOT],
            default => null,
        };
    }
}
