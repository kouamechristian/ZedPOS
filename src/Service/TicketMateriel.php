<?php

namespace App\Service;

use App\Entity\Vente;
use App\Enum\ModeReglement;

/**
 * Traduit une vente en charge utile pour la route `/print` de l'agent matériel
 * local (voir `assets/js/pos-agent.js`).
 *
 * Le format attendu par l'agent est volontairement plat — `header[]`, `lines[]`,
 * `total`, `paid`, `change`, `footer[]`, `openDrawer` — et il n'est pas celui du
 * ticket 58 mm rendu en HTML. Ce service est le seul point de traduction entre
 * les deux : il part du {@see TicketData} produit par {@see TicketBuilder}, donc
 * de la même source que la page imprimable et que la sortie ESC/POS. Ce que
 * l'agent imprime ne peut pas diverger de ce que la caissière a sous les yeux.
 *
 * ⚠ **Les montants sortent d'ici en FCFA entiers**, alors qu'ils circulent en
 * centimes partout ailleurs dans l'application. L'agent les imprime tels quels,
 * sans les interpréter : c'est donc une conversion de présentation, au même titre
 * que le formatage Twig, et elle n'a lieu qu'ici. Aucun flottant n'apparaît —
 * {@see self::fcfa()} est une division entière.
 */
class TicketMateriel
{
    public function __construct(private readonly TicketBuilder $builder)
    {
    }

    /**
     * @param bool $ouvrirTiroir intention de l'appelant. Une réimpression passe
     *                           `false` : le ticket ressort, mais le tiroir n'a
     *                           aucune raison de s'ouvrir une seconde fois — il
     *                           l'a déjà fait quand l'argent est entré.
     *
     * @return array<string, mixed>
     */
    public function pour(Vente $vente, bool $ouvrirTiroir = true): array
    {
        $ticket = $this->builder->construire($vente);

        return [
            'header' => $this->entete($ticket),
            'lines' => $this->lignes($ticket),
            'total' => $this->fcfa($ticket->totalTtc),
            // Ce que le client a réellement tendu, et non le total : c'est de
            // l'écart entre les deux que sort la monnaie, et le ticket doit dire
            // la même chose que le tiroir.
            'paid' => $this->fcfa($this->regle($ticket)),
            'change' => $this->fcfa($ticket->rendu),
            'footer' => $this->pied($ticket),
            // Le tiroir ne s'ouvre que s'il y a des espèces à y mettre : sur un
            // règlement Wave ou MTN, personne ne touche au tiroir, et l'ouvrir
            // pour rien le laisse béant devant la file.
            'openDrawer' => $ouvrirTiroir && $this->comporteDesEspeces($vente),
        ];
    }

    /**
     * En-tête : identité de la boutique, puis les repères de la vente. Même ordre
     * que le ticket papier et que la sortie ESC/POS — une caissière qui compare
     * les deux doit retrouver ses lignes au même endroit.
     *
     * Les mentions vides sont écartées plutôt qu'imprimées à blanc : sur 58 mm de
     * papier, une ligne vide est une ligne perdue.
     *
     * @return list<string>
     */
    private function entete(TicketData $ticket): array
    {
        return $this->sansVide([
            $ticket->raisonSociale,
            $ticket->adresse,
            '' !== $ticket->telephone ? 'Tél : '.$ticket->telephone : '',
            $ticket->email,
            '' !== $ticket->ncc ? 'NCC : '.$ticket->ncc : '',
            '' !== $ticket->rccm ? 'RCCM : '.$ticket->rccm : '',
            'Ticket : '.$ticket->numero,
            'Date : '.$ticket->dateHeure->format('d/m/Y H:i'),
            'Caisse : '.$ticket->caissier,
        ]);
    }

