# MultiCarrier Sync TN — Documentation Technique

Plugin WooCommerce de synchronisation multi-transporteurs. Envoie les commandes vers les APIs de livraison (Colissimo TN, FirstDelivery) et gère le tracking, les étiquettes PDF et les retries.

---

## Architecture

```
wc-multicarrier-sync/
├── wc-multicarrier-sync.php              ← Bootstrap, constantes, autoloader
├── assets/
│   ├── css/admin.css
│   └── js/admin.js                       ← AJAX sync, retry, label PDF (échappement XSS systématique)
├── admin/
│   ├── class-admin-orders.php            ← Colonnes, bulk actions, metabox, AJAX, label PDF (endpoint authentifié)
│   └── class-admin-settings.php          ← Page config, onglets, règles détection, field mapping
├── compatibility/
│   └── class-wc-compat.php               ← Abstraction HPOS/legacy, Action Scheduler
├── includes/
│   ├── interface-shipping-provider.php  ← Contrat transporteur
│   ├── trait-field-mapping.php          ← Trait partagé de mapping WC → transporteur (commun aux providers)
│   ├── class-installer.php              ← Activation, tables DB, options
│   ├── class-order-meta.php             ← Lecture/écriture meta sync + journal DB wcmcs_sync_log
│   ├── class-carrier-detector.php       ← Moteur de règles, registre providers
│   └── class-sync-manager.php           ← Orchestration sync single + bulk (verrou anti-concurrence)
├── integrations/
│   ├── firstdelivery/
│   │   └── class-firstdelivery-provider.php  ← API REST FirstDelivery v2 (Bearer token)
│   └── colissimo/
│       └── class-colissimo-provider.php     ← API SOAP Colissimo TN (AuthHeader)
├── queues/
│   └── class-queue-manager.php          ← Action Scheduler uniquement (retries auto, cleanup actions coincées)
└── logs/
    └── class-logger.php                  ← WC_Logger + fallback fichier, redaction PII
```

---

## Workflow technique

```
Admin sélectionne commandes → Bulk Action "Synchroniser"
         ↓
WCMCS_Admin_Orders::handle_bulk_actions()
         ↓
WCMCS_Sync_Manager::sync_bulk($order_ids, $async=true)
         ↓ (Action Scheduler disponible)
Pour chaque commande → as_schedule_single_action(HOOK_SINGLE)
         ↓ (exécuté par AS)
WCMCS_Sync_Manager::sync_single($order_id)
     ├── WCMCS_License::is_operational() (blocage si trial/licence expiré)
     ├── WCMCS_WC_Compat::get_order($id)
     ├── Verrou anti-concurrence (_wcmcs_sync_lock, TTL 90 s)
     ├── WCMCS_Carrier_Detector::detect($order)
     │     ├── Mapping manuel forcé (si transporteur activé)
     │     ├── Hook wcmcs_detect_carrier_custom (si transporteur activé)
     │     ├── Règles shipping method
     │     ├── Règles gouvernorat/pays
     │     ├── Règles catégorie produit
     │     └── Transporteur par défaut
     ├── $provider->is_configured()
     ├── Check COD : get_payment_method() ∈ wcmcs_cod_payment_methods (défaut ['cod'])
     │     └── non-COD → skip avec message (aucun envoi transporteur)
     ├── $provider->validate_order($order)
     │     └── Colissimo TN : validation du field mapping + gouvernorat obligatoire
     ├── Filtre wcmcs_pre_sync_order
     ├── $provider->create_shipment($order)
     │     └── Colissimo TN : SOAP AjouterColis avec pic JSON
     └── WCMCS_Order_Meta::mark_synced/failed($order, $result) + journal DB wcmcs_sync_log
```

---

## Colissimo TN — API Reference

### Connexion

| Paramètre | Valeur |
|-----------|--------|
| WSDL | `http://delivery.colissimo.com.tn/wsColissimoGo/wsColissimoGo.asmx?wsdl` |
| Protocol | SOAP 1.2 |
| Auth | Header SOAP `AuthHeader` (`Uilisateur` + `Pass`) |
| Sécurité | ⚠ HTTP uniquement (pas HTTPS) — credentials en clair sur le réseau. Si leur serveur redirige vers un HTTPS au certificat expiré/invalide, **retente automatiquement sans vérification SSL** (log warning) avant d'échouer |

### Opérations supportées

| Opération | Description |
|-----------|-------------|
| `AjouterColis` | Créer un colis — retourne `code_barre` |
| `SupprimerColis` | Supprimer un colis (état "En Attente" uniquement) |
| `GetColis` | Statut d'un colis |
| `GetColisPdf` | Étiquette PDF en base64 |
| `listGouvernorats` | Liste des gouvernorats (cache 24h) |

### Payload `AjouterColis` (pic JSON)

```json
{
  "reference": "20",
  "client": "Nom Prénom",
  "adresse": "12 rue Exemple",
  "ville": "Tunis",
  "gouvernorat": "Tunis",
  "nb_pieces": 1,
  "prix": 49.0,
  "tel1": "20202020",
  "tel2": "",
  "designation": "Chaussure M10 x1",
  "commentaire": "",
  "type": "VO",
  "echange": 0
}
```

### Types de colis

| Code | Description |
|------|-------------|
| `VO` | Versement à domicile (défaut) |
| `VM` | Livraison à domicile |
| `GV` | Grand volume |
| `EXP` | Express |

### Gouvernorats (24)

Ariana, Béja, Ben Arous, Bizerte, Gabès, Gafsa, Jendouba, Kairouan, Kasserine, Kébili, Le Kef, Mahdia, La Manouba, Médenine, Monastir, Nabeul, Sfax, Sousse, Siliana, Sidi Bouzid, Tataouine, Tozeur, Tunis, Zaghouan

La validation est insensible à la casse et aux accents.

---

## Afex — API Reference (OpenAPI publique)

### Connexion

