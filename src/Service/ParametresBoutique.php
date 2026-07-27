<?php

namespace App\Service;

use App\Entity\Parametre;
use App\Enum\CleParametre;
use App\Repository\ParametreRepository;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lecture et écriture des paramètres de l'établissement.
 *
 * Source de vérité : la table `parametre`. Une clé absente retombe sur la valeur
 * par défaut du catalogue {@see CleParametre} — l'application fonctionne donc dès
 * la première installation, avant toute saisie.
 *
 * Les valeurs sont mémorisées pour la durée de la requête : un ticket lit une
 * dizaine de paramètres, il serait absurde de faire dix requêtes SQL.
 */
class ParametresBoutique
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParametreRepository $parametres,
    ) {
    }

    public function valeur(CleParametre $cle): string
    {
        return $this->toutes()[$cle->value] ?? $cle->valeurParDefaut();
    }

    /**
     * Toutes les valeurs courantes, défauts compris, indexées par clé.
     *
     * @return array<string, string>
     */
    public function toutes(): array
    {
        if (null !== $this->cache) {
            return $this->cache;
        }

        $valeurs = [];
        foreach (CleParametre::cases() as $cle) {
            $valeurs[$cle->value] = $cle->valeurParDefaut();
        }

        try {
            foreach ($this->parametres->findAll() as $parametre) {
                $valeurs[$parametre->getCle()] = $parametre->getValeur();
            }
        } catch (DbalException) {
            // Table absente (installation neuve, migration non jouée) : on sert les
            // valeurs par défaut plutôt que de faire échouer l'impression d'un ticket.
        }

        return $this->cache = $valeurs;
    }

    /**
     * Enregistre les valeurs saisies. Les clés inconnues du catalogue sont ignorées.
     *
     * @param array<string, string|null> $valeurs indexées par valeur de CleParametre
     */
    public function enregistrer(array $valeurs): void
    {
        $existants = $this->parametres->parCle();

        foreach (CleParametre::cases() as $cle) {
            if (!\array_key_exists($cle->value, $valeurs)) {
                continue;
            }

            $valeur = trim((string) $valeurs[$cle->value]);

            if (isset($existants[$cle->value])) {
                $existants[$cle->value]->definir($valeur);
            } else {
                $this->em->persist(new Parametre($cle->value, $valeur));
            }
        }

        $this->em->flush();
        $this->cache = null;
    }

    /**
     * Vue « ticket » des paramètres. Sert de fabrique au service
     * {@see ParametresTicket}, ce qui laisse inchangés tous ses consommateurs.
     */
    public function pourTicket(): ParametresTicket
    {
        $enseigne = $this->valeur(CleParametre::ENSEIGNE);
        $ville = $this->valeur(CleParametre::VILLE);
        $adresse = $this->valeur(CleParametre::ADRESSE);

        return new ParametresTicket(
            // L'enseigne prime en tête de ticket ; à défaut, la raison sociale.
            raisonSociale: '' !== $enseigne ? $enseigne : $this->valeur(CleParametre::RAISON_SOCIALE),
            adresse: trim($adresse.('' !== $ville ? ', '.$ville : ''), ', '),
            ncc: $this->valeur(CleParametre::NCC),
            telephone: $this->valeur(CleParametre::TELEPHONE),
            pied: $this->valeur(CleParametre::PIED_TICKET),
            rccm: $this->valeur(CleParametre::RCCM),
            email: $this->valeur(CleParametre::EMAIL),
        );
    }
}
