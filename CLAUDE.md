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
- Créer un compte : `php bin/console app:creer-utilisateur <email> <nom>
  --role=GERANT --mot-de-passe=…` (ou `--role=CAISSIER --code-pin=1234`).

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
| Annuler une vente encaissée  | non      | **oui**| oui        | non       |

(L) = lecture seule. Le comptable ne reçoit **aucune** permission d'écriture.

- **`ArticleVoter`** — `ARTICLE_VOIR_COUT` (jamais un caissier),
  `ARTICLE_MODIFIER_PRIX` (**dirigeante seule**), `ARTICLE_MODIFIER`. Le sujet peut
  être `null` pour la question générale (masquer une colonne entière).
- **`VenteVoter`** — `VENTE_VOIR` : un caissier n'accède qu'aux ventes de **ses**
  sessions de caisse, y compris via `/caisse/ticket/{uuid}` et sa sortie ESC/POS.
  `VENTE_ANNULER` : gérant et au-dessus.
- **`DonneesGlobalesVoter`** — `VOIR_CA_GLOBAL`, `VOIR_TOUTES_VENTES` (sujet `null`).

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
php -S localhost:8000 -t public/

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

### Back-office gérant (`/admin`)

- Réservé à `ROLE_GERANT` (donc aussi la dirigeante par hiérarchie). Layout Twig +
  Tailwind avec navigation latérale, contrôleurs sous `src/Controller/Admin/`.
- CRUD : Familles, Articles (filtre famille + recherche + activation), Matières
  premières, Fournisseurs. Onglet « Fiche technique » sur l'article (ajout/retrait
  de matières premières) via **Turbo Frame** — aucune dépendance JS lourde.
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

### Interface de caisse tactile (`/caisse`)

- Plein écran, sans navigation, réservée à `ROLE_CAISSIER`. 3 colonnes : familles +
  grille de touches produits (≥ 92 px) à gauche, ticket au centre (total très gros),
  pavé de règlement à droite (Espèces, Wave, Orange Money, MTN MoMo, Moov Money).
- Deux modes commutables : **BOULANGERIE** (un appui = +1 unité, aucune étape) et
  **FASTFOOD** (un appui ouvre un panneau variantes + commentaire libre avant ajout).
- Tout l'état du ticket vit **côté client** dans le contrôleur Stimulus
  `assets/controllers/caisse_controller.js` : aucun rechargement pendant la commande,
  ajout d'article instantané. Seul l'**encaissement** appelle le serveur
  (`POST /api/vente`, JSON, idempotent) qui **recalcule tous les prix côté serveur**
  (jamais de confiance au client), crée la Vente/lignes/règlement dans la session
  de caisse **ouverte** du caissier, et renvoie le numéro de ticket. La vente est
  d'abord écrite en IndexedDB — voir « Caisse hors ligne ».
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
- `POST /api/vente/{uuid}/annuler` (**ROLE_GERANT**) : motif obligatoire, **jamais de
  suppression** (statut → `ANNULEE`, motif conservé).
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
- **Ticket X** `/caisse/session/x` : synthèse **intermédiaire** imprimable 80 mm.
  **Ne clôture rien et n'écrit rien** — la session reste `OUVERTE`.
- **Clôture Z** `/caisse/session/cloture` : le caissier saisit **uniquement le montant
  physiquement compté**. Le serveur calcule le
  **théorique = fond + espèces encaissées − dépenses − sorties** puis
  l'**écart = compté − théorique**. **Commentaire obligatoire si l'écart ≠ 0**
  (règle portée par `SessionCaisse::cloturer()`, non contournable par le formulaire).
  Les espèces encaissées sont **nettes du rendu de monnaie**.
- **Rapport Z** `/caisse/session/z/{id}` (80 mm) et `/admin/clotures/{id}` (gérant) :
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

Réservé à `ROLE_DIRIGEANTE` et **pensé mobile-first** : elle le consulte depuis son
téléphone à Abidjan. Une seule colonne, en-tête collant, onglets défilables, aucun
tableau large — les listes sont des cartes empilées. **Lecture seule.**

