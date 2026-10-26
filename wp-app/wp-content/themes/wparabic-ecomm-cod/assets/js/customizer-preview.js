/**
 * Apercu en direct des couleurs dans le personnalisateur.
 *
 * Les reglages de couleur sont en transport « postMessage » (inc/customizer.php) :
 * au lieu de recharger l'apercu, on reecrit une feuille <style> qui redefinit
 * les variables lues par les CSS du theme. Les teintes claires sont calculees
 * comme cote PHP (wparabic_tint dans functions.php).
 */
(function (api) {
	'use strict';

	var values = {};
	var $style = null;

	function tint(hex, ratio) {
		hex = String(hex || '').replace('#', '');
		if (hex.length === 3) {
			hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
		}
		if (!/^[0-9a-f]{6}$/i.test(hex)) {
			return '';
		}
		var out = '#';
		for (var i = 0; i < 6; i += 2) {
			var v = parseInt(hex.substr(i, 2), 16);
			var c = Math.round(v + (255 - v) * ratio).toString(16);
			out += c.length === 1 ? '0' + c : c;
		}
		return out;
	}

	// Meme calcul que wparabic_contrast_text() (functions.php).
	function contrast(hex) {
		hex = String(hex || '').replace('#', '');
		if (hex.length === 3) {
			hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
		}
		if (!/^[0-9a-f]{6}$/i.test(hex)) {
			return '#333333';
		}
		var lum = 0;
		[0.2126, 0.7152, 0.0722].forEach(function (w, i) {
			var c = parseInt(hex.substr(i * 2, 2), 16) / 255;
			c = c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
			lum += w * c;
		});
		return lum > 0.179 ? '#333333' : '#ffffff';
	}

	function render() {
		var css = '';
		var p = values.primary_color;

		if (p) {
			css += ':root{--wparabic-primary:' + p
				+ ';--wparabic-primary-light:' + tint(p, 0.70)
				+ ';--wparabic-primary-lighter:' + tint(p, 0.93)
				+ ';--wparabic-primary-disabled:' + tint(p, 0.55) + ';}';
		}
		if (values.accent_color) {
			css += ':root{--wparabic-accent:' + values.accent_color + ';}';
		}
		if (values.topbar_bg) {
			css += ':root{--wparabic-topbar-bg:' + values.topbar_bg + ';}.top-head-wrap{background-color:' + values.topbar_bg + '}';
		}
		if (values.topbar_text) {
			css += '.navigation-top>li>a,.top-head-wrap{color:' + values.topbar_text + '}';
		}
		if (values.footer_bg) {
			css += '.footer-widgets{background-color:' + values.footer_bg + '}';
		}
		// Texte du pied : couleur choisie, sinon automatique selon le fond.
		if (values.footer_text_color || values.footer_bg) {
			css += ':root{--wparabic-footer-text:' + (values.footer_text_color || contrast(values.footer_bg)) + ';}';
		}

		// Voile des tuiles : couleur + intensite, 0 % = transparent.
		var oc = values.tile_overlay_color || '#2b2c2c';
		var oo = parseInt(values.tile_overlay_opacity, 10);
		if (isNaN(oo)) { oo = 40; }
		oo = Math.max(0, Math.min(100, oo));
		var rgba = 'transparent';
		if (oo > 0) {
			var oh = oc.replace('#', '');
			if (oh.length === 3) {
				oh = oh[0] + oh[0] + oh[1] + oh[1] + oh[2] + oh[2];
			}
			rgba = 'rgb(' + parseInt(oh.substr(0, 2), 16) + ' ' + parseInt(oh.substr(2, 2), 16)
				+ ' ' + parseInt(oh.substr(4, 2), 16) + ' / ' + oo + '%)';
		}
		css += ':root{--wparabic-tile-overlay:' + rgba + ';}';

		if (!$style) {
			$style = document.createElement('style');
			$style.id = 'wparabic-live-colors';
			document.head.appendChild($style);
		}
		$style.textContent = css;
	}

	['primary_color', 'accent_color', 'topbar_bg', 'topbar_text', 'footer_bg', 'footer_text_color', 'tile_overlay_color', 'tile_overlay_opacity'].forEach(function (id) {
		api(id, function (setting) {
			// Valeurs de depart : le texte automatique du pied depend du fond
			// meme quand seul l'un des deux reglages change.
			if (id === 'footer_bg' || id === 'footer_text_color') {
				values[id] = setting.get();
			}
			setting.bind(function (value) {
				values[id] = value;
				render();
			});
		});
	});
})(wp.customize);
