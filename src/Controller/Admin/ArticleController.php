<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\FicheTechnique;
use App\Entity\LigneFicheTechnique;
use App\Controller\Trait\ReponseFormulaire;
use App\Form\ArticleType;
use App\Form\ImportArticlesType;
use App\Form\LigneFicheTechniqueType;
use App\Repository\ArticleRepository;
use App\Repository\FamilleProduitRepository;
use App\Security\Permission;
use App\Service\AuditLogger;
use App\Service\CalculateurCoutMatiere;
use App\Service\ImageArticle;
use App\Service\ImportArticles;
use App\Service\RapportImportArticles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/articles')]
#[IsGranted('ROLE_GERANT')]
class ArticleController extends AbstractController
{
    use ReponseFormulaire;

    /** Clé de session portant le compte rendu d'import jusqu'à son affichage. */
    private const SESSION_RAPPORT_IMPORT = 'admin.import_articles.rapport';

    public function __construct(private readonly ImageArticle $images)
    {
    }

    #[Route('', name: 'admin_article_index', methods: ['GET'])]
    public function index(
        Request $request,
        ArticleRepository $articles,
        FamilleProduitRepository $familles,
        CalculateurCoutMatiere $calculateur,
    ): Response {
        $familleId = $request->query->get('famille');
        $famille = $familleId ? $familles->find($familleId) : null;
        $recherche = $request->query->get('q');
        $statut = $request->query->get('statut');
        $actif = match ($statut) {
            'actifs' => true,
            'inactifs' => false,
            default => null,
        };

        $resultats = $articles->rechercher($famille, $recherche, $actif, $request->query->getInt('page', 1));

        // Les coûts ne sont même pas calculés pour qui n'a pas le droit de les voir :
        // rien ne peut alors fuiter par le gabarit. Depuis la pagination, seuls
        // les articles de la page en cours sont calculés — le coût d'affichage ne
        // dépend plus de la taille du catalogue.
        $couts = [];
        if ($this->isGranted(Permission::ARTICLE_VOIR_COUT)) {
            foreach ($resultats->items as $article) {
                $couts[$article->getId()] = $calculateur->calculer($article);
            }
        }

        return $this->render('admin/article/index.html.twig', [
            'articles' => $resultats,
            'couts' => $couts,
            'familles' => $familles->findBy([], ['position' => 'ASC']),
            'famille_active' => $famille,
            'recherche' => $recherche,
            'statut' => $statut,
        ]);
    }

    #[Route('/nouveau', name: 'admin_article_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $article = new Article('', 0, 'pièce');
        $peutFixerPrix = $this->isGranted(Permission::ARTICLE_MODIFIER_PRIX, $article);

