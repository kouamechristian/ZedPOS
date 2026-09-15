<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Modification du ticket en caisse : une vente peut en remplacer une autre, qui
 * reste en base, annulée.
 *
 * Index **unique** : un ticket n'est remplacé qu'une fois, même par deux requêtes
 * simultanées. Colonne nulle pour tout l'historique.
 */
final class Version20260915100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Modification de ticket : vente.vente_remplacee_id (unique, clé étrangère vers vente).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vente ADD vente_remplacee_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE vente ADD CONSTRAINT FK_888A2A4C49A49E9A FOREIGN KEY (vente_remplacee_id) REFERENCES vente (id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_vente_remplacee ON vente (vente_remplacee_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vente DROP FOREIGN KEY FK_888A2A4C49A49E9A');
        $this->addSql('DROP INDEX uniq_vente_remplacee ON vente');
        $this->addSql('ALTER TABLE vente DROP vente_remplacee_id');
    }
}
