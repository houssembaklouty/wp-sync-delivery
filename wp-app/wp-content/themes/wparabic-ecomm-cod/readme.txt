=== WPArabicEcommCOD ===

Requires at least: 6.0
Tested up to: 6.9
WC requires at least: 9.0
WC tested up to: 10.7
Requires PHP: 7.4
License: GNU General Public License v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Theme WooCommerce arabe RTL avec paiement a la livraison (COD).

== Description ==

Theme mono-produit / petite boutique pour le marche tunisien :

* Formulaire de commande COD en une etape sur la fiche produit (nom, telephone,
  gouvernorat, delegation, adresse), sans passer par le panier ni le tunnel
  WooCommerce.
* Frais de livraison depuis les zones WooCommerce, gouvernorats enregistres en
  codes ISO 3166-2 (24 gouvernorats, 266 delegations).
* Selection des variations (couleur, pointure) par listes deroulantes natives.
* Commande alternative par WhatsApp.
* Interface entierement arabe, RTL.

== Attribution / Licences ==

Ce theme est DERIVE du theme WordPress « hanout » v4.6,
Copyright (c) ar-themes.com,
https://www.ar-themes.com/wordpress-themes/hanout
distribue sous licence GNU General Public License v2 or later.

« hanout » est lui-meme base sur le theme « Nozama » de CSSIgniter.

La feuille de style style.css et le fichier rtl.css reprennent, en grande partie
verbatim, le CSS de « hanout ». Modifications apportees en 2026 :

* En-tete de theme remplace.
* Import Google Fonts remplace par la police Cairo auto-hebergee (fonts/cairo/).
* Gabarits PHP et JavaScript reecrits (formulaire COD tunisien, delegations,
  variations par boutons radio, recherche AJAX).
* assets/css/overrides.css ajoute pour les surcharges propres a ce theme.

Ressources tierces embarquees :

* normalize.css v7.0.0 — Nicolas Gallagher — licence MIT
  (dans assets/css/vendor.css)
* Font Awesome Free 6.5.1 — Fonticons, Inc. (auto-heberge dans
  assets/vendor/fontawesome/, licence dans LICENSE.txt)
  Icones : CC BY 4.0 — Polices : SIL OFL 1.1 — Code : MIT
* Cairo — SIL Open Font License 1.1
  (fonts/cairo/)

== Changelog ==