        $form = $this->createForm(ArticleType::class, $article, ['modifier_prix' => $peutFixerPrix]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // La photo d'abord : un message de succès posé avant elle s'afficherait
            // au-dessus du formulaire réaffiché en cas d'échec, annonçant une
            // création qui n'a pas eu lieu.
            if (!$this->appliquerImage($form, $article)) {
                return $this->rendreFormulaire('admin/article/form.html.twig', $form, ['titre' => 'Nouvel article']);
            }

            // Sans habilitation sur le prix, l'article naît à 0 FCFA : on le force
            // inactif pour qu'il ne parte pas gratuitement en caisse. Créer puis
            // recréer un article ne permet donc pas de contourner la règle de prix.
            if (!$peutFixerPrix) {
                $article->setActif(false);
                $this->addFlash('success', 'Article créé sans prix : il reste inactif jusqu\'à ce que la dirigeante fixe son prix de vente.');
            } else {
                $this->addFlash('success', 'Article « '.$article->getNom().' » créé.');
            }

            $em->persist($article);
            $em->flush();

            return $this->redirectToRoute('admin_article_show', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->rendreFormulaire('admin/article/form.html.twig', $form, ['titre' => 'Nouvel article']);
    }

    /**
     * Import du catalogue en masse : un nom, un prix de vente, une ligne par article.
     *
     * Déclaré **avant** `/{id}` : celle-ci exige un identifiant numérique
     * (`requirements`), `/importer` ne peut donc pas y être confondu, mais l'ordre
     * garde la lecture des routes évidente.
     *
     * Le compte rendu voyage par la **session** jusqu'à une redirection, et non dans
     * la réponse du POST : Turbo Drive n'affiche pas le corps d'une soumission qui
     * répond 200, il attend une redirection ou un statut d'erreur. Rendu directement,
     * le compte rendu ne s'afficherait donc jamais — l'écran resterait figé sur le
     * formulaire alors que les articles auraient bel et bien été créés.
     */
    #[Route('/importer', name: 'admin_article_import', methods: ['GET', 'POST'])]
    public function import(Request $request, ImportArticles $import): Response
    {
        // Même règle qu'à la création à l'unité : sans l'habilitation, les prix du
        // fichier sont écartés et les articles naissent inactifs. L'import ne doit
        // pas être la porte de service du prix de vente.
        $avecPrix = $this->isGranted(Permission::ARTICLE_MODIFIER_PRIX);

        $form = $this->createForm(ImportArticlesType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $fichier */
            $fichier = $form->get('fichier')->getData();
            $contenu = (string) file_get_contents($fichier->getPathname());

            $rapport = $import->importer($this->enUtf8($contenu), $avecPrix);

            if ($rapport->estVide()) {
                $form->get('fichier')->addError(new FormError(
                    'Ce fichier ne contient aucune ligne exploitable. Attendu : un nom d\'article et un prix par ligne.'
                ));

                return $this->rendreFormulaire('admin/article/import.html.twig', $form, [
                    'avec_prix' => $avecPrix,
                    'rapport' => null,
                ]);
            }

            $request->getSession()->set(self::SESSION_RAPPORT_IMPORT, $rapport->enTableau());

            return $this->redirectToRoute('admin_article_import', [], Response::HTTP_SEE_OTHER);
        }

        // Relevé **et retiré** : un rafraîchissement de la page ne doit pas
        // réafficher le compte rendu d'un import déjà fait, on croirait l'avoir
        // rejoué.
        $precedent = $request->getSession()->remove(self::SESSION_RAPPORT_IMPORT);

        return $this->rendreFormulaire('admin/article/import.html.twig', $form, [
            'avec_prix' => $avecPrix,
            'rapport' => \is_array($precedent) ? RapportImportArticles::depuisTableau($precedent) : null,
        ]);
    }

    /**
     * Modèle de fichier à remplir.
     *
     * Le format ne se devine pas, et un fichier mal formé revient en autant de
     * lignes rejetées : mieux vaut partir du bon squelette. **BOM UTF-8** en tête,
     * sans quoi Excel sous Windows lit le fichier en ANSI et massacre les accents —
     * même raison que pour les exports comptables.
     */
    #[Route('/importer/modele', name: 'admin_article_import_modele', methods: ['GET'])]
    public function importModele(): Response
    {
        $lignes = [
            'Nom;Prix de vente (FCFA)',
            'Baguette;150',
            'Pain au chocolat;300',
            'Sandwich poulet;1500',
        ];

        return new Response("\u{FEFF}".implode("\r\n", $lignes)."\r\n", Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="modele-articles.csv"',
        ]);
    }

    #[Route('/{id}', name: 'admin_article_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Article $article): Response
    {
        return $this->render('admin/article/show.html.twig', [
            'article' => $article,
            'ligne_form' => $this->creerFicheForm($article)->createView(),
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_article_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Article $article, EntityManagerInterface $em, AuditLogger $audit): Response
    {
        // Relevé avant liaison du formulaire : ensuite l'entité porte le nouveau prix.
        $ancienPrix = $article->getPrixVenteTtc();

        $form = $this->createForm(ArticleType::class, $article, [
            'modifier_prix' => $this->isGranted(Permission::ARTICLE_MODIFIER_PRIX, $article),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->appliquerImage($form, $article)) {
                return $this->rendreFormulaire('admin/article/form.html.twig', $form, ['titre' => 'Modifier l\'article', 'article' => $article]);
            }

            $em->flush();

            if ($article->getPrixVenteTtc() !== $ancienPrix) {
                $audit->prixModifie($article, $ancienPrix, $article->getPrixVenteTtc());
            }

            $this->addFlash('success', 'Article mis à jour.');

            return $this->redirectToRoute('admin_article_show', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->rendreFormulaire('admin/article/form.html.twig', $form, ['titre' => 'Modifier l\'article', 'article' => $article]);
    }

    #[Route('/{id}/basculer', name: 'admin_article_toggle', methods: ['POST'])]
    public function toggle(Request $request, Article $article, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('basculer_article_'.$article->getId(), (string) $request->request->get('_token'))) {
            $article->setActif(!$article->isActif());
            $em->flush();
            $this->addFlash('success', $article->isActif() ? 'Article activé.' : 'Article désactivé.');
        }

        return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('admin_article_index'));
    }

    #[Route('/{id}/supprimer', name: 'admin_article_delete', methods: ['POST'])]
    public function delete(Request $request, Article $article, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('supprimer_article_'.$article->getId(), (string) $request->request->get('_token'))) {
            // Le fichier est relevé avant la suppression et effacé après : sinon
            // il resterait sur le disque sans plus rien pour le désigner.
            $image = $article->getImage();

            $em->remove($article);
            $em->flush();
            $this->images->supprimer($image);

            $this->addFlash('success', 'Article supprimé.');
        }

        return $this->redirectToRoute('admin_article_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/fiche/lignes', name: 'admin_article_fiche_add', methods: ['POST'])]
    public function ficheAdd(Request $request, Article $article, EntityManagerInterface $em): Response
    {
        $fiche = $this->obtenirFiche($article, $em);
        $form = $this->creerFicheForm($article);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $donnees = $form->getData();
            new LigneFicheTechnique($fiche, $donnees['matierePremiere'], (int) $donnees['quantite'], (int) ($donnees['pourcentagePerte'] ?? 0));
            $em->flush();
            $this->addFlash('success', 'Matière première ajoutée à la fiche technique.');
            $form = $this->creerFicheForm($article);
        }

        return $this->rendreFiche($request, $article, $form);
    }

