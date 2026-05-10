# WC MultiCarrier Sync — Documentation Technique

## Architecture

```
wc-multicarrier-sync/
├── wc-multicarrier-sync.php          ← Bootstrap, constantes, autoloader
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
├── admin/
│   ├── class-admin-orders.php        ← Colonnes, bulk actions, metabox, AJAX
│   └── class-admin-settings.php     ← Page config, onglets, règles détection
├── compatibility/
│   └── class-wc-compat.php          ← Abstraction HPOS/legacy, AS/WP Cron
├── includes/
│   ├── interface-shipping-provider.php  ← Contrat transporteur
│   ├── class-installer.php          ← Activation, tables DB, options
│   ├── class-order-meta.php         ← Lecture/écriture meta sync
│   ├── class-carrier-detector.php   ← Moteur de règles, registre providers
│   └── class-sync-manager.php       ← Orchestration sync single + bulk
├── integrations/
│   ├── firstdelivery/
│   │   └── class-firstdelivery-provider.php
│   └── colissimo/
│       └── class-colissimo-provider.php
├── queues/
│   └── class-queue-manager.php      ← Action Scheduler + WP Cron fallback
└── logs/
    └── class-logger.php             ← WC_Logger + fichier custom fallback
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
         ↓ (exécuté par AS ou WP Cron)
WCMCS_Sync_Manager::sync_single($order_id)
    ├── WCMCS_WC_Compat::get_order($id)
    ├── WCMCS_Carrier_Detector::detect($order)
    │     ├── Mapping manuel forcé
    │     ├── Hook wcmcs_detect_carrier_custom
    │     ├── Règles shipping method
    │     ├── Règles gouvernorat/pays
    │     ├── Règles catégorie produit
    │     └── Transporteur par défaut
    ├── $provider->validate_order($order)
    ├── $provider->create_shipment($order) → API HTTP
    └── WCMCS_Order_Meta::mark_synced/failed($order, $result)
```

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
    // ... implémenter toutes les méthodes de l'interface
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

// Modifier le payload avant envoi FirstDelivery
add_filter('wcmcs_firstdelivery_payload', function($payload, $order) {
    $payload['custom_field'] = 'value';
    return $payload;
}, 10, 2);

// Modifier le payload Colissimo
add_filter('wcmcs_colissimo_payload', function($payload, $order) { ... }, 10, 2);

// URLs de tracking custom
add_filter('wcmcs_tracking_urls', function($urls) {
    $urls['mon-transporteur'] = 'https://tracking.mon-transporteur.tn/{tracking_id}';
    return $urls;
});

// Méthodes de paiement COD
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
```

---

## Meta keys WooCommerce

| Meta key                | Type    | Description                          |
|-------------------------|---------|--------------------------------------|
| `_wcmcs_sync_status`    | string  | pending \| synced \| failed \| queued |
| `_wcmcs_tracking_id`    | string  | Numéro de suivi transporteur         |
| `_wcmcs_carrier_slug`   | string  | firstdelivery \| colissimo \| ...    |
| `_wcmcs_sync_date`      | string  | MySQL datetime de la dernière sync   |
| `_wcmcs_error_message`  | string  | Message d'erreur humain              |
| `_wcmcs_api_response`   | JSON    | Réponse brute de l'API               |
| `_wcmcs_retry_count`    | int     | Nombre de tentatives                 |
| `_wcmcs_label_url`      | string  | URL du PDF étiquette                 |
| `_wcmcs_forced_carrier` | string  | Forcer un transporteur spécifique    |

---

## Plan de développement

### Phase 1 — Core (semaines 1-2)
- [x] Architecture plugin + autoloader
- [x] Interface ShippingProviderInterface
- [x] Couche compatibilité HPOS/WC Compat
- [x] Order meta manager
- [x] Carrier detector (moteur de règles)
- [x] Sync manager (single + bulk)
- [x] Logger (WC_Logger + fallback)

### Phase 2 — Intégrations (semaines 3-4)
- [x] Intégration FirstDelivery
- [x] Intégration Colissimo
- [x] Queue manager (Action Scheduler + WP Cron)
- [x] Admin orders (colonnes, bulk, metabox, AJAX)
- [x] Settings page (onglets, règles)

### Phase 3 — Production (semaines 5-6)
- [ ] Tests unitaires (PHPUnit + WP_Mock)
- [ ] Tests d'intégration avec les APIs réelles
- [ ] Ajuster le mapping payload FirstDelivery selon doc réelle
- [ ] Retries automatiques avec backoff
- [ ] Rapport email admin en cas d'échecs en masse
- [ ] Widget tableau de bord (stats sync)
- [ ] Export CSV des logs de sync

### Phase 4 — Évolutions futures
- [ ] Provider DHL / Aramex / TNT
- [ ] Génération étiquettes en masse (ZIP)
- [ ] Webhook de tracking (mise à jour statut commande auto)
- [ ] API REST publique pour intégrations tierces

---

## Sécurité checklist

- ✅ `defined('ABSPATH') || exit` sur chaque fichier
- ✅ `current_user_can('manage_woocommerce')` sur toutes les actions admin
- ✅ `check_admin_referer()` / `check_ajax_referer()` sur toutes les requêtes
- ✅ `sanitize_*()` sur tous les inputs
- ✅ `esc_html/attr/url()` sur tous les outputs
- ✅ API keys stockées via `get_option` (jamais en dur)
- ✅ Logs protégés par `.htaccess`
- ✅ `wp_remote_request()` (pas `file_get_contents`)
- ✅ Nonces uniques par action

---

## Performance

- **Action Scheduler** : traitement async, pas de timeout PHP
- **Batch processing** : 10 commandes/cycle par défaut (configurable)
- **Isolation des erreurs** : try/catch par commande, le batch continue toujours
- **Pas de requêtes en boucle** : utiliser `wc_get_orders()` (pas WP_Query)
- **`save_meta_data()` différé** : toutes les meta sont écrites en une seule fois