= 3.12.0 =
* Defilement automatique du carrousel produits (option « تحريك تلقائي ») :
* case d'activation + duree entre deux pas en secondes (2 a 20, defaut 4),
  reglages visibles seulement quand le mode carrousel est coche (bascule
  JS deleguee, meme mecanique que les champs par modele d'affichage).
* En boucle : en fin de piste, retour en douceur au debut. Pause au
  survol et pendant un appui (souris/doigt), reprise deux secondes apres.
  Respect de prefers-reduced-motion : pas d'animation imposee.

= 3.11.2 =
* Fleches du carrousel produits visibles a TOUTES les largeurs : le
  carrousel categories les masquait sous 768 px, les cartes produits les
  affichent desormais aussi en version compacte (34 px, rentrees de 4 px
  des le bord) — le glissement au doigt reste possible en parallele.

= 3.11.1 =
* Le mode « une seule rangee » devient un vrai carrousel : fleches
  precedent/suivant (celles du carrousel categories, reutilisees avec son
  JS data-wparabic-carousel), defilement de 85% de la largeur, arret
  magnétique, fleches desactivees aux extremites, masque'es sur mobile ou
  le glissement au doigt reste la gestuelle naturelle. Libelle de
  l'option : « عرض ككاروسيل (صف واحد بأسهم للتنقل) ».

= 3.11.0 =
* Option « تمرير أفقي » (defilement horizontal) sur les widgets
  « أقسام حسب التصنيف » et « قسم المنتجات » : une seule rangee qui
  glisse au lieu de la grille empilee. Les cartes gardent la largeur
  qu'elles auraient a N colonnes (N = reglage عدد الأعمدة = nombre de
  cartes visibles d'un coup), les suivantes se revelent au glissement,
  avec arrêt magnétique (scroll-snap). Compatible pleine largeur et
  ratio. Sur mobile, 1,8 carte visible pour signaler le glissement.

= 3.10.0 =
* Option « عرض كامل » (pleine largeur) par section : la grille sort du
  conteneur et s'étend d'un bord à l'autre de l'écran, les blocs se
  répartissant selon le nombre de colonnes choisi. Disponible sur les
  widgets « أقسام حسب التصنيف », « قسم المنتجات » et « قسم التصنيفات »
  (maquettes blocs égaux, grille, carrousel). Le titre de section reste
  centré au-dessus.
* Correction incluse : la grille 6 colonnes n'existait pas dans
  style.css (règles 2 à 5 seulement) — le choix 6 colonnes du widget
  « أقسام حسب التصنيف » est maintenant réellement appliqué, avec le
  centrage des rangées incomplètes comme pour 3/4/5.
* body overflow-x:clip absorbe les pixels de barre de défilement du
  plein écran (clip, et non hidden : sticky continue de fonctionner).

= 3.9.1 =
* Nouveau ratio « 7:10 » (portrait, format affiche) dans l'option
  « نسبة الصور » — la liste devient 1:1, 4:3, 9:16, 7:10, 16:6.

= 3.9.0 =
* Option « نسبة الصور » (ratio des images) sur les sections d'accueil :
  1:1 (défaut), 4:3, 9:16 ou 16:6, au choix de l'admin, indépendamment
  pour chaque section.
* Concerne : le widget « أقسام حسب التصنيف », le widget « قسم المنتجات »
  (cartes produits, via la variable --wparabic-thumb-ratio déjà lue par
  .item-thumb img) et les modèles بلاطات متساوية / شبكة / شريط متحرك du
  widget catégories (variable --wparabic-ratio).
* Non concerné par construction : mosaïque (composition fixe), cercles
  (rondes), bannière (réglage hauteur dédié).
* Conséquence du défaut 1:1 : les blocs égaux, avant en 4:3 figé,
  deviennent carrés par défaut (l'admin peut revenir à 4:3).

= 3.8.0 =
* Nouveau widget d'accueil « أقسام حسب التصنيف » : pour chaque categorie
  choisie par l'admin, une section avec en-tete (nom de la categorie ou
  titre personnalise) et une grille de cartes produits complets (photo,
  badge promo, prix) — les produits affiches dans chaque categorie sont
  choisis par l'admin par cases a cocher.
* Jusqu'a 6 groupes categorie/produits, colonnes 2 a 6 partagees, en-tete
  global facultatif. Changer le nombre de groupes puis enregistrer
  regenere le formulaire (mecanisme natif, aucun JS ajoute).
* Rendu identique au widget « قسم المنتجات » (content-product.php du
  theme). Groupe incomplet ecarte ; section non affichee si aucun groupe
  n'est complet.
* Retrait du modele « كتل مختارة » du widget categories (remplace par ce
  widget) : le selecteur revient aux 5 modeles (mosaique, blocs egaux,
  grille, cercles, carrousel).

= 3.7.0 =
* Section categories, nouveau modele « كتل مختارة (تصنيف + منتج) » : des
  blocs composes par l'admin — chaque bloc montre la photo du produit choisi,
  porte le nom de la categorie choisie en calque et mene a la fiche du produit.
* Repartition des blocs par motif de rangees choisi par l'admin (ex. 3,3,4,
  4,4, 2,2,2,2) : 1 a 6 blocs par rangee, 24 blocs au maximum. Le formulaire
  genere un couple de selecteurs (categorie + produit) par emplacement ;
  changer le motif puis enregistrer regenere la liste.
* Les selecteurs produits sont groupes par categorie ; en dessous de 992 px
  le motif laisse place au mobile-first (2 colonnes, puis 3 sur tablette).
* Un bloc incomplet (categorie ou produit manquant) n'est pas affiche ; la
  section ne s'affiche pas si aucun bloc n'est complet.

