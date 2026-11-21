#!/usr/bin/env bash
# Package installable du plugin COD Tracker CRM → dist/cod-tracker-crm-<version>.zip
set -euo pipefail
cd "$(dirname "$0")"

PLUGIN_DIR="wp-app/wp-content/plugins/cod-tracker-crm"
SLUG="cod-tracker-crm"
VERSION=$(grep -oP "define\( 'CODCRM_VERSION',\s*'\K[0-9.]+" "$PLUGIN_DIR/cod-tracker-crm.php")
HEADER=$(grep -oP "Version:\s+\K[0-9.]+" "$PLUGIN_DIR/cod-tracker-crm.php" | head -1)
[ -n "${VERSION:-}" ] || { echo "ERREUR : version introuvable" >&2; exit 1; }
[ "$VERSION" = "$HEADER" ] || { echo "ERREUR : en-tête ($HEADER) ≠ constante ($VERSION)" >&2; exit 1; }

# Contrôle syntaxique avant packaging (si php est disponible).
if command -v php >/dev/null 2>&1; then
  while IFS= read -r f; do php -l "$f" >/dev/null || { echo "ERREUR syntaxe : $f" >&2; exit 1; }; done \
    < <(find "$PLUGIN_DIR" -name '*.php')
fi

mkdir -p dist
ZIP="dist/${SLUG}-${VERSION}.zip"
rm -f "$ZIP"
( cd wp-app/wp-content/plugins && zip -qr "$OLDPWD/$ZIP" "$SLUG" -x '*.DS_Store' -x '*.log' -x '*.bak' )

LISTING=$(unzip -l "$ZIP")
echo "$LISTING" | grep -q "${SLUG}/cod-tracker-crm.php" || { echo "ERREUR : structure du zip incorrecte" >&2; exit 1; }
echo "$LISTING" | grep -q "assets/vendor/html5-qrcode.min.js" || { echo "ERREUR : librairie de scan absente" >&2; exit 1; }
echo "✅ $ZIP — $(du -h "$ZIP" | cut -f1) — sha256 $(sha256sum "$ZIP" | cut -c1-16)…"
