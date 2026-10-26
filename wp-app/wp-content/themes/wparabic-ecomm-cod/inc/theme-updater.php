<?php
/**
 * Mise a jour automatique du theme depuis le depot GitHub public.
 *
 * Le depot contient TOUTE la base de code (wp-content/themes inclus) : la
 * source de verite de la version est le champ Version du style.css du repo,
 * pas un release GitHub.
 *
 * Fonctionnement :
 * - une fois par cycle de verification WP (12 h), le style.css distant est
 *   recupere sur raw.githubusercontent ; si sa Version est superieure a la
 *   version installée, WordPress affiche « Mettre a jour » dans Apparence ;
 * - le telechargement pointe sur le zip du repo (codeload, sans auth pour
 *   un depot public). Le dossier extrait « mba-shoes-main » est renomme en
 *   « wparabic-ecomm-cod » pendant l'installation (upgrader_source_selection).
 * - depot passe un jour en prive : la verification echouera silencieusement
 *   (pas de ligne « mise a jour ») ; filtrable via
 *   wparabic_theme_updater_remote_version pour brancher un serveur proxy.
 *
 * @package wparabic-ecomm-cod
 */

defined('ABSPATH') || exit;

define('WPARABIC_GITHUB_REPO', 'houssembaklouty/mba-shoes');
define('WPARABIC_GITHUB_BRANCH', 'main');
define('WPARABIC_THEME_SLUG', 'wparabic-ecomm-cod');
define(
    'WPARABIC_THEME_ZIP_URL',
    'https://codeload.github.com/' . WPARABIC_GITHUB_REPO . '/zip/refs/heads/' . WPARABIC_GITHUB_BRANCH
);

/**
 * Version declaree dans le style.css du repo, avec un cache court.
 *
 * Valeur '' (30 min) quand la remote est injoignable : on ne revient que
 * toutes les demi-heures, et WordPress n'annonce rien sans version fiable.
 *
 * @return string
 */
function wparabic_theme_remote_version() {
    $cached = get_transient('wparabic_theme_remote_version');

    if (false !== $cached) {
        return (string) $cached;
    }

    $version = '';
    $response = wp_remote_get(
        sprintf(
            'https://raw.githubusercontent.com/%1$s/%2$s/wp-content/themes/%3$s/style.css',
            WPARABIC_GITHUB_REPO,
            WPARABIC_GITHUB_BRANCH,
            WPARABIC_THEME_SLUG
        ),
        array('timeout' => 10)
    );

    if (is_array($response) && 200 === (int) wp_remote_retrieve_response_code($response)) {
        if (preg_match('/^Version:\s*([0-9A-Za-z.\-]+)/mi', wp_remote_retrieve_body($response), $match)) {
            $version = $match[1];
        }
    }

    // Point d'extension : un jour le depot est prive, ou le download passe
    // par un serveur a nous — la comparison reste la meme.
    $version = (string) apply_filters('wparabic_theme_updater_remote_version', $version);

    set_transient(
        'wparabic_theme_remote_version',
        $version,
        $version === '' ? 30 * MINUTE_IN_SECONDS : 12 * HOUR_IN_SECONDS
    );

    return $version;
}

/**
 * Version distante disponible vs version installee.
 *
 * @return array{update_available:bool, remote_version:string}
 */
function wparabic_theme_update_check() {
    $remote = wparabic_theme_remote_version();
    $installed = wp_get_theme(WPARABIC_THEME_SLUG)->get('Version');

    return array(
        'update_available' => $remote !== '' && version_compare($remote, $installed, '>'),
        'remote_version' => $remote,
    );
}

/**
 * Petite le resultat dans le transient natif des themes.
 *
 * @param object $transient Transient update_themes en cours d'ecriture.
 */
