<?php
/**
 * Localisation des données de commande → français.
 *
 * Les boutiques tunisiennes opèrent souvent en arabe : le client remplit
 * le gouvernorat et la ville en arabe au checkout, alors que les APIs des
 * transporteurs (Colissimo TN, FirstDelivery, Jetpack, Fiabilo) attendent
 * des valeurs françaises/latines. Cette classe traduit gouvernorat + ville
 * arabe→français avant validation et envoi.
 *
 * Aucune interprétation de codes (TN-XX, TN61…) : les thèmes utilisent
 * leur propre numérotation, sans rapport avec les codes ISO officiels —
 * interpréter « TN-23 » comme Jendouba a déjà envoyé des colis destinés
 * à Bizerte vers le mauvais gouvernorat. Les valeurs non reconnues
 * (codes inclus) sont retournées telles quelles.
 *
 * @package WC_MultiCarrier_Sync
 */

defined( 'ABSPATH' ) || exit;

class WCMCS_Localization {

	// ─────────────────────────────────────────────
	//  Gouvernorats arabe → français (24 + variantes)
	// ─────────────────────────────────────────────

	const AR_GOVERNORATS = [
		'تونس'       => 'Tunis',
		'أريانة'     => 'Ariana',
		'بن عروس'    => 'Ben Arous',
		'باجة'       => 'Béja',
		'بجا'        => 'Béja',
		'بنزرت'      => 'Bizerte',
		'قابس'       => 'Gabès',
		'قفصة'       => 'Gafsa',
		'جندوبة'     => 'Jendouba',
		'القيروان'   => 'Kairouan',
		'القصرين'    => 'Kasserine',
		'قبلي'       => 'Kébili',
		'منوبة'      => 'La Manouba',
		'الكاف'      => 'Le Kef',
		'المهدية'    => 'Mahdia',
		'مدنين'      => 'Médenine',
		'المنستير'   => 'Monastir',
		'نابل'       => 'Nabeul',
		'صفاقس'      => 'Sfax',
		'سيدي بوزيد' => 'Sidi Bouzid',
		'سليانة'     => 'Siliana',
		'سوسة'       => 'Sousse',
		'تطاوين'     => 'Tataouine',
		'توزر'       => 'Tozeur',
		'توزرة'      => 'Tozeur',
		'زغوان'      => 'Zaghouan',
	];

	// ─────────────────────────────────────────────
	//  Villes majeures arabe → français
	// ─────────────────────────────────────────────

	const AR_VILLES = [
		// Capitales de gouvernorat
		'تونس'       => 'Tunis',
		'أريانة'     => 'Ariana',
		'بن عروس'    => 'Ben Arous',
		'باجة'       => 'Béja',
		'بنزرت'      => 'Bizerte',
		'قابس'       => 'Gabès',
		'قفصة'       => 'Gafsa',
		'جندوبة'     => 'Jendouba',
		'القيروان'   => 'Kairouan',
		'القصرين'    => 'Kasserine',
		'قبلي'       => 'Kébili',
		'منوبة'      => 'La Manouba',
		'الكاف'      => 'Le Kef',
		'المهدية'    => 'Mahdia',
		'مدنين'      => 'Médenine',
		'المنستير'   => 'Monastir',
		'نابل'       => 'Nabeul',
		'صفاقس'      => 'Sfax',
		'سيدي بوزيد' => 'Sidi Bouzid',
		'سليانة'     => 'Siliana',
		'سوسة'       => 'Sousse',
		'تطاوين'     => 'Tataouine',
		'توزر'       => 'Tozeur',
		'زغوان'      => 'Zaghouan',
		// Villes (non capitales)
		'الحمامات'   => 'Hammamet',
		'جربة'       => 'Djerba',
		'جرجيس'      => 'Zarzis',
		'الجم'       => 'El Jem',
		'دوز'        => 'Douz',
		'نفتا'       => 'Nefta',
		'طبرقة'      => 'Tabarka',
		'عين دراهم'  => 'Aïn Draham',
		'قليبية'     => 'Kélibia',
		'المرسى'     => 'La Marsa',
		'رادس'       => 'Radès',
		'المكنين'    => 'Moknine',
		'مساكن'      => 'Msaken',
		'النفيضة'    => 'Enfidha',
		'حلق الوادي' => 'La Goulette',
		'قرطاج'      => 'Carthage',
		'سيدي بوسعيد' => 'Sidi Bou Said',
		'بن قردان'   => 'Ben Gardane',
		'القلعة الكبرى' => 'Kalaa Kebira',
		'القلعة الصغرى' => 'Kalaa Sghira',
		'سيدي الهني' => 'Sidi El Heni',
		'بوفيشة'     => 'Bouficha',
		'هرقلة'      => 'Hergla',
		'أكودة'      => 'Akouda',
		'حمام سوسة'  => 'Hammam Sousse',
		'جمال'       => 'Jammel',
		'بقطة'       => 'Bekalta',
		'الساحلية'    => 'Sahline',
		'قصيبة المديونة' => 'Ksibet Médiouni',
		'طبربة'      => 'Tebourba',
		'وادي الليل' => 'Oued Ellil',
		'الフトاحة'   => 'Ettadhamen',
		'السوقرة'    => 'La Soukra',
		'رواد'       => 'Raoued',
		'سيدي ثابت'  => 'Sidi Thabet',
		'قلعة الأندلس' => 'Kalâat el-Andalous',
		'مرناق'      => 'Mornag',
		'فشنة'       => 'Fouchana',
		'مقرين'      => 'Mégrine',
		'الهمامة'    => 'El Hamma',
		'متماطة'     => 'Matmata',
		'متلويل'     => 'Metlaoui',
		'رديدف'      => 'Redeyef',
		'حومة السوق' => 'Houmt Souk',
		// Délégations supplémentaires (fallback ville → gouvernorat, capital
		// de + en + courant sur les checkouts arabes dont l'État contient un
		// code) — chaque valeur correspond à une clé latine de
		// VILLES_TO_GOUVERNORAT (résolution garantie).
		// Bizerte
		'سجنان'        => 'Sejnane',
		'منزل بورقيبة' => 'Menzel Bourguiba',
		'منزل جميل'    => 'Menzel Jemil',
		'غار المل'     => 'Ghar El Melh',
		// Béja
		'نفزة'         => 'Nefza',
		'تبرسق'        => 'Teboursouk',
		// Jendouba
		'فرنانة'       => 'Fernana',
		'بوسالم'       => 'Bou Salem',
		'غار الدماء'   => 'Ghardimaou',
		// Kairouan
		'سبيخة'        => 'Sbikha',
		'حفوز'         => 'Haffouz',
		// Kasserine
		'تالة'         => 'Thala',
		'فريانة'       => 'Feriana',
		'صبيطلة'       => 'Sbeitla',
		'فقصانة'       => 'Foussana',
		// Kébili
		'سبرة'         => 'Sabria',
		'فطناسة'       => 'Fatnassa',
		// Tataouine
		'غمراسن'       => 'Ghomrassen',
		'بني خداش'     => 'Beni Khedache',
		// Tozeur
		'دقاش'         => 'Degache',
		'تامزر'        => 'Tamerza',
		// Nabeul
		'كوربة'        => 'Korba',
		'منزل تميم'    => 'Menzel Temime',
		'بني خيار'     => 'Beni Khiar',
		// Sidi Bouzid
		'رقاب'         => 'Regueb',
		'جلما'         => 'Jilma',
		'المكناسي'     => 'Meknassy',
	];

