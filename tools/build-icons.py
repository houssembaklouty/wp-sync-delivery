#!/usr/bin/env python3
"""Génère les bibliothèques d'icônes (Heroicons v2 Outline 24 px, MIT ; Simple Icons CC0 pour les marques).

Usage : npm pack heroicons@2 simple-icons@13 (dans /tmp/hi), puis  python3 tools/build-icons.py /tmp/hi
Sortie : plugins/cod-tracker-crm/{includes/class-icons.php,assets/js/icons.js}, plugins/wc-multicarrier-sync/{…},
         themes/wparabic-ecomm-cod/inc/icons.php (+ LICENSE-icons.txt dans chaque projet).
"""
import json, os, re, sys, glob, tarfile

SRC = sys.argv[1] if len(sys.argv) > 1 else '/tmp/hi'
for t in glob.glob(SRC + '/*.tgz'):
    d = t.replace('.tgz', '')
    if not os.path.isdir(d):
        os.makedirs(d); tarfile.open(t).extractall(d)
HERO = glob.glob(SRC + '/heroicons-*/package')[0] + '/24/outline/'
SIMP = glob.glob(SRC + '/simple-icons-*/package')[0] + '/icons/'
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PL = ROOT + '/wp-app/wp-content/plugins/'
TH = os.environ.get('THEME_OUT', ROOT + '/wp-app/wp-content/themes/wparabic-ecomm-cod/')

COMMON = ['pencil-square', 'phone', 'chat-bubble-left-right', 'chat-bubble-left', 'trash', 'user', 'user-plus', 'bell', 'bell-slash', 'bell-alert',
          'exclamation-triangle', 'check', 'check-circle', 'x-mark', 'x-circle', 'arrow-path', 'play', 'pause', 'lock-closed', 'lock-open', 'key',
          'magnifying-glass', 'clipboard-document', 'clipboard-document-list', 'truck', 'qr-code', 'camera', 'arrow-uturn-left', 'cube', 'star',
          'arrow-down-tray', 'clock', 'arrow-left', 'arrow-right', 'chevron-down', 'chevron-up', 'chevron-left', 'chevron-right', 'speaker-wave',
          'speaker-x-mark', 'document-text', 'banknotes', 'map-pin', 'map', 'cog-6-tooth', 'envelope', 'rocket-launch', 'bolt', 'paper-airplane',
          'link', 'globe-alt', 'arrows-pointing-out', 'arrows-pointing-in', 'shopping-cart', 'bars-3', 'trophy', 'shield-check', 'calendar',
          'minus', 'plus', 'information-circle', 'x-circle', 'building-storefront', 'gift', 'tag', 'heart', 'home']
THEME = ['shopping-cart', 'chevron-left', 'chevron-right', 'chevron-down', 'chevron-up', 'magnifying-glass', 'phone', 'bars-3', 'truck', 'arrow-uturn-left',
         'banknotes', 'trophy', 'shield-check', 'check', 'check-circle', 'x-mark', 'map-pin', 'envelope', 'gift', 'tag', 'heart', 'home', 'star', 'clock',
         'minus', 'plus', 'building-storefront', 'information-circle', 'exclamation-triangle', 'user', 'chat-bubble-left-right', 'arrow-left', 'arrow-right',
         'cube', 'bolt', 'rocket-launch', 'lock-closed', 'calendar', 'document-text', 'no-symbol', 'globe-alt', 'x-circle', 'arrow-top-right-on-square']
BRANDS = ['whatsapp', 'facebook', 'instagram', 'tiktok']

def inner(name):
    s = open(HERO + name + '.svg').read()
    return re.search(r'<svg[^>]*>(.*)</svg>', s, re.S).group(1).strip().replace('\n', '').replace('  ', '')

def brand(name):
    s = open(SIMP + name + '.svg').read()
    return re.search(r'<path d="([^"]+)"', s).group(1)

def php_array(d, indent='\t'):
    return ''.join("%s'%s' => '%s',\n" % (indent, k, v.replace("'", "\\'")) for k, v in d.items())

