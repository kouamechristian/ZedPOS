<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notifications de la dirigeante : rattachement à la vente concernée, pour que le
 * pilotage affiche « avant → après » au lieu d'une phrase.
 *
 * Les notifications existantes sont rattachées d'après leur lien
 * (`/pilotage/ventes/{uuid}`) : l'uuid est stocké en BINARY(16), il est remis en
 * forme canonique par HEX/SUBSTR — fonctions communes à MariaDB 10.4 et MySQL 9.
 */
final class Version20260915140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification : notification.vente_id (clé étrangère vers vente), rattachement de l\'existant.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD vente_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA7DC7170A FOREIGN KEY (vente_id) REFERENCES vente (id)');
        $this->addSql('CREATE INDEX IDX_BF5476CA7DC7170A ON notification (vente_id)');

        $this->addSql(<<<'SQL'
            UPDATE notification n
            INNER JOIN vente v ON n.lien = CONCAT('/pilotage/ventes/', LOWER(CONCAT_WS('-',
                SUBSTR(HEX(v.uuid), 1, 8),
                SUBSTR(HEX(v.uuid), 9, 4),
                SUBSTR(HEX(v.uuid), 13, 4),
                SUBSTR(HEX(v.uuid), 17, 4),
                SUBSTR(HEX(v.uuid), 21, 12)
            )))
            SET n.vente_id = v.id
            WHERE n.vente_id IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA7DC7170A');
        $this->addSql('DROP INDEX IDX_BF5476CA7DC7170A ON notification');
        $this->addSql('ALTER TABLE notification DROP vente_id');
    }
}
