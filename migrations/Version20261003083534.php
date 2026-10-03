<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Magasin, phase 5 : inventaires du magasin et leurs lignes
 * (en cours → validé ou abandonné). Tables propres au module.
 */
final class Version20261003083534 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Magasin : inventaires (magasin_inventaire, magasin_ligne_inventaire)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE magasin_inventaire (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(20) NOT NULL, statut VARCHAR(20) NOT NULL, commentaire VARCHAR(255) DEFAULT NULL, valide_at DATETIME DEFAULT NULL, abandonne_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, emplacement_id INT DEFAULT NULL, ouvert_par_id INT NOT NULL, valide_par_id INT DEFAULT NULL, abandonne_par_id INT DEFAULT NULL, INDEX IDX_5D0B2708C4598A51 (emplacement_id), INDEX IDX_5D0B27086FD322E7 (ouvert_par_id), INDEX IDX_5D0B27086AF12ED9 (valide_par_id), INDEX IDX_5D0B27087DCA5074 (abandonne_par_id), UNIQUE INDEX uniq_magasin_inventaire_numero (numero), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE magasin_ligne_inventaire (id INT AUTO_INCREMENT NOT NULL, qte_theorique BIGINT NOT NULL, cout_unitaire INT NOT NULL, unite_achat VARCHAR(30) DEFAULT NULL, contenance BIGINT NOT NULL, qte_comptee BIGINT DEFAULT NULL, qte_saisie BIGINT DEFAULT NULL, en_unite_achat TINYINT NOT NULL, inventaire_id INT NOT NULL, produit_id INT NOT NULL, emplacement_id INT NOT NULL, INDEX IDX_9D0B1398CE430A85 (inventaire_id), INDEX IDX_9D0B1398F347EFB (produit_id), INDEX IDX_9D0B1398C4598A51 (emplacement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE magasin_inventaire ADD CONSTRAINT FK_5D0B2708C4598A51 FOREIGN KEY (emplacement_id) REFERENCES magasin_emplacement (id)');
        $this->addSql('ALTER TABLE magasin_inventaire ADD CONSTRAINT FK_5D0B27086FD322E7 FOREIGN KEY (ouvert_par_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE magasin_inventaire ADD CONSTRAINT FK_5D0B27086AF12ED9 FOREIGN KEY (valide_par_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE magasin_inventaire ADD CONSTRAINT FK_5D0B27087DCA5074 FOREIGN KEY (abandonne_par_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE magasin_ligne_inventaire ADD CONSTRAINT FK_9D0B1398CE430A85 FOREIGN KEY (inventaire_id) REFERENCES magasin_inventaire (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE magasin_ligne_inventaire ADD CONSTRAINT FK_9D0B1398F347EFB FOREIGN KEY (produit_id) REFERENCES magasin_produit (id)');
        $this->addSql('ALTER TABLE magasin_ligne_inventaire ADD CONSTRAINT FK_9D0B1398C4598A51 FOREIGN KEY (emplacement_id) REFERENCES magasin_emplacement (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magasin_inventaire DROP FOREIGN KEY FK_5D0B2708C4598A51');
        $this->addSql('ALTER TABLE magasin_inventaire DROP FOREIGN KEY FK_5D0B27086FD322E7');
        $this->addSql('ALTER TABLE magasin_inventaire DROP FOREIGN KEY FK_5D0B27086AF12ED9');
        $this->addSql('ALTER TABLE magasin_inventaire DROP FOREIGN KEY FK_5D0B27087DCA5074');
        $this->addSql('ALTER TABLE magasin_ligne_inventaire DROP FOREIGN KEY FK_9D0B1398CE430A85');
        $this->addSql('ALTER TABLE magasin_ligne_inventaire DROP FOREIGN KEY FK_9D0B1398F347EFB');
        $this->addSql('ALTER TABLE magasin_ligne_inventaire DROP FOREIGN KEY FK_9D0B1398C4598A51');
        $this->addSql('DROP TABLE magasin_inventaire');
        $this->addSql('DROP TABLE magasin_ligne_inventaire');
    }
}
