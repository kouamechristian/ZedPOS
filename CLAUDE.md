# ZedPOS

Logiciel de **caisse** et de **gestion de stock** pour une **boulangerie + fast-food**
située à **Abengourou (Côte d'Ivoire)**.

- **Devise** : Franc CFA (FCFA / XOF).
- **Langue** : français (interface, entités, code métier).
- **Fuseau horaire** : `Africa/Abidjan`.
- **Stack** : Symfony 7.4 (LTS), PHP 8.2, MariaDB, Twig, Doctrine ORM, Stimulus,
  Tailwind CSS via AssetMapper.

---

## Contexte métier

ZedPOS gère la vente au comptoir et le suivi du stock d'un établissement qui
fonctionne selon **deux modes de caisse** distincts :

### 1. Mode « boulangerie rapide »

Vente à haute cadence de produits standardisés (pain, viennoiseries, boissons).
- L'opérateur ajoute des articles à la volée, encaisse immédiatement.
- Pas de table, pas d'attente, pas de préparation à suivre : le client paie et part.
- Objectif : le moins de clics possible entre l'ajout d'un article et l'encaissement.

### 2. Mode « fast-food commande »

Vente de plats préparés à la demande (sandwichs, plats chauds, menus).
- Une **commande** est ouverte, éventuellement rattachée à une table ou un numéro.
- La commande passe par des états (en préparation → prête → servie/livrée) avant
  d'être encaissée.
- Plusieurs articles peuvent être ajoutés/modifiés tant que la commande n'est pas
  clôturée.

Les deux modes produisent au final une **vente** (l'entité comptable de référence).

---

## Conventions du projet

Ces conventions sont **impératives**. Toute contribution doit les respecter.

### Nommage

- **Les entités, propriétés et concepts métier sont en français**
  (`Vente`, `Produit`, `Commande`, `LigneDeVente`, `Stock`, `Caissier`…).
- Le français s'applique aussi aux champs de formulaire, aux libellés Twig et
  aux messages destinés à l'utilisateur.

### Argent

- **Tous les montants sont stockés en `int`, exprimés en centimes de FCFA**
  (1 FCFA = 100 centimes).
- **Jamais de `float` / `double` pour représenter de l'argent**, à aucun niveau
  (entité, calcul, transport). Les arrondis flottants sont interdits en comptabilité.
- Les calculs (totaux, remises, TVA le cas échéant) se font en arithmétique entière.
- Le formatage en FCFA pour l'affichage (division par 100, séparateurs) se fait
  **uniquement à la présentation** (Twig / DTO), jamais dans les données persistées.

### Intégrité des ventes

- **Aucune suppression physique d'une vente.** Une vente enregistrée ne doit jamais
  être supprimée de la base.
- Une vente erronée est **annulée** (statut / vente d'annulation / avoir), jamais
  effacée. L'historique doit rester traçable et auditable.

### Base de données

- MariaDB, configurée via `DATABASE_URL` (voir `.env` / `.env.local`).
- Migrations Doctrine obligatoires pour tout changement de schéma
  (`php bin/console make:migration` puis `doctrine:migrations:migrate`).
- **Compatibilité MariaDB 10.4 (XAMPP)** : cette version n'a pas la colonne
  `information_schema.CHECK_CONSTRAINTS.TABLE_NAME` attendue par l'introspection
  de Doctrine DBAL 4, ce qui casserait `make:migration` et `migrate`. Un middleware
  DBAL (`src/Doctrine/DBAL/`) substitue une plateforme MariaDB corrigée : ne pas le
  retirer tant que le serveur reste en 10.4.

### Amorçage — premier démarrage sur une base vierge

L'œuf et la poule : `/admin/utilisateurs` exige d'être déjà gérant, et personne ne
peut se connecter sans compte. `App\Controller\InstallationController` est la seule
porte d'entrée.

- `App\EventSubscriber\InstallationSubscriber` redirige **tout** vers
  `/installation` tant que `UtilisateurRepository::aucunCompte()` est vrai. Sans
  lui, une base vierge n'offrirait qu'un écran de connexion sur lequel aucun
  identifiant ne marche : porte close, sans indication de la marche à suivre.
- **Priorité 20** : après le routeur (32), qui renseigne `_route`, mais **avant le
  pare-feu** (8). Sinon `/admin` partirait d'abord vers `/login`, et l'exploitant
  ferait un détour par un écran de connexion inutilisable.
- **La route se referme (404) dès qu'un compte existe** — `aucunCompte()`, pas
  « aucune dirigeante » : c'est l'existence d'un compte qui compte, sinon un
  caissier créé en console rouvrirait la porte. Elle est `PUBLIC_ACCESS` par
  nécessité ; laissée ouverte, n'importe qui s'ouvrirait un accès dirigeante.
  404 et non 403 : inutile d'annoncer qu'il existe ici un écran de création.
- Le compte créé est **toujours `ROLE_DIRIGEANTE`**, sans choix possible :
  installer une caisse sans personne au-dessus d'elle rendrait la dirigeante
  incréable ensuite.
- Passe par `CreationUtilisateur` comme partout ailleurs — unicité, hachage et
  trace d'audit ne se réimplémentent pas pour le premier compte. L'auteur de
  l'entrée d'audit est **nul** (personne n'était connecté), et c'est exact.
- **Mot de passe saisi deux fois** (`RepeatedType`). C'est le seul du système :
  une faute de frappe et l'installation est perdue, sans second compte pour la
  rattraper. Ailleurs la confirmation ne se justifie pas, la dirigeante pouvant
  réinitialiser n'importe quel mot de passe.
- Pas de connexion automatique après création : mieux vaut vérifier le mot de
  passe tout de suite, tant qu'on l'a en tête.
- `exclusion` : le sous-abonné laisse passer `/_*` (profileur, barre de débogage)
  et `/assets` — les intercepter casserait l'écran d'installation lui-même.

`InstallationTest` fige les deux moitiés : tout mène à l'installation tant que la
base est vide, et plus rien n'y mène ensuite.

### Sécurité et rôles

- **Rôles** : `ROLE_DIRIGEANTE` > `ROLE_GERANT` > `ROLE_CAISSIER` (hiérarchie) ;
  `ROLE_COMPTABLE` est autonome. Voir l'enum `App\Enum\RoleUtilisateur`.
- **Deux connexions** sur le même pare-feu `main` :
  - classique e-mail / mot de passe (`/login`) pour dirigeante, gérant, comptable ;
  - code PIN 4 chiffres sur pavé numérique (`/caisse/login`) pour les caissiers,
    via `App\Security\CaisseAuthenticator`.
- `Utilisateur` porte **deux identifiants distincts** : `motDePasse` (connexion
  classique, = `getPassword()`) et `codePin` (caisse) — les deux hachés.
- Redirection post-connexion par rôle (`App\Security\RoleRedirectionHandler`) :
  caissier → `/caisse`, gérant → `/admin`, dirigeante → `/pilotage`,
  comptable → `/comptabilite`.
- Chaque connexion / déconnexion / échec est tracé dans `JournalAudit` via
  `App\Service\AuditLogger` (`App\EventSubscriber\AuditConnexionSubscriber`) —
  voir « Journal d'audit inaltérable » plus bas.
- **Nombre d'essais limité — deux mécanismes, parce qu'un seul ne couvrait pas
  les deux portes.** Tracer un échec ne l'empêche pas : le journal d'audit disait
  ce qui s'était passé, rien ne l'arrêtait.

  | Porte | Mécanisme | Quota | Compté par |
  |---|---|---|---|
  | `/login` | `login_throttling` du pare-feu | 5 / 15 min | identifiant + IP |
  | `/caisse/login` | limiteur `connexion_caisse` dans `CaisseAuthenticator` | 10 échecs / 5 min | IP |

  Le pavé numérique **échappe au `login_throttling`**, et pas qu'à moitié :

  - le contrôle de Symfony s'accroche à `CheckPassportEvent`, or sur un PIN
    inconnu `CaisseAuthenticator::authenticate()` lève **avant** de construire le
    passeport. L'événement ne part jamais : **pas un seul échec ne serait compté**
    sur la porte la plus exposée de l'application ;
  - il compte par identifiant saisi, et un PIN n'en porte aucun — quatre chiffres,
    et rien d'autre. Il ne reste que l'IP.

  D'où un limiteur explicite, et trois partis pris :

  - **seuls les échecs consomment un jeton**, et le quota est consulté *avant* de
    comparer quoi que ce soit. Une caissière qui tape juste n'est jamais gênée, et
    un attaquant est écarté sans que le serveur ait haché son essai contre chaque
    caissier — sinon la limite elle-même ouvrait une voie d'épuisement du
    processeur, une tentative coûtant autant de vérifications qu'il y a de comptes.
  - **5 minutes et non un quart d'heure** : toute la boutique sort souvent par une
    seule IP publique, un blocage immobilise donc le comptoir. On ne suspend pas la
    file du matin parce que quelqu'un a oublié son code. Le calcul reste dissuasif :
    10 000 combinaisons à 120 essais/heure, ce sont plus de 80 heures.
  - **lire `getRemainingTokens()`, jamais `isAccepted()` sur un `consume(0)`** :
    consommer zéro jeton est toujours accepté, par construction. S'y fier laissait
    passer toutes les tentatives — la limite était en place et ne servait à rien.
    `SecurityTest::testLeCodePinNeSeBalayePasCombinaisonParCombinaison` l'a
    attrapé, en exigeant que le bon code lui-même soit écarté passé le quota.

  > En test, les compteurs vivent dans un cache sur disque qui **survit au vidage
  > des tables** : `SecurityTest::setUp()` purge `test.cache.rate_limiter`, sans
  > quoi un test qui échoue à se connecter lègue sa dette au suivant et l'ordre
  > d'exécution décide du résultat.
- **Créer un compte** — deux chemins, un seul service :
  - back-office `/admin/utilisateurs/nouveau` (bouton « + Nouvel utilisateur »),
    ouvert au **gérant et à la dirigeante** (`Permission::UTILISATEUR_GERER`,
    `UtilisateurVoter`) — gérer une équipe qui tourne en fait partie.
    **Ce qui les sépare n'est pas la porte, c'est le rôle attribuable** :
    `RoleUtilisateur::attribuablesPar()` ne propose au gérant que `GERANT` et
    `CAISSIER`. `DIRIGEANTE` lui est refusé — il s'octroierait sinon les prix de
    vente, le pilotage et l'audit en s'ouvrant un second compte — et `COMPTABLE`
    aussi : ouvrir l'accès au cabinet extérieur relève du contrat, pas du magasin.
    Le rôle interdit n'est **pas affiché** dans la liste déroulante, donc rejeté à
    la soumission même en forgeant la requête — même technique que le prix de vente.
  - console : `php bin/console app:creer-utilisateur <email> <nom> --role=GERANT
    --mot-de-passe=…` (ou `--role=CAISSIER --code-pin=1234`).
- **Modifier un compte** — `/admin/utilisateurs/{id}/modifier` (nom, e-mail, rôle,
  réinitialisation du secret), même permission portant sur **le compte visé** : un
  gérant ne modifie pas une dirigeante, il n'aurait qu'à changer son e-mail pour
  s'emparer de son accès. Quatre règles, toutes dans `CreationUtilisateur::modifier()` :
  - **Secret laissé vide = inchangé.** Corriger une faute de frappe dans un nom ne
    doit pas réinitialiser un identifiant — personne ne reteste sa connexion après.
  - **Changer de rôle exige le secret du nouveau rôle** si le compte ne l'a pas
    déjà : promouvoir un caissier en gérant sans mot de passe l'enfermerait dehors.
    Le refus tombe **avant** toute modification.
  - **Le secret devenu sans objet est effacé.** `CaisseAuthenticator` accepte tout
    compte actif porteur d'un code PIN : un caissier promu gérant qui garderait le
    sien continuerait d'ouvrir la caisse au pavé numérique, sans que rien ne le
    signale.
  - **Le plafond de rôle vaut aussi en promotion** — sinon il suffirait de
    promouvoir une dirigeante au lieu d'en créer une. Et **nul ne change son propre
    rôle** : seul le rôle en place lui est proposé, un gérant qui se rétrograderait
    perdrait `/admin` séance tenante. Même esprit que l'interdiction de se
    désactiver soi-même.
  - Le rôle **en place** figure toujours dans la liste, même hors de portée de
    l'auteur : sans lui, le formulaire s'ouvrirait sur un choix vide et le simple
    fait d'enregistrer rétrograderait le compte.
  - Les deux passent par `App\Service\CreationUtilisateur`, qui porte l'unicité de
    l'e-mail, l'**unicité du code PIN** (comparée hachage par hachage : deux
    caissiers au même PIN seraient indistinguables à la connexion), le hachage et
    la trace d'audit. **Ne pas réimplémenter la création ailleurs.**
  - Le secret attendu suit le rôle : `CreerUtilisateurType` affiche les deux champs
    et n'exige que celui du rôle choisi (`POST_SUBMIT`) ; le contrôleur Stimulus
    `secret_role_controller.js` masque l'autre — confort d'affichage seulement, la
    règle est tranchée côté serveur.

### Habilitations fines (Security Voters)

Les rôles disent **qui est** l'utilisateur ; les permissions de
`App\Security\Permission` disent **ce qu'il peut faire**. Hors sécurité pure,
contrôleurs et gabarits testent une **permission**, jamais un rôle.

|                              | Caissier | Gérant | Dirigeante | Comptable |
|------------------------------|----------|--------|------------|-----------|
| Voir coût de revient / marge | non      | oui    | oui        | oui (L)   |
| Voir le CA global            | non      | oui    | oui        | oui (L)   |
| Voir toutes les ventes       | non      | oui    | oui        | oui (L)   |
| Voir **ses** ventes          | oui      | oui    | oui        | oui (L)   |
| Modifier un prix de vente    | non      | **non**| **oui**    | non       |
| Modifier un article          | non      | oui    | oui        | non       |
| Annuler une vente encaissée  | son dernier ticket | **oui** | oui | non   |
| Exporter la comptabilité     | non      | oui    | oui        | oui (L)   |
| Gérer les comptes            | non      | oui    | oui        | non       |
| Agir sur un compte dirigeante| non      | **non**| oui        | non       |
| Attribuer le rôle dirigeante | non      | **non**| oui        | non       |

(L) = lecture seule. Le comptable ne reçoit **aucune** permission d'écriture.

- **`ArticleVoter`** — `ARTICLE_VOIR_COUT` (jamais un caissier),
  `ARTICLE_MODIFIER_PRIX` (**dirigeante seule**), `ARTICLE_MODIFIER`. Le sujet peut
  être `null` pour la question générale (masquer une colonne entière).
- **`VenteVoter`** — `VENTE_VOIR` : un caissier n'accède qu'aux ventes de **ses**
  sessions de caisse, y compris via `/caisse/ticket/{uuid}` et sa sortie ESC/POS.
  `VENTE_ANNULER` : gérant et au-dessus sans restriction ; **le caissier sur le
  seul ticket qu'il vient d'encaisser** — voir « Annulation du dernier ticket ».
- **`UtilisateurVoter`** — `UTILISATEUR_GERER`. Sujet `null` : « puis-je gérer des
  comptes ? » (l'écran, le bouton de création). Sujet `Utilisateur` : « puis-je
  agir sur **ce** compte ? » — un gérant ne bascule pas une dirigeante, il
  couperait l'établissement de son seul accès au pilotage et à l'audit. Le rôle
  attribuable est plafonné à part, par `RoleUtilisateur::attribuablesPar()`.
- **`DonneesGlobalesVoter`** — `VOIR_CA_GLOBAL`, `VOIR_TOUTES_VENTES` et
  `EXPORTER_COMPTABILITE` (sujet `null`). Les trois partagent la **même audience** :
  gérant, dirigeante et comptable ; le caissier est exclu. Le voter n'a donc plus
  qu'une seule règle — les trois constantes subsistent parce que les points d'appel
  expriment des intentions différentes, pas parce que l'arbitrage diffère.
  > `EXPORTER_COMPTABILITE` a longtemps fait exception, le gérant en étant écarté
  > (un export sort de l'application l'intégralité du CA, des charges et des écarts
  > de caisse). **Cette restriction a été levée** : ne pas la réintroduire sans
  > décision explicite.

**Prix de vente** : le champ `prixVenteTtc` n'est **pas ajouté** au formulaire sans
l'habilitation (`ArticleType`, option `modifier_prix`). Un champ absent ne peut pas
être soumis, même en forgeant la requête — plus sûr que le désactiver. Un article
**créé sans prix est forcé inactif**, sinon un gérant contournerait la règle en
recréant l'article.

**Annulation** : `EncaissementService::annuler()` trace au journal d'audit **et**
notifie la dirigeante (`NotificateurDirigeante` → entité `Notification`, relevée
sur `/pilotage` avec un bouton « J'ai vu »). Notification en base plutôt que par
e-mail : la dirigeante consulte le pilotage depuis son téléphone, c'est le canal
qu'elle regarde réellement.

**Annulation du dernier ticket — la seule écriture accordée au caissier.**
Le caissier annule le ticket qu'il vient d'encaisser, depuis le panneau « Reçu »
de `/caisse`. L'erreur de saisie se constate au comptoir dans les secondes qui
suivent, et faire venir le gérant pour deux baguettes de trop immobilise la file
du matin — c'est-à-dire exactement ce que cette caisse existe pour éviter.

Ce n'est pas une brèche dans la matrice, c'est une exception **bornée par trois
conditions cumulatives**, toutes dans `VenteVoter::peutAnnuler()` :

- **sa propre session** — connaître l'uuid du ticket d'un collègue ne suffit pas,
  sinon un caissier effacerait les ventes d'un autre et lui laisserait l'écart ;
- **session encore ouverte** — après le Z la journée est arrêtée. Le refus vient
  de l'habilitation (403), pas de l'exception métier levée plus loin par
  `SessionCaisse::garantirOuverte()` : une porte fermée vaut mieux qu'une porte
  qui casse au passage ;
- **le dernier ticket, et lui seul** (`VenteRepository::derniereDe()`) — dès
  qu'une vente suivante est encaissée, l'annulation redevient l'affaire du
  gérant. Sans cette borne, un caissier pourrait remonter sa journée et effacer
  ses écarts au fil de l'eau, et le Z ne signalerait plus rien.

> `derniereDe()` trie sur l'**identifiant**, pas sur `createdAt` : en boulangerie
> rapide deux ventes tombent couramment dans la même seconde, et « la dernière »
> ne doit pas dépendre de laquelle l'horodatage a départagée.

Rien n'est allégé pour autant : c'est le même `EncaissementService::annuler()`,
donc le même journal d'audit, la même notification à la dirigeante et les mêmes
mouvements de stock inverses. L'exception est **ouverte, pas silencieuse** — c'est
ce qui permet de l'accorder.

**Côté écran** (`caisse/index.html.twig`, `ticket_controller.js`) :

- Le bouton « Annuler ce ticket » est **sur sa propre ligne, sous** « Imprimer »
  et « Nouveau ticket ». Ces deux-là gardent leur place au pixel près : c'est là
  que le pouce va sans regarder, vingt fois par heure — une action irréversible
  ne doit pas se trouver sous un doigt qui enchaîne.
- **Jamais un seul appui.** Le motif est obligatoire côté serveur, il l'est donc
  aussi à l'écran : le bouton de confirmation naît désactivé, et choisir un motif
  tient lieu de confirmation. Quatre motifs proposés d'un appui plus un champ
  libre — au comptoir, taper au clavier tactile coûte plus cher que le geste
  qu'on est en train de corriger, et des motifs normalisés se relisent au
  pilotage là où un champ libre donne autant de formulations que de caissières.
- **L'annulation ne passe pas par la file de synchronisation.** Hors ligne elle
  est refusée franchement. Mise en file, elle serait rejouée au retour du réseau
  sur un ticket que d'autres ventes auront dépassé : le serveur la refuserait et
  la caissière croirait son ticket annulé depuis une heure. C'est la seule sortie
  réseau de l'écran qui exige le réseau — `TurboNavigationTest` fige la liste des
  méthodes asynchrones du contrôleur, l'y ajouter était une décision.
- Le motif retenu prend un **aplat rouge** (`red-700`, blanc à 6,4:1) et non
  ambre : l'ambre marque les états courants de l'écran (famille, mode), ce geste
  n'en est pas un. Comme pour les règlements, la bordure change de couleur mais
  **jamais d'épaisseur**.

Couverture : `VenteApiTest` (les trois bornes), `HabilitationsTest` (le ticket
d'un collègue reste refusé), `CaisseTest::testLeRecuOffreLAnnulationDuTicketDerriereUnMotif`.

**Étanchéité des réponses de caisse** : un caissier a légitimement accès à
`/caisse/catalogue.json`, `/api/vente` et `/caisse/ticket/{uuid}` — leur sûreté
tient donc à ce qu'elles **ne contiennent pas**. `FuiteDonneesCaisseTest` vérifie
l'absence de tout terme de gestion (coût, marge, CA, écart, stock, fournisseur…)
et fige la **liste blanche exacte des clés** de chaque réponse JSON : ajouter un
champ au catalogue ou à la réponse d'encaissement fait échouer le test.

---

## Commandes utiles

```bash
# Lancer les tests (commande de test de référence)
php bin/phpunit

# Tests unitaires JavaScript (file de synchronisation hors ligne)
node --test "tests/js/*.test.js"

# Serveur de développement (ou `symfony serve` si la CLI Symfony est installée)
# Le dernier argument est le **script de routage** : sans lui, le serveur intégré
# de PHP renvoie 404 sur tout fichier absent du disque — c'est-à-dire sur tout ce
# qu'AssetMapper sert à la volée. La page s'affiche alors sans aucun style, et
# rien n'indique pourquoi. Le piège reste invisible tant qu'un `public/assets/`
# compilé traîne (voir le piège inverse plus bas).
php -S localhost:8000 -t public/ public/index.php

# Compiler les assets Tailwind
php bin/console tailwind:build          # une fois
php bin/console tailwind:build --watch  # en continu pendant le développement

# Dépendances JavaScript (AssetMapper, pas de bundler)
php bin/console importmap:require <paquet>  # ajoute une dépendance (ex. chart.js)
php bin/console asset-map:compile           # compile les assets pour la production

# Base de données
php bin/console doctrine:database:create
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

**Commande de test : `php bin/phpunit`**

### Performance du poste de développement

Symptôme déjà rencontré : **chaque lien et chaque bouton mettait 5 à 20 secondes**.
Le code n'y était pour rien — `php bin/console about`, qui ne fait qu'amorcer le
noyau, prenait déjà 20 s. **Vérifier l'environnement avant de suspecter le code.**

- **OPcache doit être activé** (`zend_extension=opcache` dans `C:\xampp\php\php.ini`).
  Sans lui, PHP recompile les ~8 700 fichiers de `vendor/` à chaque requête.
  Réglages importants : `opcache.enable_cli=1` (le serveur de développement et
  `bin/console` utilisent le SAPI **CLI**), `opcache.max_accelerated_files=30000`
  (10 000 par défaut ne suffit pas), `opcache.revalidate_freq=2` et
  `opcache.file_cache` — ce dernier est le seul à survivre entre deux processus,
  donc le seul à accélérer les commandes console.
- **Un `stat` coûte ~2 ms sur ce poste** : deux antivirus temps réel tournent en
  parallèle (Windows Defender **et** Reason Cybersecurity). Exclure
  `C:\xampp\htdocs\zedpos` et `C:\xampp\php` du scan, et n'en garder qu'un.
- Mesurer le noyau seul (`php bin/console about`) sépare d'emblée un problème
  d'environnement d'un problème applicatif.

**Requêtes** : les listes chargent leurs relations en **une seule requête**
(`VenteRepository::recentes()`, `SessionCaisseRepository::recentes()`,
`FicheTechniqueRepository::avecMatieres()`, `ArticleRepository::rechercher()`).
Afficher `vente.sessionCaisse.utilisateur.nom` sur 100 lignes sans jointure
anticipée produisait plus de 100 requêtes. Compter se fait en base
(`compterActifs()`, `fetch: 'EXTRA_LAZY'` sur `FamilleProduit::$articles`), jamais
en hydratant des entités pour les dénombrer ensuite en PHP.

### Identité visuelle

Habillage « boulangerie » : chaleureux, lisible en plein jour, sans dépendance
réseau. **Deux palettes Tailwind natives, rien de personnalisé à maintenir :**

- **`amber`** — l'accent. `amber-700` (#b45309) pour les actions et les états
  actifs, `amber-500` pour le logo et les liserés, `amber-50` (#fffbeb) en fond
  de page. C'est la couleur de la croûte.
- **`stone`** — le neutre. **Chaud**, contrairement au `slate` bleuté par défaut
  de Tailwind : c'est ce qui empêche l'interface de « refroidir ».
  **Ne jamais réintroduire de `slate-*`** — le remplacer par `stone-*`.

Jetons dans `assets/styles/app.css` (`@theme`) : `--font-titre` (serif système),
`--color-creme`, `--color-croute`. La classe `.titre` applique le serif — réservée
aux titres d'écran et aux montants mis en avant, **jamais aux tableaux de chiffres**,
plus lisibles en sans-serif.

> **Polices exclusivement système.** Aucune police distante : l'écran de caisse
> doit rester lisible hors ligne, et une requête Google Fonts échouerait de toute
> façon (hors périmètre du Service Worker).

Déclinaisons par espace :

| Espace | Traitement |
|---|---|
| `/admin` | Barre latérale brun profond, liseré ambre sur l'entrée active, fond crème, en-tête translucide |
| `/pilotage` | En-tête en dégradé chaud, onglets en pastilles ambre, cartes à liseré dégradé |
| `/caisse` | Ambre sur les états actifs (famille, mode) — en plein jour la sélection doit sauter aux yeux. Les **règlements portent la couleur de leur opérateur** ; le bouton **Encaisser** reste vert : convention forte en caisse |

**Détail de l'écran de caisse** — il avait dérivé vers le monochrome (états actifs
en gris `#f5f4f2`, fond quasi blanc) ; `CaisseTest::testLesEtatsActifsSontEnAmbre`
interdit maintenant ce retour en arrière.

- **Famille active** : pastille `amber-700` pleine, texte blanc. Un gris clair ne se
  distingue pas derrière un comptoir en plein jour.
- **Moyens de paiement — aux couleurs des réseaux.** Seul endroit de l'application
  où la couleur ne nous appartient pas : la caissière reconnaît le bleu Wave ou le
  jaune MTN avant d'avoir lu le libellé, ce qui supprime une hésitation par vente.

  | Mode | Teinte | Encre | Contraste |
  |---|---|---|---|
  | Espèces (pas d'opérateur) | `#44403c` | blanc | 10,3:1 |
  | Wave | `#1dc8ff` | `#073b4c` | 6,2:1 |
  | Orange Money | `#ff7900` | `#3d1c00` | 5,9:1 |
  | MTN MoMo | `#ffcc00` | `#3d3000` | 8,6:1 |
  | Moov Money | `#0a4ea3` | blanc | 7,9:1 |

  **Le blanc est exclu sur l'orange (2,6:1) et sur le jaune MTN** — d'où l'encre
  foncée déclinée de chaque teinte, jamais du noir pur.
  ⚠ Le bleu de Wave et de Moov est une **exception assumée** au « ni bleu ni
  lavande » des touches produits : ce sont des logos, pas un choix esthétique. Ne
  pas les « réchauffer ».
  Au repos les cinq boutons restent blancs, la marque tenant dans une **pastille** :
  cinq aplats saturés côte à côte se disputeraient l'écran et plus rien ne
  ressortirait. **Le règlement retenu prend l'aplat plein** de sa couleur. La
  bordure change de teinte mais **jamais d'épaisseur** — une bordure qui grossit
  décale le texte au clic. Source unique : la variable `reglements` de
  `caisse/index.html.twig`, d'où le CSS est engendré ; ces boutons ne sont pas
  reconstruits depuis IndexedDB, contrairement aux touches produits.
  `CaisseTest::testLesReglementsPortentLesCouleursDesReseaux` fige les cinq teintes.
- **Touches produits** : palette **fermée de 8 teintes chaudes** (pain doré,
  terracotta, framboise, olive, brique, blé, prune, sauge), texte foncé de la même
  teinte — jamais de noir pur sur fond coloré. **Ni bleu ni lavande** : ils
  refroidissaient l'écran et juraient avec l'ambre. Toutes à ≥ 5,35:1 (AA).
  ⚠ Cette palette est **écrite deux fois** — dans `caisse/index.html.twig`
  (variable `teintes`, premier affichage) et dans `ticket_controller.teinte()`
  (rendu depuis IndexedDB). Sans quoi les produits changeraient de couleur au
  rechargement, ou hors ligne. `testLesTeintesProduitsSontIdentiquesCoteTwigEtCoteJs`
  échoue si les deux divergent.
- **Photo de touche** (facultative, `Article::$image`). Quand elle existe, elle
  occupe une bande de 80 px **au-dessus** du libellé ; sans elle, la touche est
  inchangée. Le **libellé reste sur l'aplat de couleur, jamais sur la photo** :
  posé dessus, son contraste dépendrait de l'image téléversée — donc de personne —
  et l'écran doit rester lisible en plein jour. C'est ce qui permet de garder la
  palette et ses rapports de contraste vérifiés.
  ⚠ Ce balisage est lui aussi **écrit deux fois**, `caisse/index.html.twig` et
  `ticket_controller.vignette()`, pour la même raison que la palette.
  `testLaPhotoEstRendueALIdentiqueCoteTwigEtCoteJs` fige les deux.
  Un fichier disparu **retire l'image** (`onerror="this.remove()"`) et la touche
  retombe sur son aplat : une icône d'image cassée en pleine grille de caisse ne
  rend service à personne.
- **Encaisser** : `green-800` (#166534), blanc à 7,0:1. Vert et jamais ambre —
  c'est le seul bouton qui engage l'argent, il ne doit se confondre avec aucun état
  actif. Il était en `emerald-700` (5,48:1) ; depuis que les règlements portent les
  couleurs des réseaux, l'emerald se noyait dans la rangée colorée juste au-dessus,
  et un vert plus dense reprend le dessus. **Ne pas remonter vers `emerald-600`** :
  le blanc n'y atteint que 3,77:1, illisible en plein jour.
- **Total** : `.titre` (serif) en `amber-800`, le plus gros chiffre de l'écran —
  c'est le montant que la caissière annonce à voix haute.

**Exception : les tickets imprimés.** `templates/ticket/ticket.html.twig` et
`templates/caisse/rapport.html.twig` restent en noir et blanc neutre — ils sortent
sur une imprimante thermique 58 mm, la couleur n'y a aucun sens.

### Navigation Turbo (pas de rechargement de page)

Toute la navigation passe par **Hotwire Turbo Drive** : les clics sur les liens et
les soumissions de formulaire sont interceptés, seul le `<body>` est remplacé. Il
n'y a plus de rechargement complet ni de spinner navigateur.

- Turbo est démarré par `assets/app.js` (`import '@hotwired/turbo'`) **et** par le
  contrôleur `symfony--ux-turbo--turbo-core` chargé en « eager ». Avec AssetMapper
  le paquet s'appelle `@hotwired/turbo` — `@hotwired/turbo-bundle` appartient au
  monde Webpack Encore et n'existe pas ici.
- **Turbo 8** dans `templates/base.html.twig` : `turbo-refresh-method=morph`
  (un rafraîchissement ne remplace que les nœuds modifiés, le focus est préservé),
  `turbo-refresh-scroll=preserve`, `turbo-prefetch=true` (préchargement au survol),
  et `data-turbo-prefetch="true"` explicite sur les barres de navigation.
- La **barre de progression** est stylée dans `assets/styles/app.css`
  (`.turbo-progress-bar`) : c'est le seul retour visuel pendant une navigation.
- L'écran de caisse porte `turbo-cache-control=no-cache` : son DOM est vivant
  (ticket en cours), un aperçu mis en cache afficherait un ticket périmé.

**Règle impérative — formulaires et Turbo.** Un formulaire soumis et **invalide**
doit répondre **422**, jamais 200 : sinon Turbo refuse de remplacer la page et
l'utilisateur reste devant un formulaire figé, sans message d'erreur. Tous les
contrôleurs à formulaire utilisent `App\Controller\Trait\ReponseFormulaire` et sa
méthode `rendreFormulaire()`, qui s'en charge. **Ne jamais revenir à un
`$this->render()` direct après une soumission.**

**Turbo Frames.** Trois listes se rafraîchissent sans toucher au reste de la page :
`liste-articles` (`/admin/articles`, avec ses filtres), `liste-ventes`
(`/admin/ventes`), `tableau-stock` (`/admin/stock`), plus `liste-tickets`
(`/pilotage/ventes`, filtre par date + pagination).

> Piège classique : un lien **à l'intérieur** d'un frame navigue **dans** le frame.
> Les liens de détail et les formulaires d'action portent donc
> `data-turbo-frame="_top"` — sans quoi une fiche article s'afficherait à
> l'intérieur du tableau, et les messages flash (rendus hors du frame) seraient
> perdus. `TurboNavigationTest` vérifie que c'est le cas sur chaque lien.

**Piège JavaScript.** `form.submit()` natif **n'émet pas** d'événement `submit` :
Turbo ne peut pas l'intercepter et le navigateur recharge toute la page. Utiliser
`form.requestSubmit()` (cf. `numpad_controller.js`).

**Chargement.** `graphique_ca_controller.js` est annoté `/* stimulusFetch: 'lazy' */` :
Chart.js ne se télécharge que sur le tableau de bord de la dirigeante, pas sur
l'écran de caisse. `public/.htaccess` met `/assets/` en cache un an (`immutable` —
les noms sont condensés) et interdit la mise en cache de `sw.js`.

> **Piège : un `public/assets/` compilé gèle le mode développement.**
> Dès que `php bin/console asset-map:compile` a été lancé une fois, le répertoire
> `public/assets/` et son `manifest.json` existent — et AssetMapper **sert ce build
> figé même en dev**. `tailwind:build`, une nouvelle classe utilitaire dans un
> gabarit, un contrôleur Stimulus modifié : plus rien n'atteint le navigateur.
> Symfony le dit lui-même à la fin de `asset-map:compile` (« Symfony will not serve
> any changed assets until you delete the files in the public/assets directory »),
> mais l'avertissement défile et se rate facilement.
>
> Le symptôme est déroutant car il est **muet** : aucune erreur, aucun 404. Une
> classe absente du CSS figé ne s'applique simplement pas. Cas réellement
> rencontré : le bouton **Encaisser** passé en `bg-green-800`, rendu sans fond,
> donc blanc sur blanc — bouton invisible.
>
> - **En développement : supprimer `public/assets/`.** C'est un artefact de build,
>   gitignoré et régénérable ; AssetMapper reprend alors le service à la volée.
> - **Sinon, recompiler après chaque modification de gabarit** :
>   `php bin/console tailwind:build && php bin/console asset-map:compile`.
>
> Vérifier ce qui est réellement servi plutôt que ce qui est construit :
> `public/assets/manifest.json` donne le nom condensé du fichier, et c'est **lui**
> qu'il faut inspecter — pas `var/tailwind/app.built.css`.

### Back-office gérant (`/admin`)

- Réservé à `ROLE_GERANT` (donc aussi la dirigeante par hiérarchie). Layout Twig +
  Tailwind avec navigation latérale, contrôleurs sous `src/Controller/Admin/`.
- CRUD : Familles, Articles (filtre famille + recherche + activation), Matières
  premières, Fournisseurs. Onglet « Fiche technique » sur l'article (ajout/retrait
  de matières premières) via **Turbo Frame** — aucune dépendance JS lourde.
- **Accès à la fiche technique.** Une fiche ne se « crée » pas : elle naît quand on
  ajoute sa première matière à un article (`obtenirFiche()`). Il n'y a donc aucun
  bouton « nouvelle fiche », et l'écran Production ne fait que consulter.
  L'accès se fait par le bouton **« Fiche technique »** de chaque ligne
  d'`/admin/articles`.
  > Il a manqué longtemps : la ligne n'offrait que « Désactiver » et « Modifier »,
  > et le seul chemin était le **nom** de l'article — un lien qui ne se souligne
  > qu'au survol, sur un écran où rien n'annonçait qu'on pouvait entrer.
  > Les onglets étant en CSS pur (radios masqués + `peer-checked`), ils n'ont pas
  > d'URL propre : `?onglet=fiche` sélectionne lequel s'ouvre, sans quoi tout lien
  > venant d'ailleurs retomberait sur « Détails ». `AdminSmokeTest` fige le bouton,
  > le paramètre et son défaut.
- Formulaires stylés par un thème Tailwind : `templates/admin/_form_theme.html.twig`
  (il fait `{% use 'form_div_layout.html.twig' %}` pour pouvoir appeler `parent()`).
- Montants saisis en FCFA / unités entières et convertis en centimes / millièmes
  par des transformateurs de formulaire (jamais de float persisté).
- **Coût / marge** : `App\Service\CalculateurCoutMatiere` calcule le coût de revient
  (Σ quantité × coût moyen matière, ajusté du % de perte : coût / (1 − perte)), la
  marge brute et le taux de marge. Colonne « Coût / Marge » sur la liste des articles,
  badge rouge si la marge passe sous le seuil (`app.seuil_marge_bp`, défaut 6000 = 60 %).
  Ces informations sont **strictement réservées à `ROLE_GERANT`** (jamais un caissier) :
  page sous `access_control ^/admin` + garde `is_granted('ROLE_GERANT')` dans le template.

### Import du catalogue (`/admin/articles/importer`)

Bouton **« Importer »** sur `/admin/articles`. Un CSV à deux colonnes — **nom, prix
de vente en FCFA** — pour garnir un catalogue au démarrage ou ajouter une gamme
entière. Service `App\Service\ImportArticles`, compte rendu
`RapportImportArticles`.

**Deux invariants, et ils commandent tout le reste.**

- **L'import ne contourne pas la règle du prix.** Le prix n'est repris que si
  l'auteur détient `ARTICLE_MODIFIER_PRIX` (dirigeante). Sinon les articles naissent
  **à 0 FCFA et inactifs**, exactement comme un article créé sans prix dans le
  formulaire à l'unité. Sans cette symétrie, l'import serait la porte de service :
  il suffirait de déposer un fichier pour fixer des prix qu'on n'a pas le droit de
  saisir à l'écran. Le gérant en est **averti avant de déposer son fichier** — le
  découvrir après coup, soixante articles créés à zéro, serait une mauvaise surprise.
- **L'import n'écrase jamais rien.** Un nom déjà au catalogue est *ignoré*, pas mis
  à jour : réécrire les prix en place serait l'autre façon de contourner la règle, et
  un fichier mal daté changerait des prix que personne n'a voulu toucher. Les
  doublons sont comparés **sans égard à la casse ni aux espaces**, et les articles
  **inactifs comptent** — un article importé puis désactivé ne doit pas revenir en
  double au fichier suivant.

**Ce qui sort réellement d'un tableur** est pris en charge, parce que chacun de ces
détails coûte sinon un import à refaire :

| Cas | Traitement |
|---|---|
| Séparateur `;`, tabulation ou `,` | déduit du contenu, pas demandé à l'exploitant |
| BOM UTF-8 (Excel Windows) | retiré — sinon le premier nom porte trois octets invisibles et ne sera **plus jamais** reconnu comme un doublon |
| Fichier en Windows-1252 | converti (`ArticleController::enUtf8()`) — sinon « Pâté » arrive en « PÃ¢tÃ© », à retaper à la main |
| `1 500`, `1.500`, `1,500`, `1500 FCFA` | 1 500 FCFA — **trois** chiffres après le séparateur sont des milliers |
| `1500,00` | 1 500 FCFA (décimale nulle tolérée) |
| `1500,50` | **refusé** : le franc CFA ne circule pas en centimes, et arrondir en silence est interdit ici |
| Prix **absent** | omission → article sans prix, donc inactif, et signalé |
| Prix **illisible** | erreur → ligne rejetée avec sa raison |
| Ligne d'en-têtes | reconnue et sautée en silence |

- **Le compte rendu est ligne par ligne** : numéro, contenu brut, raison. Sur
  soixante lignes tapées dans un tableur il y en a toujours une fautive, et un
  « 59 articles créés » laisse chercher laquelle.
- **Le compte rendu voyage par la session**, jusqu'à une redirection 303. Turbo
  Drive n'affiche pas le corps d'une soumission qui répond 200 : rendu directement,
  le compte rendu ne s'afficherait **jamais** — l'écran resterait figé sur le
  formulaire alors que les articles auraient bel et bien été créés. Il est **retiré à
  la lecture** : un rafraîchissement ne doit pas le réafficher, on croirait avoir
  rejoué l'import.
- **Une seule écriture en base, à la fin.** Un fichier à moitié importé serait le
  pire des états : personne ne saurait où reprendre.
- Plafond `ImportArticles::MAX_LIGNES` (500). Au-delà, ce n'est pas un catalogue
  qu'on importe, c'est un fichier qui n'est pas celui qu'on croit.
- Les articles naissent en **« pièce »**, sans famille ni TVA — à compléter sur la
  fiche. Un modèle CSV est téléchargeable (`/admin/articles/importer/modele`, BOM
  compris, lien `data-turbo="false"`).

> **Piège corrigé, à ne pas réintroduire.** La ligne d'en-têtes était d'abord
> reconnue au seul fait que « la deuxième colonne n'est pas un prix ». Sur
> `Baguette;gratuit` suivi de lignes correctes, la baguette passait donc pour un
> en-tête et **disparaissait sans un mot** : article absent du catalogue, compte
> rendu muet, rien pour le remarquer. La reconnaissance exige désormais **aussi** un
> libellé connu en première colonne (`LIBELLES_EN_TETE`). Le prix de ce choix est
> qu'un en-tête exotique ressort en ligne rejetée — visible et corrigible, alors
> qu'une ligne avalée ne l'est pas.
> `testUnePremiereLigneAuPrixFautifEstRejeteeEtNonAvalee` fige le cas.

`ImportArticlesTest` couvre les deux invariants, les formats de prix, les
séparateurs, l'encodage, les garde-fous et l'écran.

### Pagination — un seul mécanisme pour tout le projet

**Toute liste non bornée est paginée.** `App\Repository\Pagination` porte le
calcul, `templates/_pagination.html.twig` le rendu. Il y avait déjà deux
implémentations voisines mais non identiques (ventes, audit) et deux contrôles
recopiés : ajouter huit listes en aurait fait dix.

```php
// Dans le repository — c'est la base qui découpe, jamais PHP.
return Pagination::depuis($qb, $page);                              // relations ToOne
return Pagination::depuis($qb, $page, fetchJoinCollection: true);   // une collection est jointe
```

```twig
{% import '_pagination.html.twig' as pagination %}
{% for article in articles.items %}…{% endfor %}
{{ pagination.barre(articles, 'admin_article_index') }}
```

- **`fetchJoinCollection: true` dès qu'une collection est jointe** (`ft.lignes`,
  `a.ficheTechnique.lignes`). Sans lui, Doctrine compte les lignes du produit
  cartésien : un article à cinq matières compte pour cinq, la dernière page est
  fausse et le découpage tombe au milieu d'une fiche. À laisser à `false` sinon —
  le comptage par sous-requête est nettement plus coûteux.
- **Les filtres survivent au changement de page.** La macro repart de
  `app.request.query.all` : famille, recherche, statut, mois, date sont conservés.
  C'est le défaut le plus pénible d'une pagination écrite à la main, et le plus
  facile à ne pas voir en développement, où l'on teste sans filtre.
- **Une page au-delà de la fin rend une liste vide, pas une erreur** : un lien
  périmé ou une suppression entre deux clics n'immobilise pas un écran de gestion.
- **Fenêtre de largeur constante** (`fenetre()`) : les numéros ne se déplacent pas
  sous le curseur au moment du clic, et une année d'audit ne produit pas une barre
  plus longue que le tableau.
- `Pagination::surTableau()` existe pour les agrégats déjà calculés en PHP, mais
  **pas pour une table** : charger 10 000 lignes pour n'en afficher 25 est un
  contresens.
- **Une liste vide n'affiche pas de barre** (`{% if page.total > 0 %}`) : « 0–0 sur
  0 » et des flèches inertes n'apprennent rien, le message « aucun élément » du
  tableau suffit. Le décompte reste en revanche visible sur une page unique — il
  dit *combien d'éléments existent*, pas seulement où l'on se trouve.

> **Turbo Frames.** Les liens de page vivent **dans** le frame et n'ont donc
> **pas** de `data-turbo-frame="_top"` — c'est tout l'intérêt : seul le tableau se
> redessine. Ils font exception à la règle des liens de détail, qui eux doivent en
> sortir. `TurboNavigationTest` fige les deux sens :
> `testLesActionsDuTableauDeStockSortentDuFrame` (les actions sortent) et
> `testLesLiensDePaginationRestentDansLeFrame` (la navigation reste).

**Listes paginées** : articles, ventes, stock, familles, fournisseurs, production,
clôtures, détail des pertes, utilisateurs, inventaires, plus `/pilotage/ventes` et
`/pilotage/audit`.

### Recherche — un seul mécanisme, comme la pagination

**Tous les tableaux du back-office se cherchent**, par le paramètre `q`.
`App\Repository\Recherche` porte la requête, `templates/admin/_recherche.html.twig`
le rendu. Neuf listes à équiper, c'était neuf occasions d'oublier l'échappement.

```php
// Dans le repository — le terme peut être null, la garde est dans le helper.
Recherche::appliquer($qb, $recherche, 'u.nom', 'u.email');
```

```twig
{% import 'admin/_recherche.html.twig' as recherche %}
{{ recherche.barre('admin_utilisateurs', 'Nom, e-mail ou rôle') }}
```

| Tableau | Cherché sur |
|---|---|
| Articles | nom (avec les filtres famille et statut, formulaire propre à l'écran) |
| Familles | nom |
| Fournisseurs | nom, téléphone, e-mail |
| Stock | matière, unité, **nom du fournisseur** |
| Ventes | **numéro de ticket**, nom de la caissière |
| Production | produit, **matière de la fiche** |
| Clôtures | nom de la caissière |
| Utilisateurs | nom, e-mail, rôle (valeur brute : `caissier` retombe sur `ROLE_CAISSIER`) |
| Pertes | matière, article |
| Inventaires | auteur, valideur, commentaire |

- **`%` et `_` sont échappés** (`addcslashes`). C'est le défaut classique d'un
  `LIKE` écrit à la main, et il est **muet** : chercher « 100% » ramène la table
  entière au lieu de rien, le tableau se remplit et personne ne s'en aperçoit.
- **Les conditions `OR` sont parenthésées.** Sans cela, le `OR` se lie au reste de
  la requête et une recherche fait ressortir des lignes exclues par les autres
  filtres — un mois, une période, un statut.
- **La barre reconduit les autres paramètres** de l'URL en champs cachés, mais
  **abandonne `page`** : une nouvelle recherche repart de la première page, sinon
  on atterrit sur une page qui n'existe plus dans le résultat filtré et le tableau
  paraît vide.
- **Une recherche vide ne filtre rien** : `normaliser()` ramène `null`, la requête
  ressort inchangée. Un champ effacé ne doit pas vider l'écran.
- La recherche des ventes rend enfin exploitable le **code-barres du ticket** :
  un lecteur USB se comporte comme un clavier, il saisit le numéro dans ce champ
  et la vente sort. C'était l'écart signalé plus bas — le code était scannable
  mais sans consommateur.

> **Turbo Frames.** Comme la pagination, le formulaire de recherche vit **dans**
> le frame et ne porte donc **pas** `data-turbo-frame="_top"` : filtrer ne
> redessine que le tableau. Il se distingue des actions par `role="search"`, sur
> lequel `TurboNavigationTest` s'appuie pour figer les deux sens
> (`testLeFormulaireDeRechercheResteDansLeFrame`).

> Le **commentaire d'une perte n'est pas cherchable** : `Perte` ne le stocke pas,
> `PerteService` le concatène au motif du `MouvementStock` (« Casse — panne du
> frigo »). Il faudrait un champ sur l'entité pour pouvoir y revenir.

**Volontairement non paginés** : le top 10 du tableau de bord, le top 5 des pertes,
la ventilation par motif et le rapport Z d'une session. Ces tableaux sont **bornés
par construction** — paginer un top 10 n'a pas de sens.

### Photos des touches produits

`Article::$image` porte le **nom du fichier**, jamais un chemin ni une URL :
déplacer le stockage ne doit pas obliger à réécrire la table. Le chemin public se
compose dans `App\Service\ImageArticle::chemin()`, exposé aux gabarits par la
fonction Twig `image_article()`.

> Le traitement lui-même (GD, réduction, nom tiré au sort, suppression) vit dans
> `App\Service\StockageImages`, dont `ImageArticle` et `LogoBoutique` héritent.
> Chaque usage ne dit que trois choses : **où** les fichiers vivent, sous **quelle
> URL** ils sont servis, à quelle **taille** ils sont ramenés. Extrait plutôt que
> recopié : chacune de ces lignes porte une précaution (transparence préservée,
> ancien fichier effacé après le nouveau), et c'est l'oubli d'une de ces
> précautions dans une seconde copie qui ne se verrait pas à l'œil.

- **Stockage** : `public/uploads/articles/`, servi en statique par le serveur web
  — la grille de caisse charge une image par touche, il n'y a pas de raison
  d'amorcer le noyau pour chacune. Répertoire **gitignoré** : c'est du contenu
  d'exploitation, il accompagne la base, pas le dépôt.
- **Réduction à l'enregistrement**, grand côté ramené à 400 px (`ImageArticle`,
  GD). Une photo prise au téléphone fait 4 000 px pour une touche qui en occupe
  92 : servie telle quelle elle chargerait la tablette du comptoir, et surtout
  elle gonflerait le cache du Service Worker — dont dépend la caisse hors ligne.
  L'alpha est préservé (`imagealphablending` / `imagesavealpha`), sans quoi un
  PNG transparent ressortirait sur du noir.
- **Nom de fichier tiré au sort** à chaque téléversement. Ce n'est pas un détail :
  remplacer une photo **change son URL**, ce qui autorise le « cache d'abord » du
  Service Worker et le `Cache-Control: immutable` du `.htaccess`. Une adresse
  donnée désigne toujours la même image.
- **Le disque ne garde pas d'orphelin** : l'ancien fichier est effacé au
  remplacement, au retrait explicite (case « Retirer la photo ») et à la
  suppression de l'article. L'ancienne n'est retirée qu'**après** l'écriture de
  la nouvelle : en cas d'échec, l'article garde la photo qu'il avait.
- Le champ `imageFichier` est **non mappé** : l'entité ne connaît qu'un nom, le
  dépôt sur disque appartient au service. Formats acceptés : JPEG, PNG, WebP, 5 Mo.
- **Hors ligne** : `public/sw.js` met `/uploads/` en cache « cache d'abord »,
  comme `/assets/`. Sans cette règle, la grille perdrait ses photos dès la coupure
  alors que tout le reste continuerait de fonctionner.
- En **test**, le service pointe vers `%kernel.cache_dir%/images-articles`
  (`when@test` dans `services.yaml`) : les tests écrivent de vrais fichiers, ils
  n'ont rien à faire dans les images de la boutique.

Le catalogue `/caisse/catalogue.json` expose la clé `image` (chemin public ou
`null`) — `FuiteDonneesCaisseTest` fige la liste blanche, l'y ajouter était donc
une décision, pas un effet de bord.

### Paramètres de l'établissement (`/admin/parametres`)

Raison sociale, enseigne, logo, adresse, mentions légales, pied de ticket. Réservé
à `ROLE_GERANT`. Ces informations sortent sur **chaque ticket** et **chaque
rapport Z** : c'est le seul écran dont une erreur s'imprime des milliers de fois.

**Stockage en clé/valeur** : entité `Parametre` (`cle`, `valeur`, `modifieA`), et
le catalogue de référence dans l'énumération `App\Enum\CleParametre` — clé
persistée, libellé, aide de saisie, valeur par défaut, groupe. **Ajouter un
paramètre = ajouter un cas à l'énumération**, sans migration ni retouche du
formulaire (`ParametresBoutiqueType` boucle sur `cases()`) ni du gabarit (les
sections viennent de `groupe()`).

- Une clé absente **retombe sur la valeur par défaut** : l'application imprime un
  ticket dès la première installation, avant toute saisie. `ParametresBoutique`
  avale même l'absence de la table (migration non jouée) — un ticket ne doit pas
  échouer pour un paramètre.
- Les valeurs sont **mémorisées pour la durée de la requête** : un ticket en lit
  une dizaine, il serait absurde de faire dix requêtes SQL.
- `ParametresBoutique::pourTicket()` est déclaré comme **fabrique** de
  `ParametresTicket` dans `services.yaml`. Les consommateurs (`TicketBuilder`,
  `ImpressionService`, `RapportQuotidienTexte`) injectent ce type sans rien savoir
  du stockage — ces informations venaient de `.env` auparavant, et rien chez eux
  n'a bougé.
- `enregistrer()` **n'écrit que les clés présentes** dans la soumission : un
  formulaire qui n'expose pas une clé ne l'effacera donc pas.

**Le logo** (`CleParametre::LOGO`) est le seul paramètre qui ne se tape pas au
clavier. Sa valeur persistée est un **nom de fichier**, comme `Article::$image` —
service `App\Service\LogoBoutique`, répertoire `public/uploads/boutique/`,
grand côté ramené à **600 px**.

- **600 et non 400** (la borne des touches produits) : le logo s'imprime sur toute
  la largeur du papier thermique — 384 points à 203 dpi sur 58 mm. Plus petit,
  il ressortirait crénelé sur le seul support où il n'y a pas de seconde chance —
  le papier est déjà sorti.
- **Répertoire distinct de celui des articles** : ce n'est pas une photo de touche,
  et le mélanger aux quarante images du catalogue rendrait une sauvegarde ou un
  nettoyage impossibles à trier. Il reste sous `/uploads/`, donc mis en cache
  « cache d'abord » par le Service Worker : le reçu garde son logo hors ligne.
- `CleParametre::estFichier()` **écarte la clé de la boucle** qui engendre les
  champs texte, remplacée par un couple non mappé « téléverser / retirer »
  (`logo_fichier`, `logo_retirer`) — même schéma que la photo d'article. Un champ
  texte sur cette clé laisserait taper n'importe quel nom, et le ticket
  désignerait un fichier absent du disque.
- La case **« Retirer le logo » ne s'affiche que s'il y en a un**, et le gabarit
  marque alors le champ `setRendered` : sans cela `form_end` la ferait réapparaître
  seule en bas de page, avec le reste du formulaire non affiché.
- **Le disque ne garde pas d'orphelin** : `definirLogo()` efface l'ancien fichier,
  mais **après** l'écriture de la nouvelle valeur — un échec laisse le ticket avec
  le logo qu'il avait plutôt qu'avec un nom pointant dans le vide.
- **Un fichier disparu retire l'image** (`onerror="this.remove()"`) : le nom de la
  boutique figure en clair juste en dessous, et une icône de lien cassé en tête de
  ticket ne rend service à personne. Même parti pris que les touches produits.
- **Rien sur la sortie ESC/POS**, qui n'envoie que du texte : imprimer un logo
  demanderait une trame raster (`GS v 0`), à traiter avec l'écart n° 3.
- En **test**, le service pointe vers `%kernel.cache_dir%/logo-boutique`.

**L'identité s'affiche partout, et n'est écrite nulle part.** Le back-office et le
pilotage portaient `ZedPOS` en dur dans leurs gabarits — le nom du **logiciel**, là
où l'exploitant attend celui de sa **boutique**. Les deux lisent maintenant la même
table que le ticket, par deux fonctions Twig (`App\Twig\BoutiqueExtension`) :

| Fonction | Rend |
|---|---|
| `nom_boutique()` | l'enseigne, à défaut la raison sociale (`ParametresBoutique::nom()`) |
| `logo_boutique()` | le chemin public du logo, ou `null` |

- **Sans argument** ni l'une ni l'autre : il n'y a qu'un établissement, et le
  gabarit n'a pas à savoir d'où vient la valeur. Renommer l'enseigne dans
  `/admin/parametres` renomme tous les écrans, onglets du navigateur compris.
- **L'enseigne prime sur la raison sociale**, même règle qu'en tête de ticket et
  portée par le seul `ParametresBoutique::nom()` : « ETS KOUAME SARL » est ce qu'on
  écrit au fisc, pas ce qu'on lit sur la devanture, et les deux ne doivent pas se
  contredire d'un écran à l'autre.
- **À défaut de logo, la pastille « Z »** subsiste dans les deux en-têtes : c'est
  l'état de toute installation neuve, et un en-tête ne doit pas s'ouvrir sur un trou.
- Le nom **passe à la ligne** dans la barre latérale du back-office (240 px de
  large) mais est **tronqué** dans l'en-tête du pilotage : celui-ci est collant et
  partage sa ligne avec « Quitter » sur un téléphone, où deux lignes repousseraient
  les onglets et voleraient de la hauteur aux chiffres à chaque défilement.
- Le fond du logo est **blanc** dans les deux en-têtes (barre brune, dégradé
  sombre) : un logo à fond transparent y disparaîtrait.
- La caisse, la connexion et l'installation gardent `ZedPOS` : ce sont des écrans
  du logiciel, pas de la vitrine — et la caisse doit s'afficher hors ligne, sans
  dépendre d'une lecture en base.

`ParametresBoutiqueTest` couvre le stockage et la reprise sur le ticket ;
`LogoBoutiqueTest` fige le téléversement, la réduction, le refus d'un fichier qui
n'est pas une image (422), la propreté du disque, l'arrivée sur le papier et
l'absence de nom codé en dur dans les deux espaces de gestion.

### Interface de caisse tactile (`/caisse`)

- Plein écran, sans navigation, réservée à `ROLE_CAISSIER`. 3 colonnes : familles +
  grille de touches produits (≥ 92 px) à gauche, ticket au centre (total très gros),
  pavé de règlement à droite (Espèces, Wave, Orange Money, MTN MoMo, Moov Money).
- Deux modes commutables : **BOULANGERIE** (un appui = +1 unité, aucune étape) et
  **FASTFOOD** (un appui ouvre un panneau variantes + commentaire libre avant ajout).
- Tout l'état du ticket vit **côté client** dans le contrôleur Stimulus
  `assets/controllers/ticket_controller.js` : aucun rechargement pendant la commande,
  ajout d'article instantané. Seul l'**encaissement** appelle le serveur
  (`POST /api/vente`, JSON, idempotent) qui **recalcule tous les prix côté serveur**
  (jamais de confiance au client), crée la Vente/lignes/règlement dans la session
  de caisse **ouverte** du caissier, et renvoie le numéro de ticket. La vente est
  d'abord écrite en IndexedDB — voir « Caisse hors ligne ».
- **Montant reçu et rendu de monnaie** (espèces uniquement). La caissière saisit ce
  que le client lui tend ; l'écran affiche aussitôt la monnaie — ou, en rouge, ce
  qui **manque** encore, et `Encaisser` reste alors bloqué. Quatre coupures sont
  proposées d'un appui (le compte juste, puis le total arrondi aux coupures
  supérieures : `suggestionsEspeces()`) — taper « 2 000 » chiffre par chiffre
  pendant la file du matin coûte plus cher que le calcul.
  - Le champ est **facultatif** : laissé vide il vaut compte juste, et
    l'encaissement garde sa vitesse d'un seul appui. C'est la contrainte de la
    boulangerie rapide, elle ne se négocie pas contre un champ à remplir.
  - Le calcul se fait **à l'écran, sans réseau** (`renduMonnaie()` / `manquant()`
    dans `assets/caisse/calculs.js`) : c'est le geste immédiatement suivant, et
    hors ligne la réponse du serveur n'arriverait qu'au retour du réseau — d'où
    aussi le rappel de la monnaie dans le message de repli hors ligne.
  - Le règlement transmis est **la somme tendue**, pas le total : `EncaissementService`
    en déduit le rendu (excédent, espèces seulement — un paiement électronique ne
    peut pas dépasser le total), et le Z retranche ce rendu pour retrouver les
    espèces réellement en tiroir. L'écran ne fait donc foi pour personne.
  - Restitution : ligne **Rendu** sur le ticket 58 mm et en ESC/POS (mise en avant
    comme le total, `.ticket .rendu`), plus un rappel en grand au-dessus du reçu
    affiché après encaissement.
- « Mise en attente » : tickets mémorisés côté client, repris en un appui.
- L'écran exige une **session de caisse ouverte** : sans elle, `/caisse` redirige vers
  `/caisse/session/ouverture`. La barre supérieure donne accès à Dépense, Ticket X et
  Clôture Z (voir « Cycle de caisse » ci-dessous).

### Caisse hors ligne (Service Worker + IndexedDB)

La caisse doit continuer à encaisser quand Internet tombe. **Contrainte absolue :
aucune vente perdue, aucune vente dupliquée.**

**Ordre d'exécution d'un encaissement — non négociable :**

1. la vente est écrite **durablement en IndexedDB** avec son `uuid` (généré client) ;
2. l'écran est libéré (ticket suivant possible) ;
3. **seulement ensuite** la transmission réseau est tentée.

Le réseau n'intervient qu'à l'étape 3 : une coupure ne peut donc pas faire perdre
une vente. Et comme `POST /api/vente` est **idempotent sur l'uuid**, une requête
dont on ignore l'issue (coupure pendant la réponse) est rejouée sans créer de
doublon — c'est le filet de sécurité, pas une optimisation.

- **`assets/offline/file_synchronisation.js`** — la file et sa relance
  exponentielle (2 s → 5 min, gigue ±12,5 %). Aucune dépendance navigateur : dépôt,
  fonction d'envoi et horloge sont injectés, ce qui la rend testable sous Node.
  - `200` (rejeu) **et** `201` (création) valent succès → l'entrée quitte la file.
  - `400/404/422` = refus définitif → entrée marquée **`BLOQUEE`**, **jamais
    supprimée**, signalée dans le bandeau.
  - `401/403` (session expirée), `409` (caisse fermée), `5xx`, panne réseau →
    conservée et réessayée.
- **`assets/offline/depot_indexeddb.js`** — deux magasins : `catalogue` (snapshot
  produits) et `file_ventes` (clé = `uuid`). `depot_memoire.js` est le repli et
  sert aux tests.
- **`public/sw.js`** — Service Worker servi **à la racine** (portée `/`). Réseau
  d'abord + secours cache pour `/caisse` et `/caisse/catalogue.json` ; cache
  d'abord pour `/assets/*` (URL condensées, donc immuables). Il **n'intercepte
  jamais autre chose qu'un GET** : un `POST /api/vente` doit atteindre le réseau
  ou échouer franchement, pour que la file prenne le relais. Aucune redirection ni
  réponse non-200 n'est mise en cache (sinon la page de login serait servie hors ligne).
- **Catalogue** `GET /caisse/catalogue.json` → rangé en IndexedDB et rafraîchi à
  chaque connexion réussie. C'est lui qui reconstruit les touches produits ; le
  rendu Twig ne sert plus que de premier affichage.
- **Bandeau permanent** en haut de la caisse (`synchronisation_controller.js`) :
  « Synchronisé » / « Hors ligne — N ventes en attente » / « Synchronisation… » /
  « N vente(s) à vérifier ». Un clic force un vidage.
- **Un seul chemin d'écriture** : `POST /api/vente`. L'ancien `/caisse/encaisser`,
  non idempotent, a été **supprimé** — un second chemin ruinerait la garantie.
  Corollaire : pas de jeton CSRF sur l'encaissement (un jeton expirerait avant le
  rejeu) ; la protection repose sur `SameSite=Lax` et l'exigence de
  `Content-Type: application/json`.

**Tests JavaScript** (lanceur intégré de Node, aucune dépendance npm) :

```bash
node --test "tests/js/*.test.js"     # ou : npm test
```

`tests/js/file_synchronisation.test.js` simule **20 ventes encaissées hors ligne
puis une reconnexion**, y compris le cas le plus dangereux (le serveur enregistre
mais la réponse se perd) : le serveur reçoit exactement 20 ventes malgré les rejeux.
Côté PHP, `tests/Functional/SynchronisationHorsLigneTest.php` rejoue le même trafic
contre la vraie API — 60 requêtes pour 20 ventes, sans doublon.

### API d'encaissement (`/api/vente`)

- `POST /api/vente` (ROLE_CAISSIER) : reçoit `{uuid, mode, lignes, remise?, reglements}`.
  Crée la Vente **en transaction**, recalcule HT/TVA/TTC côté serveur, numérote
  chronologiquement (`Vaammjj-00001`). Service : `App\Service\EncaissementService`.
  - **Idempotence stricte** sur l'`uuid` (généré côté client) : rejouer la requête
    renvoie la vente existante (HTTP 200) sans créer de doublon ; création = 201.
  - **Paiement mixte** : plusieurs `reglements` ; l'électronique ne peut dépasser le
    total, le total réglé doit le couvrir. **Rendu de monnaie** = excédent (espèces).
  - **Remise** en `POURCENTAGE` ou `VALEUR`, plafonnée par rôle (**caissier 0 %,
    gérant 10 %**) ; **motif obligatoire au-delà de 500 FCFA**.
- `POST /api/vente/{uuid}/annuler` : motif obligatoire, **jamais de suppression**
  (statut → `ANNULEE`, motif conservé). Gérant et au-dessus sans restriction ; le
  **caissier sur le seul dernier ticket de sa session ouverte** (`VenteVoter`).
- Montants toujours en centimes ; erreurs métier via `EncaissementException` (code HTTP).
- La `Vente` accepte désormais un UUID client au constructeur et porte `remise`,
  `motifRemise`, `rendu`, `motifAnnulation`.
- L'encaissement **exige une session de caisse ouverte** (HTTP 409 sinon) : aucune
  création implicite, sinon la saisie du fond de caisse serait contournable.

### Cycle de caisse (`/caisse/session`)

Service central : `App\Service\SessionCaisseService` ; agrégation des chiffres :
`App\Service\RapportCaisseService` → DTO `RapportCaisse` (partagé par le X et le Z).

- **Ouverture** `/caisse/session/ouverture` : saisie du **fond de caisse** (en FCFA,
  converti en centimes). **Une seule session `OUVERTE` par caissier** — une seconde
  ouverture est refusée tant que la précédente n'est pas clôturée.
- **Dépenses de caisse** `/caisse/session/depense` : entité `MouvementCaisse`
  (`TypeMouvementCaisse` = `DEPENSE` | `SORTIE`, `CategorieDepense`, montant,
  commentaire). Une **dépense exige une catégorie** ; une **sortie** est un simple
  prélèvement (coffre, banque). Les deux diminuent le fond théorique.
- **Ticket X** `/caisse/session/x` : synthèse **intermédiaire** imprimable 58 mm.
  **Ne clôture rien et n'écrit rien** — la session reste `OUVERTE`.
- **Clôture Z** `/caisse/session/cloture` : le caissier saisit **uniquement le montant
  physiquement compté**. Le serveur calcule le
  **théorique = fond + espèces encaissées − dépenses − sorties** puis
  l'**écart = compté − théorique**. **Commentaire obligatoire si l'écart ≠ 0**
  (règle portée par `SessionCaisse::cloturer()`, non contournable par le formulaire).
  Les espèces encaissées sont **nettes du rendu de monnaie**.
- **Rapport Z** `/caisse/session/z/{id}` (58 mm) et `/admin/clotures/{id}` (gérant) :
  CA total, HT/TVA, nombre de tickets, panier moyen, ventilation **par mode de
  règlement** (somme = CA) et **par famille**, remises accordées, annulations,
  dépenses/sorties détaillées, fond théorique, compté et écart.

**Immuabilité d'une journée clôturée** — contrôle applicatif au niveau du domaine :
`SessionCaisse::garantirOuverte()` est appelée par `Vente::__construct()`,
`Vente::annuler()`, `MouvementCaisse::__construct()` et `SessionCaisse::cloturer()`.
Une session `CLOTUREE` n'accepte donc **plus aucune vente, annulation, dépense ni
re-clôture** (`\DomainException` ; HTTP 409 via l'API). Après un Z, le caissier ouvre
une **nouvelle** session. Couverture : `tests/Functional/SessionCaisseTest.php`.

### Espace de pilotage (`/pilotage`)

Réservé à `ROLE_DIRIGEANTE`. **Lecture seule.**

**Mobile-first, puis large.** La dirigeante consulte cet écran depuis son téléphone
à Abidjan : la mise en page de base reste **une colonne**, en-tête collant, onglets
défilables, aucun tableau large — les listes sont des cartes empilées. À partir de
`lg`, le cadre s'élargit (`max-w-3xl lg:max-w-7xl`) et le tableau de bord se
déplie en **grille à trois colonnes**, pour ne pas laisser deux bandes vides sur un
ordinateur. **L'ordre de lecture ne change pas** : ce qui vient en premier sur
téléphone reste en haut à gauche sur grand écran.

La largeur passe par le bloc Twig `pilotage_largeur` (`pilotage/base.html.twig`) :
une page qui gagnerait à rester étroite le redéfinit sans toucher au cadre.

- **Écran principal** `/pilotage` (`?jour=AAAA-MM-JJ` pour rejouer une journée) :
  CA du jour en très gros, comparé à **la veille** et au **même jour de la semaine
  précédente** (pastille verte/rouge, % signé) ; tickets et panier moyen ;
  **ventes par caissière** ; ventilation par mode de règlement avec barres de
  proportion ; **points de vigilance** (annulations, remises, écart de caisse,
  ruptures de stock, pertes valorisées) — le bloc vire à l'ambre dès qu'il y a
  quelque chose à signaler ; **top 10** des produits ; **courbe du CA sur 30 jours**
  (Chart.js). Sélecteur de journée **auto-soumis** (`soumission_auto_controller.js`,
  `requestSubmit()` et jamais `submit()`) plus trois raccourcis.

**Ventes par caissière.** `SyntheseJourneeService::parCaissiere()` produit, par
caissière ayant encaissé : tickets, CA, panier moyen, **part du CA en points de
base**, remises, annulations, écart de caisse et état de la session. Affiché en
barres horizontales (`graphique_caissieres_controller.js`) puis en liste détaillée,
et repris dans le rapport texte.

> **Quatre requêtes, pas une.** Ventes validées, annulations et sessions de caisse
> ne se comptent pas sur les mêmes lignes : une jointure unique les multiplierait
> entre elles, et une caissière ayant tenu **deux sessions dans la journée** verrait
> son chiffre doublé. `testDeuxSessionsPourUneMemeCaissiereNeDoublentPasSonChiffre`
> fige le cas.

Une caissière **sans vente n'apparaît pas** : la liste répond à « qui a vendu quoi »,
pas « qui était de service ». Son `ecart` reste **null** tant que sa caisse n'est pas
clôturée, même convention que pour la journée entière.

**Téléchargements** (liens `data-turbo="false"` — sinon Turbo attend du HTML et le
fichier ne descend pas) :

| Route | Fichier | Usage |
|---|---|---|
| `/pilotage/rapport.txt` | synthèse du jour en texte | à transmettre par WhatsApp |
| `/pilotage/rapport.csv` | une ligne par ticket | à trier dans un tableur |

Le `.txt` réutilise `RapportQuotidienTexte` : ce que la dirigeante télécharge et ce
que le cron envoie le soir sont le **même** message, au caractère près. Le `.csv`
(`RapportVentesCsv`) **inclut les ventes annulées**, avec statut et motif — c'est ce
qu'on vient y vérifier — mais les exclut de la ligne de total.
Ne pas confondre avec `/comptabilite` : ici on trie à la main, là-bas on transmet
des écritures équilibrées au cabinet.
- **Tickets** `/pilotage/ventes?jour=…` (paginé, 30/page) et détail
  `/pilotage/ventes/{uuid}` : lignes, règlements, remise, motif d'annulation et
  **nom du caissier**.
- **Service** `App\Service\SyntheseJourneeService` → DTO `SyntheseJournee`
  (montants en centimes, **variations en points de base**, jamais de float pour
  l'argent). L'écran et la commande consomment le **même** objet : les chiffres ne
  peuvent pas diverger.
- `ecartCaisse` vaut **null** tant qu'aucune caisse n'est clôturée — afficher « 0 »
  laisserait croire à une caisse juste.
- **Chart.js** est servi par AssetMapper (`importmap.php`). Le contrôleur Stimulus
  `assets/controllers/graphique_ca_controller.js` importe `{ Chart, registerables }`
  depuis `chart.js` et les enregistre lui-même : `chart.js/auto` **n'est pas** dans
  l'importmap et ne se résoudrait pas dans le navigateur.

### Rapport quotidien (`app:rapport-quotidien`)

```bash
php bin/console app:rapport-quotidien                   # journée en cours
php bin/console app:rapport-quotidien --date=2026-07-24 # journée précise
php bin/console app:rapport-quotidien -f rapport.txt    # écriture dans un fichier
```

Écrit sur la sortie standard une synthèse **courte en texte brut** (pas de Markdown
ni de tableau : WhatsApp ne les rend pas), prête à être copiée ou envoyée. La commande
**n'envoie rien** elle-même. Mise en forme : `App\Service\RapportQuotidienTexte`.

Planification à 21h30, après la clôture des caisses :

```cron
30 21 * * * cd /var/www/zedpos && php bin/console app:rapport-quotidien \
  | mail -s "ZedPOS - rapport du jour" dirigeante@exemple.ci
```

### Espace comptable — exports SYSCOHADA (`/comptabilite`)

Traduit l'activité d'une période en **écritures du SYSCOHADA révisé** (le plan
comptable OHADA applicable en Côte d'Ivoire), pour transmission au cabinet.
**Lecture seule** : aucune route d'écriture, et il ne doit jamais en être ajoutée
— la comptabilité lit ce que la caisse a produit, elle ne le corrige pas.

Accès : `ROLE_COMPTABLE`, `ROLE_GERANT` et `ROLE_DIRIGEANTE` (qui hérite du gérant)
— permission `Permission::EXPORTER_COMPTABILITE` (`DonneesGlobalesVoter`), écran
**et** téléchargements. Le caissier reste exclu.

Le gérant l'atteint par l'entrée **Comptabilité** de la barre latérale du
back-office ; l'en-tête de `/comptabilite` lui rend le chemin inverse
(« Back-office »), affiché sur `is_granted('ROLE_GERANT')` — on y reproduit le
pare-feu `^/admin`, pas une décision métier, et le comptable ne voit donc pas un
lien qui ne lui rendrait qu'un 403.

> Le gérant en a longtemps été **exclu** : un export sort de l'application
> l'intégralité du chiffre d'affaires, des charges et des écarts de caisse, et il
> lisait les chiffres à l'écran sans emporter les comptes. **Restriction levée** —
> ne pas la rétablir sans décision explicite.

**Trois journaux.**

| Journal | Contenu | Pièce |
|---|---|---|
| `VE` | Ventes, **centralisées par rapport Z** : une écriture par session de caisse et par journée | `Z{id session}` |
| `CA` | Dépenses réglées en espèces, sorties de fonds, écarts constatés au Z | `CA{id}` / `Z{id}` |
| `OD` | Pertes de stock valorisées | `PE{id}` |

Le journal des ventes ne reprend **pas** les tickets un par un : un mois de
boulangerie en compte plusieurs milliers, aucun cabinet ne les saisirait. La pièce
justificative est le rapport Z, le document papier qui reste au classeur.

**Correspondances principales** — toutes dans `App\Comptabilite\PlanComptable`,
seul endroit où un numéro de compte est écrit. Ne pas en coder ailleurs.

- Ventes : `7021` produits finis (article **doté d'une fiche technique**, donc
  fabriqué sur place), `7011` marchandises (revendu en l'état). Une famille peut
  imposer son compte (`FamilleProduit::$compteVente`, back-office → Familles).
- Remise : `7019` RRR accordés, **au débit** — un rabais diminue un produit, il ne
  s'impute pas en charge. TVA collectée : `4431`.
- Trésorerie : `5711` caisse, `5521`–`5524` monnaie électronique (Wave, Orange,
  MTN, Moov — compte 55 du SYSCOHADA révisé), `4111` clients pour le crédit.
- Dépenses : `6021` approvisionnement, `612` transport, `624` entretien, `605`
  eau/électricité, `6056` petit équipement, `6588` divers, `585` sortie de caisse.
  L'**avance au personnel va en `4211`** : c'est une créance sur le salarié, pas
  une charge.
- Écart de caisse : `6588` si manquant, `7588` si excédent.
- Pertes : débit `6032`/`6031`/`736` (variation de stocks), crédit `321`/`311`/`361`.

**Équilibre garanti par construction.** `EcritureComptable` refuse une écriture
déséquilibrée **à la construction**, pas à l'export : mieux vaut échouer là où
l'erreur est commise que là où elle est constatée (à la réception, chez le
comptable). Les montants d'une écriture sont pris dans les colonnes de la vente
(`total_ttc`, `total_tva`, `total_ht`, `remise`), qui font foi ; les lignes ne
servent qu'à **ventiler** entre comptes de produits. Un taux de TVA modifié sur un
article après coup peut donc décaler la ventilation, jamais les totaux.
Les ventes **annulées sont exclues**, comme partout ailleurs.

**Contrôles.** L'écran présente cinq rapprochements entre l'application et les
écritures (CA TTC, TVA collectée, espèces nettes du rendu, mouvements de caisse,
écarts de caisse) plus l'équilibre débit/crédit. Ils passent par construction —
c'est l'intérêt : un contrôle qui casse signale une régression, et le comptable a
sous les yeux la vérification qu'il aurait faite lui-même.

**Trois formats** (`App\Comptabilite\FormatExport`) :

| Format | Fichier | Usage |
|---|---|---|
| Écritures | CSV `;` + BOM UTF-8 | se relit et se corrige dans un tableur |
| FEC | 18 colonnes tabulées | s'importe dans le logiciel du cabinet |
| Balance | CSV `;` + BOM UTF-8 | se contrôle d'un coup d'œil, contrôles en pied |

Le BOM n'est pas décoratif : sans lui Excel sous Windows lit le CSV en ANSI et
massacre les accents. Le FEC n'a **aucun mécanisme d'échappement** — tabulations et
retours à la ligne sont remplacés par une espace dans les libellés, sinon toutes
les colonnes suivantes se décalent. Les colonnes non renseignées (lettrage, devise)
restent **présentes et vides** : les retirer ferait échouer l'import.

`GenerateurEcrituresSyscohada` calcule, `ExportComptable` met en fichier, ni l'un
ni l'autre ne fait le travail de l'autre. L'écran et les trois formats consomment
le **même** `JeuEcritures` : les chiffres affichés et les chiffres exportés ne
peuvent pas diverger.

> **Piège Turbo** : les liens de téléchargement portent `data-turbo="false"`.
> Turbo intercepte les clics et attend une page HTML ; sans cet attribut le
> fichier ne se télécharge pas et la navigation échoue en silence.

```bash
php bin/console app:export-comptable --mois=2026-06 --format=fec -o juin.txt
php bin/console app:export-comptable --du=2026-06-01 --au=2026-06-30
php bin/console app:export-comptable --format=balance     # mois en cours, stdout
```

La commande **échoue (code 1)** si un contrôle n'est pas conforme : un fichier ne
doit pas partir au cabinet sans que les rapprochements soient justes. Envoi
automatique le 1er du mois, pour le mois écoulé :

```cron
0 7 1 * * cd /var/www/zedpos && php bin/console app:export-comptable \
  --mois=$(date -d 'last month' +\%Y-\%m) --format=fec -o /tmp/zedpos.txt
```

### Journal d'audit inaltérable (`/pilotage/audit`)

- **Service unique** `App\Service\AuditLogger` : toute écriture passe par lui. Il
  déduit l'auteur (`Security`) et l'IP (`RequestStack`) — surchargeables pour les
  événements de sécurité et la console. Chaque entrée stocke **utilisateur, action,
  entité, id, valeurs avant/après en JSON, IP, horodatage** (`JournalAudit`).
- L'entrée est persistée **et flushée dans la transaction de l'appelant** : une
  action annulée par un rollback ne laisse pas de trace fantôme.
- **Actions tracées** (`App\Enum\ActionAudit`) : `CONNEXION`, `DECONNEXION`,
  `ECHEC_CONNEXION`, `VENTE_ANNULEE`, `REMISE_ACCORDEE`, `PRIX_MODIFIE`,
  `PERTE_SAISIE`, `INVENTAIRE_VALIDE`, `CAISSE_CLOTUREE`, `ECART_CAISSE`,
  `UTILISATEUR_CREE`, `UTILISATEUR_MODIFIE`, `UTILISATEUR_ACTIVE`,
  `UTILISATEUR_DESACTIVE`.
  Une clôture avec écart produit **deux** entrées (clôture + écart), pour filtrer
  les écarts seuls.
  `UTILISATEUR_MODIFIE` est **sensible** (surligné) : un rôle changé ou un
  identifiant réinitialisé redistribue un accès. Le secret lui-même n'y figure
  **jamais**, pas même haché — seul `secret_remplace: true` l'est. Un journal
  d'audit se consulte, il ne doit pas devenir un second endroit où traînent des
  identifiants.
- **Points d'appel** : `EncaissementService` (annulation, remise > 0),
  `Admin\ArticleController::edit` (uniquement si le prix change réellement),
  `PerteService`, `SessionCaisseService::cloturer`, `CreerUtilisateurCommand`,
  `CreationUtilisateur` (création et modification),
  `InventaireService::valider()` (une entrée par ligne corrigée),
  `Admin\DashboardController::basculerUtilisateur`, `AuditConnexionSubscriber`.
  `InventaireService::valider()` (un écart par ligne corrigée).
- **Inaltérabilité** — trois barrières : aucun setter sur `JournalAudit` ; aucune
  route d'écriture (`/pilotage/audit` est **GET seulement**, et il ne doit jamais en
  être ajouté) ; `App\EventListener\JournalAuditImmuableListener` rejette tout
  UPDATE/DELETE au niveau de l'ORM (`\DomainException`). La garantie est
  **applicative** : elle ne couvre pas un accès SQL direct à MariaDB.
- **Consultation** réservée à `ROLE_DIRIGEANTE` : filtres par période, utilisateur et
  type d'action (groupé par famille), pagination 50 par page
  (`JournalAuditRepository::rechercher()`). Les actions sensibles (échec de connexion,
  annulation, remise, écart, désactivation) sont surlignées en ambre.
- **Activation / désactivation de compte** : `POST /admin/utilisateurs/{id}/basculer`,
  réservée à `ROLE_DIRIGEANTE` (couper un accès n'est pas de la gestion courante) ;
  impossible sur son propre compte.

### Inventaire (`/admin/inventaires`)

**Le seul chemin par lequel un stock se corrige.** Modifier `stockActuel` à la main
ne créait ni mouvement ni trace d'audit : l'historique divergeait du stock affiché
sans que rien ne le signale. Le champ a donc **disparu du formulaire de
modification** d'une matière (`MatierePremiereType`, option `stock_initial`, vrai à
la création seulement) — absent, donc non soumettable même en forgeant la requête.

Entités `Inventaire` + `LigneInventaire`, service `App\Service\InventaireService`.

- **Ouverture** — la feuille fige l'état théorique de **tout ce qui est suivi en
  stock** : matières premières *et* articles `suiviStock` (une boisson revendue
  telle quelle dérive autant qu'un sac de farine). Sont figés aussi le **libellé**,
  l'**unité** et le **coût unitaire** : une feuille est un document daté, elle doit
  rester lisible telle qu'imprimée même si l'article est renommé, et l'écart
  valorisé ne doit pas changer parce que le coût moyen a bougé depuis.
- **Une seule feuille ouverte à la fois** (`InventaireRepository::enCours()`) : deux
  figeraient le même théorique et la seconde validation écraserait la première.
- **Feuille imprimable** `/admin/inventaires/{id}/feuille` : A4, noir et blanc, une
  case vide par ligne. **Le théorique n'y figure pas** — lire « 42 » avant de
  compter suffit à en trouver 42. C'est le seul écran du back-office qui cache
  volontairement une donnée qu'il possède.
- **Saisie** en unités, convertie en millièmes. Une case **vide** vaut « non
  comptée », un **zéro** vaut « il n'en reste aucun » : à la validation, les lignes
  non comptées sont **ignorées**. Une feuille rendue à moitié remplie ne doit pas
  mettre à zéro ce qu'on n'a pas eu le temps de relever.
- **Validation** — `Inventaire::valider()` porte les règles indépendantes de
  l'appelant et lève **avant toute écriture** : rien n'est appliqué si la feuille
  est refusée. **Commentaire obligatoire dès qu'un écart est constaté**, même règle
  que la clôture de caisse. Chaque écart produit alors un `MouvementStock`
  **INVENTAIRE** (quantité signée, `source = inventaire`) et une entrée
  `INVENTAIRE_VALIDE` au journal d'audit — une par ligne corrigée, parce que le
  journal se lit pour retrouver ce qui est arrivé à *un* produit.
- **Immuabilité** : `Inventaire::garantirEnCours()`, appelée par
  `LigneInventaire::compter()`, `ajouterLigne()`, `valider()` et `abandonner()`.
  Une feuille validée a produit des écritures de stock ; y revenir les rendrait
  fausses. Même barrière que `SessionCaisse::garantirOuverte()`, applicative elle
  aussi (elle ne couvre pas un UPDATE SQL direct).

> **L'écart est appliqué en delta, jamais en écrasant `stockActuel` avec la
> quantité comptée.** Entre le comptage en réserve et la validation à l'écran, des
> ventes ont pu déstocker : poser la quantité comptée telle quelle les effacerait.
> Le comptage constate un écart à un instant donné, c'est cet écart qu'on reporte.
> `testLesVentesEntreLeComptageEtLaValidationNeSontPasEffacees` fige le cas.

**Abandon** : une feuille non validée se supprime (`orphanRemoval` sur les lignes).
Aucune écriture de stock n'a eu lieu, il n'y a rien à défaire.

### Module Pertes et alertes de seuil

- **Saisie rapide** `/admin/pertes/saisie` (`PerteType`) : matière OU article, quantité,
  motif (casse, périmé, invendu, erreur de production, personnel, offert), commentaire.
- `App\Service\ValorisationService` valorise automatiquement au **coût moyen pondéré**
  (matière) ou au **coût de revient** (article avec fiche). `App\Service\PerteService`
  crée la `Perte` valorisée + un `MouvementStock` **PERTE** et décrémente le stock
  (matière toujours, article seulement si `suiviStock`).
- **Synthèse mensuelle** `/admin/pertes?mois=YYYY-MM` : total valorisé, ventilation par
  motif, top 5 des produits les plus perdus, détail du mois.
- **Alertes de seuil** : `App\Service\AlerteStockService` liste les matières sous
  `stockMini` (exposé en **variable globale Twig `alertesStock`**) → bandeau ambre
  affiché dans le back-office et sur l'écran de caisse.

### Déstockage automatique (stock ↔ ventes)

- `App\EventListener\DestockageVenteListener` (Doctrine `postPersist` + `preUpdate`
  → travail exécuté en `postFlush` avec garde de ré-entrance, car l'id de la vente
  n'existe qu'après l'INSERT).
- À la **création** d'une vente : pour chaque ligne, si l'article a une fiche
  technique, chaque matière première est décrémentée de
  `quantité vendue × quantité fiche × 1/(1 − perte)` ; sinon, si l'article est
  **suivi en stock** (`Article.suiviStock`, ex. boissons), son stock est décrémenté
  directement. Un `MouvementStock` **SORTIE_VENTE** (quantité signée) est créé par
  décrément, avec `source = vente`.
- À l'**annulation** : les mouvements **inverses** (ENTREE) sont générés et le stock
  restauré.
- Le stock **peut devenir négatif** (une vente n'est jamais bloquée) mais **journalise
  une alerte** (`logger->warning`). `Article` porte désormais `suiviStock`,
  `stockActuel`, `stockMini`.

### Ticket de caisse et impression

**Le papier fait 58 mm, la tête n'en imprime que 48.** L'imprimante du comptoir
déclare un format de 58 × 3276 mm — la seconde valeur étant la longueur maximale
d'un rouleau continu, pas celle d'un ticket. Trois conséquences, toutes portées
par `templates/ticket/_styles.html.twig` :

- **`width: 58mm` et `padding: 3mm 5mm`.** Une tête 58 mm trace 384 points à
  203 dpi, soit 48 mm, centrés sur le papier : les 5 mm de chaque côté sont
  exactement ce qu'elle ne couvre pas. Le retrait ne coûte donc aucune largeur
  utile, il empêche seulement le texte de tomber dans une bande où il serait
  rogné — sans avertissement, et sans que le ticket paraisse anormal tant qu'on
  ne compare pas avec le total.
- **Le retrait survit à `@media print`.** La règle d'impression remettait
  `padding: 0` (« la page EST le ticket ») : sur ce papier, cela poussait le
  texte dans la bande morte. Elle ne remet plus que la largeur et le fond.
- **`@page { size: 58mm auto }`, jamais `58mm 3276mm`.** Une hauteur fixe ferait
  dérouler trois mètres de papier à chaque vente. Seule la largeur doit
  correspondre au réglage du pilote.

Le corps est en **Tahoma 11 px**, soit une trentaine de colonnes sur 48 mm —
autant que les **32 colonnes** de la police A côté ESC/POS (`ImpressionService::LARGEUR`).
Les deux supports replient donc les lignes au même endroit, et un libellé qui
tient à l'écran tient sur le papier. Le rapport X / Z (`caisse/rapport.html.twig`)
sort de la même imprimante et suit les mêmes valeurs.

**La police est imposée par la tête thermique, pas par le goût.** Elle chauffe un
point ou ne le chauffe pas : elle ne connaît pas le gris. Le ticket était en
**Courier New** — l'allure d'un ticket de caisse, et une sortie pâle et hachée :
ses traits font un pixel de large, le navigateur les lisse en gris, et chaque
pixel gris tombe au hasard en noir ou en blanc. Ses empattements, larges d'un
point, disparaissaient purement et simplement.

- **`Tahoma, Verdana, 'DejaVu Sans', Arial, sans-serif`** : sans empattement,
  traits épais, dessinée pour les petites tailles à basse résolution. DejaVu Sans
  couvre les postes non Windows. Polices système uniquement, comme partout
  ailleurs — un ticket doit sortir hors ligne.
- **Le monospace ne manque pas** : les montants sont alignés par flexbox, pas par
  des espaces de remplissage. C'est l'ESC/POS qui compte des colonnes, pas la
  feuille de style. `font-variant-numeric: tabular-nums` garde les chiffres en
  colonne d'une ligne à l'autre.
- **Ni gris ni lissage à l'impression** : `.muted` repasse en `#000` et
  `-webkit-font-smoothing: none` coupe l'antialiasing sous `@media print`. Une
  demi-teinte n'est qu'un semis de points — plus sale que du noir franc, et pas
  plus discrète. À l'écran, les deux gardent leur rôle.
- **Séparateurs en trait plein**, plus en pointillés : un tiret d'un point sur
  deux à 203 dpi sort gris et irrégulier, quand il sort.

> Le retour en arrière serait **invisible à l'écran**, où Courier s'affiche
> parfaitement — il ne se verrait qu'au comptoir, sur le papier déjà sorti. D'où
> `CaisseTest::testLeTicketEstDansUnePoliceQuiSortSurUneTeteThermique`.

> **Si le ticket sort encore mal, vérifier l'échelle d'impression du navigateur
> avant le CSS** : Chrome propose « Ajuster à la page », qui réduit tout et rend
> les petits corps illisibles. Il faut **100 %**, sans en-tête ni pied de page.

> Le **code-barres tient de justesse** : 46 mm sur les 48 imprimables en HTML,
> et 178 modules à deux points en ESC/POS, soit 356 des 384 points de la tête.
> Un numéro de ticket plus long que `Vaammjj-00001` déborderait, et l'imprimante
> tronquerait le symbole sans rien signaler. C'est la contrainte à vérifier avant
> de toucher au format du numéro.

> ⚠️ Les commentaires **CSS** de ces gabarits partent dans la réponse HTTP, à la
> différence des commentaires Twig `{# … #}`. `FuiteDonneesCaisseTest` y interdit
> tout terme de gestion : « tenir le contenu à l'**écart** des bords » a suffi à
> faire échouer la suite. Écrire « en dehors de ».

- **Vue HTML 58 mm** imprimable via `window.print()` (CSS `@media print`, `@page size: 58mm`) :
  `GET /caisse/ticket/{uuid}` → `templates/ticket/ticket.html.twig`. En-tête (**logo**,
  raison sociale, adresse Abengourou, NCC, n° ticket, date/heure, caissier), lignes,
  total, ventilation TVA, règlement(s), rendu, **pied paramétrable** et **code-barres
  du numéro de ticket**.
- Infos boutique saisies dans le **back-office** (`/admin/parametres`, table
  `parametre`) → service `App\Service\ParametresTicket`, dont
  `ParametresBoutique::pourTicket()` est la fabrique. Elles étaient auparavant dans
  `.env` (`TICKET_*`) : ces variables n'existent plus, ne pas les réintroduire.
- `App\Service\TicketBuilder` construit un `TicketData` (indépendant du support) partagé
  par la vue HTML et l'ESC/POS ; `App\Service\ImpressionService` prépare la commande
  **ESC/POS** (texte ASCII + coupe papier + ouverture tiroir), exposée en base64 par
  `GET /caisse/ticket/{uuid}/escpos` pour un futur pont d'impression local.
- **Impression automatique** après encaissement via un iframe caché (`?auto=1`), avec
  la case **« Imprimer le ticket »** (décochable) dans l'écran de caisse.

**Code-barres du numéro de ticket.** Le numéro (`Vaammjj-00001`) est imprimé en
**Code 128, jeu B** : on retrouve une vente en la scannant plutôt qu'en la
cherchant. `App\Service\CodeBarres128` encode, `App\Service\CodeBarres` porte la
géométrie obtenue (barres en modules).

- **Jeu B** parce qu'il couvre l'ASCII imprimable, et qu'un numéro mêle lettres,
  chiffres et tiret. Le jeu C serait deux fois plus compact sur les chiffres au
  prix d'un changement de jeu en cours de chaîne — invisible sur ce papier.
- **Écrit à la main, sans dépendance** : la spécification tient en une table de
  motifs et une somme de contrôle, et elle ne bouge pas. Une bibliothèque
  n'aurait servi qu'au rendu HTML — côté thermique, c'est le firmware qui dessine.
- **Deux chemins, deux techniques, la même chaîne** :
  - HTML : un **SVG vectoriel** (`_contenu.html.twig`). Pas des `<div>` en pixels —
    le navigateur les arrondirait au sous-pixel et deux barres voisines finiraient
    par se toucher. Le noir vient de `fill`, pas d'un fond : une barre SVG est du
    contenu, elle s'imprime donc sans dépendre du réglage « imprimer les
    arrière-plans », désactivé par défaut, qui effacerait le code.
  - ESC/POS : la commande native `GS k 73` avec le préfixe `{B`. On envoie **la
    chaîne, pas une image** : le firmware trace à la résolution de la tête. Une
    trame calculée en PHP serait rééchantillonnée et des barres d'un point se
    confondraient.
- **Zones de silence de 10 modules** incluses dans le symbole. C'est l'oubli
  classique : sans elles un lecteur ne trouve pas le départ du code, et rien ne
  paraît anormal à l'impression.
- Le numéro est **répété en clair sous le code** : si le lecteur refuse, la
  caissière le lit et le saisit.
- Un numéro non encodable (import, format futur) donne un ticket **sans**
  code-barres plutôt qu'une impression qui échoue — la caisse ne doit pas se
  bloquer sur un ornement.
- `CodeBarres128Test` déroule à la main l'encodage de « A » depuis la table
  normative : un code-barres faux s'imprime proprement et c'est le lecteur, au
  comptoir, qui refuse — les invariants de forme seuls ne suffisent pas.

> Ce code-barres **ne vaut pas facture normalisée**. Il encode un numéro interne,
> pas une signature fiscale : l'écart n° 4 (RNE / DGI) reste entier.

> **Il a désormais un consommateur** : la recherche de `/admin/ventes` porte sur
> `v.numero`. Un lecteur USB se comporte comme un clavier — il saisit le numéro
> dans la barre de recherche et la vente sort. Le code était scannable mais sans
> emploi ; il ne l'est plus.
>
> `/pilotage/ventes` n'a en revanche **pas** de champ de recherche : la dirigeante
> y filtre par date, elle n'a pas de ticket en main à scanner.

**Un seul format, deux supports.** Le ticket a **deux sources de vérité uniques**,
à ne jamais recopier :

| Fichier | Rôle |
|---|---|
| `templates/ticket/_contenu.html.twig` | le **balisage** du ticket 58 mm |
| `templates/ticket/_styles.html.twig` | le **style** du ticket 58 mm |

Tous deux sont inclus par `ticket/ticket.html.twig` (page imprimable) **et** par
`caisse/index.html.twig` (reçu affiché après encaissement). Ce que la caissière voit
à l'écran et ce qu'elle tend au client ne peuvent donc pas diverger.

Les règles sont portées par `.ticket`, la racine du fragment : elles s'appliquent
telles quelles, que le fragment soit seul dans une page ou injecté dans l'écran de
caisse, sans wrapper ni classe supplémentaire.

> Les deux gabarits ont déjà eu **chacun sa copie** des mêmes règles, et elles
> avaient divergé : la caisse avait oublié `.ticket` lui-même, le reçu s'étirait
> donc à la largeur du panneau au lieu de celle du papier.
> `CaisseTest::testLeRecuEtLeTicketPartagentLeMemeFormat` interdit le retour en
> arrière — il échoue si les règles sont recopiées au lieu d'être incluses.

**L'habillage « papier » est réservé à l'écran** : la bande de 58 mm dentelée en haut
et en bas (masque CSS) et son ombre portée (`filter: drop-shadow`, qui suit la
découpe là où un `box-shadow` ne saurait pas) vivent dans `caisse/index.html.twig`.
Le fichier partagé, lui, reste strictement noir et blanc — il part sur une
imprimante thermique. Attention aussi aux **commentaires CSS** de ces gabarits :
`FuiteDonneesCaisseTest` interdit tout terme de gestion dans une réponse de caisse,
et le mot « marge » dans un commentaire suffit à faire échouer le test.

### Matériel de caisse (afficheur client, tête thermique, tiroir)

Un **agent local en Node** tourne sur le terminal de caisse et expose
`http://127.0.0.1:9100` : `POST /display` (afficheur client), `POST /print`
(ticket 58 mm), `POST /drawer` (tiroir), `GET /status`. Il ne fait pas partie de
ce dépôt — l'application ne fait que lui parler.

**Le matériel est un agrément, jamais une dépendance.** La très grande majorité
des postes n'a pas d'agent : tablette du comptoir, poste de secours, navigateur
de la dirigeante. Sur chacun d'eux la caisse doit se comporter **exactement**
comme avant. D'où la règle qui commande tout le reste :

> `assets/js/pos-agent.js` — **aucune méthode ne rejette jamais.** Toutes
> renvoient un booléen. L'appelant n'a donc ni `try/catch` ni `.catch()` à
> écrire, et un `pos.display()` peut partir sans `await` au milieu d'une méthode
> synchrone sans risquer de rejet non capturé. Sur un poste sans agent, l'appel
> ne fait rien du tout.

- **Délai court (1,2 s ; 6 s à l'impression).** L'agent est sur la boucle
  locale : il répond en quelques millisecondes ou pas du tout. Attendre
  davantage ferait traîner l'afficheur derrière la frappe de la caissière,
  au bénéfice de personne.
- **La présence de l'agent est mémorisée.** Elle décide du chemin d'impression à
  chaque vente ; sonder la boucle locale vingt fois par heure pour la même
  réponse n'apprendrait rien. `pos.verifier()` force une nouvelle sonde — c'est
  ce que fait la pastille « Matériel » quand on la touche.

**Afficheur client** — piloté depuis le seul `ticket_controller.majAfficheur()` :
deux appels partis d'endroits différents se contrediraient, et le dernier arrivé
gagnerait. Ticket vide → `clear` (et non « 0 FCFA », qui se lit comme un article
gratuit) ; ticket en cours → sous-total en `price` ; règlement choisi → même
montant en `total`. Après encaissement, la monnaie passe en `change` **15 s**,
puis retour au repos — assez pour compter les billets devant le client, trop peu
pour que le suivant lise ce qu'on a rendu à son voisin. Toute activité sur le
ticket annule ce compte à rebours, sinon il effacerait un sous-total bien vivant.

**Impression — l'un ou l'autre, jamais les deux.** `imprimerMateriel()` tente
l'agent ; s'il est absent ou refuse, on retombe sur l'iframe `window.print()`
d'origine. Laisser partir les deux sortirait **deux tickets par vente**, l'un par
la tête thermique, l'autre par l'imprimante par défaut de Windows.

**Le ticket est composé côté serveur**, par `App\Service\TicketMateriel`, à
partir du même `TicketData` que la page 58 mm et la sortie ESC/POS : ce que
l'agent imprime ne peut pas diverger de ce que la caissière a sous les yeux.

- ⚠ **Les montants en sortent en FCFA entiers**, alors qu'ils circulent en
  centimes partout ailleurs. L'agent imprime ce qu'on lui donne sans
  l'interpréter : c'est une conversion de présentation, elle n'a lieu qu'ici
  (`intdiv`, jamais de flottant). Côté JS, `ticket_controller.afficher()`
  tronque de la même façon — un arrondi divergent ferait dire deux nombres
  différents à l'afficheur et au papier.
- La **remise voyage en ligne négative**, faute de champ dédié dans le format de
  l'agent : sans elle, la somme des lignes ne tomberait pas sur le total et le
  client aurait un ticket qui ne s'additionne pas.
- Le **détail des règlements descend au pied**, le format de l'agent n'offrant
  qu'un seul champ `paid` : sur un paiement mixte, il dirait « 5 000 » sans
  jamais dire d'où ils viennent.

**Le tiroir s'ouvre à l'appui sur « Encaisser », pas à l'impression.**
`ticket_controller.encaisser()` appelle `pos.drawer()` (`POST /drawer`) dès que la
vente est durablement en file, **sans `await`** : la caissière va prendre l'argent,
le tiroir doit être sorti quand sa main y arrive, et un poste sans agent — le cas
courant — ne doit pas payer une milliseconde d'attente pour ça. L'appel ne rejette
jamais, il n'y a donc rien à rattraper.

- **Espèces uniquement** (`this.especes`, donc `ESPECES` en règlement retenu). Sur
  un Wave ou un MTN personne ne touche au tiroir, et l'ouvrir le laisserait béant
  devant la file — même règle que l'`openDrawer` du serveur, appliquée à l'écran.
  Le test se fait **avant** la remise à zéro du ticket, sans quoi `reglement` serait
  déjà nul et le tiroir ne s'ouvrirait plus jamais.
- **Le ticket ne le rouvre pas** : l'impression qui suit passe `tiroir = false`, une
  seconde impulsion sur un tiroir déjà sorti n'ajoutant rien.
- **L'ouverture ne dépend plus de l'impression.** Elle en dépendait tant qu'elle
  voyageait dans la seule charge utile `/print` : la case « Imprimer le ticket »
  décochée, le tiroir restait fermé sur une vente en espèces. `openDrawer` reste
  calculé par le serveur et sert toujours la réimpression (forcé à faux).
- **`/drawer` part sans en-tête ni corps** (`pos-agent.impulsion()`), et non par
  `appeler()` comme `/display` et `/print`. Un `Content-Type: application/json`
  n'est pas sur la liste sûre du CORS : le navigateur ferait précéder le POST d'un
  **préflight OPTIONS**, auquel un agent qui n'expose que `POST /drawer` ne répond
  pas — le `fetch` échouerait avant d'avoir rien envoyé. Le symptôme est
  déroutant : `Invoke-RestMethod -Method Post -Uri http://127.0.0.1:9100/drawer`
  ouvre le tiroir depuis un terminal (pas de CORS dans un shell), et le même appel
  depuis la caisse ne fait rien. Un second essai en **`no-cors`** suit, pour l'agent
  qui n'envoie pas `Access-Control-Allow-Origin` : la requête part, la réponse est
  opaque — sur un tiroir, c'est le geste qui compte, pas l'accusé de réception.
  Les deux essais échouent si rien n'écoute sur 9100, et la caisse continue comme
  si de rien n'était.

**Réimpression** — `GET /caisse/ticket/{uuid}/materiel`, la même charge utile,
`openDrawer` **forcé à faux**. Une réimpression ne fait pas entrer d'argent : le
tiroir s'est ouvert quand le client a payé, et un bouton qui le rouvre depuis un
écran de gestion serait un moyen commode de l'ouvrir sans vente. La route passe
par le contrôle d'accès du ticket papier (`VENTE_VOIR`) : un caissier ne
réimprime pas la vente d'un collègue. Points d'appel : le panneau « Reçu » de
`/caisse` et le bouton « Réimprimer » de `/admin/ventes`.

> **Pourquoi une route GET alors que `POST /api/vente` renvoie déjà `ticket` ?**
> Parce que l'écran de caisse ne voit jamais cette réponse : l'encaissement passe
> par la file de synchronisation hors ligne, qui **jette le corps** dès que le
> serveur a confirmé (`FileSynchronisation.transmettre()`). Plutôt que de toucher
> à ce code — celui qui porte « aucune vente perdue, aucune vente dupliquée » —
> le ticket est redemandé. La clé `ticket` de la réponse d'encaissement reste
> exposée pour tout autre consommateur, et sort du **même service**.

**Indicateur « Matériel »** (`materiel_controller.js`), à côté de la pastille de
synchronisation et sur le même gabarit. L'absence d'agent est signalée en
**stone, pas en rouge ni en ambre** : ce n'est pas une anomalie mais la
configuration ordinaire de la plupart des postes, et une alerte permanente sur un
état normal finit par ne plus rien vouloir dire — elle disputerait l'attention au
bandeau voisin, qui lui signale de vraies ventes en attente.

> ⚠ **Contexte sécurisé.** Servie en HTTPS, la page ne peut pas appeler
> `http://127.0.0.1:9100` (contenu mixte, bloqué par le navigateur) : l'agent
> devient invisible et la caisse retombe silencieusement sur `window.print()`.
> À traiter avec le certificat évoqué dans « Limites connues » — l'agent devra
> servir en HTTPS ou être exposé derrière le même hôte.

Couverture : `TicketTest` (FCFA entiers, lignes, en-tête, tiroir fermé en
réimpression, ticket d'un collègue refusé), `FuiteDonneesCaisseTest` (liste
blanche des clés de `ticket`), `TurboNavigationTest` (les appels à l'afficheur
restent synchrones).

### Démonstration client (`app:demo:reset` + `DEMO.md`)

```bash
php bin/console app:demo:reset            # DESTRUCTIF : vide la base et la reconstruit
php bin/console app:demo:reset --force    # sans confirmation (scripts)
php bin/console app:demo:reset --garder-utilisateurs   # garnit sans toucher aux comptes
```

Prépare l'état de départ d'une démonstration : recharge `AppFixtures` (30 jours
d'historique, tirage déterministe), **réapprovisionne le stock** au-dessus des seuils
— 30 jours de ventes rejouées sans appro le laissent négatif — puis injecte
**deux anomalies volontaires**, visibles sur `/pilotage` :

1. un **ticket encaissé puis annulé** par le gérant ;
2. un **écart de caisse de −2 500 FCFA** sur la session clôturée du second caissier.

Les deux anomalies passent par le **vrai chemin métier** (`EncaissementService`,
`SessionCaisseService`) : elles produisent donc aussi la trace d'audit et la
notification à la dirigeante, au lieu d'être fabriquées en base. La caisse de Fatou
Traoré reste **ouverte** avec les ventes de la matinée (journée en cours) ; celle de
Yao Kouassi est clôturée.

**`--garder-utilisateurs`** épargne la table `utilisateur` (via
`--purge-exclusions` du bundle de fixtures) : c'est ce qu'il faut pour **garnir une
base déjà installée** sans perdre les accès créés à l'écran d'installation.

- `AppFixtures::creerUtilisateurs()` détecte alors les comptes en place et les
  **réutilise**. Sans cela, créer « koffi.nguessan@zedpos.ci » par-dessus un compte
  réel échouerait sur l'unicité de l'e-mail — et surtout écraserait des accès réels
  par des comptes de démonstration.
- L'historique est attribué aux **caissiers existants**. S'il n'y en a aucun (base
  où seule la dirigeante s'est inscrite), un compte de caisse est créé : trente
  jours de ventes ont besoin de quelqu'un derrière le comptoir.
- Les caissiers sont désormais résolus **par rôle** et non par adresse e-mail
  codée en dur — celle-ci ne valait que sur une base de démonstration fraîche.
- **Avec un seul caissier, l'écart de caisse n'est pas injecté** : clôturer sa
  caisse fermerait la journée en cours et effacerait le ticket annulé de l'écran.
  La commande le dit.
- Le récapitulatif liste alors les comptes **réels**, sans annoncer de mot de
  passe : ce sont ceux de l'exploitant, la commande ne les connaît pas.

La commande refuse de tourner en `prod` (sauf `--force`), demande confirmation, et
élève elle-même `memory_limit` à 1 Go. Les avertissements « Stock négatif » pendant
le chargement sont attendus : le stock est remis à niveau juste après.

**`DEMO.md`** décrit le déroulé en 10 minutes : vendre en boulangerie, vendre en
fast-food, couper le réseau et continuer à vendre, reconnecter, clôturer la caisse,
puis constater les deux anomalies depuis le téléphone de la dirigeante. Il inclut
les comptes, les questions fréquentes et la procédure de remise à zéro entre deux
démonstrations (ne pas oublier de vider IndexedDB côté navigateur).

`DemoResetTest` verrouille tout ce que `DEMO.md` promet — si l'état de démonstration
dérive, le test échoue.

### Données de démonstration (fixtures)

```bash
# Charge 7 familles, 43 articles, 23 matières, 14 fiches, 5 comptes
# et ~30 jours de ventes historiques réalistes (pics 5-9h / 18-21h), avec une
# session de caisse par caissier et par jour : dépenses, clôture Z et écarts
# justifiés pour les jours passés, sessions ouvertes pour aujourd'hui.
php -d memory_limit=1G bin/console doctrine:fixtures:load --no-interaction --no-debug
```

Comptes créés (mots de passe de démo, à ne pas utiliser en production) :

| Rôle | Identifiant | Secret |
|------|-------------|--------|
| Dirigeante | `aya.kone@zedpos.ci` | mot de passe `dirigeante123` |
| Gérant | `koffi.nguessan@zedpos.ci` | mot de passe `gerant123` |
| Comptable | `cabinet@zedpos.ci` | mot de passe `comptable123` |
| Caissier | `fatou.traore@zedpos.ci` | code PIN `1234` |
| Caissier | `yao.kouassi@zedpos.ci` | code PIN `5678` |

---

## État du projet

Dernière mise à jour : 30 juillet 2026.

Il n'existe pas de document de cahier des charges dans le dépôt. La référence est
la section **« Contexte métier »** en tête de ce fichier ; l'inventaire ci-dessous
compare l'implémentation à cette description et signale les écarts.

### Ce qui est livré

| Module | État | Points d'entrée |
|---|---|---|
| Authentification 2 voies (mot de passe / PIN) + redirection par rôle | ✅ | `/login`, `/caisse/login` |
| Limitation des tentatives de connexion (mot de passe et PIN) | ✅ | `login_throttling`, limiteur `connexion_caisse` |
| Habilitations fines par Voters | ✅ | `App\Security\Permission` |
| Back-office : familles, articles, matières, fournisseurs, fiches techniques | ✅ | `/admin` |
| Coût de revient, marge, seuil d'alerte | ✅ | `/admin/articles`, `/admin/production` |
| Caisse tactile, mode boulangerie | ✅ | `/caisse` |
| Caisse tactile, mode fast-food | ⚠️ partiel | variantes + commentaire seulement, voir écart n° 1 |
| Encaissement idempotent, paiement mixte, remise plafonnée, rendu | ✅ | `POST /api/vente` |
| Annulation du dernier ticket par le caissier (motif, audit, notification) | ✅ | panneau « Reçu » de `/caisse` |
| Cycle de caisse : ouverture, dépenses, ticket X, clôture Z, écart | ✅ | `/caisse/session/*` |
| Journée clôturée non modifiable | ✅ | `SessionCaisse::garantirOuverte()` |
| Déstockage automatique par fiche technique | ✅ | `DestockageVenteListener` |
| Pertes valorisées + synthèse mensuelle | ✅ | `/admin/pertes` |
| Ticket 58 mm + génération ESC/POS | ⚠️ partiel | voir écart n° 3 |
| Matériel de caisse : afficheur client, tête thermique, tiroir | ✅ | `assets/js/pos-agent.js`, `App\Service\TicketMateriel` |
| Caisse hors ligne (Service Worker, IndexedDB, file de synchronisation) | ✅ | `public/sw.js`, `assets/offline/` |
| Espace de pilotage responsive, ventes par caissière, courbe 30 jours | ✅ | `/pilotage` |
| Téléchargement des rapports du jour (texte, CSV) | ✅ | `/pilotage/rapport.txt`, `.csv` |
| Journal d'audit inaltérable + consultation | ✅ | `/pilotage/audit` |
| Gestion des comptes (création, modification, activation) | ✅ | `/admin/utilisateurs` |
| Amorçage du premier compte sur base vierge | ✅ | `/installation` |
| Notification de la dirigeante sur annulation | ✅ | `NotificateurDirigeante` |
| Rapport quotidien texte (WhatsApp / e-mail) | ✅ | `app:rapport-quotidien` |
| Jeu de démonstration reproductible | ✅ | `app:demo:reset`, `DEMO.md` |
| Exports comptables SYSCOHADA (écritures, FEC, balance) | ✅ | `/comptabilite`, `app:export-comptable` |
| Espace comptable : journal des ventes détaillé | ⚠️ partiel | voir écart n° 2 |
| Inventaire (feuille, comptage, validation) | ✅ | `/admin/inventaires` |
| Paramètres de l'établissement, logo compris | ✅ | `/admin/parametres` |
| Import du catalogue en masse (nom, prix) | ✅ | `/admin/articles/importer` |

Tests : **477 tests PHPUnit** (`php bin/phpunit`) et **37 tests Node**
(`node --test "tests/js/*.test.js"`).

### Écarts par rapport au contexte métier — à traiter

Classés par importance.

1. **Mode « fast-food commande » incomplet — écart principal.**
   Le contexte métier décrit une **commande** ouverte, rattachée à une table ou un
   numéro, passant par des **états** (en préparation → prête → servie/livrée) avant
   d'être encaissée. Rien de cela n'existe : il n'y a **pas d'entité `Commande`**,
   le mode FASTFOOD se limite à un panneau de variantes et de commentaire, et la
   vente est encaissée immédiatement comme en boulangerie. À prévoir : entité
   `Commande` + états, rattachement table/numéro, écran de suivi cuisine, et
   transformation de la commande en `Vente` à l'encaissement.

2. **Espace comptable réduit aux exports.**
   `/comptabilite` produit les écritures SYSCOHADA, le FEC et la balance d'une
   période, avec ses contrôles. Il manque encore la **consultation** : le comptable
   ne peut pas parcourir les tickets ni le détail d'une vente depuis son espace,
   alors que `VenteVoter` le lui accorde. À prévoir : journal des ventes détaillé
   (l'écran `/pilotage/ventes` existant est réservé à la dirigeante) et un état de
   TVA par taux — la ventilation actuelle se fait par compte de produits, pas par
   taux d'imposition.

3. **Sortie ESC/POS toujours sans consommateur.**
   L'impression thermique est **branchée** depuis l'intégration de l'agent
   matériel local (voir « Matériel de caisse » plus haut) — mais par la route
   `/print` de l'agent, qui reçoit un ticket **structuré en JSON** et le compose
   lui-même. `ImpressionService` et son `GET /caisse/ticket/{uuid}/escpos`
   restent donc **sans appelant** : ils produisent une trame ESC/POS que personne
   ne lit. Deux issues, à trancher : soit l'agent apprend à consommer la trame
   base64 (et l'application reprend la main sur la mise en page au caractère
   près), soit `ImpressionService` est retiré. En attendant, deux mises en page
   thermiques coexistent et **doivent être modifiées ensemble**.
   Corollaire inchangé : le **logo ne sort ni en ESC/POS ni sur `/print`**, qui
   n'envoient que du texte. Il faudrait une trame raster (`GS v 0`).

4. **Facture normalisée (RNE / DGI) non implémentée.**
   Le ticket porte désormais un code-barres, mais il encode le **numéro interne**
   de la vente : c'est un outil de recherche, pas une signature fiscale. Il ne
   satisfait donc en rien l'obligation de facture normalisée, et l'emplacement
   qui rappelait cet écart sur le papier a disparu avec lui — d'où ce rappel ici.
   À cadrer avec les obligations ivoiriennes avant mise en production : nature du
   code (QR signé), données à y porter, et cohabitation avec le code-barres actuel.

5. **Règlement à crédit non géré.**
   `ModeReglement::CREDIT` existe dans l'énumération mais il n'y a ni compte client,
   ni encours, ni relance. Ne pas proposer ce mode en caisse tant que c'est le cas.

6. **Entrées de stock non outillées.**
   Le CRUD fournisseurs existe, mais il n'y a **ni bon de commande ni réception**.
   Depuis que `stockActuel` n'est plus modifiable à la main, une livraison ne peut
   être enregistrée **que par un inventaire** — ce qui est un détournement : on
   n'a rien compté, on a reçu. Le coût moyen pondéré n'est de toute façon jamais
   recalculé automatiquement, faute de prix d'achat saisi à la réception.

7. **Tickets « en attente » non durables.**
   La mise en attente vit uniquement en mémoire du contrôleur Stimulus : un
   rechargement de page les perd. Les ventes encaissées, elles, sont bien en
   IndexedDB. À aligner sur le même stockage.

8. **`Article.suiviStock` non éditable depuis le back-office.**
   `ArticleType` n'expose ni `suiviStock`, ni `stockActuel`, ni `stockMini` : un
   article revendu tel quel (boisson) ne peut donc être mis sous suivi de stock que
   par les fixtures ou en SQL. Champs à ajouter au formulaire.

### Limites connues, à garder en tête

- **Le Service Worker exige `localhost` ou HTTPS.** Sur une tablette accédant au
  serveur par `http://192.168.x.x`, la file de synchronisation fonctionne mais la
  page n'est pas mise en cache : l'écran de caisse ne se rechargera pas hors ligne.
  Prévoir un certificat avant déploiement en boutique.
- **L'inaltérabilité du journal d'audit est applicative**, pas garantie par MariaDB :
  un `UPDATE` SQL direct passerait.
- **La notification à la dirigeante est en base uniquement** — pas d'e-mail ni de SMS.
- **Le rendu visuel du mode hors ligne et de la courbe Chart.js n'a jamais été
  vérifié dans un vrai navigateur** (extension indisponible pendant le
  développement). La résolution des modules est vérifiée statiquement et les tests
  couvrent la logique, mais un passage manuel sur `/caisse` et `/pilotage` reste à
  faire avant la première démonstration client.
- Le stock peut devenir négatif : c'est **volontaire** (ne jamais bloquer une vente),
  mais journalisé en `warning`.

### Documentation

| Fichier | Public |
|---|---|
| `README.md` | Installation, commandes, architecture |
| `docs/GUIDE-CAISSIER.md` | La caissière — une page, à imprimer |
| `docs/GUIDE-GERANT.md` | Le gérant — stock, pertes, rapports |
| `docs/GUIDE-COMPTABLE.md` | Le comptable — exports SYSCOHADA, contrôles, plan de comptes |
| `docs/DEPLOIEMENT.md` | Mise en production sur un VPS — prérequis, `.env.local`, HTTPS, sauvegardes |
| `DEMO.md` | Démonstration client en 10 minutes |
