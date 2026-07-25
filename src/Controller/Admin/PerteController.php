<?php

namespace App\Controller\Admin;

use App\Controller\Trait\ReponseFormulaire;
use App\Enum\MotifPerte;
use App\Form\PerteType;
use App\Repository\PerteRepository;
use App\Service\PerteService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/pertes')]
#[IsGranted('ROLE_GERANT')]
class PerteController extends AbstractController
{
    use ReponseFormulaire;

    /**
     * Synthèse mensuelle : total valorisé, ventilation par motif, top 5 des produits.
     */
    #[Route('', name: 'admin_pertes', methods: ['GET'])]
    public function index(Request $request, Connection $connexion, PerteRepository $pertes): Response
    {
        $mois = (string) $request->query->get('mois', (new \DateTimeImmutable())->format('Y-m'));
        $debut = \DateTimeImmutable::createFromFormat('!Y-m-d', $mois.'-01') ?: new \DateTimeImmutable('first day of this month 00:00');
        $debut = $debut->setTime(0, 0);
        $fin = $debut->modify('+1 month');

        $bornes = [$debut->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s')];

        $total = (int) $connexion->fetchOne(
            'SELECT COALESCE(SUM(valorisation), 0) FROM perte WHERE created_at >= ? AND created_at < ?',
            $bornes,
        );

        $ventilation = [];
        foreach ($connexion->fetchAllAssociative(
            'SELECT motif, COUNT(*) AS nombre, COALESCE(SUM(valorisation), 0) AS valorisation
             FROM perte WHERE created_at >= ? AND created_at < ? GROUP BY motif ORDER BY valorisation DESC',
            $bornes,
        ) as $ligne) {
            $ventilation[] = [
                'motif' => MotifPerte::from($ligne['motif']),
                'nombre' => (int) $ligne['nombre'],
                'valorisation' => (int) $ligne['valorisation'],
            ];
        }

        $top = $connexion->fetchAllAssociative(
            'SELECT COALESCE(m.nom, a.nom) AS nom,
                    COALESCE(SUM(p.valorisation), 0) AS valorisation,
                    COALESCE(SUM(p.quantite), 0) AS quantite
             FROM perte p
             LEFT JOIN matiere_premiere m ON m.id = p.matiere_premiere_id
             LEFT JOIN article a ON a.id = p.article_id
             WHERE p.created_at >= ? AND p.created_at < ?
             GROUP BY nom ORDER BY valorisation DESC LIMIT 5',
            $bornes,
        );

        return $this->render('admin/pertes.html.twig', [
            'mois' => $debut->format('Y-m'),
            'total' => $total,
            'ventilation' => $ventilation,
            'top' => $top,
            'pertes' => $pertes->createQueryBuilder('p')
                ->andWhere('p.createdAt >= :debut')->andWhere('p.createdAt < :fin')
                ->setParameter('debut', $debut)->setParameter('fin', $fin)
                ->orderBy('p.createdAt', 'DESC')
                ->getQuery()->getResult(),
        ]);
    }

    #[Route('/saisie', name: 'admin_perte_saisie', methods: ['GET', 'POST'])]
    public function saisie(Request $request, PerteService $service): Response
    {
        $form = $this->createForm(PerteType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $donnees = $form->getData();
            try {
                $service->enregistrer(
                    $donnees['motif'],
                    $donnees['matierePremiere'],
                    $donnees['article'],
                    (int) $donnees['quantite'],
                    $donnees['commentaire'],
                );
                $this->addFlash('success', 'Perte enregistrée et valorisée.');

                return $this->redirectToRoute('admin_perte_saisie', [], Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->rendreFormulaire('admin/perte/saisie.html.twig', $form);
    }
}