= 3.6.0 =
* Section categories, nouveau modele « بلاطات متساوية » : tuiles plein cadre
  de meme taille, nom du categorie (et nombre de produits au choix) en calque
  sur l'image, degrade vers le bas comme les tuiles de la mosaique.
* Nombre de colonnes par ligne choisi par l'admin (2 a 6) pour les modeles
  « بلاطات متساوية » et « شبكة بطاقات » — la grille passait de 3/4/6 a 2-6.
* Mobile-first conserve : 2 colonnes sur telephone, 3 sur tablette, le reglage
  admin s'applique au-dela de 992 px.

= 3.5.0 =
* Langue de la boutique independante de WordPress : arabe, francais ou
  anglais. Personnaliser > (panneau du theme) > اللغة : langue par defaut,
  langues proposees, et choix d'afficher ou non le selecteur aux visiteurs.
  La langue de l'administration n'est jamais modifiee.
* Selecteur de langue en liste deroulante a drapeaux (Tunisie, France,
  Royaume-Uni ; SVG livres dans assets/img/flags/), dans l'en-tete et le menu
  mobile. Choix du visiteur memorise un an (cookie wparabic_lang).
* Le sens RTL/LTR suit la langue, meme si la traduction arabe de WordPress
  n'est pas installee. Les traductions WordPress/WooCommerce manquantes sont
  installees automatiquement quand c'est possible ; sinon un avis l'indique.
* Traductions du theme livrees dans languages/ (fr_FR, en_US, ar) : tous les
  textes du theme, y compris formulaire de commande, messages AJAX et
  gouvernorats, passent par les fonctions de traduction.
* Une version par langue des textes saisis : messages du bandeau, texte et
  ligne de credit du pied de page (Personnaliser), titres et textes des
  sections de l'accueil (bloc « الترجمات » de chaque widget), et un menu
  principal par langue (Apparence > Menus). Vide = texte de base.
* E-mails : le client recoit les siens dans sa langue, le marchand dans la
  langue par defaut de la boutique. La langue du client est enregistree sur
  la commande (meta _wparabic_lang).
* Mode de paiement « a la livraison » affiche dans la langue du client.
* Bandeau superieur en defilement continu (marquee) sur tout le site : boucle
  sans saut, sens selon la langue (droite en arabe, gauche en francais et
  anglais), bords en fondu de la couleur du bandeau, pause au survol, texte
  fixe si le visiteur a demande moins d'animations. Vitesse reglable :
  Personnaliser > الشريط العلوي.
* Nom du theme dans l'administration generique et traduisible : le panneau
  du personnalisateur et les quatre widgets de l'accueil s'appellent
  « خيارات القالب » (Options du theme / Theme options) au lieu du titre du
  site. Le nom de la boutique reste affiche aux visiteurs (logo texte,
  copyright) et se regle dans Reglages > General > Titre du site.
