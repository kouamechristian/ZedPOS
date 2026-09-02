<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260809172453 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE article (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(150) NOT NULL, prix_vente_ttc INT NOT NULL, unite VARCHAR(20) NOT NULL, taux_tva SMALLINT DEFAULT 0 NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, couleur VARCHAR(7) DEFAULT NULL, image VARCHAR(120) DEFAULT NULL, position_caisse SMALLINT DEFAULT 0 NOT NULL, suivi_stock TINYINT DEFAULT 0 NOT NULL, stock_actuel BIGINT DEFAULT 0 NOT NULL, stock_mini BIGINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, famille_produit_id INT DEFAULT NULL, INDEX IDX_23A0E66FBC0E351 (famille_produit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE famille_produit (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(100) NOT NULL, couleur VARCHAR(7) DEFAULT NULL, position SMALLINT DEFAULT 0 NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, compte_vente VARCHAR(10) DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE fiche_technique (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, article_id INT NOT NULL, UNIQUE INDEX UNIQ_505525A97294869C (article_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE fournisseur (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(150) NOT NULL, telephone VARCHAR(30) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, adresse LONGTEXT DEFAULT NULL, actif TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE inventaire (id INT AUTO_INCREMENT NOT NULL, statut VARCHAR(20) NOT NULL, valide_at DATETIME DEFAULT NULL, commentaire LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, auteur_id INT NOT NULL, valide_par_id INT DEFAULT NULL, INDEX IDX_338920E060BB6FE6 (auteur_id), INDEX IDX_338920E06AF12ED9 (valide_par_id), INDEX idx_inventaire_created_at (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE journal_audit (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(100) NOT NULL, entite VARCHAR(100) NOT NULL, entite_id INT DEFAULT NULL, avant JSON DEFAULT NULL, apres JSON DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL, utilisateur_id INT DEFAULT NULL, INDEX IDX_71C3CC53FB88E14F (utilisateur_id), INDEX idx_journal_audit_created_at (created_at), INDEX idx_journal_audit_entite (entite, entite_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ligne_fiche_technique (id INT AUTO_INCREMENT NOT NULL, quantite BIGINT NOT NULL, pourcentage_perte SMALLINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, fiche_technique_id INT NOT NULL, matiere_premiere_id INT NOT NULL, INDEX IDX_5BB444BA431AD613 (fiche_technique_id), INDEX IDX_5BB444BA5B42BE3C (matiere_premiere_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ligne_inventaire (id INT AUTO_INCREMENT NOT NULL, libelle VARCHAR(180) NOT NULL, unite VARCHAR(30) NOT NULL, quantite_theorique BIGINT NOT NULL, quantite_comptee BIGINT DEFAULT NULL, cout_unitaire INT NOT NULL, inventaire_id INT NOT NULL, matiere_premiere_id INT DEFAULT NULL, article_id INT DEFAULT NULL, INDEX IDX_D025CEFDCE430A85 (inventaire_id), INDEX IDX_D025CEFD5B42BE3C (matiere_premiere_id), INDEX IDX_D025CEFD7294869C (article_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ligne_vente (id INT AUTO_INCREMENT NOT NULL, quantite INT NOT NULL, prix_unitaire INT NOT NULL, remise INT DEFAULT 0 NOT NULL, commentaire LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, vente_id INT NOT NULL, article_id INT NOT NULL, INDEX IDX_8B26C07C7DC7170A (vente_id), INDEX IDX_8B26C07C7294869C (article_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE matiere_premiere (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(150) NOT NULL, unite_stock VARCHAR(20) NOT NULL, stock_actuel BIGINT DEFAULT 0 NOT NULL, stock_mini BIGINT DEFAULT 0 NOT NULL, cout_moyen_pondere INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, fournisseur_id INT DEFAULT NULL, INDEX IDX_179505B7670C757F (fournisseur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mouvement_caisse (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, categorie VARCHAR(30) DEFAULT NULL, montant INT NOT NULL, commentaire VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, session_caisse_id INT NOT NULL, utilisateur_id INT NOT NULL, INDEX IDX_C8E3DDFEFB88E14F (utilisateur_id), INDEX idx_mouvement_caisse_session (session_caisse_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mouvement_stock (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(30) NOT NULL, quantite BIGINT NOT NULL, motif VARCHAR(255) DEFAULT NULL, source_type VARCHAR(50) DEFAULT NULL, source_id INT DEFAULT NULL, created_at DATETIME NOT NULL, matiere_premiere_id INT DEFAULT NULL, article_id INT DEFAULT NULL, INDEX IDX_61E2C8EB5B42BE3C (matiere_premiere_id), INDEX IDX_61E2C8EB7294869C (article_id), INDEX idx_mouvement_stock_created_at (created_at), INDEX idx_mouvement_stock_source (source_type, source_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notification (id INT AUTO_INCREMENT NOT NULL, role_destinataire VARCHAR(50) NOT NULL, type VARCHAR(50) NOT NULL, titre VARCHAR(255) NOT NULL, message LONGTEXT NOT NULL, lien VARCHAR(255) DEFAULT NULL, lu_a DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_notification_destinataire (role_destinataire, lu_a), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE parametre (id INT AUTO_INCREMENT NOT NULL, cle VARCHAR(100) NOT NULL, valeur LONGTEXT NOT NULL, modifie_a DATETIME NOT NULL, UNIQUE INDEX uniq_parametre_cle (cle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE perte (id INT AUTO_INCREMENT NOT NULL, motif VARCHAR(30) NOT NULL, quantite BIGINT NOT NULL, valorisation INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, matiere_premiere_id INT DEFAULT NULL, article_id INT DEFAULT NULL, INDEX IDX_12F685F85B42BE3C (matiere_premiere_id), INDEX IDX_12F685F87294869C (article_id), INDEX idx_perte_created_at (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reglement (id INT AUTO_INCREMENT NOT NULL, mode VARCHAR(20) NOT NULL, montant INT NOT NULL, reference VARCHAR(100) DEFAULT NULL, created_at DATETIME NOT NULL, vente_id INT NOT NULL, INDEX IDX_EBE4C14C7DC7170A (vente_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE session_caisse (id INT AUTO_INCREMENT NOT NULL, fond_caisse INT NOT NULL, ouverture_at DATETIME NOT NULL, cloture_at DATETIME DEFAULT NULL, statut VARCHAR(20) NOT NULL, theorique INT DEFAULT NULL, montant_compte INT DEFAULT NULL, ecart INT DEFAULT NULL, commentaire_cloture LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, utilisateur_id INT NOT NULL, INDEX IDX_DDC85991FB88E14F (utilisateur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE utilisateur (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, mot_de_passe VARCHAR(255) DEFAULT NULL, code_pin VARCHAR(255) DEFAULT NULL, nom VARCHAR(120) NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_utilisateur_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE vente (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, numero VARCHAR(30) NOT NULL, mode VARCHAR(20) NOT NULL, total_ht INT NOT NULL, total_tva INT NOT NULL, total_ttc INT NOT NULL, statut VARCHAR(20) NOT NULL, remise INT DEFAULT 0 NOT NULL, motif_remise VARCHAR(255) DEFAULT NULL, rendu INT DEFAULT 0 NOT NULL, motif_annulation VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, session_caisse_id INT NOT NULL, INDEX IDX_888A2A4C6456BBB5 (session_caisse_id), INDEX idx_vente_created_at (created_at), UNIQUE INDEX uniq_vente_uuid (uuid), UNIQUE INDEX uniq_vente_numero (numero), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE article ADD CONSTRAINT FK_23A0E66FBC0E351 FOREIGN KEY (famille_produit_id) REFERENCES famille_produit (id)');
        $this->addSql('ALTER TABLE fiche_technique ADD CONSTRAINT FK_505525A97294869C FOREIGN KEY (article_id) REFERENCES article (id)');
        $this->addSql('ALTER TABLE inventaire ADD CONSTRAINT FK_338920E060BB6FE6 FOREIGN KEY (auteur_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE inventaire ADD CONSTRAINT FK_338920E06AF12ED9 FOREIGN KEY (valide_par_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE journal_audit ADD CONSTRAINT FK_71C3CC53FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE ligne_fiche_technique ADD CONSTRAINT FK_5BB444BA431AD613 FOREIGN KEY (fiche_technique_id) REFERENCES fiche_technique (id)');
        $this->addSql('ALTER TABLE ligne_fiche_technique ADD CONSTRAINT FK_5BB444BA5B42BE3C FOREIGN KEY (matiere_premiere_id) REFERENCES matiere_premiere (id)');
        $this->addSql('ALTER TABLE ligne_inventaire ADD CONSTRAINT FK_D025CEFDCE430A85 FOREIGN KEY (inventaire_id) REFERENCES inventaire (id)');
        $this->addSql('ALTER TABLE ligne_inventaire ADD CONSTRAINT FK_D025CEFD5B42BE3C FOREIGN KEY (matiere_premiere_id) REFERENCES matiere_premiere (id)');
        $this->addSql('ALTER TABLE ligne_inventaire ADD CONSTRAINT FK_D025CEFD7294869C FOREIGN KEY (article_id) REFERENCES article (id)');
        $this->addSql('ALTER TABLE ligne_vente ADD CONSTRAINT FK_8B26C07C7DC7170A FOREIGN KEY (vente_id) REFERENCES vente (id)');
        $this->addSql('ALTER TABLE ligne_vente ADD CONSTRAINT FK_8B26C07C7294869C FOREIGN KEY (article_id) REFERENCES article (id)');
        $this->addSql('ALTER TABLE matiere_premiere ADD CONSTRAINT FK_179505B7670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id)');
        $this->addSql('ALTER TABLE mouvement_caisse ADD CONSTRAINT FK_C8E3DDFE6456BBB5 FOREIGN KEY (session_caisse_id) REFERENCES session_caisse (id)');
        $this->addSql('ALTER TABLE mouvement_caisse ADD CONSTRAINT FK_C8E3DDFEFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id)');
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
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article DROP FOREIGN KEY FK_23A0E66FBC0E351');
        $this->addSql('ALTER TABLE fiche_technique DROP FOREIGN KEY FK_505525A97294869C');
        $this->addSql('ALTER TABLE inventaire DROP FOREIGN KEY FK_338920E060BB6FE6');
        $this->addSql('ALTER TABLE inventaire DROP FOREIGN KEY FK_338920E06AF12ED9');
        $this->addSql('ALTER TABLE journal_audit DROP FOREIGN KEY FK_71C3CC53FB88E14F');
        $this->addSql('ALTER TABLE ligne_fiche_technique DROP FOREIGN KEY FK_5BB444BA431AD613');
        $this->addSql('ALTER TABLE ligne_fiche_technique DROP FOREIGN KEY FK_5BB444BA5B42BE3C');
        $this->addSql('ALTER TABLE ligne_inventaire DROP FOREIGN KEY FK_D025CEFDCE430A85');
        $this->addSql('ALTER TABLE ligne_inventaire DROP FOREIGN KEY FK_D025CEFD5B42BE3C');
        $this->addSql('ALTER TABLE ligne_inventaire DROP FOREIGN KEY FK_D025CEFD7294869C');
        $this->addSql('ALTER TABLE ligne_vente DROP FOREIGN KEY FK_8B26C07C7DC7170A');
        $this->addSql('ALTER TABLE ligne_vente DROP FOREIGN KEY FK_8B26C07C7294869C');
        $this->addSql('ALTER TABLE matiere_premiere DROP FOREIGN KEY FK_179505B7670C757F');
        $this->addSql('ALTER TABLE mouvement_caisse DROP FOREIGN KEY FK_C8E3DDFE6456BBB5');
        $this->addSql('ALTER TABLE mouvement_caisse DROP FOREIGN KEY FK_C8E3DDFEFB88E14F');
        $this->addSql('ALTER TABLE mouvement_stock DROP FOREIGN KEY FK_61E2C8EB5B42BE3C');
        $this->addSql('ALTER TABLE mouvement_stock DROP FOREIGN KEY FK_61E2C8EB7294869C');
        $this->addSql('ALTER TABLE perte DROP FOREIGN KEY FK_12F685F85B42BE3C');
        $this->addSql('ALTER TABLE perte DROP FOREIGN KEY FK_12F685F87294869C');
        $this->addSql('ALTER TABLE reglement DROP FOREIGN KEY FK_EBE4C14C7DC7170A');
        $this->addSql('ALTER TABLE session_caisse DROP FOREIGN KEY FK_DDC85991FB88E14F');
        $this->addSql('ALTER TABLE vente DROP FOREIGN KEY FK_888A2A4C6456BBB5');
        $this->addSql('DROP TABLE article');
        $this->addSql('DROP TABLE famille_produit');
        $this->addSql('DROP TABLE fiche_technique');
        $this->addSql('DROP TABLE fournisseur');
        $this->addSql('DROP TABLE inventaire');
        $this->addSql('DROP TABLE journal_audit');
        $this->addSql('DROP TABLE ligne_fiche_technique');
        $this->addSql('DROP TABLE ligne_inventaire');
        $this->addSql('DROP TABLE ligne_vente');
        $this->addSql('DROP TABLE matiere_premiere');
        $this->addSql('DROP TABLE mouvement_caisse');
        $this->addSql('DROP TABLE mouvement_stock');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE parametre');
        $this->addSql('DROP TABLE perte');
        $this->addSql('DROP TABLE reglement');
        $this->addSql('DROP TABLE session_caisse');
        $this->addSql('DROP TABLE utilisateur');
        $this->addSql('DROP TABLE vente');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
