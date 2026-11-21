<?php
defined( 'ABSPATH' ) || exit;

/**
 * Page « Suivi COD → Cash COD » (propriétaire).
 */
class CODCRM_Cash_Page {

	const PAGE     = 'codcrm-cash';
	const PER_PAGE = 25;
	const MAX_CSV  = 2097152; // 2 Mo.

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_post_codcrm_cash_import', [ __CLASS__, 'handle_import' ] );
		add_action( 'admin_post_codcrm_cash_confirm', [ __CLASS__, 'handle_confirm' ] );
		add_action( 'admin_post_codcrm_cash_bulk', [ __CLASS__, 'handle_bulk' ] );
		add_action( 'admin_post_codcrm_cash_export', [ __CLASS__, 'handle_export' ] );
	}

	public static function menu(): void {
		add_submenu_page( CODCRM_Work_Queue::PAGE_QUEUE, __( 'Cash COD', 'cod-tracker-crm' ), __( 'Cash COD', 'cod-tracker-crm' ), CODCRM_Agents::CAP_MANAGE, self::PAGE, [ __CLASS__, 'render' ] );
	}

	private static function guard( string $nonce_action ): void {
		if ( ! current_user_can( CODCRM_Agents::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'cod-tracker-crm' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}

	private static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::PAGE ], $args ), admin_url( 'admin.php' ) );
	}

	/** Filtres de la liste (GET). */
	private static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification
		return [
			'status'  => sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ),
			'carrier' => sanitize_key( wp_unslash( $_GET['carrier'] ?? '' ) ),
			'from'    => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $_GET['from'] ?? '' ) ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
			'to'      => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $_GET['to'] ?? '' ) ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
			'q'       => sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ),
		];
		// phpcs:enable
	}

	// ── Affichage ──────────────────────────────────────────────

	public static function render(): void {
		if ( ! current_user_can( CODCRM_Agents::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'cod-tracker-crm' ) );
		}
		global $wpdb;

		$f     = self::filters();
		$paged = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$sum   = CODCRM_Cash::summary( $f );
		$list  = CODCRM_Cash::query( $f, $paged, self::PER_PAGE );
		$pages = (int) ceil( $list['total'] / self::PER_PAGE );

		echo '<div class="wrap codcrm-dash"><h1>' . esc_html__( 'Cash COD — rapprochement', 'cod-tracker-crm' ) . '</h1>';
		self::notices();

		// Cartes.
		$to_pay = $sum[ CODCRM_Cash::COLLECTED ];
		$paid   = $sum[ CODCRM_Cash::PAID_OUT ];
		$disp   = $sum[ CODCRM_Cash::DISPUTED ];
		$cards  = [
			[ __( 'À reverser', 'cod-tracker-crm' ), CODCRM_Cash::money( $to_pay['collected'] ), sprintf( _n( '%d colis livré', '%d colis livrés', $to_pay['n'], 'cod-tracker-crm' ), $to_pay['n'] ), '#2271b1' ],
			[ __( 'Reversé', 'cod-tracker-crm' ), CODCRM_Cash::money( $paid['collected'] ), sprintf( __( '%1$d colis · frais %2$s', 'cod-tracker-crm' ), $paid['n'], CODCRM_Cash::money( $paid['fee'] ) ), '#00a651' ],
			[ __( 'Litiges', 'cod-tracker-crm' ), CODCRM_Cash::money( $disp['expected'] ), sprintf( _n( '%d colis', '%d colis', $disp['n'], 'cod-tracker-crm' ), $disp['n'] ), '#c0392b' ],
			[ __( 'En cours', 'cod-tracker-crm' ), CODCRM_Cash::money( $sum[ CODCRM_Cash::PENDING ]['expected'] ), sprintf( _n( '%d colis', '%d colis', $sum[ CODCRM_Cash::PENDING ]['n'], 'cod-tracker-crm' ), $sum[ CODCRM_Cash::PENDING ]['n'] ), '#e67e22' ],
			[ __( 'Retournés', 'cod-tracker-crm' ), (string) $sum[ CODCRM_Cash::RETURNED ]['n'], CODCRM_Cash::money( $sum[ CODCRM_Cash::RETURNED ]['expected'] ) . ' ' . __( 'non encaissés', 'cod-tracker-crm' ), '#7f8c8d' ],
		];
		echo '<div class="codcrm-cards">';
		foreach ( $cards as $c ) {
			printf( '<div class="codcrm-card" style="border-top-color:%s"><div class="v">%s</div><div class="l">%s</div><div class="s">%s</div></div>', esc_attr( $c[3] ), esc_html( $c[1] ), esc_html( $c[0] ), esc_html( $c[2] ) );
		}
		echo '</div>';

		self::render_import();

		// Filtres.
		$carriers = (array) $wpdb->get_col( 'SELECT DISTINCT carrier_slug FROM ' . CODCRM_Installer::table_cash() . " WHERE carrier_slug <> '' ORDER BY carrier_slug" ); // phpcs:ignore WordPress.DB.PreparedSQL
		echo '<h2>' . esc_html__( 'Colis', 'cod-tracker-crm' ) . '</h2><form method="get" class="codcrm-dash-filters"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';
		echo '<select name="status"><option value="">' . esc_html__( 'Tous statuts', 'cod-tracker-crm' ) . '</option>';
		foreach ( CODCRM_Cash::statuses() as $k => $d ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $f['status'], $k, false ), esc_html( $d[0] ) );
		}
		echo '</select> <select name="carrier"><option value="">' . esc_html__( 'Tous transporteurs', 'cod-tracker-crm' ) . '</option>';
		foreach ( $carriers as $c ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $c ), selected( $f['carrier'], $c, false ), esc_html( $c ) );
		}
		printf( '</select> <label>%s <input type="date" name="from" value="%s" /></label> <label>%s <input type="date" name="to" value="%s" /></label> <input type="search" name="q" value="%s" placeholder="%s" /> <button class="button">%s</button> ',
			esc_html__( 'Créés du', 'cod-tracker-crm' ), esc_attr( $f['from'] ), esc_html__( 'au', 'cod-tracker-crm' ), esc_attr( $f['to'] ), esc_attr( $f['q'] ), esc_attr__( 'N° de suivi / commande', 'cod-tracker-crm' ), esc_html__( 'Filtrer', 'cod-tracker-crm' ) );
		printf( '<a class="button" href="%s">⬇ %s</a> <span class="codcrm-count"><strong>%d</strong> %s</span></form>',
			esc_url( wp_nonce_url( add_query_arg( array_filter( $f ) + [ 'action' => 'codcrm_cash_export' ], admin_url( 'admin-post.php' ) ), 'codcrm_cash_export' ) ),
			esc_html__( 'Exporter CSV', 'cod-tracker-crm' ), $list['total'], esc_html__( 'colis', 'cod-tracker-crm' ) );

		// Liste + actions groupées.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="codcrm_cash_bulk" />';
		wp_nonce_field( 'codcrm_cash_bulk' );
		echo '<p><select name="bulk"><option value="">' . esc_html__( 'Action groupée…', 'cod-tracker-crm' ) . '</option>'
			. '<option value="paid">' . esc_html__( 'Marquer reversé', 'cod-tracker-crm' ) . '</option>'
			. '<option value="disputed">' . esc_html__( 'Marquer en litige', 'cod-tracker-crm' ) . '</option>'
			. '<option value="collected">' . esc_html__( 'Remettre « À reverser » (litige résolu)', 'cod-tracker-crm' ) . '</option></select> '
			. '<input type="text" name="ref" placeholder="' . esc_attr__( 'Référence relevé / virement', 'cod-tracker-crm' ) . '" /> '
			. '<button class="button">' . esc_html__( 'Appliquer', 'cod-tracker-crm' ) . '</button></p>';

		echo '<table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" onclick="jQuery(\'.codcrm-cb\').prop(\'checked\',this.checked)" /></td>';
		foreach ( [ __( 'Commande', 'cod-tracker-crm' ), __( 'Suivi', 'cod-tracker-crm' ), __( 'Attendu', 'cod-tracker-crm' ), __( 'Encaissé', 'cod-tracker-crm' ), __( 'Frais', 'cod-tracker-crm' ), __( 'Statut', 'cod-tracker-crm' ), __( 'Relevé', 'cod-tracker-crm' ), __( 'Note', 'cod-tracker-crm' ) ] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $list['rows'] ) {
			echo '<tr><td colspan="9">' . esc_html__( 'Aucun colis.', 'cod-tracker-crm' ) . '</td></tr>';
		}
		foreach ( $list['rows'] as $r ) {
			$st    = CODCRM_Cash::statuses()[ $r->status ] ?? [ $r->status, '#777' ];
			$order = wc_get_order( (int) $r->order_id );
			printf( '<tr><th class="check-column"><input type="checkbox" class="codcrm-cb" name="ids[]" value="%d" /></th><td><a href="%s">#%d</a></td><td><code>%s</code><br><small>%s</small></td><td>%s</td><td>%s</td><td>%s</td><td><span class="codcrm-pill" style="%s">%s</span></td><td>%s</td><td>%s</td></tr>',
				(int) $r->order_id,
				esc_url( $order ? $order->get_edit_order_url() : '#' ),
				(int) $r->order_id,
				esc_html( $r->tracking ),
				esc_html( $r->carrier_slug ),
				esc_html( CODCRM_Cash::money( $r->expected ) ),
				esc_html( CODCRM_Cash::money( $r->collected ) ),
				esc_html( $r->fee > 0 ? CODCRM_Cash::money( $r->fee ) : '—' ),
				esc_attr( CODCRM_Stages::badge_style( $st[1] ) ),
				esc_html( $st[0] ),
				esc_html( $r->statement_ref ?: '—' ),
				esc_html( $r->note )
			);
		}
		echo '</tbody></table></form>';

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( (string) paginate_links( [
				'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages, 'prev_text' => '‹', 'next_text' => '›',
			] ) ) . '</div></div>';
		}
		echo '</div>';
	}

	private static function notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['imported'] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf(
				__( 'Relevé appliqué : %1$d reversé(s), %2$d litige(s), %3$d ligne(s) ignorée(s).', 'cod-tracker-crm' ),
				absint( $_GET['paid'] ?? 0 ), absint( $_GET['disputed'] ?? 0 ), absint( $_GET['skipped'] ?? 0 )
			) ) );
		}
		if ( isset( $_GET['bulk_done'] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( _n( '%d colis mis à jour.', '%d colis mis à jour.', absint( $_GET['bulk_done'] ), 'cod-tracker-crm' ), absint( $_GET['bulk_done'] ) ) ) );
		}
		if ( isset( $_GET['err'] ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( sanitize_text_field( rawurldecode( wp_unslash( $_GET['err'] ) ) ) ) );
		}
		// phpcs:enable
	}

	private static function render_import(): void {
		echo '<h2>' . esc_html__( 'Importer un relevé du transporteur', 'cod-tracker-crm' ) . '</h2>';

		$preview = get_transient( 'codcrm_cash_import_' . get_current_user_id() );
		if ( is_array( $preview ) && isset( $_GET['preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::render_preview( $preview );
			return;
		}

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="codcrm_cash_import" />';
		wp_nonce_field( 'codcrm_cash_import' );
		echo '<input type="file" name="statement" accept=".csv,.txt,text/csv" required /> ';
		echo '<input type="text" name="ref" placeholder="' . esc_attr__( 'Référence du relevé (si absente du fichier)', 'cod-tracker-crm' ) . '" class="regular-text" /> ';
		echo '<button class="button button-primary">' . esc_html__( 'Analyser', 'cod-tracker-crm' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'CSV avec une ligne d\'en-têtes : « code » (n° de suivi) et « montant » obligatoires ; « frais » et « relevé » facultatifs. Séparateur , ; ou tabulation. Rien n\'est modifié avant votre confirmation.', 'cod-tracker-crm' ) . '</p></form>';
	}

	private static function verdict_label( string $v ): array {
		$map = [
			'ok'         => [ __( 'Conforme → sera marqué reversé', 'cod-tracker-crm' ), '#00a651' ],
			'diff'       => [ __( 'Écart de montant → litige', 'cod-tracker-crm' ), '#c0392b' ],
			'unexpected' => [ __( 'Colis non livré → litige', 'cod-tracker-crm' ), '#c0392b' ],
			'paid'       => [ __( 'Déjà reversé (ignoré)', 'cod-tracker-crm' ), '#7f8c8d' ],
			'duplicate'  => [ __( 'Doublon dans le fichier (ignoré)', 'cod-tracker-crm' ), '#7f8c8d' ],
			'unknown'    => [ __( 'Colis inconnu (ignoré)', 'cod-tracker-crm' ), '#e67e22' ],
			'invalid'    => [ __( 'Montant illisible (ignoré)', 'cod-tracker-crm' ), '#e67e22' ],
		];
		return $map[ $v ] ?? [ $v, '#777' ];
	}

	private static function render_preview( array $p ): void {
		$counts = array_count_values( array_column( $p['rows'], 'verdict' ) );
		printf( '<p><strong>%s</strong> — %s</p>', esc_html( $p['file'] ), esc_html( sprintf( __( '%d ligne(s) analysée(s)', 'cod-tracker-crm' ), count( $p['rows'] ) ) ) );
		echo '<p>';
		foreach ( $counts as $v => $n ) {
			$l = self::verdict_label( (string) $v );
			printf( '<span class="codcrm-pill" style="background:%s">%s : %d</span> ', esc_attr( $l[1] ), esc_html( $l[0] ), (int) $n );
		}
		echo '</p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Suivi', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Commande', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Attendu', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Relevé', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Frais', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Résultat', 'cod-tracker-crm' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $p['rows'], 0, 300 ) as $r ) {
			$l = self::verdict_label( $r['verdict'] );
			printf( '<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><span class="codcrm-pill" style="background:%s">%s</span></td></tr>',
				esc_html( $r['tracking'] ), $r['order_id'] ? '#' . (int) $r['order_id'] : '—', esc_html( CODCRM_Cash::money( $r['expected'] ) ), esc_html( CODCRM_Cash::money( $r['amount'] ) ), esc_html( $r['fee'] > 0 ? CODCRM_Cash::money( $r['fee'] ) : '—' ), esc_attr( $l[1] ), esc_html( $l[0] ) );
		}
		echo '</tbody></table>';
		if ( count( $p['rows'] ) > 300 ) {
			echo '<p class="description">' . esc_html( sprintf( __( '+ %d autres lignes (aperçu limité à 300, tout sera appliqué).', 'cod-tracker-crm' ), count( $p['rows'] ) - 300 ) ) . '</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px"><input type="hidden" name="action" value="codcrm_cash_confirm" />';
		wp_nonce_field( 'codcrm_cash_confirm' );
		echo '<button class="button button-primary">' . esc_html__( 'Confirmer et appliquer', 'cod-tracker-crm' ) . '</button> ';
		echo '<a class="button" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Annuler', 'cod-tracker-crm' ) . '</a></form>';
	}

	// ── Actions ────────────────────────────────────────────────

	public static function handle_import(): void {
		self::guard( 'codcrm_cash_import' );

		$file = $_FILES['statement'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$fail = static function ( string $msg ) {
			wp_safe_redirect( self::url( [ 'err' => rawurlencode( $msg ) ] ) );
			exit;
		};

		if ( ! is_array( $file ) || ! isset( $file['tmp_name'], $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$fail( __( 'Aucun fichier reçu.', 'cod-tracker-crm' ) );
		}
		if ( (int) $file['size'] > self::MAX_CSV ) {
			$fail( __( 'Fichier trop volumineux (2 Mo maximum).', 'cod-tracker-crm' ) );
		}
		$ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, [ 'csv', 'txt' ], true ) ) {
			$fail( __( 'Format non accepté : fichier .csv attendu.', 'cod-tracker-crm' ) );
		}

		$parsed = CODCRM_Cash::parse_statement( $file['tmp_name'] );
		if ( $parsed['error'] ) {
			$fail( $parsed['error'] );
		}

		set_transient( 'codcrm_cash_import_' . get_current_user_id(), [
			'file' => sanitize_file_name( (string) $file['name'] ),
			'ref'  => sanitize_text_field( wp_unslash( $_POST['ref'] ?? '' ) ),
			'rows' => CODCRM_Cash::classify( $parsed['rows'] ),
		], HOUR_IN_SECONDS );

		wp_safe_redirect( self::url( [ 'preview' => 1 ] ) );
		exit;
	}

	public static function handle_confirm(): void {
		self::guard( 'codcrm_cash_confirm' );

		$key = 'codcrm_cash_import_' . get_current_user_id();
		$p   = get_transient( $key );
		if ( ! is_array( $p ) ) {
			wp_safe_redirect( self::url( [ 'err' => rawurlencode( __( 'Aperçu expiré : réimportez le fichier.', 'cod-tracker-crm' ) ) ] ) );
			exit;
		}
		delete_transient( $key ); // un aperçu ne s'applique qu'une fois.

		// Les lignes viennent de l'aperçu stocké côté serveur (jamais du navigateur) ;
		// les verdicts sont recalculés car la base a pu changer entre-temps.
		$rows = CODCRM_Cash::classify( array_map( static function ( $r ) {
			return [ 'tracking' => $r['tracking'], 'amount' => $r['amount'], 'fee' => $r['fee'], 'ref' => $r['ref'] ];
		}, $p['rows'] ) );
		$res  = CODCRM_Cash::apply_statement( $rows, (string) $p['ref'], get_current_user_id() );

		wp_safe_redirect( self::url( [ 'imported' => 1, 'paid' => $res['paid'], 'disputed' => $res['disputed'], 'skipped' => $res['skipped'] ] ) );
		exit;
	}

	public static function handle_bulk(): void {
		self::guard( 'codcrm_cash_bulk' );

		$bulk = sanitize_key( wp_unslash( $_POST['bulk'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$ids  = array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ?? [] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$ref  = sanitize_text_field( wp_unslash( $_POST['ref'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$map  = [ 'paid' => CODCRM_Cash::PAID_OUT, 'disputed' => CODCRM_Cash::DISPUTED, 'collected' => CODCRM_Cash::COLLECTED ];

		if ( ! isset( $map[ $bulk ] ) || ! $ids ) {
			wp_safe_redirect( self::url() );
			exit;
		}

		$n = 0;
		foreach ( array_slice( $ids, 0, 500 ) as $id ) {
			$extra = [];
			if ( CODCRM_Cash::PAID_OUT === $map[ $bulk ] ) {
				$extra = [ 'paid_at' => current_time( 'mysql' ), 'statement_ref' => $ref ];
			}
			$n += CODCRM_Cash::set_status( $id, $map[ $bulk ], get_current_user_id(), '' !== $ref ? $ref : '', $extra ) ? 1 : 0;
		}
		wp_safe_redirect( self::url( [ 'bulk_done' => $n ] ) );
		exit;
	}

	public static function handle_export(): void {
		if ( ! current_user_can( CODCRM_Agents::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'cod-tracker-crm' ), 403 );
		}
		check_admin_referer( 'codcrm_cash_export' );

		$f = self::filters();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="cash-cod-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM UTF-8 pour Excel. // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, [ 'commande', 'suivi', 'transporteur', 'attendu', 'encaisse', 'frais', 'statut', 'releve', 'note', 'cree_le', 'paye_le' ], ';' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		for ( $page = 1; $page <= 200; $page++ ) {
			$batch = CODCRM_Cash::query( $f, $page, 500 );
			foreach ( $batch['rows'] as $r ) {
				fputcsv( $out, array_map( [ 'CODCRM_Cash', 'csv_cell' ], [
					$r->order_id, $r->tracking, $r->carrier_slug,
					number_format( (float) $r->expected, 3, ',', '' ),
					null === $r->collected ? '' : number_format( (float) $r->collected, 3, ',', '' ),
					number_format( (float) $r->fee, 3, ',', '' ),
					CODCRM_Cash::statuses()[ $r->status ][0] ?? $r->status,
					$r->statement_ref, $r->note, $r->created_at, (string) $r->paid_at,
				] ), ';' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			if ( count( $batch['rows'] ) < 500 ) {
				break;
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
