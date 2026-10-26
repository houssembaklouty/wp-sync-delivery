<?php
/**
 * Delegations (معتمديات) par gouvernorat tunisien.
 *
 * Le jeu de donnees est envoye au navigateur via wp_localize_script(), la
 * cascade « gouvernorat -> delegation » se fait donc cote client sans aller-
 * retour AJAX : l'ensemble tient en quelques kilo-octets.
 *
 * Les cles correspondent exactement aux slugs de
 * wparabic_get_tunisian_governorates() dans inc/cod-form.php.
 *
 * @package wparabic-ecomm-cod
 */

defined('ABSPATH') || exit;



/**
 * Dictionnaire arabe -> français des délégations (affichage version FR).
 *
 * @return array<string, string>
 */
function wparabic_delegations_fr() {
    return array(
        // Tunis
        'قرطاج' => 'Carthage', 'المدينة' => 'Tunis Médina', 'باب البحر' => 'Bab Bhar',
        'باب سويقة' => 'Bab Souika', 'العمران' => 'El Omrane', 'العمران الأعلى' => 'El Omrane Supérieur',
        'التحرير' => 'El Tahrir', 'المنزه' => 'El Menzah', 'حي الخضراء' => 'Hay El Khadhra',
        'باردو' => 'Le Bardo', 'السيجومي' => 'Sijoumi', 'الزهور' => 'Ezzouhour',
        'الحرائرية' => 'El Hrairia', 'سيدي حسين' => 'Sidi Hussein', 'الوردية' => 'El Ouardia',
        'الكبارية' => 'El Kabaria', 'سيدي البشير' => 'Sidi El Béchir', 'جبل الجلود' => 'Jebel Jelloud',
        'حلق الوادي' => 'La Goulette', 'الكرم' => 'Le Kram', 'المرسى' => 'La Marsa',
        // Ariana
        'أريانة المدينة' => 'Ariana Ville', 'سكرة' => 'La Soukra', 'رواد' => 'Raoued',
        'قلعة الأندلس' => 'Kalâat el-Andalous', 'سيدي ثابت' => 'Sidi Thabet',
        'التضامن' => 'Ettadhamen', 'المنيهلة' => 'El Mnihla',
        // Ben Arous
        'بن عروس' => 'Ben Arous', 'المدينة الجديدة' => 'Ville Nouvelle', 'المروج' => 'El Mourouj',
        'حمام الأنف' => 'Hammam Lif', 'حمام الشط' => 'Hammam Chott', 'بومهل البساتين' => 'Boumhel El Bassatine',
        'الزهراء' => 'Ezzahra', 'رادس' => 'Radès', 'مقرين' => 'Mégrine',
        'المحمدية' => 'El Mohammedia', 'فوشانة' => 'Fouchana', 'مرناق' => 'Mornag',
        // Manouba
        'منوبة' => 'La Manouba', 'دوار هيشر' => 'Douar Hicher', 'وادي الليل' => 'Oued Ellil',
        'طبربة' => 'Tebourba', 'البطان' => 'El Batan', 'الجديدة' => 'Djedeïda',
        'المرناقية' => 'El Mornaguia', 'برج العامري' => 'Borj El Amri',
        // Nabeul
        'نابل' => 'Nabeul', 'دار شعبان الفهري' => 'Dar Chaabane El Fehri', 'بني خيار' => 'Beni Khiar',
        'قربة' => 'Korba', 'منزل تميم' => 'Menzel Temime', 'الميدة' => 'El Mida',
        'قليبية' => 'Kelibia', 'حمام الأغزاز' => 'Hammam El Guezaz', 'الهوارية' => 'El Haouaria',
        'تاكلسة' => 'Takelsa', 'سليمان' => 'Soliman', 'منزل بوزلفة' => 'Menzel Bouzelfa',
        'بني خلاد' => 'Beni Khalled', 'قرمبالية' => 'Grombalia', 'بوعرقوب' => 'Bou Argoub',
        'الحمامات' => 'Hammamet',
        // Zaghouan
        'زغوان' => 'Zaghouan', 'الزريبة' => 'Zriba', 'بئر مشارقة' => 'Bir Mchargui',
        'الفحص' => 'El Fahs', 'الناظور' => 'Nadhour', 'صواف' => 'Souaf',
        // Bizerte
        'بنزرت الشمالية' => 'Bizerte Nord', 'جرزونة' => 'Zarzouna', 'بنزرت الجنوبية' => 'Bizerte Sud',
        'سجنان' => 'Sejnane', 'جومين' => 'Joumine', 'ماطر' => 'Mateur',
        'غزالة' => 'Ghezala', 'منزل بورقيبة' => 'Menzel Bourguiba', 'تينجة' => 'Tinja',
        'أوتيك' => 'Utique', 'غار الملح' => 'Ghar El Melh', 'منزل جميل' => 'Menzel Jemil',
        'العالية' => 'El Alia', 'رأس الجبل' => 'Ras Jebel',
        // Béja
        'باجة الشمالية' => 'Béja Nord', 'باجة الجنوبية' => 'Béja Sud', 'عمدون' => 'Amdoun',
        'نفزة' => 'Nefza', 'تبرسق' => 'Téboursouk', 'تيبار' => 'Thibar',
        'تستور' => 'Testour', 'قبلاط' => 'Goubellat', 'مجاز الباب' => 'Medjez El Bab',
        // Jendouba
        'جندوبة' => 'Jendouba', 'جندوبة الشمالية' => 'Jendouba Nord', 'بوسالم' => 'Bou Salem',
        'طبرقة' => 'Tabarka', 'عين دراهم' => 'Aïn Draham', 'فرنانة' => 'Fernana',
        'غار الدماء' => 'Ghardimaou', 'وادي مليز' => 'Oued Meliz', 'بلطة بوعوان' => 'Balta Bou Aouane',
        // Le Kef
        'الكاف الغربية' => 'Le Kef Ouest', 'الكاف الشرقية' => 'Le Kef Est', 'نبر' => 'Nebeur',
        'الطويرف' => 'Touiref', 'ساقية سيدي يوسف' => 'Sakiet Sidi Youssef', 'تاجروين' => 'Tajerouine',
        'القلعة الخصبة' => 'Kalâat Senan', 'الجريصة' => 'Djerissa', 'القصور' => 'El Ksour',
        'الدهماني' => 'Dahmani', 'السرس' => 'Sers',
        // Siliana
        'سليانة الشمالية' => 'Siliana Nord', 'سليانة الجنوبية' => 'Siliana Sud', 'بوعرادة' => 'Bou Arada',
        'ڨعفور' => 'El Gaâfour', 'الكريب' => 'Le Krib', 'بورويس' => 'Bou Rouis',
        'مكثر' => 'Makthar', 'الروحية' => 'Rouhia', 'كسرى' => 'Kesra',
        'برڨو' => 'Bargou', 'العروسة' => 'El Aroussa',
        // Sousse
        'سوسة المدينة' => 'Sousse Médina', 'الزاوية القصيبة الثريات' => 'Zaouiet Ksiba Thrayet',
        'سوسة الرياض' => 'Sousse Riadh', 'سوسة جوهرة' => 'Sousse Jawhara',
        'سوسة سيدي عبد الحميد' => 'Sousse Sidi Abdelhamid', 'حمام سوسة' => 'Hammam Sousse',
        'أكودة' => 'Akouda', 'القلعة الكبرى' => 'Kalâa Kebira', 'القلعة الصغرى' => 'Kalâa Sghira',
        'سيدي بوعلي' => 'Sidi Bou Ali', 'هرقلة' => 'Hergla', 'النفيضة' => 'Enfidha',
        'بوفيشة' => 'Bouficha', 'كندار' => 'Kondar', 'سيدي الهاني' => 'Sidi El Héni',
        'المسعدين' => 'Msedrine',
        // Monastir
        'المنستير' => 'Monastir', 'الوردانين' => 'Ouerdanine', 'الساحلين' => 'Sahel Sud',
        'زرمدين' => 'Zeramdine', 'بني حسان' => 'Beni Hassen', 'جمال' => 'Jammel',
        'بنبلة' => 'Benbla', 'المكنين' => 'Moknine', 'البقالطة' => 'Bekalta',
        'طبلبة' => 'Téboulba', 'قصر هلال' => 'Ksar Hellal', 'قصيبة المديوني' => 'Ksibet Médiouni',
        'صيادة لمطة بوحجر' => 'Sayada-Lamta-Bouhjar',
        // Mahdia
        'المهدية' => 'Mahdia', 'بومرداس' => 'Boumerdès', 'أولاد الشامخ' => 'Ouled Chamekh',
        'شربان' => 'Chorbane', 'هبيرة' => 'Hebira', 'السواسي' => 'Souassi',
        'الجم' => 'El Jem', 'الشابة' => 'Chebba', 'ملولش' => 'Melloulèche',
        'سيدي علوان' => 'Sidi Alouane', 'قصور الساف' => 'Ksour Essaf',
        // Sfax
        'صفاقس المدينة' => 'Sfax Ville', 'صفاقس الغربية' => 'Sfax Ouest', 'ساقية الزيت' => 'Sakiet Ezzit',
        'ساقية الداير' => 'Sakiet Eddayer', 'صفاقس الجنوبية' => 'Sfax Sud', 'طينة' => 'Thyna',
        'عقارب' => 'Agareb', 'جبنيانة' => 'Jebeniana', 'العامرة' => 'El Amra',
        'الحنشة' => 'El Hancha', 'منزل شاكر' => 'Menzel Chaker', 'الغريبة' => 'Ghriba',
        'بئر علي بن خليفة' => 'Bir Ali Ben Khalifa', 'الصخيرة' => 'Skhira',
        'المحرس' => 'Mahres', 'قرقنة' => 'Kerkennah',
        // Kairouan
        'القيروان الشمالية' => 'Kairouan Nord', 'القيروان الجنوبية' => 'Kairouan Sud',
        'الشبيكة' => 'Chbika', 'السبيخة' => 'Sbikha', 'الوسلاتية' => 'Oueslatia',
        'حفوز' => 'Haffouz', 'العلا' => 'El Alâ', 'حاجب العيون' => 'Hajeb El Ayoun',
        'نصر الله' => 'Nasrallah', 'الشراردة' => 'Chrarda', 'بوحجلة' => 'Bouhajla',
        'عين جلولة' => 'Aïn Jeloula', 'منزل المهيري' => 'Menzel Mehiri',
        // Kasserine
        'القصرين الشمالية' => 'Kasserine Nord', 'القصرين الجنوبية' => 'Kasserine Sud',
        'الزهور' => 'Ezzouhour', 'حاسي الفريد' => 'Hassi El Frid', 'سبيطلة' => 'Sbeitla',
        'سبيبة' => 'Sbiba', 'جدليان' => 'Jedliane', 'العيون' => 'El Ayoun',
        'تالة' => 'Tala', 'حيدرة' => 'Haidra', 'فوسانة' => 'Foussana',
        'فريانة' => 'Feriana', 'ماجل بلعباس' => 'Majel Bel Abbes',
        // Sidi Bouzid
        'سيدي بوزيد الغربية' => 'Sidi Bouzid Ouest', 'سيدي بوزيد الشرقية' => 'Sidi Bouzid Est',
        'جلمة' => 'Jelma', 'سيدي علي بن عون' => 'Sidi Ali Ben Aoun', 'منزل بوزيان' => 'Menzel Bouziane',
        'المكناسي' => 'Meknassy', 'سوق الجديد' => 'Souk Jedid', 'المزونة' => 'Mezzouna',
        'الرقاب' => 'Regueb', 'أولاد حفوز' => 'Ouled Haffouz', 'بئر الحفي' => 'Bir Lahfid',
        'السبالة أولاد عسكر' => 'Sabala Ouled Askar',
        // Gabès
        'ڨابس المدينة' => 'Gabès Médina', 'ڨابس الغربية' => 'Gabès Ouest', 'ڨابس الجنوبية' => 'Gabès Sud',
        'غنوش' => 'Ghannouch', 'المطوية' => 'El Metouia', 'منزل الحبيب' => 'Menzel El Habib',
        'الحامة' => 'El Hamma', 'مطماطة' => 'Matmata', 'مطماطة الجديدة' => 'Nouvelle Matmata',
        'مارث' => 'Marth',
        // Médenine
        'مدنين الشمالية' => 'Médenine Nord', 'مدنين الجنوبية' => 'Médenine Sud',
        'بني خداش' => 'Beni Khedache', 'بن ڨردان' => 'Ben Gardane', 'جرجيس' => 'Zarzis',
        'جربة حومة السوق' => 'Djerba Houmt Souk', 'جربة ميدون' => 'Djerba Midoun',
        'جربة أجيم' => 'Djerba Ajim', 'سيدي مخلوف' => 'Sidi Makhlouf',
        // Tataouine
        'تطاوين الشمالية' => 'Tataouine Nord', 'تطاوين الجنوبية' => 'Tataouine Sud',
        'الصمار' => 'Smar', 'البئر الأحمر' => 'Bir Lahmar', 'غمراسن' => 'Ghomrassen',
        'ذهيبة' => 'Dehiba', 'رمادة' => 'Remada',
        // Gafsa
        'ڨفصة الشمالية' => 'Gafsa Nord', 'ڨفصة الجنوبية' => 'Gafsa Sud', 'سيدي عيش' => 'Sidi Aïch',
        'القصر' => 'El Ksar', 'أم العرائس' => 'Oum El Araïs', 'الرديف' => 'Redeyef',
        'المتلوي' => 'Metlaoui', 'المظيلة' => 'M\'dhilla', 'القطار' => 'El Guettar',
        'بلخير' => 'Belkhir', 'السند' => 'Es-Sened',
        // Tozeur
        'توزر' => 'Tozeur', 'دقاش' => 'Degache', 'تمغزة' => 'Tameghza',
        'نفطة' => 'Nefta', 'حزوة' => 'Hazoua',
        // Kébili
        'ڨبلي الشمالية' => 'Kébili Nord', 'ڨبلي الجنوبية' => 'Kébili Sud',
        'سوق الأحد' => 'Souk El Ahad', 'دوز الشمالية' => 'Douz Nord',
        'دوز الجنوبية' => 'Douz Sud', 'الفوار' => 'El Faouar',
    );
}

