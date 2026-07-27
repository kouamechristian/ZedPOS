<?php

namespace App\Service\Comptabilite;

use App\Comptabilite\FormatExport;
use App\Comptabilite\JeuEcritures;
use App\Enum\CleParametre;
use App\Service\ParametresBoutique;

/**
 * Met en fichier un {@see JeuEcritures}.
 *
 * Ce service ne calcule rien : il n'interroge pas la base et ne décide d'aucun
 * compte. Il reçoit des écritures déjà arrêtées et les écrit dans l'un des trois
 * formats. La séparation est volontaire — les chiffres affichés à l'écran et les
 * chiffres des fichiers viennent du même objet, donc ne peuvent pas diverger.
 *
 * **Conversion en FCFA à la présentation seulement.** Les montants circulent en
 * centimes jusqu'ici ; la division par 100 se fait dans {@see self::montant()},
 * en arithmétique entière. Aucun float n'est manipulé, même transitoirement.
 */
class ExportComptable
{
    /** Excel sous Windows lit un CSV en ANSI s'il n'y a pas de marque d'ordre. */
    private const BOM_UTF8 = "\u{FEFF}";

    /** RFC 4180 : les enregistrements CSV se terminent par CRLF. */
    private const FIN_LIGNE = "\r\n";

    public function __construct(private readonly ParametresBoutique $parametres)
    {
    }

    public function rendre(JeuEcritures $jeu, FormatExport $format): string
    {
        return match ($format) {
            FormatExport::ECRITURES_CSV => self::BOM_UTF8.$this->ecrituresCsv($jeu),
            FormatExport::FEC => $this->fec($jeu),
            FormatExport::BALANCE_CSV => self::BOM_UTF8.$this->balanceCsv($jeu),
        };
    }

    /**
     * Nom de fichier proposé au téléchargement.
     *
     * Le FEC suit la convention du format : identifiant de l'entreprise, mention
     * « FEC », date de clôture de la période. Le NCC sert d'identifiant lorsqu'il
     * est renseigné dans les paramètres de l'établissement.
     */
    public function nomFichier(JeuEcritures $jeu, FormatExport $format): string
    {
        if (FormatExport::FEC === $format) {
            return \sprintf('%sFEC%s.txt', $this->identifiantEntreprise(), $jeu->au->format('Ymd'));
        }

        return \sprintf(
            'zedpos-%s-%s-%s.%s',
            $format->value,
            $jeu->du->format('Ymd'),
            $jeu->au->format('Ymd'),
            $format->extension(),
        );
    }

    // -- Écritures détaillées (CSV) ------------------------------------------

    private function ecrituresCsv(JeuEcritures $jeu): string
    {
        $lignes = [$this->csv([
            'Journal', 'Libellé journal', 'Date', 'Pièce', 'Compte',
            'Libellé compte', 'Libellé écriture', 'Débit', 'Crédit',
        ])];

        foreach ($jeu->ecritures as $ecriture) {
            foreach ($ecriture->lignes as $ligne) {
                $lignes[] = $this->csv([
                    $ecriture->journal->value,
                    $ecriture->journal->libelle(),
                    $ecriture->date->format('d/m/Y'),
                    $ecriture->piece,
                    $ligne->compte,
                    $ligne->libelleCompte,
                    $ecriture->libelle,
                    $this->montant($ligne->debit),
                    $this->montant($ligne->credit),
                ]);
            }
        }

        return implode(self::FIN_LIGNE, $lignes).self::FIN_LIGNE;
    }

    // -- Fichier des écritures comptables ------------------------------------

    /**
     * Fichier des écritures comptables : 18 colonnes séparées par des tabulations,
     * dans l'ordre imposé par le format.
     *
     * Les colonnes que ZedPOS ne renseigne pas restent **présentes et vides** —
     * un lettrage ou une devise étrangère n'ont pas de sens pour une caisse de
     * boulangerie, mais retirer la colonne ferait échouer l'import.
     */
    private function fec(JeuEcritures $jeu): string
    {
        $lignes = [$this->tabule([
            'JournalCode', 'JournalLib', 'EcritureNum', 'EcritureDate',
            'CompteNum', 'CompteLib', 'CompAuxNum', 'CompAuxLib',
            'PieceRef', 'PieceDate', 'EcritureLib', 'Debit', 'Credit',
            'EcritureLet', 'DateLet', 'ValidDate', 'Montantdevise', 'Idevise',
        ])];

        // Numérotation continue par journal : toutes les lignes d'une écriture
        // partagent le même numéro, c'est ce qui les relie entre elles.
        $compteurs = [];

        foreach ($jeu->ecritures as $ecriture) {
            $code = $ecriture->journal->value;
            $compteurs[$code] = ($compteurs[$code] ?? 0) + 1;
            $numero = \sprintf('%s%05d', $code, $compteurs[$code]);
            $date = $ecriture->date->format('Ymd');

            foreach ($ecriture->lignes as $ligne) {
                $lignes[] = $this->tabule([
                    $code,
                    $ecriture->journal->libelle(),
                    $numero,
                    $date,
                    $ligne->compte,
                    $ligne->libelleCompte,
                    '',
                    '',
                    $ecriture->piece,
                    $date,
                    $ecriture->libelle,
                    $this->montant($ligne->debit),
                    $this->montant($ligne->credit),
                    '',
                    '',
                    $date,
                    '',
                    '',
                ]);
            }
        }

        return implode(self::FIN_LIGNE, $lignes).self::FIN_LIGNE;
    }

