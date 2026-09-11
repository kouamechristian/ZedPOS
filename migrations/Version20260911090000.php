<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Passage d'un stock unique à un stock par emplacement.
 *
 * Schéma : tables `emplacement` et `stock_courant` ; `mouvement_stock` gagne un
 * emplacement (obligatoire) et un auteur, et sa source devient un document.
 *
 * Données, dans cet ordre :
 * 1. création du dépôt principal (code DEPOT) ;
 * 2. tous les mouvements existants lui sont rattachés ;
 * 3. conversion des anciens types : SORTIE_VENTE et l'ENTREE d'annulation d'une
 *    vente → VENTE_CAISSE ; INVENTAIRE, SORTIE_PRODUCTION et les autres ENTREE →
 *    AJUSTEMENT ; PERTE inchangé ;
 * 4. **écriture d'ouverture** par produit (AJUSTEMENT, document « reprise ») égale
 *    à `stock_actuel − somme des mouvements existants`. L'historique ne retombait
 *    pas sur le stock affiché — un stock de départ saisi à la création d'une
 *    matière ne créait aucun mouvement — et sans cette écriture le premier
 *    `recalculerStock()` effacerait le stock ;
 * 5. `stock_courant` du dépôt = `stock_actuel`, qui reste en place et synchronisé.
 *
 * SQL volontairement portable : le poste de développement tourne sous MySQL 9,
 * la cible annoncée est MariaDB 10.4, qui ne connaît pas `RENAME INDEX`.
 */
final class Version20260911090000 extends AbstractMigration
{
    private const REPRISE = "'Reprise du stock existant (passage au stock par emplacement)'";

    public function getDescription(): string
    {
        return 'Stock par emplacement : emplacement, stock_courant, mouvement_stock rattaché ; bascule du stock existant au dépôt principal.';
    }

    public function isTransactional(): bool
    {
        // MySQL et MariaDB valident implicitement chaque ordre DDL.
        return false;
    }

