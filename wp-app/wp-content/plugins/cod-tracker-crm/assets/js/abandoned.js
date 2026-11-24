/* Commandes abandonnées : capture du formulaire COD de la fiche produit (COD Tracker CRM). */
(function ($) {
    'use strict';
    var cfg = window.codcrmAb;
    var $form = $('#codplugin_woo_single_form');
    if (!cfg || !$form.length) { return; }

    // Jeton de session : relie la commande abandonnée à la commande finale (champ envoyé avec « Acheter »).
    var token = '';
    try { token = window.sessionStorage.getItem('codcrm_ab') || ''; } catch (e) { token = ''; }
    if (!/^[a-z0-9]{16,40}$/.test(token)) {
        token = (Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2) + Date.now().toString(36)).replace(/[^a-z0-9]/g, '').slice(0, 32);
        try { window.sessionStorage.setItem('codcrm_ab', token); } catch (e) { /* navigation privée */ }
    }
    $form.append('<input type="hidden" name="codcrm_ab" value="' + token + '" />');

    var timer = null, lastSent = '', submitted = false, dirty = false;

    function phoneOk() {
        var d = String($form.find('input[name="phone_number"]').val() || '').replace(/\D+/g, '').replace(/^(00)?216(?=\d{8}$)/, '');
        return /^\d{8}$/.test(d);
    }

    function payload() {
        var p = {
            action: 'codcrm_ab_capture',
            nonce: $('#rwc-form-nonce').val() || '',
            token: token,
            product_id: $form.find('input[name="product_id"]').val() || '',
            phone_number: $form.find('input[name="phone_number"]').val() || '',
            full_name: $form.find('input[name="full_name"]').val() || '',
            codplugin_state: $form.find('[name="codplugin_state"]').val() || '',
            codplugin_city: $form.find('[name="codplugin_city"]').val() || '',
            full_address: $form.find('[name="full_address"]').val() || '',
            billing_email: $form.find('input[name="billing_email"]').val() || '',
            codplugin_website: $form.find('input[name="codplugin_website"]').val() || '',
            codplugin_c_number: $('#codplugin_c_number').val() || 1,
            vars: []
        };
        var qty = Math.max(1, parseInt(p.codplugin_c_number, 10) || 1);
        $form.find('.codplugin-article').slice(0, qty).each(function () {
            p.vars.push(parseInt($(this).find('.codplugin-item-var').val(), 10) || 0);
        });
        return p;
    }

    function send(useBeacon) {
        clearTimeout(timer);
        if (submitted || !phoneOk()) { return; }
        var p = payload(), key = JSON.stringify(p);
        if (key === lastSent) { return; }
        lastSent = key;
        dirty = false;
        if (useBeacon && navigator.sendBeacon) {
            var fd = new FormData();
            $.each(p, function (k, v) {
                if (k === 'vars') { $.each(v, function (_, x) { fd.append('vars[]', x); }); } else { fd.append(k, v); }
            });
            navigator.sendBeacon(cfg.ajax, fd);
            return;
        }
        $.post(cfg.ajax, p).done(function (r) {
            // Nonce périmé (page en cache) : on en redemande un au thème, puis on retente une fois.
            if (r && r.success === false && r.data && r.data.code === 'nonce') {
                $.post(cfg.ajax, { action: 'wparabic_cod_nonce' }).done(function (n) {
                    if (n && n.success && n.data && n.data.nonce) {
                        $('#rwc-form-nonce').val(n.data.nonce);
                        lastSent = '';
                        schedule(200);
                    }
                });
            }
        });
    }

    function schedule(ms) {
        if (submitted) { return; }
        dirty = true;
        clearTimeout(timer);
        timer = setTimeout(function () { send(false); }, ms || 1500);
    }

    // Toute saisie / changement dans le formulaire (téléphone, adresse, quantité, variations…).
    $form.on('input change', 'input, select, textarea', function () { schedule(); });
    // Les blocs de variation / quantité peuvent changer sans événement sur le formulaire.
    $(document).on('change', '#codplugin_c_number, .codplugin-item-var', function () { schedule(); });

    // Le client valide : on arrête de capturer (la commande finale absorbera l'abandonnée).
    $form.on('submit', function () { submitted = true; clearTimeout(timer); });
    $(document).on('click', '#nrwooconfirm', function () { submitted = true; clearTimeout(timer); });
    $(document).ajaxComplete(function (e, xhr, settings) {
        var d = settings && settings.data;
        if (typeof d !== 'string' || d.indexOf('action=codplugin_submit') === -1) { return; }
        var ok = xhr && xhr.responseJSON && xhr.responseJSON.success;
        if (ok) {
            try { window.sessionStorage.removeItem('codcrm_ab'); } catch (err) { /* rien */ }
        } else {
            submitted = false; // refus (champ manquant…) : le client est toujours dans le formulaire.
        }
    });

    // Fermeture / changement d'onglet : dernier envoi sans bloquer la page.
    $(window).on('pagehide', function () { if (dirty) { send(true); } });
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden' && dirty) { send(true); } });
})(jQuery);
