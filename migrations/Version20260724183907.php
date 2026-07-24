<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sécurité : ajoute le mot de passe classique et rend le code PIN facultatif
 * (le code PIN ne concerne que les caissiers).
 */
final class Version20260724183907 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'utilisateur : ajout mot_de_passe, code_pin devient nullable.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur ADD mot_de_passe VARCHAR(255) DEFAULT NULL, CHANGE code_pin code_pin VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur DROP mot_de_passe, CHANGE code_pin code_pin VARCHAR(255) NOT NULL');
    }
}
