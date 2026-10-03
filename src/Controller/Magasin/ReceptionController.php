<?php

namespace App\Controller\Magasin;

use App\Entity\Fournisseur;
use App\Entity\Magasin\MagasinReception;
use App\Entity\Utilisateur;
use App\Enum\Magasin\MotifRejetMagasin;
use App\Enum\Magasin\StatutReceptionMagasin;
use App\Repository\FournisseurRepository;
use App\Repository\Magasin\MagasinEmplacementRepository;
use App\Repository\Magasin\MagasinProduitRepository;
use App\Repository\Magasin\MagasinReceptionRepository;
use App\Security\Permission;
use App\Service\Magasin\QuantiteMagasin;
use App\Service\Magasin\ReceptionMagasinService;
use App\Service\Magasin\SaisieQuantite;
use App\Service\Magasin\StockMagasinInsuffisantException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réception → Contrôle → Inspection → Stockage : un écran par étape, au doigt.
 *
 * Pas de formulaire Symfony : chaque écran poste des tableaux indexés par ligne
 * (`comptees[id]`, `decisions[id][acceptee]`…) que le service lit en millièmes,
 * sans flottant. Une erreur réaffiche l'écran en **422 avec ce qui a été tapé**,
 * comme l'écran de dotation.
 *
 * Les prix n'existent qu'avec MAGASIN_VOIR_PRIX (dirigeante) : sans elle, les
 * champs ne sont pas rendus, et une saisie forgée est **ignorée** — même règle
 * que le prix de vente d'un article.
 */
#[Route('/magasin/receptions')]
#[IsGranted(Permission::MAGASIN_VOIR)]
class ReceptionController extends AbstractController
{
    public function __construct(
        private readonly MagasinReceptionRepository $receptions,
        private readonly MagasinProduitRepository $produits,
        private readonly MagasinEmplacementRepository $emplacements,
        private readonly FournisseurRepository $fournisseurs,
        private readonly ReceptionMagasinService $service,
    ) {
    }

    #[Route('', name: 'magasin_reception_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $statut = StatutReceptionMagasin::tryFrom((string) $request->query->get('statut'));

