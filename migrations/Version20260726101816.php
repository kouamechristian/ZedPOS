<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260726101816 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE inventaire (id INT AUTO_INCREMENT NOT NULL, statut VARCHAR(20) NOT NULL, valide_at DATETIME DEFAULT NULL, commentaire LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, auteur_id INT NOT NULL, valide_par_id INT DEFAULT NULL, INDEX IDX_338920E060BB6FE6 (auteur_id), INDEX IDX_338920E06AF12ED9 (valide_par_id), INDEX idx_inventaire_created_at (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ligne_inventaire (id INT AUTO_INCREMENT NOT NULL, libelle VARCHAR(180) NOT NULL, unite VARCHAR(30) NOT NULL, quantite_theorique BIGINT NOT NULL, quantite_comptee BIGINT DEFAULT NULL, cout_unitaire INT NOT NULL, inventaire_id INT NOT NULL, matiere_premiere_id INT DEFAULT NULL, article_id INT DEFAULT NULL, INDEX IDX_D025CEFDCE430A85 (inventaire_id), INDEX IDX_D025CEFD5B42BE3C (matiere_premiere_id), INDEX IDX_D025CEFD7294869C (article_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE inventaire ADD CONSTRAINT FK_338920E060BB6FE6 FOREIGN KEY (auteur_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE inventaire ADD CONSTRAINT FK_338920E06AF12ED9 FOREIGN KEY (valide_par_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE ligne_inventaire ADD CONSTRAINT FK_D025CEFDCE430A85 FOREIGN KEY (inventaire_id) REFERENCES inventaire (id)');
        $this->addSql('ALTER TABLE ligne_inventaire ADD CONSTRAINT FK_D025CEFD5B42BE3C FOREIGN KEY (matiere_premiere_id) REFERENCES matiere_premiere (id)');
        $this->addSql('ALTER TABLE ligne_inventaire ADD CONSTRAINT FK_D025CEFD7294869C FOREIGN KEY (article_id) REFERENCES article (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE inventaire DROP FOREIGN KEY FK_338920E060BB6FE6');
        $this->addSql('ALTER TABLE inventaire DROP FOREIGN KEY FK_338920E06AF12ED9');
        $this->addSql('ALTER TABLE ligne_inventaire DROP FOREIGN KEY FK_D025CEFDCE430A85');
        $this->addSql('ALTER TABLE ligne_inventaire DROP FOREIGN KEY FK_D025CEFD5B42BE3C');
        $this->addSql('ALTER TABLE ligne_inventaire DROP FOREIGN KEY FK_D025CEFD7294869C');
        $this->addSql('DROP TABLE inventaire');
        $this->addSql('DROP TABLE ligne_inventaire');
    }
}
