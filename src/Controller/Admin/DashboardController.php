<?php

namespace App\Controller\Admin;

use App\Repository\ArticleRepository;
use App\Repository\FicheTechniqueRepository;
use App\Repository\SessionCaisseRepository;
use App\Repository\UtilisateurRepository;
use App\Repository\VenteRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_GERANT')]
class DashboardController extends AbstractController
{
    #[Route('', name: 'admin_dashboard', methods: ['GET'])]
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
            'articles_actifs' => \count($articles->findBy(['actif' => true])),
            'matieres_alerte' => $matieresAlerte,
            'top_articles' => $topArticles,
        ]);
    }

    #[Route('/ventes', name: 'admin_ventes', methods: ['GET'])]
    public function ventes(VenteRepository $ventes): Response
    {
        return $this->render('admin/ventes.html.twig', [
            'ventes' => $ventes->findBy([], ['createdAt' => 'DESC'], 100),
        ]);
    }

    #[Route('/production', name: 'admin_production', methods: ['GET'])]
    public function production(FicheTechniqueRepository $fiches): Response
    {
        $lignes = [];
        foreach ($fiches->findAll() as $fiche) {
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

        return $this->render('admin/production.html.twig', ['fiches' => $lignes]);
    }

    #[Route('/clotures', name: 'admin_clotures', methods: ['GET'])]
    public function clotures(SessionCaisseRepository $sessions): Response
    {
        return $this->render('admin/clotures.html.twig', [
            'sessions' => $sessions->findBy([], ['ouvertureAt' => 'DESC'], 60),
        ]);
    }

    #[Route('/utilisateurs', name: 'admin_utilisateurs', methods: ['GET'])]
    public function utilisateurs(UtilisateurRepository $utilisateurs): Response
    {
        return $this->render('admin/utilisateurs.html.twig', [
            'utilisateurs' => $utilisateurs->findBy([], ['nom' => 'ASC']),
        ]);
    }
}
