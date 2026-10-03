<?php

namespace App\Controller\Magasin;

use App\Controller\Trait\ReponseFormulaire;
use App\Entity\Magasin\MagasinEmplacement;
use App\Enum\ActionAudit;
use App\Form\Magasin\EmplacementType;
use App\Repository\Magasin\MagasinEmplacementRepository;
use App\Security\Permission;
use App\Service\AuditLogger;
use App\Service\Magasin\MagasinStockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Zones de rangement du magasin. La « Réserve principale » existe toujours ;
 * on en ajoute d'autres (chambre froide, étagère A) quand le magasin grandit.
 * Une zone ne se supprime pas, elle se désactive.
 */
#[Route('/magasin/emplacements')]
#[IsGranted(Permission::MAGASIN_VOIR)]
class EmplacementController extends AbstractController
{
    use ReponseFormulaire;

    public function __construct(
        private readonly MagasinEmplacementRepository $emplacements,
        private readonly MagasinStockService $stock,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'magasin_emplacement_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // La réserve par défaut existe toujours, même sur une base neuve.
        $this->stock->reserveParDefaut();

        return $this->render('magasin/emplacement/index.html.twig', [
            'emplacements' => $this->emplacements->pagines($request->query->getInt('page', 1), $request->query->get('q')),
        ]);
    }

    #[Route('/nouveau', name: 'magasin_emplacement_new', methods: ['GET', 'POST'])]
    #[IsGranted(Permission::MAGASIN_REFERENTIEL)]
    public function nouveau(Request $request): Response
    {
        $form = $this->createForm(EmplacementType::class, new MagasinEmplacement('', ''), ['avec_code' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $code = mb_strtoupper(trim((string) $form->get('code')->getData()));

            if (null !== $this->emplacements->findOneBy(['code' => $code])) {
                $form->get('code')->addError(new FormError('Ce code est déjà pris.'));
            } else {
                /** @var MagasinEmplacement $saisi */
                $saisi = $form->getData();
                $emplacement = (new MagasinEmplacement($code, $saisi->getLibelle()))->setActif($saisi->isActif());
                $this->em->persist($emplacement);
                $this->em->flush();
                $this->audit->enregistrer(ActionAudit::MAGASIN_EMPLACEMENT_ENREGISTRE, 'MagasinEmplacement', $emplacement->getId(), null, $this->etat($emplacement));

                $this->addFlash('success', \sprintf('Emplacement « %s » créé.', $emplacement->getLibelle()));

                return $this->redirectToRoute('magasin_emplacement_index', [], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->rendreFormulaire('magasin/emplacement/form.html.twig', $form, ['titre' => 'Nouvel emplacement', 'emplacement' => null]);
    }

    #[Route('/{id}/modifier', name: 'magasin_emplacement_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Permission::MAGASIN_REFERENTIEL)]
    public function modifier(Request $request, MagasinEmplacement $emplacement): Response
    {
        $avant = $this->etat($emplacement);
        $form = $this->createForm(EmplacementType::class, $emplacement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // La réserve par défaut reçoit tout ce qui n'a pas de zone : on ne la ferme pas.
            if ($emplacement->estReserveParDefaut() && !$emplacement->isActif()) {
                $form->get('actif')->addError(new FormError('La réserve principale ne se désactive pas.'));
            } else {
                $this->em->flush();
                if ($avant !== $this->etat($emplacement)) {
                    $this->audit->enregistrer(ActionAudit::MAGASIN_EMPLACEMENT_ENREGISTRE, 'MagasinEmplacement', $emplacement->getId(), $avant, $this->etat($emplacement));
                }
                $this->addFlash('success', \sprintf('Emplacement « %s » mis à jour.', $emplacement->getLibelle()));

                return $this->redirectToRoute('magasin_emplacement_index', [], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->rendreFormulaire('magasin/emplacement/form.html.twig', $form, ['titre' => 'Modifier l\'emplacement', 'emplacement' => $emplacement]);
    }

    /** @return array<string, mixed> */
    private function etat(MagasinEmplacement $emplacement): array
    {
        return ['code' => $emplacement->getCode(), 'libelle' => $emplacement->getLibelle(), 'actif' => $emplacement->isActif()];
    }
}