    // -- Balance générale ----------------------------------------------------

    private function balanceCsv(JeuEcritures $jeu): string
    {
        $lignes = [
            $this->csv(['Balance générale — '.$this->parametres->valeur(CleParametre::RAISON_SOCIALE)]),
            $this->csv(['Période du '.$jeu->du->format('d/m/Y').' au '.$jeu->au->format('d/m/Y')]),
            '',
            $this->csv(['Compte', 'Libellé', 'Débit', 'Crédit', 'Solde débiteur', 'Solde créditeur']),
        ];

        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($jeu->balance() as $compte) {
            $totalDebit += $compte['debit'];
            $totalCredit += $compte['credit'];

            $lignes[] = $this->csv([
                $compte['compte'],
                $compte['libelle'],
                $this->montant($compte['debit']),
                $this->montant($compte['credit']),
                $this->montant(max(0, $compte['solde'])),
                $this->montant(max(0, -$compte['solde'])),
            ]);
        }

        $lignes[] = $this->csv([
            'TOTAL', '',
            $this->montant($totalDebit),
            $this->montant($totalCredit),
            '', '',
        ]);

        // Pied de contrôle : la balance est un document de lecture, pas un
        // fichier d'import. On y rappelle les rapprochements pour que le
        // comptable n'ait pas à les refaire à la main.
        $lignes[] = '';
        $lignes[] = $this->csv(['Contrôles', 'Attendu', 'Écritures', 'Écart', 'Résultat']);

        foreach ($jeu->controles as $controle) {
            $lignes[] = $this->csv([
                $controle->libelle,
                $this->montant($controle->attendu),
                $this->montant($controle->obtenu),
                $this->montant($controle->ecart()),
                $controle->estBon() ? 'OK' : 'ANOMALIE',
            ]);
        }

        $lignes[] = $this->csv([
            'Équilibre débit / crédit',
            $this->montant($jeu->totalDebit()),
            $this->montant($jeu->totalCredit()),
            $this->montant($jeu->totalDebit() - $jeu->totalCredit()),
            $jeu->estEquilibre() ? 'OK' : 'ANOMALIE',
        ]);

        return implode(self::FIN_LIGNE, $lignes).self::FIN_LIGNE;
    }

    // -- Écriture bas niveau -------------------------------------------------

    /**
     * Montant en FCFA à partir de centimes, séparateur décimal virgule (usage
     * francophone, attendu par les logiciels de comptabilité OHADA).
     */
    private function montant(int $centimes): string
    {
        $signe = $centimes < 0 ? '-' : '';
        $absolu = abs($centimes);

        return \sprintf('%s%d,%02d', $signe, intdiv($absolu, 100), $absolu % 100);
    }

    /** @param list<string> $champs */
    private function csv(array $champs): string
    {
        return implode(';', array_map($this->echapperCsv(...), $champs));
    }

    private function echapperCsv(string $valeur): string
    {
        if (!preg_match('/[";\r\n]/', $valeur)) {
            return $valeur;
        }

        return '"'.str_replace('"', '""', $valeur).'"';
    }

    /**
     * Le FEC n'a pas de mécanisme d'échappement : une tabulation ou un retour à
     * la ligne dans un libellé décalerait toutes les colonnes suivantes. On les
     * remplace donc par une espace, plutôt que de produire un fichier illisible.
     *
     * @param list<string> $champs
     */
    private function tabule(array $champs): string
    {
        return implode("\t", array_map(
            static fn (string $valeur): string => trim((string) preg_replace('/[\t\r\n]+/', ' ', $valeur)),
            $champs,
        ));
    }

    /**
     * Identifiant de l'entreprise dans le nom du fichier FEC : le NCC s'il est
     * renseigné, sinon un repli neutre — un nom de fichier ne doit jamais être
     * vide, même sur une installation qui n'a pas encore saisi ses mentions légales.
     */
    private function identifiantEntreprise(): string
    {
        $ncc = preg_replace('/[^A-Za-z0-9]/', '', $this->parametres->valeur(CleParametre::NCC)) ?? '';

        return '' !== $ncc ? $ncc : 'ZEDPOS';
    }
}