    /**
     * Une entrée par ligne de vente, plus la remise s'il y en a une.
     *
     * La remise voyage en ligne négative faute de champ dédié dans le format de
     * l'agent : sans elle, la somme des lignes ne tomberait pas sur le total et
     * le client aurait sous les yeux un ticket qui ne s'additionne pas.
     *
     * @return list<array{label: string, qty: string, price: int}>
     */
    private function lignes(TicketData $ticket): array
    {
        $lignes = [];

        foreach ($ticket->lignes as $ligne) {
            $libelle = $ligne['nom'];
            // Le commentaire d'une commande fast-food (« sans oignon ») fait
            // partie de ce que le client doit relire : il tient sur la même
            // ligne, l'agent ne sachant pas rendre de sous-ligne.
            if (null !== $ligne['commentaire'] && '' !== $ligne['commentaire']) {
                $libelle .= ' ('.$ligne['commentaire'].')';
            }

            $lignes[] = [
                'label' => $libelle,
                'qty' => $this->quantite($ligne['quantiteMillimes']),
                'price' => $this->fcfa($ligne['montant']),
            ];
        }

        if ($ticket->remise > 0) {
            $lignes[] = [
                'label' => 'Remise',
                'qty' => '1',
                'price' => -$this->fcfa($ticket->remise),
            ];
        }

        return $lignes;
    }

    /**
     * Pied : ventilation de TVA, règlements, puis la phrase de fin paramétrée
     * dans `/admin/parametres`.
     *
     * Le détail des règlements descend ici parce que le format de l'agent n'offre
     * qu'un seul champ `paid` : sur un paiement mixte, il dirait « 5 000 » sans
     * jamais dire d'où ils viennent.
     *
     * @return list<string>
     */
    private function pied(TicketData $ticket): array
    {
        $lignes = [];

        foreach ($ticket->ventilationTva as $tva) {
            $taux = number_format($tva['tauxBp'] / 100, ($tva['tauxBp'] % 100) ? 2 : 0, ',', ' ');
            $lignes[] = \sprintf(
                'TVA %s%% : %d FCFA (base %d)',
                $taux,
                $this->fcfa($tva['montant']),
                $this->fcfa($tva['base']),
            );
        }

        foreach ($ticket->reglements as $reglement) {
            $lignes[] = \sprintf('%s : %d FCFA', $reglement['libelle'], $this->fcfa($reglement['montant']));
        }

        $lignes[] = $ticket->pied;

        return $this->sansVide($lignes);
    }

    /** Total réellement remis par le client, en centimes. */
    private function regle(TicketData $ticket): int
    {
        return array_sum(array_column($ticket->reglements, 'montant'));
    }

    /**
     * Y a-t-il des espèces dans cette vente ? Lu sur les règlements de l'entité,
     * et non sur leur libellé : le libellé est de l'affichage, il peut changer.
     */
    private function comporteDesEspeces(Vente $vente): bool
    {
        foreach ($vente->getReglements() as $reglement) {
            if (ModeReglement::ESPECES === $reglement->getMode()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Centimes → FCFA entiers.
     *
     * Division **entière** : le franc CFA ne circule pas en centimes, et un
     * flottant n'a rien à faire sur un ticket. Les montants stockés sont de toute
     * façon des multiples de 100, la troncature ne perd donc rien.
     */
    private function fcfa(int $centimes): int
    {
        return intdiv($centimes, 100);
    }

    /**
     * Quantité en millièmes → texte lisible (« 2 », « 0.5 »).
     *
     * Chaîne et non nombre : une quantité au kilo se lit « 0.5 », et un flottant
     * transmis en JSON ressortirait « 0.5000000001 » sur le papier.
     * Même mise en forme que {@see ImpressionService}, pour que les deux sorties
     * thermiques restent identiques.
     */
    private function quantite(int $millimes): string
    {
        return rtrim(rtrim(number_format($millimes / 1000, 3, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * @param list<string> $lignes
     *
     * @return list<string>
     */
    private function sansVide(array $lignes): array
    {
        return array_values(array_filter($lignes, static fn (string $ligne) => '' !== trim($ligne)));
    }
}
