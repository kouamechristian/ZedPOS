<?php

namespace App\Service;

use App\Entity\Article;
use App\Repository\ArticleRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import d'articles en masse depuis un fichier CSV : **nom et prix de vente**.
 *
 * Sert à garnir un catalogue au démarrage, ou à ajouter une gamme entière. Saisir
 * soixante articles un par un dans le formulaire prend une matinée ; ils sont déjà
 * dans un tableur, il n'y a pas de raison de les retaper.
 *
 * **L'import n'écrase jamais rien.** Un nom déjà au catalogue est *ignoré*, pas mis
 * à jour : le prix de vente est une décision réservée à la dirigeante
 * ({@see \App\Security\Permission::ARTICLE_MODIFIER_PRIX}), et un import qui
 * réécrirait les prix existants serait le moyen le plus simple de contourner cette
 * règle — en plus de changer, sur un fichier mal daté, des prix que personne n'a
 * voulu toucher.
 *
 * **Le prix n'est repris que si l'auteur a le droit de le fixer** (`$avecPrix`).
 * Sinon les articles naissent à 0 FCFA et **inactifs**, exactement comme un article
 * créé sans prix dans le formulaire à l'unité. Sans cette symétrie, l'import
 * deviendrait la porte de service : il suffirait d'importer un fichier pour fixer
 * des prix qu'on n'a pas le droit de saisir à l'écran.
 */
class ImportArticles
{
    /**
     * Plafond de lignes traitées en une fois.
     *
     * Le catalogue d'une boulangerie se compte en dizaines d'articles : au-delà de
     * ce plafond, ce n'est pas un catalogue qu'on importe, c'est un fichier qui
     * n'est pas celui qu'on croit. Mieux vaut le dire que d'écrire mille articles
     * qu'il faudra ensuite désactiver un par un.
     */
    public const MAX_LIGNES = 500;

    /** Unité par défaut : celle de la quasi-totalité du catalogue, modifiable après coup. */
    private const UNITE_PAR_DEFAUT = 'pièce';

    /** Longueur de la colonne `article.nom`. Tronquer un nom serait pire que le refuser. */
    private const NOM_MAX = 150;

    /** Séparateurs reconnus, par ordre de fréquence sous Excel francophone. */
    private const SEPARATEURS = [';', "\t", ','];

    /**
     * Libellés reconnus en première colonne d'une ligne d'en-têtes.
     *
     * Les formes accentuées **et** non accentuées sont listées : un tableur écrit
     * « Libellé », un export en écrit « Libelle », et faire dépendre la lecture
     * d'une translittération (`iconv`, dont le comportement varie selon la
     * plateforme) rendrait le résultat dépendant du poste.
     */
    private const LIBELLES_EN_TETE = [
        'nom', 'name', 'article', 'produit', 'product',
        'libellé', 'libelle', 'désignation', 'designation', 'intitulé', 'intitule',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArticleRepository $articles,
    ) {
    }

