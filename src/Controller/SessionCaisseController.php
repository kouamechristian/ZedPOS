<?php

namespace App\Controller;

use App\Controller\Trait\ReponseFormulaire;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Form\ClotureCaisseType;
use App\Form\MouvementCaisseType;
use App\Form\OuvertureCaisseType;
use App\Repository\MouvementCaisseRepository;
use App\Service\RapportCaisseService;
use App\Service\SessionCaisseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Cycle de caisse côté caissier : ouverture, dépenses, ticket X, clôture Z.
 */
#[Route('/caisse/session')]
#[IsGranted('ROLE_CAISSIER')]
class SessionCaisseController extends AbstractController
{
    use ReponseFormulaire;

    /**
     * Ouverture de session : saisie du fond de caisse. Une seule session active
     * par caissier — s'il en a déjà une, on le renvoie directement à la caisse.
     */
    #[Route('/ouverture', name: 'app_caisse_ouverture', methods: ['GET', 'POST'])]
    public function ouverture(Request $request, SessionCaisseService $service): Response
    {
        if (null !== $service->sessionOuverte($this->utilisateur())) {
            return $this->redirectToRoute('app_caisse');
        }

        $form = $this->createForm(OuvertureCaisseType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $service->ouvrir($this->utilisateur(), (int) $form->getData()['fondCaisse']);

                return $this->redirectToRoute('app_caisse');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->rendreFormulaire('caisse/ouverture.html.twig', $form);
    }

    /**
     * Saisie d'une dépense de caisse ou d'une sortie d'espèces.
     */
    #[Route('/depense', name: 'app_caisse_depense', methods: ['GET', 'POST'])]
    public function depense(
        Request $request,
        SessionCaisseService $service,
        MouvementCaisseRepository $mouvements,
    ): Response {
        $session = $service->sessionOuverte($this->utilisateur());
        if (null === $session) {
            return $this->redirectToRoute('app_caisse_ouverture');
        }

        $form = $this->createForm(MouvementCaisseType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $donnees = $form->getData();
            try {
                $service->enregistrerMouvement(
                    $session,
                    $this->utilisateur(),
                    $donnees['type'],
                    (int) $donnees['montant'],
                    $donnees['categorie'],
                    $donnees['commentaire'],
                );
                $this->addFlash('success', 'Mouvement de caisse enregistré.');

                return $this->redirectToRoute('app_caisse_depense', [], Response::HTTP_SEE_OTHER);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->rendreFormulaire('caisse/depense.html.twig', $form, [
            'session' => $session,
            'mouvements' => $mouvements->pourSession($session),
        ]);
    }

    /**
     * Ticket X : synthèse intermédiaire imprimable. Ne clôture rien.
     */
    #[Route('/x', name: 'app_caisse_ticket_x', methods: ['GET'])]
    public function ticketX(Request $request, SessionCaisseService $service): Response
    {
        $session = $service->sessionOuverte($this->utilisateur());
        if (null === $session) {
            return $this->redirectToRoute('app_caisse_ouverture');
        }

        return $this->render('caisse/rapport.html.twig', [
            'rapport' => $service->ticketX($session),
            'auto' => $request->query->getBoolean('auto'),
        ]);
    }

    /**
     * Clôture Z : saisie du montant compté, calcul du théorique et de l'écart.
     */
    #[Route('/cloture', name: 'app_caisse_cloture', methods: ['GET', 'POST'])]
    public function cloture(Request $request, SessionCaisseService $service, RapportCaisseService $rapports): Response
    {
        $session = $service->sessionOuverte($this->utilisateur());
        if (null === $session) {
            return $this->redirectToRoute('app_caisse_ouverture');
        }

        $form = $this->createForm(ClotureCaisseType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $donnees = $form->getData();
            try {
                $service->cloturer($session, (int) $donnees['montantCompte'], $donnees['commentaire']);

                return $this->redirectToRoute('app_caisse_rapport_z', ['id' => $session->getId()]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->rendreFormulaire('caisse/cloture.html.twig', $form, [
            'rapport' => $rapports->construire($session, false),
        ]);
    }

    /**
     * Rapport Z d'une session clôturée (impression et réimpression).
     * Un caissier ne consulte que ses propres sessions ; le gérant les voit toutes.
     */
    #[Route('/z/{id}', name: 'app_caisse_rapport_z', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function rapportZ(SessionCaisse $session, Request $request, SessionCaisseService $service): Response
    {
        if ($session->getUtilisateur() !== $this->getUser() && !$this->isGranted('ROLE_GERANT')) {
            throw $this->createAccessDeniedException('Cette session de caisse ne vous appartient pas.');
        }

        return $this->render('caisse/rapport.html.twig', [
            'rapport' => $service->rapportZ($session),
            'auto' => $request->query->getBoolean('auto'),
        ]);
    }

    private function utilisateur(): Utilisateur
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        return $utilisateur;
    }
}
