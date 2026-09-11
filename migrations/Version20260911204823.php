<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dettes des vendeurs : dette_vendeur, remboursement_dette ; sort du manquant sur
 * l'arrêté (arrete.traitement_ecart).
 *
 * Les points validés avant cette migration gardent un traitement nul : leurs
 * manquants n'ont été ni imputés ni passés en perte, et aucune dette n'est créée
 * après coup — ce serait précisément une création silencieuse.
 */
final class Version20260911204823 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dettes des vendeurs : dette_vendeur, remboursement_dette ; arrete.traitement_ecart.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dette_vendeur (id INT AUTO_INCREMENT NOT NULL, montant INT NOT NULL, type VARCHAR(15) NOT NULL, statut VARCHAR(12) NOT NULL, montant_rembourse INT NOT NULL, commentaire LONGTEXT DEFAULT NULL, annule_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, vendeur_id INT NOT NULL, arrete_id INT DEFAULT NULL, created_by_id INT NOT NULL, INDEX IDX_BDFC8AA8858C065E (vendeur_id), INDEX IDX_BDFC8AA8F9001553 (arrete_id), INDEX IDX_BDFC8AA8B03A8386 (created_by_id), INDEX idx_dette_vendeur_statut (vendeur_id, statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE remboursement_dette (id INT AUTO_INCREMENT NOT NULL, montant INT NOT NULL, date_remboursement DATE NOT NULL, moyen VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, dette_id INT NOT NULL, encaisse_par_id INT NOT NULL, INDEX IDX_8D36E8ADE11400A1 (dette_id), INDEX IDX_8D36E8ADA4FBCD6F (encaisse_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE dette_vendeur ADD CONSTRAINT FK_BDFC8AA8858C065E FOREIGN KEY (vendeur_id) REFERENCES vendeur (id)');
        $this->addSql('ALTER TABLE dette_vendeur ADD CONSTRAINT FK_BDFC8AA8F9001553 FOREIGN KEY (arrete_id) REFERENCES arrete (id)');
        $this->addSql('ALTER TABLE dette_vendeur ADD CONSTRAINT FK_BDFC8AA8B03A8386 FOREIGN KEY (created_by_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE remboursement_dette ADD CONSTRAINT FK_8D36E8ADE11400A1 FOREIGN KEY (dette_id) REFERENCES dette_vendeur (id)');
        $this->addSql('ALTER TABLE remboursement_dette ADD CONSTRAINT FK_8D36E8ADA4FBCD6F FOREIGN KEY (encaisse_par_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE arrete ADD traitement_ecart VARCHAR(10) DEFAULT NULL');
    }

    /** Refusé dès qu'une dette existe : ce serait effacer ce que doivent les vendeurs. */
    public function down(Schema $schema): void
    {
        $dettes = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM dette_vendeur');
        $this->abortIf($dettes > 0, 'Des dettes de vendeurs existent : le retour arrière les effacerait.');

        $this->addSql('ALTER TABLE remboursement_dette DROP FOREIGN KEY FK_8D36E8ADE11400A1');
        $this->addSql('ALTER TABLE remboursement_dette DROP FOREIGN KEY FK_8D36E8ADA4FBCD6F');
        $this->addSql('ALTER TABLE dette_vendeur DROP FOREIGN KEY FK_BDFC8AA8858C065E');
        $this->addSql('ALTER TABLE dette_vendeur DROP FOREIGN KEY FK_BDFC8AA8F9001553');
        $this->addSql('ALTER TABLE dette_vendeur DROP FOREIGN KEY FK_BDFC8AA8B03A8386');
        $this->addSql('DROP TABLE remboursement_dette');
        $this->addSql('DROP TABLE dette_vendeur');
        $this->addSql('ALTER TABLE arrete DROP traitement_ecart');
    }
}
