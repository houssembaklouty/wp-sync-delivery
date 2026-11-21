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

        var st = { channel: 'whatsapp', lang: 'fr', context: '', phone8: '', sms: false };

        function setSendLabel() {
            $m.find('.codcrm-send').text(st.channel === 'sms' ? i18n.msg_send_sms : i18n.msg_send_wa);
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
            var $ta = $m.find('.codcrm-msgtext').prop('disabled', true);
            post({ action: 'codcrm_message_preview', order_id: orderId, context: st.context, lang: st.lang, channel: st.channel })
                .done(function (r) {
                    if (r.success) {
                        $ta.val(r.data.text).attr('dir', st.lang === 'ar' ? 'rtl' : 'ltr');
                        $m.find('.codcrm-err').text('');
                    } else {
                        $m.find('.codcrm-err').text((r.data && r.data.message) || i18n.error);
                    }
                })
                .fail(function () { $m.find('.codcrm-err').text(i18n.error); })
                .always(function () { $ta.prop('disabled', false); updateCount(); $m.find('.codcrm-send').prop('disabled', false); });
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
                        if (win) { win.location = url; } else { window.location = url; }
                        location.reload();
                    });
                return;
            }

            post({ action: 'codcrm_send_sms', order_id: orderId, text: text, context: st.context, lang: st.lang })
                .done(function (r) {
                    if (r.success) { location.reload(); return; }
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
})(jQuery);
