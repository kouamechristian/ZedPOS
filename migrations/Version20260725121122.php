<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notifications destinées à la dirigeante (annulations de ventes encaissées).
 */
final class Version20260725121122 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Table notification (alertes de l'espace de pilotage)";
    }

    public function up(Schema $schema): void
    {

        $this->addSql('CREATE TABLE notification (id INT AUTO_INCREMENT NOT NULL, role_destinataire VARCHAR(50) NOT NULL, type VARCHAR(50) NOT NULL, titre VARCHAR(255) NOT NULL, message LONGTEXT NOT NULL, lien VARCHAR(255) DEFAULT NULL, lu_a DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_notification_destinataire (role_destinataire, lu_a), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {

        $this->addSql('DROP TABLE notification');
    }
}