    /**
     * Analyse le contenu, crée les articles retenus et rend le compte rendu.
     *
     * Une seule écriture en base, à la fin : un fichier à moitié importé serait le
     * pire des états, personne ne saurait où reprendre.
     *
     * @param bool $avecPrix l'auteur a-t-il l'habilitation de fixer un prix
     */
    public function importer(string $contenu, bool $avecPrix): RapportImportArticles
    {
        $lignes = $this->lignes($contenu);
        $separateur = $this->separateur($lignes);

        // Un seul aller en base pour les doublons : comparer nom par nom ferait une
        // requête par ligne du fichier.
        $connus = [];
        foreach ($this->articles->tousLesNoms() as $nom) {
            $connus[$this->cle($nom)] = true;
        }

        $creees = [];
        $doublons = [];
        $rejets = [];
        $prixIgnores = false;
        $traitees = 0;

        foreach ($lignes as $numero => $brute) {
            // Le plafond compte les lignes **traitées**, pas les numéros de ligne :
            // un fichier aéré de lignes vides ne doit pas être coupé avant la fin.
            if (++$traitees > self::MAX_LIGNES) {
                $rejets[] = [
                    'ligne' => $numero,
                    'contenu' => $brute,
                    'raison' => \sprintf('Plafond de %d lignes atteint : la suite du fichier n\'a pas été traitée.', self::MAX_LIGNES),
                ];
                break;
            }

            $cellules = str_getcsv($brute, $separateur, '"', '\\');
            $nom = trim((string) ($cellules[0] ?? ''));
            $prixBrut = trim((string) ($cellules[1] ?? ''));

            // Ligne d'en-têtes (« Nom;Prix ») : reconnue à ceci que sa première
            // ligne annonce des colonnes au lieu de porter un prix lisible. On la
            // saute en silence — la signaler comme une erreur serait un faux
            // positif à chaque fichier sorti d'un tableur.
            if (1 === $traitees && $this->ressembleAUnEnTete($nom, $prixBrut)) {
                continue;
            }

            if ('' === $nom) {
                $rejets[] = ['ligne' => $numero, 'contenu' => $brute, 'raison' => 'Nom d\'article absent.'];
                continue;
            }

            if (mb_strlen($nom) > self::NOM_MAX) {
                $rejets[] = [
                    'ligne' => $numero,
                    'contenu' => $brute,
                    'raison' => \sprintf('Nom trop long (%d caractères, %d au maximum).', mb_strlen($nom), self::NOM_MAX),
                ];
                continue;
            }

            // Un prix illisible est une **erreur**, un prix absent une omission :
            // le premier fait rejeter la ligne, le second laisse l'article naître
            // sans prix — donc inactif, comme au formulaire.
            $prix = 0;
            if ('' !== $prixBrut) {
                $centimes = $this->prixEnCentimes($prixBrut);

                if (null === $centimes) {
                    $rejets[] = [
                        'ligne' => $numero,
                        'contenu' => $brute,
                        'raison' => \sprintf('Prix illisible : « %s ». Attendu un montant en FCFA, sans décimale.', $prixBrut),
                    ];
                    continue;
                }

                if ($avecPrix) {
                    $prix = $centimes;
                } else {
                    // Le fichier portait bien un prix : on le dit, plutôt que de
                    // laisser croire que le fichier était incomplet.
                    $prixIgnores = true;
                }
            }

            $cle = $this->cle($nom);
            if (isset($connus[$cle])) {
                $doublons[] = $nom;
                continue;
            }
            // Marqué tout de suite : deux fois le même nom **dans le fichier** ne
            // doit pas créer deux articles indistinguables en caisse.
            $connus[$cle] = true;

            $article = new Article($nom, $prix, self::UNITE_PAR_DEFAUT);
            // Un article à 0 FCFA ne part pas en caisse : même règle qu'à la
            // création à l'unité, sinon l'import gratifierait les clients.
            $article->setActif($prix > 0);

            $this->em->persist($article);
            $creees[] = ['nom' => $nom, 'prix' => $prix];
        }

        if ([] !== $creees) {
            $this->em->flush();
        }

        return new RapportImportArticles($creees, $doublons, $rejets, $prixIgnores);
    }

    /**
     * Découpe le contenu en lignes non vides, indexées à partir de 1.
     *
     * Le **BOM UTF-8 est retiré** : Excel sous Windows en pose un en tête de
     * fichier, et sans cela le premier nom d'article commencerait par trois octets
     * invisibles — « Baguette » ne serait plus jamais reconnu comme un doublon de
     * « Baguette », et personne ne verrait pourquoi.
     *
     * @return array<int, string>
     */
    private function lignes(string $contenu): array
    {
        $contenu = preg_replace('/^\x{FEFF}/u', '', $contenu) ?? $contenu;

        $lignes = [];
        $numero = 0;
        foreach (preg_split('/\r\n|\r|\n/', $contenu) ?: [] as $brute) {
            ++$numero;
            if ('' !== trim($brute, " \t;,")) {
                $lignes[$numero] = $brute;
            }
        }

        return $lignes;
    }

