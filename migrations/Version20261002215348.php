<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Magasin, phase 3 : sorties du magasin et leurs lignes
 * (brouillon → validée → annulée). Tables propres au module.
 */
final class Version20261002215348 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Magasin : sorties (magasin_sortie, magasin_ligne_sortie)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE magasin_ligne_sortie (id INT AUTO_INCREMENT NOT NULL, en_unite_achat TINYINT NOT NULL, unite_saisie VARCHAR(30) NOT NULL, contenance BIGINT NOT NULL, qte_saisie BIGINT NOT NULL, quantite BIGINT NOT NULL, cout_unitaire INT DEFAULT NULL, sortie_id INT NOT NULL, produit_id INT NOT NULL, emplacement_id INT NOT NULL, INDEX IDX_1226CD71CC72D953 (sortie_id), INDEX IDX_1226CD71F347EFB (produit_id), INDEX IDX_1226CD71C4598A51 (emplacement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE magasin_sortie (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(20) NOT NULL, date_sortie DATE NOT NULL, destination VARCHAR(20) NOT NULL, motif VARCHAR(20) NOT NULL, demande_par VARCHAR(100) DEFAULT NULL, commentaire VARCHAR(255) DEFAULT NULL, statut VARCHAR(20) NOT NULL, validee_at DATETIME DEFAULT NULL, annulee_at DATETIME DEFAULT NULL, motif_annulation VARCHAR(255) DEFAULT NULL, statut_avant_annulation VARCHAR(20) DEFAULT NULL, created_at DATETIME NOT NULL, cree_par_id INT NOT NULL, servie_par_id INT DEFAULT NULL, annulee_par_id INT DEFAULT NULL, INDEX IDX_84D619F3FC29C013 (cree_par_id), INDEX IDX_84D619F3AFB2744A (servie_par_id), INDEX IDX_84D619F31D95B04C (annulee_par_id), UNIQUE INDEX uniq_magasin_sortie_numero (numero), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE magasin_ligne_sortie ADD CONSTRAINT FK_1226CD71CC72D953 FOREIGN KEY (sortie_id) REFERENCES magasin_sortie (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE magasin_ligne_sortie ADD CONSTRAINT FK_1226CD71F347EFB FOREIGN KEY (produit_id) REFERENCES magasin_produit (id)');
        $this->addSql('ALTER TABLE magasin_ligne_sortie ADD CONSTRAINT FK_1226CD71C4598A51 FOREIGN KEY (emplacement_id) REFERENCES magasin_emplacement (id)');
        $this->addSql('ALTER TABLE magasin_sortie ADD CONSTRAINT FK_84D619F3FC29C013 FOREIGN KEY (cree_par_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE magasin_sortie ADD CONSTRAINT FK_84D619F3AFB2744A FOREIGN KEY (servie_par_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE magasin_sortie ADD CONSTRAINT FK_84D619F31D95B04C FOREIGN KEY (annulee_par_id) REFERENCES utilisateur (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magasin_ligne_sortie DROP FOREIGN KEY FK_1226CD71CC72D953');
        $this->addSql('ALTER TABLE magasin_ligne_sortie DROP FOREIGN KEY FK_1226CD71F347EFB');
        $this->addSql('ALTER TABLE magasin_ligne_sortie DROP FOREIGN KEY FK_1226CD71C4598A51');
        $this->addSql('ALTER TABLE magasin_sortie DROP FOREIGN KEY FK_84D619F3FC29C013');
        $this->addSql('ALTER TABLE magasin_sortie DROP FOREIGN KEY FK_84D619F3AFB2744A');
        $this->addSql('ALTER TABLE magasin_sortie DROP FOREIGN KEY FK_84D619F31D95B04C');
        $this->addSql('DROP TABLE magasin_ligne_sortie');
        $this->addSql('DROP TABLE magasin_sortie');
    }
}
