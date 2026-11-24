/* Formulaire COD de la fiche produit (COD Tracker CRM) :
 *  1) capture des commandes abandonnées (si activée) ;
 *  2) restauration du formulaire après un rafraîchissement (si activée) — stockage local au navigateur du client. */
(function ($) {
    'use strict';
    var cfg = window.codcrmAb;
    var $form = $('#codplugin_woo_single_form');
    // Les blocs d'articles (variations) sont dans #codplugin-checkout, pas dans le <form> lui-même.
    var $root = $('#codplugin-checkout').length ? $('#codplugin-checkout') : $form;
    if (!cfg || !$form.length) { return; }

    var CAPTURE = !!cfg.capture, RESTORE = !!cfg.restore;
    var KEY = 'codcrm_form', TTL = 7 * 24 * 3600 * 1000;
    var submitted = false, restoring = false;

    // ── Jeton (lie l'abandonnée à la commande finale) ─────────
    var token = '';
    try { token = window.sessionStorage.getItem('codcrm_ab') || ''; } catch (e) { token = ''; }
    if (!/^[a-z0-9]{16,40}$/.test(token)) {
        token = (Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2) + Date.now().toString(36)).replace(/[^a-z0-9]/g, '').slice(0, 32);
        try { window.sessionStorage.setItem('codcrm_ab', token); } catch (e) { /* navigation privée */ }
    }
    if (CAPTURE) { $form.append('<input type="hidden" name="codcrm_ab" value="' + token + '" />'); }

    function field(name) { return $form.find('[name="' + name + '"]'); }
    function pid() { return String(cfg.pid || $form.find('input[name="product_id"]').val() || ''); }
    function qty() { return Math.max(1, parseInt($('#codplugin_c_number').val(), 10) || 1); }

    // ── Restauration : sauvegarde locale ──────────────────────
    function snapshot() {
        var vars = [];
        $root.find('.codplugin-article').slice(0, qty()).each(function () {
            var o = {};
            $(this).find('.variations select').each(function () { if (this.name) { o[this.name] = $(this).val() || ''; } });
            vars.push(o);
        });
        return {
            t: Date.now(),
            contact: {
                full_name: field('full_name').val() || '', phone_number: field('phone_number').val() || '',
                codplugin_state: field('codplugin_state').val() || '', codplugin_city: field('codplugin_city').val() || '',
                full_address: field('full_address').val() || '', billing_email: field('billing_email').val() || ''
            },
            product: { pid: pid(), qty: qty(), vars: vars }
        };
    }
    var saveTimer = null;
    function saveLocal() {
        if (!RESTORE || submitted || restoring) { return; }
        clearTimeout(saveTimer);
        saveTimer = setTimeout(function () {
            try {
                var s = snapshot(), any = false;
                $.each(s.contact, function (k, v) { if (v) { any = true; } });
                if (any) { window.localStorage.setItem(KEY, JSON.stringify(s)); }
            } catch (e) { /* stockage indisponible */ }
        }, 500);
    }
    function clearLocal() { try { window.localStorage.removeItem(KEY); } catch (e) { /* rien */ } }

    // ── Restauration : remplissage (champs vides uniquement) ──
    function fill(name, value) {
        var $f = field(name);
        if (!$f.length || !value || $f.val()) { return false; }
        $f.val(value).trigger('input');
        return true;
    }
    function restoreVars(vars) {
        var $blocks = $root.find('.codplugin-article');
        $.each(vars || [], function (i, attrs) {
            var $sels = $blocks.eq(i).find('.variations select'), step = 0;
            $.each(attrs, function (name, val) {
                if (!val) { return; }
                // Un attribut après l'autre : WooCommerce met à jour les options disponibles entre deux.
                setTimeout(function () {
                    var $s = $sels.filter(function () { return this.name === name; });
                    if ($s.length && $s.find('option[value="' + String(val).replace(/"/g, '\\"') + '"]').length) { $s.val(val).trigger('change'); }
                }, 120 * (++step) + 60 * i);
            });
        });
    }
    function restore() {
        if (!RESTORE) { return; }
        var s;
        try { s = JSON.parse(window.localStorage.getItem(KEY) || 'null'); } catch (e) { s = null; }
        if (!s || !s.contact || Date.now() - (s.t || 0) > TTL) { if (s) { clearLocal(); } return; }
        restoring = true;
        var c = s.contact;
        fill('full_name', c.full_name); fill('phone_number', c.phone_number);
        fill('full_address', c.full_address); fill('billing_email', c.billing_email);

        var $state = field('codplugin_state'), $city = field('codplugin_city');
        if (c.codplugin_state && !$state.val() && $state.find('option[value="' + c.codplugin_state + '"]').length) {
            $state.val(c.codplugin_state).trigger('change'); // le thème remplit alors les délégations.
            if (c.codplugin_city) {
                setTimeout(function () {
                    if ($city.find('option').filter(function () { return this.value === c.codplugin_city; }).length) { $city.val(c.codplugin_city).trigger('change'); }
                }, 80);
            }
        }
        // Quantité et variations : seulement sur la même fiche produit.
        var p = s.product || {};
        if (p.pid && p.pid === pid()) {
            var want = Math.max(1, parseInt(p.qty, 10) || 1), guard = 0;
            while (qty() < want && guard++ < 30) {
                var before = qty();
                $('#codplugin_add_button').trigger('click');
                if (qty() === before) { break; } // plafond du thème atteint.
            }
            setTimeout(function () { restoreVars(p.vars); }, 150);
        }
        setTimeout(function () { restoring = false; if (CAPTURE) { schedule(300); } }, 1500);
    }

    // ── Capture des abandonnées ───────────────────────────────
    var timer = null, lastSent = '', dirty = false;

    function phoneOk() {
        var d = String(field('phone_number').val() || '').replace(/\D+/g, '').replace(/^(00)?216(?=\d{8}$)/, '');
        return /^\d{8}$/.test(d);
    }
    function payload() {
        var p = {
            action: 'codcrm_ab_capture', nonce: $('#rwc-form-nonce').val() || '', token: token,
            product_id: $form.find('input[name="product_id"]').val() || '',
            phone_number: field('phone_number').val() || '', full_name: field('full_name').val() || '',
            codplugin_state: field('codplugin_state').val() || '', codplugin_city: field('codplugin_city').val() || '',
            full_address: field('full_address').val() || '', billing_email: field('billing_email').val() || '',
            codplugin_website: field('codplugin_website').val() || '',
            codplugin_c_number: $('#codplugin_c_number').val() || 1, vars: []
        };
        $root.find('.codplugin-article').slice(0, qty()).each(function () {
            p.vars.push(parseInt($(this).find('.codplugin-item-var').val(), 10) || 0);
        });
        return p;
    }
    function send(useBeacon) {
        clearTimeout(timer);
        if (!CAPTURE || submitted || !phoneOk()) { return; }
        var p = payload(), key = JSON.stringify(p);
        if (key === lastSent) { return; }
        lastSent = key; dirty = false;
        if (useBeacon && navigator.sendBeacon) {
            var fd = new FormData();
            $.each(p, function (k, v) { if (k === 'vars') { $.each(v, function (_, x) { fd.append('vars[]', x); }); } else { fd.append(k, v); } });
            navigator.sendBeacon(cfg.ajax, fd);
            return;
        }
        $.post(cfg.ajax, p).done(function (r) {
            // Nonce périmé (page en cache) : on en redemande un au thème, puis on retente une fois.
            if (r && r.success === false && r.data && r.data.code === 'nonce') {
                $.post(cfg.ajax, { action: 'wparabic_cod_nonce' }).done(function (n) {
                    if (n && n.success && n.data && n.data.nonce) { $('#rwc-form-nonce').val(n.data.nonce); lastSent = ''; schedule(200); }
                });
            }
        });
    }
    function schedule(ms) {
        if (!CAPTURE || submitted) { return; }
        dirty = true; clearTimeout(timer);
        timer = setTimeout(function () { send(false); }, ms || 1500);
    }
    function changed() { schedule(); saveLocal(); }

    $form.on('input change', 'input, select, textarea', changed);
    // Variations (couleur, pointure…) : dans les blocs d'articles, hors du <form>.
    $root.on('change', '.variations select', changed);
    $(document).on('found_variation reset_data', '.variations_form', function () { setTimeout(changed, 100); });
    $(document).on('change', '#codplugin_c_number, .codplugin-item-var', changed);
    $(document).on('click', '#codplugin_add_button, #codplugin_remove_button', function () { setTimeout(changed, 50); });

    // Le client valide : la commande finale absorbe l'abandonnée ; le formulaire sauvegardé est effacé si ça réussit.
    function markSubmitted() { submitted = true; clearTimeout(timer); clearTimeout(saveTimer); }
    $form.on('submit', markSubmitted);
    $(document).on('click', '#nrwooconfirm', markSubmitted);
    $(document).ajaxComplete(function (e, xhr, settings) {
        var d = settings && settings.data;
        if (typeof d !== 'string' || d.indexOf('action=codplugin_submit') === -1) { return; }
        if (xhr && xhr.responseJSON && xhr.responseJSON.success) {
            clearLocal();
            try { window.sessionStorage.removeItem('codcrm_ab'); } catch (err) { /* rien */ }
        } else {
            submitted = false; // refus (champ manquant…) : le client est toujours dans le formulaire.
        }
    });

    $(window).on('pagehide', function () { if (dirty) { send(true); } });
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden' && dirty) { send(true); } });

    // Après l'initialisation du thème (main.js) et de WooCommerce (variations).
    if (document.readyState === 'complete') { setTimeout(restore, 400); } else { $(window).on('load', function () { setTimeout(restore, 400); }); }
})(jQuery);
