#!/usr/bin/env bash
# Le dossier du thème appartient à www-data : on édite une copie (/tmp/theme-work) puis on la recopie via le conteneur.
# Usage : tools/sync-theme.sh        (copie /tmp/theme-work → thème du site)
set -euo pipefail
cd "$(dirname "$0")/.."
C=$(docker compose ps -q wp)
docker compose exec -T wp rm -rf /var/www/html/wp-content/themes/wparabic-ecomm-cod/assets/vendor/fontawesome
docker cp /tmp/theme-work/. "$C":/var/www/html/wp-content/themes/wparabic-ecomm-cod/
docker compose exec -T wp chown -R www-data:www-data /var/www/html/wp-content/themes/wparabic-ecomm-cod
echo "thème synchronisé"
