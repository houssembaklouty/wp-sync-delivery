<?php
defined( 'ABSPATH' ) || exit;

/**
 * Bibliothèque de modèles de messages : situation (contexte) × langue (fr|ar)
 * × canal (whatsapp|sms). Sert à l'agent (fenêtre « Message ») et, plus tard,
 * à l'automatisation (un déclencheur = un contexte).
 *
 * Surcharges du propriétaire : option codcrm_msg_templates
 *   [ contexte ][ langue ][ canal ] => texte   (absent = texte par défaut)
 */
class CODCRM_Templates {

	const OPTION = 'codcrm_msg_templates';

	const LANGS    = [ 'fr', 'ar' ];
	const CHANNELS = [ 'whatsapp', 'sms' ];

	const MAX_LEN = [ 'whatsapp' => 1000, 'sms' => 480 ];

	/** Placeholders reconnus. */
	const PLACEHOLDERS = [ '{client}', '{commande}', '{suivi}', '{transporteur}', '{montant}', '{boutique}', '{telephone_boutique}', '{date}' ];

	public static function init(): void {
		add_action( 'wp_ajax_codcrm_message_init', [ __CLASS__, 'ajax_init' ] );
		add_action( 'wp_ajax_codcrm_message_preview', [ __CLASS__, 'ajax_preview' ] );
		self::migrate();
	}

	// ── Contextes ──────────────────────────────────────────────

	/** @return array<string,string> clé => libellé (ordre d'affichage). */
	public static function contexts(): array {
		return [
			'confirm_order'        => __( 'Confirmer la commande', 'cod-tracker-crm' ),
			'confirm_address'      => __( 'Vérifier adresse / téléphone', 'cod-tracker-crm' ),
			'order_confirmed'      => __( 'Commande confirmée (préparation)', 'cod-tracker-crm' ),
			'shipped'              => __( 'Colis expédié', 'cod-tracker-crm' ),
			'out_for_delivery'     => __( 'Livreur en route', 'cod-tracker-crm' ),
			'attempt_failed'       => __( 'Livreur n\'a pas pu vous joindre', 'cod-tracker-crm' ),
			'rescheduled'          => __( 'Livraison reportée', 'cod-tracker-crm' ),
			'callback_unreachable' => __( 'Client injoignable : merci de rappeler', 'cod-tracker-crm' ),
			'at_depot'             => __( 'Colis au dépôt (à récupérer)', 'cod-tracker-crm' ),
			'return_warning'       => __( 'Dernière tentative (risque de retour)', 'cod-tracker-crm' ),
			'delivered_thanks'     => __( 'Livré : merci', 'cod-tracker-crm' ),
			'returned'             => __( 'Colis retourné', 'cod-tracker-crm' ),
			'pickup_delay'         => __( 'Retard d\'enlèvement (excuses)', 'cod-tracker-crm' ),
			'custom'               => __( 'Message libre', 'cod-tracker-crm' ),
		];
	}

	public static function context_label( string $ctx ): string {
		return self::contexts()[ $ctx ] ?? $ctx;
	}

	// ── Textes par défaut ──────────────────────────────────────

