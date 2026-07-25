<?php

namespace App\Service;

/**
 * Prépare une commande ESC/POS (texte brut + coupe papier + ouverture tiroir) à
 * partir d'un {@see TicketData}, destinée à un futur pont d'impression thermique local.
 *
 * Le texte est translittéré en ASCII (les imprimantes thermiques gèrent mal l'UTF-8)
 * et mis en page sur une largeur de 48 colonnes (papier 80 mm, police A).
 */
class ImpressionService
{
    private const ESC = "\x1B";
    private const GS = "\x1D";
    private const LARGEUR = 48;

    public function commandeEscPos(TicketData $ticket): string
    {
        $sortie = self::ESC.'@'; // initialisation

        // En-tête centré.
        $sortie .= $this->centrer().$this->gras(true).$this->grand(true);
        $sortie .= $this->ligneTexte($ticket->raisonSociale);
        $sortie .= $this->grand(false).$this->gras(false);
        $sortie .= $this->ligneTexte($ticket->adresse);
        if ('' !== $ticket->ncc) {
            $sortie .= $this->ligneTexte('NCC : '.$ticket->ncc);
        }
        if ('' !== $ticket->telephone) {
            $sortie .= $this->ligneTexte('Tel : '.$ticket->telephone);
        }

        // Métadonnées, alignées à gauche.
        $sortie .= $this->gauche().$this->separateur();
        $sortie .= $this->ligneTexte('Ticket : '.$ticket->numero);
        $sortie .= $this->ligneTexte('Date   : '.$ticket->dateHeure->format('d/m/Y H:i'));
        $sortie .= $this->ligneTexte('Caisse : '.$ticket->caissier);
        $sortie .= $this->separateur();

        // Lignes d'articles.
        foreach ($ticket->lignes as $ligne) {
            $quantite = $this->formatQuantite($ligne['quantiteMillimes']);
            $sortie .= $this->deuxColonnes($quantite.' x '.$ligne['nom'], $this->fcfa($ligne['montant']));
            if (null !== $ligne['commentaire'] && '' !== $ligne['commentaire']) {
                $sortie .= $this->ligneTexte('  '.$ligne['commentaire']);
            }
        }

        $sortie .= $this->separateur();
        if ($ticket->remise > 0) {
            $sortie .= $this->deuxColonnes('Remise', '-'.$this->fcfa($ticket->remise));
        }
        $sortie .= $this->gras(true).$this->grand(true);
        $sortie .= $this->deuxColonnes('TOTAL', $this->fcfa($ticket->totalTtc), 24);
        $sortie .= $this->grand(false).$this->gras(false);

        // Ventilation de TVA.
        foreach ($ticket->ventilationTva as $tva) {
            $taux = number_format($tva['tauxBp'] / 100, ($tva['tauxBp'] % 100) ? 2 : 0, ',', ' ');
            $sortie .= $this->deuxColonnes(
                'TVA '.$taux.'% (base '.$this->fcfa($tva['base']).')',
                $this->fcfa($tva['montant']),
            );
        }

        // Règlements et rendu.
        $sortie .= $this->separateur();
        foreach ($ticket->reglements as $reglement) {
            $sortie .= $this->deuxColonnes($reglement['libelle'], $this->fcfa($reglement['montant']));
        }
        if ($ticket->rendu > 0) {
            $sortie .= $this->deuxColonnes('Rendu', $this->fcfa($ticket->rendu));
        }

        // Emplacement réservé au futur QR code RNE/DGI.
        $sortie .= "\n".$this->centrer().$this->ligneTexte('[ QR RNE/DGI a venir ]');

        // Pied de page.
        if ('' !== $ticket->pied) {
            $sortie .= "\n".$this->ligneTexte($ticket->pied);
        }
        $sortie .= $this->gauche();

        // Avance papier, coupe, ouverture du tiroir-caisse.
        $sortie .= "\n\n\n";
        $sortie .= self::GS.'V'.\chr(0);              // coupe complète
        $sortie .= self::ESC.'p'.\chr(0).\chr(25).\chr(250); // impulsion tiroir

        return $sortie;
    }

    private function centrer(): string
    {
        return self::ESC.'a'.\chr(1);
    }

    private function gauche(): string
    {
        return self::ESC.'a'.\chr(0);
    }

    private function gras(bool $actif): string
    {
        return self::ESC.'E'.\chr($actif ? 1 : 0);
    }

    private function grand(bool $actif): string
    {
        return self::GS.'!'.\chr($actif ? 0x11 : 0x00); // double hauteur + largeur
    }

    private function separateur(): string
    {
        return str_repeat('-', self::LARGEUR)."\n";
    }

    private function ligneTexte(string $texte): string
    {
        return $this->ascii($texte)."\n";
    }

    private function deuxColonnes(string $gauche, string $droite, int $largeur = self::LARGEUR): string
    {
        $gauche = $this->ascii($gauche);
        $droite = $this->ascii($droite);
        $place = max(1, $largeur - \strlen($droite));
        if (\strlen($gauche) > $place - 1) {
            $gauche = substr($gauche, 0, $place - 1);
        }

        return str_pad($gauche, $place).$droite."\n";
    }

    private function formatQuantite(int $millimes): string
    {
        return rtrim(rtrim(number_format($millimes / 1000, 3, '.', ''), '0'), '.');
    }

    private function fcfa(int $centimes): string
    {
        return number_format($centimes / 100, 0, ',', ' ').' F';
    }

    /**
     * Translittère l'UTF-8 en ASCII pour l'impression thermique.
     */
    private function ascii(string $texte): string
    {
        $remplacements = [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ç' => 'c', 'ñ' => 'n',
            'À' => 'A', 'Â' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E',
            'Î' => 'I', 'Ô' => 'O', 'Û' => 'U', 'Ç' => 'C',
            '’' => "'", '€' => 'E', '–' => '-', '—' => '-',
            "\u{202f}" => ' ', "\u{00a0}" => ' ',
        ];

        return strtr($texte, $remplacements);
    }
}
