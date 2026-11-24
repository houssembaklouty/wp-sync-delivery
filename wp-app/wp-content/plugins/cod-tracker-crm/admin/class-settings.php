<?php
defined( 'ABSPATH' ) || exit;

/**
 * Page « Suivi COD → Réglages », organisée en onglets. Chaque onglet a son
 * propre formulaire : l'enregistrement ne modifie QUE les réglages de l'onglet.
 */
class CODCRM_Settings {

	const PAGE = 'codcrm-settings';

	const DAYS = [ 1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche' ];

	const COND_TYPES = [ 'product', 'category', 'state', 'carrier', 'total' ];

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 20 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'admin_post_codcrm_save_settings', [ __CLASS__, 'save' ] );
		add_action( 'admin_post_codcrm_save_rule', [ __CLASS__, 'save_rule' ] );
		add_action( 'admin_post_codcrm_rule_action', [ __CLASS__, 'rule_action' ] );
		add_action( 'admin_post_codcrm_dispatch_now', [ __CLASS__, 'dispatch_now' ] );
	}

	public static function menu(): void {
		add_submenu_page( CODCRM_Work_Queue::PAGE_QUEUE, __( 'Réglages', 'cod-tracker-crm' ), __( 'Réglages', 'cod-tracker-crm' ), CODCRM_Agents::CAP_MANAGE, self::PAGE, [ __CLASS__, 'render' ] );
	}

	public static function tabs(): array {
		return [
			'general'    => __( 'Général', 'cod-tracker-crm' ),
			'repartition' => __( 'Répartition & agents', 'cod-tracker-crm' ),
			'rules'      => __( 'Règles d\'attribution', 'cod-tracker-crm' ),
			'sms'        => __( 'SMS', 'cod-tracker-crm' ),
			'messages'   => __( 'Messages', 'cod-tracker-crm' ),
			'colors'     => __( 'Couleurs', 'cod-tracker-crm' ),
		];
	}

	private static function current_tab(): string {
		$t = sanitize_key( wp_unslash( $_GET['tab'] ?? 'general' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		return isset( self::tabs()[ $t ] ) ? $t : 'general';
	}

	private static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::PAGE ], $args ), admin_url( 'admin.php' ) );
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE ) ) {
			return;
		}
		$v = static function ( string $rel ) {
			return CODCRM_URL . $rel . '?v=' . CODCRM_VERSION . '-' . (int) @filemtime( CODCRM_DIR . $rel );
		};
		wp_enqueue_style( 'codcrm-settings', $v( 'assets/css/settings.css' ), [], null );
		// Sélecteurs WooCommerce (recherche de produits, select2) : chargés d'avance, car la
		// navigation entre onglets se fait sans rechargement de la page.
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'codcrm-settings', $v( 'assets/js/settings.js' ), [ 'jquery' ], null, true );
	}

	// ── Rendu ──────────────────────────────────────────────────

	public static function render(): void {
		if ( ! current_user_can( CODCRM_Agents::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'cod-tracker-crm' ) );
		}
		$tab = self::current_tab();

		echo '<div class="wrap codcrm-settings"><h1>' . esc_html__( 'Suivi COD — réglages', 'cod-tracker-crm' ) . '</h1>';
		self::notices();

		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::tabs() as $k => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::url( [ 'tab' => $k ] ) ), $k === $tab ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav><div class="codcrm-tab">';

		switch ( $tab ) {
			case 'repartition':
				self::tab_repartition();
				break;
			case 'rules':
				isset( $_GET['rule'] ) ? self::rule_form( sanitize_key( wp_unslash( $_GET['rule'] ) ) ) : self::tab_rules(); // phpcs:ignore WordPress.Security.NonceVerification
				break;
			case 'sms':
				self::tab_sms();
				break;
			case 'messages':
				self::tab_messages();
				break;
			case 'colors':
				self::tab_colors();
				break;
			default:
				self::tab_general();
		}
		echo '</div></div>';
	}

	private static function notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Réglages enregistrés.', 'cod-tracker-crm' ) . '</p></div>';
		}
		if ( isset( $_GET['dropped'] ) && absint( $_GET['dropped'] ) ) {
			printf( '<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html( sprintf( __( '%d plage(s) horaire(s) invalide(s) ignorée(s) (format HH:MM, début avant la fin, sans chevauchement).', 'cod-tracker-crm' ), absint( $_GET['dropped'] ) ) ) );
		}
		if ( isset( $_GET['dispatched'] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( __( '%d commande(s) répartie(s).', 'cod-tracker-crm' ), absint( $_GET['dispatched'] ) ) ) );
		}
		if ( isset( $_GET['err'] ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( sanitize_text_field( rawurldecode( wp_unslash( $_GET['err'] ) ) ) ) );
		}
		// phpcs:enable
	}

	private static function form_open( string $tab ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="codcrm_save_settings" /><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';
		wp_nonce_field( 'codcrm_settings_' . $tab );
	}

	private static function form_close(): void {
		submit_button();
		echo '</form>';
	}

	private static function tab_general(): void {
		self::form_open( 'general' );
		echo '<table class="form-table">';
		printf( '<tr><th>%s</th><td><input type="number" min="1" max="10" name="threshold" value="%d" class="small-text" /> <span class="description">%s</span></td></tr>',
			esc_html__( 'Seuil de tentatives', 'cod-tracker-crm' ), CODCRM_Attempts::threshold(), esc_html__( 'Échecs de livraison avant « risque de retour » (défaut 3).', 'cod-tracker-crm' ) );
		printf( '<tr><th>%s</th><td><input type="number" min="1" max="30" name="stuck_days" value="%d" class="small-text" /> <span class="description">%s</span></td></tr>',
			esc_html__( 'Colis bloqué après', 'cod-tracker-crm' ), CODCRM_KPIs::stuck_days(), esc_html__( 'jours sans changement d\'étape (tableau de bord + tâche quotidienne).', 'cod-tracker-crm' ) );
		printf( '<tr><th>%s</th><td><input type="number" min="1" max="30" name="pickup_days" value="%d" class="small-text" /> <span class="description">%s</span></td></tr>',
			esc_html__( 'Enlèvement en attente après', 'cod-tracker-crm' ), CODCRM_KPIs::pickup_days(), esc_html__( 'jours sans enlèvement par la société de livraison.', 'cod-tracker-crm' ) );
		printf( '<tr><th>%s</th><td><input type="number" min="1" max="200" name="bulk_batch" value="%d" class="small-text" /> <span class="description">%s</span></td></tr>',
			esc_html__( 'Envois groupés : SMS par minute', 'cod-tracker-crm' ), CODCRM_Bulk::batch_size(), esc_html__( 'cadence de la file d\'attente (taille du lot traité chaque minute). Baissez-la si votre fournisseur SMS limite le débit.', 'cod-tracker-crm' ) );
		printf( '<tr><th>%s</th><td><input type="number" min="1" max="60" name="return_days" value="%d" class="small-text" /> <span class="description">%s</span></td></tr>',
			esc_html__( 'Alerte retour non reçu après', 'cod-tracker-crm' ), CODCRM_Returns::days(), esc_html__( 'jours après le retour définitif annoncé par le transporteur, si le colis n\'a pas été scanné « Retour reçu » (colis potentiellement perdu).', 'cod-tracker-crm' ) );
		printf( '<tr><th>%s</th><td><label><input type="checkbox" name="abandoned_enabled" value="yes"%s /> %s</label><br><span class="description">%s</span></td></tr>',
			esc_html__( 'Commandes abandonnées', 'cod-tracker-crm' ), checked( CODCRM_Abandoned::enabled(), true, false ), esc_html__( 'Enregistrer une commande « Abandonnée » dès qu\'un téléphone valide est saisi dans le formulaire COD', 'cod-tracker-crm' ), esc_html__( 'Si le client valide le formulaire, la commande abandonnée devient une vraie commande ; sinon un agent la rappelle.', 'cod-tracker-crm' ) );
		printf( '<tr><th>%s</th><td><label><input type="checkbox" name="form_restore" value="yes"%s /> %s</label><br><span class="description">%s</span></td></tr>',
			esc_html__( 'Restaurer le formulaire', 'cod-tracker-crm' ), checked( CODCRM_Abandoned::restore_enabled(), true, false ), esc_html__( 'Remplir à nouveau le formulaire COD si le client rafraîchit la page ou revient sur la fiche produit', 'cod-tracker-crm' ), esc_html__( 'Les informations restent dans le navigateur du client pendant 7 jours (coordonnées sur toutes les fiches ; quantité et variations sur le même produit) et sont effacées dès que la commande est validée.', 'cod-tracker-crm' ) );
		printf( '<tr><th>%s</th><td><input type="number" min="5" max="120" name="abandoned_delay" value="%d" class="small-text" /> <span class="description">%s</span></td></tr>',
			esc_html__( 'Abandonnée : attribuer à un agent après', 'cod-tracker-crm' ), CODCRM_Abandoned::delay(), esc_html__( 'minutes d\'inactivité (laisse au client le temps de finir de remplir le formulaire).', 'cod-tracker-crm' ) );
		printf( '<tr><th>%s</th><td><select name="cod_final_status"><option value="on-hold"%s>%s</option><option value="processing"%s>%s</option></select> <span class="description">%s</span></td></tr>',
			esc_html__( 'Statut d\'une commande validée du formulaire', 'cod-tracker-crm' ), selected( CODCRM_Abandoned::final_status( 'processing' ), 'on-hold', false ), esc_html__( 'En attente (confirmation, pas de paiement)', 'cod-tracker-crm' ), selected( CODCRM_Abandoned::final_status( 'processing' ), 'processing', false ), esc_html__( 'En cours (ancien comportement)', 'cod-tracker-crm' ), esc_html__( 'Statut donné quand le client clique sur « Acheter ».', 'cod-tracker-crm' ) );
		printf( '<tr><th>%s</th><td><input type="text" name="shop_phone" value="%s" class="regular-text" placeholder="71 000 000" /> <span class="description">%s</span></td></tr>',
			esc_html__( 'Téléphone de la boutique', 'cod-tracker-crm' ), esc_attr( (string) get_option( 'codcrm_shop_phone', '' ) ), esc_html__( 'Utilisé par le placeholder {telephone_boutique} dans les messages.', 'cod-tracker-crm' ) );
		echo '</table>';
		self::form_close();
	}

	private static function tab_repartition(): void {
		$cfg    = CODCRM_Dispatcher::agent_config();
		$agents = CODCRM_Agents::get_agents();
		$now    = CODCRM_Dispatcher::now();
		$mode   = CODCRM_Dispatcher::mode();
		$method = CODCRM_Dispatcher::method();

		self::form_open( 'repartition' );

		echo '<h2>' . esc_html__( 'Mode de répartition', 'cod-tracker-crm' ) . '</h2><div class="codcrm-choices">';
		printf( '<label class="codcrm-choice"><input type="radio" name="mode" value="auto"%s /> <strong>%s</strong><span>%s</span></label>', checked( 'auto', $mode, false ), esc_html__( 'Répartition automatique', 'cod-tracker-crm' ), esc_html__( 'Chaque nouvelle commande est attribuée à un agent en service, selon les règles puis la méthode ci-dessous.', 'cod-tracker-crm' ) );
		printf( '<label class="codcrm-choice"><input type="radio" name="mode" value="manual"%s /> <strong>%s</strong><span>%s</span></label>', checked( 'manual', $mode, false ), esc_html__( 'Attribution manuelle', 'cod-tracker-crm' ), esc_html__( 'Vous attribuez vous-même les commandes (liste des commandes ou fiche commande).', 'cod-tracker-crm' ) );
		echo '</div>';

		echo '<h2>' . esc_html__( 'Méthode (commandes sans règle correspondante)', 'cod-tracker-crm' ) . '</h2><div class="codcrm-choices">';
		printf( '<label class="codcrm-choice"><input type="radio" name="method" value="equal"%s /> <strong>%s</strong><span>%s</span></label>', checked( 'equal', $method, false ), esc_html__( 'Distribution équitable', 'cod-tracker-crm' ), esc_html__( 'Chaque commande va à l\'agent en service qui en a reçu le moins aujourd\'hui.', 'cod-tracker-crm' ) );
		printf( '<label class="codcrm-choice"><input type="radio" name="method" value="weighted"%s /> <strong>%s</strong><span>%s</span></label>', checked( 'weighted', $method, false ), esc_html__( 'Par pourcentage', 'cod-tracker-crm' ), esc_html__( 'Chaque agent reçoit sa part (ex. 70 % / 30 %) parmi les agents en service.', 'cod-tracker-crm' ) );
		echo '</div>';

		echo '<h2>' . esc_html__( 'Réattribution automatique', 'cod-tracker-crm' ) . '</h2><table class="form-table">';
		printf( '<tr><th>%s</th><td><label><input type="checkbox" name="reassign_enabled" value="yes"%s /> %s</label></td></tr>',
			esc_html__( 'Activer', 'cod-tracker-crm' ), checked( CODCRM_Dispatcher::reassign_enabled(), true, false ), esc_html__( 'Si l\'agent n\'a ni confirmé la commande ni contacté le client dans le délai, elle passe à un autre agent en service.', 'cod-tracker-crm' ) );
		printf( '<tr><th>%s</th><td><input type="number" min="5" max="1440" name="reassign_minutes" value="%d" class="small-text" /> %s</td></tr>',
			esc_html__( 'Délai', 'cod-tracker-crm' ), CODCRM_Dispatcher::reassign_minutes(), esc_html__( 'minutes (défaut 30)', 'cod-tracker-crm' ) );
		printf( '<tr><th>%s</th><td><input type="number" min="1" max="10" name="reassign_max" value="%d" class="small-text" /> %s</td></tr>',
			esc_html__( 'Maximum', 'cod-tracker-crm' ), CODCRM_Dispatcher::reassign_max(), esc_html__( 'réattribution(s) par commande. Les attributions manuelles ne sont jamais réattribuées.', 'cod-tracker-crm' ) );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Agents : activité, pourcentage et horaires de travail', 'cod-tracker-crm' ) . '</h2>';
		if ( ! $agents ) {
			echo '<p><em>' . esc_html__( 'Aucun agent : créez des utilisateurs avec le rôle « Agent suivi COD » (Utilisateurs → Ajouter).', 'cod-tracker-crm' ) . '</em></p>';
		}
		$total_w = 0;
		foreach ( $agents as $u ) {
			$id  = (int) $u->ID;
			$c   = $cfg[ $id ];
			$on  = CODCRM_Dispatcher::on_duty( $id, $now, $cfg );
			$total_w += $c['active'] ? $c['weight'] : 0;

			echo '<div class="codcrm-agent">';
			printf( '<div class="codcrm-agent-head"><label><input type="checkbox" name="agents[%1$d][active]" value="1"%2$s /> <strong>%3$s</strong></label> <span class="codcrm-pill" style="background:%4$s">%5$s</span>',
				$id, checked( $c['active'], true, false ), esc_html( $u->display_name ), $on ? '#00a651' : '#8c8f94', esc_html( $on ? __( 'En service', 'cod-tracker-crm' ) : __( 'Hors service', 'cod-tracker-crm' ) ) );
			printf( ' <label class="codcrm-weight">%s <input type="number" min="0" max="100" name="agents[%d][weight]" value="%d" class="small-text codcrm-w" /> %%</label></div>', esc_html__( 'Part', 'cod-tracker-crm' ), $id, $c['weight'] );

			echo '<table class="codcrm-schedule"><tbody>';
			foreach ( self::DAYS as $d => $dname ) {
				$ranges = (array) ( $c['days'][ $d ] ?? [] );
				printf( '<tr data-day="%d"><th>%s</th>', $d, esc_html( $dname ) );
				for ( $i = 0; $i < 2; $i++ ) {
					printf( '<td><input type="time" name="agents[%1$d][days][%2$d][%3$d][0]" value="%4$s" /> → <input type="time" name="agents[%1$d][days][%2$d][%3$d][1]" value="%5$s" /></td>',
						$id, $d, $i, esc_attr( $ranges[ $i ][0] ?? '' ), esc_attr( $ranges[ $i ][1] ?? '' ) );
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '<p class="codcrm-agent-tools"><button type="button" class="button button-small codcrm-copy-monday">' . esc_html__( 'Copier le lundi sur toute la semaine', 'cod-tracker-crm' ) . '</button> <button type="button" class="button button-small codcrm-copy-weekdays">' . esc_html__( 'Copier le lundi du mardi au samedi', 'cod-tracker-crm' ) . '</button> <span class="description">' . esc_html__( 'Jour vide = repos. Aucun horaire du tout = toujours en service.', 'cod-tracker-crm' ) . '</span></p>';
			echo '</div>';
		}
		if ( $agents ) {
			printf( '<p class="codcrm-total-w" data-total="%d">%s <strong class="codcrm-total-v">%d %%</strong> <span class="codcrm-total-warn">%s</span></p>',
				$total_w, esc_html__( 'Total des parts (agents actifs) :', 'cod-tracker-crm' ), $total_w, esc_html__( '— utilisé seulement en mode « Par pourcentage » ; idéalement 100 %.', 'cod-tracker-crm' ) );
		}
		self::form_close();

		// File d'attente.
		$waiting = wc_get_orders( [ 'limit' => 1, 'paginate' => true, 'return' => 'ids', 'type' => 'shop_order', 'meta_query' => [ [ 'key' => CODCRM_Dispatcher::META_WAITING, 'value' => '1' ] ] ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$n       = is_object( $waiting ) ? (int) $waiting->total : 0;
		echo '<h2>' . esc_html__( 'Commandes en attente d\'un agent', 'cod-tracker-crm' ) . '</h2>';
		printf( '<p>%s</p>', esc_html( sprintf( _n( '%d commande attend qu\'un agent concerné prenne son service (répartition automatique toutes les 5 minutes).', '%d commandes attendent qu\'un agent concerné prenne son service (répartition automatique toutes les 5 minutes).', $n, 'cod-tracker-crm' ), $n ) ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="codcrm_dispatch_now" />';
		wp_nonce_field( 'codcrm_dispatch_now' );
		submit_button( __( 'Répartir maintenant', 'cod-tracker-crm' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	private static function tab_rules(): void {
		$rules  = CODCRM_Dispatcher::rules();
		echo '<p>' . esc_html__( 'Les règles sont évaluées par priorité croissante ; la première qui correspond attribue la commande à ses membres (répartis équitablement parmi ceux en service). Si aucune règle ne correspond, la méthode de l\'onglet « Répartition » s\'applique.', 'cod-tracker-crm' ) . '</p>';
		printf( '<p><a class="button button-primary" href="%s">+ %s</a></p>', esc_url( self::url( [ 'tab' => 'rules', 'rule' => 'new' ] ) ), esc_html__( 'Créer une nouvelle règle de répartition', 'cod-tracker-crm' ) );

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Priorité', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Nom', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Conditions', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Membres', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'État', 'cod-tracker-crm' ) . '</th><th></th></tr></thead><tbody>';
		if ( ! $rules ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Aucune règle : toutes les commandes suivent la méthode globale.', 'cod-tracker-crm' ) . '</td></tr>';
		}
		foreach ( $rules as $r ) {
			$id      = (int) $r['id'];
			$members = array_filter( array_map( static function ( $a ) { return CODCRM_Agents::agent_name( (int) $a ); }, (array) $r['agents'] ) );
			$act     = static function ( string $do ) use ( $id ) {
				return wp_nonce_url( add_query_arg( [ 'action' => 'codcrm_rule_action', 'do' => $do, 'rule' => $id ], admin_url( 'admin-post.php' ) ), 'codcrm_rule_' . $id );
			};
			printf( '<tr><td>%d</td><td><strong><a href="%s">%s</a></strong></td><td>%s</td><td>%s</td><td><span class="codcrm-pill" style="background:%s">%s</span></td><td class="codcrm-rule-actions"><a href="%s">%s</a> · <a href="%s">%s</a> · <a href="%s">%s</a> · <a href="%s" class="codcrm-del">%s</a></td></tr>',
				(int) $r['priority'],
				esc_url( self::url( [ 'tab' => 'rules', 'rule' => $id ] ) ), esc_html( $r['name'] ),
				esc_html( self::rule_summary( $r ) ),
				esc_html( implode( ', ', $members ) ?: '—' ),
				! empty( $r['active'] ) ? '#00a651' : '#8c8f94', esc_html( ! empty( $r['active'] ) ? __( 'Active', 'cod-tracker-crm' ) : __( 'Inactive', 'cod-tracker-crm' ) ),
				esc_url( self::url( [ 'tab' => 'rules', 'rule' => $id ] ) ), esc_html__( 'Modifier', 'cod-tracker-crm' ),
				esc_url( $act( 'toggle' ) ), esc_html( ! empty( $r['active'] ) ? __( 'Désactiver', 'cod-tracker-crm' ) : __( 'Activer', 'cod-tracker-crm' ) ),
				esc_url( $act( 'duplicate' ) ), esc_html__( 'Dupliquer', 'cod-tracker-crm' ),
				esc_url( $act( 'delete' ) ), esc_html__( 'Supprimer', 'cod-tracker-crm' )
			);
		}
		echo '</tbody></table>';
	}

	private static function type_labels(): array {
		return [
			'product'  => __( 'Produit', 'cod-tracker-crm' ),
			'category' => __( 'Catégorie de produit', 'cod-tracker-crm' ),
			'state'    => __( 'Gouvernorat', 'cod-tracker-crm' ),
			'carrier'  => __( 'Transporteur', 'cod-tracker-crm' ),
			'total'    => __( 'Montant de la commande', 'cod-tracker-crm' ),
		];
	}

	private static function states(): array {
		return function_exists( 'WC' ) && WC()->countries ? (array) WC()->countries->get_states( 'TN' ) : [];
	}

	private static function carriers(): array {
		$out = [];
		if ( class_exists( 'WCMCS_Carrier_Detector' ) ) {
			foreach ( (array) WCMCS_Carrier_Detector::get_enabled_providers() as $p ) {
				if ( is_object( $p ) && method_exists( $p, 'get_slug' ) ) {
					$out[ $p->get_slug() ] = method_exists( $p, 'get_label' ) ? $p->get_label() : $p->get_slug();
				}
			}
		}
		return $out;
	}

	private static function categories(): array {
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		$out   = [];
		foreach ( is_array( $terms ) ? $terms : [] as $t ) {
			$out[ (int) $t->term_id ] = $t->name;
		}
		return $out;
	}

	public static function rule_summary( array $r ): string {
		$labels = self::type_labels();
		$parts  = [];
		foreach ( (array) $r['conditions'] as $c ) {
			$vals = (array) ( $c['values'] ?? [] );
			switch ( $c['type'] ) {
				case 'product':
					$vals = array_map( static function ( $id ) { $p = wc_get_product( (int) $id ); return $p ? $p->get_name() : '#' . $id; }, $vals );
					break;
				case 'category':
					$cats = self::categories();
					$vals = array_map( static function ( $id ) use ( $cats ) { return $cats[ (int) $id ] ?? '#' . $id; }, $vals );
					break;
				case 'state':
					$st   = self::states();
					$vals = array_map( static function ( $s ) use ( $st ) { return $st[ $s ] ?? $s; }, $vals );
					break;
				case 'total':
					$vals = [ trim( ( '' !== ( $c['min'] ?? '' ) ? '≥ ' . $c['min'] : '' ) . ' ' . ( '' !== ( $c['max'] ?? '' ) ? '≤ ' . $c['max'] : '' ) ) . ' DT' ];
					break;
			}
			$parts[] = ( $labels[ $c['type'] ] ?? $c['type'] ) . ' : ' . implode( ' ou ', $vals );
		}
		return implode( ' ET ', $parts );
	}

	/** Une ligne de condition (aussi utilisée comme gabarit JS avec l'index __i__). */
	private static function condition_row( $i, array $c ): string {
		$type   = $c['type'] ?? 'product';
		$vals   = array_map( 'strval', (array) ( $c['values'] ?? [] ) );
		$name   = static function ( string $f ) use ( $i ) { return 'conds[' . $i . '][' . $f . ']'; };
		$opts   = static function ( array $all, array $sel ) {
			$h = '';
			foreach ( $all as $k => $label ) {
				$h .= '<option value="' . esc_attr( (string) $k ) . '"' . ( in_array( (string) $k, $sel, true ) ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
			}
			return $h;
		};

		$h  = '<div class="codcrm-cond" data-type="' . esc_attr( $type ) . '">';
		$h .= '<label>' . esc_html__( 'Type', 'cod-tracker-crm' ) . ' <select class="codcrm-cond-type" name="' . esc_attr( $name( 'type' ) ) . '">';
		foreach ( self::type_labels() as $k => $label ) {
			$h .= '<option value="' . esc_attr( $k ) . '"' . selected( $type, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$h .= '</select></label>';

		// Produits : recherche AJAX WooCommerce (select2).
		$prod = '';
		foreach ( 'product' === $type ? $vals : [] as $pid ) {
			$p = wc_get_product( (int) $pid );
			if ( $p ) {
				$prod .= '<option value="' . (int) $pid . '" selected>' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
			}
		}
		$h .= '<div class="codcrm-w-product"><select class="wc-product-search" multiple style="width:100%" name="' . esc_attr( $name( 'product' ) ) . '[]" data-placeholder="' . esc_attr__( 'Rechercher un produit…', 'cod-tracker-crm' ) . '" data-action="woocommerce_json_search_products_and_variations">' . $prod . '</select></div>';
		$h .= '<div class="codcrm-w-category"><select class="wc-enhanced-select" multiple style="width:100%" name="' . esc_attr( $name( 'category' ) ) . '[]">' . $opts( self::categories(), 'category' === $type ? $vals : [] ) . '</select></div>';
		$h .= '<div class="codcrm-w-state"><select class="wc-enhanced-select" multiple style="width:100%" name="' . esc_attr( $name( 'state' ) ) . '[]">' . $opts( self::states(), 'state' === $type ? $vals : [] ) . '</select></div>';
		$h .= '<div class="codcrm-w-carrier"><select class="wc-enhanced-select" multiple style="width:100%" name="' . esc_attr( $name( 'carrier' ) ) . '[]">' . $opts( self::carriers(), 'carrier' === $type ? $vals : [] ) . '</select></div>';
		$h .= '<div class="codcrm-w-total">' . esc_html__( 'Entre', 'cod-tracker-crm' ) . ' <input type="number" step="0.001" min="0" name="' . esc_attr( $name( 'min' ) ) . '" value="' . esc_attr( 'total' === $type ? (string) ( $c['min'] ?? '' ) : '' ) . '" class="small-text" /> ' . esc_html__( 'et', 'cod-tracker-crm' ) . ' <input type="number" step="0.001" min="0" name="' . esc_attr( $name( 'max' ) ) . '" value="' . esc_attr( 'total' === $type ? (string) ( $c['max'] ?? '' ) : '' ) . '" class="small-text" /> DT <span class="description">' . esc_html__( '(un seul champ suffit)', 'cod-tracker-crm' ) . '</span></div>';
		$h .= '<button type="button" class="button-link codcrm-cond-del" aria-label="' . esc_attr__( 'Supprimer la condition', 'cod-tracker-crm' ) . '">✕</button>';
		$h .= '</div>';
		return $h;
	}

	private static function rule_form( string $rule_id ): void {
		$rule = [ 'id' => 0, 'name' => '', 'priority' => 10, 'active' => true, 'conditions' => [ [ 'type' => 'product', 'values' => [] ] ], 'agents' => [] ];
		if ( 'new' !== $rule_id ) {
			foreach ( CODCRM_Dispatcher::rules() as $r ) {
				if ( (int) $r['id'] === (int) $rule_id ) {
					$rule = $r;
				}
			}
		}

		printf( '<p><a href="%s">← %s</a></p><h2>%s</h2><p>%s</p>', esc_url( self::url( [ 'tab' => 'rules' ] ) ), esc_html__( 'Toutes les règles', 'cod-tracker-crm' ),
			esc_html( $rule['id'] ? __( 'Modifier la règle de répartition', 'cod-tracker-crm' ) : __( 'Créer une nouvelle règle de répartition', 'cod-tracker-crm' ) ),
			esc_html__( 'Définissez les conditions et le membre de l\'équipe qui traitera les commandes correspondantes.', 'cod-tracker-crm' ) );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="codcrm-rule-form"><input type="hidden" name="action" value="codcrm_save_rule" /><input type="hidden" name="rule_id" value="' . (int) $rule['id'] . '" />';
		wp_nonce_field( 'codcrm_save_rule' );

		echo '<div class="codcrm-box"><h3>1. ' . esc_html__( 'Informations de la règle', 'cod-tracker-crm' ) . '</h3><p class="description">' . esc_html__( 'Définissez les informations de base utilisées pour identifier et prioriser cette règle de répartition.', 'cod-tracker-crm' ) . '</p>';
		printf( '<p><label>%s *<br><input type="text" name="name" value="%s" class="regular-text" required maxlength="80" /></label></p>', esc_html__( 'Nom de la règle', 'cod-tracker-crm' ), esc_attr( $rule['name'] ) );
		printf( '<p><label>%s<br><input type="number" name="priority" value="%d" min="1" max="999" class="small-text" /></label> <span class="description">%s</span></p>', esc_html__( 'Priorité', 'cod-tracker-crm' ), (int) $rule['priority'], esc_html__( 'Plus petit = évalué en premier.', 'cod-tracker-crm' ) );
		printf( '<p><label><input type="checkbox" name="active" value="1"%s /> %s</label></p></div>', checked( ! empty( $rule['active'] ), true, false ), esc_html__( 'Règle active', 'cod-tracker-crm' ) );

		echo '<div class="codcrm-box"><h3>2. ' . esc_html__( 'Définir les conditions', 'cod-tracker-crm' ) . '</h3><p class="description">' . esc_html__( 'Les commandes correspondant aux conditions suivantes seront attribuées au membre de l\'équipe sélectionné. Les valeurs d\'une même condition se combinent avec OU ; des conditions distinctes se combinent avec ET. Pour exiger deux produits ensemble, ajoutez une condition de produit pour chacun.', 'cod-tracker-crm' ) . '</p>';
		echo '<div class="codcrm-conds">';
		foreach ( array_values( (array) $rule['conditions'] ) as $i => $c ) {
			echo self::condition_row( $i, $c ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans condition_row().
		}
		echo '</div><p class="description">' . esc_html__( 'Toutes les conditions doivent correspondre pour que la règle s\'applique.', 'cod-tracker-crm' ) . '</p>';
		echo '<p><button type="button" class="button codcrm-cond-add">+ ' . esc_html__( 'Ajouter une autre condition', 'cod-tracker-crm' ) . '</button></p>';
		echo '<script type="text/template" id="codcrm-cond-tpl">' . self::condition_row( '__i__', [ 'type' => 'product' ] ) . '</script></div>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<div class="codcrm-box"><h3>3. ' . esc_html__( 'Attribuer à', 'cod-tracker-crm' ) . '</h3><p class="description">' . esc_html__( 'Sélectionnez le ou les membres de l\'équipe qui traiteront les commandes correspondant à cette règle. Avec plusieurs membres, les commandes sont réparties équitablement entre ceux en service.', 'cod-tracker-crm' ) . '</p>';
		$agents = CODCRM_Agents::get_agents();
		if ( ! $agents ) {
			echo '<p><em>' . esc_html__( 'Aucun agent disponible.', 'cod-tracker-crm' ) . '</em></p>';
		}
		foreach ( $agents as $u ) {
			printf( '<label class="codcrm-member"><input type="checkbox" name="agents[]" value="%d"%s /> %s</label>', (int) $u->ID, checked( in_array( (int) $u->ID, array_map( 'intval', (array) $rule['agents'] ), true ), true, false ), esc_html( $u->display_name ) );
		}
		echo '</div>';

		submit_button( $rule['id'] ? __( 'Enregistrer la règle', 'cod-tracker-crm' ) : __( 'Créer la règle', 'cod-tracker-crm' ) );
		echo '</form>';
	}

	private static function tab_sms(): void {
		self::form_open( 'sms' );
		self::render_sms_settings();
		self::form_close();

		if ( CODCRM_SMS_Gateway::is_configured() ) {
			echo '<h2>' . esc_html__( 'Tester la passerelle', 'cod-tracker-crm' ) . '</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="codcrm_sms_test" />';
			wp_nonce_field( 'codcrm_sms_test' );
			echo '<input type="text" name="test_phone" placeholder="55123456" /> ';
			submit_button( __( 'Envoyer un SMS test', 'cod-tracker-crm' ), 'secondary', 'submit', false );
			echo '</form>';
		}
	}

	private static function render_sms_settings(): void {
		$o = static function ( string $k, $d = '' ) { return get_option( $k, $d ); };

		echo '<h2>' . esc_html__( 'Passerelle SMS', 'cod-tracker-crm' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Compatible avec tout fournisseur offrant une API HTTP. Placeholders : {to} {message} {sender} {api_key}.', 'cod-tracker-crm' ) . '</p>';
		if ( isset( $_GET['sms_test'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$r = sanitize_text_field( wp_unslash( $_GET['sms_test'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			printf( '<div class="notice %s"><p>%s</p></div>', 'ok' === $r ? 'notice-success' : 'notice-error', esc_html( 'ok' === $r ? __( 'SMS test envoyé.', 'cod-tracker-crm' ) : $r ) );
		}

		echo '<table class="form-table">';
		printf( '<tr><th>%s</th><td><label><input type="checkbox" name="sms_enabled" value="yes"%s /> %s</label></td></tr>', esc_html__( 'Activer', 'cod-tracker-crm' ), checked( 'yes', $o( 'codcrm_sms_enabled', 'no' ), false ), esc_html__( 'Autoriser l\'envoi de SMS', 'cod-tracker-crm' ) );
		printf( '<tr><th>URL</th><td><input type="url" class="large-text" name="sms_url" value="%s" placeholder="https://api.fournisseur.tn/sms/send" /></td></tr>', esc_attr( (string) $o( 'codcrm_sms_url' ) ) );
		printf( '<tr><th>%s</th><td><select name="sms_method"><option value="POST"%s>POST</option><option value="GET"%s>GET</option></select> <select name="sms_format"><option value="form"%s>form-urlencoded</option><option value="json"%s>JSON</option></select></td></tr>',
			esc_html__( 'Méthode / format', 'cod-tracker-crm' ),
			selected( 'POST', $o( 'codcrm_sms_method', 'POST' ), false ), selected( 'GET', $o( 'codcrm_sms_method', 'POST' ), false ),
			selected( 'form', $o( 'codcrm_sms_format', 'form' ), false ), selected( 'json', $o( 'codcrm_sms_format', 'form' ), false )
		);
		printf( '<tr><th>%s</th><td><textarea name="sms_params" rows="5" class="large-text code" placeholder="to={to}&#10;text={message}&#10;from={sender}">%s</textarea><p class="description">%s</p></td></tr>', esc_html__( 'Paramètres', 'cod-tracker-crm' ), esc_textarea( (string) $o( 'codcrm_sms_params' ) ), esc_html__( 'Un par ligne : clé=valeur.', 'cod-tracker-crm' ) );
		printf( '<tr><th>%s</th><td><textarea name="sms_headers" rows="2" class="large-text code" placeholder="Authorization: Bearer {api_key}">%s</textarea></td></tr>', esc_html__( 'En-têtes', 'cod-tracker-crm' ), esc_textarea( (string) $o( 'codcrm_sms_headers' ) ) );
		printf( '<tr><th>%s</th><td><input type="password" name="sms_api_key" value="" autocomplete="new-password" class="regular-text" placeholder="%s" /></td></tr>', esc_html__( 'Clé API / token', 'cod-tracker-crm' ), '' !== (string) $o( 'codcrm_sms_api_key' ) ? esc_attr__( '•••••• (laisser vide pour conserver)', 'cod-tracker-crm' ) : '' );
		printf( '<tr><th>%s</th><td><input type="text" name="sms_sender" value="%s" class="regular-text" /></td></tr>', esc_html__( 'Expéditeur', 'cod-tracker-crm' ), esc_attr( (string) $o( 'codcrm_sms_sender' ) ) );
		printf( '<tr><th>%s</th><td><select name="sms_phone"><option value="216"%s>216XXXXXXXX</option><option value="+216"%s>+216XXXXXXXX</option><option value="local"%s>XXXXXXXX</option></select></td></tr>',
			esc_html__( 'Format du numéro {to}', 'cod-tracker-crm' ),
			selected( '216', $o( 'codcrm_sms_phone', '216' ), false ), selected( '+216', $o( 'codcrm_sms_phone', '216' ), false ), selected( 'local', $o( 'codcrm_sms_phone', '216' ), false )
		);
		printf( '<tr><th>%s</th><td><input type="text" name="sms_success" value="%s" class="regular-text" /><p class="description">%s</p></td></tr>', esc_html__( 'Texte de succès', 'cod-tracker-crm' ), esc_attr( (string) $o( 'codcrm_sms_success' ) ), esc_html__( 'Texte à retrouver dans la réponse du fournisseur. Vide = HTTP 2xx suffit.', 'cod-tracker-crm' ) );

		echo '<tr><th>' . esc_html__( 'Envois automatiques', 'cod-tracker-crm' ) . '</th><td>';
		$auto = (array) $o( 'codcrm_sms_auto', [] );
		foreach ( CODCRM_SMS_Gateway::triggers() as $k => $label ) {
			printf( '<p><label><input type="checkbox" name="sms_auto[]" value="%1$s"%2$s /> <strong>%3$s</strong></label> <a href="%4$s">%5$s</a></p>',
				esc_attr( $k ), checked( in_array( $k, $auto, true ), true, false ), esc_html( $label ),
				esc_url( self::url( [ 'tab' => 'messages', 'ctx' => CODCRM_SMS_Gateway::trigger_context( $k ) ] ) ), esc_html__( 'modifier les textes FR / AR', 'cod-tracker-crm' ) );
		}
		echo '<p class="description">' . esc_html__( 'Désactivés par défaut. Un seul SMS par déclencheur et par tournée ; la langue (français / arabe) suit le nom et l\'adresse du client.', 'cod-tracker-crm' ) . '</p>';
		echo '</td></tr></table>';
	}

	/** Ligne d'un tableau de couleurs : aperçu, sélecteur, remise à zéro. */
	private static function color_row( string $name, string $label, string $current, string $default, string $note = '' ): void {
		printf(
			'<tr><td><strong>%1$s</strong>%2$s</td><td><span class="codcrm-color-chip" style="%3$s">%1$s</span></td><td><input type="color" class="codcrm-color" name="%4$s" value="%5$s" data-default="%6$s" /> <code class="codcrm-color-hex">%5$s</code></td><td><button type="button" class="button codcrm-color-reset" data-default="%6$s" title="%7$s">↺</button></td></tr>',
			esc_html( $label ),
			'' !== $note ? '<br><small class="description">' . esc_html( $note ) . '</small>' : '',
			esc_attr( CODCRM_Stages::badge_style( $current ) ),
			esc_attr( $name ),
			esc_attr( $current ),
			esc_attr( $default ),
			esc_attr__( 'Remettre la couleur par défaut', 'cod-tracker-crm' )
		);
	}

	private static function tab_colors(): void {
		self::form_open( 'colors' );
		echo '<p>' . esc_html__( 'Couleur des badges de statut dans le CRM (timeline, Mes suivis, tableau de bord, Cash). Les étapes qui existent aussi dans MultiCarrier Sync (Confirmée, Emballée, Colis créé, Livré, Retour définitif…) reprennent sa couleur et la suivent si vous la changez là-bas ; choisissez une couleur ici pour la remplacer. La couleur du texte (noir ou blanc) s\'adapte automatiquement.', 'cod-tracker-crm' ) . '</p>';

		echo '<h2>' . esc_html__( 'Étapes du colis', 'cod-tracker-crm' ) . '</h2>';
		echo '<table class="widefat striped codcrm-colors"><thead><tr><th>' . esc_html__( 'Étape', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Aperçu', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Couleur', 'cod-tracker-crm' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( CODCRM_Stages::all() as $code => $def ) {
			$note = ( CODCRM_Stages::follows_mcs( $code ) && '' === trim( (string) get_option( CODCRM_Stages::OPT_COLOR . $code, '' ) ) )
				? __( 'suit la couleur de MultiCarrier Sync', 'cod-tracker-crm' )
				: '';
			self::color_row( 'colors[stage][' . $code . ']', $def['label'], CODCRM_Stages::color( $code ), CODCRM_Stages::default_color( $code ), $note );
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Statuts du cash COD', 'cod-tracker-crm' ) . '</h2>';
		echo '<table class="widefat striped codcrm-colors"><thead><tr><th>' . esc_html__( 'Statut', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Aperçu', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Couleur', 'cod-tracker-crm' ) . '</th><th></th></tr></thead><tbody>';
		$defaults = CODCRM_Cash::default_statuses();
		foreach ( CODCRM_Cash::statuses() as $k => $def ) {
			self::color_row( 'colors[cash][' . $k . ']', $def[0], $def[1], $defaults[ $k ][1] );
		}
		echo '</tbody></table>';

		echo '<p><button type="button" class="button codcrm-color-reset-all">↺ ' . esc_html__( 'Tout remettre par défaut', 'cod-tracker-crm' ) . '</button> <span class="description">' . esc_html__( '(effectif après « Enregistrer »)', 'cod-tracker-crm' ) . '</span></p>';
		self::form_close();
	}

	private static function tab_messages(): void {
		$ctxs = CODCRM_Templates::contexts();
		$ctx  = sanitize_key( wp_unslash( $_GET['ctx'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $ctxs[ $ctx ] ) ) {
			$ctx = array_key_first( $ctxs );
		}

		echo '<p>' . esc_html__( 'Bibliothèque de messages envoyés par les agents (bouton « Message ») et par les SMS automatiques : une situation, deux langues, deux canaux. Chaque texte est modifiable ; « Rétablir » revient au texte d\'origine.', 'cod-tracker-crm' ) . '</p>';

		echo '<div class="codcrm-ctx-list">';
		foreach ( $ctxs as $k => $label ) {
			$custom = false;
			foreach ( CODCRM_Templates::LANGS as $l ) {
				foreach ( CODCRM_Templates::CHANNELS as $c ) {
					$custom = $custom || CODCRM_Templates::is_overridden( $k, $l, $c );
				}
			}
			printf( '<a class="codcrm-ctx%s" href="%s">%s%s</a>', $k === $ctx ? ' is-active' : '', esc_url( self::url( [ 'tab' => 'messages', 'ctx' => $k ] ) ), esc_html( $label ), $custom ? ' <span title="' . esc_attr__( 'Texte personnalisé', 'cod-tracker-crm' ) . '">✎</span>' : '' );
		}
		echo '</div>';

		self::form_open( 'messages' );
		printf( '<input type="hidden" name="ctx" value="%s" /><h2>%s</h2>', esc_attr( $ctx ), esc_html( $ctxs[ $ctx ] ) );
		echo '<p class="description">' . esc_html( sprintf( __( 'Placeholders : %s', 'cod-tracker-crm' ), implode( ' ', CODCRM_Templates::PLACEHOLDERS ) ) ) . '</p>';

		$labels = [ 'fr' => 'Français', 'ar' => 'العربية' ];
		$chan   = [ 'whatsapp' => 'WhatsApp', 'sms' => 'SMS' ];
		echo '<div class="codcrm-tpl-grid">';
		foreach ( CODCRM_Templates::LANGS as $lang ) {
			foreach ( CODCRM_Templates::CHANNELS as $ch ) {
				$over = CODCRM_Templates::is_overridden( $ctx, $lang, $ch );
				printf(
					'<div class="codcrm-tpl-box"><h3>%1$s — %2$s%3$s</h3><textarea name="tpl[%4$s][%5$s]" rows="%6$d" class="large-text codcrm-tpl-text" data-ch="%5$s" maxlength="%7$d"%8$s>%9$s</textarea><div class="codcrm-tpl-foot"><span class="codcrm-tpl-count"></span> <label><input type="checkbox" name="reset[%4$s][%5$s]" value="1"%10$s /> %11$s</label></div></div>',
					esc_html( $chan[ $ch ] ), esc_html( $labels[ $lang ] ), $over ? ' <span class="codcrm-pill" style="background:#2271b1">' . esc_html__( 'personnalisé', 'cod-tracker-crm' ) . '</span>' : '',
					esc_attr( $lang ), esc_attr( $ch ), 'sms' === $ch ? 4 : 7, (int) CODCRM_Templates::MAX_LEN[ $ch ],
					'ar' === $lang ? ' dir="rtl" lang="ar"' : '',
					esc_textarea( CODCRM_Templates::get( $ctx, $lang, $ch ) ),
					$over ? '' : ' disabled', esc_html__( 'Rétablir le texte par défaut', 'cod-tracker-crm' )
				);
			}
		}
		echo '</div>';
		self::form_close();
	}
	// ── Enregistrement ─────────────────────────────────────────

	private static function guard( string $nonce ): void {
		if ( ! current_user_can( CODCRM_Agents::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'cod-tracker-crm' ), 403 );
		}
		check_admin_referer( $nonce );
	}

	/** Enregistre UNIQUEMENT les réglages de l'onglet envoyé. */
	public static function save(): void {
		$tab = sanitize_key( wp_unslash( $_POST['tab'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( self::tabs()[ $tab ] ) || 'rules' === $tab ) {
			wp_die( esc_html__( 'Onglet invalide.', 'cod-tracker-crm' ), 400 );
		}
		self::guard( 'codcrm_settings_' . $tab );
		$extra = [];

		switch ( $tab ) {
			case 'general':
				update_option( 'codcrm_attempts_threshold', max( 1, min( 10, absint( $_POST['threshold'] ?? 3 ) ) ) );
				update_option( 'codcrm_stuck_days', max( 1, min( 30, absint( $_POST['stuck_days'] ?? 3 ) ) ) );
				update_option( 'codcrm_pickup_days', max( 1, min( 30, absint( $_POST['pickup_days'] ?? 2 ) ) ) );
				update_option( 'codcrm_bulk_batch', max( 1, min( 200, absint( $_POST['bulk_batch'] ?? 20 ) ) ) );
				update_option( 'codcrm_return_days', max( 1, min( 60, absint( $_POST['return_days'] ?? 5 ) ) ) );
				CODCRM_Returns::recount();
				$was = CODCRM_Abandoned::enabled();
				update_option( 'codcrm_abandoned_enabled', ! empty( $_POST['abandoned_enabled'] ) ? 'yes' : 'no' );
				$was_restore = CODCRM_Abandoned::restore_enabled();
				update_option( 'codcrm_form_restore', ! empty( $_POST['form_restore'] ) ? 'yes' : 'no' );
				if ( $was !== CODCRM_Abandoned::enabled() || $was_restore !== CODCRM_Abandoned::restore_enabled() ) {
					CODCRM_Abandoned::purge_page_cache(); // les fiches produit en cache doivent charger / retirer le script.
				}
				update_option( 'codcrm_abandoned_delay', max( 5, min( 120, absint( $_POST['abandoned_delay'] ?? 15 ) ) ) );
				update_option( 'codcrm_cod_final_status', 'processing' === ( $_POST['cod_final_status'] ?? '' ) ? 'processing' : 'on-hold' );
				update_option( 'codcrm_shop_phone', mb_substr( sanitize_text_field( wp_unslash( $_POST['shop_phone'] ?? '' ) ), 0, 40 ) );
				break;

			case 'repartition':
				update_option( 'codcrm_assign_mode', 'auto' === ( $_POST['mode'] ?? '' ) ? 'auto' : 'manual' );
				update_option( 'codcrm_assign_method', 'weighted' === ( $_POST['method'] ?? '' ) ? 'weighted' : 'equal' );
				update_option( 'codcrm_reassign_enabled', isset( $_POST['reassign_enabled'] ) ? 'yes' : 'no' );
				update_option( 'codcrm_reassign_minutes', max( 5, min( 1440, absint( $_POST['reassign_minutes'] ?? 30 ) ) ) );
				update_option( 'codcrm_reassign_max', max( 1, min( 10, absint( $_POST['reassign_max'] ?? 2 ) ) ) );
				[ $config, $dropped ] = self::sanitize_agents( (array) wp_unslash( $_POST['agents'] ?? [] ) );
				update_option( 'codcrm_agent_config', $config, false );
				$extra['dropped'] = $dropped;
				break;

			case 'sms':
			// Passerelle SMS.
			update_option( 'codcrm_sms_enabled', isset( $_POST['sms_enabled'] ) ? 'yes' : 'no' );
			update_option( 'codcrm_sms_url', esc_url_raw( trim( wp_unslash( $_POST['sms_url'] ?? '' ) ) ) );
			update_option( 'codcrm_sms_method', 'GET' === ( $_POST['sms_method'] ?? '' ) ? 'GET' : 'POST' );
			update_option( 'codcrm_sms_format', 'json' === ( $_POST['sms_format'] ?? '' ) ? 'json' : 'form' );
			update_option( 'codcrm_sms_params', sanitize_textarea_field( wp_unslash( $_POST['sms_params'] ?? '' ) ) );
			update_option( 'codcrm_sms_headers', sanitize_textarea_field( wp_unslash( $_POST['sms_headers'] ?? '' ) ) );
			update_option( 'codcrm_sms_sender', sanitize_text_field( wp_unslash( $_POST['sms_sender'] ?? '' ) ) );
			update_option( 'codcrm_sms_success', sanitize_text_field( wp_unslash( $_POST['sms_success'] ?? '' ) ) );
			$phone_fmt = sanitize_text_field( wp_unslash( $_POST['sms_phone'] ?? '216' ) );
			update_option( 'codcrm_sms_phone', in_array( $phone_fmt, [ '216', '+216', 'local' ], true ) ? $phone_fmt : '216' );
			$key = trim( (string) wp_unslash( $_POST['sms_api_key'] ?? '' ) );
			if ( '' !== $key ) { // vide = on conserve la clé existante.
				update_option( 'codcrm_sms_api_key', $key, false );
			}
			$valid_triggers = array_keys( CODCRM_SMS_Gateway::triggers() );
			update_option( 'codcrm_sms_auto', array_values( array_intersect( $valid_triggers, array_map( 'sanitize_key', (array) wp_unslash( $_POST['sms_auto'] ?? [] ) ) ) ) );
				break;

			case 'colors':
				self::save_colors( (array) wp_unslash( $_POST['colors'] ?? [] ) );
				break;

			case 'messages':
				$ctx = sanitize_key( wp_unslash( $_POST['ctx'] ?? '' ) );
				if ( isset( CODCRM_Templates::contexts()[ $ctx ] ) ) {
					foreach ( CODCRM_Templates::LANGS as $lang ) {
						foreach ( CODCRM_Templates::CHANNELS as $ch ) {
							if ( ! empty( $_POST['reset'][ $lang ][ $ch ] ) ) {
								CODCRM_Templates::set( $ctx, $lang, $ch, '' ); // retour au texte par défaut.
							} elseif ( isset( $_POST['tpl'][ $lang ][ $ch ] ) ) {
								CODCRM_Templates::set( $ctx, $lang, $ch, sanitize_textarea_field( wp_unslash( $_POST['tpl'][ $lang ][ $ch ] ) ) );
							}
						}
					}
					$extra['ctx'] = $ctx;
				}
				break;
		}

		wp_safe_redirect( self::url( array_merge( [ 'tab' => $tab, 'saved' => 1 ], $extra ) ) );
		exit;
	}

	/**
	 * Enregistre les couleurs personnalisées. Une couleur identique au défaut (ou invalide)
	 * supprime la personnalisation : l'étape suit alors MultiCarrier Sync / le défaut du CRM.
	 */
	public static function save_colors( array $posted ): void {
		$groups = [
			'stage' => [ array_keys( CODCRM_Stages::all() ), CODCRM_Stages::OPT_COLOR, [ 'CODCRM_Stages', 'default_color' ] ],
			'cash'  => [ array_keys( CODCRM_Cash::default_statuses() ), CODCRM_Cash::OPT_COLOR, static function ( $k ) { return CODCRM_Cash::default_statuses()[ $k ][1]; } ],
		];
		foreach ( $groups as $g => [ $keys, $prefix, $default_fn ] ) {
			foreach ( $keys as $key ) {
				if ( ! isset( $posted[ $g ][ $key ] ) ) {
					continue;
				}
				$hex = sanitize_hex_color( trim( (string) $posted[ $g ][ $key ] ) );
				if ( ! $hex || strtolower( $hex ) === strtolower( (string) call_user_func( $default_fn, $key ) ) ) {
					delete_option( $prefix . $key );
				} else {
					update_option( $prefix . $key, $hex, false );
				}
			}
		}
	}

	/**
	 * Valide la configuration des agents. Plages : HH:MM, début < fin, pas de chevauchement.
	 *
	 * @return array{0:array,1:int} configuration, nombre de plages invalides ignorées.
	 */
	public static function sanitize_agents( array $raw ): array {
		$valid   = array_map( static function ( $u ) { return (int) $u->ID; }, CODCRM_Agents::get_agents() );
		$out     = [];
		$dropped = 0;
		$hm      = static function ( $v ) {
			$v = trim( (string) $v );
			return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $v ) ? $v : '';
		};

		foreach ( $valid as $id ) {
			$a    = (array) ( $raw[ $id ] ?? [] );
			$days = [];
			foreach ( array_keys( self::DAYS ) as $d ) {
				$ranges = [];
				foreach ( (array) ( $a['days'][ $d ] ?? [] ) as $r ) {
					$s = $hm( $r[0] ?? '' );
					$e = $hm( $r[1] ?? '' );
					if ( '' === trim( (string) ( $r[0] ?? '' ) ) && '' === trim( (string) ( $r[1] ?? '' ) ) ) {
						continue; // plage vide.
					}
					if ( '' === $s || '' === $e || $s >= $e ) {
						$dropped++;
						continue;
					}
					$ranges[] = [ $s, $e ];
				}
				usort( $ranges, static function ( $x, $y ) { return strcmp( $x[0], $y[0] ); } );
				$clean = [];
				foreach ( $ranges as $r ) {
					if ( $clean && $r[0] < end( $clean )[1] ) {
						$dropped++; // chevauche la plage précédente.
						continue;
					}
					$clean[] = $r;
				}
				if ( $clean ) {
					$days[ $d ] = $clean;
				}
			}
			$out[ $id ] = [
				'active' => ! empty( $a['active'] ),
				'weight' => max( 0, min( 100, (int) ( $a['weight'] ?? 0 ) ) ),
				'days'   => $days,
			];
		}
		return [ $out, $dropped ];
	}

	/** Règle soumise → structure validée (ou message d'erreur). @return array|string */
	public static function sanitize_rule( array $p ) {
		$name = mb_substr( sanitize_text_field( (string) ( $p['name'] ?? '' ) ), 0, 80 );
		if ( '' === $name ) {
			return __( 'Le nom de la règle est obligatoire.', 'cod-tracker-crm' );
		}
		$valid_agents = array_map( static function ( $u ) { return (int) $u->ID; }, CODCRM_Agents::get_agents() );
		$agents       = array_values( array_intersect( array_map( 'intval', (array) ( $p['agents'] ?? [] ) ), $valid_agents ) );
		if ( ! $agents ) {
			return __( 'Ajoutez au moins un membre de l\'équipe.', 'cod-tracker-crm' );
		}

		$conds = [];
		foreach ( (array) ( $p['conds'] ?? [] ) as $c ) {
			$type = sanitize_key( (string) ( $c['type'] ?? '' ) );
			if ( ! in_array( $type, self::COND_TYPES, true ) ) {
				continue;
			}
			if ( 'total' === $type ) {
				$min = '' !== trim( (string) ( $c['min'] ?? '' ) ) ? round( (float) $c['min'], 3 ) : '';
				$max = '' !== trim( (string) ( $c['max'] ?? '' ) ) ? round( (float) $c['max'], 3 ) : '';
				if ( '' === $min && '' === $max ) {
					continue;
				}
				if ( '' !== $min && '' !== $max && $min > $max ) {
					return __( 'Montant : le minimum dépasse le maximum.', 'cod-tracker-crm' );
				}
				$conds[] = [ 'type' => 'total', 'values' => [], 'min' => $min, 'max' => $max ];
				continue;
			}
			$vals = (array) ( $c[ $type ] ?? [] );
			$vals = in_array( $type, [ 'product', 'category' ], true )
				? array_values( array_unique( array_filter( array_map( 'absint', $vals ) ) ) )
				: array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $vals ) ) ) ) );
			if ( $vals ) {
				$conds[] = [ 'type' => $type, 'values' => $vals ];
			}
		}
		if ( ! $conds ) {
			return __( 'Ajoutez au moins une condition avec une valeur.', 'cod-tracker-crm' );
		}

		return [
			'name'       => $name,
			'priority'   => max( 1, min( 999, (int) ( $p['priority'] ?? 10 ) ) ),
			'active'     => ! empty( $p['active'] ),
			'conditions' => $conds,
			'agents'     => $agents,
		];
	}

	public static function save_rule(): void {
		self::guard( 'codcrm_save_rule' );
		$id   = absint( $_POST['rule_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$rule = self::sanitize_rule( (array) wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( is_string( $rule ) ) {
			wp_safe_redirect( self::url( [ 'tab' => 'rules', 'rule' => $id ?: 'new', 'err' => rawurlencode( $rule ) ] ) );
			exit;
		}

		$rules = get_option( 'codcrm_assign_rules', [] );
		$rules = is_array( $rules ) ? $rules : [];
		if ( $id ) {
			foreach ( $rules as $k => $r ) {
				if ( (int) $r['id'] === $id ) {
					$rules[ $k ] = [ 'id' => $id ] + $rule;
				}
			}
		} else {
			$next    = (int) get_option( 'codcrm_rule_seq', 0 ) + 1;
			update_option( 'codcrm_rule_seq', $next, false );
			$rules[] = [ 'id' => $next ] + $rule;
		}
		update_option( 'codcrm_assign_rules', array_values( $rules ), false );

		wp_safe_redirect( self::url( [ 'tab' => 'rules', 'saved' => 1 ] ) );
		exit;
	}

	public static function rule_action(): void {
		$id = absint( $_GET['rule'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		self::guard( 'codcrm_rule_' . $id );
		$do    = sanitize_key( wp_unslash( $_GET['do'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$rules = get_option( 'codcrm_assign_rules', [] );
		$rules = is_array( $rules ) ? array_values( $rules ) : [];

		foreach ( $rules as $k => $r ) {
			if ( (int) $r['id'] !== $id ) {
				continue;
			}
			if ( 'delete' === $do ) {
				unset( $rules[ $k ] );
			} elseif ( 'toggle' === $do ) {
				$rules[ $k ]['active'] = empty( $r['active'] );
			} elseif ( 'duplicate' === $do ) {
				$next = (int) get_option( 'codcrm_rule_seq', 0 ) + 1;
				update_option( 'codcrm_rule_seq', $next, false );
				$copy           = $r;
				$copy['id']     = $next;
				$copy['name']   = mb_substr( $r['name'] . ' ' . __( '(copie)', 'cod-tracker-crm' ), 0, 80 );
				$copy['active'] = false;
				$rules[]        = $copy;
			}
			break;
		}
		update_option( 'codcrm_assign_rules', array_values( $rules ), false );

		wp_safe_redirect( self::url( [ 'tab' => 'rules', 'saved' => 1 ] ) );
		exit;
	}

	public static function dispatch_now(): void {
		self::guard( 'codcrm_dispatch_now' );
		$n = CODCRM_Dispatcher::dispatch_waiting();
		wp_safe_redirect( self::url( [ 'tab' => 'repartition', 'dispatched' => $n ] ) );
		exit;
	}
}
