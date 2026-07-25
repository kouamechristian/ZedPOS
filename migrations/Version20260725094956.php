<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cycle de caisse : dépenses / sorties d'espèces (mouvement_caisse) et données de
 * clôture Z sur la session (théorique, montant compté, écart, commentaire).
 */
final class Version20260725094956 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Cycle de caisse : mouvements d'espèces et données de clôture Z";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mouvement_caisse (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, categorie VARCHAR(30) DEFAULT NULL, montant INT NOT NULL, commentaire VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, session_caisse_id INT NOT NULL, utilisateur_id INT NOT NULL, INDEX IDX_C8E3DDFEFB88E14F (utilisateur_id), INDEX idx_mouvement_caisse_session (session_caisse_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE mouvement_caisse ADD CONSTRAINT FK_C8E3DDFE6456BBB5 FOREIGN KEY (session_caisse_id) REFERENCES session_caisse (id)');
        $this->addSql('ALTER TABLE mouvement_caisse ADD CONSTRAINT FK_C8E3DDFEFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE session_caisse ADD theorique INT DEFAULT NULL, ADD montant_compte INT DEFAULT NULL, ADD ecart INT DEFAULT NULL, ADD commentaire_cloture LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mouvement_caisse DROP FOREIGN KEY FK_C8E3DDFE6456BBB5');
        $this->addSql('ALTER TABLE mouvement_caisse DROP FOREIGN KEY FK_C8E3DDFEFB88E14F');
        $this->addSql('DROP TABLE mouvement_caisse');
        $this->addSql('ALTER TABLE session_caisse DROP theorique, DROP montant_compte, DROP ecart, DROP commentaire_cloture');
    }
}
