<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reprise des restes : ce qui restait en vitrine à la clôture d'une caisse est
 * repris dans la fiche de production de la suivante (`qte_reprise`).
 *
 * Les fiches existantes partent à zéro de reprise : la règle vaut pour les
 * caisses ouvertes à partir de maintenant.
 */
final class Version20260930215048 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fiche de production : reprise des restes de la caisse précédente (ligne_fiche_production.qte_reprise).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ligne_fiche_production ADD qte_reprise BIGINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ligne_fiche_production DROP qte_reprise');
    }
}
