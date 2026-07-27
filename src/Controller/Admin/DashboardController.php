<?php

namespace App\Controller\Admin;

use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Repository\ArticleRepository;
use App\Repository\FicheTechniqueRepository;
use App\Repository\SessionCaisseRepository;
use App\Repository\UtilisateurRepository;
use App\Repository\VenteRepository;
use App\Security\Permission;
use App\Service\AuditLogger;
use App\Service\SessionCaisseService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_GERANT')]
class DashboardController extends AbstractController
{
    #[Route('', name: 'admin_dashboard', methods: ['GET'])]
    #[IsGranted(Permission::VOIR_CA_GLOBAL)]
    public function index(Connection $connexion, ArticleRepository $articles): Response
    {
        $jour = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $debut30j = (new \DateTimeImmutable('today'))->modify('-29 days')->format('Y-m-d 00:00:00');

        $caJour = (int) $connexion->fetchOne(
            "SELECT COALESCE(SUM(total_ttc), 0) FROM vente WHERE statut = 'VALIDEE' AND DATE(created_at) = ?",
            [$jour],
        );
        $ventesJour = (int) $connexion->fetchOne('SELECT COUNT(*) FROM vente WHERE DATE(created_at) = ?', [$jour]);
        $ca30j = (int) $connexion->fetchOne(
            "SELECT COALESCE(SUM(total_ttc), 0) FROM vente WHERE statut = 'VALIDEE' AND created_at >= ?",
            [$debut30j],
        );
        $ventes30j = (int) $connexion->fetchOne('SELECT COUNT(*) FROM vente WHERE created_at >= ?', [$debut30j]);
        $matieresAlerte = (int) $connexion->fetchOne('SELECT COUNT(*) FROM matiere_premiere WHERE stock_actuel < stock_mini');

        $topArticles = $connexion->fetchAllAssociative(
            'SELECT a.nom, SUM(lv.quantite) / 1000 AS quantite, SUM(lv.quantite * lv.prix_unitaire) / 1000 AS ca
             FROM ligne_vente lv
             JOIN article a ON a.id = lv.article_id
             JOIN vente v ON v.id = lv.vente_id
             WHERE v.created_at >= ? AND v.statut = \'VALIDEE\'
             GROUP BY a.id ORDER BY quantite DESC LIMIT 8',
            [$debut30j],
        );

        return $this->render('admin/dashboard.html.twig', [
            'ca_jour' => $caJour,
            'ventes_jour' => $ventesJour,
            'ca_30j' => $ca30j,
            'panier_moyen' => $ventes30j > 0 ? intdiv($ca30j, $ventes30j) : 0,
            'articles_actifs' => $articles->compterActifs(),
            'matieres_alerte' => $matieresAlerte,
            'top_articles' => $topArticles,
        ]);
    }

    #[Route('/ventes', name: 'admin_ventes', methods: ['GET'])]
    #[IsGranted(Permission::VOIR_TOUTES_VENTES)]
    public function ventes(Request $request, VenteRepository $ventes): Response
    {
        return $this->render('admin/ventes.html.twig', [
            'ventes' => $ventes->paginees(
                $request->query->getInt('page', 1),
                $request->query->get('q'),
            ),
        ]);
    }

    // Coûts matières et marges par fiche technique : donnée de gestion.
    #[Route('/production', name: 'admin_production', methods: ['GET'])]
    #[IsGranted(Permission::ARTICLE_VOIR_COUT)]
    public function production(Request $request, FicheTechniqueRepository $fiches): Response
    {
        $page = $fiches->avecMatieres(
            $request->query->getInt('page', 1),
            $request->query->get('q'),
        );

        $lignes = [];
        foreach ($page->items as $fiche) {
            $coutMatieres = 0;
            foreach ($fiche->getLignes() as $ligne) {
                $coutMatieres += intdiv($ligne->getMatierePremiere()->getCoutMoyenPondere() * $ligne->getQuantite(), 1000);
            }
            $article = $fiche->getArticle();
            $lignes[] = [
                'article' => $article,
                'composants' => \count($fiche->getLignes()),
                'cout_matieres' => $coutMatieres,
                'marge' => $article->getPrixVenteTtc() - $coutMatieres,
            ];
        }

        // Les lignes calculées remplacent les fiches, mais la pagination reste
        // celle de la requête : c'est la base qui a découpé, pas PHP.
        return $this->render('admin/production.html.twig', [
            'fiches' => $lignes,
            'page' => $page,
        ]);
    }

    #[Route('/clotures', name: 'admin_clotures', methods: ['GET'])]
    public function clotures(Request $request, SessionCaisseRepository $sessions): Response
    {
        return $this->render('admin/clotures.html.twig', [
            'sessions' => $sessions->paginees(
                $request->query->getInt('page', 1),
                $request->query->get('q'),
            ),
        ]);
    }

    /**
     * Rapport Z détaillé d'une session (consultation gérant).
     */
    #[Route('/clotures/{id}', name: 'admin_cloture', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function cloture(SessionCaisse $session, SessionCaisseService $service): Response
    {
        return $this->render('admin/cloture.html.twig', ['rapport' => $service->rapportZ($session)]);
    }

    #[Route('/utilisateurs', name: 'admin_utilisateurs', methods: ['GET'])]
    public function utilisateurs(Request $request, UtilisateurRepository $utilisateurs): Response
    {
        return $this->render('admin/utilisateurs.html.twig', [
            'utilisateurs' => $utilisateurs->pagines(
                $request->query->getInt('page', 1),
                $request->query->get('q'),
            ),
        ]);
    }

    /**
     * Active ou désactive un compte. Ouvert au gérant et à la dirigeante, mais la
     * permission porte sur **le compte visé** : un gérant ne bascule pas une
     * dirigeante, sinon il pourrait couper l'établissement de son seul accès au
     * pilotage et à l'audit. Tracé au journal d'audit.
     */
    #[Route('/utilisateurs/{id}/basculer', name: 'admin_utilisateur_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Permission::UTILISATEUR_GERER, subject: 'utilisateur')]
    public function basculerUtilisateur(
        Request $request,
        Utilisateur $utilisateur,
        EntityManagerInterface $em,
        AuditLogger $audit,
    ): Response {
        if (!$this->isCsrfTokenValid('basculer_utilisateur_'.$utilisateur->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_utilisateurs', [], Response::HTTP_SEE_OTHER);
        }

        if ($utilisateur === $this->getUser()) {
            $this->addFlash('error', 'Vous ne pouvez pas désactiver votre propre compte.');

            return $this->redirectToRoute('admin_utilisateurs', [], Response::HTTP_SEE_OTHER);
        }

        $actifAvant = $utilisateur->isActif();
        $utilisateur->setActif(!$actifAvant);
        $em->flush();
        $audit->utilisateurBascule($utilisateur, $actifAvant);

        $this->addFlash('success', $utilisateur->isActif()
            ? 'Compte « '.$utilisateur->getNom().' » activé.'
            : 'Compte « '.$utilisateur->getNom().' » désactivé.');

        return $this->redirectToRoute('admin_utilisateurs', [], Response::HTTP_SEE_OTHER);
    }
}
