/**
 * MultiCarrier Sync TN — Admin JS
 * Gère les actions AJAX : sync individuel, retry, voir erreur
 */
/* global wcmcsAdmin, jQuery */
(function ($) {
    'use strict';

    // ─────────────────────────────────────────────
    //  Utilitaires
    // ─────────────────────────────────────────────

    /**
     * Échappe une valeur pour injection HTML (protection XSS).
     * @param {*} value
     * @return {string}
     */
    function escHtml(value) {
        return $('<span>').text(value === null || value === undefined ? '' : String(value)).html();
    }

    /**
     * Échappe une valeur pour un attribut src/href URL (protection XSS).
     * @param {*} value
     * @return {string}
     */
    function escUrl(value) {
        var v = String(value === null || value === undefined ? '' : value);
        return encodeURI(v).replace(/["'<>&]/g, function (ch) {
            return '&#' + ch.charCodeAt(0) + ';';
        });
    }

    function showResult($container, type, message) {
        var color = type === 'success' ? '#28a745' : '#dc3545';
        $container.html(
            '<div style="padding:8px;background:' + color + ';color:#fff;border-radius:4px;font-size:12px;margin-top:4px">' +
            escHtml(message) +
            '</div>'
        );
        setTimeout(function () {
            $container.fadeOut(500, function () {
                $(this).html('').show();
            });
        }, 5000);
    }

    /**
     * Met à jour les colonnes Sync d'une ligne de commande après une action AJAX.
     */
    function updateOrderColumns(orderId, columns) {
        if (!columns || !orderId) {
            return;
        }
        var $row = $('#post-' + orderId + ', #order-' + orderId);
        if (!$row.length) {
            // HPOS : chercher par data attribute ou par TD contenant l'info
            $row = $('tr').filter(function () {
                return $(this).find('.wcmcs-carrier-label, .wcmcs-na').length > 0 &&
                       ($(this).attr('id') === 'post-' + orderId || $(this).data('order-id') == orderId);
            });
        }
        if (!$row.length) {
            return;
        }

        if (columns.carrier !== undefined) {
            $row.find('.column-wcmcs_carrier').html(columns.carrier);
        }
        if (columns.sync_status !== undefined) {
            $row.find('.column-wcmcs_sync_status').html(columns.sync_status);
        }
        if (columns.tracking !== undefined) {
            $row.find('.column-wcmcs_tracking').html(columns.tracking);
        }
    }

    /**
     * Lit le transporteur sélectionné dans le dropdown « Forcer le transporteur »
     * de la metabox. Le choix n'est persisté qu'au « Mettre à jour » de la
     * commande — on le transmet à chaque sync/retry AJAX pour qu'il soit
     * honoré immédiatement (sinon la détection auto/envoie chez le
     * transporteur par défaut).
     */
    function readForcedCarrier($btn) {
        return $btn.closest('.wcmcs-metabox').find('#wcmcs_forced_carrier').val() || '';
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
            action:        'wcmcs_sync_single',
            order_id:      orderId,
            force_carrier: readForcedCarrier($btn),
            nonce:         wcmcsAdmin.nonce
        }, function (response) {
            var data = response.success ? response.data : (response.data || {});
            if (response.success) {
                var msg = data.message;
                if (data.tracking_id) {
                    msg += ' [' + data.tracking_id + ']';
                }
                showResult($result, 'success', '✓ ' + msg);
                // Mettre à jour les colonnes sans recharger la page
                if (data.columns) {
                    updateOrderColumns(orderId, data.columns);
                }
                // Mettre à jour le metabox
                if (data.status === 'success' || data.status === 'skipped') {
                    setTimeout(function () { location.reload(); }, 2000);
                }
            } else {
                var errMsg = data.message || wcmcsAdmin.i18n.error;
                showResult($result, 'error', '✗ ' + errMsg);
                // Mettre à jour les colonnes même en erreur
                if (data.columns) {
                    updateOrderColumns(orderId, data.columns);
                }
            }
        }).fail(function () {
            showResult($result, 'error', '✗ ' + (wcmcsAdmin.i18n.network_error || 'Erreur réseau.'));
        }).always(function () {
            $btn.prop('disabled', false).text(wcmcsAdmin.i18n.sync_btn || '⇄ Synchroniser');
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
            action:        'wcmcs_retry_order',
            order_id:      orderId,
            force_carrier: readForcedCarrier($btn),
            nonce:         wcmcsAdmin.nonce
        }, function (response) {
            var data = response.success ? response.data : (response.data || {});
            if (response.success) {
                showResult($result, 'success', '✓ ' + data.message);
                if (data.columns) {
                    updateOrderColumns(orderId, data.columns);
                }
                setTimeout(function () { location.reload(); }, 2000);
            } else {
                var errMsg = data.message || wcmcsAdmin.i18n.error;
                showResult($result, 'error', '✗ ' + errMsg);
                if (data.columns) {
                    updateOrderColumns(orderId, data.columns);
                }
            }
        }).fail(function () {
            showResult($result, 'error', '✗ ' + (wcmcsAdmin.i18n.network_error || 'Erreur réseau.'));
        }).always(function () {
            $btn.prop('disabled', false).text(wcmcsAdmin.i18n.retry_btn || '↺ Retry');
        });
    });

    // ─────────────────────────────────────────────
    //  Vérification du statut colis (metabox bouton)
    // ─────────────────────────────────────────────

    $(document).on('click', '.wcmcs-btn-status', function (e) {
        e.preventDefault();
        var $btn    = $(this);
        var orderId = $btn.data('order-id');
        var $result = $btn.closest('.wcmcs-metabox').find('.wcmcs-ajax-result');

        $btn.prop('disabled', true).text(wcmcsAdmin.i18n.checking);

        $.post(wcmcsAdmin.ajaxUrl, {
            action:   'wcmcs_check_status',
            order_id: orderId,
            nonce:    wcmcsAdmin.nonce
        }, function (response) {
            var data = response.success ? response.data : (response.data || {});
            if (response.success) {
                var msg = wcmcsAdmin.i18n.state_of_carrier + ' : « ' + data.state_label + ' »';
                if (data.changed) {
                    msg += ' — ' + wcmcsAdmin.i18n.status_updated + ' ' + (data.order_status_label || data.order_status);
                }
                showResult($result, 'success', '✓ ' + msg);
                if (data.columns) {
                    updateOrderColumns(orderId, data.columns);
                }
                if (data.changed) {
                    setTimeout(function () { location.reload(); }, 2500);
                }
            } else {
                showResult($result, 'error', '✗ ' + (data.message || wcmcsAdmin.i18n.error));
            }
        }).fail(function () {
            showResult($result, 'error', '✗ ' + (wcmcsAdmin.i18n.network_error || 'Erreur réseau.'));
        }).always(function () {
            $btn.prop('disabled', false).text(wcmcsAdmin.i18n.status_btn || '📍 Vérifier le statut');
        });
    });

    // ─────────────────────────────────────────────
    //  Annulation du colis (metabox bouton)
    // ─────────────────────────────────────────────

    $(document).on('click', '.wcmcs-btn-cancel', function (e) {
        e.preventDefault();
        var $btn    = $(this);
        var orderId = $btn.data('order-id');
        var $result = $btn.closest('.wcmcs-metabox').find('.wcmcs-ajax-result');

        if (!window.confirm(wcmcsAdmin.i18n.confirm_cancel || 'Annuler le colis chez le transporteur ?')) {
            return;
        }

        $btn.prop('disabled', true).text(wcmcsAdmin.i18n.cancelling || 'Annulation...');

        $.post(wcmcsAdmin.ajaxUrl, {
            action:   'wcmcs_cancel_shipment',
            order_id: orderId,
            nonce:    wcmcsAdmin.nonce
        }, function (response) {
            var data = response.success ? response.data : (response.data || {});
            if (response.success) {
                showResult($result, 'success', '✓ ' + data.message);
                if (data.columns) {
                    updateOrderColumns(orderId, data.columns);
                }
                setTimeout(function () { location.reload(); }, 2000);
            } else {
                showResult($result, 'error', '✗ ' + (data.message || wcmcsAdmin.i18n.error));
                if (data.columns) {
                    updateOrderColumns(orderId, data.columns);
                }
            }
        }).fail(function () {
            showResult($result, 'error', '✗ ' + (wcmcsAdmin.i18n.network_error || 'Erreur réseau.'));
        }).always(function () {
            $btn.prop('disabled', false).text(wcmcsAdmin.i18n.cancel_btn || '✕ Annuler le colis');
        });
    });

    // ─────────────────────────────────────────────
    //  Annulation du colis depuis la liste des commandes (row action)
    // ─────────────────────────────────────────────

    $(document).on('click', 'a[class*="wcmcs-cancel"]', function (e) {
        e.preventDefault();
        var $link   = $(this);
        var $row    = $link.closest('tr');
        var orderId = $link.data('order-id') || $row.find('.order_number a').data('id') || ($row.attr('id') || '').replace('post-', '');

        if (!orderId) {
            return;
        }

        if (!window.confirm(wcmcsAdmin.i18n.confirm_cancel || 'Annuler le colis chez le transporteur ?')) {
            return;
        }

        $link.prop('disabled', true).text(wcmcsAdmin.i18n.cancelling || 'Annulation...');

        $.post(wcmcsAdmin.ajaxUrl, {
            action:   'wcmcs_cancel_shipment',
            order_id: orderId,
            nonce:    wcmcsAdmin.nonce
        }, function (response) {
            var data = response.success ? response.data : (response.data || {});
            if (response.success) {
                $row.css('background', '#d4edda');
                $link.text('✓ ' + (wcmcsAdmin.i18n.cancelled_word || 'Annulé'));
                if (data.columns) {
                    updateOrderColumns(orderId, data.columns);
                }
                setTimeout(function () {
                    $row.css('background', '');
                    $link.closest('td').css('background', '');
                }, 3000);
            } else {
                $row.css('background', '#f8d7da');
                $link.text('✗ ' + (wcmcsAdmin.i18n.error || 'Erreur'));
                setTimeout(function () {
                    $row.css('background', '');
                    $link.text('✕ ' + (wcmcsAdmin.i18n.cancel_short || 'Annuler'));
                }, 3000);
                if (data.message) {
                    window.alert(data.message);
                }
            }
        }).fail(function () {
            $row.css('background', '#f8d7da');
            $link.text('✗ ' + (wcmcsAdmin.i18n.network_error || 'Erreur réseau.'));
            setTimeout(function () {
                $row.css('background', '');
                $link.text('✕ ' + (wcmcsAdmin.i18n.cancel_short || 'Annuler'));
            }, 3000);
        });
    });

    // ─────────────────────────────────────────────
    //  Sync depuis la liste des commandes (row action)
    // ─────────────────────────────────────────────

    $(document).on('click', 'a[class*="wcmcs-sync"]', function (e) {
        e.preventDefault();
        var $link   = $(this);
        var $row    = $link.closest('tr');
        var orderId = $link.data('order-id') || $row.find('.order_number a').data('id') || ($row.attr('id') || '').replace('post-', '');

        if (!orderId) {
            return;
        }

        $link.prop('disabled', true).text(wcmcsAdmin.i18n.syncing);

        $.post(wcmcsAdmin.ajaxUrl, {
            action:   'wcmcs_sync_single',
            order_id: orderId,
            nonce:    wcmcsAdmin.nonce
        }, function (response) {
            var data = response.success ? response.data : (response.data || {});
            if (response.success) {
                $row.css('background', '#d4edda');
                $link.text('✓ OK');
                if (data.columns) {
                    updateOrderColumns(orderId, data.columns);
                }
                setTimeout(function () {
                    $row.css('background', '');
                    $link.text('⇄ Sync');
                }, 3000);
            } else {
                $row.css('background', '#f8d7da');
                $link.text('✗ Erreur');
                if (data.columns) {
                    updateOrderColumns(orderId, data.columns);
                }
                setTimeout(function () {
                    $row.css('background', '');
                    $link.text('⇄ Sync');
                }, 3000);
            }
        }).fail(function () {
            $row.css('background', '#f8d7da');
            $link.text('✗ ' + (wcmcsAdmin.i18n.network_error || 'Erreur réseau.'));
            setTimeout(function () {
                $row.css('background', '');
                $link.text('⇄ Sync');
            }, 3000);
        });
    });

    // ─────────────────────────────────────────────
    //  Scan global des statuts (bouton page commandes)
    // ─────────────────────────────────────────────

    var scanRunning = false;
    var SCAN_DELAY_MS = window.wcmcsScanDelayMs || 1000; // délai entre appels API (anti rate-limit)
    var SCAN_RATE_RETRY_MS = 3000;                        // backoff « Too many requests » / HTTP 429

    function injectScanButton() {
        var $top = $('#wc-orders-filter .tablenav.top .actions, #posts-filter .tablenav.top .actions').first();
        if (!$top.length || $('#wcmcs-status-scan').length) {
            return;
        }
        var $btn = $('<button type="button" class="button" id="wcmcs-status-scan"></button>')
            .text(wcmcsAdmin.i18n.scan_btn || '📍 Vérifier tous les statuts')
            .css({ marginLeft: '8px', verticalAlign: 'middle' });
        $top.append($btn);
    }

    $(document).on('click', '#wcmcs-status-scan', function (e) {
        e.preventDefault();
        if (scanRunning) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text(wcmcsAdmin.i18n.checking || 'Vérification...');

        $.post(wcmcsAdmin.ajaxUrl, {
            action: 'wcmcs_status_scan_data',
            limit:  200,
            nonce:  wcmcsAdmin.nonce
        }, function (response) {
            var data = response.success ? response.data : { orders: [] };
            var orders = data.orders || [];
            if (!orders.length) {
                alert(wcmcsAdmin.i18n.scan_none || 'Aucun colis synchronisé à vérifier.');
            } else {
                openScanModal(orders);
            }
        }).fail(function () {
            alert(wcmcsAdmin.i18n.network_error || 'Erreur réseau.');
        }).always(function () {
            $btn.prop('disabled', false).text(wcmcsAdmin.i18n.scan_btn || '📍 Vérifier tous les statuts');
        });
    });

    /** Couleur de badge d'un statut (map PHP statusColors, clés sans préfixe wc-). */
    function scanStatusColor(key) {
        var colors = wcmcsAdmin.statusColors || {};
        if (!key) { return ''; }
        if (colors[key]) { return colors[key]; }
        if (key.indexOf('wcmcs-') === 0 && colors['wc-' + key]) { return colors['wc-' + key]; }
        return '';
    }

    /** Mini badge coloré du statut commande (texte auto-contraste YIQ). */
    function scanStatusChip(labelText, statusKey) {
        var c = scanStatusColor(statusKey);
        if (!c) { return escHtml(labelText); }
        var yiq = parseInt(c.substr(1, 2), 16) * 0.299
            + parseInt(c.substr(3, 2), 16) * 0.587
            + parseInt(c.substr(5, 2), 16) * 0.114;
        var t = yiq >= 150 ? '#1e1e1e' : '#ffffff';
        return '<span style="display:inline-block;padding:1px 8px;border-radius:11px;font-size:11px;font-weight:600;'
            + 'background:' + c + ';color:' + t + '">' + escHtml(labelText) + '</span>';
    }

    function scanFilters() {
        var i = wcmcsAdmin.i18n;
        var defs = [
            { key: 'wcmcs-synced',  label: i.f_synced,     on: true  },
            { key: 'processing',    label: i.f_processing, on: true  },
            { key: 'on-hold',       label: i.f_onhold,     on: true  },
            { key: 'pending',       label: i.f_pending,    on: true  },
            { key: 'wcmcs-confirmed', label: i.f_confirmed, on: true },
            { key: 'wcmcs-packed',    label: i.f_packed,    on: true },
            { key: 'completed',     label: i.f_completed,  on: false },
            { key: 'wcmcs-delivered', label: i.f_delivered, on: false },
            { key: 'wcmcs-returned',  label: i.f_returned,  on: false }
        ];
        var html = '<strong>' + escHtml(i.scan_filter_word || 'Filtrer') + ' :</strong> ';
        for (var k = 0; k < defs.length; k++) {
            var d = defs[k];
            var c = scanStatusColor(d.key);
            html += '<label style="margin-right:10px;white-space:nowrap">'
                + '<input type="checkbox" class="wcmcs-scan-filtre" value="' + d.key + '" ' + (d.on ? 'checked' : '') + ' /> '
                + (c ? '<span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:' + c + ';vertical-align:-1px;margin-right:3px"></span>' : '')
                + escHtml(d.label) + '</label>';
        }
        html += '<a href="#" id="wcmcs-scan-filters-toggle" style="margin-left:4px">'
            + escHtml(i.scan_filters_word || 'Tout') + '</a>'
            + '<span class="wcmcs-scan-visible" style="float:right;font-weight:600"></span>';
        return '<div class="wcmcs-scan-filters" style="margin:2px 2px 10px;font-size:12px;line-height:1.9">' + html + '</div>';
    }

    function scanStateBadge(category, lastState, i) {
        var map = {
            unknown:   { c: '#777',    t: i.badge_unknown   },
            transit:   { c: 'inherit', t: i.badge_transit,  b: '#2271b1' },
            delivered: { c: '#fff',    t: i.badge_delivered, b: '#28a745' },
            returned:  { c: '#fff',    t: i.badge_returned,  b: '#e67e22' }
        };
        var m = map[category] || map.transit;
        var badge = '<span style="display:inline-block;padding:1px 7px;border-radius:11px;font-size:11px;color:' + m.c + (m.b ? ';background:' + m.b : '') + ';">' + escHtml(m.t) + '</span>';
        return badge + (lastState ? ' <span style="font-size:11px">— ' + escHtml(lastState) + '</span>' : '');
    }

    var SCAN_FULLSCREEN_KEY = 'wcmcs-scan-fullscreen';

    function scanFullBtn() {
        var i = wcmcsAdmin.i18n;
        return '<button type="button" class="wcmcs-scan-full" title="' + escHtml(i.fullscreen_on || 'Plein écran') + '" aria-label="' + escHtml(i.fullscreen_on || 'Plein écran') + '">'
            + '<span class="dashicons dashicons-fullscreen-alt" aria-hidden="true"></span></button>';
    }

    /**
     * Bascule le plein écran du modal scan. Le modal (et son overlay) est
     * borné à la zone de travail : la barre d'admin WP et le menu backoffice
     * restent visibles et cliquables. Décalages mesurés dynamiquement
     * (menu replié : 36px, barre masquée : 0).
     */
    function applyScanFullscreen($overlay, on) {
        var $modal = $overlay.find('#wcmcs-scan-modal');
        $overlay.toggleClass('wcmcs-scan-fs', on);
        $modal.toggleClass('wcmcs-scan-fullscreen', on);
        $modal.find('.wcmcs-scan-body').css('max-height', ''); // laisson le CSS (flex) décider
        var i = wcmcsAdmin.i18n;
        $modal.find('.wcmcs-scan-full')
            .html(on
                ? '<span class="dashicons dashicons-fullscreen-exit-alt" aria-hidden="true"></span>'
                : '<span class="dashicons dashicons-fullscreen-alt" aria-hidden="true"></span>')
            .attr('title', on ? (i.fullscreen_off || 'Réduire') : (i.fullscreen_on || 'Plein écran'))
            .attr('aria-label', on ? (i.fullscreen_off || 'Réduire') : (i.fullscreen_on || 'Plein écran'));
        if (on) {
            var barH = $('#wpadminbar').length ? $('#wpadminbar').outerHeight() : 0;
            var menuW = $('#adminmenuwrap').length ? $('#adminmenuwrap').outerWidth() : 0;
            $overlay.css({ top: barH + 'px', left: menuW + 'px' });
        } else {
            $overlay.css({ top: '', left: '' });
        }
        try {
            window.localStorage.setItem(SCAN_FULLSCREEN_KEY, on ? '1' : '0');
        } catch (e) { /* stockage indisponible : préférence non mémorisée. */ }
    }

    function openScanModal(orders) {
        var i = wcmcsAdmin.i18n;
        var rows = '';
        for (var k = 0; k < orders.length; k++) {
            var o = orders[k];
            if (String(o.id) === '0') { continue; }
            rows += '<tr data-id="' + o.id + '" data-status="' + escHtml(o.wc_status_key) + '" data-configured="' + (o.configured ? 1 : 0) + '">'
                + '<td style="width:26px"><input type="checkbox" class="wcmcs-scan-select" checked /></td>'
                + '<td><a href="' + escUrl(o.url) + '" target="_blank" rel="noopener">#' + escHtml(o.number) + '</a></td>'
                + '<td>'
                + (o.logo
                    ? '<img src="' + escUrl(o.logo) + '" alt="' + escHtml(o.carrier) + '" title="' + escHtml(o.carrier) + '" style="max-height:22px;max-width:90px;vertical-align:middle;display:inline-block" />'
                    : escHtml(o.carrier))
                + (o.configured ? '' : ' <span style="color:#dc3545">(' + escHtml(i.scan_not_configured) + ')</span>') + '</td>'
                + '<td><code>' + escHtml(o.tracking) + '</code></td>'
                + '<td>' + scanStatusChip(o.wc_status, o.wc_status_key) + '</td>'
                + '<td class="wcmcs-scan-state">' + scanStateBadge(o.state_category, o.last_state, i) + '</td>'
                + '<td class="wcmcs-scan-result">—</td>'
                + '</tr>';
        }

        var $overlay = $(
            '<div class="wcmcs-bulk-overlay">'
            + '<div class="wcmcs-bulk-modal" id="wcmcs-scan-modal">'
            + '<div class="wcmcs-bulk-header"><h2>' + escHtml(i.scan_title) + '</h2>'
            + '<span class="wcmcs-header-actions">' + scanFullBtn()
            + '<button type="button" class="wcmcs-bulk-x">&times;</button></span></div>'
            + '<div class="wcmcs-scan-topbar">'
            + '<button type="button" class="button button-primary" id="wcmcs-scan-start">'
            + escHtml(i.scan_start) + '</button>'
            + '<button type="button" class="button" id="wcmcs-scan-close" style="display:none">'
            + escHtml(i.scan_reload) + '</button>'
            + '<span class="wcmcs-scan-summary"></span>'
            + '</div>'
            + '<div class="wcmcs-scan-body" style="max-height:55vh;overflow:auto;padding:12px 20px 16px">'
            + scanFilters()
            + '<table class="widefat striped"><thead><tr>'
            + '<th style="width:26px"><input type="checkbox" id="wcmcs-scan-toggle-all" checked /></th>'
            + '<th>' + escHtml(i.scan_col_order) + '</th>'
            + '<th>' + escHtml(i.scan_col_carrier) + '</th>'
            + '<th>' + escHtml(i.scan_col_tracking) + '</th>'
            + '<th>' + escHtml(i.scan_col_wc_status) + '</th>'
            + '<th>' + escHtml(i.scan_col_state) + '</th>'
            + '<th>' + escHtml(i.scan_col_result) + '</th>'
            + '</tr></thead><tbody>' + rows + '</tbody></table>'
            + '</div></div></div>'
        );

        $('body').append($overlay);

        // Préférence plein écran mémorisée.
        var wasFull = false;
        try { wasFull = window.localStorage.getItem(SCAN_FULLSCREEN_KEY) === '1'; } catch (e) { /* noop */ }
        if (wasFull) {
            applyScanFullscreen($overlay, true);
        }

        $overlay.on('click', '.wcmcs-scan-full', function () {
            applyScanFullscreen($overlay, !$overlay.hasClass('wcmcs-scan-fs'));
            applyScanFilters($overlay);
        });

        applyScanFilters($overlay);
        $overlay.on('change', 'input.wcmcs-scan-filtre', function () {
            applyScanFilters($overlay);
        });

        $overlay.find('#wcmcs-scan-filters-toggle').on('click', function (e) {
            e.preventDefault();
            var $f = $overlay.find('input.wcmcs-scan-filtre');
            var allChecked = $f.length === $f.filter(':checked').length;
            $f.prop('checked', !allChecked).trigger('change');
        });

        $overlay.on('change', '#wcmcs-scan-toggle-all', function () {
            $overlay.find('input.wcmcs-scan-select:visible').prop('checked', $(this).is(':checked'));
        });
        $overlay.on('change', 'input.wcmcs-scan-select', function () {
            $overlay.find('#wcmcs-scan-toggle-all').prop('checked',
                $overlay.find('input.wcmcs-scan-select:visible').length === $overlay.find('input.wcmcs-scan-select:visible:checked').length);
        });

        $overlay.find('.wcmcs-bulk-x').on('click', function () {
            if (scanRunning) { return; }
            $overlay.remove();
        });

        $overlay.find('#wcmcs-scan-start').on('click', function () {
            startScan($overlay);
        });

        $overlay.find('#wcmcs-scan-close').on('click', function () {
            location.reload();
        });
    }

    /**
     * Filtre multi-statuts côté client : seule une valeur cochée fait apparaître
     * la ligne (les checkbox et le lancement ignorent les lignes masquées).
     */
    function applyScanFilters($overlay) {
        var on = {};
        $overlay.find('input.wcmcs-scan-filtre:checked').each(function () {
            on[$(this).val()] = true;
        });
        $overlay.find('tbody tr').each(function () {
            var show = !!on[$(this).data('status')];
            $(this).toggle(show);
        });
        var $visible = $overlay.find('input.wcmcs-scan-select:visible');
        $overlay.find('#wcmcs-scan-toggle-all').prop('checked',
            $visible.length > 0 && $visible.length === $visible.filter(':checked').length);
        $overlay.find('.wcmcs-scan-visible').text(
            $overlay.find('tbody tr:visible').length + ' ' + (wcmcsAdmin.i18n.scan_visible_word || 'colis affichés')
        );
        // Libellé du toggle « Tout / Aucun » : toutes cochées ? → « Aucun », sinon « Tout ».
        var $f = $overlay.find('input.wcmcs-scan-filtre');
        $overlay.find('#wcmcs-scan-filters-toggle').text(
            ($f.length > 0 && $f.length === $f.filter(':checked').length)
                ? (wcmcsAdmin.i18n.scan_filternone_word || 'Aucun')
                : (wcmcsAdmin.i18n.scan_filters_word || 'Tout')
        );
    }

    function markRowError($row, message) {
        $row.find('.wcmcs-scan-result').html('<span style="color:#dc3545">✗ ' + escHtml(message || '') + '</span>');
    }

    function renderScanSuccess($row, data, i18n) {
        var res = '✓ ' + escHtml(data.state_label || '');
        if (data.changed) {
            res += ' <strong style="color:#28a745">→ ' + escHtml(data.order_status_label || data.order_status) + '</strong>';
        }
        $row.find('.wcmcs-scan-result').html(res);
    }

    function startScan($overlay) {
        if (scanRunning) {
            return;
        }

        var $selected = $overlay.find('input.wcmcs-scan-select:checked').closest('tr').filter(':visible');
        var i18n = wcmcsAdmin.i18n;
        if (!$selected.length) {
            alert(i18n.scan_none_selected || 'Aucun colis sélectionné.');
            return;
        }

        scanRunning = true;
        var $start = $overlay.find('#wcmcs-scan-start');
        $start.prop('disabled', true).text(i18n.checking || 'Vérification...');
        $overlay.find('#wcmcs-scan-toggle-all, input.wcmcs-scan-select, input.wcmcs-scan-filtre, #wcmcs-scan-filters-toggle').prop('disabled', true).css('pointer-events', 'none');

        var total = $selected.length;
        var done = 0;
        var updated = 0;
        var errors = 0;
        var queue = [];
        $selected.each(function () { queue.push($(this)); });
        var idx = 0;

        function progress() {
            if (done < total) {
                $overlay.find('.wcmcs-scan-summary').html(done + ' / ' + total + ' — <em>' + escHtml(i18n.scan_running_short || '...') + '</em>');
            }
        }

        function finish() {
            scanRunning = false;
            $start.prop('disabled', false).text(i18n.scan_start || 'Lancer la vérification');
            $overlay.find('#wcmcs-scan-toggle-all, input.wcmcs-scan-select, input.wcmcs-scan-filtre').prop('disabled', false);
            $overlay.find('#wcmcs-scan-filters-toggle').css('pointer-events', '');
            applyScanFilters($overlay);
            $overlay.find('.wcmcs-scan-summary').html(
                escHtml(done + ' ' + i18n.scan_checked_word) + ' · '
                + '<span style="color:#28a745"> ' + updated + ' ' + escHtml(i18n.scan_updated_word) + '</span> · '
                + '<span style="color:#dc3545"> ' + errors + ' ' + escHtml(i18n.scan_errors_word) + '</span>'
            );
            $start.hide();
            $overlay.find('#wcmcs-scan-close').show().trigger('focus');
        }

        function afterRow() {
            progress();
            idx++;
            setTimeout(step, SCAN_DELAY_MS);
        }

        function step() {
            if (idx >= queue.length) {
                finish();
                return;
            }

            var $row = queue[idx];
            $row.find('.wcmcs-scan-result').html('<em>⏳ ...</em>');

            // Transporteur non configuré : erreur immédiate, pas d'appel API.
            if (parseInt($row.data('configured'), 10) !== 1) {
                markRowError($row, i18n.scan_not_configured);
                errors++;
                done++;
                afterRow();
                return;
            }

            var orderId = $row.data('id');
            var attempt = 0;

            var call = function () {
                $.post(wcmcsAdmin.ajaxUrl, {
                    action:   'wcmcs_check_status',
                    order_id: orderId,
                    nonce:    wcmcsAdmin.nonce
                }).done(function (response) {
                    var data = response.success ? response.data : (response.data || {});
                    var msg = String(data.message || '');
                    if (response.success) {
                        renderScanSuccess($row, data, i18n);
                        if (data.changed) { updated++; }
                        done++;
                        afterRow();
                    } else if (attempt < 1 && /too many requests/i.test(msg)) {
                        attempt++;
                        $row.find('.wcmcs-scan-result').html('<em>⏳ ' + escHtml(i18n.scan_rate_limited) + '</em>');
                        setTimeout(call, SCAN_RATE_RETRY_MS);
                    } else {
                        markRowError($row, msg || i18n.error);
                        errors++;
                        done++;
                        afterRow();
                    }
                }).fail(function (jqXHR) {
                    if (attempt < 1 && jqXHR && jqXHR.status === 429) {
                        attempt++;
                        $row.find('.wcmcs-scan-result').html('<em>⏳ ' + escHtml(i18n.scan_rate_limited) + '</em>');
                        setTimeout(call, SCAN_RATE_RETRY_MS);
                        return;
                    }
                    markRowError($row, i18n.network_error);
                    errors++;
                    done++;
                    afterRow();
                });
            };

            call();
        }

        step();
    }

    // ─────────────────────────────────────────────
    //  Statut depuis la liste des commandes (row action)
    // ─────────────────────────────────────────────

    $(document).on('click', 'a[class*="wcmcs-status"]', function (e) {
        e.preventDefault();
        var $link   = $(this);
        var $row    = $link.closest('tr');
        var orderId = $link.data('order-id') || $row.find('.order_number a').data('id') || ($row.attr('id') || '').replace('post-', '');

        if (!orderId) {
            return;
        }

        $link.prop('disabled', true).text(wcmcsAdmin.i18n.checking);

        $.post(wcmcsAdmin.ajaxUrl, {
            action:   'wcmcs_check_status',
            order_id: orderId,
            nonce:    wcmcsAdmin.nonce
        }, function (response) {
            var data = response.success ? response.data : (response.data || {});
            if (response.success) {
                var msg = data.state_label || '';
                if (data.changed) {
                    $row.css('background', '#d4edda');
                    $link.text('✓ ' + (data.order_status_label || data.order_status));
                    setTimeout(function () { location.reload(); }, 2000);
                } else {
                    $row.css('background', '#d4edda');
                    $link.text('✓ ' + msg);
                    setTimeout(function () {
                        $row.css('background', '');
                        $link.text('📍 Statut');
                    }, 3000);
                }
            } else {
                $row.css('background', '#f8d7da');
                $link.text('✗ Erreur');
                setTimeout(function () {
                    $row.css('background', '');
                    $link.text('📍 Statut');
                }, 3000);
            }
        }).fail(function () {
            $row.css('background', '#f8d7da');
            $link.text('✗ Réseau');
            setTimeout(function () {
                $row.css('background', '');
                $link.text('📍 Statut');
            }, 3000);
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
                    '<h3 style="margin:0 0 12px">❌ Erreur — Commande #' + escHtml(orderId) + '</h3>' +
                    '<p><strong>Message :</strong> ' + escHtml(data.error_message) + '</p>' +
                    '<p><strong>Date :</strong> ' + escHtml(data.sync_date) + '</p>' +
                    '<p><strong>Tentatives :</strong> ' + escHtml(data.retry_count) + '</p>' +
                    (data.api_response && Object.keys(data.api_response).length ?
                        '<p><strong>Réponse API :</strong></p>' +
                        '<pre style="background:#f4f4f4;padding:8px;overflow-x:auto;font-size:11px">' +
                        escHtml(JSON.stringify(data.api_response, null, 2)) + '</pre>'
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

    // ─────────────────────────────────────────────
    //  Bouton télécharger étiquette PDF (Colissimo TN)
    // ─────────────────────────────────────────────

    $(document).on('click', '.wcmcs-btn-label', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var orderId = $btn.data('order-id');
        var $result = $btn.closest('.wcmcs-metabox').find('.wcmcs-ajax-result');

        $btn.prop('disabled', true).text('📄 ' + (wcmcsAdmin.i18n.downloading || 'Téléchargement...'));

        $.post(wcmcsAdmin.ajaxUrl, {
            action: 'wcmcs_get_label_pdf',
            order_id: orderId,
            nonce: wcmcsAdmin.nonce
        }, function (response) {
            if (response.success && response.data.label_url) {
                window.open(response.data.label_url, '_blank');
                showResult($result, 'success', '✓ ' + (wcmcsAdmin.i18n.label_downloaded || 'Étiquette téléchargée'));
            } else {
                var errMsg = response.data ? response.data.message : (wcmcsAdmin.i18n.error || 'Erreur');
                showResult($result, 'error', '✗ ' + errMsg);
            }
        }).fail(function () {
            showResult($result, 'error', '✗ ' + (wcmcsAdmin.i18n.network_error || 'Erreur réseau.'));
        }).always(function () {
            $btn.prop('disabled', false).text('📄 ' + (wcmcsAdmin.i18n.label_pdf || 'Étiquette PDF'));
        });
    });

    // ─────────────────────────────────────────────
    //  Bulk Sync — Interception & Modal
    // ─────────────────────────────────────────────

    var bulkRunning = false;

    function getSelectedOrderIds() {
        var ids = [];
        $('input[name="post[]"]:checked, input[name="id[]"]:checked').each(function () {
            var v = parseInt($(this).val(), 10);
            if (v > 0) {
                ids.push(v);
            }
        });
        return ids;
    }

    function openBulkSyncModal(orderIds) {
        if (!orderIds.length) {
            alert(wcmcsAdmin.i18n.bulk_no_orders);
            return;
        }

        $.post(wcmcsAdmin.ajaxUrl, {
            action: 'wcmcs_bulk_sync_modal_data',
            order_ids: orderIds,
            nonce: wcmcsAdmin.nonce
        }, function (response) {
            if (!response.success) {
                var msg = (response.data && response.data.message) ? response.data.message : wcmcsAdmin.i18n.error;
                alert(msg);
                return;
            }
            renderBulkSyncModal(response.data.orders, response.data.carriers, orderIds);
        }).fail(function () {
            alert(wcmcsAdmin.i18n.network_error);
        });
    }

    function renderBulkSyncModal(orders, carriers, orderIds) {
        var unsyncedCount = 0;
        for (var i = 0; i < orders.length; i++) {
            if (!orders[i].already_synced) {
                unsyncedCount++;
            }
        }

        if (unsyncedCount === 0) {
            alert(wcmcsAdmin.i18n.bulk_all_synced);
            return;
        }

        var html =
            '<div class="wcmcs-bulk-overlay">' +
            '<div class="wcmcs-bulk-modal" id="wcmcs-bulk-modal">' +
            '<div class="wcmcs-bulk-header">' +
            '<h2>' + $('<span>').text(wcmcsAdmin.i18n.bulk_title).html() + '</h2>' +
            '<button type="button" class="wcmcs-bulk-x" title="' + $('<span>').text(wcmcsAdmin.i18n.bulk_cancel).html() + '">&times;</button>' +
            '</div>' +
            '<div class="wcmcs-bulk-carrier-row">' +
            '<label for="wcmcs-bulk-carrier">' + $('<span>').text(wcmcsAdmin.i18n.bulk_carrier_label).html() + '</label>' +
            '<select id="wcmcs-bulk-carrier">' +
            '<option value="">' + $('<span>').text(wcmcsAdmin.i18n.bulk_auto_detect).html() + '</option>';

        for (var c = 0; c < carriers.length; c++) {
            var cl = carriers[c];
            var lbl = $('<span>').text(cl.label).html();
            if (!cl.configured) {
                lbl += ' (' + $('<span>').text(wcmcsAdmin.i18n.bulk_not_configured).html() + ')';
            }
            html += '<option value="' + $('<span>').text(cl.slug).html() + '">' + lbl + '</option>';
        }

        html += '</select></div>' +
            '<div class="wcmcs-bulk-table-wrap">' +
            '<table class="wcmcs-bulk-table">' +
            '<thead><tr>' +
            '<th>' + $('<span>').text(wcmcsAdmin.i18n.bulk_order_col).html() + '</th>' +
            '<th>' + $('<span>').text(wcmcsAdmin.i18n.bulk_status_col).html() + '</th>' +
            '<th>' + $('<span>').text(wcmcsAdmin.i18n.bulk_result_col).html() + '</th>' +
            '</tr></thead><tbody>';

        for (var o = 0; o < orders.length; o++) {
            var ord = orders[o];
            var rowClass = ord.already_synced ? ' class="wcmcs-bulk-row-synced"' : '';
            // Statut réel de la sync (pas le transporteur) + transporteur de la
            // dernière tentative en info secondaire grise.
            var statusHtml = ord.already_synced
                ? '✓ ' + escHtml(wcmcsAdmin.i18n.bulk_already_synced)
                : escHtml(ord.status_label || '—');
            if (ord.carrier_label) {
                statusHtml += ' <span style="color:#8c8f94;font-size:11px">· ' + escHtml(ord.carrier_label) + '</span>';
            }
            var resultText = ord.already_synced
                ? '✓ ' + escHtml(wcmcsAdmin.i18n.bulk_already_synced)
                : '—';

            html += '<tr data-order-id="' + ord.id + '" data-already-synced="' + (ord.already_synced ? '1' : '0') + '"' + rowClass + '>' +
                '<td>#' + escHtml(ord.id) + '</td>' +
                '<td>' + statusHtml + '</td>' +
                '<td class="wcmcs-bulk-result">' + resultText + '</td>' +
                '</tr>';
        }

        html += '</tbody></table></div>' +
            '<div class="wcmcs-bulk-progress" style="display:none">' +
            '<div class="wcmcs-bulk-progress-bar"><div class="wcmcs-bulk-progress-fill" style="width:0%"></div></div>' +
            '<span class="wcmcs-bulk-progress-text"></span>' +
            '</div>' +
            '<div class="wcmcs-bulk-actions">' +
            '<button type="button" class="button button-primary" id="wcmcs-bulk-start">' + $('<span>').text(wcmcsAdmin.i18n.bulk_start).html() + '</button>' +
            '<button type="button" class="button" id="wcmcs-bulk-cancel">' + $('<span>').text(wcmcsAdmin.i18n.bulk_cancel).html() + '</button>' +
            '<button type="button" class="button button-primary" id="wcmcs-bulk-close" style="display:none">' + $('<span>').text(wcmcsAdmin.i18n.bulk_close).html() + '</button>' +
            '</div>' +
            '</div></div>';

        $('body').append(html);
    }

    function startBulkSync() {
        if (bulkRunning) {
            return;
        }
        bulkRunning = true;

        var forceCarrier = $('#wcmcs-bulk-carrier').val() || '';
        var unsynced = [];
        $('#wcmcs-bulk-modal tbody tr').each(function () {
            if ($(this).data('already-synced') !== 1 && $(this).data('already-synced') !== '1') {
                unsynced.push(parseInt($(this).data('order-id'), 10));
            }
        });

        if (!unsynced.length) {
            bulkRunning = false;
            return;
        }

        var total = unsynced.length;
        var processed = 0;
        var successes = 0;
        var failures = 0;

        $('#wcmcs-bulk-start').prop('disabled', true).text(wcmcsAdmin.i18n.bulk_processing);
        $('#wcmcs-bulk-cancel').prop('disabled', true);
        $('#wcmcs-bulk-carrier').prop('disabled', true);
        $('.wcmcs-bulk-progress').show();

        function processNext() {
            if (!unsynced.length || !bulkRunning) {
                finishBulkSync(successes, failures, total);
                return;
            }

            var orderId = unsynced.shift();
            var $row = $('#wcmcs-bulk-modal tbody tr[data-order-id="' + orderId + '"]');
            $row.addClass('wcmcs-bulk-row-active');
            $row.find('.wcmcs-bulk-result').html('<span class="wcmcs-bulk-spinner"></span> ' + (wcmcsAdmin.i18n.bulk_processing || '...'));

            $.post(wcmcsAdmin.ajaxUrl, {
                action: 'wcmcs_bulk_sync_process',
                order_id: orderId,
                force_carrier: forceCarrier,
                nonce: wcmcsAdmin.nonce
            }, function (response) {
                processed++;
                var data = response.data || {};
                var isSuccess = response.success;

                if (isSuccess) {
                    successes++;
                    $row.removeClass('wcmcs-bulk-row-active').addClass('wcmcs-bulk-row-done');
                    $row.find('.wcmcs-bulk-result').html('✓ ' + $('<span>').text(data.message || wcmcsAdmin.i18n.success).html());
                    if (data.tracking_id) {
                        $row.find('.wcmcs-bulk-result').append(' <code>' + $('<span>').text(data.tracking_id).html() + '</code>');
                    }
                } else {
                    failures++;
                    $row.removeClass('wcmcs-bulk-row-active').addClass('wcmcs-bulk-row-failed');
                    $row.find('.wcmcs-bulk-result').html('✗ ' + $('<span>').text(data.message || wcmcsAdmin.i18n.error).html());
                }

                if (data.columns) {
                    updateOrderColumns(orderId, data.columns);
                }

                var pct = Math.round((processed / total) * 100);
                $('.wcmcs-bulk-progress-fill').css('width', pct + '%');
                $('.wcmcs-bulk-progress-text').text(
                    processed + '/' + total + ' — ' + successes + ' ✓, ' + failures + ' ✗'
                );

                processNext();
            }).fail(function () {
                processed++;
                failures++;
                $row.removeClass('wcmcs-bulk-row-active').addClass('wcmcs-bulk-row-failed');
                $row.find('.wcmcs-bulk-result').html('✗ ' + wcmcsAdmin.i18n.network_error);

                var pct = Math.round((processed / total) * 100);
                $('.wcmcs-bulk-progress-fill').css('width', pct + '%');
                $('.wcmcs-bulk-progress-text').text(
                    processed + '/' + total + ' — ' + successes + ' ✓, ' + failures + ' ✗'
                );

                processNext();
            });
        }

        processNext();
    }

    function finishBulkSync(successes, failures, total) {
        bulkRunning = false;
        $('#wcmcs-bulk-start').hide();
        $('#wcmcs-bulk-cancel').hide();
        $('#wcmcs-bulk-close').show();

        var summary = wcmcsAdmin.i18n.bulk_done + ' — ' + successes + ' ✓, ' + failures + ' ✗';
        $('.wcmcs-bulk-progress-text').text(summary);
    }

    function closeBulkSyncModal() {
        if (bulkRunning) {
            bulkRunning = false;
        }
        $('.wcmcs-bulk-overlay').remove();
    }

    $(document).on('submit', '#posts-filter, #wc-orders-filter', function (e) {
        var $form = $(this);
        var action = $form.find('select[name="action"]').val();
        var action2 = $form.find('select[name="action2"]').val();
        if (action !== 'wcmcs_sync' && action2 !== 'wcmcs_sync') {
            return true;
        }

        e.preventDefault();

        var orderIds = getSelectedOrderIds();
        if (!orderIds.length) {
            alert(wcmcsAdmin.i18n.bulk_no_orders);
            return false;
        }

        openBulkSyncModal(orderIds);
        return false;
    });

    $(document).on('click', '#wcmcs-bulk-start', function () {
        startBulkSync();
    });

    $(document).on('click', '#wcmcs-bulk-cancel, .wcmcs-bulk-x', function () {
        closeBulkSyncModal();
    });

    $(document).on('click', '#wcmcs-bulk-close', function () {
        closeBulkSyncModal();
        location.reload();
    });

    $(document).on('click', '.wcmcs-bulk-overlay', function (e) {
        if (e.target === this && !bulkRunning) {
            closeBulkSyncModal();
        }
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && !bulkRunning) {
            closeBulkSyncModal();
        }
    });

    // ─────────────────────────────────────────────
    // Grille « Apparence des statuts » (page réglages) : aperçu badge live.
    // ─────────────────────────────────────────────
    (function initStatusDesign() {
        var $grid = $('.wcmcs-status-design');
        if (!$grid.length) { return; }

        function designYiq(hex) {
            var m = /^#?([0-9a-fA-F]{6})$/.exec(hex || '');
            if (!m) { return 0; }
            var h = m[1];
            return parseInt(h.substr(0, 2), 16) * 0.299
                + parseInt(h.substr(2, 2), 16) * 0.587
                + parseInt(h.substr(4, 2), 16) * 0.114;
        }

        function refreshDesignRow($row) {
            var c = $row.find('.wcmcs-design-color').val() || '#777777';
            var $chip = $row.find('.wcmcs-design-chip');
            var lang = $grid.data('curlang') || 'fr';
            var $inp = $row.find('.wcmcs-design-label[data-lang="' + lang + '"]');
            var label = $inp.length
                ? ($inp.val() ? $inp.val() : ($inp.attr('placeholder') || $chip.text()))
                : $chip.text();
            $chip.text(label).css({
                background: c,
                color: designYiq(c) >= 150 ? '#1e1e1e' : '#ffffff'
            });
        }

        $grid.find('tr[data-key]').each(function () {
            refreshDesignRow($(this));
        });

        $grid.on('input change', '.wcmcs-design-color', function () {
            refreshDesignRow($(this).closest('tr'));
        });
        $grid.on('input', '.wcmcs-design-label', function () {
            refreshDesignRow($(this).closest('tr'));
        });
        $grid.on('click', '.wcmcs-design-reset', function () {
            var $row = $(this).closest('tr');
            $row.find('.wcmcs-design-color').val($(this).data('default'));
            refreshDesignRow($row);
        });
    })();

    // Injection bouton scan des statuts sur la page commandes.
    injectScanButton();

}(jQuery));