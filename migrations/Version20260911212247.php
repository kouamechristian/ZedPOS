<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rapports des stands : la part de chaque ligne de dotation dans le point qui l'a
 * soldée (vendu, retourné, perdu, montant, rémunération).
 *
 * Colonnes nulles tant qu'aucun point validé n'a arrêté la ligne. Les points validés
 * avant cette migration se reportent par `php bin/console app:stands:figer-ventes`
 * — le calcul est en PHP, il n'a pas sa place dans une migration SQL.
 */
final class Version20260911212247 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rapports des stands : ligne_dotation.qte_vendue, qte_retournee, qte_perdue, montant_vendu, remuneration.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ligne_dotation ADD qte_vendue BIGINT DEFAULT NULL, ADD qte_retournee BIGINT DEFAULT NULL, ADD qte_perdue BIGINT DEFAULT NULL, ADD montant_vendu INT DEFAULT NULL, ADD remuneration INT DEFAULT NULL');
    }

    /** Sans perte : ces colonnes se recalculent depuis les points validés. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ligne_dotation DROP qte_vendue, DROP qte_retournee, DROP qte_perdue, DROP montant_vendu, DROP remuneration');
    }
}
