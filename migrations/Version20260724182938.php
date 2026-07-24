<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schéma initial du domaine ZedPOS : référentiel, ventes, stock et sécurité.
 */
final class Version20260724182938 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schéma initial ZedPOS (référentiel, ventes, stock, sécurité, audit).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE article (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(150) NOT NULL, prix_vente_ttc INT NOT NULL, unite VARCHAR(20) NOT NULL, taux_tva SMALLINT DEFAULT 0 NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, couleur VARCHAR(7) DEFAULT NULL, position_caisse SMALLINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, famille_produit_id INT DEFAULT NULL, INDEX IDX_23A0E66FBC0E351 (famille_produit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE famille_produit (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(100) NOT NULL, couleur VARCHAR(7) DEFAULT NULL, position SMALLINT DEFAULT 0 NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE fiche_technique (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, article_id INT NOT NULL, UNIQUE INDEX UNIQ_505525A97294869C (article_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE journal_audit (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(100) NOT NULL, entite VARCHAR(100) NOT NULL, entite_id INT DEFAULT NULL, avant JSON DEFAULT NULL, apres JSON DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL, utilisateur_id INT DEFAULT NULL, INDEX IDX_71C3CC53FB88E14F (utilisateur_id), INDEX idx_journal_audit_created_at (created_at), INDEX idx_journal_audit_entite (entite, entite_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ligne_fiche_technique (id INT AUTO_INCREMENT NOT NULL, quantite BIGINT NOT NULL, pourcentage_perte SMALLINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, fiche_technique_id INT NOT NULL, matiere_premiere_id INT NOT NULL, INDEX IDX_5BB444BA431AD613 (fiche_technique_id), INDEX IDX_5BB444BA5B42BE3C (matiere_premiere_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ligne_vente (id INT AUTO_INCREMENT NOT NULL, quantite INT NOT NULL, prix_unitaire INT NOT NULL, remise INT DEFAULT 0 NOT NULL, commentaire LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, vente_id INT NOT NULL, article_id INT NOT NULL, INDEX IDX_8B26C07C7DC7170A (vente_id), INDEX IDX_8B26C07C7294869C (article_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE matiere_premiere (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(150) NOT NULL, unite_stock VARCHAR(20) NOT NULL, stock_actuel BIGINT DEFAULT 0 NOT NULL, stock_mini BIGINT DEFAULT 0 NOT NULL, cout_moyen_pondere INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mouvement_stock (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(30) NOT NULL, quantite BIGINT NOT NULL, motif VARCHAR(255) DEFAULT NULL, source_type VARCHAR(50) DEFAULT NULL, source_id INT DEFAULT NULL, created_at DATETIME NOT NULL, matiere_premiere_id INT DEFAULT NULL, article_id INT DEFAULT NULL, INDEX IDX_61E2C8EB5B42BE3C (matiere_premiere_id), INDEX IDX_61E2C8EB7294869C (article_id), INDEX idx_mouvement_stock_created_at (created_at), INDEX idx_mouvement_stock_source (source_type, source_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE perte (id INT AUTO_INCREMENT NOT NULL, motif VARCHAR(30) NOT NULL, quantite BIGINT NOT NULL, valorisation INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, matiere_premiere_id INT DEFAULT NULL, article_id INT DEFAULT NULL, INDEX IDX_12F685F85B42BE3C (matiere_premiere_id), INDEX IDX_12F685F87294869C (article_id), INDEX idx_perte_created_at (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reglement (id INT AUTO_INCREMENT NOT NULL, mode VARCHAR(20) NOT NULL, montant INT NOT NULL, reference VARCHAR(100) DEFAULT NULL, created_at DATETIME NOT NULL, vente_id INT NOT NULL, INDEX IDX_EBE4C14C7DC7170A (vente_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE session_caisse (id INT AUTO_INCREMENT NOT NULL, fond_caisse INT NOT NULL, ouverture_at DATETIME NOT NULL, cloture_at DATETIME DEFAULT NULL, statut VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, utilisateur_id INT NOT NULL, INDEX IDX_DDC85991FB88E14F (utilisateur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE utilisateur (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, code_pin VARCHAR(255) NOT NULL, nom VARCHAR(120) NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_utilisateur_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE vente (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, numero VARCHAR(30) NOT NULL, mode VARCHAR(20) NOT NULL, total_ht INT NOT NULL, total_tva INT NOT NULL, total_ttc INT NOT NULL, statut VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, session_caisse_id INT NOT NULL, INDEX IDX_888A2A4C6456BBB5 (session_caisse_id), INDEX idx_vente_created_at (created_at), UNIQUE INDEX uniq_vente_uuid (uuid), UNIQUE INDEX uniq_vente_numero (numero), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE article ADD CONSTRAINT FK_23A0E66FBC0E351 FOREIGN KEY (famille_produit_id) REFERENCES famille_produit (id)');
        $this->addSql('ALTER TABLE fiche_technique ADD CONSTRAINT FK_505525A97294869C FOREIGN KEY (article_id) REFERENCES article (id)');
        $this->addSql('ALTER TABLE journal_audit ADD CONSTRAINT FK_71C3CC53FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE ligne_fiche_technique ADD CONSTRAINT FK_5BB444BA431AD613 FOREIGN KEY (fiche_technique_id) REFERENCES fiche_technique (id)');
        $this->addSql('ALTER TABLE ligne_fiche_technique ADD CONSTRAINT FK_5BB444BA5B42BE3C FOREIGN KEY (matiere_premiere_id) REFERENCES matiere_premiere (id)');
        $this->addSql('ALTER TABLE ligne_vente ADD CONSTRAINT FK_8B26C07C7DC7170A FOREIGN KEY (vente_id) REFERENCES vente (id)');
        $this->addSql('ALTER TABLE ligne_vente ADD CONSTRAINT FK_8B26C07C7294869C FOREIGN KEY (article_id) REFERENCES article (id)');
        $this->addSql('ALTER TABLE mouvement_stock ADD CONSTRAINT FK_61E2C8EB5B42BE3C FOREIGN KEY (matiere_premiere_id) REFERENCES matiere_premiere (id)');
        $this->addSql('ALTER TABLE mouvement_stock ADD CONSTRAINT FK_61E2C8EB7294869C FOREIGN KEY (article_id) REFERENCES article (id)');
        $this->addSql('ALTER TABLE perte ADD CONSTRAINT FK_12F685F85B42BE3C FOREIGN KEY (matiere_premiere_id) REFERENCES matiere_premiere (id)');
        $this->addSql('ALTER TABLE perte ADD CONSTRAINT FK_12F685F87294869C FOREIGN KEY (article_id) REFERENCES article (id)');
        $this->addSql('ALTER TABLE reglement ADD CONSTRAINT FK_EBE4C14C7DC7170A FOREIGN KEY (vente_id) REFERENCES vente (id)');
        $this->addSql('ALTER TABLE session_caisse ADD CONSTRAINT FK_DDC85991FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE vente ADD CONSTRAINT FK_888A2A4C6456BBB5 FOREIGN KEY (session_caisse_id) REFERENCES session_caisse (id)');
    }

    public function down(Schema $schema): void
    {
        // Suppression dans un ordre respectant les clés étrangères (enfants d'abord).
        $this->addSql('ALTER TABLE article DROP FOREIGN KEY FK_23A0E66FBC0E351');
        $this->addSql('ALTER TABLE fiche_technique DROP FOREIGN KEY FK_505525A97294869C');
        $this->addSql('ALTER TABLE journal_audit DROP FOREIGN KEY FK_71C3CC53FB88E14F');
        $this->addSql('ALTER TABLE ligne_fiche_technique DROP FOREIGN KEY FK_5BB444BA431AD613');
        $this->addSql('ALTER TABLE ligne_fiche_technique DROP FOREIGN KEY FK_5BB444BA5B42BE3C');
        $this->addSql('ALTER TABLE ligne_vente DROP FOREIGN KEY FK_8B26C07C7DC7170A');
        $this->addSql('ALTER TABLE ligne_vente DROP FOREIGN KEY FK_8B26C07C7294869C');
        $this->addSql('ALTER TABLE mouvement_stock DROP FOREIGN KEY FK_61E2C8EB5B42BE3C');
        $this->addSql('ALTER TABLE mouvement_stock DROP FOREIGN KEY FK_61E2C8EB7294869C');
        $this->addSql('ALTER TABLE perte DROP FOREIGN KEY FK_12F685F85B42BE3C');
        $this->addSql('ALTER TABLE perte DROP FOREIGN KEY FK_12F685F87294869C');
        $this->addSql('ALTER TABLE reglement DROP FOREIGN KEY FK_EBE4C14C7DC7170A');
        $this->addSql('ALTER TABLE session_caisse DROP FOREIGN KEY FK_DDC85991FB88E14F');
        $this->addSql('ALTER TABLE vente DROP FOREIGN KEY FK_888A2A4C6456BBB5');
        $this->addSql('DROP TABLE ligne_fiche_technique');
        $this->addSql('DROP TABLE ligne_vente');
        $this->addSql('DROP TABLE reglement');
        $this->addSql('DROP TABLE mouvement_stock');
        $this->addSql('DROP TABLE perte');
        $this->addSql('DROP TABLE journal_audit');
        $this->addSql('DROP TABLE fiche_technique');
        $this->addSql('DROP TABLE vente');
        $this->addSql('DROP TABLE session_caisse');
        $this->addSql('DROP TABLE article');
        $this->addSql('DROP TABLE matiere_premiere');
        $this->addSql('DROP TABLE famille_produit');
        $this->addSql('DROP TABLE utilisateur');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
