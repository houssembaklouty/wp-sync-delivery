/* global jQuery, codcrmScan, Html5Qrcode */
(function ($) {
    'use strict';

    var i18n = codcrmScan.i18n;
    var items = [];          // { code, order_id, number, client, ok, warn, msg, status }
    var codes = {};          // dédoublonnage par code scanné
    var camera = null;
    var lastCamCode = '', lastCamAt = 0;
    var busy = false;

    function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
    function mode() { return $('input[name=codcrm_mode]:checked').val(); }

    function beep(ok) {
        try {
            var C = window.AudioContext || window.webkitAudioContext;
            var ctx = new C(), o = ctx.createOscillator(), g = ctx.createGain();
            o.frequency.value = ok ? 880 : 220; g.gain.value = 0.08;
            o.connect(g); g.connect(ctx.destination); o.start();
            setTimeout(function () { o.stop(); ctx.close(); }, ok ? 120 : 350);
        } catch (e) { /* audio indisponible */ }
        if (navigator.vibrate) { navigator.vibrate(ok ? 60 : [120, 60, 120]); }
    }

    function render() {
        var ok = 0, ko = 0, html = '';
        $.each(items, function (i, it) {
            if (it.status === 'DONE') { /* compté ci-dessous */ }
            var cls = it.status === 'DONE' ? 'codcrm-done' : (it.ok ? (it.warn ? 'codcrm-warn' : 'codcrm-ok') : 'codcrm-ko');
            if (it.status !== 'DONE') { if (it.ok) { ok++; } else { ko++; } }
            html += '<tr class="' + cls + '"><td><code>' + esc(it.code) + '</code></td>'
                + '<td>' + (it.number ? '#' + esc(it.number) : '—') + '</td>'
                + '<td>' + esc(it.client) + '</td>'
                + '<td>' + (it.status === 'DONE' ? '✓ ' : (it.ok ? (it.warn ? '' : '✓ ') : '✗ ')) + esc(it.msg) + '</td>'
                + '<td>' + (it.status === 'DONE' ? '' : '<a href="#" class="codcrm-rm" data-i="' + i + '">✕</a>') + '</td></tr>';
        });
        $('#codcrm-list tbody').html(html);
        $('#codcrm-sum').html('<strong>' + ok + '</strong> ' + esc(i18n.ok_word) + ' · <strong>' + ko + '</strong> ' + esc(i18n.ko_word));
        $('#codcrm-apply').prop('disabled', ok === 0);
    }

    function addScan(code) {
        code = $.trim(code);
        if (!code || busy) { return; }
        if (codes[code]) { beep(false); flash(i18n.dup); return; }
        if (items.length >= codcrmScan.max) { beep(false); flash(i18n.full); return; }

        busy = true;
        $.post(codcrmScan.ajax, { action: 'codcrm_scan_check', nonce: codcrmScan.nonce, mode: mode(), code: code })
            .done(function (r) {
                var d = (r && r.success) ? r.data : { ok: false, msg: (r && r.data && r.data.message) || i18n.net };
                // Un même colis scanné via deux codes (n° de commande puis code-barres) = doublon.
                if (d.order_id && codes['#' + d.order_id]) { beep(false); flash(i18n.dup); return; }
                codes[code] = true;
                if (d.order_id) { codes['#' + d.order_id] = true; }
                items.unshift({ code: code, order_id: d.order_id || 0, number: d.number || '', client: d.client || '', ok: !!d.ok, warn: !!d.warn, msg: d.msg || '', status: 'PENDING' });
                beep(!!d.ok);
                render();
            })
            .fail(function () { beep(false); flash(i18n.net); })
            .always(function () { busy = false; $('#codcrm-code').val('').trigger('focus'); });
    }

    function flash(msg) {
        $('#codcrm-sum').after('<span class="codcrm-flash"> ' + esc(msg) + '</span>');
        setTimeout(function () { $('.codcrm-flash').remove(); }, 2500);
    }

    // Saisie / douchette USB : Entrée valide.
    $('#codcrm-code').on('keydown', function (e) {
        if (e.which === 13) { e.preventDefault(); addScan($(this).val()); }
    });

    // Changer de mode : les vérifications dépendent du mode → on repart d'une liste vide.
    $('input[name=codcrm_mode]').on('change', function () {
        items = []; codes = {}; render(); $('#codcrm-code').trigger('focus');
    });

    $(document).on('click', '.codcrm-rm', function (e) {
        e.preventDefault();
        var it = items.splice($(this).data('i'), 1)[0];
        if (it) { delete codes[it.code]; if (it.order_id) { delete codes['#' + it.order_id]; } }
        render();
    });

    $('#codcrm-clear').on('click', function () { items = []; codes = {}; render(); $('#codcrm-code').trigger('focus'); });

    $('#codcrm-apply').on('click', function () {
        var ids = [];
        $.each(items, function (i, it) { if (it.ok && it.status !== 'DONE' && it.order_id) { ids.push(it.order_id); } });
        if (!ids.length) { flash(i18n.none); return; }

        var $b = $(this).prop('disabled', true);
        $.post(codcrmScan.ajax, { action: 'codcrm_scan_apply', nonce: codcrmScan.nonce, mode: mode(), order_ids: ids })
            .done(function (r) {
                if (!r || !r.success) { flash((r && r.data && r.data.message) || i18n.net); return; }
                var done = 0;
                $.each(items, function (i, it) {
                    var res = r.data.results[it.order_id];
                    if (res && it.ok) {
                        it.status = res.status === 'DONE' ? 'DONE' : 'PENDING';
                        it.msg = res.msg;
                        if (res.status !== 'DONE') { it.ok = false; } else { done++; }
                    }
                });
                render();
                flash(done + ' ' + i18n.validated);
            })
            .fail(function () { flash(i18n.net); })
            .always(function () { $b.prop('disabled', false); render(); });
    });

    // Caméra.
    function setCamLabel() { $('#codcrm-cam').text(camera ? i18n.cam_off : i18n.cam_on); }
    setCamLabel();

    $('#codcrm-cam').on('click', function () {
        if (camera) {
            camera.stop().then(function () { camera.clear(); camera = null; $('#codcrm-reader').hide(); setCamLabel(); });
            return;
        }
        $('#codcrm-reader').show();
        var h = new Html5Qrcode('codcrm-reader');
        h.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 260, height: 160 } }, function (text) {
            // La caméra relit le même code en boucle : on ignore la répétition < 3 s.
            var now = Date.now();
            if (text === lastCamCode && now - lastCamAt < 3000) { return; }
            lastCamCode = text; lastCamAt = now;
            addScan(text);
        }, function () { /* pas de code dans l'image */ })
            .then(function () { camera = h; setCamLabel(); })
            .catch(function () { $('#codcrm-reader').hide(); flash(i18n.cam_err); });
    });

    render();
})(jQuery);