	/** @return array<string,array<string,array<string,string>>> contexte → langue → canal → texte */
	public static function defaults(): array {
		static $d = null;
		if ( null !== $d ) {
			return $d;
		}

		$d = [
			'confirm_order' => [
				'fr' => [
					'whatsapp' => "Bonjour {client} 👋, c'est {boutique}.\nVous avez commandé #{commande} pour {montant} DT (paiement à la livraison).\nConfirmez-vous votre commande ? Répondez *OUI* pour valider. Merci 🙏",
					'sms'      => "{boutique}: commande #{commande} ({montant} DT, paiement à la livraison). Confirmez-vous ? Répondez OUI. Merci",
				],
				'ar' => [
					'whatsapp' => "مرحبا {client} 👋، معك {boutique}.\nطلبتم الطلبية رقم #{commande} بقيمة {montant} د.ت (الدفع عند الاستلام).\nهل تؤكدون الطلبية؟ المرجو الرد بـ «نعم» للتأكيد. شكرا لثقتكم 🙏",
					'sms'      => "{boutique}: طلبيتكم #{commande} ({montant} د.ت) الدفع عند الاستلام. هل تؤكدون؟ أجيبوا نعم. شكرا",
				],
			],
			'confirm_address' => [
				'fr' => [
					'whatsapp' => "Bonjour {client}, pour livrer votre commande #{commande}, pouvez-vous nous confirmer votre adresse complète (gouvernorat, ville, rue) et un numéro joignable ? Merci 🙏",
					'sms'      => "{boutique}: pour livrer la commande #{commande}, merci de confirmer votre adresse complète et un numéro joignable. Merci",
				],
				'ar' => [
					'whatsapp' => "مرحبا {client}، لتسليم طلبيتكم رقم #{commande} نحتاج تأكيد عنوانكم الكامل (الولاية، المدينة، الشارع) ورقم هاتف متاح. شكرا 🙏",
					'sms'      => "{boutique}: لتسليم الطلبية #{commande} المرجو تأكيد عنوانكم الكامل ورقم هاتف متاح. شكرا",
				],
			],
			'order_confirmed' => [
				'fr' => [
					'whatsapp' => "Bonjour {client}, votre commande #{commande} est confirmée et en cours de préparation ✅\nLivraison sous 24 à 72h. Merci de votre confiance 🙏",
					'sms'      => "{boutique}: merci {client}, votre commande #{commande} est confirmée et en préparation. Livraison sous 24 à 72h.",
				],
				'ar' => [
					'whatsapp' => "مرحبا {client}، تم تأكيد طلبيتكم رقم #{commande} وهي الآن قيد التحضير ✅\nسيتم تسليمها خلال 24 إلى 72 ساعة. شكرا لثقتكم 🙏",
					'sms'      => "{boutique}: تم تأكيد طلبيتكم #{commande} وهي قيد التحضير. التسليم خلال 24-72 ساعة. شكرا",
				],
			],
			'shipped' => [
				'fr' => [
					'whatsapp' => "Bonjour {client}, votre commande #{commande} a été expédiée 📦\nTransporteur : {transporteur} · Suivi : {suivi}\nLe livreur vous contactera très bientôt.",
					'sms'      => "{boutique}: votre commande #{commande} est expédiée ({transporteur}, suivi {suivi}). Livraison sous 24 à 72h.",
				],
				'ar' => [
					'whatsapp' => "مرحبا {client}، تم شحن طلبيتكم رقم #{commande} 📦\nشركة التوصيل: {transporteur} · رقم التتبع: {suivi}\nسيتصل بكم الموزع قريبا.",
					'sms'      => "{boutique}: تم شحن طلبيتكم #{commande} عبر {transporteur}. رقم التتبع {suivi}",
				],
			],
			'out_for_delivery' => [
				'fr' => [
					'whatsapp' => "Bonjour {client}, votre commande #{commande} ({montant} DT) est en route vers vous aujourd'hui 🚚\nMerci de rester joignable et de préparer l'appoint. Merci !",
					'sms'      => "{boutique}: votre commande #{commande} ({montant} DT) est en cours de livraison. Merci de rester joignable et de préparer l'appoint.",
				],
				'ar' => [
					'whatsapp' => "مرحبا {client}، طلبيتكم رقم #{commande} بقيمة {montant} د.ت في طريقها إليكم اليوم 🚚\nالمرجو البقاء على الاتصال وتحضير المبلغ. شكرا!",
					'sms'      => "{boutique}: طلبيتكم #{commande} ({montant} د.ت) في الطريق إليكم اليوم. المرجو البقاء على الاتصال",
				],
			],
			'attempt_failed' => [
				'fr' => [
					'whatsapp' => "Bonjour {client}, le livreur a essayé de vous joindre pour la commande #{commande} ({montant} DT) sans succès 📞\nMerci de répondre à son appel ou de nous indiquer quand vous êtes disponible.",
					'sms'      => "{boutique}: le livreur n'a pas pu vous joindre pour la commande #{commande} ({montant} DT). Merci de répondre à son appel.",
				],
				'ar' => [
					'whatsapp' => "مرحبا {client}، حاول الموزع الاتصال بكم لتسليم الطلبية رقم #{commande} ({montant} د.ت) دون جدوى 📞\nالمرجو الرد على مكالمته أو إعلامنا بالوقت المناسب للتسليم.",
					'sms'      => "{boutique}: تعذر على الموزع الاتصال بكم لتسليم الطلبية #{commande}. المرجو الرد على مكالمته",
				],
			],
			'rescheduled' => [
				'fr' => [
					'whatsapp' => "Bonjour {client}, la livraison de votre commande #{commande} est reportée comme convenu 📅\nNous vous recontactons pour fixer un nouveau créneau. Merci de votre compréhension.",
					'sms'      => "{boutique}: la livraison de la commande #{commande} est reportée. Nous vous recontacterons pour un nouveau créneau.",
				],
				'ar' => [
					'whatsapp' => "مرحبا {client}، تم تأجيل تسليم طلبيتكم رقم #{commande} حسب طلبكم 📅\nسنتصل بكم لتحديد موعد جديد. شكرا على تفهمكم.",
					'sms'      => "{boutique}: تم تأجيل تسليم الطلبية #{commande}. سنتصل بكم لتحديد موعد جديد. شكرا",
				],
			],
			'callback_unreachable' => [
				'fr' => [
					'whatsapp' => "Bonjour {client}, nous avons essayé de vous joindre au sujet de la commande #{commande}, sans réponse. Pouvez-vous répondre à ce message ou nous rappeler dès que possible ? {telephone_boutique} 🙏",
					'sms'      => "{boutique}: nous n'arrivons pas à vous joindre pour la commande #{commande}. Merci de nous rappeler dès que possible. {telephone_boutique}",
				],
				'ar' => [
					'whatsapp' => "مرحبا {client}، حاولنا الاتصال بكم بخصوص الطلبية رقم #{commande} دون رد. المرجو الرد على هذه الرسالة أو معاودة الاتصال بنا في أقرب وقت {telephone_boutique} 🙏",
					'sms'      => "{boutique}: تعذر الاتصال بكم بخصوص الطلبية #{commande}. المرجو معاودة الاتصال بنا {telephone_boutique}",
				],
			],
			'at_depot' => [
				'fr' => [
					'whatsapp' => "Bonjour {client}, votre colis #{commande} est disponible au dépôt {transporteur} 🏢\nMerci de nous contacter pour organiser la livraison ou le retrait. Suivi : {suivi}",
					'sms'      => "{boutique}: votre colis #{commande} est au depot {transporteur}. Merci de nous contacter pour la livraison ou le retrait. Suivi {suivi}",
				],
				'ar' => [
					'whatsapp' => "مرحبا {client}، طلبيتكم رقم #{commande} موجودة بمركز {transporteur} 🏢\nالمرجو التنسيق معنا لاستلامها أو تأكيد موعد التسليم. رقم التتبع: {suivi}",
					'sms'      => "{boutique}: طلبيتكم #{commande} بمركز {transporteur}. المرجو التنسيق لاستلامها. تتبع {suivi}",
				],
			],
			'return_warning' => [
				'fr' => [
					'whatsapp' => "Bonjour {client}, c'est la dernière tentative de livraison pour la commande #{commande} ⚠️\nSans réponse de votre part, le colis nous sera retourné. Merci de nous répondre pour confirmer la livraison.",
					'sms'      => "{boutique}: dernière tentative de livraison pour la commande #{commande}. Sans réponse de votre part, le colis sera retourné. Merci de confirmer.",
				],
				'ar' => [
					'whatsapp' => "مرحبا {client}، هذه آخر محاولة لتسليم طلبيتكم رقم #{commande} ⚠️\nفي حال عدم الرد سيتم إرجاع الطرد إلينا. المرجو الاتصال بنا أو الرد على هذه الرسالة لتأكيد التسليم.",
					'sms'      => "{boutique}: آخر محاولة لتسليم الطلبية #{commande}. دون رد منكم سيتم إرجاعها. المرجو التأكيد",
				],
			],
			'delivered_thanks' => [
				'fr' => [
					'whatsapp' => "Merci {client} pour votre confiance en {boutique} 🙏\nVotre commande #{commande} a bien été livrée. Nous espérons que vous êtes satisfait(e) — à très bientôt ❤️",
					'sms'      => "{boutique}: merci {client} ! Votre commande #{commande} a bien été livrée. A très bientot.",
				],
				'ar' => [
					'whatsapp' => "شكرا {client} على ثقتكم في {boutique} 🙏\nتم تسليم طلبيتكم رقم #{commande} بنجاح. نتمنى أن ينال المنتج رضاكم، وفي انتظار زيارتكم القادمة ❤️",
					'sms'      => "{boutique}: شكرا {client}! تم تسليم طلبيتكم #{commande} بنجاح. في انتظار زيارتكم القادمة",
				],
			],
			'returned' => [
				'fr' => [
					'whatsapp' => "Bonjour {client}, le colis #{commande} nous a été retourné faute de livraison. Si vous êtes toujours intéressé(e), dites-le-nous et nous le renverrons 🙏",
					'sms'      => "{boutique}: votre colis #{commande} nous a été retourné faute de livraison. Contactez-nous si vous souhaitez une nouvelle livraison.",
				],
				'ar' => [
					'whatsapp' => "مرحبا {client}، تم إرجاع الطرد رقم #{commande} إلينا لتعذر التسليم. إذا كنتم ما زلتم مهتمين، أعلمونا لنعيد إرساله إليكم 🙏",
					'sms'      => "{boutique}: تم إرجاع الطرد #{commande} لتعذر التسليم. اتصلوا بنا إن رغبتم في إعادة إرساله",
				],
			],
			'pickup_delay' => [
				'fr' => [
					'whatsapp' => "Bonjour {client}, toutes nos excuses : votre commande #{commande} n'a pas encore été récupérée par la société de livraison. Nous relançons pour qu'elle vous parvienne au plus vite 🙏",
					'sms'      => "{boutique}: désolés, la commande #{commande} n'a pas encore été enlevée par le transporteur. Nous signalons le retard à la société de livraison.",
				],
				'ar' => [
					'whatsapp' => "مرحبا {client}، نعتذر عن التأخير: طلبيتكم رقم #{commande} لم تستلمها شركة التوصيل بعد، ونتابع الأمر لتصلكم في أقرب وقت 🙏",
					'sms'      => "{boutique}: نعتذر عن التأخير، الطلبية #{commande} لم تستلمها شركة التوصيل بعد ونتابع الأمر",
				],
			],
			'custom' => [
				'fr' => [ 'whatsapp' => '', 'sms' => '' ],
				'ar' => [ 'whatsapp' => '', 'sms' => '' ],
			],
		];
		return $d;
	}