        return $this->render('magasin/reception/index.html.twig', [
            'receptions' => $this->receptions->pagines(max(1, $request->query->getInt('page', 1)), $request->query->get('q'), $statut),
            'statut' => $statut,
            'statuts' => StatutReceptionMagasin::cases(),
            'comptes' => $this->receptions->compteParStatut(),
        ]);
    }

    // ------------------------------------------------------------ Étape 1

    #[Route('/nouvelle', name: 'magasin_reception_new', methods: ['GET', 'POST'])]
    #[IsGranted(Permission::MAGASIN_GERER)]
    public function nouvelle(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->verifierJeton($request, 'reception_nouvelle');
            try {
                [$fournisseur, $date, $bl, $commentaire, $lignes] = $this->lireEntete($request);
                $reception = $this->service->creer($fournisseur, $date, $bl, $commentaire, $lignes, $this->auteur());
                $this->addFlash('success', \sprintf('Réception %s enregistrée. Étape suivante : le contrôle.', $reception->getNumero()));

                return $this->redirectToRoute('magasin_reception_controle', ['id' => $reception->getId()], Response::HTTP_SEE_OTHER);
            } catch (\DomainException $e) {
                return $this->ecranAnnonce(null, $request, $e->getMessage());
            }
        }

        return $this->ecranAnnonce(null, $request);
    }

    #[Route('/{id}/annonce', name: 'magasin_reception_annonce', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Permission::MAGASIN_GERER)]
    public function annonce(Request $request, int $id): Response
    {
        $reception = $this->charger($id);
        if (StatutReceptionMagasin::RECUE !== $reception->getStatut()) {
            return $this->versEtapeCourante($reception, 'L\'annonce ne se corrige plus une fois le contrôle fait.');
        }

        if ($request->isMethod('POST')) {
            $this->verifierJeton($request, 'reception_'.$id);
            try {
                [$fournisseur, $date, $bl, $commentaire, $lignes] = $this->lireEntete($request);
                $this->service->corrigerAnnonce($reception, $fournisseur, $date, $bl, $commentaire, $lignes, $this->auteur());
                $this->addFlash('success', 'Annonce corrigée.');

                return $this->redirectToRoute('magasin_reception_controle', ['id' => $id], Response::HTTP_SEE_OTHER);
            } catch (\DomainException $e) {
                return $this->ecranAnnonce($reception, $request, $e->getMessage());
            }
        }

        return $this->ecranAnnonce($reception, $request);
    }

    // ------------------------------------------------------------ Étape 2

    #[Route('/{id}/controle', name: 'magasin_reception_controle', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Permission::MAGASIN_GERER)]
    public function controle(Request $request, int $id): Response
    {
        $reception = $this->charger($id);
        if (!\in_array($reception->getStatut(), [StatutReceptionMagasin::RECUE, StatutReceptionMagasin::CONTROLEE], true)) {
            return $this->versEtapeCourante($reception, 'Le contrôle ne se refait plus une fois l\'inspection faite.');
        }

        $erreur = null;
        if ($request->isMethod('POST')) {
            $this->verifierJeton($request, 'reception_'.$id);
            try {
                $comptees = $this->service->lireComptages($reception, $this->tableau($request, 'comptees'));
                $this->service->controler($reception, $comptees, $request->request->getString('commentaire'), $this->auteur());
                $this->addFlash('success', 'Contrôle enregistré. Étape suivante : l\'inspection.');

                return $this->redirectToRoute('magasin_reception_inspection', ['id' => $id], Response::HTTP_SEE_OTHER);
            } catch (\DomainException $e) {
                $erreur = $e->getMessage();
            }
        }

        return $this->render('magasin/reception/controle.html.twig', [
            'reception' => $reception,
            'erreur' => $erreur,
            'saisie' => $request->isMethod('POST') ? $this->tableau($request, 'comptees') : null,
            'commentaire' => $request->isMethod('POST') ? $request->request->getString('commentaire') : $reception->getCommentaireControle(),
        ], new Response(status: null !== $erreur ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    // ------------------------------------------------------------ Étape 3

    #[Route('/{id}/inspection', name: 'magasin_reception_inspection', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Permission::MAGASIN_GERER)]
    public function inspection(Request $request, int $id): Response
    {
        $reception = $this->charger($id);
        if (!\in_array($reception->getStatut(), [StatutReceptionMagasin::CONTROLEE, StatutReceptionMagasin::INSPECTEE], true)) {
            return $this->versEtapeCourante($reception, StatutReceptionMagasin::RECUE === $reception->getStatut()
                ? 'Faites d\'abord le contrôle.'
                : 'L\'inspection ne se refait plus une fois le stockage fait.');
        }

        $erreur = null;
        if ($request->isMethod('POST')) {
            $this->verifierJeton($request, 'reception_'.$id);
            try {
                $decisions = $this->service->lireDecisions($reception, $this->tableau($request, 'decisions'));
                $this->service->inspecter($reception, $decisions, $this->auteur());
                $this->addFlash('success', 'Inspection enregistrée. Étape suivante : le stockage.');

                return $this->redirectToRoute('magasin_reception_stockage', ['id' => $id], Response::HTTP_SEE_OTHER);
            } catch (\DomainException $e) {
                $erreur = $e->getMessage();
            }
        }

        return $this->render('magasin/reception/inspection.html.twig', [
            'reception' => $reception,
            'erreur' => $erreur,
            'saisie' => $request->isMethod('POST') ? $this->tableau($request, 'decisions') : null,
            'motifs' => MotifRejetMagasin::cases(),
        ], new Response(status: null !== $erreur ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    // ------------------------------------------------------------ Étape 4

    #[Route('/{id}/stockage', name: 'magasin_reception_stockage', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Permission::MAGASIN_GERER)]
    public function stockage(Request $request, int $id): Response
    {
        $reception = $this->charger($id);
        if (StatutReceptionMagasin::INSPECTEE !== $reception->getStatut()) {
            return $this->versEtapeCourante($reception, $reception->getStatut()->rang() < StatutReceptionMagasin::INSPECTEE->rang()
                ? 'Le stockage n\'a lieu qu\'après l\'inspection.'
                : 'Cette réception est déjà rangée.');
        }

        $erreur = null;
        if ($request->isMethod('POST')) {
            $this->verifierJeton($request, 'reception_'.$id);
            try {
                if ($this->isGranted(Permission::MAGASIN_VOIR_PRIX)) {
                    $this->service->fixerPrix($reception, $this->service->lirePrix($reception, $this->tableau($request, 'prix')));
                }
                $rangement = $this->service->lireRangement($reception, $this->tableau($request, 'emplacements'));
                $this->service->stocker($reception, $rangement, $this->auteur());
                $this->addFlash('success', \sprintf('Réception %s stockée : les quantités acceptées sont entrées en stock.', $reception->getNumero()));

                return $this->redirectToRoute('magasin_reception_show', ['id' => $id], Response::HTTP_SEE_OTHER);
            } catch (\DomainException $e) {
                $erreur = $e->getMessage();
            }
        }

        return $this->render('magasin/reception/stockage.html.twig', [
            'reception' => $reception,
            'erreur' => $erreur,
            'saisie' => $request->isMethod('POST') ? $this->tableau($request, 'emplacements') : null,
            'saisie_prix' => $request->isMethod('POST') ? $this->tableau($request, 'prix') : null,
            'emplacements' => $this->emplacements->actifs(),
        ], new Response(status: null !== $erreur ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    // ------------------------------------------------------------ Lecture

    #[Route('/{id}', name: 'magasin_reception_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        return $this->render('magasin/reception/show.html.twig', ['reception' => $this->charger($id)]);
    }

    #[Route('/{id}/bon', name: 'magasin_reception_bon', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function bon(int $id): Response
    {
        return $this->render('magasin/reception/impression.html.twig', ['reception' => $this->charger($id)]);
    }

    #[Route('/{id}/annuler', name: 'magasin_reception_annuler', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Permission::MAGASIN_GERER)]
    public function annuler(Request $request, int $id): Response
    {
        $reception = $this->charger($id);
        $this->verifierJeton($request, 'reception_'.$id);

        try {
            $this->service->annuler($reception, $request->request->getString('motif'), $this->auteur());
            $this->addFlash('success', $reception->aEteStockee()
                ? \sprintf('Réception %s annulée : ses entrées en stock sont contre-passées.', $reception->getNumero())
                : \sprintf('Réception %s annulée.', $reception->getNumero()));
        } catch (StockMagasinInsuffisantException $e) {
            $this->addFlash('error', 'Annulation refusée — une partie de la marchandise est déjà sortie. '.$e->getMessage());
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('magasin_reception_show', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    // ------------------------------------------------------------ Interne

    private function ecranAnnonce(?MagasinReception $reception, Request $request, ?string $erreur = null): Response
    {
        $voirPrix = $this->isGranted(Permission::MAGASIN_VOIR_PRIX);
        $posted = $request->isMethod('POST');

        if ($posted) {
            $lignes = array_values(array_filter($this->tableau($request, 'lignes'), 'is_array'));
            $entete = [
                'fournisseur' => $request->request->getString('fournisseur'),
                'date' => $request->request->getString('date'),
                'bonLivraison' => $request->request->getString('bon_livraison'),
                'commentaire' => $request->request->getString('commentaire'),
            ];
        } else {
            $lignes = null === $reception ? [] : array_map(static fn ($l): array => [
                'produit' => (string) $l->getProduit()->getId(),
                'annoncee' => SaisieQuantite::texte($l->getQteAnnoncee()),
                'prix' => null !== $l->getPrixUnitaire() ? (string) intdiv($l->getPrixUnitaire(), 100) : '',
            ], $reception->getLignes()->toArray());
            $entete = [
                'fournisseur' => null !== $reception ? (string) $reception->getFournisseur()->getId() : '',
                'date' => ($reception?->getDateReception() ?? new \DateTimeImmutable('today'))->format('Y-m-d'),
                'bonLivraison' => (string) $reception?->getBonLivraison(),
                'commentaire' => (string) $reception?->getCommentaire(),
            ];
        }
        if ([] === $lignes) {
            $lignes = [['produit' => '', 'annoncee' => '', 'prix' => '']];
        }

        $produits = $this->produits->actifs();
        // Une ligne déjà enregistrée sur un produit désactivé depuis reste lisible.
        foreach ($reception?->getLignes() ?? [] as $ligne) {
            if (!\in_array($ligne->getProduit(), $produits, true)) {
                $produits[] = $ligne->getProduit();
            }
        }

        return $this->render('magasin/reception/annonce.html.twig', [
            'reception' => $reception,
            'erreur' => $erreur,
            'entete' => $entete,
            'lignes' => $lignes,
            'voir_prix' => $voirPrix,
            'fournisseurs' => $this->fournisseurs->findBy(['actif' => true], ['nom' => 'ASC']),
            'produits' => $produits,
            'unites' => array_combine(
                array_map(static fn ($p): int => (int) $p->getId(), $produits),
                array_map(static fn ($p): string => $p->aUneUniteAchat()
                    ? \sprintf('%s de %s %s', $p->getUniteAchat(), QuantiteMagasin::nombre((int) $p->getContenanceAchat()), $p->getUniteStock())
                    : $p->getUniteStock(), $produits),
            ),
        ], new Response(status: null !== $erreur ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * @return array{0: Fournisseur, 1: \DateTimeImmutable, 2: ?string, 3: ?string, 4: list<array<string, mixed>>}
     *
     * @throws \DomainException
     */
    private function lireEntete(Request $request): array
    {
        $fournisseur = $this->fournisseurs->find($request->request->getInt('fournisseur'));
        if (null === $fournisseur) {
            throw new \DomainException('Choisissez le fournisseur.');
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->request->getString('date'));
        if (false === $date || $date->format('Y-m-d') !== $request->request->getString('date')) {
            throw new \DomainException('Date de réception illisible.');
        }
        if ($date > new \DateTimeImmutable('today')) {
            throw new \DomainException('La date de réception ne peut pas être dans le futur.');
        }

        $lignes = $this->service->lireAnnonce($this->tableau($request, 'lignes'), $this->isGranted(Permission::MAGASIN_VOIR_PRIX));

        return [$fournisseur, $date, $request->request->getString('bon_livraison'), $request->request->getString('commentaire'), $lignes];
    }

    /** @return array<int|string, mixed> */
    private function tableau(Request $request, string $cle): array
    {
        $valeur = $request->request->all()[$cle] ?? [];

        return \is_array($valeur) ? $valeur : [];
    }

    private function charger(int $id): MagasinReception
    {
        return $this->receptions->avecLignes($id) ?? throw new NotFoundHttpException('Réception introuvable.');
    }

    /** Une étape demandée hors de son tour ramène à la fiche, avec la raison. */
    private function versEtapeCourante(MagasinReception $reception, string $message): Response
    {
        $this->addFlash('error', $message);

        return $this->redirectToRoute('magasin_reception_show', ['id' => $reception->getId()], Response::HTTP_SEE_OTHER);
    }

    private function verifierJeton(Request $request, string $intention): void
    {
        if (!$this->isCsrfTokenValid($intention, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide : rechargez la page.');
        }
    }

    private function auteur(): Utilisateur
    {
        $utilisateur = $this->getUser();
        \assert($utilisateur instanceof Utilisateur);

        return $utilisateur;
    }
}
