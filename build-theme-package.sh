#!/usr/bin/env bash
# Package installable du thème WPArabicEcommCOD → dist/wparabic-ecomm-cod-<version>.zip
set -euo pipefail
cd "$(dirname "$0")"

THEME_DIR="wp-app/wp-content/themes/wparabic-ecomm-cod"
SLUG="wparabic-ecomm-cod"
VERSION=$(grep -oP "^Version:\s*\K[0-9.]+" "$THEME_DIR/style.css" | head -1)
CONST=$(grep -oP "define\('WPARABIC_VERSION',\s*'\K[0-9.]+" "$THEME_DIR/functions.php" | head -1)
[ -n "${VERSION:-}" ] || { echo "ERREUR : version introuvable dans style.css" >&2; exit 1; }
[ "$VERSION" = "$CONST" ] || { echo "ERREUR : style.css ($VERSION) ≠ WPARABIC_VERSION ($CONST)" >&2; exit 1; }

if command -v php >/dev/null 2>&1; then
  while IFS= read -r f; do php -l "$f" >/dev/null || { echo "ERREUR syntaxe : $f" >&2; exit 1; }; done < <(find "$THEME_DIR" -name '*.php')
fi
[ ! -d "$THEME_DIR/assets/vendor/fontawesome" ] || { echo "ERREUR : Font Awesome ne doit plus être livré" >&2; exit 1; }

mkdir -p dist
ZIP="dist/${SLUG}-${VERSION}.zip"
rm -f "$ZIP"
( cd wp-app/wp-content/themes && zip -qr "$OLDPWD/$ZIP" "$SLUG" -x '*.DS_Store' -x '*.log' -x '*.bak' )

LISTING=$(unzip -l "$ZIP")
for must in "${SLUG}/style.css" "${SLUG}/functions.php" "${SLUG}/inc/icons.php" "${SLUG}/assets/css/icons.css" "${SLUG}/LICENSE-icons.txt"; do
  echo "$LISTING" | grep -q "$must" || { echo "ERREUR : $must absent du zip" >&2; exit 1; }
done
echo "✅ $ZIP — $(du -h "$ZIP" | cut -f1) — sha256 $(sha256sum "$ZIP" | cut -c1-16)…"
