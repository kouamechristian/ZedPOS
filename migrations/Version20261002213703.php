<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Magasin, phase 2 : réceptions fournisseurs et leurs lignes
 * (réception → contrôle → inspection → stockage). Tables propres au module.
 */
final class Version20261002213703 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Magasin : réceptions fournisseurs (magasin_reception, magasin_ligne_reception)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE magasin_ligne_reception (id INT AUTO_INCREMENT NOT NULL, unite_saisie VARCHAR(30) NOT NULL, contenance BIGINT NOT NULL, qte_annoncee BIGINT NOT NULL, qte_comptee BIGINT DEFAULT NULL, qte_acceptee BIGINT DEFAULT NULL, qte_rejetee BIGINT DEFAULT NULL, motif_rejet VARCHAR(20) DEFAULT NULL, prix_unitaire INT DEFAULT NULL, reception_id INT NOT NULL, produit_id INT NOT NULL, emplacement_id INT DEFAULT NULL, INDEX IDX_38FB7C77C14DF52 (reception_id), INDEX IDX_38FB7C7F347EFB (produit_id), INDEX IDX_38FB7C7C4598A51 (emplacement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE magasin_reception (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(20) NOT NULL, bon_livraison VARCHAR(50) DEFAULT NULL, date_reception DATE NOT NULL, statut VARCHAR(20) NOT NULL, commentaire VARCHAR(255) DEFAULT NULL, commentaire_controle VARCHAR(255) DEFAULT NULL, controlee_at DATETIME DEFAULT NULL, inspectee_at DATETIME DEFAULT NULL, stockee_at DATETIME DEFAULT NULL, annulee_at DATETIME DEFAULT NULL, motif_annulation VARCHAR(255) DEFAULT NULL, statut_avant_annulation VARCHAR(20) DEFAULT NULL, created_at DATETIME NOT NULL, fournisseur_id INT NOT NULL, recue_par_id INT NOT NULL, controlee_par_id INT DEFAULT NULL, inspectee_par_id INT DEFAULT NULL, stockee_par_id INT DEFAULT NULL, annulee_par_id INT DEFAULT NULL, INDEX IDX_B9D78AB8670C757F (fournisseur_id), INDEX IDX_B9D78AB8A813CA1F (recue_par_id), INDEX IDX_B9D78AB8BB2D75ED (controlee_par_id), INDEX IDX_B9D78AB8A1776520 (inspectee_par_id), INDEX IDX_B9D78AB82B2BD1EF (stockee_par_id), INDEX IDX_B9D78AB81D95B04C (annulee_par_id), UNIQUE INDEX uniq_magasin_reception_numero (numero), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE magasin_ligne_reception ADD CONSTRAINT FK_38FB7C77C14DF52 FOREIGN KEY (reception_id) REFERENCES magasin_reception (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE magasin_ligne_reception ADD CONSTRAINT FK_38FB7C7F347EFB FOREIGN KEY (produit_id) REFERENCES magasin_produit (id)');
        $this->addSql('ALTER TABLE magasin_ligne_reception ADD CONSTRAINT FK_38FB7C7C4598A51 FOREIGN KEY (emplacement_id) REFERENCES magasin_emplacement (id)');
        $this->addSql('ALTER TABLE magasin_reception ADD CONSTRAINT FK_B9D78AB8670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id)');
        $this->addSql('ALTER TABLE magasin_reception ADD CONSTRAINT FK_B9D78AB8A813CA1F FOREIGN KEY (recue_par_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE magasin_reception ADD CONSTRAINT FK_B9D78AB8BB2D75ED FOREIGN KEY (controlee_par_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE magasin_reception ADD CONSTRAINT FK_B9D78AB8A1776520 FOREIGN KEY (inspectee_par_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE magasin_reception ADD CONSTRAINT FK_B9D78AB82B2BD1EF FOREIGN KEY (stockee_par_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE magasin_reception ADD CONSTRAINT FK_B9D78AB81D95B04C FOREIGN KEY (annulee_par_id) REFERENCES utilisateur (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magasin_ligne_reception DROP FOREIGN KEY FK_38FB7C77C14DF52');
        $this->addSql('ALTER TABLE magasin_ligne_reception DROP FOREIGN KEY FK_38FB7C7F347EFB');
        $this->addSql('ALTER TABLE magasin_ligne_reception DROP FOREIGN KEY FK_38FB7C7C4598A51');
        $this->addSql('ALTER TABLE magasin_reception DROP FOREIGN KEY FK_B9D78AB8670C757F');
        $this->addSql('ALTER TABLE magasin_reception DROP FOREIGN KEY FK_B9D78AB8A813CA1F');
        $this->addSql('ALTER TABLE magasin_reception DROP FOREIGN KEY FK_B9D78AB8BB2D75ED');
        $this->addSql('ALTER TABLE magasin_reception DROP FOREIGN KEY FK_B9D78AB8A1776520');
        $this->addSql('ALTER TABLE magasin_reception DROP FOREIGN KEY FK_B9D78AB82B2BD1EF');
        $this->addSql('ALTER TABLE magasin_reception DROP FOREIGN KEY FK_B9D78AB81D95B04C');
        $this->addSql('DROP TABLE magasin_ligne_reception');
        $this->addSql('DROP TABLE magasin_reception');
    }
}
