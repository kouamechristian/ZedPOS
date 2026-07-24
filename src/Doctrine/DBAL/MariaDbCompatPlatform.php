<?php

namespace App\Doctrine\DBAL;

use Doctrine\DBAL\Platforms\MariaDBPlatform;

/**
 * Plateforme MariaDB compatible avec les anciennes versions 10.4 (ex. XAMPP)
 * dont `information_schema.CHECK_CONSTRAINTS` ne possède pas encore la colonne
 * `TABLE_NAME`.
 *
 * La plateforme MariaDB de Doctrine DBAL 4 génère, pour l'introspection des
 * colonnes, un sous-select sur `CHECK_CONSTRAINTS.TABLE_NAME` (afin de détecter
 * les colonnes JSON stockées en LONGTEXT). Cette colonne étant absente sur
 * MariaDB 10.4.32, toute introspection échoue (`Unknown column 'i_c.TABLE_NAME'`),
 * ce qui casse `doctrine:migrations:migrate` et `make:migration`.
 *
 * On remplace ce fragment par une simple lecture du type de colonne. Les colonnes
 * JSON sont alors introspectées comme LONGTEXT — sans incidence ici, les migrations
 * étant écrites explicitement plutôt que générées par diff.
 */
final class MariaDbCompatPlatform extends MariaDBPlatform
{
    public function getColumnTypeSQLSnippet(string $tableAlias, string $databaseName): string
    {
        return $tableAlias . '.DATA_TYPE';
    }
}