def write_php(path, cls, prefix, names, with_brands, extra_doc):
    icons = {n: inner(n) for n in names}
    brands = {n: brand(n) for n in BRANDS} if with_brands else {}
    code = """<?php
defined( 'ABSPATH' ) || exit;

/**
 * Icônes : Heroicons v2 « Outline » 24 px (MIT, © Tailwind Labs — https://heroicons.com)%s.
 * Fichier généré par tools/build-icons.py : ne pas modifier à la main.
 *
 * Usage : %s::svg( 'pencil-square' ) → <svg class="hi hi-pencil-square" …>  (couleur = couleur du texte).
 */
class %s {

	/** @var array<string,string> nom => contenu SVG (24×24, trait). */
	const ICONS = [
%s	];
%s
	/** Nom connu ? */
	public static function has( string $name ): bool {
		return isset( self::ICONS[ $name ] )%s;
	}

	/**
	 * @param string $name  Nom Heroicons (ex. « truck »)%s.
	 * @param string $class Classes CSS supplémentaires.
	 */
	public static function svg( string $name, string $class = '' ): string {
%s		if ( ! isset( self::ICONS[ $name ] ) ) {
			return '';
		}
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="hi hi-' . esc_attr( $name . ( $class ? ' ' . $class : '' ) ) . '" aria-hidden="true" focusable="false">' . self::ICONS[ $name ] . '</svg>';
	}

	/** Icône en data-URI (menu WordPress : fill noir, recoloré par WordPress). */
	public static function data_uri( string $name ): string {
		if ( ! isset( self::ICONS[ $name ] ) ) {
			return '';
		}
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="black" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' . self::ICONS[ $name ] . '</svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/** CSS de base des icônes (taille relative au texte). */
	public static function css(): string {
		return '.hi{width:1.15em;height:1.15em;vertical-align:-.2em;flex:none;display:inline-block}';
	}
}
""" % (extra_doc[0], cls, cls, php_array(icons, '\t\t'),
       ("\n\t/** @var array<string,string> marques (Simple Icons, CC0) : nom => tracé SVG 24×24 plein. */\n\tconst BRANDS = [\n" + php_array(brands, '\t\t') + "\t];\n") if with_brands else '',
       " || isset( self::BRANDS[ $name ] )" if with_brands else '',
       ' ou marque (« whatsapp »…)' if with_brands else '',
       ("\t\tif ( isset( self::BRANDS[ $name ] ) ) {\n\t\t\treturn '<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\" fill=\"currentColor\" class=\"hi hi-brand hi-' . esc_attr( $name . ( $class ? ' ' . $class : '' ) ) . '\" aria-hidden=\"true\" focusable=\"false\"><path d=\"' . self::BRANDS[ $name ] . '\"/></svg>';\n\t\t}\n") if with_brands else '')
    open(path, 'w').write(code)

def write_js(path, var, names):
    icons = {n: inner(n) for n in names}
    js = """/* Icônes : Heroicons v2 « Outline » 24 px (MIT, © Tailwind Labs — https://heroicons.com). Généré par tools/build-icons.py. */
(function (w) {
    'use strict';
    var I = %s;
    /** %s.svg('trash', 'classe') → balise <svg> (couleur = couleur du texte). */
    w.%s = {
        has: function (n) { return Object.prototype.hasOwnProperty.call(I, n); },
        svg: function (n, c) {
            if (!I[n]) { return ''; }
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="hi hi-' + n + (c ? ' ' + c : '') + '" aria-hidden="true" focusable="false">' + I[n] + '</svg>';
        }
    };
})(window);
""" % (json.dumps(icons, ensure_ascii=False), var, var)
    open(path, 'w').write(js)

LIC = open(glob.glob(SRC + '/heroicons-*/package/LICENSE')[0]).read()
SLIC = "Simple Icons — CC0 1.0 Universal (domaine public) — https://simpleicons.org\nLes marques WhatsApp, Facebook, Instagram et TikTok appartiennent à leurs propriétaires respectifs.\n"

write_php(PL + 'cod-tracker-crm/includes/class-icons.php', 'CODCRM_Icons', 'codcrm', COMMON, False, [''])
write_js(PL + 'cod-tracker-crm/assets/js/icons.js', 'codcrmIcons', COMMON)
write_php(PL + 'wc-multicarrier-sync/includes/class-icons.php', 'WCMCS_Icons', 'wcmcs', COMMON, False, [''])
write_js(PL + 'wc-multicarrier-sync/assets/js/icons.js', 'wcmcsIcons', COMMON)
os.makedirs(TH + 'inc', exist_ok=True)
write_php(TH + 'inc/icons.php', 'WParabic_Icons', 'wparabic', THEME, True, [' et Simple Icons (CC0) pour les marques'])
for d in (PL + 'cod-tracker-crm/', PL + 'wc-multicarrier-sync/', TH):
    open(d + 'LICENSE-icons.txt', 'w').write('Heroicons — MIT License\n\n' + LIC + ('\n\n' + SLIC if d == TH else ''))
print('ok', len(COMMON), 'icônes communes,', len(THEME), 'thème')
