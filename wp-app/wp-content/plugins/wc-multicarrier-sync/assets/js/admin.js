/**
 * WC MultiCarrier Sync — Admin JS
 * Gère les actions AJAX : sync individuel, retry, voir erreur
 */
/* global wcmcsAdmin, jQuery */
(function ($) {
    'use strict';

    // ─────────────────────────────────────────────
    //  Utilitaires
    // ─────────────────────────────────────────────

    function showResult($container, type, message) {
        var color = type === 'success' ? '#28a745' : '#dc3545';
        $container.html(
            '<div style="padding:8px;background:' + color + ';color:#fff;border-radius:4px;font-size:12px;margin-top:4px">' +
            message +
            '</div>'
        );
        setTimeout(function () {
            $container.fadeOut(500, function () {
                $(this).html('').show();
            });
        }, 5000);
    }

    // ─────────────────────────────────────────────
    //  Sync individuel (metabox bouton)
    // ─────────────────────────────────────────────

    $(document).on('click', '.wcmcs-btn-sync', function (e) {
        e.preventDefault();
        var $btn      = $(this);
        var orderId   = $btn.data('order-id');
        var $result   = $btn.closest('.wcmcs-metabox').find('.wcmcs-ajax-result');

        $btn.prop('disabled', true).text(wcmcsAdmin.i18n.syncing);

        $.post(wcmcsAdmin.ajaxUrl, {
            action:   'wcmcs_sync_single',
            order_id: orderId,
            nonce:    wcmcsAdmin.nonce
        }, function (response) {
            if (response.success) {
                var msg = response.data.message;
                if (response.data.tracking_id) {
                    msg += ' [' + response.data.tracking_id + ']';
                }
                showResult($result, 'success', '✓ ' + msg);
                // Recharger après 2s pour refléter les changements
                setTimeout(function () { location.reload(); }, 2000);
            } else {
                var errMsg = response.data ? response.data.message : wcmcsAdmin.i18n.error;
                showResult($result, 'error', '✗ ' + errMsg);
            }
        }).fail(function () {
            showResult($result, 'error', '✗ Erreur réseau.');
        }).always(function () {
            $btn.prop('disabled', false).text('⇄ Synchroniser');
        });
    });

    // ─────────────────────────────────────────────
    //  Retry (metabox)
    // ─────────────────────────────────────────────

    $(document).on('click', '.wcmcs-btn-retry', function (e) {
        e.preventDefault();
        var $btn    = $(this);
        var orderId = $btn.data('order-id');
        var $result = $btn.closest('.wcmcs-metabox').find('.wcmcs-ajax-result');

        $btn.prop('disabled', true).text(wcmcsAdmin.i18n.retrying);

        $.post(wcmcsAdmin.ajaxUrl, {
            action:   'wcmcs_retry_order',
            order_id: orderId,
            nonce:    wcmcsAdmin.nonce
        }, function (response) {
            if (response.success) {
                showResult($result, 'success', '✓ ' + response.data.message);
                setTimeout(function () { location.reload(); }, 2000);
            } else {
                var errMsg = response.data ? response.data.message : wcmcsAdmin.i18n.error;
                showResult($result, 'error', '✗ ' + errMsg);
            }
        }).fail(function () {
            showResult($result, 'error', '✗ Erreur réseau.');
        }).always(function () {
            $btn.prop('disabled', false).text('↺ Retry');
        });
    });

    // ─────────────────────────────────────────────
    //  Sync depuis la liste des commandes (row action)
    // ─────────────────────────────────────────────

    $(document).on('click', 'a[class*="wcmcs-sync"]', function (e) {
        e.preventDefault();
        var $link   = $(this);
        var $row    = $link.closest('tr');
        // Extraire l'order ID depuis l'URL ou le data attribute
        var orderId = $link.data('order-id') || $row.find('.order_number a').data('id') || $row.attr('id').replace('post-', '');

        if (!orderId) {
            return;
        }

        $link.text(wcmcsAdmin.i18n.syncing);

        $.post(wcmcsAdmin.ajaxUrl, {
            action:   'wcmcs_sync_single',
            order_id: orderId,
            nonce:    wcmcsAdmin.nonce
        }, function (response) {
            if (response.success) {
                // Flash vert sur la ligne
                $row.css('background', '#d4edda');
                $link.text('✓ OK');
                setTimeout(function () {
                    $row.css('background', '');
                    $link.text('⇄ Sync');
                }, 3000);
            } else {
                $row.css('background', '#f8d7da');
                $link.text('✗ Erreur');
                setTimeout(function () {
                    $row.css('background', '');
                    $link.text('⇄ Sync');
                }, 3000);
            }
        });
    });

    // ─────────────────────────────────────────────
    //  Voir erreur (modal simple)
    // ─────────────────────────────────────────────

    $(document).on('click', '.wcmcs-view-error', function (e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');

        $.post(wcmcsAdmin.ajaxUrl, {
            action:   'wcmcs_get_error',
            order_id: orderId,
            nonce:    wcmcsAdmin.nonce
        }, function (response) {
            if (response.success) {
                var data = response.data;
                var html =
                    '<div class="wcmcs-error-modal" style="' +
                    'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center">' +
                    '<div style="background:#fff;padding:24px;border-radius:8px;max-width:600px;width:90%;max-height:80vh;overflow-y:auto">' +
                    '<h3 style="margin:0 0 12px">❌ Erreur — Commande #' + orderId + '</h3>' +
                    '<p><strong>Message :</strong> ' + $('<span>').text(data.error_message).html() + '</p>' +
                    '<p><strong>Date :</strong> ' + $('<span>').text(data.sync_date).html() + '</p>' +
                    '<p><strong>Tentatives :</strong> ' + data.retry_count + '</p>' +
                    (data.api_response && Object.keys(data.api_response).length ?
                        '<p><strong>Réponse API :</strong></p>' +
                        '<pre style="background:#f4f4f4;padding:8px;overflow-x:auto;font-size:11px">' +
                        $('<span>').text(JSON.stringify(data.api_response, null, 2)).html() + '</pre>'
                        : '') +
                    '<button type="button" class="button wcmcs-close-modal" style="margin-top:12px">Fermer</button>' +
                    '</div></div>';
                $('body').append(html);
            }
        });
    });

    $(document).on('click', '.wcmcs-close-modal, .wcmcs-error-modal', function (e) {
        if (e.target === this) {
            $('.wcmcs-error-modal').remove();
        }
    });

    // Fermer modal avec Escape
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            $('.wcmcs-error-modal').remove();
        }
    });

}(jQuery));