	// ─────────────────────────────────────────────
	//  Gouvernorats tunisiens — noms français canoniques (24)
	// ─────────────────────────────────────────────

	const GOUVERNORATS_FR = [
		'Ariana', 'Béja', 'Ben Arous', 'Bizerte', 'Gabès',
		'Gafsa', 'Jendouba', 'Kairouan', 'Kasserine', 'Kébili',
		'Le Kef', 'La Manouba', 'Mahdia', 'Médenine', 'Monastir',
		'Nabeul', 'Sfax', 'Sidi Bouzid', 'Siliana', 'Sousse',
		'Tataouine', 'Tozeur', 'Tunis', 'Zaghouan',
	];

	/**
	 * Gouvernorats STRICTS par transporteur — TOUJOURS EN FRANÇAIS.
	 * clé   = gouvernorat canonique (résolu par translate_gouvernorat) ;
	 * valeur = orthographe EXACTE exigée par la doc du transporteur.
	 *
	 * '*' = défaut : identité française canonique (FirstDelivery, ADEX,
	 * Intigo, Aramex, Jetpack, Navex, Fiabilo, Ciblex, AFEX, Shipper —
	 * leurs docs attendent les noms français standard).
	 * Colissimo : liste stricte SANS accents de leur doc (Beja, Gabes,
	 * Kebili, LE Kef, Mannouba, Medenine…).
	 */
	const CARRIER_GOUVERNORATS = [
		'*' => [
			'Ariana' => 'Ariana', 'Béja' => 'Béja', 'Ben Arous' => 'Ben Arous', 'Bizerte' => 'Bizerte',
			'Gabès' => 'Gabès', 'Gafsa' => 'Gafsa', 'Jendouba' => 'Jendouba', 'Kairouan' => 'Kairouan',
			'Kasserine' => 'Kasserine', 'Kébili' => 'Kébili', 'Le Kef' => 'Le Kef', 'La Manouba' => 'La Manouba',
			'Mahdia' => 'Mahdia', 'Médenine' => 'Médenine', 'Monastir' => 'Monastir', 'Nabeul' => 'Nabeul',
			'Sfax' => 'Sfax', 'Sidi Bouzid' => 'Sidi Bouzid', 'Siliana' => 'Siliana', 'Sousse' => 'Sousse',
			'Tataouine' => 'Tataouine', 'Tozeur' => 'Tozeur', 'Tunis' => 'Tunis', 'Zaghouan' => 'Zaghouan',
		],
		'colissimo' => [
			'Ariana' => 'Ariana', 'Béja' => 'Beja', 'Ben Arous' => 'Ben Arous', 'Bizerte' => 'Bizerte',
			'Gabès' => 'Gabes', 'Gafsa' => 'Gafsa', 'Jendouba' => 'Jendouba', 'Kairouan' => 'Kairouan',
			'Kasserine' => 'Kasserine', 'Kébili' => 'Kebili', 'Le Kef' => 'LE Kef', 'La Manouba' => 'Mannouba',
			'Mahdia' => 'Mahdia', 'Médenine' => 'Medenine', 'Monastir' => 'Monastir', 'Nabeul' => 'Nabeul',
			'Sfax' => 'Sfax', 'Sidi Bouzid' => 'Sidi Bouzid', 'Siliana' => 'Siliana', 'Sousse' => 'Sousse',
			'Tataouine' => 'Tataouine', 'Tozeur' => 'Tozeur', 'Tunis' => 'Tunis', 'Zaghouan' => 'Zaghouan',
		],
	];