	public static function default_text( string $ctx, string $lang, string $channel ): string {
		return self::defaults()[ $ctx ][ $lang ][ $channel ] ?? '';
	}

	// ── Lecture (surcharge ou défaut) ──────────────────────────

	private static function overrides(): array {
		$o = get_option( self::OPTION, [] );
		return is_array( $o ) ? $o : [];
	}

	public static function get( string $ctx, string $lang, string $channel ): string {
		$o = self::overrides();
		if ( isset( $o[ $ctx ][ $lang ][ $channel ] ) && '' !== trim( (string) $o[ $ctx ][ $lang ][ $channel ] ) ) {
			return (string) $o[ $ctx ][ $lang ][ $channel ];
		}
		return self::default_text( $ctx, $lang, $channel );
	}

	public static function is_overridden( string $ctx, string $lang, string $channel ): bool {
		$o = self::overrides();
		return isset( $o[ $ctx ][ $lang ][ $channel ] ) && '' !== trim( (string) $o[ $ctx ][ $lang ][ $channel ] );
	}

	/** Enregistre (ou supprime si identique au défaut / vide) une surcharge. */
	public static function set( string $ctx, string $lang, string $channel, string $text ): void {
		if ( ! isset( self::contexts()[ $ctx ] ) || ! in_array( $lang, self::LANGS, true ) || ! in_array( $channel, self::CHANNELS, true ) ) {
			return;
		}
		$o    = self::overrides();
		$text = trim( $text );
		if ( '' === $text || $text === self::default_text( $ctx, $lang, $channel ) ) {
			unset( $o[ $ctx ][ $lang ][ $channel ] );
			if ( empty( $o[ $ctx ][ $lang ] ) ) {
				unset( $o[ $ctx ][ $lang ] );
			}
			if ( empty( $o[ $ctx ] ) ) {
				unset( $o[ $ctx ] );
			}
		} else {
			$o[ $ctx ][ $lang ][ $channel ] = mb_substr( $text, 0, self::MAX_LEN[ $channel ] );
		}
		update_option( self::OPTION, $o, false );
	}

