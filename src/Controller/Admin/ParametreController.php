<?php

namespace App\Controller\Admin;

use App\Controller\Trait\ReponseFormulaire;
use App\Enum\CleParametre;
use App\Form\ParametresBoutiqueType;
use App\Service\ParametresBoutique;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Informations de l'établissement imprimées sur les tickets et les rapports Z.
 */
#[Route('/admin/parametres')]
#[IsGranted('ROLE_GERANT')]
class ParametreController extends AbstractController
{
    use ReponseFormulaire;

    #[Route('', name: 'admin_parametres', methods: ['GET', 'POST'])]
    public function index(Request $request, ParametresBoutique $parametres): Response
    {
        // Le formulaire est indexé par nom de champ ; on part des valeurs courantes.
        $valeurs = [];
        foreach (CleParametre::cases() as $cle) {
            $valeurs[ParametresBoutiqueType::champ($cle)] = $parametres->valeur($cle);
        }

        $form = $this->createForm(ParametresBoutiqueType::class, $valeurs);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $saisie = [];
            foreach (CleParametre::cases() as $cle) {
                $saisie[$cle->value] = $form->getData()[ParametresBoutiqueType::champ($cle)] ?? '';
            }

            $parametres->enregistrer($saisie);
            $this->addFlash('success', 'Paramètres enregistrés. Les prochains tickets les reprennent.');

            return $this->redirectToRoute('admin_parametres', [], Response::HTTP_SEE_OTHER);
        }

        // Groupes affichés dans l'ordre du catalogue, sans les coder en dur ici.
        $groupes = [];
        foreach (CleParametre::cases() as $cle) {
            $groupes[$cle->groupe()][] = ParametresBoutiqueType::champ($cle);
        }

        return $this->rendreFormulaire('admin/parametres.html.twig', $form, ['groupes' => $groupes]);
    }
}