* Section categories de l'accueil : quatre modeles au choix (champ
  « نموذج العرض » du widget) — mosaique (modele d'origine), grille de cartes
  (3, 4 ou 6 colonnes), cercles et carrousel. Categories cochees ou, a
  defaut, toutes celles qui ont des produits ; nombre maximum ; nombre de
  produits affiche ou non. Le formulaire n'affiche que les reglages du
  modele choisi. Responsive : grille 2 colonnes, cercles et carrousel
  defilables au doigt sur mobile. Une categorie sans image prend la photo
  de son dernier produit.
* Pret pour la production :
  - telephone : un numero Ooredoo qui commence par 216 (21 6xx xxx) n'est
    plus pris pour un prefixe et rejete ; coller « +216 xx xxx xxx » marche ;
  - protection du formulaire de commande : piege a robots, refus d'une meme
    commande repetee en moins de 3 minutes, limite par adresse IP
    (10 commandes / 10 min, filtre wparabic_cod_rate_limit) ;
  - page mise en cache : un jeton de securite perime est renouvele
    automatiquement au lieu de faire echouer la commande ;
  - erreur serveur (rupture, champ invalide...) : le formulaire reste
    affiche avec le message, le client corrige et renvoie ;
  - quantite plafonnee selon WooCommerce (vendu individuellement, quantite
    maximale) ; plus de commande orpheline si la creation echoue ;
  - origine des commandes (WooCommerce > Commandes > Origine) renseignee ;
  - champs du formulaire avec etiquettes accessibles et autocompletion ;
  - frais de livraison mis en cache (invalides a chaque modification de
    zone, de methode ou de produit) ;
  - description longue plus affichee en double sur la fiche produit ;
  - delegation de Tunis corrigee : « جبل الجلود » (Djebel Jelloud) ;
  - Font Awesome auto-heberge : plus aucune ressource externe.
* Vignettes produit : le recadrage choisi dans Personnaliser > WooCommerce >
  Images du produit (1:1, personnalise, non recadre) est enfin respecte a
  l'affichage ; le CSS imposait toujours un carre. Taille d'image hanout_item
  (630x630, inutilisee) retiree.
* Fiche produit : afficher ou masquer les onglets « معلومات إضافية » et
  « المراجعات », et le bloc « ملخص الطلب » du formulaire
  (Personnaliser > صفحة المنتج).
* Couleurs : couleur principale et couleur de survol reglables (الألوان), avec
  apercu en direct ; la couleur de survol s'applique a tous les boutons.
* Bouton WhatsApp : interrupteur d'affichage, et numero WhatsApp facultatif
  (par defaut le numero de telephone).
* Fichiers CSS/JS versionnes par date de modification : plus d'ancienne
  feuille gardee en cache par les navigateurs.
* Limites : le contenu WordPress (produits, categories, attributs, pages) et
  les 264 delegations ne sont pas traduits par le theme ; il faut pour cela
  une extension multilingue. Une seule URL par page pour toutes les langues.

= 3.4.0 =
* Le theme ne porte plus aucune marque de commerçant : il est reutilisable tel
  quel sur une autre boutique.
* Le nom affiche dans l'admin (panneau du personnalisateur, noms des quatre
  widgets) vient desormais du titre du site — Reglages > General. Rien a
  reconfigurer : c'est le meme nom qu'avant sur une installation existante.
* Les coordonnees du marchand ne sont plus des valeurs par defaut du code :
  telephone, WhatsApp, messages du bandeau et ligne de copyright partent vides
  ou neutres, a renseigner depuis le personnalisateur.
* Un numero non renseigne masque desormais ce qui en depend, au lieu de
  produire un lien mort : bouton d'appel flottant, ligne « هاتف » du pied et
  bouton de commande par WhatsApp.
* En-tete du theme : Theme URI, Author et Author URI retires — ils pointaient
  vers le site d'un commerçant particulier.
* assets/css/mba.css renomme en assets/css/overrides.css.
* Une seule source pour les valeurs par defaut (wparabic_default), au lieu de
  deux copies qui pouvaient diverger.

= 3.3.0 =
* Choix des champs affiches dans le formulaire de commande :
  Personnaliser > (panneau du theme) > حقول نموذج الطلب. Quatre cases a cocher — nom,
  delegation, adresse, e-mail — toutes activees par defaut.
* Le telephone et la wilaya restent toujours affiches : le telephone est le seul
  moyen de confirmer une commande COD, et la wilaya determine le prix de la
  livraison, la cascade des delegations et la ligne « سعر التوصيل ».