	// ── Langue, suggestion, rendu ──────────────────────────────

	/** Arabe si le nom ou l'adresse du client contient des caractères arabes, sinon français. */
	public static function detect_lang( WC_Order $o ): string {
		$s    = $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() . ' ' . $o->get_billing_address_1() . ' ' . $o->get_billing_city();
		$lang = preg_match( '/\p{Arabic}/u', $s ) ? 'ar' : 'fr';
		$lang = (string) apply_filters( 'codcrm_message_lang', $lang, $o );
		return in_array( $lang, self::LANGS, true ) ? $lang : 'fr';
	}

	/** Contexte le plus adapté à la situation actuelle de la commande. */
	public static function suggest( WC_Order $o ): string {
		$stage  = (string) $o->get_meta( CODCRM_Event_Collector::META_STAGE );
		$failed = (int) $o->get_meta( CODCRM_Attempts::META_FAILED );
		$status = $o->get_status();

		if ( in_array( $stage, [ CODCRM_Stages::LIVRES, CODCRM_Stages::LIVRES_PAYES ], true ) ) {
			return 'delivered_thanks';
		}
		if ( 0 === strpos( $stage, 'RETOUR_' ) ) {
			return 'returned';
		}
		// Plusieurs échecs : prévenir avant le retour.
		if ( $failed >= 1 && $failed >= CODCRM_Attempts::threshold() - 1 && ! CODCRM_Stages::is_terminal( $stage ) ) {
			return 'return_warning';
		}

		switch ( $stage ) {
			case CODCRM_Stages::EN_COURS_LIVRAISON:
				return 'out_for_delivery';
			case CODCRM_Stages::RENDEZ_VOUS:
				return 'rescheduled';
			case CODCRM_Stages::NON_RECUS:
			case CODCRM_Stages::EN_ANOMALIE:
				return 'attempt_failed';
			case CODCRM_Stages::AU_DEPOT:
			case CODCRM_Stages::TRANSFERT:
				return $failed >= 1 ? 'attempt_failed' : 'at_depot';
			case CODCRM_Stages::ENLEVES:
				return 'shipped';
			case CODCRM_Stages::EN_ATTENTE:
			case CODCRM_Stages::A_ENLEVER:
				// Créé chez le transporteur mais pas encore récupéré depuis plusieurs jours.
				$since = strtotime( (string) $o->get_meta( CODCRM_Event_Collector::META_STAGE_SINCE ) );
				if ( $since && time() - $since > CODCRM_KPIs::pickup_days() * DAY_IN_SECONDS ) {
					return 'pickup_delay';
				}
				return 'shipped';
		}

		if ( in_array( $status, [ 'wcmcs-synced', 'wcmcs-packed' ], true ) ) {
			return 'shipped';
		}
		if ( 'wcmcs-confirmed' === $status ) {
			return 'order_confirmed';
		}
		return 'confirm_order';
	}

