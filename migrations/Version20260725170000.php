<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Colonne `famille_produit.compte_vente` : compte de produits SYSCOHADA sur
 * lequel ventiler le chiffre d'affaires de la famille dans les exports comptables.
 *
 * Nullable et sans valeur initiale : tant qu'elle n'est pas renseignée, l'export
 * déduit le compte de la nature de l'article (produit fini fabriqué sur place ou
 * marchandise revendue en l'état). L'installation existante n'a donc rien à saisir.
 */
final class Version20260725170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compte de vente SYSCOHADA paramétrable par famille de produits';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE famille_produit ADD compte_vente VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE famille_produit DROP compte_vente');
    }
}