    #[Route('/{id}/fiche/lignes/{ligneId}/retirer', name: 'admin_article_fiche_remove', methods: ['POST'])]
    public function ficheRemove(
        Request $request,
        Article $article,
        #[MapEntity(id: 'ligneId')] LigneFicheTechnique $ligne,
        EntityManagerInterface $em,
    ): Response {
        if ($ligne->getFicheTechnique()->getArticle() !== $article) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('retirer_ligne_'.$ligne->getId(), (string) $request->request->get('_token'))) {
            $em->remove($ligne);
            $em->flush();
            $this->addFlash('success', 'Matière première retirée de la fiche technique.');
        }

        return $this->rendreFiche($request, $article, $this->creerFicheForm($article));
    }

    /**
     * Ramène le contenu téléversé en UTF-8.
     *
     * Excel sous Windows francophone enregistre ses CSV en **Windows-1252**, pas en
     * UTF-8 : sans conversion, « Pâté en croûte » arrive en base en « PÃ¢tÃ© ». Le
     * nom serait à retaper article par article, et le fichier d'origine paraîtrait
     * pourtant correct à qui l'ouvre dans son tableur.
     *
     * L'ordre du test compte : tout fichier UTF-8 valide est laissé tel quel, la
     * conversion ne s'applique qu'à ce qui ne peut pas en être.
     */
    private function enUtf8(string $contenu): string
    {
        return mb_check_encoding($contenu, 'UTF-8')
            ? $contenu
            : (string) mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
    }

    /**
     * Applique la photo soumise : dépôt du nouveau fichier, retrait de l'ancien.
     *
     * Renvoie `false` si l'image n'a pas pu être traitée — l'appelant réaffiche
     * alors le formulaire avec le message, en 422. Une photo illisible ne doit
     * pas faire échouer l'enregistrement du reste de l'article en silence, mais
     * elle ne doit pas non plus passer inaperçue.
     */
    private function appliquerImage(FormInterface $form, Article $article): bool
    {
        $fichier = $form->get('imageFichier')->getData();
        $retirer = (bool) $form->get('supprimerImage')->getData();

        if (null === $fichier && !$retirer) {
            return true;
        }

        $ancienne = $article->getImage();

        try {
            // Une nouvelle photo l'emporte sur la case « retirer » : c'est le
            // geste le plus récent, et le plus explicite.
            $article->setImage(null !== $fichier ? $this->images->enregistrer($fichier) : null);
        } catch (\RuntimeException $e) {
            $form->get('imageFichier')->addError(new FormError($e->getMessage()));

            return false;
        }

        // L'ancienne n'est supprimée qu'une fois la nouvelle écrite : en cas
        // d'échec, l'article garde la photo qu'il avait.
        if ($ancienne !== $article->getImage()) {
            $this->images->supprimer($ancienne);
        }

        return true;
    }

    private function creerFicheForm(Article $article): FormInterface
    {
        return $this->createForm(LigneFicheTechniqueType::class, null, [
            'action' => $this->generateUrl('admin_article_fiche_add', ['id' => $article->getId()]),
        ]);
    }

    private function obtenirFiche(Article $article, EntityManagerInterface $em): FicheTechnique
    {
        $fiche = $article->getFicheTechnique();
        if (null === $fiche) {
            $fiche = new FicheTechnique($article);
            $em->persist($fiche);
        }

        return $fiche;
    }

    /**
     * Renvoie le fragment Turbo (frame) pour une requête Turbo, sinon redirige
     * vers la fiche article (dégradation gracieuse sans JavaScript).
     */
    private function rendreFiche(Request $request, Article $article, FormInterface $form): Response
    {
        if ($request->headers->has('Turbo-Frame')) {
            return $this->render('admin/article/_fiche_frame.html.twig', [
                'article' => $article,
                'ligne_form' => $form->createView(),
            ]);
        }

        return $this->redirectToRoute('admin_article_show', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
