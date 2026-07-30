<?php

namespace App\Enum;

/**
 * Paramètres de l'établissement, stockés dans la table `parametre`.
 *
 * L'énumération est le **catalogue de référence** : elle porte la clé persistée,
 * le libellé du formulaire, une aide de saisie et la valeur par défaut. Ajouter un
 * paramètre = ajouter un cas ici, sans migration — le stockage est en clé/valeur.
 *
 * Ces informations sont imprimées sur les tickets de caisse et les rapports Z.
 */
enum CleParametre: string
{
    case RAISON_SOCIALE = 'boutique.raison_sociale';
    case ENSEIGNE = 'boutique.enseigne';
    /** Nom du fichier déposé, jamais un chemin ni une URL — voir {@see \App\Service\LogoBoutique}. */
    case LOGO = 'boutique.logo';
    case ADRESSE = 'boutique.adresse';
    case VILLE = 'boutique.ville';
    case TELEPHONE = 'boutique.telephone';
    case EMAIL = 'boutique.email';
    case NCC = 'boutique.ncc';
    case RCCM = 'boutique.rccm';
    case PIED_TICKET = 'ticket.pied';

    public function libelle(): string
    {
        return match ($this) {
            self::RAISON_SOCIALE => 'Raison sociale',
            self::ENSEIGNE => 'Enseigne',
            self::LOGO => 'Logo',
            self::ADRESSE => 'Adresse',
            self::VILLE => 'Ville',
            self::TELEPHONE => 'Téléphone',
            self::EMAIL => 'Adresse e-mail',
            self::NCC => 'NCC',
            self::RCCM => 'RCCM',
            self::PIED_TICKET => 'Pied de ticket',
        };
    }

    /** Aide affichée sous le champ, pour éviter les saisies approximatives. */
    public function aide(): string
    {
        return match ($this) {
            self::RAISON_SOCIALE => 'Nom légal de l\'entreprise, tel qu\'il figure sur vos documents officiels.',
            self::ENSEIGNE => 'Nom commercial affiché en tête de ticket, s\'il diffère de la raison sociale.',
            self::LOGO => 'JPEG, PNG ou WebP, 5 Mo au plus. Imprimé en tête de ticket ; l\'image est réduite automatiquement.',
            self::ADRESSE => 'Rue, quartier ou repère.',
            self::VILLE => 'Ville et pays.',
            self::TELEPHONE => 'Numéro imprimé sur le ticket, joignable par les clients.',
            self::EMAIL => 'Facultatif. Imprimé sur le ticket s\'il est renseigné.',
            self::NCC => 'Numéro de Compte Contribuable. Laissez vide si vous n\'en avez pas encore.',
            self::RCCM => 'Registre du Commerce et du Crédit Mobilier. Facultatif.',
            self::PIED_TICKET => 'Phrase de fin de ticket (remerciement, horaires…).',
        };
    }

    public function valeurParDefaut(): string
    {
        return match ($this) {
            self::RAISON_SOCIALE => 'ZedPOS — Boulangerie & Fast-food',
            self::ENSEIGNE => '',
            self::LOGO => '',
            self::ADRESSE => 'Abengourou',
            self::VILLE => "Côte d'Ivoire",
            self::TELEPHONE => '',
            self::EMAIL => '',
            self::NCC => '',
            self::RCCM => '',
            self::PIED_TICKET => 'Merci de votre visite et à bientôt !',
        };
    }

    /** Champ multiligne dans le formulaire d'administration. */
    public function estLong(): bool
    {
        return self::PIED_TICKET === $this;
    }

    /**
     * Paramètre dont la valeur est un **nom de fichier déposé**, et non une saisie.
     *
     * Ces clés sont écartées de la boucle qui engendre les champs texte du
     * formulaire : un logo ne se tape pas au clavier, il se téléverse — et sa
     * valeur ne doit surtout pas être modifiable en texte libre, sinon le nom
     * persisté désignerait un fichier absent du disque.
     */
    public function estFichier(): bool
    {
        return self::LOGO === $this;
    }

    /** Regroupement pour l'écran de saisie. */
    public function groupe(): string
    {
        return match ($this) {
            self::RAISON_SOCIALE, self::ENSEIGNE, self::LOGO, self::ADRESSE, self::VILLE,
            self::TELEPHONE, self::EMAIL => 'Identité de l\'établissement',
            self::NCC, self::RCCM => 'Mentions légales',
            self::PIED_TICKET => 'Ticket de caisse',
        };
    }
}