| Paramètre | Valeur |
|-----------|--------|
| Base | `https://apis.afex.tn` (production) · `https://apis-sandbox.afex.tn` (sandbox) |
| Auth | Header **`X-API-Key`** |
| **Docs OpenAPI** | https://apis-docs.afex.tn/openapi.yaml |
| Création | `POST /v1/shipments` (JSON) → `{ barcode, agence_source, agence_destination, zone_source, zone_destination }` |
| Suivi | `GET /v1/shipments/status/{shipmentId}` → `state` (23 états) + `delivered_state` + `attempt_number` + `motif` |
| **Bordereau** | `GET /v1/print_labels?barcodes=X` (PDF) ✓ |
| **Annulation** | `POST /v1/tickets` (category = `shipment_cancel`) ✓ — via ticket · ⚠️ répond **toujours HTTP 200** : l'issue réelle est dans l'enveloppe jsonrpc `result.code` (200 = OK) — vérifiée par le plugin (code ≠ 200 = erreur, la commande reste synchronisée) |

### Champs création

`nom`*, `telephone1`* (entier **8 chiffres** — indicatif 216/00216 strippé, malformé bloqué localement), `telephone2`, `gouvernorat` (strict 24 + traduction), `delegation` (notre ville — traduite), `adresse`*, `marchandise`* (items), `reference` (order ID), `paquets`* (quantité), `type_envoi`* (« Livraison à domicile » / « Échange » — réglage), `cod` (float), `mode_reglement`* (« Seulement en espèces » / « Chèque ou espèces » — réglage), `avec_decharge`, `ouverture_colis`, `manifest`.

### 23 états de suivi