- **Écran principal** `/pilotage` (`?jour=AAAA-MM-JJ` pour rejouer une journée) :
  CA du jour en très gros, comparé à **la veille** et au **même jour de la semaine
  précédente** (pastille verte/rouge, % signé) ; tickets et panier moyen ; ventilation
  par mode de règlement avec barres de proportion ; **points de vigilance**
  (annulations, remises, écart de caisse, ruptures de stock, pertes valorisées) —
  le bloc vire à l'ambre dès qu'il y a quelque chose à signaler ; **top 10** des
  produits ; **courbe du CA sur 30 jours** (Chart.js).
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
  `UTILISATEUR_CREE`, `UTILISATEUR_ACTIVE`, `UTILISATEUR_DESACTIVE`.
  Une clôture avec écart produit **deux** entrées (clôture + écart), pour filtrer
  les écarts seuls.
- **Points d'appel** : `EncaissementService` (annulation, remise > 0),
  `Admin\ArticleController::edit` (uniquement si le prix change réellement),
  `PerteService`, `SessionCaisseService::cloturer`, `CreerUtilisateurCommand`,
  `Admin\DashboardController::basculerUtilisateur`, `AuditConnexionSubscriber`.
  `AuditLogger::inventaireValide()` est prêt mais **non appelé** : il n'existe pas
  encore de module d'inventaire dans l'application.
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

- **Vue HTML 80 mm** imprimable via `window.print()` (CSS `@media print`, `@page size: 80mm`) :
  `GET /caisse/ticket/{uuid}` → `templates/ticket/ticket.html.twig`. En-tête (raison
  sociale, adresse Abengourou, NCC, n° ticket, date/heure, caissier), lignes, total,
  ventilation TVA, règlement(s), rendu, **pied paramétrable** et **emplacement réservé
  au futur QR code RNE/DGI**.
- Infos boutique **paramétrables via `.env`** (`TICKET_RAISON_SOCIALE`, `TICKET_ADRESSE`,
  `TICKET_NCC`, `TICKET_TELEPHONE`, `TICKET_PIED`) → service `App\Service\ParametresTicket`.
- `App\Service\TicketBuilder` construit un `TicketData` (indépendant du support) partagé
  par la vue HTML et l'ESC/POS ; `App\Service\ImpressionService` prépare la commande
  **ESC/POS** (texte ASCII + coupe papier + ouverture tiroir), exposée en base64 par
  `GET /caisse/ticket/{uuid}/escpos` pour un futur pont d'impression local.
- **Impression automatique** après encaissement via un iframe caché (`?auto=1`), avec
  la case **« Imprimer le ticket »** (décochable) dans l'écran de caisse.

### Démonstration client (`app:demo:reset` + `DEMO.md`)

