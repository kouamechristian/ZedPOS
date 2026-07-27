# Déploiement sur un serveur (VPS)

Mise en production de ZedPOS depuis le dépôt GitHub. Le déploiement se fait par
`git pull` : le serveur est une copie du dépôt, jamais un envoi FTP de fichiers
modifiés à la main — sinon plus rien ne dit ce qui tourne réellement.

> **Aucun secret dans le dépôt.** Le mot de passe de la base et `APP_SECRET`
> vivent dans `.env.local`, créé **sur le serveur** et jamais commité
> (`.gitignore` l'exclut). Un dépôt privé n'est pas un coffre : il se clone, se
> partage et se retrouve dans les sauvegardes de chaque poste.

---

## 1. Prérequis du serveur

| Composant | Version | Vérification |
|---|---|---|
| PHP | **8.2 minimum** (CLI *et* module web, la même) | `php -v` |
| Extensions PHP | `ctype`, `iconv`, `pdo_mysql`, `gd`, `intl`, `mbstring`, `zip` | `php -m` |
| MariaDB / MySQL | MariaDB 10.4+ ou MySQL 8 | `mysql --version` |
| Composer | 2.x | `composer -V` |
| Serveur web | Apache (`mod_rewrite`) ou Nginx | |

`gd` n'est pas optionnel : c'est lui qui réduit les photos des touches produits
(`ImageArticle`). Sans lui, le téléversement d'une image échoue.

```bash
# Debian / Ubuntu, si une extension manque
sudo apt install php8.2-{mysql,gd,intl,mbstring,zip,xml,curl}
sudo systemctl restart apache2   # ou php8.2-fpm
```

## 2. Récupérer le code

```bash
cd /var/www
git clone https://github.com/kouamechristian/ZedPOS.git zedpos
cd zedpos
git checkout main          # ou la branche à déployer
```

## 3. Dépendances PHP

```bash
composer install --no-dev --optimize-autoloader
```

`--no-dev` écarte PHPUnit, le profileur et la barre de débogage : ils n'ont rien
à faire en production, et le profileur enregistrerait chaque requête sur disque.

## 4. Configuration — `.env.local`

À créer **sur le serveur**, à la racine du projet :

```bash
nano /var/www/zedpos/.env.local
```

```dotenv
APP_ENV=prod
APP_DEBUG=0
# Chaîne aléatoire de 32 caractères hexadécimaux, propre à ce serveur.
# Générer avec : php -r "echo bin2hex(random_bytes(16));"
APP_SECRET=<32_caracteres_hexadecimaux>

# Base de données. serverVersion doit correspondre au serveur réel :
#   mysql -e "SELECT VERSION();"
DATABASE_URL="mysql://UTILISATEUR:MOT_DE_PASSE@127.0.0.1:3306/NOM_BASE?serverVersion=10.11.6-MariaDB&charset=utf8mb4"

# URL publique, utilisée par les commandes console (rapport quotidien, exports)
DEFAULT_URI=https://caisse.mondomaine.ci
```

Puis restreindre sa lecture — il contient le mot de passe de la base :

```bash
chmod 600 .env.local
```

### Points de vigilance

- **`serverVersion` doit être exact.** Doctrine adapte le SQL généré à la version
  annoncée ; une valeur fausse produit des migrations qui passent en local et
  échouent sur le serveur.
- **Le mot de passe doit être encodé en URL** s'il contient `@`, `:`, `/`, `#` ou
  `?` : `Mot@Passe` s'écrit `Mot%40Passe`. Sans cela, le DSN est coupé au mauvais
  endroit et la connexion échoue avec un message qui ne désigne pas la cause.
- **`APP_SECRET` doit différer de celui du dépôt.** Celui de `.env` est public :
  il signe les cookies « se souvenir de moi » et les jetons CSRF.
- **Hôte de la base** : `127.0.0.1` sur un VPS classique. Sur un hébergement
  mutualisé / cPanel, c'est souvent `localhost`, parfois un hôte dédié indiqué
  dans le panneau.

## 5. Base de données

Si la base et l'utilisateur n'existent pas encore (VPS sans panneau) :

```sql
CREATE DATABASE nom_base CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'utilisateur'@'localhost' IDENTIFIED BY 'mot_de_passe';
GRANT ALL PRIVILEGES ON nom_base.* TO 'utilisateur'@'localhost';
FLUSH PRIVILEGES;
```

Puis, depuis le projet :

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

**Ne pas charger les fixtures ni `app:demo:reset` sur le serveur d'exploitation** :
les deux vident la base et créent des comptes de démonstration aux mots de passe
publiés dans ce dépôt.

## 6. Compiler les assets

```bash
php bin/console tailwind:build --minify
php bin/console asset-map:compile
```

`tailwind:build` télécharge le binaire Tailwind au premier appel : le serveur doit
pouvoir sortir sur Internet. À défaut, compiler en local et téléverser
`public/assets/` et `var/tailwind/`.

> Ces deux commandes sont à **relancer après chaque `git pull`** qui touche un
> gabarit, une feuille de style ou un contrôleur Stimulus. Le contenu de
> `public/assets/` est figé et porte des noms condensés ; sans recompilation, le
> navigateur continue de servir l'ancienne version.

## 7. Cache et permissions

```bash
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod

mkdir -p public/uploads/articles var/log var/cache
chown -R www-data:www-data var public/uploads
chmod -R 775 var public/uploads
```

`public/uploads/` est **gitignoré** : il contient les photos des touches produits,
donc du contenu d'exploitation. Il n'existe pas après un `git clone` — il faut le
créer, et le sauvegarder avec la base, pas avec le code.

## 8. Serveur web

La racine web est **`public/`**, jamais la racine du projet : exposer celle-ci
mettrait `.env.local`, `src/` et `var/` à portée du premier visiteur.

### Apache

```apache
<VirtualHost *:443>
    ServerName caisse.mondomaine.ci
    DocumentRoot /var/www/zedpos/public

    <Directory /var/www/zedpos/public>
        AllowOverride All
        Require all granted
        FallbackResource /index.php
    </Directory>

    # Le reste du projet reste hors d'atteinte
    <Directory /var/www/zedpos>
        Options -Indexes
    </Directory>

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/caisse.mondomaine.ci/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/caisse.mondomaine.ci/privkey.pem

    ErrorLog  ${APACHE_LOG_DIR}/zedpos-error.log
    CustomLog ${APACHE_LOG_DIR}/zedpos-access.log combined
</VirtualHost>
```

```bash
sudo a2enmod rewrite headers expires ssl
sudo systemctl reload apache2
```

`headers` et `expires` sont nécessaires à `public/.htaccess`, qui met `/assets/`
en cache un an et interdit la mise en cache de `sw.js`.

### Nginx

```nginx
server {
    listen 443 ssl;
    server_name caisse.mondomaine.ci;
    root /var/www/zedpos/public;

    location / { try_files $uri /index.php$is_args$args; }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    location ~ \.php$ { return 404; }

    ssl_certificate     /etc/letsencrypt/live/caisse.mondomaine.ci/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/caisse.mondomaine.ci/privkey.pem;
}
```

## 9. HTTPS — obligatoire, pas recommandé

```bash
sudo certbot --apache -d caisse.mondomaine.ci
```

**Le Service Worker ne s'enregistre qu'en HTTPS** (ou sur `localhost`). En HTTP
simple, la file de synchronisation continue de fonctionner mais **la page n'est
pas mise en cache** : à la première coupure réseau, la tablette du comptoir
n'affiche plus l'écran de caisse du tout. C'est précisément le scénario que le
mode hors ligne est censé couvrir — déployer sans certificat le vide de son sens.

## 10. Premier démarrage

La base est vierge, aucun compte n'existe. Ouvrir simplement :

```
https://caisse.mondomaine.ci/
```

Tout est redirigé vers **`/installation`**, qui crée le premier compte
**dirigeante**. La route se referme (404) dès qu'un compte existe. Le mot de passe
y est saisi deux fois : c'est le seul du système sans filet, aucun second compte ne
peut le réinitialiser.

Ensuite, depuis l'application :

1. **Paramètres** (`/admin/parametres`) — raison sociale, adresse, NCC, téléphone,
   pied de ticket. Ces informations sont en base, plus dans `.env`.
2. **Utilisateurs** (`/admin/utilisateurs`) — gérant, caissiers (code PIN),
   comptable.
3. Familles, articles, matières premières, fiches techniques.

## 11. Tâches planifiées

```cron
# Rapport quotidien à 21h30, après la clôture des caisses
30 21 * * * cd /var/www/zedpos && php bin/console app:rapport-quotidien \
  | mail -s "ZedPOS - rapport du jour" dirigeante@exemple.ci

# Export comptable du mois écoulé, le 1er à 7h
0 7 1 * * cd /var/www/zedpos && php bin/console app:export-comptable \
  --mois=$(date -d 'last month' +\%Y-\%m) --format=fec -o /tmp/zedpos.txt
```

## 12. Mise à jour du serveur

```bash
cd /var/www/zedpos
php bin/console doctrine:database:dump 2>/dev/null || \
  mysqldump -u UTILISATEUR -p NOM_BASE > ~/sauvegardes/zedpos-$(date +%F).sql

git pull origin main
composer install --no-dev --optimize-autoloader
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console tailwind:build --minify
php bin/console asset-map:compile
php bin/console cache:clear --env=prod
```

**Sauvegarder la base avant toute migration.** Une migration ne se défait pas
toujours proprement, et il n'existe aucun bouton « annuler » sur une table de
ventes.

## 13. Sauvegardes

Deux choses à sauvegarder, et le code n'en fait pas partie (il est sur GitHub) :

| Quoi | Où | Pourquoi |
|---|---|---|
| Base de données | `mysqldump` quotidien | ventes, stock, journal d'audit |
| `public/uploads/` | copie du répertoire | photos des touches produits |

```cron
0 2 * * * mysqldump -u UTILISATEUR -pMOT_DE_PASSE NOM_BASE \
  | gzip > /var/sauvegardes/zedpos-$(date +\%F).sql.gz
```

## 14. En cas de problème

| Symptôme | Piste |
|---|---|
| Page blanche / erreur 500 | `tail -50 var/log/prod.log` puis le log du serveur web |
| « Unable to connect to database » | `serverVersion`, hôte, et mot de passe encodé en URL dans `.env.local` |
| Écran sans aucun style | `asset-map:compile` non lancé, ou `public/assets/` non lisible par le serveur web |
| Le bouton Encaisser est invisible | `tailwind:build` non relancé après une modification de gabarit |
| Caisse inutilisable hors ligne | HTTPS absent : le Service Worker ne s'enregistre pas |
| « Permission denied » sur `var/` | `chown -R www-data:www-data var` |
| Une photo téléversée ne s'affiche pas | `public/uploads/articles` inexistant ou non inscriptible |