| code | Libellé |
|------|---------|
| `pre_manifest` | Pré-manifest |
| `awaiting_removal` | En attente d'enlèvement |
| `being_removed` | En cours d'enlèvement |
| `awaiting_delivery` | En attente de livraison |
| `in_delivery` | En cours de livraison |
| **`delivered`** | **Livré** → suivi auto : commande **Terminée** |
| `awaiting_return` / `being_returned` / `returned` | Retours |
| **`canceled`** / **`not_recovered`** / **`anomaly`** | **→ suivi auto : commande En attente** |
| **`recovered`** / **`exception_refunded`** / **`exception_not_refunded`** | **états TERMINAUX → suivi auto : « Retournée »** (récupération/échange terminé, issues d'exception — sinon la commande restait « En cours » à vie) |
| `transfered`, `pending`… | Transit (mémo seul) |

### Tickets (annulation + modifications)

`POST /v1/tickets` : categories `shipment_cancel`, `change_information` (adresse/montant/tel), `shipment_report` (report), `shipment_damaged`, `shipment_lost`, `driver_opinion`…

### Hooks

```php
add_filter('wcmcs_afex_payload', function($payload, $order) { ... }, 10, 2);
add_filter('wcmcs_afex_api_timeout', function($timeout) { return 60; });
```

## INTIGO — API Reference

### Connexion

| Paramètre | Valeur |
|-----------|--------|
| Base | `https://partner-app.intigo.tn/api/v1/externalapi` |
| Auth | **`apiKey` dans le body JSON** (dashboard INTIGO → Profile) |
| Création | `POST /adddelivery` → `{ "nid": 5212 }` (201) |
| Suivi | `POST /getdelivery` {apiKey, nid} → `dropOff.status` |
| **Bordereau** | `GET /bordereau/{nid}` (PDF) ✓ |
| Annulation | ❌ non documentée |
| Sécurité | apiKey masquée dans les logs (`[REDACTED]`) |

### ⚠ Mapping INVERSÉ vs les autres transporteurs

| INTIGO | Notre champ | Détail |
|--------|-------------|--------|
| `city` | **gouvernorat** | Validation stricte 24 gouvernorats + traduction (arabe/ISO/codes) |
| `subDivision` | **ville/délégation** | Traduite (arabe → FR, fallback gouvernorat) |

### Champs création (pickUp OBLIGATOIRE)

| Champ | Source |
|-------|--------|
| `apiKey` | réglage (Profile INTIGO) |
| `id` | numéro de commande (cid) |
| `pickUp.address` | **réglage** (adresse de ramassage du marchand) |
| `pickUp.pickUpDate` | aujourd'hui (auto) |
| `pickUp.fromTo` | **réglage** (créneau : 08:00-12:00 / 14:00-17:00 / 18:00-21:00) |
| `pickUp.city` | **réglage** (gouvernorat de ramassage) |
| `paymentStatus` | **toujours 2** (collecte à la livraison — COD) |
| `amount` | total commande |
| `dropOff.address` | mapping (adresse livraison) |
| `ClientName`, `ClientPhone` | mapping |
| `city` | ⚠ **gouvernorat** résolu (strict 24) |
| `subDivision` | ⚠ **ville/délégation** mappée |
| `additionalInfo`, `descProduit` | mapping commentaire + items |
| `isExchange` | false (réglage futur) |

### Suivi (18 états livraison)

- **Livré** : « Commande livrée » (6) → suivi auto : commande **Terminée**
- **Retours** : « Retour définitif » (16), « Retour vendeur » (17), « Annulée par vendeur » (14)… → suivi auto : **En attente**
- **En cours** : « En attente de livraison » (1), « Assigné » (2), « Livraison en cours » (10), « Transfert en cours » (20)…

### Hooks

```php
add_filter('wcmcs_intigo_payload', function($payload, $order) { ... }, 10, 2);
add_filter('wcmcs_intigo_api_timeout', function($timeout) { return 60; });
```

## Adex — API Reference

### Connexion

| Paramètre | Valeur |
|-----------|--------|
| Base | `https://my.adex.tn/api/rest/StColis` |
| Auth | **`Utilisateur` + `Pass` (token UUID) dans le body JSON** — Profil → Accès API REST (valable 1 an) |
| Création | `POST /AjouterVColis` · Suivi : `POST /getColis` (`codeBar`) · **Annulation : `POST /supprimerColis`** (uniquement « En Attente ») |
| BL PDF | `GET /printColis/{code}?Utilisateur=…&Pass=…` (PDF direct, 1 page par pièce) |
| Sécurité | **Token masqué dans les logs** (`Pass: [REDACTED]`) + redaction PII |

### Champs création

`reference` (ID commande), `client`, `adresse`, `ville` (délégation — mappé), `gouvernorat` (**validation stricte** — arabe/codes traduits), `nb_pieces` (quantité réelle), **`prix` millimes (« 36.500 »)**, **`tel1`/`tel2` normalisés : exactement 8 chiffres** (espaces/`+216` strippés, validation locale), `designation`, **`type` requis** (FIX/LGR/MOY/GRD/ONP/BLK/SMD — réglage), `type_colis_tab` auto (taille réglage × nb_pieces, ex « 0/0 »), `fragile`/`autorisation_ouv`/`autorisation_chq`/`echange` (réglages 0/1), `produit_arecevoir` (obligatoire si échange), `adresse_enlevement` (comptes multi-adresses).

**Réponse** : `success: 1` + `num_suivi_cmd` (tracking) + **`url_bl` → lien 📄 étiquette automatique** (auth en query). Erreurs : `result_type: "erreur"` + `result_code` (14 codes mappés FR : auth_invalide, token_expire, tel_incorrect, ville_incorrecte, colis_non_supprimable…).

### États (getColis → code_etat)

0 En Attente · 1 Enlevé · 2 Au Dépôt · 3 En Cours de Livraison · 4 Retour Dépôt · 5 Livré · 6 Retour Expéditeur

### Hooks

```php
add_filter('wcmcs_adex_payload', function($payload, $order) { ... }, 10, 2);
add_filter('wcmcs_adex_api_timeout', function($timeout) { return 60; });
```

## Fiabilo TN — API Reference

### Connexion

| Paramètre | Valeur |
|-----------|--------|
| API (endpoint **unique**) | `https://www.fiabilo.tn/api/v1/post.php` |
| Méthode | POST — **multipart form-data** (body array) |
| Authentification | Champ **`token` dans le body** (pas de header) — 2 tokens : **ajout** + **tracking** |
| Tokens | Espace expéditeur → « Vos Tokens » (fiabilo.tn) |

### Ajout de colis

`prix` (décimal, ex « 50.600 »), `nom`, `gouvernerat` (24 gouvernorats, **validation stricte** insensible casse/accents), `ville`, `adresse`, `cp` (code postal — mappé sur shipping/billing postcode), `tel`, `tel2`, `designation`, `nb_article` (quantité réelle), `msg`, `echange`/`article`/`nb_echange`, `ouvrir` (options par défaut en réglages), `token`.

Réponse : `{"status": 1, "lien": "<URL BL imprimable>", "status_message": "<code à barre>"}` — le **`lien` devient le lien 📄 étiquette** de la commande automatiquement.

### Suivi

Body `code` + token de tracking → `etat` (17 états FR : En attente, En cours, Au magasin, Retour au dépôt, Livré, Livrer paye, client/agence, Retour définitif, Retour reçu, Retour payé, Retour expéditeur, À vérifier, Échange, À enlever, Enlevé, Non reçu, Supprimé, Inconnu) + `motif`.

### Non supporté par l'API

- Annulation de colis (`cancel_shipment` → message explicite)

### Hooks

```php
// Modifier le payload avant envoi Fiabilo
add_filter('wcmcs_fiabilo_payload', function($payload, $order) { ... }, 10, 2);
// Timeout API
add_filter('wcmcs_fiabilo_api_timeout', function($timeout) { return 60; });
```

## FirstDelivery — API

### Connexion (endpoints vérifiés en réel)

| Paramètre | Valeur |
|-----------|--------|
| Base | `https://www.firstdeliverygroup.com/api/v2` |
| **Création** | `POST /create` (JSON, Bearer token) |
| **Suivi** | `POST /etat` (body `barCode`) |
| Annulation | `POST /cancel-orders` |
| Auth | `Authorization: Bearer <token>` |

> ⚠️ La doc v2 mentionne `POST /orders` + `GET /orders?barCode=` : **l'API live
> rejette cet endpoint** (« Method not allowed », vérifié) — `/create` + `/etat`
> sont les endpoints réels (prouvés en production). À re-tester si FirstDelivery
> déploie la nouvelle API.

### Champs création

Client : `nom`, `gouvernerat` (nom français, validé strictement), `ville`, `adresse`, `telephone`, `telephone2`, **`locality_id` résolu PAR COMMANDE** (`GET /localities` — 1756 localités, cache 24 h : localité exacte dans le gouvernorat → délégation → délégation contenant → localité unique hors gouvernorat → réglage global « secours » → omis+warning). Produit : `prix` (0–999 TND), `designation`, `nombreArticle` (quantité réelle), `article`, `nombreEchange`, `commentaire`, `estFragile`/`ouvrirColis` (**toujours envoyés**, `"oui"`/`"non"` — standardisation doc v2 §5). **Rate-limit doc (1 req/s)** : HTTP 429 → pause 3 s + 1 retry.

Statuts (POST /etat, champ `state`) : table de 20 états (0 = En attente, 2 = Livré…).

## Jetpack TN — API Reference

### Connexion

| Paramètre | Valeur |
|-----------|--------|
| API | `https://jetpack.tn/apis-v2` |
| Ajouter une commande | `POST add_order.php` — **token d'ajout** (`X-Api-Key`) |
| Suivi | `POST track_order.php` — **token de tracking** (`X-Api-Key`), 30 req/min |
| Format | `application/x-www-form-urlencoded` (pas JSON) |
| Tokens | Espace expéditeur → Paramètres → API (jetpack.tn/expediteur/api.php) — **2 tokens différents** |

### Payload `add_order` (extraits)

`nom`, `tel`, `tel2`, `adresse`, `ville`, `gouvernerat` (24 gouvernorats, insensible casse/accents), **`prix` au format millimes (« 120.000 »)**, `designation`, `article`, `nb_article` (quantité réelle), `echange`/`nb_echange`, `fragile`, `ouvrir`, `msg` — tous mappables depuis WooCommerce + options par défaut en réglages.

Réponse : `{"status": 1, "status_message": "<code à barre>"}` → le code devient le tracking.

### Suivi `track_order`

`codes` = tracking (jusqu'à 200/requête) → par code : `etat` (16 états FR), `status` (`payee`/`Non payee`), `motif`, et **`pre-etat`/`pre-motif`** = info live du livreur avant clôture de sa tournée (runsheet). **Le `pre-etat` déclenche la transition** dès le scan (`Retour` → Retournée, `Livrer` → Livrée) sans attendre la clôture du runsheet au dépôt — mention « pré-état livreur » dans la note. `A verifier` = état de retour (doc §4.4). Token de suivi manquant → erreur locale explicite (2 tokens distincts : ajout / tracking).

### Non supporté par l'API

- Annulation de colis (`cancel_shipment` → message explicite)
- Étiquette PDF (aucun bouton affiché)

### Hooks

```php
// Modifier le payload avant envoi Jetpack
add_filter('wcmcs_jetpack_payload', function($payload, $order) { ... }, 10, 2);
// Timeout API
add_filter('wcmcs_jetpack_api_timeout', function($timeout) { return 60; });
```

## Shipper Network — API Reference (sans doc officielle)

### Connexion

| Paramètre | Valeur |
|-----------|--------|
| Base | `https://server.shipper.network/api/v1` (REST JSON) |
| Auth | Header **`Authorization: Bearer <token>`** |
| Création | `POST /orders` → `{ id }` (id = tracking) |
| Suivi | `GET /orders/{id}` → `shipments[0].status` |
| Annulation | `POST /orders/{id}/cancel` ✓ |

### Payload création (vérifié en interne)

`client_name`, `phone1`, `address`, `division_1` (**gouvernorat** traduit), `division_2` (délégation traduite), `items[]` (UUID produits du catalogue Shipper + prix), `total_price` (float TND), `shipping_total` (réglage). Catégorisation du suivi : anglais + FR (`delivered`/`livr…`, `returned|refunded|canceled`/`retour|rtn`).

## Navex — API Reference (création seule)

### Connexion

| Paramètre | Valeur |
|-----------|--------|
| Base | `https://app.navex.tn/api/v1/post.php` (form-urlencoded) |
| Auth | Header **`Authorization: Basic <token>:`** (token, mot de passe vide) |
| Création | `POST` → `{ colis: { code_barre\|id\|reference } }` — **sans bloc `colis` = erreur `navex_no_tracking`** (aucun fallback sur `status_message` : un message polluait le tracking) |

Suivi/annulation : **non documentés** — jamais interrogés par le poll horaire (`CARRIER_FEATURES['suivi'] = false`). Payload : `sender_name`/`sender_location`/`sender_gouvernorat` (réglages), client, gouvernorat traduit, ville, prix **millimes** (`36.500`), `designation` FR, `msg`, `echange`/`nb_echange`, `ouvrir` (réglages).

## Ciblex Express — API Reference (sans doc officielle)

### Connexion

| Paramètre | Valeur |
|-----------|--------|
| Base | `https://api.ciblex.delivery/api` (JSON) |
| Auth | Header **`x-access-token`** |
| Création | `POST orders/Create` → barcode |
| Suivi | `POST orders/GetOrder` (`barcode`) — champs libres, extraction tolérante |

### Payload création

Documenté : `client_name`, `phone`, `address`, `price` (COD). Additionnels non documentés (filtrables `wcmcs_ciblex_payload`) : `gouvernorat` (traduit — **warning log si non reconnu**, aligné sur les autres providers), `ville` (traduite). Catégorisation du suivi : latin (`livr|deliver`/`retou|rtn|return`, non-ancré) + arabe (`توصيل`/`إرجاع`/`مرتجع`).

## Field Mapping — WooCommerce → Colissimo TN

### Principe

Chaque champ Colissimo TN est mappable à un champ WooCommerce, avec un champ de secours (fallback) si le premier est vide. La configuration se fait dans **WP Admin → WooCommerce → MultiCarrier Sync → Colissimo**.

### Champs mappables

| Champ Colissimo | Requis | Source par défaut | Fallback par défaut |
|----------------|--------|-------------------|---------------------|
| `client` | ✅ | `shipping_full_name` | `billing_full_name` |
| `adresse` | ✅ | `full_address` | `billing_full_address` |
| `ville` | ✅ | `shipping_city` | `billing_city` |
| `gouvernorat` | ✅ | `shipping_state` | `billing_state` |
| `tel1` | ✅ | `shipping_phone` | `billing_phone` |
| `tel2` | ❌ | — Aucun — | — Aucun — |

### Sources WooCommerce disponibles

| Clé | Description |
|-----|-------------|
| `shipping_first_name` | Prénom (livraison) |
| `shipping_last_name` | Nom (livraison) |
| `shipping_company` | Société (livraison) |
| `shipping_address_1` | Adresse 1 (livraison) |
| `shipping_address_2` | Adresse 2 (livraison) |
| `shipping_city` | Ville (livraison) |
| `shipping_state` | État/Gouvernorat (livraison) |
| `shipping_postcode` | Code postal (livraison) |
| `shipping_country` | Pays (livraison) |
| `shipping_phone` | Téléphone (livraison) |
| `billing_first_name` | Prénom (facturation) |
| `billing_last_name` | Nom (facturation) |
| `billing_company` | Société (facturation) |
| `billing_address_1` | Adresse 1 (facturation) |
| `billing_address_2` | Adresse 2 (facturation) |
| `billing_city` | Ville (facturation) |
| `billing_state` | État/Gouvernorat (facturation) |
| `billing_postcode` | Code postal (facturation) |
| `billing_country` | Pays (facturation) |
| `billing_phone` | Téléphone (facturation) |
| `billing_email` | Email (facturation) |
| `shipping_full_name` | Nom complet (livraison) |
| `billing_full_name` | Nom complet (facturation) |
| `full_address` | Adresse complète (livraison) |
| `billing_full_address` | Adresse complète (facturation) |
| `order_id` | Numéro de commande |
| `customer_note` | Note du client |
| `order_total` | Montant total |

### Règle gouvernorat

Le gouvernorat est **obligatoire et strict** :

- Si vide → erreur bloquante avec la liste des 24 gouvernorats valides
- Si la valeur ne correspond à aucun gouvernorat → erreur bloquante
- Pas de gouvernorat par défaut — l'admin doit mapper le bon champ WC et s'assurer que les commandes ont un gouvernorat valide

---

## Ajouter un transporteur tiers

```php
// Dans votre plugin ou functions.php
add_action('wcmcs_register_providers', function() {
    WCMCS_Carrier_Detector::register_provider(new MonTransporteurProvider());
});

class MonTransporteurProvider implements WCMCS_ShippingProviderInterface {
    public function get_slug(): string { return 'mon-transporteur'; }
    public function get_label(): string { return 'Mon Transporteur'; }
    public function is_configured(): bool { return !empty(get_option('mon_api_key')); }
    public function get_settings_fields(): array { return [...]; }
    public function validate_order(WC_Order $order) { ... }
    public function create_shipment(WC_Order $order) { ... }
    public function cancel_shipment(string $tracking_id, WC_Order $order) { ... }
    public function get_tracking_status(string $tracking_id) { ... }
}
```

Pour ajouter le support étiquette PDF (optionnel) :

```php
// Méthode additionnelle (pas dans l'interface, vérifiée via method_exists)
public function get_label_pdf(string $tracking_id, int $order_id): string|WP_Error {
    // Télécharger et sauvegarder le PDF, retourner l'URL
}
```

---

## Hooks disponibles

### Filtres

```php
// Détection custom du transporteur
add_filter('wcmcs_detect_carrier_custom', function($provider, $order, $providers) {
    // Retourner un WCMCS_ShippingProviderInterface ou null
}, 10, 3);

// Modifier le payload avant envoi FirstDelivery (structure Client/Produit v2)
add_filter('wcmcs_firstdelivery_payload', function($payload, $order) {
    $payload['Client']['commentaire'] = 'Livraison urgente';
    return $payload;
}, 10, 2);

// Modifier le payload avant envoi Colissimo TN (array plat JSON)
add_filter('wcmcs_colissimo_payload', function($payload, $order) {
    $payload['commentaire'] = 'Livraison urgente';
    return $payload;
}, 10, 2);

// URLs de tracking custom
add_filter('wcmcs_tracking_urls', function($urls) {
    $urls['mon-transporteur'] = 'https://tracking.example.com/{tracking_id}';
    return $urls;
});

// Méthodes de paiement COD (défaut : ['cod'] — les commandes non-COD sont skip à la sync)
add_filter('wcmcs_cod_payment_methods', function($methods) {
    $methods[] = 'mon-moyen-paiement-cod';
    return $methods;
});

// Bloquer/modifier une sync avant envoi
add_filter('wcmcs_pre_sync_order', function($proceed, $order, $provider) {
    if ($order->get_total() > 10000) {
        return new WP_Error('too_expensive', 'Commande trop élevée');
    }
    return $proceed;
}, 10, 3);

// Timeout SOAP Colissimo
add_filter('wcmcs_colissimo_soap_timeout', function($timeout) {
    return 60; // 60 secondes au lieu de 45
});
```

### Actions

```php
// Après sync réussie
add_action('wcmcs_order_synced', function($order, $result, $carrier_slug) {
    // Envoyer un email, mettre à jour un CRM...
}, 10, 3);

// Après échec
add_action('wcmcs_order_failed', function($order, $error_message, $carrier_slug) {
    // Alerter l'équipe...
}, 10, 3);

// Après batch complet
add_action('wcmcs_bulk_sync_complete', function($report) {
    // Rapport Slack/email...
});

// Après annulation du colis chez le transporteur
add_action('wcmcs_order_cancelled', function($order, $carrier_slug, $tracking_id) {
    // Notifier l'équipe, comptabiliser...
}, 10, 3);
```

---

## Meta keys WooCommerce

| Meta key | Type | Description |
|----------|------|-------------|
| `_wcmcs_sync_status` | string | pending \| synced \| failed \| queued (« cancelled » = historique seul — la commande repasse à pending après annulation transporteur) |
| `_wcmcs_tracking_id` | string | Numéro de suivi transporteur |
| `_wcmcs_carrier_slug` | string | firstdelivery \| colissimo \| ... |
| `_wcmcs_sync_date` | string | MySQL datetime de la dernière sync |
| `_wcmcs_error_message` | string | Message d'erreur humain |
| `_wcmcs_api_response` | JSON | Réponse brute de l'API |
| `_wcmcs_retry_count` | int | Retries automatiques consommés (budget Action Scheduler, reset à 0 après succès) |
| `_wcmcs_label_url` | string | URL de l'étiquette (endpoint authentifié pour les fichiers locaux) |
| `_wcmcs_forced_carrier` | string | Forcer un transporteur spécifique |

Chaque tentative (queued / synced / failed) est aussi journalisée dans la table
`{$wpdb->prefix}wcmcs_sync_log` (historique persistant : order_id, carrier, statut,
tracking, erreur, réponse API).

---

## Sécurité

- ✅ `defined('ABSPATH') || exit` sur chaque fichier
- ✅ `current_user_can('manage_woocommerce')` sur toutes les actions admin
- ✅ `check_admin_referer()` / `check_ajax_referer()` sur toutes les requêtes
- ✅ `sanitize_*()` sur tous les inputs
- ✅ `esc_html/attr/url()` sur tous les outputs + échappement systématique côté JS (helper `escHtml`)
- ✅ API keys stockées via `get_option` (jamais en dur)
- ✅ Logs protégés par `.htaccess`
- ✅ Étiquettes PDF servies via endpoint authentifié (`wcmcs_download_label` : nonce + cap), dossier `wcmcs-labels/` bloqué par `.htaccess` (deny from all, Apache 2.2/2.4)
- ✅ Redaction PII dans les logs debug (`WCMCS_Logger::redact_pii()` : client/nom, adresse, ville, téléphones, prix)
- ✅ `wp_remote_request()` pour Colissimo TN (SOAP via HTTP, pas d'extension php-soap requise)
- ✅ `wp_remote_request()` pour FirstDelivery
- ✅ Nonces uniques par action
- ✅ Credentials SOAP stripés des logs (AuthHeader redacted)
- ✅ Verrou anti-concurrence `_wcmcs_sync_lock` + désplanification AS avant resync/retry (pas de double expédition)
- ⚠️ API Colissimo TN en HTTP uniquement — credentials transmis en clair (contrainte transporteur, warning affiché dans les réglages)

---

## Suivi automatique du colis (statut de commande)

Job **horaire** (Action Scheduler, hook `wcmcs_poll_tracking`) : interroge `get_tracking_status()` du transporteur pour chaque commande synchronisée (statut WC non terminal), puis :

| État transporteur | Effet commande |
|-------------------|----------------|
| **Livré** (Adex code 5 · FirstDelivery état 2 · Jetpack « Livrer » · Fiabilo « Livré »/« Livrer paye » · Colissimo libellé « livr… » · INTIGO 6 « Commande livrée »/« Delivered » · AFEX `delivered` · Shipper `delivered`/« livr… ») | **« Livrée »** (`wcmcs-delivered`, ou « Terminée »/« En attente » selon le réglage « À la livraison ») + note interne |
| **Retour** (Adex code 6 · FirstDelivery 5/7/8/11/30/31/201-203 — 7 « Rtn client/agence », 8 « Au magasin » = retour au dépôt · Jetpack/Fiabilo « Rtn… »/« Retour… »/« client/agence »/« Non reçu »/« Supprimé »/« Au magasin »/« A verifier » · INTIGO 8/12/14/15/16/17/19/**21 (« Colis perdu »)**/24 + anglais `Return/Lost/Declined…` · AFEX `returned/canceled/not_recovered/anomaly` + **états terminaux `recovered`/`exception_*`** · Shipper `returned/refunded/canceled`) | **« Retournée »** (`wcmcs-returned`, ou « En attente » selon le réglage « Au retour ») + note interne |
| **Jetpack `pre-etat` temps réel** (`Livrer`/`Retour` pendant la tournée, doc §4.4) | transition **dès le scan** — sans attendre la clôture du runsheet au dépôt (mention « pré-état livreur » dans la note) |
| **FirstDelivery « En attente » / « En cours »** (codes 0/1) | commande **« En cours »** → **« En attente »** (file de suivi agent) + note — les statuts pipeline (Téléchargée/Confirmée/Emballée) ne sont jamais rabaisés |
| Autres états (Enlevé, Au dépôt, Échange, transit AFEX/INTIGO…) | Aucun effet — état mémorisé |

- **Correction du statut** : la transition s'applique dès que la commande n'est pas au statut cible, même si l'état transporteur est inchangé — l'anti-spam (meta `_wcmcs_last_state`) ne filtre plus que les notes en double ; une commande restée « En cours » malgré un retour connu est corrigée au scan suivant
- **Jamais de dégradation** : les statuts terminaux (`completed`/`cancelled`/`refunded`/`failed`) ne sont ni interrogés par le poll ni modifiés par le scan/check unitaire — mémo de l'état transporteur seul
- **Cap 30 commandes/passage** (rate-limits : Jetpack 30 req/min) — le reste au passage suivant
- **Réglage on/off** : WooCommerce → MultiCarrier Sync TN → Général → « Mise à jour auto du statut de commande » (défaut : activé)
- Filtre : `wcmcs_tracking_poll_statuses` (statuts WC interrogés)
- **Transporteurs sans suivi** (Navex — matrice `CARRIER_FEATURES['suivi']`) : jamais interrogés par le poll
- Webhooks Adex temps réel : prévus dans une version future (le polling couvre les transporteurs avec suivi)

## Annulation du colis chez le transporteur

Bouton **« ✕ Annuler le colis »** (metabox commande + action ligne de la liste des commandes), visible uniquement si la commande est **synchronisée** et que le transporteur supporte l'annulation (matrice `CARRIER_FEATURES['annulation']` — FirstDelivery, Adex, AFEX, Shipper, Colissimo).

- **Confirmation obligatoire** avant envoi (action destructive)
- **Envoi direct** : si le colis n'est plus « en attente » chez le transporteur, son API rejette et le message s'affiche (ex. Adex « le colis ne peut plus être annulé — uniquement en état En Attente, avant enlèvement »)
- **Après succès** : badge Sync → « En attente » (commande **re-synchronisable immédiatement**), meta purgées (tracking/étiquette/réponse API), **statut WooCommerce inchangé**, note commande + ligne historique « Annulé »
- API : `WCMCS_Sync_Manager::cancel_single( $order_id )` · meta : `WCMCS_Order_Meta::mark_cancelled()` · AJAX : `wcmcs_cancel_shipment` (nonce `wcmcs_ajax`, cap `manage_woocommerce`)
- Action : `wcmcs_order_cancelled( $order, $carrier_slug, $tracking_id )`

## Historique des synchronisations

Chaque tentative (queued / synced / failed) est journalisée dans la table `wcmcs_sync_log` **et consultable** :

- **Metabox commande** : « 📜 Historique (N) » — tentatives de la commande (date, transporteur, statut, tracking/erreur)
- **Onglet WooCommerce → MultiCarrier Sync TN → 📜 Historique** : liste globale paginée avec filtres (commande, transporteur, statut, plage de dates) + **export CSV** (BOM UTF-8, séparateur `;` — Excel FR)
- API : `WCMCS_Order_Meta::get_sync_history( $order_id, $limit )` / `WCMCS_Order_Meta::get_sync_history_all( $args )`

## Compatibilité des transporteurs (chips)

Chaque card transporteur (onglet **Règles de détection**) affiche sa compatibilité — `📄 Bordereau` · `📍 Suivi` · `↺ Annulation` (✓ = supporté, ✗ grisé = non supporté) :

| Transporteur | Bordereau | Suivi | Annulation |
|--------------|:---------:|:-----:|:----------:|
| Adex | ✓ (url_bl) | ✓ | ✓ (supprimerColis) |
| **Afex** | ✓ (print_labels) | ✓ (23 états) | ✓ (tickets) |
| Aramex | ✓ (LabelInfo 9201) | ✓ (TrackShipments) | ✗ |
| Ciblex | ✗ | ✓ (GetOrder) | ✗ |
| Colissimo TN | ✓ (getColisPdf) | ✓ | ✓ (SupprimerColis) |
| Fiabilo | ✓ (lien) | ✓ | ✗ |
| FirstDelivery | ✓ (link) | ✓ | ✓ (cancel-orders) |
| INTIGO | ✓ (bordereau/{nid}) | ✓ | ✗ |
| Jetpack | ✗ | ✓ | ✗ |
| Navex | ✗ | ✗ (non documenté) | ✗ |
| Shipper | ✗ | ✓ (GET /orders/{id}) | ✓ (/orders/{id}/cancel) |

Matrice : `WCMCS_Carrier_Detector::get_carrier_features( $slug )` — étendable pour les transporteurs tiers via le filtre `wcmcs_carrier_features`.

## Localisation arabe → français

Les boutiques tunisiennes opèrent souvent en arabe (checkout en arabe). Les APIs des transporteurs attendant des valeurs **françaises/latines**, le plugin traduit automatiquement gouvernorat + ville (`WCMCS_Localization`, `includes/class-localization.php`) :

| Élément | Comportement |
|---------|--------------|
| **Gouvernorat en CODE** (`TN-61`, `TN61`, `23`…) | **Jamais interprété** (v2.10.0) — les thèmes utilisent leur propre numérotation, incompatible avec les codes ISO officiels (un code de thème lu comme ISO envoyait Bizerte vers Jendouba). Le gouvernorat est résolu depuis la ville/délégation si possible, sinon la valeur brute est envoyée au transporteur (warning en log) |
| **Gouvernorat arabe** (ex : `صفاقس`, `ولاية تونس`, `سيديبوزيد`) | Traduit vers l'orthographe française canonique (`Sfax`, `Tunis`, `Sidi Bouzid`) puis validé — 24 gouvernorats + variantes orthographiques |
| **Gouvernorat latin** (casse/accents libres : `tunis`, `SFAX`, `le kef`) | Résolu vers l'orthographe française canonique (`Tunis`, `Sfax`, `Le Kef`) |
| **Ville arabe connue** (ex : `سوسة`, `الحمامات`, `جربة`) | Traduite (`Sousse`, `Hammamet`, `Djerba`) — ~40 villes majeures + capitales de gouvernorat |
| **Ville arabe inconnue** | **Fallback = nom du gouvernorat résolu** (ex : ville inconnue à Sfax → `Sfax`) |
| **Détection par gouvernorat** | L'état arabe de la commande est traduit avant le match — une règle `TN:SFAX` matche un état `صفاقس` |
| Nom / adresse / téléphone | Non traduits (tels quels) |
| Gouvernorat non reconnu (ni nom, ni résolvable via la ville) | Valeur brute envoyée au transporteur (warning en log) — seul le champ **vide** bloque la sync |

## Licences (Keygen.sh)

La gestion des licences s'appuie sur [Keygen.sh](https://keygen.sh) (API de licensing, free tier OK).

### Workflow de vente (manuel)

1. Dans le dashboard Keygen (Portal) : créer la licence pour le client (policy « à vie, 1 site »)
2. Envoyer la clé au client (ex: `WCMCS-XXXX-XXXX-XXXX-XXXX`)
3. Le client active la clé dans **WooCommerce → MultiCarrier Sync → Licence**
4. La licence est liée au **domaine du site** (fingerprint, `www.` ignoré) — 1 licence = 1 site
5. Suspendre/révoquer depuis Portal → prend effet à la prochaine revalidation (< 24 h)

### Comportement technique

| Aspect | Détail |
|--------|--------|
| **Mode gratuit** (sans licence, après l'essai) | Toutes les fonctionnalités, **3 syncs réussies/jour** (reset à minuit heure du site) — filtre `wcmcs_daily_sync_limit` (défaut 3) ; les échecs ne consomment pas le quota |
| **Essai** | 3 jours **illimités** à l'activation du plugin (local, réactivation ≠ reset) |
| **Licence** (à vie, 1 site) | Syncs illimitées |
| Authentification API | Par la clé de licence du client (`Authorization: License <clé>`) — **aucun secret dans le code** |
| Account | Constante `WCMCS_License::KEYGEN_ACCOUNT` (slug public), filtrable via `wcmcs_keygen_account` |
| Self-hosting futur | Filtre `wcmcs_license_api_base` (compatible Keygen CE) |
| Revalidation | Cron quotidien `wcmcs_license_revalidate` + à la volée sur l'admin (throttle 12 h, couvre `DISABLE_WP_CRON`) |
| Hors ligne | Grace period 7 jours si le serveur de licences est injoignable |
| Migration de site | Bouton « Désactiver la licence » → libère le domaine côté serveur, la clé devient réutilisable |
| Trial | 3 jours en local à l'activation du plugin (réactivation ≠ reset, corrigé) |
| Blocage | Sans licence ni trial : toutes les syncs sont bloquées avec un message explicite |
| Bypass dev | Filtre `wcmcs_license_dev_bypass` (`__return_true` dans un mu-plugin local — jamais en production) |

## Livraison & mises à jour (vendeur)

### Publier une mise à jour (release GitHub, auto-update client)

1. Développer/corriger → **bump `WCMCS_VERSION` + `Version:`** dans `wc-multicarrier-sync.php`
2. `./build-wcmcs-package.sh --release` → build + vérifs auto + release GitHub v{VERSION} + upload du ZIP
   - Token requis dans `~/.github_token` (write, jamais commité) ; sans token, le script affiche la procédure manuelle
3. Les clients détectent la mise à jour **sous ≤ 12 h** (check PUC) : badge « mise à jour disponible » dans wp-admin → clic standard → fait. (Bouton « Check for updates » sur la ligne du plugin = check immédiat)
4. Côté client, la mise à jour conserve licence, réglages et statuts de sync (tout est en DB)

### Auto-updates — architecture

| Élément | Rôle |
|---------|------|
| `includes/libraries/plugin-update-checker/` | Lib PUC v5.7 (MIT) vendorée — détection + update UI native WP |
| `includes/class-updater.php` | Câblage : repo privé `houssembaklouty/multicarrier-sync-tn` + token de distribution |
| **Token de distribution** (embarqué) | Fine-grained PAT GitHub, **portée repo unique, Contents: Read-only** — publiable par conception (il ne donne accès qu'aux ZIPs déjà distribués) |
| **Token de release** (`~/.github_token`, vendeur) | Write — création des releases/tags/assets. **Ne doit jamais être embarqué ni commité** |
| Event `puc_cron_check_updates-wc-multicarrier-sync` | Check automatique toutes les 12 h côté client |

### ⚠️ Renouvellement du token de distribution (T-1 mois)

Le token embarqué a une expiration. **≥ 1 mois avant l'échéance** :
1. Créer un nouveau fine-grained PAT read-only (repo unique, Contents:Read-only, expiration max)
2. Remplacer la constante `WCMCS_Updater::GH_DISTRIBUTION_TOKEN`
3. Bump version → `./build-wcmcs-package.sh --release`
4. Les clients s'auto-mettent à jour **avec l'ancien token encore valide** → la nouvelle version porte le token frais. Si l'échéance est manquée : envoi manuel du ZIP (process ci-dessus).

### Première livraison à un client

1. Portal Keygen → créer licence (clé `WCMCS-…`, policy « À vie — 1 site ») + metadata client
2. `./build-wcmcs-package.sh` → `dist/wc-multicarrier-sync-{VERSION}.zip`
3. Envoyer au client : **ZIP + clé + GUIDE-CLIENT.md** (email/WhatsApp)
4. Le client : Extensions → Ajouter → Téléverser → Activer → entrer la clé (onglet Licence)

> NB : la première version livrée à un client DOIT contenir PUC (1.2.0+) —
> une version sans updater ne peut pas recevoir les auto-updates suivantes.

## Performance

- **Action Scheduler** (bundlé WooCommerce) : traitement async une-commande-une-action, pas de timeout PHP — seul chemin supporté
- **Retries automatiques** : budget dédié (`wcmcs_max_auto_retries`, backoff exponentiel 5 min → 1 h), non consommé par les échecs des syncs manuelles
- **Isolation des erreurs** : try/catch par commande, le batch continue toujours
- **Pas de requêtes en boucle** : `wc_get_orders()` (pas WP_Query)
- **`save_meta_data()` différé** : toutes les meta écrites en une seule fois (HPOS)
- **Gouvernorats cache** : `listGouvernorats()` mis en cache 24h via transient
- **SOAP timeout** : 45s par défaut, filtrable via `wcmcs_colissimo_soap_timeout`
- **Cleanup résilient** : actions AS coincées > 30 min re-planifiées automatiquement (`admin_init`)

---

## Plan de développement

### Phase 1 — Core ✅
- [x] Architecture plugin + autoloader
- [x] Interface ShippingProviderInterface
- [x] Couche compatibilité HPOS/WC Compat
- [x] Order meta manager
- [x] Carrier detector (moteur de règles)
- [x] Sync manager (single + bulk)
- [x] Logger (WC_Logger + fallback)

### Phase 2 — Intégrations ✅
- [x] Intégration FirstDelivery v2 (REST, Bearer token, Client/Produit)
- [x] Intégration Colissimo TN (SOAP, AuthHeader)
- [x] Field mapping configurable (WC → Colissimo TN / FirstDelivery)
- [x] Étiquette PDF (GetColisPdf)
- [x] Queue manager (Action Scheduler + WP Cron)
- [x] Admin orders (colonnes, bulk, metabox, AJAX, label PDF)
- [x] Settings page (onglets, règles détection, field mapping)

### Phase 3 — Production (en cours)
- [ ] Tests unitaires (PHPUnit + WP_Mock)
- [ ] Tests d'intégration avec les APIs réelles
- [x] Logging détaillé (payload, réponse, erreurs SOAP)
- [x] Retries automatiques via `max_auto_retries`
- [ ] Rapport email admin en cas d'échecs en masse
- [ ] Widget tableau de bord (stats sync)
- [ ] Export CSV des logs de sync

### Phase 4 — Évolutions futures
- [ ] Provider DHL / Aramex / TNT
- [ ] Génération étiquettes en masse (ZIP)
- [ ] Webhook de tracking (mise à jour statut commande auto)
- [ ] API REST publique pour intégrations tierces