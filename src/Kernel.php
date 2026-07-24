<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // Fuseau horaire de l'application : Abengourou, Côte d'Ivoire.
        date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Africa/Abidjan');

        parent::boot();
    }
}
