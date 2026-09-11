<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module des revendeurs : vendeurs, dotations des stands, arrêté de période
 * (squelette), prix de cession des articles, périodicité du point et vendeur
 * habituel sur l'emplacement.
 *
 * Purement additif : aucune donnée existante n'est touchée. `prix_cession` vaut 0
 * partout — non fixé, donc aucun article dotable tant que la dirigeante ne l'a pas
 * renseigné, et c'est voulu.
 */
final class Version20260911110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Revendeurs : vendeur, bon_dotation, ligne_dotation, arrete_periode ; article.prix_cession ; emplacement.periodicite_point et vendeur_habituel_id.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE vendeur (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, telephone VARCHAR(30) DEFAULT NULL, actif TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE arrete_periode (id INT AUTO_INCREMENT NOT NULL, debut DATE NOT NULL, fin DATE NOT NULL, statut VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, stand_id INT NOT NULL, INDEX IDX_98CC09A89734D487 (stand_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bon_dotation (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(20) NOT NULL, date_dotation DATE NOT NULL, statut VARCHAR(20) NOT NULL, valide_at DATETIME DEFAULT NULL, annule_at DATETIME DEFAULT NULL, motif_annulation VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, stand_id INT NOT NULL, vendeur_id INT NOT NULL, arrete_id INT DEFAULT NULL, created_by_id INT NOT NULL, INDEX IDX_E8DF93569734D487 (stand_id), INDEX IDX_E8DF9356858C065E (vendeur_id), INDEX IDX_E8DF9356F9001553 (arrete_id), INDEX IDX_E8DF9356B03A8386 (created_by_id), INDEX idx_bon_dotation_date (date_dotation), UNIQUE INDEX uniq_bon_dotation_numero (numero), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ligne_dotation (id INT AUTO_INCREMENT NOT NULL, quantite BIGINT NOT NULL, prix_vente_unitaire INT DEFAULT NULL, prix_cession_unitaire INT DEFAULT NULL, bon_id INT NOT NULL, produit_id INT NOT NULL, INDEX IDX_CC32B16BAD65737C (bon_id), INDEX IDX_CC32B16BF347EFB (produit_id), UNIQUE INDEX uniq_ligne_dotation_produit (bon_id, produit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE article ADD prix_cession INT DEFAULT 0 NOT NULL');
        $this->addSql("ALTER TABLE emplacement ADD periodicite_point VARCHAR(10) DEFAULT 'JOUR' NOT NULL, ADD vendeur_habituel_id INT DEFAULT NULL");
        $this->addSql('CREATE INDEX IDX_C0CF65F6FB4140F8 ON emplacement (vendeur_habituel_id)');

        $this->addSql('ALTER TABLE arrete_periode ADD CONSTRAINT FK_98CC09A89734D487 FOREIGN KEY (stand_id) REFERENCES emplacement (id)');
        $this->addSql('ALTER TABLE bon_dotation ADD CONSTRAINT FK_E8DF93569734D487 FOREIGN KEY (stand_id) REFERENCES emplacement (id)');
        $this->addSql('ALTER TABLE bon_dotation ADD CONSTRAINT FK_E8DF9356858C065E FOREIGN KEY (vendeur_id) REFERENCES vendeur (id)');
        $this->addSql('ALTER TABLE bon_dotation ADD CONSTRAINT FK_E8DF9356F9001553 FOREIGN KEY (arrete_id) REFERENCES arrete_periode (id)');
        $this->addSql('ALTER TABLE bon_dotation ADD CONSTRAINT FK_E8DF9356B03A8386 FOREIGN KEY (created_by_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE ligne_dotation ADD CONSTRAINT FK_CC32B16BAD65737C FOREIGN KEY (bon_id) REFERENCES bon_dotation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ligne_dotation ADD CONSTRAINT FK_CC32B16BF347EFB FOREIGN KEY (produit_id) REFERENCES article (id)');
        $this->addSql('ALTER TABLE emplacement ADD CONSTRAINT FK_C0CF65F6FB4140F8 FOREIGN KEY (vendeur_habituel_id) REFERENCES vendeur (id) ON DELETE SET NULL');
    }

    /**
     * Retour arrière refusé dès qu'un bon existe : les supprimer effacerait des
     * dotations, et leurs mouvements de stock resteraient sans justificatif.
     */
    public function down(Schema $schema): void
    {
        $bons = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM bon_dotation');
        $this->abortIf($bons > 0, 'Des bons de dotation existent : le retour arrière les effacerait.');

        $this->addSql('ALTER TABLE emplacement DROP FOREIGN KEY FK_C0CF65F6FB4140F8');
        $this->addSql('DROP INDEX IDX_C0CF65F6FB4140F8 ON emplacement');
        $this->addSql('ALTER TABLE emplacement DROP periodicite_point, DROP vendeur_habituel_id');
        $this->addSql('ALTER TABLE article DROP prix_cession');
        $this->addSql('DROP TABLE ligne_dotation');
        $this->addSql('DROP TABLE bon_dotation');
        $this->addSql('DROP TABLE arrete_periode');
        $this->addSql('DROP TABLE vendeur');
    }
}
