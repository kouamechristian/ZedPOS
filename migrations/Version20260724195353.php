<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260724195353 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE fournisseur (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(150) NOT NULL, telephone VARCHAR(30) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, adresse LONGTEXT DEFAULT NULL, actif TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE matiere_premiere ADD fournisseur_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE matiere_premiere ADD CONSTRAINT FK_179505B7670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id)');
        $this->addSql('CREATE INDEX IDX_179505B7670C757F ON matiere_premiere (fournisseur_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE fournisseur');
        $this->addSql('ALTER TABLE matiere_premiere DROP FOREIGN KEY FK_179505B7670C757F');
        $this->addSql('DROP INDEX IDX_179505B7670C757F ON matiere_premiere');
        $this->addSql('ALTER TABLE matiere_premiere DROP fournisseur_id');
    }
}