	/**
	 * Gouvernorat final pour un transporteur : résolution (code WC, arabe,
	 * latin → canonique) PUIS constante stricte key→value de la doc.
	 *
	 * @param string $raw_state Champ état/gouvernorat brut de la commande.
	 * @param string $raw_ville Champ ville (fallback si l'état est vide/illisible).
	 * @param string $carrier   Slug transporteur (clé de CARRIER_GOUVERNORATS).
	 * @return string|null Orthographe EXACTE de la doc, en français ; null = hors liste.
	 */
	public static function carrier_gouvernorat( string $raw_state, string $raw_ville, string $carrier ): ?string {
		$canonical = self::translate_gouvernorat( $raw_state, $raw_ville );
		if ( '' === $canonical ) {
			return null;
		}
		$map = self::CARRIER_GOUVERNORATS[ $carrier ] ?? self::CARRIER_GOUVERNORATS['*'];
		return $map[ $canonical ] ?? null;
	}

	/**
	 * Liste des valeurs acceptées par un transporteur (orthographe doc), pour
	 * les messages d'erreur et les réglages.
	 *
	 * @return string[]
	 */
	public static function carrier_gouvernorat_list( string $carrier ): array {
		return array_values( self::CARRIER_GOUVERNORATS[ $carrier ] ?? self::CARRIER_GOUVERNORATS['*'] );
	}

	// ─────────────────────────────────────────────
	//  Villes/délégations → gouvernorats (fallback)
	//  Clés normalisées (minuscules + sans accents + alphanumérique).
	// ─────────────────────────────────────────────

