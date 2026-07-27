<?php

namespace App\Repository;

use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<Utilisateur>
 *
 * @implements PasswordUpgraderInterface<Utilisateur>
 */
class UtilisateurRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Utilisateur::class);
    }

    /**
     * Comptes paginés, actifs d'abord puis par nom — on cherche plus souvent un
     * compte en service qu'un compte désactivé.
     *
     * @return Pagination<Utilisateur>
     */
    /**
     * L'application n'a-t-elle encore **aucun compte** ?
     *
     * C'est la question de l'amorçage : tant qu'elle est vraie, il n'existe
     * personne pour se connecter, et l'écran d'installation prend la main.
     *
     * `LIMIT 1` et non un `COUNT(*)` : on ne veut pas dénombrer, seulement savoir
     * s'il en existe au moins un — la requête s'arrête à la première ligne.
     */
    public function aucunCompte(): bool
    {
        return null === $this->createQueryBuilder('u')
            ->select('u.id')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function pagines(int $page = 1, ?string $recherche = null): Pagination
    {
        $qb = $this->createQueryBuilder('u')->orderBy('u.actif', 'DESC')->addOrderBy('u.nom', 'ASC');

        // Le rôle est cherché sur sa valeur brute (`ROLE_CAISSIER`) : la colonne
        // stocke un JSON, taper « caissier » y retombe donc quand même.
        Recherche::appliquer($qb, $recherche, 'u.nom', 'u.email', 'u.roles');

        return Pagination::depuis($qb, $page);
    }

    /**
     * Utilisateurs actifs disposant d'un code PIN (candidats à la connexion caisse).
     *
     * @return Utilisateur[]
     */
    public function findActifsAvecCodePin(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.actif = true')
            ->andWhere('u.codePin IS NOT NULL')
            ->getQuery()
            ->getResult();
    }

    /**
     * Réhache automatiquement le code PIN lorsque nécessaire.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof Utilisateur) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setCodePin($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }
}
