<?php

namespace App\Service;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Vie d'un compte utilisateur : **création** et **modification**.
 *
 * Point d'entrée **unique**, partagé par la commande `app:creer-utilisateur` et
 * le back-office (`/admin/utilisateurs/nouveau`, `/admin/utilisateurs/{id}/modifier`).
 * Les règles d'unicité (e-mail, code PIN), le hachage et la trace d'audit vivent
 * ici : dupliqués dans chaque appelant, ils auraient fini par diverger — et un PIN
 * en doublon rendrait deux caissiers indistinguables à la connexion.
 *
 * Le nom de la classe ne dit que la création parce qu'elle n'a longtemps fait que
 * cela ; c'est bien tout le cycle des identifiants qu'elle porte.
 */
class CreationUtilisateur
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PasswordHasherFactoryInterface $hasherFactory,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param string $secret Mot de passe en clair, ou code PIN à 4 chiffres si le
     *                       rôle se connecte au pavé numérique (caissier)
     *
     * @throws CreationUtilisateurException si l'e-mail ou le PIN est déjà pris,
     *                                      ou si le secret ne respecte pas les règles
     */
    public function creer(string $email, string $nom, RoleUtilisateur $role, string $secret): Utilisateur
    {
        $email = trim($email);
        $nom = trim($nom);

        if ('' === $email || '' === $nom) {
            throw new CreationUtilisateurException('L\'e-mail et le nom sont obligatoires.');
        }

        if (null !== $this->utilisateurs->findOneBy(['email' => $email])) {
            throw new CreationUtilisateurException(\sprintf('Un utilisateur existe déjà avec l\'e-mail « %s ».', $email));
        }

        $utilisateur = new Utilisateur($email, $nom);
        $utilisateur->setRoles([$role->value]);

        if ($role->utiliseCodePin()) {
            $this->definirCodePin($utilisateur, $secret);
        } else {
            $this->definirMotDePasse($utilisateur, $secret);
        }

        $this->em->persist($utilisateur);
        $this->em->flush();

        // Après le flush : l'id de l'utilisateur créé doit figurer au journal.
        $this->audit->utilisateurCree($utilisateur);

        return $utilisateur;
    }

    /**
     * Modifie un compte existant : nom, e-mail, rôle, et remise à zéro du secret.
     *
     * @param ?string $secret nouveau mot de passe ou code PIN ; `null` ou vide
     *                        conserve celui en place — on ne réinitialise pas un
     *                        identifiant par accident en corrigeant un nom
     *
     * @throws CreationUtilisateurException si l'e-mail est pris, si le secret est
     *                                      invalide, ou si le compte se retrouverait
     *                                      sans identifiant utilisable
     */
    public function modifier(
        Utilisateur $utilisateur,
        string $email,
        string $nom,
        RoleUtilisateur $role,
        ?string $secret = null,
    ): Utilisateur {
        $email = trim($email);
        $nom = trim($nom);
        $secret = trim((string) $secret);

        if ('' === $email || '' === $nom) {
            throw new CreationUtilisateurException('L\'e-mail et le nom sont obligatoires.');
        }

        $homonyme = $this->utilisateurs->findOneBy(['email' => $email]);
        if (null !== $homonyme && $homonyme !== $utilisateur) {
            throw new CreationUtilisateurException(\sprintf('Un utilisateur existe déjà avec l\'e-mail « %s ».', $email));
        }

        // Changer de rôle change de moyen de connexion. Sans nouveau secret, le
        // compte deviendrait inaccessible — on refuse avant d'avoir rien touché,
        // plutôt que d'enfermer quelqu'un dehors.
        $secretEnPlace = $role->utiliseCodePin() ? $utilisateur->getCodePin() : $utilisateur->getMotDePasse();
        if ('' === $secret && null === $secretEnPlace) {
            throw new CreationUtilisateurException($role->utiliseCodePin()
                ? 'Ce rôle se connecte au code PIN : saisissez-en un.'
                : 'Ce rôle se connecte par mot de passe : saisissez-en un.');
        }

        $avant = [
            'email' => $utilisateur->getEmail(),
            'nom' => $utilisateur->getNom(),
            'roles' => $utilisateur->getRoles(),
        ];

        $utilisateur->setEmail($email)->setNom($nom)->setRoles([$role->value]);

        if ('' !== $secret) {
            if ($role->utiliseCodePin()) {
                // `sauf` : sans lui, reconduire à l'identique le PIN d'un caissier
                // se heurterait à son propre hachage — « déjà utilisé » par lui-même.
                $this->definirCodePin($utilisateur, $secret, $utilisateur);
            } else {
                $this->definirMotDePasse($utilisateur, $secret);
            }
        }

        // Le secret devenu sans objet est **effacé**, pas laissé en place :
        // `CaisseAuthenticator` accepte tout compte actif porteur d'un code PIN.
        // Un caissier promu gérant qui garderait le sien continuerait d'ouvrir la
        // caisse au pavé numérique, sans que rien ne le signale.
        if ($role->utiliseCodePin()) {
            $utilisateur->setMotDePasse(null);
        } else {
            $utilisateur->setCodePin(null);
        }

        $this->em->flush();
        $this->audit->utilisateurModifie($utilisateur, $avant, '' !== $secret);

        return $utilisateur;
    }

    /**
     * Ce code PIN est-il déjà celui d'un caissier actif ?
     *
     * Les PIN sont hachés : impossible de les comparer en SQL, il faut les vérifier
     * un à un. Le nombre de caissiers actifs reste petit, l'opération est sans
     * conséquence sur les performances.
     *
     * @param ?Utilisateur $sauf compte à ignorer — celui qu'on est en train de
     *                           modifier n'entre pas en conflit avec lui-même
     */
    public function codePinDejaUtilise(string $pin, ?Utilisateur $sauf = null): bool
    {
        $hasher = $this->hasherFactory->getPasswordHasher(Utilisateur::class);

        foreach ($this->utilisateurs->findActifsAvecCodePin() as $utilisateur) {
            if ($utilisateur === $sauf) {
                continue;
            }

            $hash = $utilisateur->getCodePin();
            if (null !== $hash && $hasher->verify($hash, $pin)) {
                return true;
            }
        }

        return false;
    }

    private function definirCodePin(Utilisateur $utilisateur, string $pin, ?Utilisateur $sauf = null): void
    {
        if (1 !== preg_match('/^\d{4}$/', $pin)) {
            throw new CreationUtilisateurException('Le code PIN doit comporter exactement 4 chiffres.');
        }

        if ($this->codePinDejaUtilise($pin, $sauf)) {
            throw new CreationUtilisateurException('Ce code PIN est déjà utilisé par un autre caissier actif.');
        }

        $utilisateur->setCodePin($this->hasher->hashPassword($utilisateur, $pin));
    }

    private function definirMotDePasse(Utilisateur $utilisateur, string $motDePasse): void
    {
        if (\strlen($motDePasse) < 6) {
            throw new CreationUtilisateurException('Le mot de passe doit comporter au moins 6 caractères.');
        }

        $utilisateur->setMotDePasse($this->hasher->hashPassword($utilisateur, $motDePasse));
    }
}
