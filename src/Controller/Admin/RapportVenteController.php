<?php

namespace App\Controller\Admin;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use App\Repository\VenteRepository;
use App\Security\Permission;
use App\Service\GenerateurPdf;
use App\Service\ParametresBoutique;
use App\Service\Rapport\RapportVentesJournee;
use App\Service\Rapport\VentesDuJour;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Rapport de journée par caissière, ventilé par famille de produits.
 *
 * Ce que la gérante fait en fin de service : elle choisit une journée et une
 * caissière, relit ce qui est sorti du comptoir rangé par famille, et rapproche
 * le total du bas de page des espèces en tiroir. Le PDF est ce qui reste au
 * classeur, ou ce qu'on envoie à la dirigeante.
 *
 * **Lecture seule** : deux routes GET, et il ne doit jamais en être ajouté
 * d'écriture — un rapport relit ce que la caisse a produit, il ne le corrige pas.
 *
 * L'écran et le PDF consomment le **même** {@see VentesDuJour} : ils ne peuvent
 * pas afficher deux chiffres différents. C'est la même règle que l'espace
 * comptable, et pour la même raison — un rapport qu'on imprime et qui ne
 * correspond pas à l'écran fait perdre confiance dans les deux.
 */
#[Route('/admin/ventes/rapport')]
#[IsGranted('ROLE_GERANT')]
#[IsGranted(Permission::VOIR_TOUTES_VENTES)]
class RapportVenteController extends AbstractController
{
    #[Route('', name: 'admin_ventes_rapport', methods: ['GET'])]
    public function index(
        Request $request,
        RapportVentesJournee $rapports,
        VenteRepository $ventes,
        UtilisateurRepository $utilisateurs,
    ): Response {
        $jour = $this->jour($request);
        $caissier = $this->caissier($request, $utilisateurs);

        return $this->render('admin/rapport_vente/index.html.twig', [
            'rapport' => $rapports->pour($jour, $caissier),
            'jour' => $jour,
            'caissier' => $caissier,
            // Le sélecteur ne propose que les caissières ayant encaissé ce
            // jour-là : proposer toute l'équipe reviendrait à proposer des
            // rapports vides. Celle qui est retenue y figure toujours, même sans
            // vente — sinon le filtre disparaîtrait de l'écran en se croyant
            // appliqué.
            'caissiers' => $this->caissiersProposes($ventes->caissiersDuJour($jour), $caissier),
        ]);
    }

    /**
     * Le même rapport, en PDF.
     *
     * Affiché dans la visionneuse du navigateur plutôt que téléchargé d'office :
     * on le relit avant de l'imprimer ou de l'enregistrer, et sur la tablette du
     * comptoir un fichier qui tombe dans « Téléchargements » sans rien afficher
     * donne l'impression que le bouton n'a rien fait.
     */
    #[Route('.pdf', name: 'admin_ventes_rapport_pdf', methods: ['GET'])]
    public function pdf(
        Request $request,
        RapportVentesJournee $rapports,
        UtilisateurRepository $utilisateurs,
        ParametresBoutique $boutique,
        GenerateurPdf $pdf,
    ): Response {
        $jour = $this->jour($request);
        $caissier = $this->caissier($request, $utilisateurs);
        $rapport = $rapports->pour($jour, $caissier);

        $html = $this->renderView('admin/rapport_vente/pdf.html.twig', [
            'rapport' => $rapport,
            'boutique' => $boutique->nom(),
            // Dompdf n'a pas de navigateur derrière lui pour aller chercher une
            // URL : le logo voyage dans le document.
            'logo' => $boutique->logoDataUri(),
            'edite_le' => new \DateTimeImmutable(),
            'edite_par' => $this->getUser() instanceof Utilisateur ? $this->getUser()->getNom() : '',
        ]);

        return $pdf->reponse($html, $this->nomFichier($rapport), telecharger: false);
    }

    /**
     * Journée demandée. Une date absente vaut aujourd'hui, une date illisible
     * aussi : un lien tronqué ou recopié à la main n'immobilise pas un écran de
     * gestion — même parti pris que la pagination.
     */
    private function jour(Request $request): \DateTimeImmutable
    {
        $saisi = (string) $request->query->get('jour', '');
        $jour = \DateTimeImmutable::createFromFormat('Y-m-d', $saisi);

        return false !== $jour ? $jour->setTime(0, 0) : new \DateTimeImmutable('today');
    }

    /**
     * Caissière retenue, ou `null` pour toute l'équipe.
     *
     * Un identifiant inconnu — compte supprimé, lien périmé — retombe sur toute
     * l'équipe plutôt que sur une erreur.
     */
    private function caissier(Request $request, UtilisateurRepository $utilisateurs): ?Utilisateur
    {
        // Lu en chaîne puis converti, et non par `getInt()` : sous Symfony 7,
        // celui-ci répond 400 sur un champ vide — or le sélecteur en envoie un
        // dès qu'on choisit « toutes les caissières ».
        $id = (int) $request->query->get('caissier', '0');

        return $id > 0 ? $utilisateurs->find($id) : null;
    }

    /**
     * @param list<Utilisateur> $duJour
     *
     * @return list<Utilisateur>
     */
    private function caissiersProposes(array $duJour, ?Utilisateur $caissier): array
    {
        if (null !== $caissier && !\in_array($caissier, $duJour, true)) {
            $duJour[] = $caissier;
        }

        return $duJour;
    }

    /**
     * Nom du fichier PDF : la journée et la caissière y figurent, comme pour les
     * CSV des rapports de stands. Un dossier de fins de journée se classe sinon
     * sur douze fichiers appelés « rapport.pdf ».
     */
    private function nomFichier(VentesDuJour $rapport): string
    {
        $qui = null !== $rapport->caissier ? '_'.$rapport->caissier->getNom() : '';

        return 'ventes_'.$rapport->jour->format('Y-m-d').$qui.'.pdf';
    }
}
