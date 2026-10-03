<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Magasin, phase 1 : référentiel (produits, emplacements), stock par
 * produit × emplacement et mouvements immuables. Tables propres au module
 * (préfixe magasin_), sans lien avec le stock de la boutique.
 *
 * Crée la zone par défaut « Réserve principale » (code RESERVE). Les lignes JSON
 * `journal_audit` / `utilisateur` du diff, artefact d'introspection MariaDB
 * 10.4, sont retirées.
 */
final class Version20261002211753 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Magasin : magasin_produit, magasin_emplacement (Réserve principale), magasin_stock, magasin_mouvement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE magasin_emplacement (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(20) NOT NULL, libelle VARCHAR(100) NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_magasin_emplacement_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE magasin_mouvement (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(30) NOT NULL, quantite BIGINT NOT NULL, document_type VARCHAR(30) NOT NULL, document_id INT NOT NULL, motif VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, produit_id INT NOT NULL, emplacement_id INT NOT NULL, auteur_id INT DEFAULT NULL, INDEX IDX_B250F3A9F347EFB (produit_id), INDEX IDX_B250F3A9C4598A51 (emplacement_id), INDEX IDX_B250F3A960BB6FE6 (auteur_id), INDEX idx_magasin_mouvement_date (created_at), INDEX idx_magasin_mouvement_document (document_type, document_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE magasin_produit (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(150) NOT NULL, categorie VARCHAR(20) NOT NULL, unite_stock VARCHAR(20) NOT NULL, unite_achat VARCHAR(30) DEFAULT NULL, contenance_achat BIGINT DEFAULT NULL, seuil_alerte BIGINT DEFAULT 0 NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, cout_moyen INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, fournisseur_habituel_id INT DEFAULT NULL, INDEX IDX_5E1A357B9F8E1010 (fournisseur_habituel_id), UNIQUE INDEX uniq_magasin_produit_nom (nom), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE magasin_stock (id INT AUTO_INCREMENT NOT NULL, quantite BIGINT NOT NULL, modifie_a DATETIME NOT NULL, produit_id INT NOT NULL, emplacement_id INT NOT NULL, INDEX IDX_1F595857F347EFB (produit_id), INDEX IDX_1F595857C4598A51 (emplacement_id), UNIQUE INDEX uniq_magasin_stock_couple (produit_id, emplacement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE magasin_mouvement ADD CONSTRAINT FK_B250F3A9F347EFB FOREIGN KEY (produit_id) REFERENCES magasin_produit (id)');
        $this->addSql('ALTER TABLE magasin_mouvement ADD CONSTRAINT FK_B250F3A9C4598A51 FOREIGN KEY (emplacement_id) REFERENCES magasin_emplacement (id)');
        $this->addSql('ALTER TABLE magasin_mouvement ADD CONSTRAINT FK_B250F3A960BB6FE6 FOREIGN KEY (auteur_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE magasin_produit ADD CONSTRAINT FK_5E1A357B9F8E1010 FOREIGN KEY (fournisseur_habituel_id) REFERENCES fournisseur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE magasin_stock ADD CONSTRAINT FK_1F595857F347EFB FOREIGN KEY (produit_id) REFERENCES magasin_produit (id)');
        $this->addSql('ALTER TABLE magasin_stock ADD CONSTRAINT FK_1F595857C4598A51 FOREIGN KEY (emplacement_id) REFERENCES magasin_emplacement (id)');
        $this->addSql("INSERT INTO magasin_emplacement (code, libelle, actif, created_at) VALUES ('RESERVE', 'Réserve principale', 1, NOW())");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magasin_mouvement DROP FOREIGN KEY FK_B250F3A9F347EFB');
        $this->addSql('ALTER TABLE magasin_mouvement DROP FOREIGN KEY FK_B250F3A9C4598A51');
        $this->addSql('ALTER TABLE magasin_mouvement DROP FOREIGN KEY FK_B250F3A960BB6FE6');
        $this->addSql('ALTER TABLE magasin_produit DROP FOREIGN KEY FK_5E1A357B9F8E1010');
        $this->addSql('ALTER TABLE magasin_stock DROP FOREIGN KEY FK_1F595857F347EFB');
        $this->addSql('ALTER TABLE magasin_stock DROP FOREIGN KEY FK_1F595857C4598A51');
        $this->addSql('DROP TABLE magasin_mouvement');
        $this->addSql('DROP TABLE magasin_stock');
        $this->addSql('DROP TABLE magasin_produit');
        $this->addSql('DROP TABLE magasin_emplacement');
    }
}
