# Changelog

## 2.10.0

- Correctif (revue de code transporteurs, P0) : **redaction PII réparée** — `PII_KEYS` étendu à tous les namings des 11 providers (`tel` = téléphone principal Colissimo/Jetpack/Fiabilo/Navex, **`token` Fiabilo en clair**, `apiKey`/`ClientName`/`ClientPhone` INTIGO, `name`/`phone1`/`address`/`price` Shipper/Ciblex, `PersonName`/`EmailAddress`/`Line1` Aramex, `msg`/`commentaire`, `cp`…) + les 12 logs « response body » passent par `redact_body()` (JSON redacté, non-JSON **jamais** loggé — code HTTP + taille) + les `'raw'` de création non protégés (Fiabilo/FirstDelivery/Jetpack/Shipper) redactés avant stockage meta/DB.
- Correctif (revue de code, P0) : **famine du poll horaire réparée** — les transporteurs sans suivi (Navex) sont exclus DÈS LA REQUÊTE candidates (ils consumaient le quota) et un **curseur de rotation** (`wcmcs_poll_cursor`) fait traiter le lot SUIVANT à chaque passage au lieu des 30 plus anciennes commandes interrogées à vie.
- Correctif (revue de code, P0) : **étiquettes** — Aramex : l'URL stockée meurt à ~24 h (nonce périssable dans une URL admin-ajax) → URL locale pattern Colissimo (nonce frais régénéré à l'affichage) · Colissimo : chaque **réimpression** échouait (branchement SOAP legacy au lieu de servir le PDF local existant) → le fichier local est servi directement · AFEX : le lien `print_labels` stocké était un **401 permanent** (auth par header) → `get_label_pdf()` (fetch serveur + sauvegarde locale) · Adex : le **token (1 an) en query string** n'est plus stocké dans `_wcmcs_label_url` ni exposé dans les liens admin → idem `get_label_pdf()` proxifié.
- Correctif (revue de code, P0) : **Ciblex `extract_tracking`** — une enveloppe `{"status":"success","code":200,"data":{…}}` faisait capturer `"200"` comme tracking (poll en échec permanent) → clés fortes d'abord (barcode/code_barre/reference…), récursion ensuite, clés faibles (`code`/`id`/`order_id`) en dernier avec garde anti-enveloppe (symétrie avec `extract_state`).
- Correctif (revue de code, P1) : **annulation & verrou** — `cancel_single` prend le **verrou atomique** (une annulation pendant une re-sync en vol pouvait écraser le tracking d'un nouveau colis = double expédition) et **désplanifie le retry Action Scheduler** en attente (sinon il re-créait un colis pour une commande ANNULÉE) + purge complète de `mark_cancelled` (date de sync, mémo d'état, drapeau pre-état) · le verrou de sync devient **atomique** (`add_option` + jeton propriétaire — l'ancien check-then-set en meta était TOCTOU : deux runners pouvaient tous deux créer une expédition ; un runner ne peut plus supprimer le verrou d'un autre ; TTL 180 s recyclé).
- Correctif (revue de code, P1) : **catégorisation du suivi ancrée** — Ciblex `livr|deliver` non ancré matchait « En livraison »/« Livraison retour » → livré ; défaut `^livr` matchait « Livraison en cours » ; Shipper `^(deliver|livr)` matchait « Delivering » (colis en transit marqués livrés + mail client) → ancres resserrées (`^livre`, `^(delivered|livre|livrer)`, Aramex ancré) + `classify_state` aligné.
- Correctif (revue de code, P1) : **pre-état Jetpack fiabilisé** — ① utilisé aussi quand l'état officiel est VIDE (l'API peut omettre `etat` pendant la tournée) ② drapeau `_wcmcs_awaiting_official_state` : une commande passée « Livrée » via pre-état **reste dans le poll** jusqu'à confirmation par l'état officiel — un retour officiel tardif (client refusé après le scan terrain) est désormais vu au lieu d'être figé « Livrée » à vie.
- Correctif (revue de code, P1) : **divers** — `detect_by_state` passe la ville au résolveur (règle `TN:X` ne matchait jamais si l'État = code + ville correcte → partait chez le transporteur par défaut) · Adex `normalize_phone` gère `00216` (13 chiffres — numéros valides bloqués à tort) · INTIGO `get_regions` + FD `get_localities` : **cache négatif 5 min** en cas de panne (fini les 10-20 s × N commandes en cascade) + timeout INTIGO filtrable · `VILLES_TO_GOUVERNORAT` : clés CamelCase/espaces mortes normalisées (`El Fahs`, `Kalaa Senan`, `Den Den`, `Sidi El Heni`, `Bab Souika`… enfin résolues), doublon faux `elhamma → Ben Arous` supprimé (El Hamma = **Gabès**), clés de dédoublonnage mortes retirées, ambiguïté `chebika` documentée (Kairouan/Tozeur — Tozeur retenue) · Aramex : warning ProductGroup déplacé du constructeur (spam à chaque requête HTTP) vers le payload (1×/sync) + poids converti via `wc_get_weight(..., 'kg')` (unité boutique) · `register_provider` : **premier enregistrement gagne** — un provider tiers ne peut plus remplacer silencieusement un built-in et hériter de ses credentials (collision = warning + refus).
- Rapport de revue complet archivé : `.opencode/plans/wcmcs-revue-code-transporteurs-2.10.0.md` (constats élevés/moyens/mineurs, non-corrigés assumés — duplication ×11 `resolve_gouvernorat`, rétention PII meta/log, forçage slug désactivé, etc.).
- Correctif (audit API, Aramex — écarts **vérifiés en direct sur l'API de production**) : **7 correctifs Aramex** — ① `Transaction` complet (les 5 références — Reference1 seul provoquait un HTTP 400 « required data members Reference2-5 » sur la création ET le suivi) ② `Shipments` de suivi en tableau de chaînes AWB (+ `GetLastTrackingUpdateOnly`) ③ parsing `TrackingResults` corrigé (la réponse réelle est une **liste de `{Key, Value:[…]}`** — l'ancien indexage par AWB renvoyait « colis introuvable » en permanence) ④ **COD réparé** : `PaymentType='C'`, `Services='CODS'`, `CashOnDeliveryAmount` en objet Money `{Value, CurrencyCode:'TND'}` (un float brut casse la désérialisation) ⑤ `LabelInfo` (ReportID 9201, type URL) enfin envoyé — l'étiquette n'était **jamais** retournée ⑥ combo `ProductGroup/ProductType` alignée automatiquement sur l'Annexe A (PDX/PPX = EXP, seul OND = DOM — le défaut historique DOM+PDX était invalide) ⑦ champs Contact obligatoires remplis (CompanyName + EmailAddress consignee/expéditeur) + `DescriptionOfGoods` tronqué à 100 caractères. ⚠️ À confirmer avec l'account manager Aramex Tunisie : devise COD (manuel « USD »), codes SH du suivi, combos exacts.
- Correctif (audit API, AFEX — OpenAPI officielle) : ① **annulation fantôme réparée** — `/v1/tickets` répond TOUJOURS HTTP 200 avec l'issue réelle dans l'enveloppe jsonrpc `result.code`, jamais vérifiée : une annulation REJETÉE était journalisée « succès » puis la commande purgée → re-sync → **doublon d'expédition** ; désormais `result.code` ≠ 200 = erreur et la commande reste synchronisée ② téléphones normalisés 8 chiffres (indicatif 216/00216 strippé, malformé bloqué localement — l'API rejette en 422 sinon) ③ états terminaux `recovered`, `exception_refunded`, `exception_not_refunded` → « Retournée » (les commandes d'échange/exception restaient « En cours » à vie).
- Correctif (audit API, Shipper/Navex/Ciblex — sans doc officielle, cohérence interne) : Shipper — catégorisation du suivi en **anglais** ajoutée (`delivered`/`returned|refunded|canceled` — le cas générique ne matchait que le français alors que la matrice annonce `suivi=true`) · Navex — le poll horaire **ignore désormais les transporteurs sans suivi** (`CARRIER_FEATURES['suivi']` — arrêt des erreurs horaires parasites) + suppression du fallback `status_message` en tracking (« Product Added. » polluait `_wcmcs_tracking_id`) → erreur claire `navex_no_tracking` · Ciblex — warning log gouvernorat non reconnu (aligné sur les 10 autres providers).
- Correctif (audit API, FirstDelivery) : **`locality_id` résolu PAR COMMANDE** (`GET /localities`, 1756 localités, cache 24 h — « strongly recommended » doc v2) : localité exacte dans le gouvernorat → délégation/homonyme → délégation contenant (ex. « Ettadhamen » → « Cité Ettadhamen ») → localité unique hors gouvernorat → réglage global « secours » → omis + warning. Avant : **une seule localité fixe pour TOUTES les commandes** (ou aucune) = routage vers la mauvaise agence. + **HTTP 429 : pause 3 s + retry** (doc : 1 req/s) + `estFragile`/`ouvrirColis` envoyés explicitement `"oui"`/`"non"` (standardisation doc §5).
- Correctif (audit API, Colissimo TN) : **validation téléphone réparée** — l'ancienne normalisation tronquait silencieusement à 8 chiffres (numéro erroné passé) et un tel sans aucun chiffre partait **vide** ; désormais seul l'indicatif 216/00216 est strippé et tout numéro ≠ 8 chiffres est **bloqué localement** avec un message clair. + coquilles UI `colissimodelivery.tn`, migration type_colis `VM → 2` (moyen, pas petit), alias mort supprimé.
- Correctif (audit API, suivi statuts) : **Jetpack `pre-etat`/`pre-motif` (temps réel livreur, doc §4.4) enfin exploités** — une commande livrée/retournée **sur le terrain** passait « En cours » jusqu'à la clôture de la tournée au dépôt (potentiellement le lendemain) ; désormais le pré-état déclenche la transition dès le scan (mention « pré-état livreur » dans la note) + `A verifier` → retour + erreur locale claire si token de suivi manquant (2 tokens distincts doc). **Fiabilo** : `Non reçu`, `Supprimé`, `Au magasin`, `À vérifier` → retour (10/18 états étaient ignorés). **INTIGO** : matching des statuts par **valeur numérique + libellé FR + anglais** (l'exemple de la doc `status: "Delivered"` ne matchait jamais) + états finaux « Colis perdu » (21), « declined by admin » (8), « Adresse non exacte » (12) → retour ; **`subDivision` résolue via `GET /api/deliveryrequest/regions`** (orthographe officielle dans la division, cache 24 h) et **fallback gouvernorat→subDivision supprimé** (valeur structurellement invalide). Adex : conforme 8/8 (aucun correctif).
- Correctif (critique) : **le transporteur forcé via la metabox est désormais honoré au premier clic** — les boutons « ⇄ Synchroniser » et « ↺ Retry » (page commande) lisent le dropdown « Forcer le transporteur » au moment du clic et le transmettent à la sync (paramètre AJAX `force_carrier`, persisté en meta AVANT la détection). Avant : le dropdown n'était sauvé qu'au « Mettre à jour » de la commande — un clic direct sur « Synchroniser » partait en détection automatique (→ transporteur par défaut), pouvant créer un colis chez le MAUVAIS transporteur (ex : FirstDelivery voulu, colis envoyé chez Colissimo).
- Correctif : **+27 délégations arabes dans la table des villes** (`سجنان`/Sejnane, `منزل بورقيبة`/Menzel Bourguiba, `نفزة`/Nefza, `فرنانة`/Fernana, `غار الدماء`/Ghardimaou, `صبيطلة`/Sbeitla, `كوربة`/Korba, `رقاب`/Regueb…) — quand le champ État contient un code de thème, le fallback ville résout maintenant le gouvernorat depuis une ville/délégation arabe bien plus souvent (chaque ajout correspond à une clé latine existante de `VILLES_TO_GOUVERNORAT`).
- Nouveau : **bouton « ✕ Annuler le colis »** (metabox commande + action ligne de la liste des commandes) — annule le colis chez le transporteur pour les 5 transporteurs supportant l'annulation via API (FirstDelivery `/cancel-orders`, Adex `supprimerColis`, AFEX ticket, Shipper, Colissimo SOAP `SupprimerColis`), invisible sinon (matrice `CARRIER_FEATURES['annulation']`). Confirmation obligatoire avant envoi ; l'annulation part telle quelle — si le colis n'est plus « en attente » chez le transporteur, son API rejette et le message s'affiche (ex. Adex « le colis ne peut plus être annulé »). En cas de succès : badge Sync → « En attente » (commande **re-synchronisable immédiatement**), meta purgées (tracking/étiquette/réponse API), statut **WooCommerce inchangé**, note commande + ligne historique « Annulé », hook `wcmcs_order_cancelled`. Chaînes traduites (EN/ar).
- Correctif : **suppression totale de l'interprétation des codes gouvernorats (TN-XX, TN61…)** pour tous les transporteurs (11) — les thèmes/plugins de checkout enregistrent leur PROPRE numérotation dans le champ État, sans rapport avec les codes ISO officiels : « TN-23 » (Bizerte chez le thème) était interprété ISO → **Jendouba** et envoyé tel quel à FirstDelivery. Toute la chaîne de résolution par codes est retirée de `WCMCS_Localization::translate_gouvernorat()` : codes ISO officiels · codes personnalisés (réglage « TN-61 = Sfax ») · auto-apprentissage (`wcmcs_learned_governing_codes`, qui figeait les faux positifs en base) · lookup WC States. Le gouvernorat est désormais TOUJOURS résolu depuis un nom réel : arabe → français, nom latin insensible casse/accents, puis fallback ville/délégation (exacte, préfixe, contenu) — quel que soit le langage du navigateur/checkout.
- Changement : **gouvernorat non reconnu = envoi de la valeur brute** (warning en log, plus de blocage `invalid_gouvernorat`) — un colis dont l'État contient un code est soit corrigé via la ville, soit envoyé tel quel à l'API transporteur qui tranche ; plus jamais de substitution silencieuse par un gouvernorat ISO erroné (Ciblex fonctionnait déjà ainsi, inchangé). Le blocage « Gouvernorat manquant » (champ vide) est conservé.
- Suppression : réglage « Codes gouvernorats personnalisés » (onglet Général) — devenu inutile ; l'option `wcmcs_custom_gouvernorat_codes` et les mappings appris `wcmcs_learned_governing_codes` sont purgés de la base au premier chargement en 2.10.0.

