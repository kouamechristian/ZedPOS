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