add_filter('pre_set_site_transient_update_themes', 'wparabic_theme_updates_transient');
function wparabic_theme_updates_transient($transient) {
    // WP recontacte sans entete checked pendant les divers refresh partiels.
    if (!is_object($transient) || empty($transient->checked)) {
        return $transient;
    }

    $check = wparabic_theme_update_check();

    if (!$check['update_available']) {
        return $transient;
    }

    $transient->response[WPARABIC_THEME_SLUG] = array(
        'theme' => WPARABIC_THEME_SLUG,
        'new_version' => $check['remote_version'],
        'url' => 'https://github.com/' . WPARABIC_GITHUB_REPO,
        'package' => WPARABIC_THEME_ZIP_URL,
        'requires' => '6.0',
        'requires_php' => '7.4',
    );

    return $transient;
}

/**
 * Le zip du repo s'extrait sous « mba-shoes-<branche>/ » ; le repertoire du
 * theme s'appelle « wparabic-ecomm-cod ». On renomme la racine extraite
 * pendant l'installation/mise a jour pour que WordPress la remplace au bon
 * endroit (WordPress n'a aucun jeton de correspondance par defaut).
 *
 * @param string   $source        Chemin de la source extraite.
 * @param string   $remote_source (inutilise) racine distante.
 * @param object   $upgrader      Instance de l'upgrader.
 * @param array    $hook_extra    Contexte : type/action/themes.
 */
add_filter('upgrader_source_selection', 'wparabic_theme_rename_zip_root', 10, 4);
function wparabic_theme_rename_zip_root($source, $remote_source, $upgrader, $hook_extra) {
    global $wp_filesystem;

    // Uniquement pour le theme pendant une mise a jour/installation : les
    // extensions restent intactes meme si le zip d'une extension passe ici.
    if (
        !$wp_filesystem
        || !is_array($hook_extra)
        || ($hook_extra['type'] ?? '') !== 'theme'
        || !in_array(($hook_extra['action'] ?? ''), array('update', 'install'), true)
    ) {
        return $source;
    }

    $root = basename($source);

    // Deja au nom du theme (zip du theme seul) : rien a renommer.
    if ($root === WPARABIC_THEME_SLUG) {
        return $source;
    }

    // Zip reconnu : racine « mba-shoes-… » (archive GitHub, refs/heads ou
    // tags), ou n'importe quelle racine tant que l'arbre contient le theme
    // (wp-content/themes/<slug>/style.css) — firmware futur via releases
    // archives sous un nom libre.
    $marker = trailingslashit($source) . 'wp-content/themes/' . WPARABIC_THEME_SLUG . '/style.css';
    $is_repo_zip = strpos($root, 'mba-shoes-') === 0 || $wp_filesystem->exists($marker);

    if (!$is_repo_zip) {
        return $source;
    }

    $new_source = trailingslashit(dirname($source)) . WPARABIC_THEME_SLUG;

    // overwrite=true : le tmp est vierge, sauf un contenu residuel du meme nom
    // deja present dans le tmp (jamais en pratique).
    if (!$wp_filesystem->move($source, $new_source, true)) {
        return new WP_Error(
            'wparabic_theme_zip_root',
            sprintf(
                /* translators: %s : nom du dossier extrait. */
                __('Renommage du dossier extrait « %s » impossible.', 'wparabic-ecomm-cod'),
                $root
            )
        );
    }

    return $new_source;
}

/**
 * Purge du cache de version des qu'une mise a jour du theme se termine :
 * la prochaine verification repart proprement (et n'annonce plus rien
 * puisque remote == installee).
 *
 * @param object  $upgrader   Upgrader en cours.
 * @param array         $hook_extra Contexte du process.
 */
add_action('upgrader_process_complete', 'wparabic_theme_clear_update_cache', 10, 2);
function wparabic_theme_clear_update_cache($upgrader, $hook_extra) {
    if (
        is_array($hook_extra)
        && ($hook_extra['type'] ?? '') === 'theme'
        && in_array(WPARABIC_THEME_SLUG, (array) ($hook_extra['themes'] ?? array()), true)
    ) {
        delete_transient('wparabic_theme_remote_version');
    }
}
