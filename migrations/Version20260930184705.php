<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Refonte de la production et de la vitrine : une fiche de production par caisse.
 *
 * - `fiche_production` / `ligne_fiche_production` : produit et mis en vitrine
 *   par article, nés à zéro à l'ouverture de la caisse.
 * - Reprise : chaque caisse ouverte ou déjà déclarée reçoit sa fiche, lignes
 *   additionnées depuis ses déclarations validées ; la caisse ouverte reçoit en
 *   plus tous les articles d'atelier actifs à zéro.
 * - Point de vitrine : plus de reprise de la veille (chaque caisse repart de
 *   zéro), « remis en vente demain » devient « resté en vitrine ».
 *
 * SQL portable MariaDB 10.4 / MySQL 9 : ni `RENAME INDEX`, ni `VALUES()`.
 */
final class Version20260930184705 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fiche de production par caisse ; point de vitrine sans reprise de la veille.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fiche_production (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, session_caisse_id INT NOT NULL, UNIQUE INDEX uniq_fiche_production_session (session_caisse_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ligne_fiche_production (id INT AUTO_INCREMENT NOT NULL, qte_produite BIGINT NOT NULL, qte_vitrine BIGINT NOT NULL, fiche_id INT NOT NULL, article_id INT NOT NULL, INDEX IDX_F90E15A8DF522508 (fiche_id), INDEX IDX_F90E15A87294869C (article_id), UNIQUE INDEX uniq_ligne_fiche_production_article (fiche_id, article_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE fiche_production ADD CONSTRAINT FK_7DBBB5176456BBB5 FOREIGN KEY (session_caisse_id) REFERENCES session_caisse (id)');
        $this->addSql('ALTER TABLE ligne_fiche_production ADD CONSTRAINT FK_F90E15A8DF522508 FOREIGN KEY (fiche_id) REFERENCES fiche_production (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ligne_fiche_production ADD CONSTRAINT FK_F90E15A87294869C FOREIGN KEY (article_id) REFERENCES article (id)');

        // Une fiche pour la caisse ouverte et pour chaque caisse déjà déclarée.
        $this->addSql("INSERT INTO fiche_production (session_caisse_id, created_at)
            SELECT s.id, s.ouverture_at FROM session_caisse s
            WHERE s.statut = 'OUVERTE'
               OR EXISTS (SELECT 1 FROM saisie_production p WHERE p.session_caisse_id = s.id)");

        // Lignes : la somme des déclarations validées, par article.
        $this->addSql("INSERT INTO ligne_fiche_production (fiche_id, article_id, qte_produite, qte_vitrine)
            SELECT f.id, l.article_id,
                   SUM(CASE WHEN p.type = 'PRODUCTION' THEN l.quantite ELSE 0 END),
                   SUM(CASE WHEN p.type = 'VITRINE' THEN l.quantite ELSE 0 END)
            FROM ligne_saisie_production l
            JOIN saisie_production p ON p.id = l.saisie_id AND p.statut = 'VALIDEE'
            JOIN fiche_production f ON f.session_caisse_id = p.session_caisse_id
            GROUP BY f.id, l.article_id");

        // La caisse ouverte : tous les articles d'atelier actifs, à zéro.
        $this->addSql("INSERT INTO ligne_fiche_production (fiche_id, article_id, qte_produite, qte_vitrine)
            SELECT f.id, a.id, 0, 0
            FROM fiche_production f
            JOIN session_caisse s ON s.id = f.session_caisse_id AND s.statut = 'OUVERTE'
            JOIN article a ON a.actif = 1
            JOIN famille_produit fa ON fa.id = a.famille_produit_id AND fa.atelier IS NOT NULL
            WHERE NOT EXISTS (SELECT 1 FROM ligne_fiche_production x WHERE x.fiche_id = f.id AND x.article_id = a.id)");

        $this->addSql('ALTER TABLE ligne_point_vitrine DROP qte_reprise, CHANGE qte_reportee qte_restante BIGINT NOT NULL');
        $this->addSql('ALTER TABLE point_vitrine DROP FOREIGN KEY FK_C63F9078B9EA9D9D');
        $this->addSql('DROP INDEX IDX_C63F9078B9EA9D9D ON point_vitrine');
        $this->addSql('ALTER TABLE point_vitrine DROP point_precedent_id, CHANGE valeur_reportee valeur_restante INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE point_vitrine ADD point_precedent_id INT DEFAULT NULL, CHANGE valeur_restante valeur_reportee INT NOT NULL');
        $this->addSql('ALTER TABLE point_vitrine ADD CONSTRAINT FK_C63F9078B9EA9D9D FOREIGN KEY (point_precedent_id) REFERENCES point_vitrine (id)');
        $this->addSql('CREATE INDEX IDX_C63F9078B9EA9D9D ON point_vitrine (point_precedent_id)');
        $this->addSql('ALTER TABLE ligne_point_vitrine ADD qte_reprise BIGINT DEFAULT 0 NOT NULL, CHANGE qte_restante qte_reportee BIGINT NOT NULL');

        $this->addSql('ALTER TABLE ligne_fiche_production DROP FOREIGN KEY FK_F90E15A8DF522508');
        $this->addSql('ALTER TABLE ligne_fiche_production DROP FOREIGN KEY FK_F90E15A87294869C');
        $this->addSql('ALTER TABLE fiche_production DROP FOREIGN KEY FK_7DBBB5176456BBB5');
        $this->addSql('DROP TABLE ligne_fiche_production');
        $this->addSql('DROP TABLE fiche_production');
    }
}
