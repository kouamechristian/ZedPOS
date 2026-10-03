<?php

namespace App\Controller\Magasin;

use App\Security\Permission;
use App\Service\Magasin\TableauDeBordMagasin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tableau de bord du magasin (`/magasin`) : le plan de traitement en six cartes,
 * avec les chiffres de la journée, ce qui attend à chaque étape et les produits
 * sous leur seuil. **Lecture seule** — ouvert au magasinier comme à la gérante.
 *
 * `?jour=AAAA-MM-JJ` rejoue une autre journée ; une date illisible ou future
 * retombe sur aujourd'hui, comme au pilotage : un lien périmé n'immobilise pas
 * l'écran.
 */
#[IsGranted(Permission::MAGASIN_VOIR)]
class TableauDeBordController extends AbstractController
{
    #[Route('/magasin', name: 'magasin_tableau_de_bord', methods: ['GET'])]
    public function index(Request $request, TableauDeBordMagasin $tableau): Response
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $saisie = (string) $request->query->get('jour');
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', $saisie);
        if (false === $jour || $jour->format('Y-m-d') !== $saisie || $jour > $aujourdhui) {
            $jour = $aujourdhui;
        }

        return $this->render('magasin/tableau_de_bord.html.twig', [
            'jour' => $jour,
            'estAujourdhui' => $jour == $aujourdhui,
            'veille' => $jour->modify('-1 day'),
            'lendemain' => $jour < $aujourdhui ? $jour->modify('+1 day') : null,
            'chiffres' => $tableau->journee($jour),
        ]);
    }
}