## 2.9.3

- Nouveau : **Colissimo — migration complète de l'API SOAP (wsColissimoGo) vers l'API v1** `colissimodelelivery.tn/api/v1/post.php` (token du compte expéditeur, POST form, réponse JSON `{status, lien, lien_zebra, status_message}` = code à barres ; le lien BL imprimable est stocké dans `_wcmcs_label_url`). Gouvernorats orthographe stricte imposée par le transporteur (Beja, Gabes, Kebili, Mannouba, Medenine, LE Kef…) avec alias automatiques (Béja, La Manouba…, l'arabe traduit en amont), téléphone normalisé à 8 chiffres exacts (+216 retiré), type de colis 1/2/3 (migration automatique des anciens VO/VM/GV/EXP), options ouvrir/fragile/échange. L'ancien SOAP reste branché pour le suivi / annulation / étiquette des colis créés avant la migration ; l'URL du suivi v1 (non documentée à ce jour) est configurable dans l'onglet transporteur.
- Nouveau : **désignation FR des articles dans les payloads transporteurs** (9/9 : ADEX, AFEX, Aramex, Colissimo TN, Fiabilo, FirstDelivery, Intigo, Jetpack, Navex) — les boutiques COD nomment produits/variations en arabe alors que les APIs transporteurs affichent une désignation lisible par le livreur. Recomposition pièce par pièce via `WCMCS_Localization::item_designation_french()` : nom produit FR (post meta `_wcmcs_name_fr` sur le parent, puis filtre `wcmcs_product_name_fr`, puis titre exact) + valeurs d'attribut FR (term meta `wcmcs_term_fr` par slug ou libellé arabe, puis filtre `wcmcs_term_fr`, puis valeur brute — pointures numériques 40–44 passées telles quelles), accents retirés (filtre `wcmcs_designation_strip_accents`, défaut activé — gabarit « Vetements » des docs transporteurs). **Sans aucun mapping : titre exact + valeurs brutes/slugs (souvent déjà latines), déploiement sans risque** ; villes/gouvernorats déjà gérés, inchangés.

## 2.9.2

- Nouveau : **actions groupées « ☎ Marquer confirmée » et « 📦 Marquer emballée »** — pipeline COD accessible depuis la liste des commandes (menu Actions groupées, aux côtés des statuts natifs WooCommerce) : l'agent qui confirme plusieurs commandes par téléphone (ou les prépare) passe tout en lot. Traitement natif WooCommerce (`mark_*` → `update_status`, note « Order status changed by bulk edit. ») — l'onglet filtre « Confirmée (N) » / « Emballée (N) » s'ajoute automatiquement dès la 1re commande dans le statut. Traductions EN/ar.

## 2.9.1

- Nouveau : **badges de statut harmonisés** avec le badge « Synchronisé » de la colonne Sync — même gabarit (`padding 2px 8px`, rayon 3px, police 11px semi-grasse, marge 0) appliqué aux marques WooCommerce classiques (`mark.order-status`) et HPOS (`.fulfillment-status`) pour les 7 statuts personnalisés ; le texte conserve l'auto-contraste noir/blanc selon la couleur du badge (YIQ).

## 2.9.0

- Nouveau : **règles de suivi FirstDelivery étendues (retours prod)** — « Au magasin » (code 8 = retour au dépôt) et « Rtn client/agence » passent maintenant la commande en **« Retournée »** ; les colis « En attente » / « En cours » (codes 0/1) ramènent une commande encore **« En cours »** en **« En attente »** (file de suivi agent — les statuts pipeline Téléchargée/Confirmée/Emballée ne sont jamais rabaisés ; autre transporteurs inchangés).
- Nouveau : **correction du suivi de statut** — la transition livrée/retournée s'applique dès que la commande n'est pas au statut cible, **même si l'état transporteur est inchangé** (l'anti-spam `meta _wcmcs_last_state` ne filtre plus que les notes en double) ; une commande restée « En cours » malgré un retour connu (ex. #550 prod) est corrigée au scan suivant. **Statuts terminaux WC (Terminée/Annulée/Remboursée/Échouée) jamais modifiés** — mémo de l'état seul. Notes de statut traduites (EN/ar).
- Nouveau : **libellés multilingues (fr/en/ar)** — défauts « vrais noms » par langue du site (Téléchargée/Synced/تم التحميل …) + champs « Libellés personnalisés » pour chaque statut dans les 3 langues (15 champs). La langue suit WordPress (« déterminer la langue » : profil utilisateur sinon langue du site) ; la langue FR n'applicable qu'aux sites FR.
- Nouveau : traductions `.po`/`.mo` (EN/ar) rafraîchies — 82 chaînes (v2.5.0 → 2.9.0).
- Nouveau : **onglet « 🔄 Statuts de commande »** — cycle de vie configurable complet : Commande passée (défaut **En attente** : commande COD reçue non payée, file de confirmation) · Après synchronisation (défaut Téléchargée) · À la livraison (Livrée/Terminée) · Au retour (Retournée/En attente) + libellés personnalisés. Les anciennes options de l'onglet Général sont migrées (valeurs conservées, compatibilité totale) — mécanisme `woocommerce_cod_process_payment_order_status` (le paiement COD n'appelle jamais `payment_complete()` ; les commandes payées en ligne restent « En cours »).
- Nouveau : **statuts « Confirmée » et « Emballée »** (`wc-wcmcs-confirmed`, `wc-wcmcs-packed`) — pipeline COD complet : En attente → Confirmée → Emballée → Téléchargée → Livrée / Retournée. L'admin peut sauter librement les étapes (En attente → Téléchargée direct) ; la sync passe à « Téléchargée » depuis n'importe quel statut pré-expédition ; surveillance colis (polling) active dès Confirmée/Emballée ; inclus dans le modal « Vérifier les statuts » (9 statuts, filtres par défaut cochés). Libellés configurables dans l'onglet.
- Nouveau : **logo transporteur** dans la colonne Transporteur de la liste des commandes et dans le modal « Vérifier les statuts des colis » (nom accessible via infobulle/alt, texte en repli si pas de logo).
- Nouveau : **section « Apparence des statuts »** (fusion « Libellés personnalisés » + « Couleurs des badges ») — grille 7 lignes × 6 colonnes (Statut / Aperçu / FR / EN / AR / Couleur) : **aperçu du badge en direct** (mise à jour à la frappe et au changement de couleur), bouton ↺ par ligne pour remettre la couleur par défaut, statuts natifs WooCommerce (En cours / En attente) **grisés à libellés figés** (couleur seule modifiable), colonne AR en RTL, responsive `overflow-x`. Défauts couleurs : **En cours gris** (statut natif WC — payé ≠ En cours dans le flux COD, le cash est collecté à la livraison), En attente gold, Confirmée orange, Emballée violet, Téléchargée bleu ciel, Livrée vert foncé, Retournée rouge. Couleurs appliquées à la liste des commandes (badges, texte auto-contraste noir/blanc) et au modal scan (chips statut + pastilles filtres).
- Correctif : normalisation de l'option « Après synchronisation » (`synced` → `wc-wcmcs-synced`) pour `update_status()`.

