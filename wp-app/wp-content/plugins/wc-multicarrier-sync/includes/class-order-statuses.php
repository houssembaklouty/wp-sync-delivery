<?php
/**
 * Statuts de commande personnalisés (Téléchargée / Livrée / Retournée).
 *
 * 3 statuts WooCommerce enregistrés pour refléter le cycle transporteur :
 *  - wc-wcmcs-synced    « Téléchargée » : sync réussie avec le transporteur.
 *  - wc-wcmcs-delivered « Livrée »      : transporteur signale la livraison.
 *  - wc-wcmcs-returned  « Retournée »   : transporteur signale un retour.
 *
 * Transitions automatiques activées par défaut (désactivables dans
 * Réglages → Général → « Statuts de commande »). Libellés affichés
 * personnalisables (champ texte) + traductions AR/EN via i18n standard.
 *
 * @package WC_MultiCarrier_Sync
 */

defined( 'ABSPATH' ) || exit;

class WCMCS_Order_Statuses {

	/** Statut : sync transporteur réussie. */
	const STATUS_SYNCED = 'wc-wcmcs-synced';

	/** Statut : livrée par le transporteur. */
	const STATUS_DELIVERED = 'wc-wcmcs-delivered';

	/** Statut : retour chez le transporteur. */
	const STATUS_RETURNED = 'wc-wcmcs-returned';

	/** Statut : commande confirmée par l'agent (COD non payée). */
	const STATUS_CONFIRMED = 'wc-wcmcs-confirmed';

	/** Statut : commande emballée (prête transporteur). */
	const STATUS_PACKED = 'wc-wcmcs-packed';

	/** Option : transition auto « Téléchargée » après sync. */
	const OPT_ENABLE_SYNCED = 'wcmcs_status_synced_enabled';

	/** Option : « Livrée » à la place de « Terminée ». */
	const OPT_ENABLE_DELIVERED = 'wcmcs_status_delivered_enabled';
	/** Option : « Retournée » à la place de « En attente ». */
	const OPT_ENABLE_RETURNED = 'wcmcs_status_returned_enabled';

	/** Option : libellé personnalisé « Téléchargée ». */
	const OPT_LABEL_SYNCED = 'wcmcs_status_synced_label';

	/** Option : libellé personnalisé « Livrée ». */
	const OPT_LABEL_DELIVERED = 'wcmcs_status_delivered_label';

	/** Option : libellé personnalisé « Retournée ». */
	const OPT_LABEL_RETURNED = 'wcmcs_status_returned_label';

	/** Option : libellé personnalisé « Confirmée ». */
	const OPT_LABEL_CONFIRMED = 'wcmcs_status_confirmed_label';

	/** Option : libellé personnalisé « Emballée ». */
	const OPT_LABEL_PACKED = 'wcmcs_status_packed_label';

	/** Carte clé de statut → option libellé (FR / héritée). Suffixe _en/_ar pour les autres langues. */
	const OPTION_LABELS = [
		'synced'    => self::OPT_LABEL_SYNCED,
		'delivered' => self::OPT_LABEL_DELIVERED,
		'returned'  => self::OPT_LABEL_RETURNED,
		'confirmed' => self::OPT_LABEL_CONFIRMED,
		'packed'    => self::OPT_LABEL_PACKED,
	];

	/** Option : statut à la commande passée (paiement / COD). Défaut on-hold. */
	const OPT_ON_PAYMENT = 'wcmcs_status_on_payment';
	const ON_PAYMENT_DEFAULT = 'on-hold';

	/** Option : statut après synchronisation transporteur. */
	const OPT_AFTER_SYNC = 'wcmcs_status_after_sync';

	/** Statuts WC éligibles à la transition « Téléchargée » (non terminaux).
	 *  NB : clés telles que renvoyées par WC_Order::get_status() — sans préfixe wc-. */
	const ELIGIBLE_FOR_SYNCED = [
		'processing', 'on-hold', 'pending',
		'wcmcs-confirmed', 'wcmcs-packed',
	];

	/** Préfixe des options couleur de badge par statut. */
	const OPT_COLOR = 'wcmcs_status_color_';

