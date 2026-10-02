<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retrait du point de vitrine (`/admin/vitrine`) : la fiche de production de la
 * caisse suffit. Ses tables et son paramètre de seuil d'écart disparaissent.
 *
 * Les entrées POINT_VITRINE_* / ECART_VITRINE déjà au journal d'audit restent :
 * le journal est inaltérable.
 */
final class Version20260930192903 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retrait du point de vitrine : point_vitrine, ligne_point_vitrine, paramètre vitrine.seuil_ecart.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ligne_point_vitrine DROP FOREIGN KEY FK_CD5C91197294869C');
        $this->addSql('ALTER TABLE ligne_point_vitrine DROP FOREIGN KEY FK_CD5C9119C028CEA2');
        $this->addSql('ALTER TABLE point_vitrine DROP FOREIGN KEY FK_C63F90786456BBB5');
        $this->addSql('ALTER TABLE point_vitrine DROP FOREIGN KEY FK_C63F9078B03A8386');
        $this->addSql('ALTER TABLE point_vitrine DROP FOREIGN KEY FK_C63F9078F376B95');
        $this->addSql('DROP TABLE ligne_point_vitrine');
        $this->addSql('DROP TABLE point_vitrine');
        $this->addSql("DELETE FROM parametre WHERE cle = 'vitrine.seuil_ecart'");
    }

    public function down(Schema $schema): void
    {
        // Tables recréées vides : les points supprimés ne reviennent pas.
        $this->addSql('CREATE TABLE point_vitrine (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(20) NOT NULL, statut VARCHAR(20) NOT NULL, manquant INT NOT NULL, excedent INT NOT NULL, valeur_perdue INT NOT NULL, valeur_restante INT NOT NULL, justification LONGTEXT DEFAULT NULL, annule_at DATETIME DEFAULT NULL, motif_annulation VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, session_caisse_id INT NOT NULL, created_by_id INT NOT NULL, annule_par_id INT DEFAULT NULL, INDEX IDX_C63F90786456BBB5 (session_caisse_id), INDEX IDX_C63F9078B03A8386 (created_by_id), INDEX IDX_C63F9078F376B95 (annule_par_id), UNIQUE INDEX uniq_point_vitrine_numero (numero), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ligne_point_vitrine (id INT AUTO_INCREMENT NOT NULL, libelle VARCHAR(150) NOT NULL, prix_unitaire INT NOT NULL, qte_produite BIGINT NOT NULL, qte_mise_en_vitrine BIGINT NOT NULL, qte_restante BIGINT NOT NULL, qte_perdue BIGINT NOT NULL, motif_perte VARCHAR(30) DEFAULT NULL, qte_vendue_theorique BIGINT NOT NULL, qte_vendue_caisse BIGINT NOT NULL, ecart_quantite BIGINT NOT NULL, ecart_montant INT NOT NULL, point_id INT NOT NULL, article_id INT NOT NULL, INDEX IDX_CD5C9119C028CEA2 (point_id), INDEX IDX_CD5C91197294869C (article_id), UNIQUE INDEX uniq_ligne_point_vitrine_article (point_id, article_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE point_vitrine ADD CONSTRAINT FK_C63F90786456BBB5 FOREIGN KEY (session_caisse_id) REFERENCES session_caisse (id)');
        $this->addSql('ALTER TABLE point_vitrine ADD CONSTRAINT FK_C63F9078B03A8386 FOREIGN KEY (created_by_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE point_vitrine ADD CONSTRAINT FK_C63F9078F376B95 FOREIGN KEY (annule_par_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE ligne_point_vitrine ADD CONSTRAINT FK_CD5C9119C028CEA2 FOREIGN KEY (point_id) REFERENCES point_vitrine (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ligne_point_vitrine ADD CONSTRAINT FK_CD5C91197294869C FOREIGN KEY (article_id) REFERENCES article (id)');
    }
}
