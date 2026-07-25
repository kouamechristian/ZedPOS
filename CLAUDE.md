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
- Chaque connexion / déconnexion / échec est tracé dans `JournalAudit`
  (`App\EventSubscriber\AuditConnexionSubscriber`).
- Créer un compte : `php bin/console app:creer-utilisateur <email> <nom>
  --role=GERANT --mot-de-passe=…` (ou `--role=CAISSIER --code-pin=1234`).

---

## Commandes utiles

```bash
# Lancer les tests (commande de test de référence)
php bin/phpunit

# Serveur de développement (ou `symfony serve` si la CLI Symfony est installée)
php -S localhost:8000 -t public/

# Compiler les assets Tailwind
php bin/console tailwind:build          # une fois
php bin/console tailwind:build --watch  # en continu pendant le développement

# Base de données
php bin/console doctrine:database:create
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

**Commande de test : `php bin/phpunit`**

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
  (`POST /caisse/encaisser`, JSON) qui **recalcule tous les prix côté serveur**
  (jamais de confiance au client), crée la Vente/lignes/règlement dans la session
  de caisse ouverte (créée à la volée), et renvoie le numéro de ticket.
- « Mise en attente » : tickets mémorisés côté client, repris en un appui.

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

### Données de démonstration (fixtures)

```bash
# Charge 7 familles, 43 articles, 23 matières, 14 fiches, 4 comptes
# et ~30 jours de ventes historiques réalistes (pics 5-9h / 18-21h).
php -d memory_limit=1G bin/console doctrine:fixtures:load --no-interaction --no-debug
```

Comptes créés (mots de passe de démo, à ne pas utiliser en production) :

| Rôle | Identifiant | Secret |
|------|-------------|--------|
| Dirigeante | `aya.kone@zedpos.ci` | mot de passe `dirigeante123` |
| Gérant | `koffi.nguessan@zedpos.ci` | mot de passe `gerant123` |
| Caissier | `fatou.traore@zedpos.ci` | code PIN `1234` |
| Caissier | `yao.kouassi@zedpos.ci` | code PIN `5678` |