	/** Remplace les placeholders (réutilise CODCRM_Contacts::render_template). */
	public static function render( WC_Order $o, string $text ): string {
		return CODCRM_Contacts::render_template( $o, $text );
	}

	/** Texte prêt à envoyer pour une commande. */
	public static function message( WC_Order $o, string $ctx, string $lang, string $channel ): string {
		return self::render( $o, self::get( $ctx, $lang, $channel ) );
	}

	// ── Migration des anciens modèles ──────────────────────────

	public static function migrate(): void {
		if ( get_option( 'codcrm_msg_migrated' ) ) {
			return;
		}
		$o = self::overrides();

		// Anciens modèles WhatsApp (libellés FR / AR) → « livreur en route ».
		$wa = get_option( 'codcrm_wa_templates' );
		if ( is_array( $wa ) ) {
			foreach ( $wa as $t ) {
				$lang = 'AR' === strtoupper( (string) ( $t['label'] ?? '' ) ) ? 'ar' : ( 'FR' === strtoupper( (string) ( $t['label'] ?? '' ) ) ? 'fr' : '' );
				if ( $lang && ! empty( $t['text'] ) && $t['text'] !== self::legacy_wa_default( $lang ) ) {
					$o['out_for_delivery'][ $lang ]['whatsapp'] = (string) $t['text'];
				}
			}
		}
		// Anciens modèles SMS (déclencheurs) → contextes équivalents (français).
		$sms = get_option( 'codcrm_sms_templates' );
		if ( is_array( $sms ) ) {
			foreach ( [ 'out_for_delivery' => 'out_for_delivery', 'attempt_failed' => 'attempt_failed', 'rescheduled' => 'rescheduled' ] as $trigger => $ctx ) {
				if ( ! empty( $sms[ $trigger ] ) ) {
					$o[ $ctx ]['fr']['sms'] = (string) $sms[ $trigger ];
				}
			}
		}
		update_option( self::OPTION, $o, false );
		update_option( 'codcrm_msg_migrated', 1, false );
	}

