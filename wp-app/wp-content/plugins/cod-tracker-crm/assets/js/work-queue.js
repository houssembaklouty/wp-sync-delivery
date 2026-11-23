/* global jQuery, codcrm */
(function ($) {
    'use strict';

    var i18n = codcrm.i18n;

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    function post(data) {
        data.nonce = codcrm.nonce;
        return $.post(codcrm.ajax, data);
    }

    function openCallModal(orderId) {
        var opts = '<option value="">' + esc(i18n.choose) + '</option>';
        $.each(codcrm.outcomes, function (k, label) {
            opts += '<option value="' + esc(k) + '">' + esc(label) + '</option>';
        });

        var $m = $(
            '<div class="codcrm-overlay"><div class="codcrm-modal">'
            + '<h2>' + esc(i18n.call_title) + ' — #' + esc(orderId) + '</h2>'
            + '<p><label>' + esc(i18n.outcome) + '<br><select class="codcrm-outcome">' + opts + '</select></label></p>'
            + '<p class="codcrm-cb" style="display:none"><label>' + esc(i18n.callback_at) + '<br><input type="datetime-local" class="codcrm-cbat" /></label></p>'
            + '<p><label>' + esc(i18n.note) + '<br><textarea class="codcrm-note" rows="3" maxlength="255"></textarea></label></p>'
            + '<p class="codcrm-err" style="color:#b32d2e"></p>'
            + '<p><button type="button" class="button button-primary codcrm-save">' + esc(i18n.save) + '</button> '
            + '<button type="button" class="button codcrm-cancel">' + esc(i18n.cancel) + '</button></p>'
            + '</div></div>'
        );
        $('body').append($m);

        $m.on('change', '.codcrm-outcome', function () {
            $m.find('.codcrm-cb').toggle($(this).val() === 'reporte');
        });
        $m.on('click', '.codcrm-cancel', function () { $m.remove(); });
        $m.on('click', '.codcrm-save', function () {
            var $b = $(this).prop('disabled', true);
            post({
                action: 'codcrm_log_contact',
                order_id: orderId,
                channel: 'call',
                outcome: $m.find('.codcrm-outcome').val(),
                callback_at: $m.find('.codcrm-cbat').val(),
                note: $m.find('.codcrm-note').val()
            }).done(function (r) {
                if (r.success) {
                    location.reload();
                } else {
                    $m.find('.codcrm-err').text((r.data && r.data.message) || i18n.error);
                    $b.prop('disabled', false);
                }
            }).fail(function () {
                $m.find('.codcrm-err').text(i18n.error);
                $b.prop('disabled', false);
            });
        });
    }

    // « Appeler » : l'appel part via le lien tel:, le résultat est demandé tout de suite.
    $(document).on('click', '.codcrm-call', function () {
        openCallModal($(this).data('order'));
        // Pas de preventDefault : le navigateur ouvre le composeur d'appel.
    });

    // ── Fenêtre « Message » : WhatsApp / SMS, FR / AR, modèle suggéré selon l'étape ──
    var GSM7 = /^[A-Za-z0-9 \r\n@£$¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ!"#¤%&'()*+,\-.\/:;<=>?¡ÄÖÑÜ§¿äöñüà^{}\\\[~\]|€]*$/;

    function smsInfo(text) {
        var gsm = GSM7.test(text), len = text.length;
        var single = gsm ? 160 : 70, multi = gsm ? 153 : 67;
        var parts = len <= single ? (len ? 1 : 0) : Math.ceil(len / multi);
        return { len: len, parts: parts };
    }

    function openMessageModal(orderId) {
        var $m = $(
            '<div class="codcrm-overlay"><div class="codcrm-modal codcrm-modal-msg">'
            + '<h2>' + esc(i18n.msg_title) + ' — #' + esc(orderId) + '</h2>'
            + '<p class="codcrm-msg-body">' + esc(i18n.msg_loading) + '</p>'
            + '<p class="codcrm-err" style="color:#b32d2e"></p>'
            + '<p><button type="button" class="button button-primary codcrm-send" disabled></button> '
            + '<button type="button" class="button codcrm-cancel">' + esc(i18n.cancel) + '</button></p>'
            + '</div></div>'
        );
        $('body').append($m);
        $m.on('click', '.codcrm-cancel', function () { $m.remove(); });

        var st = { channel: 'whatsapp', lang: 'fr', context: '', phone8: '', sms: false, seq: 0 };

        function setSendLabel() {
            $m.find('.codcrm-send').text(st.channel === 'sms' ? i18n.msg_send_sms : i18n.msg_send_wa);
            // Longueur maximale selon le canal (le serveur refuse un SMS > 480 sans le tronquer).
            $m.find('.codcrm-msgtext').attr('maxlength', st.channel === 'sms' ? 480 : 1000);
        }

        // Après un envoi : « Mes suivis » se recharge ; la fiche commande NON (des modifications
        // non enregistrées de la commande seraient perdues) → on ferme et on confirme.
        function afterSend() {
            if (codcrm.orderScreen) {
                $m.remove();
                var $box = $('#codcrm-timeline .inside');
                $box.find('.codcrm-sent').remove();
                $box.prepend('<div class="notice notice-success inline codcrm-sent"><p>' + esc(i18n.msg_sent) + '</p></div>');
            } else {
                location.reload();
            }
        }

        function updateCount() {
            var t = $m.find('.codcrm-msgtext').val() || '', $c = $m.find('.codcrm-msg-count');
            if (st.channel === 'sms') {
                var i = smsInfo(t);
                $c.text(i.len + ' ' + i18n.msg_chars + ' · ' + i.parts + ' ' + i18n.msg_parts).toggleClass('is-long', i.parts > 2);
            } else {
                $c.text(t.length + ' ' + i18n.msg_chars).removeClass('is-long');
            }
        }

        function loadPreview() {
            // Plusieurs changements rapides = plusieurs requêtes : seule la dernière compte,
            // sinon une réponse lente remplirait le texte d'une autre situation / langue.
            var seq = ++st.seq;
            var $ta = $m.find('.codcrm-msgtext').prop('disabled', true);
            $m.find('.codcrm-send').prop('disabled', true);
            post({ action: 'codcrm_message_preview', order_id: orderId, context: st.context, lang: st.lang, channel: st.channel })
                .done(function (r) {
                    if (seq !== st.seq) { return; }
                    if (r.success) {
                        $ta.val(r.data.text).attr('dir', st.lang === 'ar' ? 'rtl' : 'ltr');
                        $m.find('.codcrm-err').text('');
                    } else {
                        $m.find('.codcrm-err').text((r.data && r.data.message) || i18n.error);
                    }
                })
                .fail(function () { if (seq === st.seq) { $m.find('.codcrm-err').text(i18n.error); } })
                .always(function () {
                    if (seq !== st.seq) { return; }
                    $ta.prop('disabled', false); updateCount(); $m.find('.codcrm-send').prop('disabled', false);
                });
        }

        post({ action: 'codcrm_message_init', order_id: orderId }).done(function (r) {
            if (!r.success) {
                $m.find('.codcrm-msg-body').text('');
                $m.find('.codcrm-err').text((r.data && r.data.message) || i18n.error);
                return;
            }
            var d = r.data;
            st.lang = d.lang; st.context = d.suggested; st.phone8 = d.phone8; st.sms = !!d.sms;

            var ctxOpts = '<option value="' + esc(d.suggested) + '">★ ' + esc(d.contexts[d.suggested]) + ' (' + esc(i18n.msg_suggest) + ')</option>';
            $.each(d.contexts, function (k, label) {
                if (k !== d.suggested) { ctxOpts += '<option value="' + esc(k) + '">' + esc(label) + '</option>'; }
            });

            $m.find('.codcrm-msg-body').replaceWith(
                '<div class="codcrm-msg-body">'
                + '<div class="codcrm-msg-row"><strong>' + esc(i18n.msg_channel) + '</strong> '
                + '<label><input type="radio" name="codcrm_ch" value="whatsapp" checked /> WhatsApp</label> '
                + '<label><input type="radio" name="codcrm_ch" value="sms"' + (st.sms ? '' : ' disabled') + ' /> SMS</label>'
                + (st.sms ? '' : ' <small class="codcrm-muted">' + esc(i18n.msg_no_sms) + '</small>') + '</div>'
                + '<div class="codcrm-msg-row"><strong>' + esc(i18n.msg_lang) + '</strong> '
                + '<label><input type="radio" name="codcrm_lg" value="fr"' + (st.lang === 'fr' ? ' checked' : '') + ' /> Français</label> '
                + '<label><input type="radio" name="codcrm_lg" value="ar"' + (st.lang === 'ar' ? ' checked' : '') + ' /> العربية</label></div>'
                + '<p><label>' + esc(i18n.msg_context) + '<br><select class="codcrm-msgctx">' + ctxOpts + '</select></label></p>'
                + '<p><label>' + esc(i18n.msg_text) + '<br><textarea class="codcrm-msgtext" rows="7" maxlength="1000"></textarea></label>'
                + '<span class="codcrm-msg-count"></span></p></div>'
            );
            setSendLabel();
            loadPreview();
        }).fail(function () {
            $m.find('.codcrm-msg-body').text('');
            $m.find('.codcrm-err').text(i18n.error);
        });

        $m.on('change', 'input[name=codcrm_ch]', function () { st.channel = $(this).val(); setSendLabel(); loadPreview(); });
        $m.on('change', 'input[name=codcrm_lg]', function () { st.lang = $(this).val(); loadPreview(); });
        $m.on('change', '.codcrm-msgctx', function () { st.context = $(this).val(); loadPreview(); });
        $m.on('input', '.codcrm-msgtext', updateCount);

        $m.on('click', '.codcrm-send', function () {
            var text = $.trim($m.find('.codcrm-msgtext').val());
            if (!text) { $m.find('.codcrm-err').text(i18n.msg_text); return; }
            var $b = $(this).prop('disabled', true);

            if (st.channel === 'whatsapp') {
                if (!st.phone8) { $m.find('.codcrm-err').text(i18n.error); $b.prop('disabled', false); return; }
                var win = window.open('', '_blank'); // ouvert dans le clic pour éviter le blocage de pop-up.
                var url = 'https://wa.me/216' + st.phone8 + '?text=' + encodeURIComponent(text);
                post({ action: 'codcrm_log_contact', order_id: orderId, channel: 'whatsapp', context: st.context, lang: st.lang })
                    .always(function () {
                        if (win) {
                            win.location = url;
                            afterSend();
                        } else {
                            // Fenêtre bloquée par le navigateur : on navigue ici-même (pas de rechargement
                            // qui annulerait la navigation vers WhatsApp).
                            window.location = url;
                        }
                    });
                return;
            }

            post({ action: 'codcrm_send_sms', order_id: orderId, text: text, context: st.context, lang: st.lang })
                .done(function (r) {
                    if (r.success) { afterSend(); return; }
                    $m.find('.codcrm-err').text((r.data && r.data.message) || i18n.error);
                    $b.prop('disabled', false);
                })
                .fail(function () { $m.find('.codcrm-err').text(i18n.error); $b.prop('disabled', false); });
        });
    }

    $(document).on('click', '.codcrm-msg', function (e) {
        e.preventDefault();
        openMessageModal($(this).data('order'));
    });

    $(document).on('click', '.codcrm-done', function (e) {
        e.preventDefault();
        post({ action: 'codcrm_complete_task', task_id: $(this).data('task') }).done(function () {
            location.reload();
        });
    });

    // ── Sélection + « Message groupé » (file d'attente côté serveur) ──
    var allMode = false;

    function selected() {
        return $('.codcrm-cb:checked').map(function () { return $(this).val(); }).get();
    }
    function refreshSel() {
        var n = allMode ? codcrm.bulkMax : selected().length;
        $('.codcrm-selcount').text(allMode ? '≤ ' + codcrm.bulkMax : n);
        $('.codcrm-bulk-open').prop('disabled', !n);
        var total = $('.codcrm-cb:not(:disabled)').length;
        $('.codcrm-sel-all').toggle(!allMode && total > 0 && selected().length === total);
    }
    $(document).on('change', '.codcrm-cb-all', function () {
        allMode = false;
        $('.codcrm-cb:not(:disabled)').prop('checked', this.checked);
        refreshSel();
    });
    $(document).on('change', '.codcrm-cb', function () { allMode = false; refreshSel(); });
    $(document).on('click', '.codcrm-sel-all', function (e) { e.preventDefault(); allMode = true; refreshSel(); });

    $(document).on('click', '.codcrm-bulk-open', function () {
        var ids = selected(), n = allMode ? codcrm.bulkMax : ids.length;
        var ctxOpts = '';
        $.each(codcrm.contexts, function (k, l) { ctxOpts += '<option value="' + esc(k) + '">' + esc(l) + '</option>'; });
        var $m = $(
            '<div class="codcrm-overlay"><div class="codcrm-modal codcrm-modal-msg">'
            + '<h2>' + esc(i18n.bulk_title) + ' — ' + (allMode ? '≤ ' + n : n) + ' ' + esc(i18n.bulk_count) + '</h2>'
            + '<p class="codcrm-msg-row"><label>' + esc(i18n.msg_channel) + ' <select class="b-channel">'
            + '<option value="sms"' + (codcrm.sms ? '' : ' disabled') + '>SMS</option><option value="whatsapp">WhatsApp</option></select></label> '
            + '<label>' + esc(i18n.msg_lang) + ' <select class="b-lang"><option value="auto">' + esc(i18n.bulk_auto) + '</option><option value="fr">FR</option><option value="ar">AR</option></select></label></p>'
            + '<p><label>' + esc(i18n.msg_context) + '<br><select class="b-ctx">' + ctxOpts + '</select></label></p>'
            + '<p class="description b-info"></p>'
            + '<p><strong>' + esc(i18n.bulk_preview) + '</strong></p>'
            + '<textarea class="b-prev" rows="5" readonly></textarea>'
            + '<p class="codcrm-err" style="color:#b32d2e"></p>'
            + '<p><button type="button" class="button button-primary b-go">' + esc(i18n.bulk_create) + '</button> '
            + '<button type="button" class="button codcrm-cancel">' + esc(i18n.cancel) + '</button></p>'
            + '</div></div>'
        );
        $('body').append($m);
        $m.on('click', '.codcrm-cancel', function () { $m.remove(); });
        if (!codcrm.sms) { $m.find('.b-channel').val('whatsapp'); }

        var seq = 0;
        function preview() {
            var ch = $m.find('.b-channel').val();
            $m.find('.b-info').text(ch === 'sms' ? i18n.bulk_sms_info : i18n.bulk_wa_info);
            if (!ids.length) { $m.find('.b-prev').val(''); return; }
            var my = ++seq;
            $m.find('.b-prev').val(i18n.msg_loading);
            $.post(codcrm.ajax, { action: 'codcrm_message_preview', nonce: codcrm.nonce, order_id: ids[0], context: $m.find('.b-ctx').val(), lang: $m.find('.b-lang').val(), channel: ch })
                .done(function (r) { if (my === seq) { $m.find('.b-prev').val(r && r.success ? r.data.text : ''); } });
        }
        $m.on('change', 'select', preview);
        preview();

        $m.on('click', '.b-go', function () {
            if (!window.confirm(i18n.bulk_confirm)) { return; }
            var $b = $(this).prop('disabled', true);
            var data = { action: 'codcrm_bulk_create', nonce: codcrm.nonce, channel: $m.find('.b-channel').val(), context: $m.find('.b-ctx').val(), lang: $m.find('.b-lang').val() };
            if (allMode) { data.all = 1; } else { data.ids = ids; }
            // « Tous les résultats » : le serveur rejoue le filtre de la page (même URL).
            var url = codcrm.ajax + (allMode ? location.search : '');
            $.post(url, data).done(function (r) {
                if (r && r.success) { location.href = r.data.url; }
                else { $m.find('.codcrm-err').text((r && r.data && r.data.message) || i18n.error); $b.prop('disabled', false); }
            }).fail(function () { $m.find('.codcrm-err').text(i18n.error); $b.prop('disabled', false); });
        });
    });

    // ── Historique des statuts au survol ──
    var histCache = {}, histTimer = null, $pop = null;
    function hidePop() { clearTimeout(histTimer); if ($pop) { $pop.remove(); $pop = null; } }
    function showPop($pill, html) {
        hidePop();
        $pop = $('<div class="codcrm-hist"></div>').html(html).appendTo('body');
        var r = $pill[0].getBoundingClientRect(), w = $pop.outerWidth(), h = $pop.outerHeight();
        var left = Math.max(8, Math.min(r.left + window.scrollX, window.scrollX + $(window).width() - w - 8));
        var top = r.bottom + window.scrollY + 6;
        if (r.bottom + h + 12 > $(window).height()) { top = Math.max(window.scrollY + 8, r.top + window.scrollY - h - 6); }
        $pop.css({ left: left, top: top }).on('mouseleave', hidePop);
    }
    function loadHist($pill) {
        var id = $pill.data('order');
        if (histCache[id]) { showPop($pill, histCache[id]); return; }
        post({ action: 'codcrm_status_history', order_id: id }).done(function (r) {
            if (r && r.success) { histCache[id] = r.data.html; if ($pill.is(':hover') || $pill.data('tap')) { showPop($pill, r.data.html); } }
        });
    }
    $(document).on('mouseenter', '.codcrm-status-pill', function () {
        var $p = $(this); clearTimeout(histTimer); histTimer = setTimeout(function () { loadHist($p); }, 250);
    }).on('mouseleave', '.codcrm-status-pill', function () {
        clearTimeout(histTimer);
        histTimer = setTimeout(function () { if ($pop && !$pop.is(':hover')) { hidePop(); } }, 200);
    }).on('click', '.codcrm-status-pill', function (e) { e.stopPropagation(); $(this).data('tap', 1); loadHist($(this)); })
      .on('click', function (e) { if ($pop && !$(e.target).closest('.codcrm-hist').length) { hidePop(); } });
    $(document).on('keydown', function (e) { if (e.key === 'Escape') { hidePop(); } });

    // ── Fenêtre « Modifier la commande » ──
    function money(n) { return (Math.round((parseFloat(n) || 0) * 1000) / 1000).toFixed(3).replace(/\.?0+$/, ''); }
    function opts(map, cur, empty) {
        var h = empty != null ? '<option value="">' + esc(empty) + '</option>' : '';
        $.each(map, function (k, l) { h += '<option value="' + esc(k) + '"' + (String(k) === String(cur) ? ' selected' : '') + '>' + esc(l) + '</option>'; });
        return h;
    }
    function openEditor(orderId) {
        var $m = $('<div class="codcrm-overlay"><div class="codcrm-modal codcrm-modal-edit"><p>' + esc(i18n.msg_loading) + '</p></div></div>').appendTo('body');
        var dirty = false;
        function close() { if (dirty && !window.confirm(i18n.ed_unsaved)) { return; } $m.remove(); }
        $m.on('mousedown', function (e) { if (e.target === $m[0]) { close(); } });
        $(document).one('keydown.codcrmed', function (e) { if (e.key === 'Escape' && $m.length) { close(); } });

        post({ action: 'codcrm_order_get', order_id: orderId }).done(function (r) {
            if (!r || !r.success) { $m.remove(); alert((r && r.data && r.data.message) || i18n.error); return; }
            build(r.data);
        }).fail(function () { $m.remove(); alert(i18n.error); });

        function build(d) {
            var L = d.locked, ro = L ? ' disabled' : '';
            var carriers = $.extend({}, d.carriers);
            var agentMap = {}; $.each(d.agents, function (_, a) { agentMap[a.id] = a.name; });
            var html = '<div class="ed-head"><h2>' + esc(i18n.ed_title) + esc(d.number) + (d.strikes ? ' <span class="codcrm-pill" style="background:#c0392b">⚠ ' + d.strikes + '</span>' : '') + '</h2>'
                + '<span><button type="button" class="button button-primary ed-save">' + esc(i18n.save) + '</button> <button type="button" class="button codcrm-cancel">' + esc(i18n.cancel) + '</button></span></div>'
                + (L ? '<div class="notice notice-warning inline"><p>' + esc(i18n.ed_locked) + '</p></div>' : '')
                + '<p class="codcrm-err" style="color:#b32d2e"></p>'
                + '<div class="ed-card"><h3>Détails de la commande</h3><div class="ed-grid">'
                + '<label>Statut<select class="f-status">' + opts(d.statuses, d.status) + '</select></label>'
                + '<label>Société de livraison<select class="f-carrier"' + ro + '>' + opts(carriers, d.carrier || '', d.carrier_now ? 'Automatique (' + d.carrier_now + ')' : 'Automatique') + '</select></label>'
                + '<label>Attribuée à<select class="f-agent"' + (d.manager ? '' : ' disabled') + '>' + opts(agentMap, d.agent || '', '— Non assignée —') + '</select></label>'
                + '<div class="ed-checks"><label><input type="checkbox" class="f-exchange"' + (d.exchange ? ' checked' : '') + ' /> Échange</label> '
                + '<label><input type="checkbox" class="f-reminder" /> Relance</label> <input type="datetime-local" class="f-reminder-at" style="display:none" /></div>'
                + '<label class="ed-wide">Note privée<textarea class="f-private" rows="2" maxlength="500" placeholder="Ajouter une note privée…"></textarea></label>'
                + '</div></div>'
                + '<div class="ed-card"><h3>Détails du client</h3><div class="ed-grid">'
                + '<label>Prénom<input class="f-first" value="' + esc(d.first) + '"' + ro + ' /></label>'
                + '<label>Nom<input class="f-last" value="' + esc(d.last) + '"' + ro + ' /></label>'
                + '<label>Téléphone<input class="f-phone" value="' + esc(d.phone) + '"' + ro + ' /></label>'
                + '<label>Téléphone 2<input class="f-phone2" value="' + esc(d.phone2) + '"' + ro + ' placeholder="Second numéro" /></label>'
                + '<label>Ville<select class="f-state"' + ro + '>' + opts(d.states, d.state, '—') + '</select></label>'
                + '<label>Email<input class="f-email" value="' + esc(d.email) + '"' + ro + ' /></label>'
                + '<label class="ed-wide">Adresse<input class="f-address" value="' + esc(d.address) + '"' + ro + ' /></label>'
                + '<label class="ed-wide">Note du client<textarea class="f-note" rows="2">' + esc(d.note) + '</textarea></label>'
                + '</div></div>'
                + '<div class="ed-card"><h3>Produits</h3>'
                + (L ? '' : '<div class="ed-search"><input type="search" class="f-psearch" placeholder="Rechercher un produit (2 lettres min.)…" /><div class="ed-results"></div></div>')
                + '<table class="ed-items widefat"><thead><tr><th></th><th>Produit</th><th>Qté</th><th>Prix unitaire</th><th>Total</th><th></th></tr></thead><tbody></tbody></table>'
                + '<div class="ed-sum"><label>Frais de livraison <input type="number" step="0.001" min="0" class="f-ship" value="' + esc(d.shipping) + '"' + ro + ' /></label>'
                + '<label>Code promo <input class="f-coupon" placeholder="' + esc(d.coupons.join(', ') || 'Saisir le code') + '"' + ro + ' /></label>'
                + '<p class="ed-total">Total : <strong class="f-total">0</strong> DT <small class="codcrm-muted">(recalculé à l\'enregistrement)</small></p></div></div>';
            $m.find('.codcrm-modal').html(html);

            var items = $.map(d.items, function (i) { return $.extend({}, i); });
            function draw() {
                var $b = $m.find('.ed-items tbody').empty(), sum = 0;
                $.each(items, function (idx, it) {
                    sum += it.qty * it.price;
                    $b.append('<tr data-i="' + idx + '"><td>' + (it.image ? '<img src="' + esc(it.image) + '" width="36" height="36" />' : '') + '</td><td>' + esc(it.name) + '</td>'
                        + '<td><input type="number" min="1" class="i-qty" value="' + it.qty + '"' + ro + ' /></td>'
                        + '<td><input type="number" step="0.001" min="0" class="i-price" value="' + money(it.price) + '"' + ro + ' /></td>'
                        + '<td>' + money(it.qty * it.price) + '</td><td>' + (L ? '' : '<a href="#" class="i-del" title="Supprimer">🗑</a>') + '</td></tr>');
                });
                $m.find('.f-total').text(money(sum + (parseFloat($m.find('.f-ship').val()) || 0)));
            }
            draw();

            $m.on('input change', 'input, select, textarea', function () { dirty = true; });
            $m.on('input', '.i-qty, .i-price', function () {
                var $tr = $(this).closest('tr'), it = items[$tr.data('i')];
                it.qty = Math.max(1, parseInt($tr.find('.i-qty').val(), 10) || 1); it.price = parseFloat($tr.find('.i-price').val()) || 0;
                $tr.find('td').eq(4).text(money(it.qty * it.price));
                var s = 0; $.each(items, function (_, x) { s += x.qty * x.price; });
                $m.find('.f-total').text(money(s + (parseFloat($m.find('.f-ship').val()) || 0)));
            });
            $m.on('input', '.f-ship', draw);
            $m.on('click', '.i-del', function (e) { e.preventDefault(); items.splice($(this).closest('tr').data('i'), 1); dirty = true; draw(); });
            $m.on('change', '.f-reminder', function () { $m.find('.f-reminder-at').toggle(this.checked); });

            var seq = 0, tm = null;
            $m.on('input', '.f-psearch', function () {
                var q = $.trim($(this).val()); clearTimeout(tm);
                if (q.length < 2) { $m.find('.ed-results').empty(); return; }
                tm = setTimeout(function () {
                    var my = ++seq;
                    post({ action: 'codcrm_product_search', q: q }).done(function (r) {
                        if (my !== seq || !r || !r.success) { return; }
                        var h = '';
                        $.each(r.data, function (i, p) { h += '<a href="#" class="ed-res" data-i="' + i + '">' + esc(p.name) + ' — ' + money(p.price) + ' DT</a>'; });
                        $m.find('.ed-results').html(h || '<em class="codcrm-muted">—</em>').data('res', r.data);
                    });
                }, 300);
            });
            $m.on('click', '.ed-res', function (e) {
                e.preventDefault();
                var p = $m.find('.ed-results').data('res')[$(this).data('i')];
                items.push({ item_id: 0, product_id: p.product_id, variation_id: p.variation_id, name: p.name, image: p.image, qty: 1, price: p.price });
                $m.find('.ed-results').empty(); $m.find('.f-psearch').val(''); dirty = true; draw();
            });

            $m.on('click', '.codcrm-cancel', close);
            $m.on('click', '.ed-save', function () {
                var $b = $(this).prop('disabled', true), v = function (c) { return $m.find(c).val(); };
                var data = {
                    status: v('.f-status'), carrier: v('.f-carrier'), agent: v('.f-agent'), exchange: $m.find('.f-exchange').is(':checked') ? 1 : 0,
                    reminder: $m.find('.f-reminder').is(':checked') ? 1 : 0, reminder_at: v('.f-reminder-at'), private_note: v('.f-private'),
                    first: v('.f-first'), last: v('.f-last'), phone: v('.f-phone'), phone2: v('.f-phone2'), state: v('.f-state'), email: v('.f-email'),
                    address: v('.f-address'), note: v('.f-note'), shipping: v('.f-ship'), coupon: v('.f-coupon'), items: items
                };
                post({ action: 'codcrm_order_save', order_id: orderId, data: JSON.stringify(data) }).done(function (r) {
                    if (r && r.success) {
                        delete histCache[orderId];
                        var $old = $('tr[data-order="' + orderId + '"]');
                        if (r.data.row && $old.length) { $old.replaceWith(r.data.row); }
                        dirty = false; $m.remove();
                    } else {
                        $m.find('.codcrm-err').text((r && r.data && r.data.message) || i18n.error); $b.prop('disabled', false);
                        $m.find('.codcrm-modal').scrollTop(0);
                    }
                }).fail(function () { $m.find('.codcrm-err').text(i18n.error); $b.prop('disabled', false); });
            });
        }
    }
    $(document).on('click', '.codcrm-edit', function (e) { e.preventDefault(); openEditor($(this).data('order')); });
})(jQuery);
