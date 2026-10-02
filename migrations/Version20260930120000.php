<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Production et vitrine : déclarations de l'atelier et de la vitrine rattachées à
 * une caisse, point de vitrine de la gérante, et atelier de chaque famille.
 *
 * Tables neuves et une colonne nulle : rien à reprendre dans l'existant. Aucune
 * famille n'est rattachée à un atelier d'office — c'est à la gérante de dire
 * lesquelles se déclarent, dans Articles → Familles.
 */
final class Version20260930120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Production et vitrine : saisie_production, point_vitrine et leurs lignes, famille_produit.atelier.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE famille_produit ADD atelier VARCHAR(20) DEFAULT NULL');

        $this->addSql('CREATE TABLE saisie_production (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(20) NOT NULL, type VARCHAR(20) NOT NULL, atelier VARCHAR(20) DEFAULT NULL, statut VARCHAR(20) NOT NULL, annule_at DATETIME DEFAULT NULL, motif_annulation VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, session_caisse_id INT NOT NULL, created_by_id INT NOT NULL, annule_par_id INT DEFAULT NULL, INDEX IDX_291C87946456BBB5 (session_caisse_id), INDEX IDX_291C8794B03A8386 (created_by_id), INDEX IDX_291C8794F376B95 (annule_par_id), UNIQUE INDEX uniq_saisie_production_numero (numero), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ligne_saisie_production (id INT AUTO_INCREMENT NOT NULL, quantite BIGINT NOT NULL, saisie_id INT NOT NULL, article_id INT NOT NULL, INDEX IDX_72469C29A12433ED (saisie_id), INDEX IDX_72469C297294869C (article_id), UNIQUE INDEX uniq_ligne_saisie_production_article (saisie_id, article_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE point_vitrine (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(20) NOT NULL, statut VARCHAR(20) NOT NULL, manquant INT NOT NULL, excedent INT NOT NULL, valeur_perdue INT NOT NULL, valeur_reportee INT NOT NULL, justification LONGTEXT DEFAULT NULL, annule_at DATETIME DEFAULT NULL, motif_annulation VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, session_caisse_id INT NOT NULL, point_precedent_id INT DEFAULT NULL, created_by_id INT NOT NULL, annule_par_id INT DEFAULT NULL, INDEX IDX_C63F90786456BBB5 (session_caisse_id), INDEX IDX_C63F9078B9EA9D9D (point_precedent_id), INDEX IDX_C63F9078B03A8386 (created_by_id), INDEX IDX_C63F9078F376B95 (annule_par_id), UNIQUE INDEX uniq_point_vitrine_numero (numero), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ligne_point_vitrine (id INT AUTO_INCREMENT NOT NULL, libelle VARCHAR(150) NOT NULL, prix_unitaire INT NOT NULL, qte_reprise BIGINT NOT NULL, qte_produite BIGINT NOT NULL, qte_mise_en_vitrine BIGINT NOT NULL, qte_reportee BIGINT NOT NULL, qte_perdue BIGINT NOT NULL, motif_perte VARCHAR(30) DEFAULT NULL, qte_vendue_theorique BIGINT NOT NULL, qte_vendue_caisse BIGINT NOT NULL, ecart_quantite BIGINT NOT NULL, ecart_montant INT NOT NULL, point_id INT NOT NULL, article_id INT NOT NULL, INDEX IDX_CD5C9119C028CEA2 (point_id), INDEX IDX_CD5C91197294869C (article_id), UNIQUE INDEX uniq_ligne_point_vitrine_article (point_id, article_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE saisie_production ADD CONSTRAINT FK_291C87946456BBB5 FOREIGN KEY (session_caisse_id) REFERENCES session_caisse (id)');
        $this->addSql('ALTER TABLE saisie_production ADD CONSTRAINT FK_291C8794B03A8386 FOREIGN KEY (created_by_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE saisie_production ADD CONSTRAINT FK_291C8794F376B95 FOREIGN KEY (annule_par_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE ligne_saisie_production ADD CONSTRAINT FK_72469C29A12433ED FOREIGN KEY (saisie_id) REFERENCES saisie_production (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ligne_saisie_production ADD CONSTRAINT FK_72469C297294869C FOREIGN KEY (article_id) REFERENCES article (id)');
        $this->addSql('ALTER TABLE point_vitrine ADD CONSTRAINT FK_C63F90786456BBB5 FOREIGN KEY (session_caisse_id) REFERENCES session_caisse (id)');
        $this->addSql('ALTER TABLE point_vitrine ADD CONSTRAINT FK_C63F9078B9EA9D9D FOREIGN KEY (point_precedent_id) REFERENCES point_vitrine (id)');
        $this->addSql('ALTER TABLE point_vitrine ADD CONSTRAINT FK_C63F9078B03A8386 FOREIGN KEY (created_by_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE point_vitrine ADD CONSTRAINT FK_C63F9078F376B95 FOREIGN KEY (annule_par_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE ligne_point_vitrine ADD CONSTRAINT FK_CD5C9119C028CEA2 FOREIGN KEY (point_id) REFERENCES point_vitrine (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ligne_point_vitrine ADD CONSTRAINT FK_CD5C91197294869C FOREIGN KEY (article_id) REFERENCES article (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ligne_point_vitrine DROP FOREIGN KEY FK_CD5C9119C028CEA2');
        $this->addSql('ALTER TABLE ligne_point_vitrine DROP FOREIGN KEY FK_CD5C91197294869C');
        $this->addSql('ALTER TABLE point_vitrine DROP FOREIGN KEY FK_C63F90786456BBB5');
        $this->addSql('ALTER TABLE point_vitrine DROP FOREIGN KEY FK_C63F9078B9EA9D9D');
        $this->addSql('ALTER TABLE point_vitrine DROP FOREIGN KEY FK_C63F9078B03A8386');
        $this->addSql('ALTER TABLE point_vitrine DROP FOREIGN KEY FK_C63F9078F376B95');
        $this->addSql('ALTER TABLE ligne_saisie_production DROP FOREIGN KEY FK_72469C29A12433ED');
        $this->addSql('ALTER TABLE ligne_saisie_production DROP FOREIGN KEY FK_72469C297294869C');
        $this->addSql('ALTER TABLE saisie_production DROP FOREIGN KEY FK_291C87946456BBB5');
        $this->addSql('ALTER TABLE saisie_production DROP FOREIGN KEY FK_291C8794B03A8386');
        $this->addSql('ALTER TABLE saisie_production DROP FOREIGN KEY FK_291C8794F376B95');
        $this->addSql('DROP TABLE ligne_point_vitrine');
        $this->addSql('DROP TABLE point_vitrine');
        $this->addSql('DROP TABLE ligne_saisie_production');
        $this->addSql('DROP TABLE saisie_production');
        $this->addSql('ALTER TABLE famille_produit DROP atelier');
    }
}
