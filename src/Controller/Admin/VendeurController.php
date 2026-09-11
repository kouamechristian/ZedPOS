<?php

namespace App\Controller\Admin;

use App\Controller\Trait\ReponseFormulaire;
use App\Entity\Utilisateur;
use App\Entity\Vendeur;
use App\Form\DetteManuelleType;
use App\Form\RemboursementDetteType;
use App\Form\VendeurType;
use App\Repository\ArreteRepository;
use App\Repository\DetteVendeurRepository;
use App\Repository\RemboursementDetteRepository;
use App\Repository\VendeurRepository;
use App\Security\Permission;
use App\Service\DetteService;
use App\Service\ParametresBoutique;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Vendeurs des stands. Pas de suppression : un vendeur a signé des bons, il se
 * désactive.
 *
 * Sa fiche porte ce qu'il doit : solde de ses dettes, historique de ses points
 * avec leurs écarts, remboursements. Trois onglets paginés (`?onglet=`), et deux
 * formulaires — un versement, une avance — toujours sous la main.
 */
#[Route('/admin/vendeurs')]
#[IsGranted(Permission::STAND_GERER)]
class VendeurController extends AbstractController
{
    use ReponseFormulaire;

    private const ONGLETS = ['dettes', 'points', 'remboursements'];

    public function __construct(
        private readonly DetteService $service,
        private readonly DetteVendeurRepository $dettes,
        private readonly RemboursementDetteRepository $remboursements,
        private readonly ArreteRepository $arretes,
        private readonly ParametresBoutique $parametres,
    ) {
    }

    #[Route('', name: 'admin_vendeur_index', methods: ['GET'])]
    public function index(Request $request, VendeurRepository $vendeurs): Response
    {
        $page = $vendeurs->paginees($request->query->getInt('page', 1), $request->query->get('q'));

        return $this->render('admin/vendeur/index.html.twig', [
            'vendeurs' => $page,
            // Soldes de la page seulement, en une requête.
            'soldes' => $this->dettes->soldes($page->items),
            'seuil_dette' => $this->parametres->seuilAlerteDette(),
        ]);
    }

    #[Route('/nouveau', name: 'admin_vendeur_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $vendeur = new Vendeur();
        $form = $this->createForm(VendeurType::class, $vendeur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($vendeur);
            $em->flush();
            $this->addFlash('success', 'Vendeur « '.$vendeur->getNom().' » créé.');

            return $this->redirectToRoute('admin_vendeur_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->rendreFormulaire('admin/vendeur/form.html.twig', $form, ['titre' => 'Nouveau vendeur']);
    }

    #[Route('/{id}', name: 'admin_vendeur_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Request $request, Vendeur $vendeur): Response
    {
        return $this->fiche($request, $vendeur, $this->formRemboursement($vendeur), $this->formDette($vendeur));
    }

    #[Route('/{id}/remboursement', name: 'admin_vendeur_rembourser', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function rembourser(Request $request, Vendeur $vendeur): Response
    {
        $form = $this->formRemboursement($vendeur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $donnees = $form->getData();

            try {
                $lignes = $this->service->rembourser($vendeur, $donnees['montant'], $donnees['moyen'], $donnees['date'], $this->utilisateur());
                $this->addFlash('success', \sprintf(
                    'Versement de %s FCFA enregistré%s. Reste dû : %s FCFA.',
                    number_format(intdiv($donnees['montant'], 100), 0, ',', ' '),
                    \count($lignes) > 1 ? \sprintf(' sur %d dettes', \count($lignes)) : '',
                    number_format(intdiv($this->service->soldeDe($vendeur), 100), 0, ',', ' '),
                ));

                return $this->redirectToRoute('admin_vendeur_show', ['id' => $vendeur->getId()], Response::HTTP_SEE_OTHER);
            } catch (\DomainException $e) {
                $form->get('montant')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->fiche($request, $vendeur, $form, $this->formDette($vendeur), $form);
    }

    #[Route('/{id}/dette', name: 'admin_vendeur_dette', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function dette(Request $request, Vendeur $vendeur): Response
    {
        $form = $this->formDette($vendeur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $donnees = $form->getData();

            try {
                $dette = $this->service->ouvrirManuelle($vendeur, $donnees['type'], $donnees['montant'], $donnees['commentaire'], $this->utilisateur());
                $this->addFlash('success', \sprintf('%s de %s FCFA inscrite au nom de %s.', $dette->getType()->libelle(), number_format(intdiv($dette->getMontant(), 100), 0, ',', ' '), $vendeur->getNom()));

                return $this->redirectToRoute('admin_vendeur_show', ['id' => $vendeur->getId()], Response::HTTP_SEE_OTHER);
            } catch (\DomainException $e) {
                $form->get('montant')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->fiche($request, $vendeur, $this->formRemboursement($vendeur), $form, $form);
    }

    #[Route('/{id}/modifier', name: 'admin_vendeur_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Vendeur $vendeur, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(VendeurType::class, $vendeur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Vendeur mis à jour.');

            return $this->redirectToRoute('admin_vendeur_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->rendreFormulaire('admin/vendeur/form.html.twig', $form, ['titre' => 'Modifier le vendeur']);
    }

    // ------------------------------------------------------------------ Fiche

    /**
     * @param FormInterface|null $soumis le formulaire posté, pour le statut 422 s'il est invalide
     */
    private function fiche(Request $request, Vendeur $vendeur, FormInterface $remboursement, FormInterface $dette, ?FormInterface $soumis = null): Response
    {
        $onglet = \in_array($request->query->get('onglet'), self::ONGLETS, true) ? $request->query->get('onglet') : 'dettes';
        $page = max(1, $request->query->getInt('page', 1));

        $vue = [
            'vendeur' => $vendeur,
            'solde' => $this->dettes->soldeDe($vendeur),
            'seuil_dette' => $this->parametres->seuilAlerteDette(),
            'cumul' => $this->arretes->cumulEcartsDuVendeur($vendeur),
            'onglet' => $onglet,
            'liste' => match ($onglet) {
                'points' => $this->arretes->valideesDuVendeur($vendeur, $page),
                'remboursements' => $this->remboursements->paginesDe($vendeur, $page),
                default => $this->dettes->paginesDe($vendeur, $page),
            },
            'form_remboursement' => $remboursement->createView(),
            'form_dette' => $dette->createView(),
        ];

        return $this->rendreFormulaire('admin/vendeur/show.html.twig', $soumis ?? $remboursement, $vue);
    }

    private function formRemboursement(Vendeur $vendeur): FormInterface
    {
        return $this->createForm(RemboursementDetteType::class, null, [
            'action' => $this->generateUrl('admin_vendeur_rembourser', ['id' => $vendeur->getId()]),
        ]);
    }

    private function formDette(Vendeur $vendeur): FormInterface
    {
        return $this->createForm(DetteManuelleType::class, null, [
            'action' => $this->generateUrl('admin_vendeur_dette', ['id' => $vendeur->getId()]),
        ]);
    }

    private function utilisateur(): Utilisateur
    {
        $utilisateur = $this->getUser();
        \assert($utilisateur instanceof Utilisateur);

        return $utilisateur;
    }
}
