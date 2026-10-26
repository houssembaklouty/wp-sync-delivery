<?php
/**
 * Auto-updates via GitHub Releases (plugin-update-checker v5.7).
 *
 * Repo de distribution : github.com/houssembaklouty/multicarrier-sync-tn (privé).
 * Le token de distribution (fine-grained PAT, portée lecture seule sur CE
 * repo uniquement) n'est JAMAIS commité (GitHub Push Protection bloque tout
 * PAT dans le code) : il est résolu au runtime via la constante
 * WCMCS_GH_TOKEN (wp-config.php) ou l'option 'wcmcs_gh_token' (base).
 *
 * @package WC_MultiCarrier_Sync
 */

defined( 'ABSPATH' ) || exit;

class WCMCS_Updater {

	/**
	 * URL du repo GitHub de distribution.
	 */
	const GH_REPO_URL = 'https://github.com/houssembaklouty/multicarrier-sync-tn/';

	/**
	 * Token de distribution (fine-grained PAT, lecture seule, repo unique).
	 *
	 * JAMAIS en dur ici (GitHub Push Protection bloque tout PAT commité) :
	 * résolu au runtime, dans cet ordre —
	 *   1. constante WCMCS_GH_TOKEN dans wp-config.php ;
	 *   2. option WordPress 'wcmcs_gh_token' (base, autoload off).
	 *
	 * @return string Token ou '' (updates publics sans auth).
	 */
	private static function get_distribution_token(): string {
		if ( defined( 'WCMCS_GH_TOKEN' ) && '' !== WCMCS_GH_TOKEN ) {
			return (string) WCMCS_GH_TOKEN;
		}
		$token = get_option( 'wcmcs_gh_token', '' );
		return is_string( $token ) ? $token : '';
	}

	/**
	 * Initialise le checker d'updates.
	 */
	public static function init(): void {
		if ( ! class_exists( 'PucFactory' ) ) {
			$puc_file = WCMCS_PLUGIN_DIR . 'includes/libraries/plugin-update-checker/plugin-update-checker.php';
			if ( ! file_exists( $puc_file ) ) {
				return;
			}
			require_once $puc_file;
		}

		if ( ! class_exists( 'YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			return;
		}

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			self::GH_REPO_URL,
			WCMCS_PLUGIN_FILE,
			'wc-multicarrier-sync'
		);

		// Repo privé : authentification des requêtes API et des téléchargements
		// (PUC v5 : setAuthentication — Basic auth username:token côté GitHub).
		$token = self::get_distribution_token();
		if ( $token && method_exists( $checker, 'setAuthentication' ) ) {
			$checker->setAuthentication( $token );
		}

		// Repo distribution-only : utiliser l'asset ZIP de la release.
		// enableReleaseAssets() vit sur l'objet API (getVcsApi), pas le checker.
		// REQUIRE = ne JAMAIS retomber sur le zipball du tag (ce repo ne
		// contient pas le code source, son zipball ne serait que le README).
		if ( method_exists( $checker, 'getVcsApi' ) ) {
			$api = $checker->getVcsApi();
			if ( method_exists( $api, 'enableReleaseAssets' ) ) {
				$api->enableReleaseAssets(
					'/^wc-multicarrier-sync-[0-9.]+\.zip$/',
					\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS
				);
			}
		}
	}
}