* Un champ masque n'est PAS rendu du tout. Le cacher en CSS aurait bloque la
  soumission sans message (un champ « required » invisible n'est pas focusable).
* Un champ masque est aussi ignore cote serveur : un POST forge ne peut plus
  reinjecter, par exemple, un e-mail que le marchand a coupe.
* Si le nom est masque, le numero de telephone prend sa place dans la liste des
  commandes (filtre « wparabic_cod_billing_name »).
* La note client ne liste plus que les champs affichés — fini le
  « معتمدية: — » pour un champ desactive.
* La delegation devient reellement obligatoire cote serveur quand elle est
  affichee : elle l'etait deja pour le navigateur, mais un POST forge passait.
* Les 13 Ko de delegations ne sont plus envoyes au navigateur quand le champ
  correspondant est masque.

= 3.2.0 =
* Largeur du contenu reglable par emplacement : Personnaliser > (panneau du theme) >
  التخطيط. Quatre listes deroulantes — page d'accueil, boutique, fiche produit,
  reste des pages — chacune au choix « بعرض الشاشة » (pleine largeur) ou
  « محتوى محدود العرض » (limite a 1130px, centre).
* Toutes par defaut sur la pleine largeur : l'aspect du site est inchange tant
  qu'on n'y touche pas.
* Le bandeau superieur et le pied de page restent en pleine largeur quel que
  soit le reglage.
* Les archives de categorie suivent le reglage « reste des pages », pas celui de
  la boutique.
* Techniquement : la surcharge globale de .container posee en 2.2.0 est retiree ;
  les gabarits emettent container ou container-fluid via
  wparabic_container_class(). C'est ce qui rend le mode limite possible — il
  etait auparavant impossible de revenir a une largeur bornee.

= 3.1.0 =
* Le bouton flottant « اشتري الآن » de la fiche produit fonctionne enfin. Il
  etait rendu et le JS basculait une classe .visible, mais aucune regle CSS ne
  definissait cette classe : il restait en display:none en permanence.
* Il apparait desormais selon la regle attendue : des que le bouton de
  confirmation « انقر هنا لتأكيد الطلب » sort de l'ecran, et disparait des qu'il
  y revient (IntersectionObserver, insensible aux changements de hauteur du
  formulaire).
* Limite au mobile et a la tablette (moins de 992px) : au-dela, le formulaire
  COD occupe la colonne de droite et reste a l'ecran.
* Nouvelle option Personnaliser > (panneau du theme) > صفحة المنتج pour l'activer ou le
  desactiver. Activee par defaut.
* Le bouton d'appel flottant remonte au-dessus de la barre quand elle apparait,
  au lieu de la chevaucher sur mobile.

= 3.0.1 =
* Correction : ajout de sidebar.php. Depuis la 3.0.0, le hook
  woocommerce_sidebar appelait get_sidebar() sans gabarit correspondant :
  WordPress chargeait son gabarit de compatibilite herite
  (wp-includes/theme-compat/sidebar.php), ce qui affichait un bloc
  « Search / Pages / Archives / Categories » etranger au theme, precede d'une
  notice de depreciation, sur la fiche produit et les archives.
  La zone « Colonne laterale » est vide par defaut : rien ne s'affiche. Pour
  activer des sections, deposer des widgets dans
  Apparence > Widgets > Colonne laterale.

= 3.0.0 =
Compatibilite WooCommerce. Le formulaire COD en une etape est conserve.

* Les points d'extension standards sont desormais declenches dans les gabarits
  (single-product, archive-product, content-product). Ce que le theme rend
  lui-meme est retire des hooks : aucun doublon. Consequences immediates :
  fil d'Ariane, donnees structurees JSON-LD du produit, SKU et categories,
  onglets natifs, ventes incitatives, produits lies, notices — et les
  extensions WooCommerce fonctionnent enfin sur la grille et la fiche.
* Les 24 gouvernorats sont enregistres aupres de WooCommerce en codes ISO
  3166-2 (TN-11...TN-83) : la Tunisie n'en avait aucun. Les adresses de
  commande portent maintenant un code normalise au lieu d'un nom libre.
* Les frais de livraison viennent des zones WooCommerce (Reglages > Livraison),
  avec repli sur la table du theme si aucune zone ne correspond.
* La livraison est une vraie ligne WC_Order_Item_Shipping et non plus un frais :
  shipping_total est reel et les extensions de transporteur trouvent une
  methode. Auparavant shipping_total valait 0 sur toutes les commandes.
* Champ email facultatif : renseigne, le client recoit sa confirmation
  WooCommerce. Aucune n'etait envoyee jusqu'ici.
* La commande porte created_via = cod-form et declenche
  woocommerce_checkout_order_processed.
* Apres validation, redirection vers la vraie page « commande recue » : c'est
  ce qui rend le suivi de conversion possible (woocommerce_thankyou).
* Passerelle « paiement a la livraison » activee — elle etait desactivee alors
  que les commandes s'en reclamaient.
* Corrections : titre affiche deux fois et formulaires imbriques sur la
  boutique ; en-tetes @version ajoutes aux gabarits surcharges.

= 2.4.0 =
* Nouveau champ « العنوان » obligatoire dans le formulaire de commande, sur
  toute la largeur sous la delegation. L'adresse est enregistree dans
  address_1 (facturation et livraison) et reprise dans la note de commande.
* Le champ telephone n'accepte plus que des chiffres : filtrage a la saisie et
  au collage, limite a 8, retrait automatique d'un prefixe +216 / 00216.
  Validation native (pattern) et revalidation cote serveur : une commande avec
  un numero incomplet est refusee.

= 2.3.0 =
* Chaque section de la page d'accueil porte un interrupteur « إظهار هذا القسم » :
  on masque une section sans perdre ses reglages, et on la retrouve intacte en
  recochant la case.
* Nouveau raccourci Apparence > الصفحة الرئيسية : ouvre le personnalisateur
  directement sur les sections de l'accueil, avec apercu en direct. Le gerant y
  reordonne, edite et active/desactive au meme endroit.
* Le contenu de la page choisie comme accueil (Pages > الرئيسية) s'affiche
  desormais en haut de la page, dans la meme grille que les sections. Page vide
  = aucune section, aucun trou. Auparavant l'editer ne produisait rien.

= 2.2.0 =
* Toutes les pages passent en pleine largeur (conteneur fluide), avec des marges
  laterales progressives : 15px mobile, 20/30/40px aux paliers intermediaires,
  50px au-dela de 1350px.
* Les deux echelles de largeur du theme sont traitees ensemble : .container
  (vendor.css) et .head-mast-container / .head-search-container (style.css).
  L'en-tete reste donc aligne au pixel avec le contenu.
* Mobile inchange : sous 576px le conteneur etait deja a 100%.
* La grille produits passe a 6 colonnes au-dela de 1600px et 7 au-dela de
  2000px, plutot que d'elargir les cartes (les images sont generees en 630px).
* Hauteur des blocs a ratio plafonnee sur grand ecran : banniere 520/560px,
  tuiles de categories 520px (grande) et 245px (moyennes).
* Correction : .container imbrique dans l'onglet avis de la fiche produit
  (double padding).
* Correction : « </main></div> » orphelins emis par wrapper-end.php de
  WooCommerce sur les pages boutique, le hook d'ouverture n'etant jamais
  declenche par le theme.

= 2.1.0 =
* La page d'accueil est composee de widgets : zone « أقسام الصفحة الرئيسية »
  (Apparence > Widgets). Glisser-deposer pour reordonner, ajouter, dupliquer
  ou supprimer une section.
* Quatre widgets : grille de produits, mise en avant (4 arguments), tuiles de
  categories, et une nouvelle banniere d'accroche (image, titre, texte, bouton).
* Ecran des widgets classique retabli (use_widgets_block_editor a false).
* Repli automatique : si la zone est videe, les trois sections d'origine sont
  affichees, jamais une page blanche.
* Les six reglages « الصفحة الرئيسية » du personnalisateur sont retires : ils
  vivent desormais dans les widgets. Les anciennes valeurs restent lues comme
  valeurs par defaut du repli.

= 2.0.0 =
* Refonte complete alignee sur le rendu de reference.
* Feuille de style « hanout » vendoree, grille et normalize retablis.
* Ajout de page.php, single.php, 404.php (le panier, la commande et le compte
  client n'etaient pas stylees faute de gabarit de page).
* Formulaire COD : cascade gouvernorat -> delegation, variations par radio,
  ecran de remerciement.
* Correctifs : classe .open du menu mobile, etat .is-stuck de l'en-tete,
  portee du defilement ancre, garde sur WC()->cart, SVG de recherche.

= 1.0.0 =
* Version initiale.
