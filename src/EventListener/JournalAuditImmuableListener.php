<?php

namespace App\EventListener;

use App\Entity\JournalAudit;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Rend le journal d'audit inaltérable au niveau de l'ORM.
 *
 * Une entrée de {@see JournalAudit} ne peut être qu'**insérée** : toute tentative
 * de mise à jour ou de suppression échoue, y compris via du code applicatif ou
 * une console interactive. C'est le filet de sécurité qui complète l'absence de
 * setter sur l'entité et l'absence de route d'écriture.
 *
 * Cela n'empêche évidemment pas un accès SQL direct à la base : l'inaltérabilité
 * est garantie côté application, pas côté serveur MariaDB.
 */
#[AsDoctrineListener(event: Events::onFlush)]
class JournalAuditImmuableListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entite) {
            if ($entite instanceof JournalAudit) {
                throw new \DomainException("Le journal d'audit est inaltérable : une entrée ne peut pas être modifiée.");
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entite) {
            if ($entite instanceof JournalAudit) {
                throw new \DomainException("Le journal d'audit est inaltérable : une entrée ne peut pas être supprimée.");
            }
        }
    }
}
