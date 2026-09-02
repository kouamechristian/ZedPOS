<?php

namespace App\Service;

/**
 * Prépare une commande ESC/POS (texte brut + coupe papier + ouverture tiroir) à
 * partir d'un {@see TicketData}, destinée à un futur pont d'impression thermique local.
 *
 * Le texte est translittéré en ASCII (les imprimantes thermiques gèrent mal l'UTF-8)
 * et mis en page sur une largeur de 32 colonnes (papier 58 mm, police A).
 */
class ImpressionService
{
    private const ESC = "\x1B";
    private const GS = "\x1D";
    /**
     * Colonnes disponibles en police A sur un papier de 58 mm : la tête imprime
     * 384 points à 203 dpi, et un caractère de la police A en occupe 12.
     *
     * Cette valeur suit le papier, pas le goût : une ligne mise en page sur une
     * largeur plus grande que la tête est repliée par l'imprimante au caractère
     * près, et le montant aligné à droite se retrouve seul sur la ligne suivante.
     */
    private const LARGEUR = 32;

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
        // Moitié de la largeur : le total est en double largeur, chaque caractère
        // y occupe deux colonnes.
        $sortie .= $this->deuxColonnes('TOTAL', $this->fcfa($ticket->totalTtc), intdiv(self::LARGEUR, 2));
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

        // Code-barres du numéro de ticket, dessiné par l'imprimante elle-même.
        $sortie .= "\n".$this->centrer().$this->codeBarres($ticket->numero);

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

    /**
     * Code-barres Code 128 (jeu B) du numéro de ticket.
     *
     * On envoie la **chaîne**, pas une image : le firmware trace le symbole à la
     * résolution native de la tête d'impression. Une trame calculée ici serait
     * rééchantillonnée, et des barres d'un point de large finiraient par se
     * confondre — le code cesserait d'être lisible sans que rien ne le montre.
     *
     * Le préfixe `{B` sélectionne le jeu B dans les données, comme le fait
     * {@see CodeBarres128} pour le rendu HTML : les deux supports encodent donc
     * la même chose de la même façon.
     */
    private function codeBarres(string $valeur): string
    {
        // Hors du jeu B, l'imprimante rendrait un symbole faux plutôt que rien :
        // mieux vaut se contenter du numéro en clair.
        if (1 !== preg_match('/^[\x20-\x7E]+$/', $valeur)) {
            return $this->ligneTexte($valeur);
        }

        $donnees = '{B'.$valeur;

        // Deux points par module, sur les 384 que compte la tête en 58 mm. Un
        // numéro de ticket (13 caractères) donne un symbole de 178 modules, soit
        // 356 points : il tient, mais de justesse — le blanc qui reste de part et
        // d'autre, prolongé par les 5 mm de papier que la tête ne couvre pas,
        // fait office de zone de silence. Un numéro plus long déborderait, et
        // l'imprimante tronquerait le code sans rien signaler.
        //
        // Descendre à un point par module rendrait de la place mais beaucoup de
        // firmwares refusent cette valeur, et 0,125 mm de module passe sous ce
        // qu'un lecteur du commerce sait relire.
        return self::GS.'h'.\chr(60)                                   // hauteur, en points
            .self::GS.'w'.\chr(2)                                      // largeur d'un module
            .self::GS.'H'.\chr(2)                                      // numéro en clair, sous le code
            .self::GS.'k'.\chr(73).\chr(\strlen($donnees)).$donnees
            ."\n";
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
