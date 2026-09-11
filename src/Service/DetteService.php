<?php

namespace App\Service;

use App\Entity\Arrete;
use App\Entity\DetteVendeur;
use App\Entity\RemboursementDette;
use App\Entity\Utilisateur;
use App\Entity\Vendeur;
use App\Enum\ModeReglement;
use App\Enum\TraitementEcart;
use App\Enum\TypeDette;
use App\Repository\DetteVendeurRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Dettes des vendeurs : ouverture, remboursement, annulation.
 *
 * **Jamais de création silencieuse.** Un manquant au point ne devient une dette
 * qu'à la validation d'un arrêté dont la gérante a choisi
 * {@see TraitementEcart::DETTE} — `ArreteService` appelle alors
 * {@see self::ouvrirDepuisArrete()} dans sa propre transaction. Une avance ou une
 * autre dette se saisit à la main, commentaire obligatoire.
 *
 * **Un versement rembourse les dettes les plus anciennes d'abord**, réparti sur
 * autant de dettes qu'il en faut, et jamais au-delà du solde : un trop-versé se
 * rend au vendeur, il ne s'inscrit pas en avoir.
 *
 * Toutes les écritures passent au journal d'audit.
 */
class DetteService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DetteVendeurRepository $dettes,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Le manquant d'un point validé, imputé au vendeur. À appeler **dans la
     * transaction de la validation** : si elle échoue, la dette disparaît avec elle.
     *
     * @throws \LogicException le point n'est pas validé avec un manquant imputé en dette
     */
    public function ouvrirDepuisArrete(Arrete $arrete, Utilisateur $auteur): DetteVendeur
    {
        $ecart = (int) $arrete->getEcart();
        if (!$arrete->estValide() || $ecart >= 0 || TraitementEcart::DETTE !== $arrete->getTraitementEcart()) {
            throw new \LogicException(\sprintf('Le point %s n\'impute aucun manquant en dette.', $arrete->getNumero()));
        }

        $dette = new DetteVendeur(
            $arrete->getVendeur(),
            TypeDette::ECART_CAISSE,
            -$ecart,
            $arrete->getCommentaireEcart() ?? 'Manquant au point '.$arrete->getNumero(),
            $auteur,
            $arrete,
        );

        $this->em->persist($dette);
        $this->em->flush();
        $this->audit->detteCreee($dette, $auteur);

        return $dette;
    }

    /**
     * Avance ou autre dette, saisie sur la fiche du vendeur.
     *
     * @param int $montant centimes
     *
     * @throws \DomainException type réservé au point, montant, commentaire manquant
     */
    public function ouvrirManuelle(Vendeur $vendeur, TypeDette $type, int $montant, ?string $commentaire, Utilisateur $auteur): DetteVendeur
    {
        if (!\in_array($type, TypeDette::manuelles(), true)) {
            throw new \DomainException('Un manquant au point ne se saisit pas à la main : il naît de la validation du point.');
        }

        $dette = new DetteVendeur($vendeur, $type, $montant, $commentaire, $auteur);

        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();

        try {
            $this->em->persist($dette);
            $this->em->flush();
            $this->audit->detteCreee($dette, $auteur);
            $connexion->commit();
        } catch (\Throwable $e) {
            $connexion->rollBack();
            if ($this->em->isOpen() && $this->em->contains($dette)) {
                $this->em->detach($dette);
            }

            throw $e;
        }

        return $dette;
    }

    /**
     * Encaisse un versement du vendeur sur ses dettes dues, les plus anciennes
     * d'abord.
     *
     * @param int $montant centimes
     *
     * @return list<RemboursementDette> un remboursement par dette touchée
     *
     * @throws \DomainException montant nul, au-delà du solde, moyen à crédit, date future
     */
    public function rembourser(Vendeur $vendeur, int $montant, ModeReglement $moyen, \DateTimeImmutable $date, Utilisateur $encaissePar, ?\DateTimeImmutable $aujourdhui = null): array
    {
        $aujourdhui = ($aujourdhui ?? new \DateTimeImmutable('today'))->setTime(0, 0);

        if ($montant <= 0) {
            throw new \DomainException('Le remboursement doit être strictement positif.');
        }
        if (ModeReglement::CREDIT === $moyen) {
            throw new \DomainException('On ne rembourse pas une dette à crédit.');
        }
        if ($date->setTime(0, 0) > $aujourdhui) {
            throw new \DomainException('La date du remboursement ne peut pas être dans le futur.');
        }

        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();
        $dues = [];

        try {
            // Verrouillées : deux encaissements simultanés ne rembourseraient pas
            // deux fois le même reste. Relues après verrou, pas depuis la mémoire.
            $dues = $this->dettes->duesDe($vendeur, verrou: true);
            foreach ($dues as $dette) {
                $this->em->refresh($dette);
            }

            $solde = array_sum(array_map(static fn (DetteVendeur $d): int => $d->reste(), $dues));
            if ($montant > $solde) {
                throw new \DomainException(0 === $solde
                    ? \sprintf('%s ne doit rien : il n\'y a rien à rembourser.', $vendeur->getNom())
                    : \sprintf('%s ne doit que %s FCFA : le versement ne peut pas dépasser ce solde.', $vendeur->getNom(), number_format(intdiv($solde, 100), 0, ',', ' ')));
            }

            $aImputer = $montant;
            $remboursements = [];
            foreach ($dues as $dette) {
                if (0 === $aImputer) {
                    break;
                }

                $part = min($aImputer, $dette->reste());
                $remboursements[] = $dette->encaisser($part, $moyen, $date, $encaissePar);
                $aImputer -= $part;
            }

            $this->em->flush();
            $this->audit->detteRemboursee($vendeur, $remboursements, $solde, $solde - $montant, $encaissePar);
            $connexion->commit();
        } catch (\Throwable $e) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }
            // Les restes ont pu avancer en mémoire avant l'échec.
            if ($this->em->isOpen()) {
                foreach ($dues as $dette) {
                    foreach ($dette->getRemboursements() as $remboursement) {
                        if (null === $remboursement->getId() && $this->em->contains($remboursement)) {
                            $this->em->detach($remboursement);
                        }
                    }
                    if ($this->em->contains($dette)) {
                        $this->em->refresh($dette);
                    }
                }
            }

            throw $e;
        }

        return $remboursements;
    }

    /**
     * Annule la dette ouverte par un point que l'on annule. À appeler **dans la
     * transaction de l'annulation**.
     *
     * @throws \DomainException la dette a déjà reçu un remboursement
     */
    public function annulerDepuisArrete(DetteVendeur $dette, Utilisateur $auteur): void
    {
        $avant = $this->audit->etatDette($dette);
        $dette->annuler(new \DateTimeImmutable());
        $this->em->flush();
        $this->audit->detteAnnulee($dette, $avant, $auteur);
    }

    public function soldeDe(Vendeur $vendeur): int
    {
        return $this->dettes->soldeDe($vendeur);
    }
}
