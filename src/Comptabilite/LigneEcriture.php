<?php

namespace App\Comptabilite;

/**
 * Une ligne d'écriture : un compte, un sens, un montant.
 *
 * Immuable. Le montant est **en centimes de FCFA** et toujours positif : en
 * comptabilité on ne débite pas d'un montant négatif, on crédite. La conversion
 * en FCFA n'a lieu qu'à l'écriture du fichier.
 */
final class LigneEcriture
{
    private function __construct(
        public readonly string $compte,
        public readonly string $libelleCompte,
        public readonly int $debit,
        public readonly int $credit,
    ) {
    }

    public static function debit(PlanComptable|string $compte, int $montant): self
    {
        return self::creer($compte, $montant, 0);
    }

    public static function credit(PlanComptable|string $compte, int $montant): self
    {
        return self::creer($compte, 0, $montant);
    }

    /**
     * Ligne dont le sens découle du signe : débit si le montant est positif,
     * crédit s'il est négatif.
     *
     * Utile pour les postes dont le signe n'est pas connu d'avance (écart de
     * caisse, espèces nettes du rendu de monnaie) : plutôt que d'écrêter un
     * montant négatif — ce qui déséquilibrerait l'écriture — on l'inscrit de
     * l'autre côté, ce qui est exactement ce que dit la comptabilité.
     */
    public static function signee(PlanComptable|string $compte, int $montant): self
    {
        return $montant >= 0 ? self::debit($compte, $montant) : self::credit($compte, -$montant);
    }

    private static function creer(PlanComptable|string $compte, int $debit, int $credit): self
    {
        if ($debit < 0 || $credit < 0) {
            throw new \DomainException('Une ligne d\'écriture ne peut pas porter un montant négatif : inverser le sens.');
        }

        $code = $compte instanceof PlanComptable ? $compte->value : $compte;

        return new self($code, PlanComptable::libellePour($code), $debit, $credit);
    }
}