	/**
	 * Couleurs par défaut des badges (statuts natifs inclus : « payée » =
	 * En cours processing, file d'attente, emballée, etc.).
	 * Clés sans préfixe wc- (identiques aux classes CSS status-x et aux
	 * clés renvoyées par WC_Order::get_status()).
	 *
	 * @var array<string,string> clé => hex.
	 */
	const COLOR_DEFAULTS = [
		'processing'      => '#777777', // En cours : statut natif WC (gris neutre)
		'on-hold'         => '#d4af37', // En attente : gold
		'wcmcs-confirmed' => '#e5a04c', // Confirmée : orange
		'wcmcs-packed'    => '#8e44ad', // Emballée : violet
		'wcmcs-synced'    => '#4aa3df', // Téléchargée : bleu ciel
		'wcmcs-delivered' => '#116329', // Livrée : vert foncé
		'wcmcs-returned'  => '#d63638', // Retournée : rouge
	];

	public function __construct() {
		add_action( 'init', [ $this, 'register_statuses' ] );
		add_filter( 'wc_order_statuses', [ $this, 'add_statuses' ] );
		add_action( 'admin_head', [ $this, 'admin_status_css' ] );

		// Transition auto « Téléchargée » après sync réussie.
		add_action( 'wcmcs_order_synced', [ $this, 'on_order_synced' ], 20, 3 );

		// Cycle de vie : statut des commandes COD à la commande passée (« En attente » par défaut).
		// NB : le paiement COD ne passe jamais par payment_complete() — la passerelle
		// POS fixe le statut via ce filtre dédié. Les commandes payées en ligne
		// (carte) restent « En cours » (elles sont payées).
		add_filter( 'woocommerce_cod_process_payment_order_status', [ __CLASS__, 'status_on_cod' ], 10, 2 );

		// Polling : suivre les commandes dans les nouveaux statuts non terminaux.
		add_filter( 'wcmcs_tracking_poll_statuses', [ $this, 'poll_statuses' ] );
	}

	// ─────────────────────────────────────────────
	//  Activation (opt-in)
	// ─────────────────────────────────────────────

	/**
	 * Transition « Téléchargée » après sync activée ? (défaut : oui)
	 */
	public static function synced_enabled(): bool {
		return get_option( self::OPT_ENABLE_SYNCED, 'yes' ) === 'yes';
	}

	/**
	 * Statut « Livrée » activé (à la place de « Terminée ») ? (défaut : oui)
	 */
	public static function delivered_enabled(): bool {
		return get_option( self::OPT_ENABLE_DELIVERED, 'yes' ) === 'yes';
	}

	/**
	 * Statut « Retournée » activé (à la place de « En attente ») ? (défaut : oui)
	 */
	public static function returned_enabled(): bool {
		return get_option( self::OPT_ENABLE_RETURNED, 'yes' ) === 'yes';
	}

	/**
	 * Statut cible après une sync réussie, selon l'option du cycle de vie.
	 * '' s'il faut ne rien changer. BC : lit l'ancienne case « Téléchargée ».
	 *
	 * @return string 'wc-wcmcs-synced' | 'on-hold' | 'processing' | ''
	 */
	public static function target_after_sync(): string {
		$v = get_option( self::OPT_AFTER_SYNC, null );
		if ( null === $v ) {
			// Option jamais enregistrée : BC avec l'ancienne case.
			return get_option( self::OPT_ENABLE_SYNCED, 'yes' ) === 'yes' ? self::STATUS_SYNCED : '';
		}
		$v = (string) $v;
		if ( '' === $v ) {
			return '';
		}
		if ( 'synced' === $v ) {
			// Valeur du sélecteur réglages (suffixe sans wc-) → clé réelle.
			$v = self::STATUS_SYNCED;
		}
		return in_array( $v, [ self::STATUS_SYNCED, 'on-hold', 'processing' ], true ) ? $v : '';
	}

	/**
	 * Statut appliqué quand une commande « passe » (paiement/COD) : option
	 * « Commande passée » du cycle de vie pour les paiements COD (non payée
	 * d'avance : cash/virement à la livraison). Défaut « En attente » (on-hold) :
	 * commande reçue non payée, en file de l'agent de confirmation.
	 * Ne touche que le statut proposé « processing » par la passerelle (le
	 * total zéro → « completed » reste inchangé).
	 *
	 * @param string   $status Statut proposé par la passerelle.
	 * @param WC_Order $order  Commande (inutilisé, présent pour le filtre WC).
	 * @return string
	 */
	public static function status_on_cod( string $status, $order = null ): string {
		$target = get_option( self::OPT_ON_PAYMENT, self::ON_PAYMENT_DEFAULT );
		$target = is_string( $target ) ? $target : '';
		if ( ! in_array( $target, [ 'on-hold', 'processing' ], true ) ) {
			return $status;
		}
		if ( 'processing' !== $status ) {
			return $status; // total zéro → completed etc. : inchangé
		}
		return $target;
	}