```bash
php bin/console app:demo:reset            # DESTRUCTIF : vide la base et la reconstruit
php bin/console app:demo:reset --force    # sans confirmation (scripts)
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
# Charge 7 familles, 43 articles, 23 matières, 14 fiches, 4 comptes
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
| Caissier | `fatou.traore@zedpos.ci` | code PIN `1234` |
| Caissier | `yao.kouassi@zedpos.ci` | code PIN `5678` |

---

## État du projet

Dernière mise à jour : 25 juillet 2026.

Il n'existe pas de document de cahier des charges dans le dépôt. La référence est
la section **« Contexte métier »** en tête de ce fichier ; l'inventaire ci-dessous
compare l'implémentation à cette description et signale les écarts.

### Ce qui est livré

| Module | État | Points d'entrée |
|---|---|---|
| Authentification 2 voies (mot de passe / PIN) + redirection par rôle | ✅ | `/login`, `/caisse/login` |
| Habilitations fines par Voters | ✅ | `App\Security\Permission` |
| Back-office : familles, articles, matières, fournisseurs, fiches techniques | ✅ | `/admin` |
| Coût de revient, marge, seuil d'alerte | ✅ | `/admin/articles`, `/admin/production` |
| Caisse tactile, mode boulangerie | ✅ | `/caisse` |
| Caisse tactile, mode fast-food | ⚠️ partiel | variantes + commentaire seulement, voir écart n° 1 |
| Encaissement idempotent, paiement mixte, remise plafonnée, rendu | ✅ | `POST /api/vente` |
| Cycle de caisse : ouverture, dépenses, ticket X, clôture Z, écart | ✅ | `/caisse/session/*` |
| Journée clôturée non modifiable | ✅ | `SessionCaisse::garantirOuverte()` |
| Déstockage automatique par fiche technique | ✅ | `DestockageVenteListener` |
| Pertes valorisées + synthèse mensuelle | ✅ | `/admin/pertes` |
| Ticket 80 mm + génération ESC/POS | ⚠️ partiel | voir écart n° 4 |
| Caisse hors ligne (Service Worker, IndexedDB, file de synchronisation) | ✅ | `public/sw.js`, `assets/offline/` |
| Espace de pilotage mobile + courbe 30 jours | ✅ | `/pilotage` |
| Journal d'audit inaltérable + consultation | ✅ | `/pilotage/audit` |
| Notification de la dirigeante sur annulation | ✅ | `NotificateurDirigeante` |
| Rapport quotidien texte (WhatsApp / e-mail) | ✅ | `app:rapport-quotidien` |
| Jeu de démonstration reproductible | ✅ | `app:demo:reset`, `DEMO.md` |
| Espace comptable | ❌ | voir écart n° 3 |
| Inventaire | ❌ | voir écart n° 2 |

Tests : **170 tests PHPUnit** (`php bin/phpunit`) et **9 tests Node**
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

2. **Aucun module d'inventaire.**
   La correction d'un stock se fait en modifiant le champ `stockActuel` sur
   `/admin/stock/{id}/modifier`. Cette écriture **ne crée aucun `MouvementStock` et
   n'est pas auditée** : l'historique des mouvements diverge alors du stock affiché.
   `AuditLogger::inventaireValide()` est écrit et testé mais **n'est appelé nulle
   part**, en attente de ce module. À prévoir : feuille de comptage, validation,
   mouvement `INVENTAIRE` et trace d'audit.

3. **Espace comptable vide.**
   `/comptabilite` est une page d'atterrissage sans contenu. Les Voters accordent
   déjà au comptable la lecture des ventes, du CA et des coûts, mais aucun écran ne
   l'expose. À prévoir : journal des ventes exportable, ventilation TVA, export
   comptable.

4. **Impression thermique non branchée.**
   `ImpressionService` produit bien une commande ESC/POS, exposée en base64 par
   `GET /caisse/ticket/{uuid}/escpos`, mais **aucun pont d'impression local ne la
   consomme** : l'impression réelle passe aujourd'hui par `window.print()` du
   navigateur. À prévoir : agent local ou impression réseau directe.

5. **Facture normalisée (RNE / DGI) non implémentée.**
   Le ticket réserve un emplacement pour le QR code, sans aucune génération. À
   cadrer avec les obligations ivoiriennes avant mise en production.

6. **Règlement à crédit non géré.**
   `ModeReglement::CREDIT` existe dans l'énumération mais il n'y a ni compte client,
   ni encours, ni relance. Ne pas proposer ce mode en caisse tant que c'est le cas.

7. **Entrées de stock non outillées.**
   Le CRUD fournisseurs existe, mais il n'y a **ni bon de commande ni réception** :
   une livraison ne peut être saisie que par modification directe du stock (voir
   écart n° 2). Le coût moyen pondéré n'est donc jamais recalculé automatiquement.

8. **Tickets « en attente » non durables.**
   La mise en attente vit uniquement en mémoire du contrôleur Stimulus : un
   rechargement de page les perd. Les ventes encaissées, elles, sont bien en
   IndexedDB. À aligner sur le même stockage.

9. **`Article.suiviStock` non éditable depuis le back-office.**
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
| `DEMO.md` | Démonstration client en 10 minutes |