	/** Anciens textes WhatsApp par défaut (non migrés : ils n'avaient pas été personnalisés). */
	private static function legacy_wa_default( string $lang ): string {
		return 'fr' === $lang
			? "Bonjour {client}, votre commande #{commande} ({montant} DT) est en cours de livraison avec {transporteur}. Pouvez-vous confirmer que vous serez disponible ? Merci !"
			: "مرحبا {client}، طلبيتك رقم {commande} بقيمة {montant} د.ت في طريقها إليك مع {transporteur}. المرجو تأكيد توفرك لاستلامها. شكرا!";
	}

	// ── AJAX : fenêtre « Message » ─────────────────────────────

	private static function guard_order(): WC_Order {
		check_ajax_referer( 'codcrm', 'nonce' );
		if ( ! current_user_can( CODCRM_Agents::CAP_WORK ) ) {
			wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'cod-tracker-crm' ) ], 403 );
		}
		$order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
		if ( ! $order || ! CODCRM_Agents::can_handle( $order ) ) {
			wp_send_json_error( [ 'message' => __( 'Commande introuvable ou non assignée à vous.', 'cod-tracker-crm' ) ], 403 );
		}
		return $order;
	}

	/** Données d'ouverture de la fenêtre : contextes, suggestion, langue, canaux disponibles. */
	public static function ajax_init(): void {
		$o      = self::guard_order();
		$phone8 = CODCRM_Work_Queue::norm_phone( $o->get_billing_phone() );

		wp_send_json_success( [
			'contexts'  => self::contexts(),
			'suggested' => self::suggest( $o ),
			'lang'      => self::detect_lang( $o ),
			'sms'       => CODCRM_SMS_Gateway::is_configured(),
			'phone8'    => $phone8,
		] );
	}

	/** Texte d'un modèle rendu pour la commande. */
	public static function ajax_preview(): void {
		$o       = self::guard_order();
		$ctx     = sanitize_key( wp_unslash( $_POST['context'] ?? '' ) );
		$lang    = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );
		$channel = sanitize_key( wp_unslash( $_POST['channel'] ?? '' ) );

		if ( ! isset( self::contexts()[ $ctx ] ) || ! in_array( $lang, self::LANGS, true ) || ! in_array( $channel, self::CHANNELS, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Modèle invalide.', 'cod-tracker-crm' ) ] );
		}
		wp_send_json_success( [ 'text' => self::message( $o, $ctx, $lang, $channel ) ] );
	}
}