	/**
	 * Statut cible quand le transporteur signale une livraison.
	 *
	 * @return string 'wc-wcmcs-delivered' si activé, sinon 'completed'.
	 */
	public static function delivered_target(): string {
		return self::delivered_enabled() ? self::STATUS_DELIVERED : 'completed';
	}

	/**
	 * Statut cible quand le transporteur signale un retour.
	 *
	 * @return string 'wc-wcmcs-returned' si activé, sinon 'on-hold'.
	 */
	public static function returned_target(): string {
		return self::returned_enabled() ? self::STATUS_RETURNED : 'on-hold';
	}

	// ─────────────────────────────────────────────
	//  Libellés (override admin par langue > défaut multilingue > FR)
	// ─────────────────────────────────────────────

	/**
	 * Libellés par défaut « vrais noms » par langue du site (WordPress réglage
	 * langage). Clé = préfixe locale (fr, en, ar). FR = langue de base.
	 */
	const LABEL_DEFAULTS = [
		'fr' => [
			'synced'    => 'Téléchargée',
			'delivered' => 'Livrée',
			'returned'  => 'Retournée',
			'confirmed' => 'Confirmée',
			'packed'    => 'Emballée',
		],
		'en' => [
			'synced'    => 'Synced',
			'delivered' => 'Delivered',
			'returned'  => 'Returned',
			'confirmed' => 'Confirmed',
			'packed'    => 'Packed',
		],
		'ar' => [
			'synced'    => 'تم التحميل',
			'delivered' => 'تم التوصيل',
			'returned'  => 'مرتجعة',
			'confirmed' => 'مؤكدة',
			'packed'    => 'معبأة',
		],
	];

	/**
	 * Langue courante (préfixe fr/en/ar) ; FR par défaut.
	 * determine_locale() : langue de session (switch/profil utilisateur) sinon
	 * langue du site — "langue du système" par défaut de WordPress.
	 */
	public static function current_language(): string {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$prefix = $locale ? strtolower( substr( (string) $locale, 0, 2 ) ) : 'fr';
		return array_key_exists( $prefix, self::LABEL_DEFAULTS ) ? $prefix : 'fr';
	}

	/**
	 * Libellé personnalisé d'un statut pour une langue donnée.
	 * '' si vide. FR : options héritées (champs uniques historiques).
	 *
	 * @param string $which 'synced' | 'delivered' | 'returned' | 'confirmed' | 'packed'.
	 * @param string $lang  'fr' | 'en' | 'ar'.
	 */
	public static function custom_label( string $which, string $lang ): string {
		$base = self::OPTION_LABELS[ $which ] ?? '';
		if ( '' === $base ) {
			return '';
		}
		if ( 'fr' === $lang ) {
			return trim( (string) get_option( $base, '' ) );
		}
		// Options par langue : suffixe _en / _ar.
		return trim( (string) get_option( $base . '_' . $lang, '' ) );
	}

	/**
	 * Libellé affiché d'un statut selon la langue du site :
	 * override réglage (langue courante) > défaut « vrai nom » de la langue > FR.
	 *
	 * @param string $which 'synced' | 'delivered' | 'returned' | 'confirmed' | 'packed'.
	 */
	public static function label( string $which ): string {
		$lang  = self::current_language();
		$custom = self::custom_label( $which, $lang );
		if ( '' !== $custom ) {
			return $custom;
		}
		return self::LABEL_DEFAULTS[ $lang ][ $which ]
			?? self::LABEL_DEFAULTS['fr'][ $which ]
			?? $which;
	}

	/**
	 * Libellé traduit d'un statut WooCommerce quelconque (wc-xxx).
	 * Utilisé pour les notes de transition (« Terminée », « Livrée »…).
	 */
	public static function wc_status_label( string $status ): string {
		$all = wc_get_order_statuses();
		return $all[ $status ] ?? $status;
	}

	// ─────────────────────────────────────────────
	//  Enregistrement des statuts
	// ─────────────────────────────────────────────