    /**
     * Séparateur du fichier, déduit de son contenu.
     *
     * Un point-virgule sous Excel francophone, une virgule ailleurs, une
     * tabulation quand on colle depuis un tableur : demander à l'exploitant
     * lequel il utilise serait lui demander d'ouvrir son fichier dans un éditeur
     * de texte.
     */
    private function separateur(array $lignes): string
    {
        $premiere = reset($lignes);

        if (!\is_string($premiere)) {
            return self::SEPARATEURS[0];
        }

        $meilleur = self::SEPARATEURS[0];
        $occurrences = 0;
        foreach (self::SEPARATEURS as $candidat) {
            $compte = substr_count($premiere, $candidat);
            if ($compte > $occurrences) {
                $occurrences = $compte;
                $meilleur = $candidat;
            }
        }

        return $meilleur;
    }

    /**
     * Le prix, en centimes de FCFA, ou `null` si le montant n'est pas exploitable.
     *
     * La saisie se fait en **FCFA entiers**, comme partout ailleurs dans
     * l'application ; le stockage est en centimes. Les séparateurs de milliers
     * d'un tableur sont tolérés :
     *
     * - `1500`, `1 500`, `1 500 FCFA` → 1 500 FCFA ;
     * - `1.500`, `1,500` → 1 500 FCFA (**trois** chiffres après le séparateur : ce
     *   sont des milliers, jamais des décimales) ;
     * - `1500,00` → 1 500 FCFA (décimale nulle, tolérée : les tableurs en posent) ;
     * - `1500,50` → **refusé**. Le franc CFA ne circule pas en centimes ; arrondir
     *   un montant en silence est précisément ce que cette application interdit,
     *   et mieux vaut faire corriger la ligne que deviner l'intention.
     */
    private function prixEnCentimes(string $brut): ?int
    {
        // Espaces de toute nature (dont l'insécable des tableurs) et mention de la
        // devise : ce sont des ornements d'affichage, pas des chiffres.
        $montant = preg_replace('/(\x{00a0}|\s)+/u', '', $brut) ?? $brut;
        $montant = preg_replace('/(fcfa|xof|f)$/iu', '', $montant) ?? $montant;

        if (preg_match('/^\d+$/', $montant)) {
            return (int) $montant * 100;
        }

        // Trois chiffres après le séparateur : séparateur de milliers.
        if (preg_match('/^(\d+)[.,](\d{3})$/', $montant, $groupes)) {
            return (int) ($groupes[1].$groupes[2]) * 100;
        }

        // Une ou deux décimales : acceptées seulement si elles sont nulles.
        if (preg_match('/^(\d+)[.,](\d{1,2})$/', $montant, $groupes)) {
            return 0 === (int) $groupes[2] ? (int) $groupes[1] * 100 : null;
        }

        return null;
    }

    /**
     * Une ligne d'en-têtes se reconnaît à **deux** conditions réunies : sa deuxième
     * colonne n'est pas un montant, et sa première annonce un libellé de colonne.
     *
     * La seule première condition ne suffit pas, et c'est un piège qui coûte des
     * données : sur `Baguette;gratuit` suivi de lignes correctes, elle ferait passer
     * la baguette pour un en-tête — la ligne serait **silencieusement supprimée** au
     * lieu d'être signalée comme un prix illisible. Une erreur de saisie doit
     * ressortir dans le compte rendu, jamais disparaître.
     *
     * Le libellé est donc testé, ce qui lie la reconnaissance à une liste de mots.
     * C'est assumé : l'application est en français, et un en-tête non reconnu est
     * **rejeté avec sa raison** plutôt qu'avalé — le pire des deux cas reste visible.
     */
    private function ressembleAUnEnTete(string $nom, string $prix): bool
    {
        if (null !== $this->prixEnCentimes($prix)) {
            return false;
        }

        $libelle = mb_strtolower(trim($nom));

        foreach (self::LIBELLES_EN_TETE as $connu) {
            // Par préfixe et non à l'identique : « Nom de l'article » ou
            // « Désignation produit » sont des en-têtes tout aussi courants.
            if (str_starts_with($libelle, $connu)) {
                return true;
            }
        }

        return false;
    }

    /** Clé de comparaison des noms : ni la casse ni les espaces ne distinguent deux articles. */
    private function cle(string $nom): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($nom)) ?? $nom);
    }
}