	const VILLES_TO_GOUVERNORAT = [
		// NB (revue de code 2.10.0) : les clés DOIVENT être au format norm_key
		// (minuscules + sans accents + alphanumérique) — le lookup ne matche
		// jamais les CamelCase/espaces. Ambiguïtés documentées : « chebika »
		// existe chez Kairouan ET Tozeur (dernière entrée = Tozeur, retenue),
		// « elhamma » = Gabès (délégation officielle — l'entrée Ben Arous
		// était erronée, supprimée).
		// Ariana
		'ariana' => 'Ariana', 'arianaville' => 'Ariana', 'ettadhamen' => 'Ariana', 'lasoukra' => 'Ariana', 'raoued' => 'Ariana', 'sidithabet' => 'Ariana', 'sidthabet' => 'Ariana', 'kalaatelandalous' => 'Ariana', 'kalaatandalous' => 'Ariana', 'ouedellil' => 'Ariana', 'ouededellil' => 'Ariana',
		// Béja
		'beja' => 'Béja', 'nefza' => 'Béja', 'teboursouk' => 'Béja',
		// Ben Arous
		'benarous' => 'Ben Arous', 'elmourouj' => 'Ben Arous', 'hammamlif' => 'Ben Arous', 'megrine' => 'Ben Arous', 'rades' => 'Ben Arous', 'fouchana' => 'Ben Arous', 'mornag' => 'Ben Arous',
		// Bizerte
		'bizerte' => 'Bizerte', 'menzelbourguiba' => 'Bizerte', 'menzeljemil' => 'Bizerte', 'sejnane' => 'Bizerte', 'gharelmel' => 'Bizerte', 'gharelmelh' => 'Bizerte',
		// Gabès
		'gabes' => 'Gabès', 'elhamma' => 'Gabès', 'matmata' => 'Gabès', 'metouia' => 'Gabès', 'oueddham' => 'Gabès',
		// Gafsa
		'gafsa' => 'Gafsa', 'metlaoui' => 'Gafsa', 'redeyef' => 'Gafsa', 'moulars' => 'Gafsa', 'sened' => 'Gafsa',
		// Jendouba
		'jendouba' => 'Jendouba', 'tabarka' => 'Jendouba', 'aindraham' => 'Jendouba', 'fernana' => 'Jendouba', 'boussalem' => 'Jendouba', 'bousalem' => 'Jendouba', 'ghardimaou' => 'Jendouba',
		// Kairouan
		'kairouan' => 'Kairouan', 'sbikha' => 'Kairouan', 'bouhajla' => 'Kairouan', 'haffouz' => 'Kairouan',
		// Kasserine
		'kasserine' => 'Kasserine', 'thala' => 'Kasserine', 'feriana' => 'Kasserine', 'sbeitla' => 'Kasserine', 'foussana' => 'Kasserine', 'majelbelabbes' => 'Kasserine',
		// Kébili
		'kebili' => 'Kébili', 'douz' => 'Kébili', 'sabria' => 'Kébili', 'fatnassa' => 'Kébili',
		// Le Kef
		'lekef' => 'Le Kef', 'kef' => 'Le Kef', 'dahmani' => 'Le Kef', 'tajerouine' => 'Le Kef', 'nebeur' => 'Le Kef', 'kalaasenan' => 'Le Kef',
		// Mahdia
		'mahdia' => 'Mahdia', 'eljem' => 'Mahdia', 'ksoursessef' => 'Mahdia', 'chebba' => 'Mahdia', 'oulouj' => 'Mahdia', 'bradai' => 'Mahdia', 'chottmeriem' => 'Mahdia',
		// La Manouba
		'manouba' => 'La Manouba', 'lamanouba' => 'La Manouba', 'tebourba' => 'La Manouba', 'douchatel' => 'La Manouba', 'borjelamri' => 'La Manouba',
		// Médenine
		'medenine' => 'Médenine', 'bengardane' => 'Médenine', 'zarzis' => 'Médenine', 'djerba' => 'Médenine', 'jerba' => 'Médenine', 'jerbamidoun' => 'Médenine', 'jerbahoumtssouk' => 'Médenine', 'houmtsouk' => 'Médenine', 'jerguibi' => 'Médenine', 'benikhdache' => 'Médenine', 'sidimakhlouf' => 'Médenine',
		// Monastir
		'monastir' => 'Monastir', 'ksarhellal' => 'Monastir', 'ksibetmediouni' => 'Monastir', 'sahline' => 'Monastir', 'jammel' => 'Monastir', 'kerkenah' => 'Monastir', 'bekalta' => 'Monastir', 'moknine' => 'Monastir', 'welladlejmi' => 'Monastir', 'lamtah' => 'Monastir', 'soussedjedid' => 'Monastir',
		// Nabeul
		'nabeul' => 'Nabeul', 'hammamet' => 'Nabeul', 'kelibia' => 'Nabeul', 'korba' => 'Nabeul', 'menzeltemime' => 'Nabeul', 'soliman' => 'Nabeul', 'grombalia' => 'Nabeul', 'benikhiar' => 'Nabeul', 'elhaouaria' => 'Nabeul', 'takelsa' => 'Nabeul', 'korbous' => 'Nabeul',
		// Sfax
		'sfax' => 'Sfax', 'sakietezzit' => 'Sfax', 'sakietsidiyoussef' => 'Sfax', 'elain' => 'Sfax', 'chaffar' => 'Sfax', 'gharib' => 'Sfax', 'jebiniana' => 'Sfax', 'kerkena' => 'Sfax', 'elguettar' => 'Sfax', 'agra' => 'Sfax', 'thyna' => 'Sfax', 'mahres' => 'Sfax', 'soukkhal' => 'Sfax',
		// Sidi Bouzid
		'sidibouzid' => 'Sidi Bouzid', 'regueb' => 'Sidi Bouzid', 'meknassy' => 'Sidi Bouzid', 'jilma' => 'Sidi Bouzid', 'cbbabaou' => 'Sidi Bouzid', 'menzelbouzaiana' => 'Sidi Bouzid',
		// Siliana
		'siliana' => 'Siliana', 'lekrib' => 'Siliana', 'gaafour' => 'Siliana', 'bouarada' => 'Siliana', 'rouhia' => 'Siliana', 'maktar' => 'Siliana', 'kesra' => 'Siliana',
		// Sousse
		'sousse' => 'Sousse', 'msaken' => 'Sousse', 'kalaasghira' => 'Sousse', 'kalaakebira' => 'Sousse', 'hammamsousse' => 'Sousse', 'akouda' => 'Sousse', 'bouficha' => 'Sousse', 'enfidha' => 'Sousse', 'hergla' => 'Sousse', 'zaouiasousse' => 'Sousse', 'sidielheni' => 'Sousse', 'messadthine' => 'Sousse',
		// Tataouine
		'tataouine' => 'Tataouine', 'benikhedache' => 'Tataouine', 'ghomrassen' => 'Tataouine', 'smar' => 'Tataouine', 'briktou' => 'Tataouine',
		// Tozeur
		'tozeur' => 'Tozeur', 'nefta' => 'Tozeur', 'degache' => 'Tozeur', 'tamerza' => 'Tozeur', 'chebika' => 'Tozeur',
		// Tunis
		'tunis' => 'Tunis', 'lamarsa' => 'Tunis', 'carthage' => 'Tunis', 'lebardo' => 'Tunis', 'elouardia' => 'Tunis', 'elmenzah' => 'Tunis', 'lagoulette' => 'Tunis', 'sidibousaid' => 'Tunis', 'babsouika' => 'Tunis', 'babbhar' => 'Tunis', 'elfolla' => 'Tunis', 'jellaz' => 'Tunis', 'montplaisir' => 'Tunis', 'elomrane' => 'Tunis', 'medina' => 'Tunis', 'cidhibou' => 'Tunis', 'denden' => 'Tunis', 'elkhadra' => 'Tunis', 'bourguiba' => 'Tunis',
		// Zaghouan
		'zaghouan' => 'Zaghouan', 'nadhour' => 'Zaghouan', 'birmchergua' => 'Zaghouan', 'elfahs' => 'Zaghouan', 'zriba' => 'Zaghouan', 'souid' => 'Zaghouan',
	];

	// ─────────────────────────────────────────────
	//  API
	// ─────────────────────────────────────────────

	/**
	 * La valeur contient-elle du script arabe ?
	 */
	public static function is_arabic( string $value ): bool {
		return (bool) preg_match( '/[\x{0600}-\x{06FF}]/u', $value );
	}