	/**
	 * Enregistre les 5 statuts du plugin auprès de WordPress (post_status).
	 */
	public function register_statuses(): void {
		register_post_status( self::STATUS_SYNCED, [
			'label'                     => self::label( 'synced' ),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: nombre de commandes. */
			'label_count'               => _n_noop(
				self::label( 'synced' ) . ' <span class="count">(%s)</span>',
				self::label( 'synced' ) . ' <span class="count">(%s)</span>',
				'wc-multicarrier-sync'
			),
		] );

		register_post_status( self::STATUS_DELIVERED, [
			'label'                     => self::label( 'delivered' ),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: nombre de commandes. */
			'label_count'               => _n_noop(
				self::label( 'delivered' ) . ' <span class="count">(%s)</span>',
				self::label( 'delivered' ) . ' <span class="count">(%s)</span>',
				'wc-multicarrier-sync'
			),
		] );

		register_post_status( self::STATUS_RETURNED, [
			'label'                     => self::label( 'returned' ),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: nombre de commandes. */
			'label_count'               => _n_noop(
				self::label( 'returned' ) . ' <span class="count">(%s)</span>',
				self::label( 'returned' ) . ' <span class="count">(%s)</span>',
				'wc-multicarrier-sync'
			),
		] );

		register_post_status( self::STATUS_CONFIRMED, [
			'label'                     => self::label( 'confirmed' ),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: nombre de commandes. */
			'label_count'               => _n_noop(
				self::label( 'confirmed' ) . ' <span class="count">(%s)</span>',
				self::label( 'confirmed' ) . ' <span class="count">(%s)</span>',
				'wc-multicarrier-sync'
			),
		] );

		register_post_status( self::STATUS_PACKED, [
			'label'                     => self::label( 'packed' ),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: nombre de commandes. */
			'label_count'               => _n_noop(
				self::label( 'packed' ) . ' <span class="count">(%s)</span>',
				self::label( 'packed' ) . ' <span class="count">(%s)</span>',
				'wc-multicarrier-sync'
			),
		] );
	}

	/**
	 * Injecte les 5 statuts dans la liste WooCommerce (dropdowns, filtres,
	 * actions groupées, quick edit, REST).
	 *
	 * @param array<string,string> $statuses Statuts WC (clé wc-xxx => libellé).
	 * @return array<string,string>
	 */
	public function add_statuses( array $statuses ): array {
		$ours = [
			self::STATUS_SYNCED    => self::label( 'synced' ),
			self::STATUS_DELIVERED => self::label( 'delivered' ),
			self::STATUS_RETURNED  => self::label( 'returned' ),
			self::STATUS_CONFIRMED => self::label( 'confirmed' ),
			self::STATUS_PACKED    => self::label( 'packed' ),
		];

		foreach ( $ours as $key => $value ) {
			if ( ! isset( $statuses[ $key ] ) ) {
				$statuses[ $key ] = $value;
			}
		}

		return $statuses;
	}

	/**
	 * Couleur de badge d'un statut : réglage client > défaut.
	 *
	 * @param string $key Statut sans préfixe wc- (ex. 'processing', 'wcmcs-synced').
	 * @return string Hex ('' si le statut n'est pas colorisable).
	 */
	public static function status_color( string $key ): string {
		if ( ! array_key_exists( $key, self::COLOR_DEFAULTS ) ) {
			return '';
		}
		$opt   = self::OPT_COLOR . $key;
		$value = trim( (string) get_option( $opt, '' ) );
		return '' !== $value && (bool) sanitize_hex_color( $value ) ? sanitize_hex_color( $value ) : self::COLOR_DEFAULTS[ $key ];
	}

	/**
	 * Carte complet des couleurs de badge (clés sans préfixe wc-), utilisée
	 * par le CSS admin et le JS (modal scan).
	 *
	 * @return array<string,string>
	 */
	public static function status_colors(): array {
		$out = [];
		foreach ( array_keys( self::COLOR_DEFAULTS ) as $key ) {
			$out[ $key ] = self::status_color( $key );
		}
		return $out;
	}

