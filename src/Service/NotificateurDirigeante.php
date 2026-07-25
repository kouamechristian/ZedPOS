<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\RoleUtilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Alerte la dirigeante des événements qu'elle doit connaître sans avoir à les
 * chercher — aujourd'hui, les annulations de ventes encaissées.
 *
 * Notification **en base**, relevée dans l'espace de pilotage : la dirigeante le
 * consulte depuis son téléphone, c'est le canal qu'elle regarde réellement. Un
 * envoi par e-mail dépendrait d'une boîte relevée et d'une connexion sortante.
 */
class NotificateurDirigeante
{
    public const TYPE_VENTE_ANNULEE = 'VENTE_ANNULEE';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Une vente encaissée vient d'être annulée par un gérant.
     */
    public function venteAnnulee(Vente $vente, string $motif, ?Utilisateur $auteur): Notification
    {
        $montant = number_format(intdiv($vente->getTotalTtc(), 100), 0, ',', ' ');

        $notification = new Notification(
            roleDestinataire: RoleUtilisateur::DIRIGEANTE->value,
            type: self::TYPE_VENTE_ANNULEE,
            titre: \sprintf('Vente %s annulée (%s FCFA)', $vente->getNumero(), $montant),
            message: \sprintf(
                'Ticket %s du %s, encaissé par %s, annulé par %s. Motif : %s',
                $vente->getNumero(),
                $vente->getCreatedAt()->format('d/m/Y à H:i'),
                $vente->getSessionCaisse()->getUtilisateur()->getNom(),
                $auteur?->getNom() ?? 'un gérant',
                $motif,
            ),
            lien: '/pilotage/ventes/'.$vente->getUuid(),
        );

        $this->em->persist($notification);
        $this->em->flush();

        return $notification;
    }
}
