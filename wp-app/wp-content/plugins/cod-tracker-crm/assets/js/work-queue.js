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
    // Historique client (badges de la colonne Client).
    var cliCache = {}, cliTimer = null;
    $(document).on('mouseenter', '.codcrm-client-hist', function () {
        var $b = $(this), id = $b.data('order'); clearTimeout(cliTimer);
        cliTimer = setTimeout(function () {
            if (cliCache[id]) { showPop($b, cliCache[id]); return; }
            post({ action: 'codcrm_client_history', order_id: id }).done(function (r) {
                if (r && r.success) { cliCache[id] = r.data.html; if ($b.is(':hover')) { showPop($b, r.data.html); } }
            });
        }, 250);
    }).on('mouseleave', '.codcrm-client-hist', function () {
        clearTimeout(cliTimer);
        cliTimer = setTimeout(function () { if ($pop && !$pop.is(':hover')) { hidePop(); } }, 200);
    });
    $(document).on('keydown', function (e) { if (e.key === 'Escape') { hidePop(); } });

    // ── Fenêtre « Modifier la commande » ──
    function money(n) { return (Math.round((parseFloat(n) || 0) * 1000) / 1000).toFixed(3).replace(/\.?0+$/, ''); }
    function opts(map, cur, empty) {
        var h = empty != null ? '<option value="">' + esc(empty) + '</option>' : '';
        $.each(map, function (k, l) { h += '<option value="' + esc(k) + '"' + (String(k) === String(cur) ? ' selected' : '') + '>' + esc(l) + '</option>'; });
        return h;
    }
    function phone8(v) {
        var d = String(v || '').replace(/\D+/g, '').replace(/^(00)?216/, '');
        return d.length === 8 ? d : '';
    }
    function toast(msg) {
        var $t = $('<div class="codcrm-toast" role="status"></div>').text(msg).appendTo('body');
        setTimeout(function () { $t.addClass('is-in'); }, 10);
        setTimeout(function () { $t.removeClass('is-in'); setTimeout(function () { $t.remove(); }, 300); }, 3200);
    }
    // Avertissement persistant (reste affiché jusqu'à fermeture) : le colis existe encore chez le transporteur.
    function notice(msg) {
        var $n = $('<div class="codcrm-notice-warn" role="alert"><strong>⚠ ' + esc(i18n.ed_not_deleted) + '</strong><p>' + esc(msg) + '</p><button type="button" class="ed-btn">OK</button></div>').appendTo('body');
        $n.on('click', 'button', function () { $n.remove(); });
    }
    var FIELD_LABELS = { first_name: 'prénom', last_name: 'nom', phone: 'téléphone', phone2: 'téléphone 2', state: 'ville', address: 'adresse', email: 'email',
        carrier: 'société de livraison', items: 'produits', shipping: 'frais', coupon: 'code promo', note_client: 'note client', exchange: 'échange',
        agent: 'agent', note_privee: 'note privée', relance: 'relance', status: 'statut' };
    var FIELD_SEL = { phone: '.f-phone', phone2: '.f-phone2', state: '.f-state', items: '.ed-items', coupon: '.f-coupon', status: '.f-status' };

    function field(label, inner, cls) {
        return '<div class="ed-field ' + (cls || '') + '"><label>' + esc(label) + '</label>' + inner + '<small class="ed-msg"></small></div>';
    }

    function openEditor(orderId, $trigger) {
        var $ov = $('<div class="codcrm-overlay codcrm-ed-overlay"><div class="codcrm-ed" role="dialog" aria-modal="true" aria-labelledby="codcrm-ed-title">'
            + '<div class="ed-skel"><div class="sk sk-h"></div><div class="ed-body"><div class="ed-main"><div class="sk sk-card"></div><div class="sk sk-card"></div></div>'
            + '<div class="ed-side"><div class="sk sk-card"></div><div class="sk sk-small"></div></div></div></div></div></div>').appendTo('body');
        var $ed = $ov.find('.codcrm-ed'), dirty = false, d = null, items = [], removed = null;
        $('body').addClass('codcrm-noscroll');
        window.codcrmEditingOrder = orderId;

        function setDirty(v) {
            dirty = v;
            $ed.toggleClass('is-dirty', v).find('.ed-save').prop('disabled', !v);
        }
        function close(force) {
            if (!force && dirty && !window.confirm(i18n.ed_unsaved)) { return; }
            $(document).off('.codcrmed');
            $ov.remove();
            $('body').removeClass('codcrm-noscroll');
            window.codcrmEditingOrder = 0;
            if ($trigger && $trigger.length && document.body.contains($trigger[0])) { $trigger.trigger('focus'); }
        }
        $ov.on('mousedown', function (e) { if (e.target === $ov[0]) { close(); } });
        $(document).on('keydown.codcrmed', function (e) {
            if (!$ov.closest('body').length) { return; }
            if ($('.codcrm-overlay').last()[0] !== $ov[0]) { return; } // une autre fenêtre (appel) est au-dessus.
            if (e.key === 'Escape') { e.preventDefault(); close(); return; }
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); if (dirty) { save(); } return; }
            if (e.key === 'Tab') { // piège du focus
                var $f = $ed.find('a[href], button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled)').filter(':visible');
                if (!$f.length) { return; }
                var first = $f[0], last = $f[$f.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        });

        post({ action: 'codcrm_order_get', order_id: orderId }).done(function (r) {
            if (!r || !r.success) { close(true); alert((r && r.data && r.data.message) || i18n.error); return; }
            d = r.data; items = $.map(d.items, function (i) { return $.extend({}, i); });
            build();
        }).fail(function () { close(true); alert(i18n.error); });

        function statusPill(st) {
            return '<span class="ed-pill" style="background:' + esc(d.status_colors[st] || '#64748b') + '">' + esc(d.statuses[st] || st) + '</span>';
        }
        function carrierLogo(slug) {
            var u = d.logos[slug || d.carrier_now];
            return u ? '<img src="' + esc(u) + '" alt="" />' : '';
        }

        function build() {
            var L = d.locked, ro = L ? ' disabled' : '', lock = L ? ' <span class="ed-lock" title="' + esc(i18n.ed_locked) + '">🔒</span>' : '';
            var agentMap = {}; $.each(d.agents, function (_, a) { agentMap[a.id] = a.name; });
            var p8 = phone8(d.phone);
            var h = ''
                + '<header class="ed-top"><div class="ed-title"><h2 id="codcrm-ed-title">Commande n°' + esc(d.number) + '</h2> <span class="ed-cur-pill">' + statusPill(d.status) + '</span>' + '<span class="ed-htotal"><span class="s-total">0</span> DT</span>'
                + '<div class="ed-sub">' + esc($.trim(d.first + ' ' + d.last)) + (d.phone ? ' · ' + esc(d.phone) : '') + (d.strikes ? ' · <span class="ed-strike">⚠ ' + d.strikes + ' signalement(s)</span>' : '') + '</div></div>'
                + '<div class="ed-top-actions"><span class="ed-dirty">● Modifications non enregistrées</span>'
                + '<button type="button" class="ed-btn ed-primary ed-save" disabled title="Ctrl + S"><span class="ed-spin"></span>💾 Enregistrer</button>'
                + '<button type="button" class="ed-x" aria-label="Fermer">×</button></div></header>'
                + '<div class="ed-alert ed-err" role="alert" hidden></div>'
                + (L ? '<div class="ed-alert ed-warn">🔒 ' + esc(i18n.ed_locked) + '</div>' : '')
                + '<div class="ed-body"><div class="ed-main">'
                // Client
                + '<section class="ed-card' + (L ? ' is-locked' : '') + '"><h3>Détails du client' + lock + '</h3><div class="ed-grid">'
                + field('Prénom', '<input class="f-first" value="' + esc(d.first) + '"' + ro + ' />')
                + field('Nom', '<input class="f-last" value="' + esc(d.last) + '"' + ro + ' />')
                + field('Téléphone', '<div class="ed-inline"><input type="tel" inputmode="tel" class="f-phone" value="' + esc(d.phone) + '"' + ro + ' />'
                    + (p8 ? '<a class="ed-icon codcrm-call" href="tel:+216' + esc(p8) + '" data-order="' + d.id + '" title="Appeler (résultat tracé)">📞</a>' : '') + '</div>', 'f-wrap-phone')
                + field('Téléphone 2', '<input type="tel" inputmode="tel" class="f-phone2" value="' + esc(d.phone2) + '" placeholder="Optionnel"' + ro + ' />', 'f-wrap-phone2')
                + field('Ville (gouvernorat)', '<select class="f-state"' + ro + '>' + opts(d.states, d.state, '— Choisir —') + '</select>', 'f-wrap-state')
                + field('Email', '<input type="email" class="f-email" value="' + esc(d.email) + '" placeholder="Optionnel"' + ro + ' />')
                + field('Adresse', '<input class="f-address" value="' + esc(d.address) + '"' + ro + ' />', 'ed-wide')
                + field('Note du client', '<textarea class="f-note" rows="2" placeholder="Instructions de livraison…">' + esc(d.note) + '</textarea>', 'ed-wide')
                + '</div></section>'
                // Produits
                + '<section class="ed-card' + (L ? ' is-locked' : '') + '"><h3>Produits' + lock + ' <span class="ed-count"></span></h3>'
                + (L ? '' : '<div class="ed-search"><span class="ed-search-ico">🔍</span><input type="search" class="f-psearch" placeholder="Ajouter un produit : tapez son nom…" autocomplete="off" role="combobox" aria-expanded="false" /><div class="ed-results" role="listbox"></div></div>')
                + '<div class="ed-undo" hidden></div><div class="ed-items"></div></section>'
                + '</div><aside class="ed-side">'
                // Commande
                + '<section class="ed-card"><h3>Commande</h3>'
                + field('Statut', '<div class="ed-inline"><span class="ed-dot"></span><select class="f-status">' + opts(d.statuses, d.status) + '</select></div>', 'f-wrap-status')
                + field('Société de livraison', '<div class="ed-inline ed-carrier"><span class="ed-logo">' + carrierLogo(d.carrier) + '</span><select class="f-carrier"' + ro + '>'
                    + opts(d.carriers, d.carrier || '', d.carrier_now ? 'Automatique (' + (d.carriers[d.carrier_now] || d.carrier_now) + ')' : 'Automatique') + '</select></div>')
                + (d.tracking ? '<div class="ed-field"><label>Code à barre du colis</label><div class="ed-inline ed-track"><code class="ed-code">' + esc(d.tracking) + '</code><button type="button" class="ed-icon ed-copy" title="Copier" aria-label="Copier le code">⧉</button>'
                    + (d.can_unsync ? '<button type="button" class="ed-icon ed-unsync" title="Supprimer le colis chez le transporteur" aria-label="Supprimer le colis"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg></button>' : '') + '</div></div>' : '')
                + field('Attribuée à', '<select class="f-agent"' + (d.manager ? '' : ' disabled title="Réservé aux responsables"') + '>' + opts(agentMap, d.agent || '', '— Non assignée —') + '</select>')
                + '</section>'
                // Options
                + '<section class="ed-card"><h3>Options</h3>'
                + '<label class="ed-switch"><input type="checkbox" class="f-exchange"' + (d.exchange ? ' checked' : '') + ' /><span></span> Commande d\'échange</label>'
                + '<label class="ed-switch"><input type="checkbox" class="f-reminder" /><span></span> Relance (rappel agent)</label>'
                + '<div class="ed-field f-reminder-box" hidden><label>Rappeler le</label><input type="datetime-local" class="f-reminder-at" /></div>'
                + field('Note privée', '<textarea class="f-private" rows="2" maxlength="500" placeholder="Visible uniquement par l\'équipe…"></textarea>')
                + '</section>'
                // Résumé
                + '<section class="ed-card ed-summary' + (L ? ' is-locked' : '') + '"><h3>Résumé' + lock + '</h3>'
                + '<div class="ed-row"><span>Sous-total</span><strong class="s-sub">0</strong></div>'
                + '<div class="ed-row"><label for="codcrm-ship">Frais de livraison</label><input id="codcrm-ship" type="number" step="0.001" min="0" class="f-ship" value="' + esc(d.shipping) + '"' + ro + ' /></div>'
                + (d.discount ? '<div class="ed-row"><span>Remise (' + esc(d.coupons.join(', ')) + ')</span><strong>− ' + money(d.discount) + '</strong></div>' : '')
                + field('Code promo', '<div class="ed-inline"><input class="f-coupon" placeholder="SAISIR LE CODE"' + ro + ' /></div>', 'f-wrap-coupon')
                + '<div class="ed-total"><span>Total</span><strong><span class="s-total">0</span> DT</strong></div>'
                + '<small class="ed-hint">Total définitif recalculé à l\'enregistrement.</small>'
                + '</section></aside></div>';
            $ed.html(h);
            renderItems();
            refreshStatus();
            $ed.find('input:not(:disabled), select:not(:disabled)').first().trigger('focus');
            bind();
        }

        function refreshStatus() {
            var st = $ed.find('.f-status').val();
            $ed.find('.ed-dot').css('background', d.status_colors[st] || '#64748b');
        }

        function renderItems() {
            var L = d.locked, ro = L ? ' disabled' : '', h = '';
            if (!items.length) { h = '<p class="ed-empty">Aucun produit — ajoutez-en un avec la recherche.</p>'; }
            $.each(items, function (i, it) {
                h += '<div class="ed-item" data-i="' + i + '">'
                    + (it.image ? '<img src="' + esc(it.image) + '" alt="" />' : '<span class="ed-noimg">📦</span>')
                    + '<div class="ed-item-info"><strong>' + esc(it.name) + '</strong>' + (it.attrs ? '<small>' + esc(it.attrs) + '</small>' : '') + '</div>'
                    + '<div class="ed-qty"><button type="button" class="q-minus" aria-label="Moins"' + ro + '>−</button><input type="number" min="1" class="i-qty" value="' + it.qty + '" aria-label="Quantité"' + ro + ' /><button type="button" class="q-plus" aria-label="Plus"' + ro + '>+</button></div>'
                    + '<div class="ed-price"><input type="number" step="0.001" min="0" class="i-price" value="' + money(it.price) + '" aria-label="Prix unitaire"' + ro + ' /><span class="ed-unit">DT</span></div>'
                    + '<strong class="ed-line">' + money(it.qty * it.price) + ' DT</strong>'
                    + (L ? '' : '<button type="button" class="ed-del" title="Retirer" aria-label="Retirer"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg></button>')
                    + '</div>';
            });
            $ed.find('.ed-items').html(h);
            $ed.find('.ed-count').text(items.length ? '(' + items.length + ')' : '');
            summary();
        }

        function summary() {
            var sub = 0;
            $.each(items, function (_, x) { sub += x.qty * x.price; });
            var ship = parseFloat($ed.find('.f-ship').val()) || 0;
            $ed.find('.s-sub').text(money(sub) + ' DT');
            $ed.find('.s-total').text(money(Math.max(0, sub + ship - (d.discount || 0))));
        }

        function fieldError($el, msg) {
            var $w = $el.closest('.ed-field, .ed-card');
            $w.addClass('ed-invalid').find('.ed-msg').first().text(msg || '');
        }
        function clearError($el) { $el.closest('.ed-field, .ed-card').removeClass('ed-invalid').find('.ed-msg').first().text(''); }

        function validatePhone($in, required) {
            var v = $.trim($in.val());
            if ((!required && !v) || phone8(v)) { clearError($in); return true; }
            fieldError($in, 'Numéro tunisien de 8 chiffres attendu.');
            return false;
        }

        function bind() {
            $ed.on('input change', 'input, select, textarea', function () { if (!$(this).is('.f-psearch')) { setDirty(true); } });
            $ed.on('click', '.ed-x', function () { close(); });
            $ed.on('click', '.ed-unsync', function () {
                if (!window.confirm(i18n.ed_unsync_confirm)) { return; }
                var $b = $(this).prop('disabled', true);
                post({ action: 'codcrm_order_unsync', order_id: orderId }).done(function (r) {
                    if (r && r.success) {
                        delete histCache[orderId];
                        var $old = $('tr[data-order="' + orderId + '"]');
                        if (r.data.row && $old.length) { var $new = $(r.data.row).addClass('codcrm-flash'); $old.replaceWith($new); $trigger = $new.find('.codcrm-edit'); }
                        toast('✓ ' + (r.data.message || i18n.ed_unsync_done));
                        close(true); openEditor(orderId, $trigger); // rouvre : la commande est de nouveau modifiable.
                        if (r.data.warning) { notice(r.data.warning); } // colis NON supprimé chez le transporteur.
                    } else {
                        $b.prop('disabled', false);
                        $ed.find('.ed-err').text((r && r.data && r.data.message) || i18n.error).prop('hidden', false); $ed.scrollTop(0);
                    }
                }).fail(function () { $b.prop('disabled', false); $ed.find('.ed-err').text(i18n.error).prop('hidden', false); });
            });
            $ed.on('click', '.ed-copy', function () {
                var $b = $(this), t = d.tracking;
                function done() { $b.text('✓'); setTimeout(function () { $b.text('⧉'); }, 1500); }
                if (navigator.clipboard) { navigator.clipboard.writeText(t).then(done); }
                else { var $i = $('<input>').val(t).appendTo('body').select(); document.execCommand('copy'); $i.remove(); done(); }
            });
            $ed.on('change', '.f-status', refreshStatus);
            $ed.on('change', '.f-carrier', function () { $ed.find('.ed-logo').html(carrierLogo($(this).val())); });
            $ed.on('change', '.f-reminder', function () {
                var on = this.checked, $at = $ed.find('.f-reminder-at');
                $ed.find('.f-reminder-box').prop('hidden', !on);
                if (on && !$at.val()) {
                    var t = new Date(Date.now() + 2 * 3600e3 - new Date().getTimezoneOffset() * 60e3);
                    $at.val(t.toISOString().slice(0, 16));
                }
            });
            $ed.on('blur', '.f-phone', function () { validatePhone($(this), true); });
            $ed.on('blur', '.f-phone2', function () { validatePhone($(this), false); });
            $ed.on('input', '.f-phone, .f-phone2', function () { if ($(this).closest('.ed-invalid').length) { validatePhone($(this), $(this).is('.f-phone')); } });
            $ed.on('input', '.f-ship', summary);

            // Quantité / prix.
            function itemOf(el) { return items[$(el).closest('.ed-item').data('i')]; }
            function refreshLine(el) {
                var it = itemOf(el), $r = $(el).closest('.ed-item');
                $r.find('.ed-line').text(money(it.qty * it.price) + ' DT');
                summary();
            }
            $ed.on('click', '.q-minus, .q-plus', function () {
                var it = itemOf(this);
                it.qty = Math.max(1, it.qty + ($(this).is('.q-plus') ? 1 : -1));
                $(this).siblings('.i-qty').val(it.qty);
                setDirty(true); refreshLine(this);
            });
            $ed.on('input', '.i-qty', function () { itemOf(this).qty = Math.max(1, parseInt($(this).val(), 10) || 1); refreshLine(this); });
            $ed.on('input', '.i-price', function () { itemOf(this).price = Math.max(0, parseFloat($(this).val()) || 0); refreshLine(this); });
            $ed.on('click', '.ed-del', function () {
                var i = $(this).closest('.ed-item').data('i');
                removed = { i: i, item: items[i] };
                items.splice(i, 1);
                setDirty(true); renderItems();
                $ed.find('.ed-undo').prop('hidden', false).html('« ' + esc(removed.item.name) + ' » retiré. <a href="#" class="ed-undo-a">Annuler</a>');
            });
            $ed.on('click', '.ed-undo-a', function (e) {
                e.preventDefault();
                if (removed) { items.splice(removed.i, 0, removed.item); removed = null; renderItems(); }
                $ed.find('.ed-undo').prop('hidden', true);
            });

            // Recherche produit (clavier ↑ ↓ Entrée Échap).
            var seq = 0, tm = null, res = [], active = -1;
            var $res = $ed.find('.ed-results'), $q = $ed.find('.f-psearch');
            function closeRes() { $res.empty().removeClass('is-open'); $q.attr('aria-expanded', 'false'); res = []; active = -1; }
            function highlight() { $res.find('.ed-res').removeClass('is-active').eq(active).addClass('is-active').each(function () { this.scrollIntoView({ block: 'nearest' }); }); }
            function pick(i) {
                var p = res[i];
                if (!p) { return; }
                var same = $.grep(items, function (x) { return x.product_id === p.product_id && x.variation_id === p.variation_id; })[0];
                if (same) { same.qty++; } else { items.push({ item_id: 0, product_id: p.product_id, variation_id: p.variation_id, name: p.name, attrs: p.attrs, image: p.image, qty: 1, price: p.price }); }
                closeRes(); $q.val('').trigger('focus');
                setDirty(true); renderItems(); clearError($ed.find('.ed-items'));
            }
            $q.on('input', function () {
                var q = $.trim($(this).val()); clearTimeout(tm);
                if (q.length < 2) { closeRes(); return; }
                $res.addClass('is-open').html('<em class="ed-res-msg">Recherche…</em>');
                tm = setTimeout(function () {
                    var my = ++seq;
                    post({ action: 'codcrm_product_search', q: q }).done(function (r) {
                        if (my !== seq) { return; }
                        res = (r && r.success) ? r.data : []; active = res.length ? 0 : -1;
                        var h = '';
                        $.each(res, function (i, p) {
                            h += '<a href="#" class="ed-res" role="option" data-i="' + i + '">' + (p.image ? '<img src="' + esc(p.image) + '" alt="" />' : '<span class="ed-noimg">📦</span>')
                                + '<span class="ed-res-t"><strong>' + esc(p.name) + '</strong><small>' + esc(p.attrs || '') + (p.stock ? ' · ' + esc(p.stock) : '') + '</small></span>'
                                + '<b>' + money(p.price) + ' DT</b></a>';
                        });
                        $res.html(h || '<em class="ed-res-msg">Aucun résultat.</em>');
                        $q.attr('aria-expanded', 'true'); highlight();
                    });
                }, 250);
            }).on('keydown', function (e) {
                if (!res.length) { return; }
                if (e.key === 'ArrowDown') { e.preventDefault(); active = (active + 1) % res.length; highlight(); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); active = (active - 1 + res.length) % res.length; highlight(); }
                else if (e.key === 'Enter') { e.preventDefault(); pick(active); }
                else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeRes(); }
            });
            $ed.on('mousedown', '.ed-res', function (e) { e.preventDefault(); pick($(this).data('i')); });
            $ed.on('mousedown', function (e) { if (!$(e.target).closest('.ed-search').length) { closeRes(); } });

            $ed.on('click', '.ed-save', save);
        }

        function save() {
            var $b = $ed.find('.ed-save');
            if ($b.hasClass('is-busy')) { return; }
            $ed.find('.ed-err').prop('hidden', true);
            if (!d.locked) {
                var okP = validatePhone($ed.find('.f-phone'), true) & validatePhone($ed.find('.f-phone2'), false);
                if (!items.length) { fieldError($ed.find('.ed-items'), 'Ajoutez au moins un produit.'); }
                var $bad = $ed.find('.ed-invalid').first();
                if (!okP || !items.length) { $bad[0].scrollIntoView({ behavior: 'smooth', block: 'center' }); $bad.find('input, select').first().trigger('focus'); return; }
            }
            var v = function (c) { return $ed.find(c).val(); };
            var data = {
                status: v('.f-status'), carrier: v('.f-carrier'), exchange: $ed.find('.f-exchange').is(':checked') ? 1 : 0,
                reminder: $ed.find('.f-reminder').is(':checked') ? 1 : 0, reminder_at: v('.f-reminder-at'), private_note: v('.f-private'),
                first: v('.f-first'), last: v('.f-last'), phone: v('.f-phone'), phone2: v('.f-phone2'), state: v('.f-state'), email: v('.f-email'),
                address: v('.f-address'), note: v('.f-note'), shipping: v('.f-ship'), coupon: v('.f-coupon'), items: items
            };
            // L'agent n'est envoyé que s'il a réellement été changé (jamais de désattribution involontaire).
            if (String(v('.f-agent') || '') !== String(d.agent || '')) { data.agent = v('.f-agent') || 0; }
            $b.addClass('is-busy').prop('disabled', true);
            post({ action: 'codcrm_order_save', order_id: orderId, data: JSON.stringify(data) }).done(function (r) {
                $b.removeClass('is-busy');
                if (r && r.success) {
                    delete histCache[orderId];
                    var $old = $('tr[data-order="' + orderId + '"]');
                    if (r.data.row && $old.length) {
                        var $new = $(r.data.row).addClass('codcrm-flash');
                        $old.replaceWith($new);
                        $trigger = $new.find('.codcrm-edit');
                    }
                    var ch = $.map(r.data.changed || [], function (k) { return FIELD_LABELS[k] || k; });
                    toast('✓ Commande n°' + d.number + ' enregistrée' + (ch.length ? ' — ' + ch.join(', ') : ' (aucun changement)'));
                    setDirty(false); close(true);
                    return;
                }
                $b.prop('disabled', false);
                var msg = (r && r.data && r.data.message) || i18n.error, f = r && r.data && r.data.field;
                if (f && FIELD_SEL[f] && $ed.find(FIELD_SEL[f]).length) {
                    var $f = $ed.find(FIELD_SEL[f]);
                    fieldError($f, msg);
                    $f[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    $f.filter('input, select').trigger('focus');
                } else {
                    $ed.find('.ed-err').text(msg).prop('hidden', false);
                    $ed.scrollTop(0);
                }
            }).fail(function () {
                $b.removeClass('is-busy').prop('disabled', false);
                $ed.find('.ed-err').text(i18n.error).prop('hidden', false);
            });
        }
    }
    $(document).on('click', '.codcrm-edit', function (e) { e.preventDefault(); openEditor($(this).data('order'), $(this)); });

    // ── Mes suivis en direct : lignes mises à jour / nouvelles / retirées sans recharger la page ──
    (function () {
        var L = codcrm.live;
        var $tbody = $('table.codcrm-table tbody');
        if (!L || !$tbody.length) { return; }

        var now = L.now, timer = null, fails = 0, running = false, first = true;
        var muted = false, NOTIF = 'codcrm_notified';
        try { muted = window.localStorage.getItem('codcrm_mute') === '1'; } catch (e) { /* rien */ }

        // Barre d'état : « ● En direct » + bouton son.
        var $st = $('<span class="codcrm-live" role="status"><i class="dot"></i><span class="lbl"></span></span>');
        var $snd = $('<button type="button" class="codcrm-live-snd" hidden></button>');
        $('.codcrm-bulkbar').first().append($st).append($snd);
        function showState(on) { $st.toggleClass('is-off', !on).find('.lbl').text(on ? i18n.live_on : i18n.live_off); }
        function sndBtn() {
            if (L.sound === 'none') { $snd.prop('hidden', true); return; }
            var ready = window.codcrmSound && window.codcrmSound.ready();
            $snd.prop('hidden', false).toggleClass('is-warn', !ready && !muted)
                .text(muted ? '🔕' : (ready ? '🔔' : '🔔 ' + i18n.live_enable_sound))
                .attr('title', ready || muted ? i18n.live_mute : i18n.live_enable_sound);
        }
        $snd.on('click', function () {
            if (muted) { muted = false; } else if (window.codcrmSound.ready()) { muted = true; }
            try { window.localStorage.setItem('codcrm_mute', muted ? '1' : '0'); } catch (e) { /* rien */ }
            window.codcrmSound.unlock().then(sndBtn); sndBtn();
        });
        // Le navigateur n'autorise le son qu'après un geste de l'utilisateur.
        $(document).one('click keydown', function () { window.codcrmSound.unlock().then(sndBtn); });
        sndBtn();

        // Commandes déjà annoncées (partagé entre onglets) : un son par commande, une seule fois.
        function notified() { try { return JSON.parse(window.localStorage.getItem(NOTIF) || '{}'); } catch (e) { return {}; } }
        function markNotified(id) {
            var n = notified(), t = Date.now();
            n[id] = t;
            $.each(n, function (k, v) { if (t - v > 86400000) { delete n[k]; } });
            try { window.localStorage.setItem(NOTIF, JSON.stringify(n)); } catch (e) { /* rien */ }
        }
        function announce(items) {
            var n = notified(), fresh = $.grep(items, function (it) { return !n[it.id]; });
            if (!fresh.length) { return; }
            $.each(fresh, function (_, it) { markNotified(it.id); });
            if (first) { return; } // premier passage : pas de son pour ce qui existait déjà.
            var abandoned = $.grep(fresh, function (it) { return it.meta && it.meta.abandoned; }).length === fresh.length;
            var it0 = fresh[0], label = (it0.meta && it0.meta.abandoned) ? i18n.live_abandoned : i18n.live_new;
            toast('🔔 ' + label + ' #' + (it0.meta.number || it0.id) + (it0.meta.phone ? ' — ' + it0.meta.phone : '') + (fresh.length > 1 ? ' (+' + (fresh.length - 1) + ')' : ''));
            if (L.sound !== 'none' && !muted && (L.soundAbandoned || !abandoned)) { window.codcrmSound.play(L.sound, L.volume); }
            sndBtn();
        }

        function rowIds() { return $tbody.find('tr[data-order]').map(function () { return $(this).data('order'); }).get(); }
        function insertRow($row, id) {
            var placed = false;
            $tbody.find('tr[data-order]').each(function () {
                if (parseInt($(this).data('order'), 10) < id) { $row.insertBefore(this); placed = true; return false; }
            });
            if (!placed) { $tbody.append($row); }
        }
        function keepState($old, $new) {
            if ($old.find('.codcrm-cb').is(':checked')) { $new.find('.codcrm-cb:not(:disabled)').prop('checked', true); }
        }
        function apply(d) {
            var changed = false;
            $.each(d.removed || [], function (_, id) {
                $tbody.find('tr[data-order="' + id + '"]').addClass('codcrm-fade').delay(400).queue(function () { $(this).remove(); });
                changed = true;
            });
            $.each(d.updated || {}, function (id, html) {
                if (parseInt(window.codcrmEditingOrder, 10) === parseInt(id, 10)) { return; } // en cours de modification.
                var $old = $tbody.find('tr[data-order="' + id + '"]'), $new = $(html).addClass('codcrm-flash');
                if ($old.length) { keepState($old, $new); $old.replaceWith($new); delete histCache[id]; changed = true; }
            });
            var perPage = L.perPage || 25, addedNow = [];
            $.each(d.added || [], function (_, a) {
                if ($tbody.find('tr[data-order="' + a.id + '"]').length) { return; }
                var $row = $(a.html).addClass('codcrm-new');
                $tbody.find('tr:not([data-order])').remove(); // message « aucun colis ».
                insertRow($row, a.id); addedNow.push(a); changed = true;
            });
            while ($tbody.find('tr[data-order]').length > perPage && addedNow.length) { $tbody.find('tr[data-order]').last().remove(); }
            if (addedNow.length) { announce(addedNow); }
            if (typeof d.total === 'number' && d.total > 0) { $('.displaying-num').text(d.total + ' ' + (d.total > 1 ? 'éléments' : 'élément')); }
            return changed;
        }
        function qs() {
            var u = new URLSearchParams(window.location.search);
            return { stage: u.get('stage') || 'actif', flt: u.get('flt') || '', agent: u.get('agent') || 'all', paged: parseInt(u.get('paged'), 10) || 1 };
        }
        function tick() {
            if (running || document.visibilityState === 'hidden') { schedule(); return; }
            running = true;
            var f = qs();
            $.post(codcrm.ajax, { action: 'codcrm_queue_live', nonce: codcrm.nonce, since_utc: now.utc, since_db: now.db, ids: rowIds(), stage: f.stage, flt: f.flt, agent: f.agent, paged: f.paged })
                .done(function (r) {
                    if (r && r.success) { fails = 0; now = r.data.now; apply(r.data); first = false; showState(true); }
                    else { fails++; showState(false); }
                })
                .fail(function () { fails++; showState(false); })
                .always(function () { running = false; schedule(); });
        }
        function schedule() {
            clearTimeout(timer);
            timer = setTimeout(tick, Math.min(60000, L.interval * (1 + Math.min(fails, 6))));
        }
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') { clearTimeout(timer); showState(true); tick(); } else { showState(false); }
        });
        showState(true);
        first = true;
        // Premier passage après le chargement : on mémorise ce qui est déjà affiché (pas de son), puis la boucle démarre.
        $.each(rowIds(), function (_, id) { var n = notified(); if (!n[id]) { markNotified(id); } });
        first = false;
        schedule();
    })();
})(jQuery);
