#!/usr/bin/env bash
#
# Mise à jour du serveur de production.
#
# Reprend la séquence de docs/DEPLOIEMENT.md § 12, mais en corrigeant les deux
# façons dont un déploiement à la main casse **toutes** les pages du site — pas
# seulement celles qui ont changé :
#
#   1. `cache:clear` lancé en root. Le cache compilé appartient alors à root et
#      le serveur web ne peut plus y écrire : 500 sur chaque page, y compris
#      l'écran de connexion, qui n'a pourtant rien à voir avec la mise à jour.
#      D'où l'exécution de chaque commande **au nom de l'utilisateur du serveur
#      web**, et un `chown` de rattrapage si un déploiement précédent a laissé
#      des fichiers mal possédés.
#
#   2. Une commande qui échoue sans arrêter la suite. Un `asset-map:compile`
#      interrompu laisse un `public/assets/manifest.json` incomplet ; comme
#      `base.html.twig` appelle `importmap()`, c'est encore le site entier qui
#      tombe — et l'erreur, elle, a défilé dix lignes plus haut. `set -e` fait
#      échouer le script à la première commande fautive, avant qu'elle n'en
#      entraîne d'autres.
#
# Usage :  sudo bash bin/deployer.sh
#
set -euo pipefail

RACINE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RACINE"

# Utilisateur du serveur web : www-data sur Debian/Ubuntu, apache sur RHEL.
# Surchargeable — certaines installations tournent sous un compte dédié.
UTILISATEUR_WEB="${UTILISATEUR_WEB:-www-data}"

php_web() {
    # `sudo -u` seulement si on est root ; sinon on suppose être déjà le bon
    # utilisateur, ce qui permet de lancer le script sans sudo en local.
    if [ "$(id -u)" -eq 0 ]; then
        sudo -u "$UTILISATEUR_WEB" php "$@"
    else
        php "$@"
    fi
}

etape() { printf '\n\033[1;33m▸ %s\033[0m\n' "$1"; }

etape "Sauvegarde de la base"
# Volontairement **pas** automatisée ici : les identifiants vivent dans
# .env.local sous une URL qu'il faudrait décomposer, et une sauvegarde qu'on
# croit faite sans l'avoir vérifiée est pire que pas de sauvegarde du tout.
# Le script se contente d'exiger une confirmation — une migration ne se défait
# pas proprement, et il n'existe aucun bouton « annuler » sur une table de ventes.
if [ -z "${SANS_SAUVEGARDE:-}" ]; then
    echo "  mysqldump -u UTILISATEUR -p BASE > ~/sauvegardes/zedpos-\$(date +%F).sql"
    read -r -p "  Base sauvegardée ? [o/N] " reponse
    [ "$reponse" = "o" ] || { echo "  Déploiement interrompu."; exit 1; }
fi

etape "Récupération du code"
git pull origin main

etape "Dépendances PHP"
composer install --no-dev --optimize-autoloader --no-interaction

etape "Migrations"
php_web bin/console doctrine:migrations:migrate --no-interaction --env=prod

etape "Feuilles de style et assets"
# Dans cet ordre : tailwind:build produit le CSS que asset-map:compile met
# ensuite en empreinte. L'inverse publierait la version précédente du CSS.
php_web bin/console tailwind:build --minify
rm -rf public/assets
php_web bin/console asset-map:compile

etape "Vidage du cache"
php_web bin/console cache:clear --env=prod

etape "Droits"
# Rattrapage : si un déploiement antérieur a été fait en root, des fichiers de
# var/ ou public/assets/ lui appartiennent encore et le serveur web reste bloqué.
if [ "$(id -u)" -eq 0 ]; then
    chown -R "$UTILISATEUR_WEB:$UTILISATEUR_WEB" var public/assets public/uploads 2>/dev/null || true
fi

etape "Vérification"
# Le déploiement n'est pas terminé quand les commandes ont fini : il l'est quand
# le site répond. Un 500 ici vaut mieux qu'un 500 découvert par la caissière.
for chemin in /login /caisse; do
    code=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost${chemin}" || echo 000)
    printf '  %-10s %s\n' "$chemin" "$code"
    if [ "$code" = "500" ]; then
        echo
        echo "  ✗ 500 sur ${chemin} — dernières erreurs :"
        tail -20 var/log/prod.log
        exit 1
    fi
done

echo
echo "✓ Déploiement terminé."
