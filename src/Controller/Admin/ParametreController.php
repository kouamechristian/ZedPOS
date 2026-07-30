<?php

namespace App\Controller\Admin;

use App\Controller\Trait\ReponseFormulaire;
use App\Enum\CleParametre;
use App\Form\ParametresBoutiqueType;
use App\Service\LogoBoutique;
use App\Service\ParametresBoutique;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
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

    public function __construct(private readonly LogoBoutique $logos)
    {
    }

    #[Route('', name: 'admin_parametres', methods: ['GET', 'POST'])]
    public function index(Request $request, ParametresBoutique $parametres): Response
    {
        // Le formulaire est indexé par nom de champ ; on part des valeurs courantes.
        // Les paramètres-fichier en sont absents : leur nom de fichier n'est pas une
        // saisie, il est porté par le couple « téléverser / retirer ».
        $valeurs = [];
        foreach (self::clesSaisies() as $cle) {
            $valeurs[ParametresBoutiqueType::champ($cle)] = $parametres->valeur($cle);
        }

        $form = $this->createForm(ParametresBoutiqueType::class, $valeurs);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Le logo d'abord : un message de succès posé avant lui s'afficherait
            // au-dessus du formulaire réaffiché en cas d'échec, annonçant un
            // enregistrement qui n'a pas eu lieu.
            if ($this->appliquerLogo($form, $parametres)) {
                $saisie = [];
                foreach (self::clesSaisies() as $cle) {
                    $saisie[$cle->value] = $form->getData()[ParametresBoutiqueType::champ($cle)] ?? '';
                }

                $parametres->enregistrer($saisie);
                $this->addFlash('success', 'Paramètres enregistrés. Les prochains tickets les reprennent.');

                return $this->redirectToRoute('admin_parametres', [], Response::HTTP_SEE_OTHER);
            }
        }

        // Groupes affichés dans l'ordre du catalogue, sans les coder en dur ici.
        $groupes = [];
        foreach (self::clesSaisies() as $cle) {
            $groupes[$cle->groupe()][] = ParametresBoutiqueType::champ($cle);
        }

        return $this->rendreFormulaire('admin/parametres.html.twig', $form, [
            'groupes' => $groupes,
            'logo' => $parametres->cheminLogo(),
        ]);
    }

    /**
     * Applique le logo soumis : dépôt du nouveau fichier, retrait de l'ancien.
     *
     * Renvoie `false` si l'image n'a pas pu être traitée — l'appelant réaffiche
     * alors le formulaire avec le message, en 422. Un logo illisible ne doit pas
     * faire échouer l'enregistrement du reste en silence, mais il ne doit pas non
     * plus passer inaperçu : la boutique croirait avoir un logo et le ticket
     * sortirait sans.
     */
    private function appliquerLogo(FormInterface $form, ParametresBoutique $parametres): bool
    {
        $fichier = $form->get(ParametresBoutiqueType::CHAMP_LOGO)->getData();
        $retirer = (bool) $form->get(ParametresBoutiqueType::CHAMP_LOGO_RETRAIT)->getData();

        if (null === $fichier && !$retirer) {
            return true;
        }

        try {
            // Un nouveau fichier l'emporte sur la case « retirer » : c'est le geste
            // le plus récent, et le plus explicite.
            $parametres->definirLogo(null !== $fichier ? $this->logos->enregistrer($fichier) : null);
        } catch (\RuntimeException $e) {
            $form->get(ParametresBoutiqueType::CHAMP_LOGO)->addError(new FormError($e->getMessage()));

            return false;
        }

        return true;
    }

    /**
     * Les clés saisies au clavier, dans l'ordre du catalogue.
     *
     * @return list<CleParametre>
     */
    private static function clesSaisies(): array
    {
        return array_values(array_filter(
            CleParametre::cases(),
            static fn (CleParametre $cle): bool => !$cle->estFichier(),
        ));
    }
}
