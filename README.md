# ZedPOS

Logiciel de **caisse** et de **gestion de stock** pour une boulangerie + fast-food
à **Abengourou (Côte d'Ivoire)**.

Deux métiers, une seule caisse : vente rapide au comptoir le matin (pain,
viennoiseries) et plats préparés à la demande le soir. La caisse continue de
fonctionner **sans Internet**, et la dirigeante suit l'activité depuis son
téléphone à Abidjan.

- **Devise** : Franc CFA (FCFA / XOF) — tous les montants sont stockés en centimes.
- **Langue** : français (interface, code métier, base de données).
- **Fuseau** : `Africa/Abidjan`.

---

## Prérequis

| Outil | Version | Remarque |
|---|---|---|
| PHP | 8.2+ | extensions `pdo_mysql`, `intl`, `mbstring` |
| Composer | 2.x | |
| MariaDB | 10.4+ | fournie par XAMPP en développement |
| Node.js | 18+ | **uniquement** pour lancer les tests JavaScript |
| Symfony CLI | facultatif | pratique pour `symfony serve` |

Aucun bundler JavaScript : les assets passent par **AssetMapper**, il n'y a ni
webpack ni build front à lancer.

## Installation locale

```bash
git clone <dépôt> zedpos && cd zedpos
composer install

# 1. Base de données — copiez .env vers .env.local et ajustez DATABASE_URL
cp .env .env.local
#    DATABASE_URL="mysql://root:@127.0.0.1:3306/zedpos?serverVersion=10.4.32-MariaDB&charset=utf8mb4"

php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

# 2. Assets
php bin/console tailwind:build
php bin/console asset-map:compile

# 3. Jeu de données de démonstration (comptes inclus)
php bin/console app:demo:reset

# 4. Serveur
symfony serve            # ou : php -S localhost:8000 -t public/
```

Ouvrez <http://localhost:8000>. Les comptes créés sont affichés à la fin de
`app:demo:reset` (voir aussi [DEMO.md](DEMO.md)).

> **Mode hors ligne** : le Service Worker n'est actif qu'en `localhost` ou en
> HTTPS. En accédant au serveur par une IP locale en HTTP, la file de
> synchronisation fonctionne toujours mais la page n'est pas mise en cache.

## Commandes utiles

```bash
php bin/phpunit                          # tests (commande de référence)
node --test "tests/js/*.test.js"         # tests JS (file de synchronisation)

php bin/console app:demo:reset           # remet la base en état de démonstration
php bin/console app:rapport-quotidien    # synthèse du jour, prête pour WhatsApp
php bin/console app:creer-utilisateur    # crée un compte

php bin/console tailwind:build --watch   # CSS en continu pendant le développement
php bin/console make:migration           # après tout changement d'entité
php bin/console doctrine:migrations:migrate
```

## Architecture en 20 lignes

1. **Symfony 7.4 / PHP 8.2 / MariaDB / Twig / Doctrine ORM**, sans API séparée.
2. **AssetMapper** sert les modules ES natifs ; **Stimulus** pilote le front, **Tailwind** la mise en forme.
3. Le domaine est **en français** : `Vente`, `SessionCaisse`, `Perte`, `MouvementStock`.
4. **Tout montant est un `int` en centimes de FCFA** ; aucun `float` ne touche l'argent.
5. Les quantités sont des entiers en **millièmes d'unité**.
6. Trois espaces cloisonnés : `/caisse` (caissier), `/admin` (gérant), `/pilotage` (dirigeante).
7. `Vente` est l'entité comptable : **jamais supprimée**, seulement annulée avec motif.
8. `SessionCaisse` porte le cycle de caisse — ouverture, dépenses, ticket X, clôture Z.
9. Une session **clôturée est figée** : `garantirOuverte()` refuse toute écriture ultérieure.
10. `POST /api/vente` est le **seul** point d'écriture d'une vente, **idempotent** sur un uuid client.
11. La caisse écrit chaque vente en **IndexedDB avant tout appel réseau** ; un Service Worker cache l'application.
12. Une file de synchronisation vide l'attente au retour du réseau, avec relance exponentielle.
13. Les prix sont **toujours recalculés côté serveur** : le client n'est jamais cru.
14. `EventListener\DestockageVenteListener` décrémente le stock à chaque vente via les fiches techniques.
15. Les habilitations fines passent par des **Security Voters** (`App\Security\Permission`), pas par des tests de rôle.
16. `AuditLogger` écrit un journal **inaltérable** (ni UPDATE ni DELETE, garanti au niveau ORM).
17. Les services de calcul (`RapportCaisseService`, `SyntheseJourneeService`) sont partagés entre écrans et commandes.
18. Un middleware DBAL corrige l'introspection de MariaDB 10.4 (`src/Doctrine/DBAL/`) — ne pas le retirer.
19. Les tests sont **fonctionnels** et tapent une vraie base MariaDB (suffixe `_test`).
20. `CLAUDE.md` fait référence pour les conventions ; ce README ne fait que résumer.

## Documentation

| Document | Pour qui |
|---|---|
| [docs/GUIDE-CAISSIER.md](docs/GUIDE-CAISSIER.md) | La caissière, à imprimer et afficher près de la caisse |
| [docs/GUIDE-GERANT.md](docs/GUIDE-GERANT.md) | Le gérant : stock, pertes, rapports |
| [DEMO.md](DEMO.md) | Démonstration client en 10 minutes |
| [CLAUDE.md](CLAUDE.md) | Conventions techniques, état du projet, reste à faire |

## Licence

Projet privé — tous droits réservés.
