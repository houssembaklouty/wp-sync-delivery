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
})(jQuery);
