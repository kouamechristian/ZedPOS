<?php

namespace App\Controller\Admin;

use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Repository\SessionCaisseRepository;
use App\Security\Permission;
use App\Service\ChiffreCaisseService;
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
 * Rapport des ventes d'une caisse, ventilé par famille de produits.
 *
 * Ce que la gérante fait en fin de service : elle choisit une caisse — celle
 * qui est ouverte, ou une caisse déjà clôturée —, relit ce qui en est sorti
 * rangé par famille, et rapproche le total du bas de page des espèces en
 * tiroir. Le PDF est ce qui reste au classeur, ou ce qu'on envoie à la
 * dirigeante.
 *
 * Le filtre suit la **caisse** et non la journée : une caisse ouverte le soir et
 * clôturée le lendemain matin ne se coupe pas en deux rapports, et le total du
 * bas se rapproche du Z de cette caisse, ligne à ligne.
 *
 * **Lecture seule** : deux routes GET, et il ne doit jamais en être ajouté
 * d'écriture — un rapport relit ce que la caisse a produit, il ne le corrige pas.
 *
 * L'écran et le PDF consomment le **même** {@see VentesDuJour} : ils ne peuvent
 * pas afficher deux chiffres différents.
 */
#[Route('/admin/ventes/rapport')]
#[IsGranted('ROLE_GERANT')]
#[IsGranted(Permission::VOIR_TOUTES_VENTES)]
class RapportVenteController extends AbstractController
{
    /** Nombre de caisses proposées dans le sélecteur, la plus récente d'abord. */
    private const CAISSES_PROPOSEES = 60;

    public function __construct(
        private readonly ChiffreCaisseService $chiffreCaisse,
        private readonly SessionCaisseRepository $sessions,
    ) {
    }

    #[Route('', name: 'admin_ventes_rapport', methods: ['GET'])]
    public function index(Request $request, RapportVentesJournee $rapports): Response
    {
        $session = $this->session($request);

        return $this->render('admin/rapport_vente/index.html.twig', [
            'rapport' => null !== $session ? $rapports->pourSession($session) : null,
            'session' => $session,
            'caisses' => $this->caissesProposees($session),
        ]);
    }

    /**
     * Le même rapport, en PDF.
     *
     * Affiché dans la visionneuse du navigateur plutôt que téléchargé d'office :
     * on le relit avant de l'imprimer ou de l'enregistrer.
     */
    #[Route('.pdf', name: 'admin_ventes_rapport_pdf', methods: ['GET'])]
    public function pdf(
        Request $request,
        RapportVentesJournee $rapports,
        ParametresBoutique $boutique,
        GenerateurPdf $pdf,
    ): Response {
        $session = $this->session($request) ?? throw $this->createNotFoundException('Aucune caisse.');
        $rapport = $rapports->pourSession($session);

        $html = $this->renderView('admin/rapport_vente/pdf.html.twig', [
            'rapport' => $rapport,
            'session' => $session,
            'boutique' => $boutique->nom(),
            // Dompdf n'a pas de navigateur derrière lui pour aller chercher une
            // URL : le logo voyage dans le document.
            'logo' => $boutique->logoDataUri(),
            'edite_le' => new \DateTimeImmutable(),
            'edite_par' => $this->getUser() instanceof Utilisateur ? $this->getUser()->getNom() : '',
        ]);

        return $pdf->reponse($html, $this->nomFichier($session), telecharger: false);
    }

    /**
     * Caisse demandée ; à défaut la caisse ouverte, à défaut la dernière
     * clôturée. Un identifiant illisible ou inconnu retombe sur ce défaut — un
     * lien périmé n'immobilise pas un écran de gestion.
     */
    private function session(Request $request): ?SessionCaisse
    {
        // Lu en chaîne puis converti, et non par `getInt()` : sous Symfony 7,
        // celui-ci répond 400 sur un champ vide.
        $id = (int) $request->query->get('session', '0');
        $chiffre = $this->chiffreCaisse->courant($id > 0 ? $id : null);

        return null !== $chiffre ? $this->sessions->find($chiffre->sessionId) : null;
    }

    /**
     * Caisses du sélecteur, rangées en ouvertes puis clôturées. La caisse
     * retenue y figure toujours, même hors des plus récentes — sinon le filtre
     * disparaîtrait de l'écran en se croyant appliqué.
     *
     * @return array{ouvertes: list<array{id: int, caissier: string, ouverte: bool, ouvertureAt: \DateTimeImmutable}>, cloturees: list<array{id: int, caissier: string, ouverte: bool, ouvertureAt: \DateTimeImmutable}>}
     */
    private function caissesProposees(?SessionCaisse $session): array
    {
        $caisses = $this->chiffreCaisse->recentes(self::CAISSES_PROPOSEES);

        if (null !== $session && !\in_array($session->getId(), array_column($caisses, 'id'), true)) {
            $caisses[] = [
                'id' => (int) $session->getId(),
                'caissier' => $session->getUtilisateur()->getNom(),
                'ouverte' => $session->estOuverte(),
                'ouvertureAt' => $session->getOuvertureAt(),
            ];
        }

        return [
            'ouvertes' => array_values(array_filter($caisses, static fn (array $c) => $c['ouverte'])),
            'cloturees' => array_values(array_filter($caisses, static fn (array $c) => !$c['ouverte'])),
        ];
    }

    /**
     * Nom du fichier PDF : la date d'ouverture, la caissière et le numéro de
     * caisse y figurent. Un dossier de fins de journée se classe sinon sur douze
     * fichiers appelés « rapport.pdf ».
     */
    private function nomFichier(SessionCaisse $session): string
    {
        return 'ventes_'.$session->getOuvertureAt()->format('Y-m-d')
            .'_'.$session->getUtilisateur()->getNom()
            .'_caisse-'.$session->getId().'.pdf';
    }
}