	/**
	 * Texte lisible (#1e1e1e ou #fff) sur un fond hex : contraste YIQ.
	 */
	public static function text_for_color( string $hex ): string {
		$h = ltrim( $hex, '#' );
		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $h ) ) {
			return '#1e1e1e';
		}
		$yiq = ( 299 * hexdec( substr( $h, 0, 2 ) ) + 587 * hexdec( substr( $h, 2, 2 ) ) + 114 * hexdec( substr( $h, 4, 2 ) ) ) / 1000;
		return $yiq >= 150 ? '#1e1e1e' : '#ffffff';
	}

	/**
	 * Badges colorés dans la liste des commandes (CSS admin).
	 * Couleurs choisies par le client (onglet Statuts) avec texte auto-contraste.
	 */
	public function admin_status_css(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, [ 'shop_order', 'shop_subscription' ], true ) ) {
			return;
		}
		$css   = '';
		$sel   = [];
		foreach ( self::status_colors() as $key => $hex ) {
			if ( '' === $hex ) {
				continue;
			}
		// Même gabarit que le badge sync de la colonne Sync
			// (class-order-meta.php get_status_badge : padding 2px 8px,
			// rayon 3px, 11px/600, line-height 1.7) — il faut AUSSI écraser
			// le gabarit natif WooCommerce (admin.scss : line-height 2.5em,
			// marges internes du span 0 1em, bord-bottom, margin -0.25em)
			// car sinon la pilule native reste haute et espacée.
			$css .= sprintf(
				'.order-status.status-%1$s, .fulfillment-status.status-%1$s { background: %2$s; color: %3$s; padding: 2px 8px; border-radius: 32px; border-bottom: none; font-size: 11px; font-weight: 600; margin: 0; line-height: 1.7; }' . "\n" .
				'.order-status.status-%1$s > span, .fulfillment-status.status-%1$s > span { margin: 0; font-size: 11px; font-weight: 600; }' . "\n",
				esc_attr( $key ),
				esc_attr( $hex ),
				esc_attr( self::text_for_color( $hex ) )
			);
			$sel[] = '.order-status.status-' . $key;
			$sel[] = '.fulfillment-status.status-' . $key;
		}
		?>
		<style type="text/css">
			<?php echo $css; // phpcs:ignore WordPress.Security.EscapeOutput -- Escape via sprintf/esc_static ci-dessus. ?>
			<?php echo esc_attr( implode( ', ', $sel ) ); ?> { pointer-events: inherit; }
		</style>
		<?php
	}

	// ─────────────────────────────────────────────
	//  Transitions automatiques
	// ─────────────────────────────────────────────

	/**
	 * Sync réussie → statut « Téléchargée » (si activé + statut non terminal).
	 *
	 * @param WC_Order $order        Commande.
	 * @param array    $result       Résultat sync (tracking, label…).
	 * @param string   $carrier_slug Slug transporteur.
	 */
	public function on_order_synced( $order, array $result, string $carrier_slug ): void {
		$target = self::target_after_sync();
		if ( '' === $target || ! $order instanceof WC_Order ) {
			return;
		}

		// Ne jamais écraser un statut terminal / manuel (completed, cancelled…).
		if ( ! in_array( $order->get_status(), self::ELIGIBLE_FOR_SYNCED, true ) ) {
			return;
		}

		$target_label = 'wc-wcmcs-synced' === $target ? self::label( 'synced' ) : wc_get_order_status_name( $target );

		$order->update_status(
			$target,
			sprintf(
				/* translators: 1: transporteur, 2: libellé du statut */
				__( '[WCMCS] Sync réussi via %1$s — statut commande : « %2$s ».', 'wc-multicarrier-sync' ),
				strtoupper( $carrier_slug ),
				$target_label
			)
		);

		WCMCS_Logger::info( sprintf(
			'Statut commande #%d → %s (sync %s).',
			$order->get_id(),
			$target_label,
			$carrier_slug
		) );
	}

	/**
	 * Polling : ajoute les nouveaux statuts non terminaux aux candidats.
	 *
	 * « Téléchargée » : la commande vient d'être synchronisée — le suivi
	 * transporteur doit continuer (sinon plus jamais de détection « Livrée »).
	 * « Retournée » : un colis retourné peut être réexpédié.
	 * « Livrée » : terminal — jamais ajouté (comme « Terminée » aujourd'hui).
	 *
	 * @param string[] $statuses Statuts éligibles au polling.
	 * @return string[]
	 */
	public function poll_statuses( array $statuses ): array {
		if ( self::synced_enabled() && ! in_array( self::STATUS_SYNCED, $statuses, true ) ) {
			$statuses[] = self::STATUS_SYNCED;
		}
		if ( self::returned_enabled() && ! in_array( self::STATUS_RETURNED, $statuses, true ) ) {
			$statuses[] = self::STATUS_RETURNED;
		}
		if ( ! in_array( self::STATUS_CONFIRMED, $statuses, true ) ) {
			$statuses[] = self::STATUS_CONFIRMED;
		}
		if ( ! in_array( self::STATUS_PACKED, $statuses, true ) ) {
			$statuses[] = self::STATUS_PACKED;
		}
		return $statuses;
	}
}
