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
use App\Service\ChiffreCaisseService;
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
    /**
     * Deux lectures selon qui regarde :
     *
     * - **la dirigeante** : la caisse ouverte (à défaut la dernière clôturée), le
     *   **chiffre du mois en cours** (panier moyen et meilleures ventes du mois) et
     *   le **chiffre d'affaires global**, toutes les ventes validées depuis la mise
     *   en service ;
     * - **la gérante** : **la caisse ouverte seulement**, à la demande de
     *   l'exploitante. Aucun chiffre sur trente jours ne lui est calculé ni
     *   envoyé ; sans caisse ouverte, il n'y a rien à lire.
     */
    public function index(Connection $connexion, ArticleRepository $articles, ChiffreCaisseService $chiffreCaisse): Response
    {
        $dirigeante = $this->isGranted('ROLE_DIRIGEANTE');
        $matieresAlerte = (int) $connexion->fetchOne('SELECT COUNT(*) FROM matiere_premiere WHERE stock_actuel < stock_mini');

        $caisse = $chiffreCaisse->courant();
        if (!$dirigeante && null !== $caisse && !$caisse->ouverte) {
            $caisse = null;
        }

        $donnees = [
            'dirigeante' => $dirigeante,
            'caisse' => $caisse,
            'articles_actifs' => $articles->compterActifs(),
            'matieres_alerte' => $matieresAlerte,
        ];

        if (!$dirigeante) {
            return $this->render('admin/dashboard.html.twig', $donnees + [
                'panier_moyen' => null !== $caisse && $caisse->tickets > 0 ? intdiv($caisse->ca, $caisse->tickets) : 0,
                'top_articles' => null !== $caisse ? $this->meilleuresVentes($connexion, 'v.session_caisse_id = ?', [$caisse->sessionId]) : [],
            ]);
        }

        // Le mois civil en cours, du 1er à 00:00 au 1er du mois suivant (exclu).
        $debutMois = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0);
        $finMois = $debutMois->modify('+1 month');
        $mois = [$debutMois->format('Y-m-d H:i:s'), $finMois->format('Y-m-d H:i:s')];

        $ventesMois = $connexion->fetchAssociative(
            "SELECT COALESCE(SUM(total_ttc), 0) AS ca, COUNT(*) AS tickets
             FROM vente WHERE statut = 'VALIDEE' AND created_at >= ? AND created_at < ?",
            $mois,
        ) ?: [];
        $caMois = (int) ($ventesMois['ca'] ?? 0);
        $ticketsMois = (int) ($ventesMois['tickets'] ?? 0);

        // Tout ce que la caisse a encaissé depuis sa mise en service.
        $global = $connexion->fetchAssociative(
            "SELECT COALESCE(SUM(total_ttc), 0) AS ca, MIN(created_at) AS premiere
             FROM vente WHERE statut = 'VALIDEE'",
        ) ?: [];

        return $this->render('admin/dashboard.html.twig', $donnees + [
            'ca_mois' => $caMois,
            // Sans extension intl sur ce poste : les mois en français, écrits ici.
            'libelle_mois' => ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'][(int) $debutMois->format('n') - 1].' '.$debutMois->format('Y'),
            'panier_moyen' => $ticketsMois > 0 ? intdiv($caMois, $ticketsMois) : 0,
            'ca_global' => (int) ($global['ca'] ?? 0),
            'premiere_vente' => null !== ($global['premiere'] ?? null) ? new \DateTimeImmutable($global['premiere']) : null,
            'top_articles' => $this->meilleuresVentes($connexion, 'v.created_at >= ? AND v.created_at < ?', $mois),
        ]);
    }

    /**
     * Les huit articles les plus vendus sur la portée donnée, ventes validées.
     *
     * @param list<string|int> $parametres
     *
     * @return list<array<string, mixed>>
     */
    private function meilleuresVentes(Connection $connexion, string $portee, array $parametres): array
    {
        return $connexion->fetchAllAssociative(
            "SELECT a.nom, SUM(lv.quantite) / 1000 AS quantite, SUM(lv.quantite * lv.prix_unitaire) / 1000 AS ca
             FROM ligne_vente lv
             JOIN article a ON a.id = lv.article_id
             JOIN vente v ON v.id = lv.vente_id
             WHERE {$portee} AND v.statut = 'VALIDEE'
             GROUP BY a.id ORDER BY quantite DESC LIMIT 8",
            $parametres,
        );
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
