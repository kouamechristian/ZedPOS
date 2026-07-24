<?php

namespace App\Doctrine\DBAL;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Middleware DBAL enregistrant le driver de compatibilité MariaDB 10.4.
 */
final class MariaDbCompatMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new MariaDbCompatDriver($driver);
    }
}
