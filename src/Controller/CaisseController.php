<?php

namespace App\Controller;

use App\Entity\LigneVente;
use App\Entity\Reglement;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\ModeReglement;
use App\Enum\ModeVente;
use App\Enum\StatutSessionCaisse;
use App\Repository\ArticleRepository;
use App\Repository\FamilleProduitRepository;
use App\Repository\SessionCaisseRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/caisse')]
#[IsGranted('ROLE_CAISSIER')]
class CaisseController extends AbstractController
{
    #[Route('', name: 'app_caisse', methods: ['GET'])]
    public function index(
        FamilleProduitRepository $familles,
        ArticleRepository $articles,
        EntityManagerInterface $em,
        SessionCaisseRepository $sessions,
    ): Response {
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
            'session' => $this->sessionOuverte($em, $sessions),
        ]);
    }

    #[Route('/encaisser', name: 'app_caisse_encaisser', methods: ['POST'])]
    public function encaisser(
        Request $request,
        ArticleRepository $articles,
        EntityManagerInterface $em,
        SessionCaisseRepository $sessions,
    ): JsonResponse {
        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];

        if (!$this->isCsrfTokenValid('caisse', (string) ($donnees['_token'] ?? ''))) {
            return $this->json(['ok' => false, 'erreur' => 'Session expirée, rechargez la page.'], Response::HTTP_FORBIDDEN);
        }

        $modeVente = ModeVente::tryFrom((string) ($donnees['mode'] ?? ''));
        $modeReglement = ModeReglement::tryFrom((string) ($donnees['reglementMode'] ?? ''));
        $lignes = $donnees['lignes'] ?? [];

        if (null === $modeVente || null === $modeReglement) {
            return $this->json(['ok' => false, 'erreur' => 'Mode de vente ou de règlement invalide.'], Response::HTTP_BAD_REQUEST);
        }
        if (!\is_array($lignes) || [] === $lignes) {
            return $this->json(['ok' => false, 'erreur' => 'Le ticket est vide.'], Response::HTTP_BAD_REQUEST);
        }

        // Recalcul intégral côté serveur : les prix ne sont jamais pris du client.
        $totalTtc = 0;
        $totalTva = 0;
        $preparees = [];
        foreach ($lignes as $ligne) {
            $article = $articles->find((int) ($ligne['articleId'] ?? 0));
            if (null === $article || !$article->isActif()) {
                return $this->json(['ok' => false, 'erreur' => 'Article indisponible.'], Response::HTTP_BAD_REQUEST);
            }

            $quantite = max(1, (int) ($ligne['quantite'] ?? 1));
            $prix = $article->getPrixVenteTtc();
            $montantTtc = $quantite * $prix;
            $montantHt = intdiv($montantTtc * 10000, 10000 + $article->getTauxTva());

            $totalTtc += $montantTtc;
            $totalTva += $montantTtc - $montantHt;

            $commentaire = trim((string) ($ligne['commentaire'] ?? ''));
            $preparees[] = [
                'article' => $article,
                'quantiteMillimes' => $quantite * 1000,
                'prix' => $prix,
                'commentaire' => '' !== $commentaire ? $commentaire : null,
            ];
        }

        $session = $this->sessionOuverte($em, $sessions);

        // Génération d'un numéro unique, avec quelques tentatives en cas de concurrence.
        for ($tentative = 0; $tentative < 5; ++$tentative) {
            $vente = new Vente($session, $modeVente, $this->genererNumero($em), $totalTtc - $totalTva, $totalTva, $totalTtc);
            foreach ($preparees as $p) {
                new LigneVente($vente, $p['article'], $p['quantiteMillimes'], $p['prix'], 0, $p['commentaire']);
            }
            new Reglement($vente, $modeReglement, $totalTtc, null);

            try {
                $em->persist($vente);
                $em->flush();

                return $this->json(['ok' => true, 'numero' => $vente->getNumero(), 'total' => $totalTtc]);
            } catch (UniqueConstraintViolationException) {
                $em->clear();
                $session = $this->sessionOuverte($em, $sessions);
            }
        }

        return $this->json(['ok' => false, 'erreur' => "Impossible d'enregistrer la vente, réessayez."], Response::HTTP_CONFLICT);
    }

    /**
     * Session de caisse ouverte de l'utilisateur courant, créée si nécessaire.
     */
    private function sessionOuverte(EntityManagerInterface $em, SessionCaisseRepository $sessions): SessionCaisse
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        $session = $sessions->findOneBy(
            ['utilisateur' => $utilisateur, 'statut' => StatutSessionCaisse::OUVERTE],
            ['ouvertureAt' => 'DESC'],
        );

        if (null === $session) {
            $session = new SessionCaisse($utilisateur, 0);
            $em->persist($session);
            $em->flush();
        }

        return $session;
    }

    private function genererNumero(EntityManagerInterface $em): string
    {
        $jour = (new \DateTimeImmutable())->format('ymd');
        $nombre = (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM vente WHERE numero LIKE ?',
            ['V'.$jour.'-%'],
        );

        return \sprintf('V%s-%05d', $jour, $nombre + 1);
    }
}
