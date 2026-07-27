<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Repository\ArticleRepository;
use App\Repository\FamilleProduitRepository;
use App\Repository\SessionCaisseRepository;
use App\Service\ImageArticle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Écran de caisse et catalogue hors ligne.
 *
 * L'enregistrement d'une vente ne passe **que** par POST /api/vente, seul point
 * d'entrée idempotent : c'est ce qui permet à la file de synchronisation de
 * rejouer une vente sans risque de doublon. Il n'existe volontairement plus de
 * second chemin d'écriture.
 */
#[Route('/caisse')]
#[IsGranted('ROLE_CAISSIER')]
class CaisseController extends AbstractController
{
    #[Route('', name: 'app_caisse', methods: ['GET'])]
    public function index(
        FamilleProduitRepository $familles,
        ArticleRepository $articles,
        SessionCaisseRepository $sessions,
    ): Response {
        // Pas de vente sans session ouverte : le fond de caisse doit être saisi.
        $session = $sessions->ouvertePour($this->utilisateur());
        if (null === $session) {
            return $this->redirectToRoute('app_caisse_ouverture');
        }

        $catalogue = [];
        foreach ($familles->findBy(['actif' => true], ['position' => 'ASC']) as $famille) {
            $liste = $articles->findBy(
                ['familleProduit' => $famille, 'actif' => true],
                ['positionCaisse' => 'ASC'],
            );
            if ([] !== $liste) {
                $catalogue[] = ['famille' => $famille, 'articles' => $liste];
            }
        }

        return $this->render('caisse/index.html.twig', [
            'catalogue' => $catalogue,
            'session' => $session,
        ]);
    }

    /**
     * Catalogue complet servi au format JSON, destiné à être stocké en IndexedDB
     * par l'écran de caisse : c'est lui qui permet d'afficher les touches produits
     * quand la connexion est coupée.
     *
     * Les prix restent purement indicatifs côté client — l'encaissement les
     * recalcule toujours côté serveur.
     */
    #[Route('/catalogue.json', name: 'app_caisse_catalogue', methods: ['GET'])]
    public function catalogue(FamilleProduitRepository $familles, ArticleRepository $articles, ImageArticle $images): JsonResponse
    {
        $donnees = [];

        foreach ($familles->findBy(['actif' => true], ['position' => 'ASC']) as $famille) {
            $liste = [];
            foreach ($articles->findBy(
                ['familleProduit' => $famille, 'actif' => true],
                ['positionCaisse' => 'ASC'],
            ) as $article) {
                $liste[] = [
                    'id' => $article->getId(),
                    'nom' => $article->getNom(),
                    'prix' => $article->getPrixVenteTtc(),
                    'tva' => $article->getTauxTva(),
                    'couleur' => $article->getCouleur(),
                    // Chemin public, pas le nom de fichier : le client s'en sert
                    // tel quel dans un `src`, et le Service Worker le met en cache
                    // pour que la grille reste identique hors ligne.
                    'image' => $images->chemin($article->getImage()),
                ];
            }

            if ([] !== $liste) {
                $donnees[] = ['id' => $famille->getId(), 'nom' => $famille->getNom(), 'articles' => $liste];
            }
        }

        return $this->json([
            'genereA' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'familles' => $donnees,
        ]);
    }

    private function utilisateur(): Utilisateur
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        return $utilisateur;
    }

}
