<?php

namespace App\Controller\Admin;

use App\Controller\Trait\ReponseFormulaire;
use App\Entity\Emplacement;
use App\Enum\ModeRemuneration;
use App\Enum\PeriodicitePoint;
use App\Enum\TypeEmplacement;
use App\Form\StandType;
use App\Repository\EmplacementRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Stands des revendeurs — des `Emplacement` de type STAND. Pas de suppression :
 * un stand porte du stock et des mouvements, il se désactive.
 */
#[Route('/admin/stands')]
#[IsGranted(Permission::STAND_GERER)]
class StandController extends AbstractController
{
    use ReponseFormulaire;

    #[Route('', name: 'admin_stand_index', methods: ['GET'])]
    public function index(Request $request, EmplacementRepository $emplacements): Response
    {
        return $this->render('admin/stand/index.html.twig', [
            'stands' => $emplacements->standsPagines($request->query->getInt('page', 1), $request->query->get('q')),
        ]);
    }

    #[Route('/nouveau', name: 'admin_stand_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, EmplacementRepository $emplacements): Response
    {
        $form = $this->createForm(
            StandType::class,
            ['periodicitePoint' => PeriodicitePoint::JOUR, 'actif' => true, 'seuilEcartAlerte' => 0, 'modeRemuneration' => ModeRemuneration::COMMISSION, 'tauxCommission' => 0],
            ['creation' => true, 'fixer_remuneration' => $this->isGranted(Permission::REMUNERATION_FIXER)],
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $donnees = $form->getData();
            $code = strtoupper(trim((string) $donnees['code']));

            if (null !== $emplacements->findOneBy(['code' => $code])) {
                $form->get('code')->addError(new FormError(\sprintf('Le code « %s » est déjà pris.', $code)));

                return $this->rendreFormulaire('admin/stand/form.html.twig', $form, ['titre' => 'Nouveau stand']);
            }

            $stand = new Emplacement($code, (string) $donnees['libelle'], TypeEmplacement::STAND);
            $this->appliquer($stand, $donnees);
            $em->persist($stand);
            $em->flush();

            $this->addFlash('success', 'Stand « '.$stand->getLibelle().' » créé.');

            return $this->redirectToRoute('admin_stand_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->rendreFormulaire('admin/stand/form.html.twig', $form, ['titre' => 'Nouveau stand']);
    }

    #[Route('/{id}/modifier', name: 'admin_stand_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Emplacement $stand, EntityManagerInterface $em): Response
    {
        if (!$stand->estStand()) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(StandType::class, [
            'libelle' => $stand->getLibelle(),
            'periodicitePoint' => $stand->getPeriodicitePoint(),
            'vendeurHabituel' => $stand->getVendeurHabituel(),
            'seuilEcartAlerte' => $stand->getSeuilEcartAlerte(),
            'modeRemuneration' => $stand->getModeRemuneration(),
            'tauxCommission' => $stand->getTauxCommission(),
            'actif' => $stand->isActif(),
        ], ['fixer_remuneration' => $this->isGranted(Permission::REMUNERATION_FIXER)]);
        $form->handleRequest($request);

        $vue = ['titre' => 'Modifier le stand « '.$stand->getCode().' »', 'stand' => $stand];

        if ($form->isSubmitted() && $form->isValid()) {
            $this->appliquer($stand, $form->getData());
            $em->flush();
            $this->addFlash('success', 'Stand mis à jour.');

            return $this->redirectToRoute('admin_stand_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->rendreFormulaire('admin/stand/form.html.twig', $form, $vue);
    }

    /** @param array<string, mixed> $donnees */
    private function appliquer(Emplacement $stand, array $donnees): void
    {
        $stand
            ->setLibelle((string) $donnees['libelle'])
            ->setPeriodicitePoint($donnees['periodicitePoint'] ?? PeriodicitePoint::JOUR)
            ->setVendeurHabituel($donnees['vendeurHabituel'] ?? null)
            ->setSeuilEcartAlerte((int) ($donnees['seuilEcartAlerte'] ?? 0))
            ->setActif((bool) ($donnees['actif'] ?? false));

        // Présents dans la soumission seulement pour la dirigeante : le formulaire
        // de la gérante ne les déclare pas.
        if ($this->isGranted(Permission::REMUNERATION_FIXER)) {
            $stand
                ->setModeRemuneration($donnees['modeRemuneration'] ?? ModeRemuneration::COMMISSION)
                ->setTauxCommission((int) ($donnees['tauxCommission'] ?? 0));
        }
    }
}