    public function up(Schema $schema): void
    {
        // 1. Emplacements, et le dépôt principal.
        $this->addSql('CREATE TABLE emplacement (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(20) NOT NULL, libelle VARCHAR(100) NOT NULL, type VARCHAR(10) NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_emplacement_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql("INSERT INTO emplacement (code, libelle, type, actif, created_at) VALUES ('DEPOT', 'Dépôt principal', 'DEPOT', 1, NOW())");

        // 2. Mouvements : emplacement nullable le temps de le renseigner.
        $this->addSql('DROP INDEX idx_mouvement_stock_source ON mouvement_stock');
        $this->addSql('ALTER TABLE mouvement_stock ADD emplacement_id INT DEFAULT NULL, ADD utilisateur_id INT DEFAULT NULL, CHANGE source_type document_type VARCHAR(50) DEFAULT NULL, CHANGE source_id document_id INT DEFAULT NULL');
        $this->addSql("UPDATE mouvement_stock SET emplacement_id = (SELECT id FROM emplacement WHERE code = 'DEPOT')");
        $this->addSql('ALTER TABLE mouvement_stock MODIFY emplacement_id INT NOT NULL');

        // 3. Anciens types. L'ordre compte : les ENTREE de vente d'abord.
        $this->addSql("UPDATE mouvement_stock SET type = 'VENTE_CAISSE' WHERE type = 'SORTIE_VENTE' OR (type = 'ENTREE' AND document_type = 'vente')");
        $this->addSql("UPDATE mouvement_stock SET type = 'AJUSTEMENT' WHERE type IN ('ENTREE', 'INVENTAIRE', 'SORTIE_PRODUCTION')");

        // 4. Écritures d'ouverture : la somme des mouvements retombe sur stock_actuel.
        $this->addSql('INSERT INTO mouvement_stock (emplacement_id, matiere_premiere_id, article_id, type, quantite, motif, document_type, document_id, utilisateur_id, created_at)
            SELECT e.id, m.id, NULL, \'AJUSTEMENT\', m.stock_actuel - COALESCE(s.total, 0), '.self::REPRISE.', \'reprise\', NULL, NULL, NOW()
            FROM matiere_premiere m
            JOIN emplacement e ON e.code = \'DEPOT\'
            LEFT JOIN (SELECT matiere_premiere_id, SUM(quantite) AS total FROM mouvement_stock WHERE matiere_premiere_id IS NOT NULL GROUP BY matiere_premiere_id) s ON s.matiere_premiere_id = m.id
            WHERE m.stock_actuel <> COALESCE(s.total, 0)');
        $this->addSql('INSERT INTO mouvement_stock (emplacement_id, matiere_premiere_id, article_id, type, quantite, motif, document_type, document_id, utilisateur_id, created_at)
            SELECT e.id, NULL, a.id, \'AJUSTEMENT\', a.stock_actuel - COALESCE(s.total, 0), '.self::REPRISE.', \'reprise\', NULL, NULL, NOW()
            FROM article a
            JOIN emplacement e ON e.code = \'DEPOT\'
            LEFT JOIN (SELECT article_id, SUM(quantite) AS total FROM mouvement_stock WHERE article_id IS NOT NULL GROUP BY article_id) s ON s.article_id = a.id
            WHERE a.stock_actuel <> COALESCE(s.total, 0)');

        // 5. Stock courant du dépôt : toutes les matières ; les articles suivis,
        // ou dotés d'un stock ou d'un historique.
        $this->addSql('CREATE TABLE stock_courant (id INT AUTO_INCREMENT NOT NULL, quantite BIGINT NOT NULL, modifie_a DATETIME NOT NULL, emplacement_id INT NOT NULL, article_id INT DEFAULT NULL, matiere_premiere_id INT DEFAULT NULL, INDEX IDX_D2317199C4598A51 (emplacement_id), INDEX IDX_D23171997294869C (article_id), INDEX IDX_D23171995B42BE3C (matiere_premiere_id), UNIQUE INDEX uniq_stock_courant_article (emplacement_id, article_id), UNIQUE INDEX uniq_stock_courant_matiere (emplacement_id, matiere_premiere_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql("INSERT INTO stock_courant (emplacement_id, matiere_premiere_id, article_id, quantite, modifie_a)
            SELECT e.id, m.id, NULL, m.stock_actuel, NOW()
            FROM matiere_premiere m JOIN emplacement e ON e.code = 'DEPOT'");
        $this->addSql("INSERT INTO stock_courant (emplacement_id, matiere_premiere_id, article_id, quantite, modifie_a)
            SELECT e.id, NULL, a.id, a.stock_actuel, NOW()
            FROM article a JOIN emplacement e ON e.code = 'DEPOT'
            WHERE a.suivi_stock = 1 OR a.stock_actuel <> 0 OR EXISTS (SELECT 1 FROM mouvement_stock ms WHERE ms.article_id = a.id)");

        // Index puis clés étrangères.
        $this->addSql('CREATE INDEX IDX_61E2C8EBC4598A51 ON mouvement_stock (emplacement_id)');
        $this->addSql('CREATE INDEX IDX_61E2C8EBFB88E14F ON mouvement_stock (utilisateur_id)');
        $this->addSql('CREATE INDEX idx_mouvement_stock_document ON mouvement_stock (document_type, document_id)');
        $this->addSql('ALTER TABLE mouvement_stock ADD CONSTRAINT FK_61E2C8EBC4598A51 FOREIGN KEY (emplacement_id) REFERENCES emplacement (id)');
        $this->addSql('ALTER TABLE mouvement_stock ADD CONSTRAINT FK_61E2C8EBFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE stock_courant ADD CONSTRAINT FK_D2317199C4598A51 FOREIGN KEY (emplacement_id) REFERENCES emplacement (id)');
        $this->addSql('ALTER TABLE stock_courant ADD CONSTRAINT FK_D23171997294869C FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stock_courant ADD CONSTRAINT FK_D23171995B42BE3C FOREIGN KEY (matiere_premiere_id) REFERENCES matiere_premiere (id) ON DELETE CASCADE');
    }

    /**
     * Retour arrière possible tant qu'aucun stand n'a servi. Les types reviennent
     * à SORTIE_VENTE / ENTREE / INVENTAIRE — une ENTREE d'approvisionnement
     * redevient INVENTAIRE, sans conséquence sur les quantités. `stock_actuel`,
     * resté synchronisé, porte le stock du dépôt.
     */
    public function down(Schema $schema): void
    {
        $horsDepot = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM mouvement_stock ms JOIN emplacement e ON e.id = ms.emplacement_id
             WHERE e.code <> 'DEPOT' OR ms.type IN ('DOTATION', 'REAPPRO', 'RETOUR', 'VENTE_STAND')",
        );
        $this->abortIf($horsDepot > 0, 'Des mouvements de stand existent : le stock unique ne saurait pas les représenter.');

        $this->addSql('ALTER TABLE mouvement_stock DROP FOREIGN KEY FK_61E2C8EBC4598A51');
        $this->addSql('ALTER TABLE mouvement_stock DROP FOREIGN KEY FK_61E2C8EBFB88E14F');
        $this->addSql('DROP TABLE stock_courant');

        $this->addSql("DELETE FROM mouvement_stock WHERE document_type = 'reprise'");
        $this->addSql("UPDATE mouvement_stock SET type = 'SORTIE_VENTE' WHERE type = 'VENTE_CAISSE' AND quantite < 0");
        $this->addSql("UPDATE mouvement_stock SET type = 'ENTREE' WHERE type = 'VENTE_CAISSE'");
        $this->addSql("UPDATE mouvement_stock SET type = 'INVENTAIRE' WHERE type = 'AJUSTEMENT'");

        $this->addSql('DROP INDEX IDX_61E2C8EBC4598A51 ON mouvement_stock');
        $this->addSql('DROP INDEX IDX_61E2C8EBFB88E14F ON mouvement_stock');
        $this->addSql('DROP INDEX idx_mouvement_stock_document ON mouvement_stock');
        $this->addSql('ALTER TABLE mouvement_stock DROP emplacement_id, DROP utilisateur_id, CHANGE document_type source_type VARCHAR(50) DEFAULT NULL, CHANGE document_id source_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_mouvement_stock_source ON mouvement_stock (source_type, source_id)');
        $this->addSql('DROP TABLE emplacement');
    }
}
