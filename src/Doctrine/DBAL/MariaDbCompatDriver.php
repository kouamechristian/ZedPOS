<?php

namespace App\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\ServerVersionProvider;

/**
 * Substitue {@see MariaDbCompatPlatform} à la plateforme MariaDB par défaut
 * pour contourner l'incompatibilité d'introspection des anciennes MariaDB 10.4.
 */
final class MariaDbCompatDriver extends AbstractDriverMiddleware
{
    public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
    {
        $platform = parent::getDatabasePlatform($versionProvider);

        if ($platform instanceof MariaDBPlatform && !$platform instanceof MariaDbCompatPlatform) {
            return new MariaDbCompatPlatform();
        }

        return $platform;
    }
}
