<?php
defined( 'ABSPATH' ) || exit;

/** Chargement des icônes (Heroicons) dans l'administration + icône du menu. */
class CODCRM_Icon_Assets {

	public static function init(): void {
		add_action( 'admin_head', [ __CLASS__, 'css' ] );
	}

	public static function css(): void {
		echo '<style id="codcrm-icons-css">' . CODCRM_Icons::css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Icône du menu « Suivi COD » : Heroicons plein (téléphone), recolorié par WordPress selon le thème d'admin. */
	public static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="black"><path fill-rule="evenodd" d="M1.5 4.5a3 3 0 0 1 3-3h1.372c.86 0 1.61.586 1.819 1.42l1.105 4.423a1.875 1.875 0 0 1-.694 1.955l-1.293.97c-.135.101-.164.249-.126.352a11.285 11.285 0 0 0 6.697 6.697c.103.038.25.009.352-.126l.97-1.293a1.875 1.875 0 0 1 1.955-.694l4.423 1.105c.834.209 1.42.959 1.42 1.82V19.5a3 3 0 0 1-3 3h-2.25C8.552 22.5 1.5 15.448 1.5 6.75V4.5Z" clip-rule="evenodd"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/** Enregistre icons.js : à lister en dépendance (« codcrm-icons ») des scripts qui construisent des icônes. */
	public static function register(): void {
		if ( ! wp_script_is( 'codcrm-icons', 'registered' ) ) {
			wp_register_script( 'codcrm-icons', CODCRM_URL . 'assets/js/icons.js?v=' . CODCRM_VERSION, [], null, true );
		}
	}

	public static function enqueue(): void {
		self::register();
		wp_enqueue_script( 'codcrm-icons' );
	}
}