/**
 * Traduit une liste de délégations pour l'affichage courant.
 *
 * @param string[] $delegations Libellés (master arabe).
 * @return string[] Libellés traduits si le front est en français.
 */
function wparabic_translate_delegations($delegations) {
    if (!function_exists('wparabic_current_lang') || wparabic_current_lang() !== 'fr') {
        return $delegations;
    }

    $fr = wparabic_delegations_fr();
    if (empty($fr)) {
        return $delegations;
    }

    return array_map(
        static function ($label) use ($fr) {
            return isset($fr[$label]) ? $fr[$label] : $label;
        },
        $delegations
    );
}


/**
 * @return array<string, string[]> slug de gouvernorat => liste de delegations
 */
function wparabic_get_delegations() {
    static $cache = array();

    $lang = function_exists('wparabic_current_lang') ? wparabic_current_lang() : 'ar';
    if (isset($cache[$lang])) {
        return $cache[$lang];
    }

    $delegations = array(
        'tunis' => array(
            'قرطاج', 'المدينة', 'باب البحر', 'باب سويقة', 'العمران', 'العمران الأعلى',
            'التحرير', 'المنزه', 'حي الخضراء', 'باردو', 'السيجومي', 'الزهور',
            'الحرائرية', 'سيدي حسين', 'الوردية', 'الكبارية', 'سيدي البشير',
            'جبل الجلود', 'حلق الوادي', 'الكرم', 'المرسى',
        ),
        'ariana' => array(
            'أريانة المدينة', 'سكرة', 'رواد', 'قلعة الأندلس', 'سيدي ثابت',
            'التضامن', 'المنيهلة',
        ),
        'ben_arous' => array(
            'بن عروس', 'المدينة الجديدة', 'المروج', 'حمام الأنف', 'حمام الشط',
            'بومهل البساتين', 'الزهراء', 'رادس', 'مقرين', 'المحمدية', 'فوشانة', 'مرناق',
        ),
        'manouba' => array(
            'منوبة', 'دوار هيشر', 'وادي الليل', 'طبربة', 'البطان', 'الجديدة',
            'المرناقية', 'برج العامري',
        ),
        'nabeul' => array(
            'نابل', 'دار شعبان الفهري', 'بني خيار', 'قربة', 'منزل تميم', 'الميدة',
            'قليبية', 'حمام الأغزاز', 'الهوارية', 'تاكلسة', 'سليمان', 'منزل بوزلفة',
            'بني خلاد', 'قرمبالية', 'بوعرقوب', 'الحمامات',
        ),
        'zaghouan' => array(
            'زغوان', 'الزريبة', 'بئر مشارقة', 'الفحص', 'الناظور', 'صواف',
        ),
        'bizerte' => array(
            'بنزرت الشمالية', 'جرزونة', 'بنزرت الجنوبية', 'سجنان', 'جومين', 'ماطر',
            'غزالة', 'منزل بورقيبة', 'تينجة', 'أوتيك', 'غار الملح', 'منزل جميل',
            'العالية', 'رأس الجبل',
        ),
        'beja' => array(
            'باجة الشمالية', 'باجة الجنوبية', 'عمدون', 'نفزة', 'تبرسق', 'تيبار',
            'تستور', 'قبلاط', 'مجاز الباب',
        ),
        'jendouba' => array(
            'جندوبة', 'جندوبة الشمالية', 'بوسالم', 'طبرقة', 'عين دراهم', 'فرنانة',
            'غار الدماء', 'وادي مليز', 'بلطة بوعوان',
        ),
        'kef' => array(
            'الكاف الغربية', 'الكاف الشرقية', 'نبر', 'الطويرف', 'ساقية سيدي يوسف',
            'تاجروين', 'القلعة الخصبة', 'الجريصة', 'القصور', 'الدهماني', 'السرس',
        ),
        'siliana' => array(
            'سليانة الشمالية', 'سليانة الجنوبية', 'بوعرادة', 'ڨعفور', 'الكريب',
            'بورويس', 'مكثر', 'الروحية', 'كسرى', 'برڨو', 'العروسة',
        ),
        'sousse' => array(
            'سوسة المدينة', 'الزاوية القصيبة الثريات', 'سوسة الرياض', 'سوسة جوهرة',
            'سوسة سيدي عبد الحميد', 'حمام سوسة', 'أكودة', 'القلعة الكبرى',
            'القلعة الصغرى', 'سيدي بوعلي', 'هرقلة', 'النفيضة', 'بوفيشة', 'كندار',
            'سيدي الهاني', 'المسعدين',
        ),
        'monastir' => array(
            'المنستير', 'الوردانين', 'الساحلين', 'زرمدين', 'بني حسان', 'جمال',
            'بنبلة', 'المكنين', 'البقالطة', 'طبلبة', 'قصر هلال', 'قصيبة المديوني',
            'صيادة لمطة بوحجر',
        ),
        'mahdia' => array(
            'المهدية', 'بومرداس', 'أولاد الشامخ', 'شربان', 'هبيرة', 'السواسي',
            'الجم', 'الشابة', 'ملولش', 'سيدي علوان', 'قصور الساف',
        ),
        'sfax' => array(
            'صفاقس المدينة', 'صفاقس الغربية', 'ساقية الزيت', 'ساقية الداير',
            'صفاقس الجنوبية', 'طينة', 'عقارب', 'جبنيانة', 'العامرة', 'الحنشة',
            'منزل شاكر', 'الغريبة', 'بئر علي بن خليفة', 'الصخيرة', 'المحرس', 'قرقنة',
        ),
        'kairouan' => array(
            'القيروان الشمالية', 'القيروان الجنوبية', 'الشبيكة', 'السبيخة',
            'الوسلاتية', 'حفوز', 'العلا', 'حاجب العيون', 'نصر الله', 'الشراردة',
            'بوحجلة', 'عين جلولة', 'منزل المهيري',
        ),
        'kasserine' => array(
            'القصرين الشمالية', 'القصرين الجنوبية', 'الزهور', 'حاسي الفريد',
            'سبيطلة', 'سبيبة', 'جدليان', 'العيون', 'تالة', 'حيدرة', 'فوسانة',
            'فريانة', 'ماجل بلعباس',
        ),
        'sidi_bouzid' => array(
            'سيدي بوزيد الغربية', 'سيدي بوزيد الشرقية', 'جلمة', 'سيدي علي بن عون',
            'منزل بوزيان', 'المكناسي', 'سوق الجديد', 'المزونة', 'الرقاب',
            'أولاد حفوز', 'بئر الحفي', 'السبالة أولاد عسكر',
        ),
        'gabes' => array(
            'ڨابس المدينة', 'ڨابس الغربية', 'ڨابس الجنوبية', 'غنوش', 'المطوية',
            'منزل الحبيب', 'الحامة', 'مطماطة', 'مطماطة الجديدة', 'مارث',
        ),
        'medenine' => array(
            'مدنين الشمالية', 'مدنين الجنوبية', 'بني خداش', 'بن ڨردان', 'جرجيس',
            'جربة حومة السوق', 'جربة ميدون', 'جربة أجيم', 'سيدي مخلوف',
        ),
        'tataouine' => array(
            'تطاوين الشمالية', 'تطاوين الجنوبية', 'الصمار', 'البئر الأحمر',
            'غمراسن', 'ذهيبة', 'رمادة',
        ),
        'gafsa' => array(
            'ڨفصة الشمالية', 'ڨفصة الجنوبية', 'سيدي عيش', 'القصر', 'أم العرائس',
            'الرديف', 'المتلوي', 'المظيلة', 'القطار', 'بلخير', 'السند',
        ),
        'tozeur' => array(
            'توزر', 'دقاش', 'تمغزة', 'نفطة', 'حزوة',
        ),
        'kebili' => array(
            'ڨبلي الشمالية', 'ڨبلي الجنوبية', 'سوق الأحد', 'دوز الشمالية',
            'دوز الجنوبية', 'الفوار',
        ),
    );

    /**
     * Permet d'ajuster la liste des delegations sans modifier le theme.
     *
     * @param array<string, string[]> $delegations
     */
    $delegations = apply_filters('wparabic_delegations', $delegations);
    $delegations = array_map('wparabic_translate_delegations', $delegations);

    $cache[$lang] = $delegations;
    return $delegations;
}

/**
 * Verifie qu'une delegation appartient bien au gouvernorat annonce.
 *
 * @param string $governorate Slug du gouvernorat.
 * @param string $delegation  Nom de la delegation.
 * @return bool
 */
function wparabic_is_valid_delegation($governorate, $delegation) {
    $all = wparabic_get_delegations();

    return isset($all[$governorate]) && in_array($delegation, $all[$governorate], true);
}
