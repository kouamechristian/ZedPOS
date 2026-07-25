<?php

namespace App\Controller\Pilotage;

use App\Enum\ActionAudit;
use App\Repository\JournalAuditRepository;
use App\Repository\UtilisateurRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Consultation du journal d'audit, réservée à la dirigeante.
 *
 * **Lecture seule, par construction** : ce contrôleur n'expose qu'une route GET.
 * Il n'existe nulle part dans l'application de route de modification ou de
 * suppression d'une entrée du journal, et il ne doit jamais en être ajouté.
 */
#[Route('/pilotage/audit')]
#[IsGranted('ROLE_DIRIGEANTE')]
class AuditController extends AbstractController
{
    #[Route('', name: 'pilotage_audit', methods: ['GET'])]
    public function index(
        Request $request,
        JournalAuditRepository $journal,
        UtilisateurRepository $utilisateurs,
    ): Response {
        $du = $this->lireDate($request->query->get('du'));
        $au = $this->lireDate($request->query->get('au'));
        $action = ActionAudit::tryFrom((string) $request->query->get('action', ''));

        $auteurId = $request->query->getInt('utilisateur');
        $auteur = $auteurId > 0 ? $utilisateurs->find($auteurId) : null;

        $resultat = $journal->rechercher($du, $au, $auteur, $action, $request->query->getInt('page', 1));

        return $this->render('pilotage/audit.html.twig', [
            'resultat' => $resultat,
            'actionsParFamille' => $this->actionsParFamille(),
            'libelles' => $this->libelles(),
            'auteurs' => $journal->auteurs(),
            'filtres' => [
                'du' => $du?->format('Y-m-d'),
                'au' => $au?->format('Y-m-d'),
                'utilisateur' => $auteur?->getId(),
                'action' => $action?->value,
            ],
        ]);
    }

    /**
     * Actions regroupées par famille, pour les `optgroup` du filtre.
     *
     * @return array<string, list<ActionAudit>>
     */
    private function actionsParFamille(): array
    {
        $groupes = [];
        foreach (ActionAudit::cases() as $action) {
            $groupes[$action->famille()][] = $action;
        }

        return $groupes;
    }

    /**
     * Table de correspondance valeur brute → libellé et criticité, pour afficher
     * les entrées du journal sans résoudre l'énumération dans le template.
     * Une valeur historique inconnue de l'énumération reste affichée telle quelle.
     *
     * @return array<string, array{libelle: string, sensible: bool}>
     */
    private function libelles(): array
    {
        $libelles = [];
        foreach (ActionAudit::cases() as $action) {
            $libelles[$action->value] = ['libelle' => $action->libelle(), 'sensible' => $action->estSensible()];
        }

        return $libelles;
    }

    private function lireDate(mixed $valeur): ?\DateTimeImmutable
    {
        if (!\is_string($valeur) || '' === trim($valeur)) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur) ?: null;
    }
}
