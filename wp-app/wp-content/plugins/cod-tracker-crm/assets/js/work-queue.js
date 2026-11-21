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

    // WhatsApp : trace le contact puis ouvre wa.me.
    $(document).on('click', '.codcrm-wa', function (e) {
        e.preventDefault();
        var url = $(this).data('url');
        var win = window.open('', '_blank'); // ouvert dans le clic pour éviter le blocage de pop-up.
        post({ action: 'codcrm_log_contact', order_id: $(this).data('order'), channel: 'whatsapp' })
            .always(function () {
                if (win) { win.location = url; } else { window.location = url; }
            });
    });

    // SMS manuel : choix d'un modèle, message modifiable, envoi tracé.
    function openSmsModal(orderId) {
        var opts = '';
        $.each(codcrm.smsTpl || [], function (i, t) { opts += '<option value="' + i + '">' + esc(t.label) + '</option>'; });
        var $m = $(
            '<div class="codcrm-overlay"><div class="codcrm-modal">'
            + '<h2>' + esc(i18n.sms_title) + ' — #' + esc(orderId) + '</h2>'
            + '<p><label>' + esc(i18n.sms_tpl) + '<br><select class="codcrm-tpl">' + opts + '</select></label></p>'
            + '<p><label>' + esc(i18n.sms_text) + '<br><textarea class="codcrm-smstext" rows="4" maxlength="480"></textarea></label></p>'
            + '<p class="codcrm-err" style="color:#b32d2e"></p>'
            + '<p><button type="button" class="button button-primary codcrm-send">' + esc(i18n.sms_send) + '</button> '
            + '<button type="button" class="button codcrm-cancel">' + esc(i18n.cancel) + '</button></p>'
            + '</div></div>'
        );
        $('body').append($m);
        function fill() { var t = (codcrm.smsTpl || [])[$m.find('.codcrm-tpl').val()]; $m.find('.codcrm-smstext').val(t ? t.text : ''); }
        fill();
        $m.on('change', '.codcrm-tpl', fill);
        $m.on('click', '.codcrm-cancel', function () { $m.remove(); });
        $m.on('click', '.codcrm-send', function () {
            var $b = $(this).prop('disabled', true);
            post({ action: 'codcrm_send_sms', order_id: orderId, text: $m.find('.codcrm-smstext').val() })
                .done(function (r) {
                    if (r.success) { location.reload(); return; }
                    $m.find('.codcrm-err').text((r.data && r.data.message) || i18n.error);
                    $b.prop('disabled', false);
                })
                .fail(function () { $m.find('.codcrm-err').text(i18n.error); $b.prop('disabled', false); });
        });
    }

    $(document).on('click', '.codcrm-sms', function (e) {
        e.preventDefault();
        openSmsModal($(this).data('order'));
    });

    $(document).on('click', '.codcrm-done', function (e) {
        e.preventDefault();
        post({ action: 'codcrm_complete_task', task_id: $(this).data('task') }).done(function () {
            location.reload();
        });
    });
})(jQuery);
