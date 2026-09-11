<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Point des stands : arrêtés de période, bons et lignes de retour ; mode de
 * rémunération, taux de commission et seuil d'écart sur l'emplacement.
 *
 * `arrete_periode`, squelette sans écran de l'étape précédente, est remplacé par
 * `arrete`. Refusé s'il contient des lignes : aucune n'a pu être créée par
 * l'application, en trouver une serait une surprise à regarder avant de la perdre.
 */
final class Version20260911130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Point des stands : arrete (remplace arrete_periode), bon_retour, ligne_retour ; emplacement.mode_remuneration, taux_commission, seuil_ecart_alerte.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $existants = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM arrete_periode');
        $this->abortIf($existants > 0, 'arrete_periode contient des lignes : à examiner avant de la remplacer.');

        $this->addSql('ALTER TABLE bon_dotation DROP FOREIGN KEY FK_E8DF9356F9001553');
        $this->addSql('ALTER TABLE arrete_periode DROP FOREIGN KEY FK_98CC09A89734D487');
        $this->addSql('DROP TABLE arrete_periode');

        $this->addSql('CREATE TABLE arrete (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(20) NOT NULL, granularite VARCHAR(10) NOT NULL, date_debut DATE NOT NULL, date_fin DATE NOT NULL, statut VARCHAR(20) NOT NULL, mode_remuneration VARCHAR(12) NOT NULL, taux_commission INT NOT NULL, montant_attendu INT NOT NULL, remuneration_vendeur INT NOT NULL, net_a_remettre INT NOT NULL, montant_remis INT DEFAULT NULL, ecart INT DEFAULT NULL, commentaire_ecart LONGTEXT DEFAULT NULL, date_validation DATETIME DEFAULT NULL, annule_at DATETIME DEFAULT NULL, motif_annulation VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, stand_id INT NOT NULL, vendeur_id INT NOT NULL, created_by_id INT NOT NULL, INDEX IDX_8D9860A9734D487 (stand_id), INDEX IDX_8D9860A858C065E (vendeur_id), INDEX IDX_8D9860AB03A8386 (created_by_id), INDEX idx_arrete_stand_fin (stand_id, date_fin), UNIQUE INDEX uniq_arrete_numero (numero), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bon_retour (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(20) NOT NULL, date_retour DATE NOT NULL, statut VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, stand_id INT NOT NULL, vendeur_id INT NOT NULL, arrete_id INT DEFAULT NULL, created_by_id INT NOT NULL, INDEX IDX_F913AB1B9734D487 (stand_id), INDEX IDX_F913AB1B858C065E (vendeur_id), INDEX IDX_F913AB1BF9001553 (arrete_id), INDEX IDX_F913AB1BB03A8386 (created_by_id), INDEX idx_bon_retour_date (date_retour), UNIQUE INDEX uniq_bon_retour_numero (numero), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ligne_retour (id INT AUTO_INCREMENT NOT NULL, quantite BIGINT NOT NULL, motif VARCHAR(10) NOT NULL, commentaire VARCHAR(255) DEFAULT NULL, bon_id INT NOT NULL, produit_id INT NOT NULL, INDEX IDX_CBB54F67AD65737C (bon_id), INDEX IDX_CBB54F67F347EFB (produit_id), UNIQUE INDEX uniq_ligne_retour_produit_motif (bon_id, produit_id, motif), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql("ALTER TABLE emplacement ADD mode_remuneration VARCHAR(12) DEFAULT 'COMMISSION' NOT NULL, ADD taux_commission INT DEFAULT 0 NOT NULL, ADD seuil_ecart_alerte INT DEFAULT 0 NOT NULL");

        $this->addSql('ALTER TABLE arrete ADD CONSTRAINT FK_8D9860A9734D487 FOREIGN KEY (stand_id) REFERENCES emplacement (id)');
        $this->addSql('ALTER TABLE arrete ADD CONSTRAINT FK_8D9860A858C065E FOREIGN KEY (vendeur_id) REFERENCES vendeur (id)');
        $this->addSql('ALTER TABLE arrete ADD CONSTRAINT FK_8D9860AB03A8386 FOREIGN KEY (created_by_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE bon_retour ADD CONSTRAINT FK_F913AB1B9734D487 FOREIGN KEY (stand_id) REFERENCES emplacement (id)');
        $this->addSql('ALTER TABLE bon_retour ADD CONSTRAINT FK_F913AB1B858C065E FOREIGN KEY (vendeur_id) REFERENCES vendeur (id)');
        $this->addSql('ALTER TABLE bon_retour ADD CONSTRAINT FK_F913AB1BF9001553 FOREIGN KEY (arrete_id) REFERENCES arrete (id)');
        $this->addSql('ALTER TABLE bon_retour ADD CONSTRAINT FK_F913AB1BB03A8386 FOREIGN KEY (created_by_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE ligne_retour ADD CONSTRAINT FK_CBB54F67AD65737C FOREIGN KEY (bon_id) REFERENCES bon_retour (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ligne_retour ADD CONSTRAINT FK_CBB54F67F347EFB FOREIGN KEY (produit_id) REFERENCES article (id)');
        $this->addSql('ALTER TABLE bon_dotation ADD CONSTRAINT FK_E8DF9356F9001553 FOREIGN KEY (arrete_id) REFERENCES arrete (id)');
    }

    /** Refusé dès qu'un arrêté ou un retour existe : ce serait effacer des points faits avec les vendeurs. */
    public function down(Schema $schema): void
    {
        $points = (int) $this->connection->fetchOne('SELECT (SELECT COUNT(*) FROM arrete) + (SELECT COUNT(*) FROM bon_retour)');
        $this->abortIf($points > 0, 'Des arrêtés ou des retours existent : le retour arrière les effacerait.');

        $this->addSql('UPDATE bon_dotation SET arrete_id = NULL');
        $this->addSql('ALTER TABLE bon_dotation DROP FOREIGN KEY FK_E8DF9356F9001553');
        $this->addSql('DROP TABLE ligne_retour');
        $this->addSql('DROP TABLE bon_retour');
        $this->addSql('DROP TABLE arrete');
        $this->addSql('ALTER TABLE emplacement DROP mode_remuneration, DROP taux_commission, DROP seuil_ecart_alerte');
        $this->addSql('CREATE TABLE arrete_periode (id INT AUTO_INCREMENT NOT NULL, debut DATE NOT NULL, fin DATE NOT NULL, statut VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, stand_id INT NOT NULL, INDEX IDX_98CC09A89734D487 (stand_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE arrete_periode ADD CONSTRAINT FK_98CC09A89734D487 FOREIGN KEY (stand_id) REFERENCES emplacement (id)');
        $this->addSql('ALTER TABLE bon_dotation ADD CONSTRAINT FK_E8DF9356F9001553 FOREIGN KEY (arrete_id) REFERENCES arrete_periode (id)');
    }
}