## 2.8.2

- Nouveau : **modal scan réorganisé** — barre d'actions/compteurs en haut (boutons « ▶ Lancer la vérification » + « Fermer & recharger » et résumé vérifiés · mis à jour · erreurs au-dessus du tableau), topbar aérée (padding 14 px, bouton élargi), largeur **1024 px responsive** (96vw en dessous de 1100px · 100vw mobile) et **mode plein écran togglable** (bouton icône Dashicons dans le header, préférence mémorisée ; plein écran **borné à la zone de travail** — la barre d'admin WP et le menu backoffice restent visibles et cliquables). Bouton × du modal scan aligné sur le gabarit de l'icône plein écran (28 px, même couleur).
- Nouveau : filtre statuts réduit à **un seul lien « Tout / Aucun » togglé** (libellé auto-recalculé à chaque changement de cases).
- Nouveau : **filtre multi-statuts dans le modal « Vérifier les statuts des colis »** — cases à cocher (Téléchargée · Processing · On hold · Paiement en attente · Terminée · Livrée · Retournée + Tout/Aucun) avec filtrage instantané côté client, sans re-fetch. Défaut = commandes « en jeu » uniquement (non terminales) : les colis livrés/retournés et statuts Terminal sont décochés par défaut.
- Nouveau : badges d'état transporteur colorés par ligne (inconnu / en cours / livré / retour) via `WCMCS_Order_Status_Sync::classify_state()`.
- « ▶ Lancer la vérification » traite uniquement les colis **cochés et visibles** (filtres verrouillés pendant l'exécution).
- Fetch initial étendu à **200** colis (statuts : synced, processing, on-hold, pending, completed, livrée, retournée).

## 2.8.1

- Correctif : le scan global des statuts ne démarre plus automatiquement — ouverture du modal avec liste + **cases à cocher** (tout sélectionner / désélectionner), lancement uniquement sur clic « ▶ Lancer la vérification ».
- Correctif : appels API transporteur **séquentiels** (1 par 1, délai 1 s) au lieu de parallèles — supprime les erreurs « Too many requests ».
- Nouveau : **backoff automatique** sur saturation API (« Too many requests » ou HTTP 429) — pause 3 s puis 1 nouvel essai par colis avant de marquer l'erreur.

## 2.8.0

- Nouveau : **bouton « 📍 Vérifier tous les statuts » sur la page commandes** — scan global des colis synchronisés à statut non terminaux (processing / on-hold / en attente + statuts custom « Téléchargée » et « Retournée ») : modal ligne par ligne avec état transporteur, bascule automatique livrée/retournée, badge « inconnu » pour les colis jamais consultés, résumé (vérifiés / statuts mis à jour / erreurs API) — limite 100 par scan.
- Nouveau : `WCMCS_Order_Status_Sync::get_scan_candidates($limit)` — énumération partagée entre le batch horaire du cron (30, inchangé) et le scan manuel.

## 2.7.0

- Nouveau : **bouton « 📍 Vérifier le statut »** — metabox commande + action sur la ligne (liste commandes). Interroge le transporteur (API statut par code à barre), applique immédiatement les règles livrée/retournée (statut WC + note), avec anti-spam d'état identique.
- Correction : **catégorisation FirstDelivery sur états en texte** — certains comptes renvoient des libellés (« Livré », « Rtn client/agence », « Retour assigné »…) au lieu de codes numériques : recherche inverse dans `STATUS_MAP` (insensible à la casse) + filet par préfixe normalisé (`retour`/`rtn` → retournée · `livre` → livrée). « Retour annulé » (204) n'agit pas. Codes retours étendus : 201/202/203.
- Validé en conditions réelles : colis FirstDelivery passé par `Rtn client/agence` → `Retour Expéditeur` → `Retour assigné`, commande basculée en « Retournée » avec note.

## 2.6.2

- Correction : **message « Aucun transporteur trouvé » de la recherche** — le message était dupliqué dans chaque carte active (11 fois) et masqué avec son parent : quand aucune recherche ne matchait, la grille apparaissait vide sans aucun retour. Il est désormais unique, placé après la grille, et s'affiche correctement quand aucun transporteur ne correspond.

## 2.6.1

- Correction : **compatibilité PHP 7.4 restaurée** — `str_contains()` (PHP 8+) remplacé par `strpos()` dans la catégorisation Ciblex.
- Correction : **extraction d'état Ciblex tolérante aux enveloppes** — un `status: success/OK/200` en haut de réponse ne masque plus l'état réel du colis (recherche récursive dans les niveaux imbriqués).
- Amélioration : **gouvernorat + ville Ciblex via le système de mapping** (champs checkout personnalisés respectés) — traduction français canonique inchangée.
- Amélioration : catégorisation Ciblex complète côté arabe (`مرتجع/مرتجعة` reconnus en plus de `إرجاع`).

## 2.6.0

- Nouveau : transporteur **Ciblex Express** (ciblexexpress.tn) — API `api.ciblex.delivery/api` (auth header `x-access-token`) : **création** (`POST /orders/Create` : client_name, phone, address, price COD + gouvernorat/ville additionnels) et **suivi** (`POST /orders/GetOrder` barcode). Réponses API à confirmer avec la doc du compte Ciblex (extraction tracking/état tolérante + heuristique d'états « livr/deliver », « retour/return », « توصيل », « إرجاع »). Annulation non documentée. Réglages : token + mapping (nom, adresse, téléphone) + filtre `wcmcs_ciblex_payload`.
- **11 transporteurs actifs** : Adex, Afex, Aramex, Ciblex Express, Colissimo TN, Fiabilo, FirstDelivery, INTIGO, Jetpack, Navex, Shipper Network.

## 2.5.0

- Nouveau : **3 statuts de commande personnalisés** liés au cycle transporteur — `Téléchargée` (sync réussie avec le transporteur), `Livrée` (transporteur signale la livraison, à la place de « Terminée »), `Retournée` (transporteur signale un retour, à la place de « En attente »). Disponibles dans toute l'admin WooCommerce (liste, filtres, actions groupées, quick edit), badges colorés. **Transitions automatiques activées par défaut** (désactivables) : Réglages → Général → « Statuts de commande ». **Libellés affichés personnalisables** (champs texte — ex : « Expédiée ») avec fallback sur les libellés traduits. **Multilingue** : FR (base) + AR (`تم التحميل`/`تم التوصيل`/`مرتجعة`) + EN (`Synced`/`Delivered`/`Returned`) via fichiers `.po`/`.mo` inclus. Le suivi automatique continue sur les commandes « Téléchargée »/« Retournée » (sinon la détection « Livrée » ne tournerait plus) ; « Livrée » est terminal comme « Terminée ». La section « Email alerte développeur » n'apparaît plus dans les réglages (fonctionnement inchangé : actif par défaut, email `houssem.baklouty@gmail.com`, throttle 15 min).
- Logos : **8 logos réels ajoutés** (A2B, Best Delivery, B2C Delivery, Ciblex Express, Droppex, Insta Delivery, Jax, Mylerz) — le registre complet affiche désormais un logo officiel. **Beebzy et Quickx retirés du registre** (aucun site officiel ni logo public trouvés — 21 transporteurs, 10 actifs + 11 « Bientôt disponible »).
- UX : grille « Transporteurs disponibles » — **actifs toujours en premier** (ordre alphabétique conservé dans chaque groupe) + **champ de recherche temps réel** (casse/accents libres, Entrée ne soumet pas le formulaire, message « Aucun transporteur trouvé. »).

## 2.4.1

- Correction : **résolution gouvernorat renforcée — 9 couches** pour les codes de thème non-ISO (ex : TN-51) : (1) auto-apprentissage, (2) codes personnalisés, (3) ISO, (4) **table des états du thème via WooCommerce** (`WC()->countries->get_states('TN')`), (5) gouvernorat arabe (+ délégation arabe mal placée dans le champ gouvernorat), (6) **nom latin casse/accents libres** (« tunis », « SFAX », « benarous »…), (7) fallback ville exacte, (8) **fallback ville préfixe** (« سوسة الرياض » contient « سوسة » + espace → Sousse ; « Ariana Zone Industrielle » → Ariana), (9) **fallback gouvernorat contenu** comme mot complet (« Cité El Ghazala Ariana » → Ariana ; « بجانب بن عروس المركز » → Ben Arous). Gouvernorat + délégation **toujours en français canonique** pour tous les transporteurs. Auto-apprentissage conservé (chaque code résolu par fallback est mémorisé). Ajout Djerba/Jerba → Médenine.

## 2.4.0

- Nouveau : **email alerte développeur** — chaque échec de sync envoie un email au développeur (défaut : houssem.baklouty@gmail.com) avec un tableau HTML récapitulatif (commande, transporteur, erreur, client, montant, lien). Throttle anti-spam (défaut 15 min entre 2 emails, configurables 5-120 min). Recovery email optionnel quand une commande échouée finit par réussir. Réglages : Général → « Email alerte développeur ».

## 2.3.0

- Nouveau : **fallback gouvernorat par ville + auto-apprentissage** — si un code TN-XX du thème checkout n'est résolu par ni learned ni custom ni ISO ni arabe, le gouvernorat est résolu depuis la **ville/délégation de la commande** (même en arabe, via table ~150 villes → gouvernorats) puis le mapping est **sauvegardé automatiquement** (plus jamais d'erreur après la 1re commande de chaque gouvernorat)
- Ordre de résolution : learned (auto) → personnalisé (réglage) → ISO → arabe → **fallback ville**

## 2.2.1

- Registre étendu : **10 transporteurs vérifiés actifs** ajoutés au registre en « Bientôt disponible » (A2B Delivery, Beebzy, Best Delivery, B2C Delivery, Ciblex Express, Droppex, Insta Delivery, Jax Delivery, Mylerz, Quickx) — **23 transporteurs au total** (10 intégrés + 13 « Bientôt disponible »)
- Les transporteurs sans documentation API publique s'affichent avec « Cette intégration sera bientôt disponible. » — annuaire complet du marché tunisien

## 2.2.0

- Nouveau : transporteur **Navex** (navex.tn) — création (Basic auth, form-urlencoded, gouvernorat strict, sender obligatoire) — API publique limitée (création uniquement)
- Nouveau : transporteur **Shipper Network** (shipper.network/tn) — plateforme dropshipping moderne : création (Bearer token, produits UUID, division_1/délegation), **suivi complet** (GET /orders avec tracking + logs), **annulation** ✓, webhooks support (futur)
- **10 transporteurs actifs** : Adex, Afex, Aramex, Colissimo TN, Fiabilo, FirstDelivery, INTIGO, Jetpack, Navex, Shipper Network

## 2.1.0

- Nouveau : transporteur **Aramex Tunisie** (aramex.com/tn) — API REST/JSON v2 mondiale, modèle freight (poids, ProductGroup DOM/EXP, ProductType PDX/PPX, PaymentType P), **COD Aramex** (CashOnDeliveryAmount — Aramex encaisse puis reverse), **bordereau PDF** (label base64 ou URL), suivi AWB, credentials business complets (email + password + account# + PIN + entity)
- **8 transporteurs actifs** : Adex, Afex, Aramex, Colissimo TN, Fiabilo, FirstDelivery, INTIGO, Jetpack
- Codes produit Aramex Tunisie (entity, ProductType local COD) à confirmer avec l'account manager Aramex

## 2.0.0

- Nouveau : transporteur **Afex** (afex.tn) — documentation **OpenAPI publique** (apis-docs.afex.tn) : création (type envoi, mode règlement, COD), **bordereau PDF** (GET /v1/print_labels), suivi **24 états**, **annulation via tickets** (POST /v1/tickets category=shipment_cancel), gouvernorat + délégation
- **7 transporteurs actifs** : Adex, Afex, Colissimo TN, Fiabilo, FirstDelivery, INTIGO, Jetpack — dont **4 avec les 3 features** (bordereau + suivi + annulation)
- Suivi automatique (v1.9.0) : Afex couvert — `delivered` → Terminée · `returned`/`canceled`/`not_recovered`/`anomaly` → En attente

## 1.10.0

- Nouveau : transporteur **INTIGO** (intigo.tn) — création (pickUp obligatoire avec créneau, `paymentStatus` COD), **bordereau PDF** (GET /bordereau/{nid}), suivi 18 états, **mapping inversé** (INTIGO « city » = gouvernorat, « subDivision » = délégation), apiKey masquée dans les logs
- **6 transporteurs actifs** : Adex, Colissimo TN, Fiabilo, FirstDelivery, INTIGO, Jetpack
- Suivi automatique (v1.9.0) : INTIGO couvert — « Commande livrée » → commande Terminée, retours → En attente

## 1.9.1

- Nouveau : **chips de compatibilité** sur les cards transporteurs (onglet Règles) — 📄 Bordereau ✓/✗ · 📍 Suivi ✓/✗ · ↺ Annulation ✓/✗ (source : `WCMCS_Carrier_Detector::get_carrier_features()`, étendable via le filtre `wcmcs_carrier_features`)

## 1.9.0

- Nouveau : **suivi automatique du colis** — job horaire (Action Scheduler) qui interroge le suivi transporteur des commandes synchronisées et met à jour la commande : **livré → « Terminée » (completed)**, **retour → « En attente » (on-hold)**, avec note interne à chaque changement d'état (anti-spam : jamais deux fois le même état)
- Les commandes terminées/annulées/remboursées ne sont jamais interrogées ni dégradées
- Réglage on/off (Général → « Mise à jour auto du statut de commande », activé par défaut) — 30 commandes par passage (rate-limits transporteurs respectés)

## 1.8.0

- Nouveau : transporteur **Adex** (my.adex.tn) — création (ville/gouvernorat, type de colis, taille par pièce, échange), **BL PDF intégré** (lien 📄 direct), suivi 7 états avec codes, **annulation réelle** (état « En Attente »), téléphones normalisés 8 chiffres, token masqué dans les logs
- **5 transporteurs actifs** : Adex, Colissimo TN, Fiabilo, FirstDelivery, Jetpack

## 1.7.1

- Correction : gouvernorats en **codes** (`TN-61`, `TN61`, `tn 61`) — reconnus et traduits automatiquement : codes **personnalisés** (nouveau réglage Général, ex : `TN-61 = Sfax`, prioritaire — pour les thèmes/plugins avec numérotation propre) + codes **ISO 3166-2:TN officiels** (`TN-34` = Sfax)
- FirstDelivery : champs optionnels `estFragile` / `ouvrirColis` (« oui »/« non », réglages — envoyés uniquement si « Oui ») ; endpoints vérifiés en réel : `/create` et `/etat` confirmés (l'API live rejette `/orders` de la nouvelle doc avec « Method not allowed »)
- Nouveau : **logo officiel** du plugin en tête de la page de réglages

## 1.7.0

- Nouveau : **traduction automatique arabe → français** des gouvernorats et villes pour tous les transporteurs (boutiques en arabe) — 24 gouvernorats + ~40 villes majeures, préfixe « ولاية » accepté, ville arabe inconnue → nom du gouvernorat
- Nouveau : FirstDelivery — **validation stricte du gouvernorat en local** (erreur claire avec la liste des 24, plus de « Gouvernorat invalide » flou de l'API)
- Nouveau : la détection par gouvernorat (règles `TN:SFAX`…) matche aussi les commandes en arabe

## 1.6.0

- Nouveau : **historique des synchronisations** — section « 📜 Historique » dans la metabox de chaque commande + onglet global (filtres par commande / transporteur / statut / dates, pagination) + **export CSV**
- Nouveau : Colissimo — en cas d'erreur de certificat SSL transporteur (expiré/invalide), **nouvelle tentative automatique sans vérification SSL** avant de remonter l'erreur (l'API étant en HTTP clair, aucune sécurité réelle n'est perdue)

## 1.5.1

- Correction : le modal de synchronisation en masse affiche désormais le **vrai statut** de chaque commande (En attente / Synchronisé / Échec / En queue) dans la colonne Statut — le transporteur de la dernière tentative apparaît en info secondaire grise (ex : « Échec · Colissimo TN ») au lieu d'occuper la colonne

## 1.5.0

- Nouveau : transporteur **Fiabilo** (fiabilo.tn) — création de colis avec **BL (bon de livraison) imprimable** intégré (lien 📄 automatique), suivi (17 états), tokens dans le body (multipart), champ code postal mappable, gouvernorat validé strictement

## 1.4.0

- Nouveau : transporteur **Jetpack** (jetpack.tn) — création et suivi de commandes via l'API v2 (2 tokens : ajout + tracking), options colis configurables (échange, fragile, ouverture), gouvernorat validé, prix au format millimes
- 9 transporteurs dans le registre (3 actifs : Colissimo TN, FirstDelivery, Jetpack)

## 1.3.0

- Nouveau : **mode gratuit permanent** — sans licence, toutes les fonctionnalités avec **3 synchronisations réussies par jour** (reset à minuit, heure du site). Fin du blocage total après l'essai
- Essai de 3 jours illimité conservé à l'installation, puis bascule en mode gratuit
- Onglet Licence : carte « Mode gratuit » avec progression X/3 du jour
- Licence (à vie, 1 site) : syncs illimitées, aucune autre différence

## 1.2.0

- Nouveau : **auto-updates** via GitHub Releases — les mises à jour apparaissent directement dans votre administration WordPress (détail + notes de version, mise à jour en 1 clic)
- Nouveau : **vraies cartes/logos des transporteurs** dans les réglages (Colissimo TN, FirstDelivery, Aramex, Intigo, Linkex, Masafa Express, Navex, X Delivery)
- Nouveau : gestion de licences serveur (Keygen) — activation liée au domaine, 1 site par licence, désactivation possible pour migration
- Sécurité : étiquettes PDF servies via un endpoint authentifié (plus d'accès direct public)
- Sécurité : redaction des données personnelles dans les logs de debug
- Sécurité : verrou anti-double-expédition (verrou + désplanification de queue)
- Correction : les commandes non-COD (paiement à la livraison) ne sont plus envoyées aux transporteurs
- Correction : gouvernorat Colisso bloquant si invalide, quantités d'articles exactes chez FirstDelivery
- Correction : retries automatiques à budget dédié, plus de double comptage avec les échecs manuels
- Renommage : le plugin s'appelle désormais **MultiCarrier Sync TN**

## 1.1.0

- Version initiale commerciale : packaging livraison, guide client, languages/

## 1.0.0

- Version initiale : Colissimo TN (SOAP) + FirstDelivery (REST), détection multi-règles, queue Action Scheduler, étiquettes PDF, licence + trial 3 jours
