<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Table `parametre` : informations de l'établissement (raison sociale, adresse,
 * NCC, pied de ticket…), auparavant figées dans les variables d'environnement
 * TICKET_*. Elles se saisissent désormais dans le back-office.
 *
 * Stockage en clé/valeur : ajouter un paramètre ne demandera pas de migration.
 * Aucune donnée initiale n'est insérée — l'énumération App\Enum\CleParametre
 * fournit les valeurs par défaut tant que la table est vide.
 */
final class Version20260725155152 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Table parametre : informations de l'établissement";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE parametre (id INT AUTO_INCREMENT NOT NULL, cle VARCHAR(100) NOT NULL, valeur LONGTEXT NOT NULL, modifie_a DATETIME NOT NULL, UNIQUE INDEX uniq_parametre_cle (cle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE parametre');
    }
}