	/**
	 * Décode un code d'état WooCommerce tunisien (TN-12…) via la liste
	 * d'états DU SITE (wc_states TN) — source exacte qui a produit le code,
	 * quelle que soit la numérotation/la langue (arabe ou français) choisie
	 * par le site ou le thème. Le libellé décodé repart ensuite dans le
	 * pipeline normal (arabe → français / latin → français canonique).
	 *
	 * @param string $raw Valeur brute du champ état (ex: « TN-12 », « tn12 »).
	 * @return string Libellé de la liste d'états ('' si ce n'est pas un code connu).
	 */
	private static function resolve_wc_state_code( string $raw ): string {
		$code = strtoupper( preg_replace( '/[\s_]+/', '-', trim( $raw ) ) );
		if ( ! preg_match( '/^TN-\d{1,2}$/', $code ) ) {
			return '';
		}
		if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
			return '';
		}
		$states = WC()->countries->get_states( 'TN' );
		if ( ! is_array( $states ) || empty( $states ) ) {
			return '';
		}
		foreach ( $states as $key => $label ) {
			if ( strtoupper( (string) $key ) === $code && is_string( $label ) && '' !== trim( $label ) ) {
				return trim( $label );
			}
		}
		return '';
	}

	/**
	 * Traduit un gouvernorat (arabe ou latin, casse/accents libres) en
	 * français (orthographe canonique).
	 *
	 * Codes d'état WooCommerce (TN-XX) : décodés via la liste d'états du site
	 * lui-même (autorité du code) — jamais via une table ISO figée, la
	 * numérotation pouvant être personnalisée par le site/le thème. Le libellé
	 * décodé (arabe ou latin) suit ensuite la traduction normale.
	 *
	 * Ordre de résolution : 0. code état WC · 1. arabe gouvernorat ·
	 * 2. nom latin (casse/accents libres) · 3. fallback ville exacte ·
	 * 4. fallback ville préfixe · 5. fallback gouvernorat contenu.
	 *
	 * @param string $raw   Valeur du champ gouvernorat.
	 * @param string $ville Valeur du champ ville/délégation (fallback optionnel).
	 * @return string Nom français exact, ou valeur inchangée si non résolu.
	 */
	public static function translate_gouvernorat( string $raw, string $ville = '' ): string {
		$raw   = trim( $raw );
		$ville = trim( $ville );
		if ( '' === $raw ) {
			return $raw;
		}

		// 0. ⭐ CODE ÉTAT WOOCOMMERCE (TN-12…) : libellé du site puis pipeline normal.
		$wc_label = self::resolve_wc_state_code( $raw );
		if ( '' !== $wc_label ) {
			$raw = $wc_label;
		}

		// 1. Arabe gouvernorat → français.
		if ( self::is_arabic( $raw ) ) {
			$key = self::arabic_key( $raw );
			foreach ( self::AR_GOVERNORATS as $arabic => $french ) {
				if ( self::arabic_key( $arabic ) === $key ) {
					return $french;
				}
			}

			// Pas un gouvernorat arabe → peut-être une ville/délégation arabe
			// mal placée dans le champ gouvernorat → fallback sur le raw.
			foreach ( [ 'resolve_gouvernorat_from_ville', 'resolve_gouvernorat_from_ville_prefix', 'resolve_gouvernorat_from_ville_contains' ] as $resolver ) {
				$gouvernorat = self::$resolver( $raw );
				if ( '' !== $gouvernorat ) {
					return $gouvernorat;
				}
			}
		}

		// 2. Nom latin → gouvernorat français (casse/accents libres : « tunis »,
		//    « SFAX », « benarous », « le kef »…).
		$norm_raw = self::norm_key( $raw );
		if ( '' !== $norm_raw ) {
			foreach ( self::GOUVERNORATS_FR as $french ) {
				if ( self::norm_key( $french ) === $norm_raw ) {
					return $french;
				}
			}

			// 2bis. ⭐ ARTICLE OMIS : les libellés peuvent arriver sans article
			// (« Manouba », « Kef » — p.ex. états WC traduits en français par
			// le thème). Comparaison avec l'article retiré DES DEUX côtés
			// (le/la/les/el) : aucune collision parmi les 24 gouvernorats.
			$stripped = self::strip_article_norm( $norm_raw );
			if ( '' !== $stripped ) {
				foreach ( self::GOUVERNORATS_FR as $french ) {
					if ( self::strip_article_norm( self::norm_key( $french ) ) === $stripped ) {
						return $french;
					}
				}
			}
		}

		// 3. ⭐ FALLBACK VILLE EXACTE : gouvernorat non résolu + ville fournie.
		if ( '' !== $ville ) {
			$gouvernorat = self::resolve_gouvernorat_from_ville( $ville );
			if ( '' !== $gouvernorat ) {
				return $gouvernorat;
			}
		}

		// 4. ⭐ FALLBACK VILLE PRÉFIXE : la ville commence par une ville/gouvernorat
		//    connu (ex: « سوسة الرياض » commence par « سوسة » + espace).
		if ( '' !== $ville ) {
			$gouvernorat = self::resolve_gouvernorat_from_ville_prefix( $ville );
			if ( '' !== $gouvernorat ) {
				return $gouvernorat;
			}
		}

		// 5. ⭐ FALLBACK GOUVERNORAT CONTENU : un des 24 gouvernorats (arabe
		//    ou français) apparaît comme mot complet dans la ville.
		if ( '' !== $ville ) {
			$gouvernorat = self::resolve_gouvernorat_from_ville_contains( $ville );
			if ( '' !== $gouvernorat ) {
				return $gouvernorat;
			}
		}

		return $raw;
	}

	/**
	 * Résout le gouvernorat depuis une ville/délégation (fallback).
	 *
	 * @param string $ville Valeur du champ ville/délégation (peut être en arabe).
	 * @return string Nom français du gouvernorat, ou '' si non trouvé.
	 */
	public static function resolve_gouvernorat_from_ville( string $ville ): string {
		$ville = trim( $ville );
		if ( '' === $ville ) {
			return '';
		}

		// Si arabe → traduire d'abord en français via AR_VILLES.
		if ( self::is_arabic( $ville ) ) {
			$key = self::arabic_key( $ville );
			foreach ( self::AR_VILLES as $arabic => $french ) {
				if ( self::arabic_key( $arabic ) === $key ) {
					$ville = $french;
					break;
				}
			}
		}

		// Normaliser la ville (minuscules, sans accents, alphanumérique).
		$norm = self::norm_key( $ville );
		if ( '' === $norm ) {
			return '';
		}

		return self::VILLES_TO_GOUVERNORAT[ $norm ] ?? '';
	}

	/**
	 * FALLBACK VILLE PREFIXE : la ville commence par une ville/gouvernorat
	 * connu (frontière de mot) → gouvernorat correspondant.
	 *
	 * « سوسة الرياض » commence par « سوسة » + espace → Sousse.
	 * « Ariana Zone Industrielle » commence par « Ariana » + espace → Ariana.
	 * Clé trop courte (< 4 caractères) ignorée (faux positifs).
	 */
	public static function resolve_gouvernorat_from_ville_prefix( string $ville ): string {
		$ville = trim( $ville );
		if ( '' === $ville || false === mb_strpos( $ville, ' ', 0, 'UTF-8' ) ) {
			return ''; // Un seul mot : rien à découper (l'exact match a déjà échoué).
		}

		$words = preg_split( '/\s+/u', $ville );
		if ( ! is_array( $words ) || count( $words ) < 2 ) {
			return '';
		}

		// Préfixes progressifs : mot 1, mots 1-2, mots 1-3.
		for ( $take = 1; $take <= 3 && $take < count( $words ); $take++ ) {
			$prefix = implode( ' ', array_slice( $words, 0, $take ) );

			// Arabe : chercher dans AR_VILLES + AR_GOVERNORATS.
			if ( self::is_arabic( $prefix ) ) {
				$key = self::arabic_key( $prefix );
				foreach ( self::AR_VILLES as $arabic => $french ) {
					if ( mb_strlen( $arabic, 'UTF-8' ) >= 4 && self::arabic_key( $arabic ) === $key ) {
						return self::gouvernorat_of_ville_francaise( $french );
					}
				}
				foreach ( self::AR_GOVERNORATS as $arabic => $french ) {
					if ( mb_strlen( $arabic, 'UTF-8' ) >= 4 && self::arabic_key( $arabic ) === $key ) {
						return $french;
					}
				}
				continue; // Un préfixe arabe non trouvé → étendre.
			}

			// Latin : chercher dans VILLES_TO_GOUVERNORAT + noms de gouvernorats.
			$norm = self::norm_key( $prefix );
			if ( mb_strlen( $norm ) >= 4 ) {
				if ( isset( self::VILLES_TO_GOUVERNORAT[ $norm ] ) ) {
					return self::VILLES_TO_GOUVERNORAT[ $norm ];
				}
				foreach ( self::GOUVERNORATS_FR as $french ) {
					if ( self::norm_key( $french ) === $norm ) {
						return $french;
					}
				}
			}
		}

		return '';
	}

	/**
	 * FALLBACK GOUVERNORAT CONTENU : un des 24 gouvernorats (arabe ou
	 * français) apparaît comme mot complet dans la ville/délégation.
	 *
	 * « سوسة الرياض » contient le mot « سوسة » = gouvernorat Sousse → Sousse.
	 */
	public static function resolve_gouvernorat_from_ville_contains( string $ville ): string {
		$ville = trim( $ville );
		if ( '' === $ville ) {
			return '';
		}

		$words = preg_split( '/\s+/u', $ville );
		if ( ! is_array( $words ) || empty( $words ) ) {
			return '';
		}

		// Paires de mots adjacents (ex : « بن عروس », « سidi bouzid »).
		$pairs = [];
		for ( $i = 0; $i < count( $words ) - 1; $i++ ) {
			$pairs[] = [ $words[ $i ], $words[ $i + 1 ] ];
		}

		foreach ( $words as $word ) {
			// Arabe : mot = gouvernorat arabe ?
			if ( self::is_arabic( $word ) ) {
				$key = self::arabic_key( $word );
				foreach ( self::AR_GOVERNORATS as $arabic => $french ) {
					if ( self::arabic_key( $arabic ) === $key ) {
						return $french;
					}
				}
				continue;
			}

			// Latin : mot = gouvernorat français ?
			$norm = self::norm_key( $word );
			if ( '' !== $norm ) {
				foreach ( self::GOUVERNORATS_FR as $french ) {
					if ( self::norm_key( $french ) === $norm ) {
						return $french;
					}
				}
			}
		}

		// Gouvernorats à deux mots (« بن عروس », « Sidi Bouzid », « Ben Arous »…).
		foreach ( $pairs as [ $w1, $w2 ] ) {
			$pair = $w1 . ' ' . $w2;

			if ( self::is_arabic( $pair ) ) {
				$key = self::arabic_key( $pair );
				foreach ( self::AR_GOVERNORATS as $arabic => $french ) {
					if ( self::arabic_key( $arabic ) === $key ) {
						return $french;
					}
				}
				continue;
			}

			$norm = self::norm_key( $pair );
			if ( '' !== $norm ) {
				foreach ( self::GOUVERNORATS_FR as $french ) {
					if ( self::norm_key( $french ) === $norm ) {
						return $french;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Gouvernorat d'une ville française connue (via AR_VILLES/VILLES_TO_GOUVERNORAT).
	 * La ville elle-même peut être un gouvernorat (ex : « Sousse »).
	 */
	private static function gouvernorat_of_ville_francaise( string $ville ): string {
		$norm = self::norm_key( $ville );
		if ( '' === $norm ) {
			return '';
		}
		if ( isset( self::VILLES_TO_GOUVERNORAT[ $norm ] ) ) {
			return self::VILLES_TO_GOUVERNORAT[ $norm ];
		}
		foreach ( self::GOUVERNORATS_FR as $french ) {
			if ( self::norm_key( $french ) === $norm ) {
				return $french;
			}
		}
		return '';
	}

	// ─────────────────────────────────────────────
	//  Normalisation
	// ─────────────────────────────────────────────

	/**
	 * Normalisation générique : minuscules, sans accents, alphanumérique.
	 */
	private static function norm_key( string $s ): string {
		$s = remove_accents( mb_strtolower( trim( $s ), 'UTF-8' ) );
		$s = preg_replace( '/[^a-z0-9]/', '', $s );
		return trim( (string) $s );
	}

	/**
	 * Norme sans article défini (le/la/les/el) — « lamanouba » → « manouba »,
	 * « lekef » → « kef ». Utilisé pour la correspondance à article omis.
	 */
	private static function strip_article_norm( string $norm ): string {
		return (string) preg_replace( '/^(le|la|les|el)/', '', $norm );
	}

	/**
	 * Traduit une ville arabe en français — et canonise les villes latines
	 * connues (casse/accents libres : « LA MARSA », « la marsa » → « La Marsa »).
	 *
	 * Arabe introuvable dans la table → $fallback_gouvernorat (gouvernorat
	 * résolu, déjà en français) ; fallback vide → valeur telle quelle.
	 */
	public static function translate_ville( string $raw, string $fallback_gouvernorat = '' ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return $raw;
		}

		if ( self::is_arabic( $raw ) ) {
			$key = self::arabic_key( $raw );
			foreach ( self::AR_VILLES as $arabic => $french ) {
				if ( self::arabic_key( $arabic ) === $key ) {
					return $french;
				}
			}

			$fallback = trim( $fallback_gouvernorat );
			return '' !== $fallback ? $fallback : $raw;
		}

		// Ville latine : orthographe canonique si elle est connue (casse/accents libres).
		$norm = self::norm_key( $raw );
		if ( '' !== $norm ) {
			foreach ( self::AR_VILLES as $french ) {
				if ( self::norm_key( $french ) === $norm ) {
					return $french;
				}
			}
		}

		return $raw;
	}

	// ─────────────────────────────────────────────
	//  Désignation FR des articles (payloads transporteurs)
	// ─────────────────────────────────────────────

	/** @var array<string,string> Cache « nom FR » par produit parent ( clé = ID ). */
	private static array $product_fr_names = [];

	/** @var array<string,string> Cache des terms FR ( clé = « taxo|slug », '' = pas de traduction ). */
	private static array $term_fr_cache = [];

	/**
	 * Désignation française d'une ligne de commande, pour les payloads
	 * transporteurs (designation, article…).
	 *
	 * Les boutiques COD nomment produits et variations en arabe (« موكاسان
	 * كلاسيكي - أسود, 42 ») alors que les APIs transporteurs affichent des
	 * désignations françaises/latines. La désignation est recomposée pièce
	 * par pièce :
	 *
	 * - nom produit    : post meta `_wcmcs_name_fr` sur le parent → filtre
	 *   `wcmcs_product_name_fr( $fr, $parent_id, $original )` → titre exact ;
	 * - valeur attribute : term meta `wcmcs_term_fr` (recherche par slug PUIS
	 *   par libellé arabe) → filtre `wcmcs_term_fr( $fr, $taxo, $value )` →
	 *   valeur brute ; valeurs numériques (pointures 40–44) passées as-is ;
	 * - accents : supprimés du résultat (remove_accents) dès que le filtre
	 *   `wcmcs_designation_strip_accents` (défaut true) le veut — les docs
	 *   transporteurs montrent « Vetements » sans diacritiques ;
	 * - sans aucun mapping : titre exact + valeurs brutes (slugs, souvent
	 *   latines — « … - noir, 40 »), déployable sans risque.
	 *
	 * @param WC_Order_Item_Product $item Ligne de commande (produit/variation).
	 *   pas d'accents ; le provider ajoute ensuite « x » + la quantité.
	 * @return string Désignation prête pour `designation`/`article`.
	 */
	public static function item_designation_french( WC_Order_Item_Product $item ): string {
		$product = $item->get_product();

		// Item ghost/externe (produit supprimé) : nom stocké tel quel.
		if ( ! $product instanceof WC_Product ) {
			return self::strip_accents_designation( (string) $item->get_name() );
		}

		$is_variation = $product->is_type( 'variation' );
		$parent_id    = $is_variation ? (int) $product->get_parent_id() : (int) $product->get_id();
		$attributes   = $is_variation ? (array) $product->get_attributes() : [];

		$labels = [];
		foreach ( $attributes as $key => $value ) {
			$label = self::attribute_value_french( (string) $key, (string) $value );
			if ( '' !== $label ) {
				$labels[] = $label;
			}
		}

		$designation = self::product_name_french( $parent_id, $item );
		if ( $labels ) {
			$designation .= ' - ' . implode( ', ', $labels );
		}

		return $designation;
	}

	/**
	 * Nom FR d'un produit (parent ou simple), avec cache par requête.
	 *
	 * @param int    $parent_id ID du produit parent (ou du simple).
	 * @param mixed  $item     Item consulté (fallback nom natif si produit orphelin).
	 * @return string
	 */
	private static function product_name_french( int $parent_id, $item ): string {
		if ( isset( self::$product_fr_names[ $parent_id ] ) ) {
			return self::strip_accents_designation( self::$product_fr_names[ $parent_id ] );
		}

		$original = (string) get_the_title( $parent_id );
		if ( '' === $original ) {
			// Produit orphelin (titre vide) : nom natif de l'item (déjà complet).
			$original = (string) $item->get_name();
		}

		$fr = (string) get_post_meta( $parent_id, '_wcmcs_name_fr', true );

		if ( '' === $fr ) {
			$fr = (string) apply_filters( 'wcmcs_product_name_fr', '', $parent_id, $original );
		}

		if ( '' === $fr ) {
			$fr = $original;
		}

		self::$product_fr_names[ $parent_id ] = $fr;

		return self::strip_accents_designation( $fr );
	}

	/**
	 * Valeur d'attribute traduite en français.
	 *
	 * @param string $taxonomy Taxonomie (pa_color…) ou attribut personnalisé.
	 * @param string $value    Valeur brute (slug « noir » ou libellé « 42 »).
	 * @return string
	 */
	private static function attribute_value_french( string $taxonomy, string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		// Pointures/tailles numériques : pas de traduction nécessaire.
		if ( ctype_digit( $value ) ) {
			return $value;
		}

		$fr = '';

		if ( taxonomy_exists( $taxonomy ) ) {
			$fr = self::term_french( $taxonomy, $value );
		}

		if ( '' === $fr ) {
			$fr = (string) apply_filters( 'wcmcs_term_fr', $fr, $taxonomy, $value );
		}

		if ( '' === $fr ) {
			$fr = $value;
		}

		return self::strip_accents_designation( $fr );
	}

	/**
	 * Libellé FR d'un term d'attribut (cache inclus).
	 *
	 * Résolution : term par slug, puis term par libellé arabe (variations
	 * configurées avec des libellés bruts), puis term meta wcmcs_term_fr.
	 *
	 * @param string $taxonomy Taxonomie existante (pa_color…).
	 * @param string $value    Slug ou libellé stocké dans la variation.
	 * @return string '' si aucun mapping.
	 */
	private static function term_french( string $taxonomy, string $value ): string {
		$cache_key = $taxonomy . '|' . $value;

		if ( array_key_exists( $cache_key, self::$term_fr_cache ) ) {
			return self::$term_fr_cache[ $cache_key ];
		}

		$fr   = '';
		$term = get_term_by( 'slug', $value, $taxonomy );

		if ( ! $term instanceof WP_Term ) {
			// Valeur stockée en libellé (attribut taxo mais slug encodé) :
			// comparaison par clé arabe sur les noms de terms.
			$key = self::arabic_key( $value );
			if ( '' !== $key ) {
				foreach ( (array) get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] ) as $candidate ) {
					if ( $candidate instanceof WP_Term && self::arabic_key( $candidate->name ) === $key ) {
						$term = $candidate;
						break;
					}
				}
			}
		}

		if ( $term instanceof WP_Term ) {
			$fr = (string) get_term_meta( $term->term_id, 'wcmcs_term_fr', true );
		}

		self::$term_fr_cache[ $cache_key ] = $fr;

		return $fr;
	}

	/**
	 * Suppression des accents (paramétrable — défaut : oui, pour rester dans
	 * le système Latin des transporteurs).
	 *
	 * @param string $value Valeur à nettoyer.
	 * @return string
	 */
	private static function strip_accents_designation( string $value ): string {
		$strip = (bool) apply_filters( 'wcmcs_designation_strip_accents', true );
		return $strip ? remove_accents( $value ) : $value;
	}

	/**
	 * Clé de comparaison arabe : trim, préfixe « ولاية » retiré,
	 * variantes maghrébines normalisées (ڨ→ق, ڤ→ف),
	 * espaces (y compris insecables et marques de direction) retirés.
	 */
	private static function arabic_key( string $value ): string {
		$value = trim( $value );
		$value = preg_replace( '/^ولاية\s*/u', '', $value );
		// Variantes maghrébines : ڨ (keheh avec points) → ق (qaf), ڤ → ف
		$value = str_replace( "\u{06A8}", "\u{0642}", $value ); // ڨ → ق
		$value = str_replace( "\u{06A4}", "\u{0641}", $value ); // ڤ → ف
		$value = preg_replace( '/[\s\x{00A0}\x{200E}\x{200F}]+/u', '', $value );
		return trim( (string) $value );
	}
}
