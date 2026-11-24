/* Alertes « nouvelle commande » sur toutes les pages de l'admin (COD Tracker CRM). */
(function ($) {
    'use strict';
    var C = window.codcrmNotify;
    if (!C) { return; }
    var NOTIF = 'codcrm_notified', CUR = 'codcrm_notify_cursor', UNSEEN = 'codcrm_unseen', MUTE = 'codcrm_mute';
    var i18n = C.i18n;
    function ls(k, v) {
        try { if (v === undefined) { return window.localStorage.getItem(k); } window.localStorage.setItem(k, v); } catch (e) { return null; }
        return null;
    }
    function notified() { try { return JSON.parse(ls(NOTIF) || '{}'); } catch (e) { return {}; } }
    function markNotified(id) {
        var n = notified(), t = Date.now(); n[id] = t;
        $.each(n, function (k, v) { if (t - v > 86400000) { delete n[k]; } });
        ls(NOTIF, JSON.stringify(n));
    }

    // ── Pastille : barre d'admin + menu « Suivi COD » ─────────
    function unseen() { return parseInt(ls(UNSEEN) || '0', 10) || 0; }
    function badge() {
        var n = unseen(), txt = n > 99 ? '99+' : String(n);
        $('.codcrm-ab-count').prop('hidden', !n).text(txt);
        var $menu = $('#toplevel_page_codcrm-queue .wp-menu-name');
        $menu.find('.codcrm-menu-count').remove();
        if (n) { $menu.append(' <span class="awaiting-mod codcrm-menu-count">' + txt + '</span>'); }
    }
    if (C.onQueue) { ls(UNSEEN, '0'); } // l'agent est sur la liste : tout est vu.
    badge();
    $(window).on('storage', function (e) { if (e.originalEvent && e.originalEvent.key === UNSEEN) { badge(); } });

    // ── Notifications de bureau + son (geste de l'utilisateur requis) ──
    function perm() { return 'Notification' in window ? Notification.permission : 'denied'; }
    function permItem() {
        var $li = $('#wp-admin-bar-codcrm-notify-perm');
        if (perm() === 'granted') { $li.hide(); } else if (perm() === 'default') { $li.show(); } else { $li.show().find('a').text(i18n.blocked); }
    }
    permItem();
    $(document).on('click', '#wp-admin-bar-codcrm-notify-perm a', function (e) {
        e.preventDefault();
        if (!('Notification' in window) || perm() === 'denied') { window.alert(i18n.blocked); return; }
        Notification.requestPermission().then(function () { permItem(); if (perm() === 'granted') { window.alert(i18n.enabled); } });
    });
    $(document).one('click keydown', function () { if (window.codcrmSound) { window.codcrmSound.unlock(); } });

    function toast(it) {
        var label = it.abandoned ? i18n.abandoned : i18n['new'];
        var $t = $('<a class="codcrm-notify-toast" role="alert"></a>').attr('href', C.queueUrl)
            .text('🔔 ' + label + ' #' + it.number + (it.phone ? ' — ' + it.phone : ''));
        $('.codcrm-notify-toast').slice(2).remove();
        $t.appendTo('body'); setTimeout(function () { $t.addClass('is-in'); }, 10);
        setTimeout(function () { $t.removeClass('is-in'); setTimeout(function () { $t.remove(); }, 300); }, 8000);
    }
    function announce(items) {
        var n = notified(), fresh = $.grep(items, function (it) { return !n[it.id]; });
        if (!fresh.length) { return; }
        $.each(fresh, function (_, it) { markNotified(it.id); });
        ls(UNSEEN, String(unseen() + fresh.length)); badge();
        toast(fresh[0]);
        var allAb = $.grep(fresh, function (it) { return it.abandoned; }).length === fresh.length;
        var muted = ls(MUTE) === '1';
        if (C.sound !== 'none' && !muted && (C.soundAbandoned || !allAb) && window.codcrmSound) { window.codcrmSound.play(C.sound, C.volume); }
        if (perm() === 'granted' && document.visibilityState === 'hidden') {
            var it0 = fresh[0];
            try {
                var nt = new Notification((it0.abandoned ? i18n.abandoned : i18n['new']) + ' #' + it0.number, { body: it0.phone + (fresh.length > 1 ? ' (+' + (fresh.length - 1) + ')' : ''), tag: 'codcrm-' + it0.id });
                nt.onclick = function () { window.focus(); window.location.href = C.queueUrl; nt.close(); };
            } catch (e) { /* notification indisponible */ }
        }
    }

    // ── Sondage (désactivé sur « Mes suivis » : la liste en direct s'en charge) ──
    if (C.onQueue) { return; }
    var cursor = null;
    try { cursor = JSON.parse(ls(CUR) || 'null'); } catch (e) { cursor = null; }
    if (!cursor || !cursor.utc) { cursor = C.now; }
    var running = false, fails = 0;
    function poll() {
        if (running) { return; }
        running = true;
        $.post(C.ajax, { action: 'codcrm_notify_poll', nonce: C.nonce, since_utc: cursor.utc, since_db: cursor.db })
            .done(function (r) {
                if (r && r.success) { fails = 0; cursor = r.data.now; ls(CUR, JSON.stringify(cursor)); announce(r.data.items || []); }
                else { fails++; }
            }).fail(function () { fails++; })
            .always(function () { running = false; schedule(); });
    }
    function schedule() {
        var base = document.visibilityState === 'hidden' ? 20000 : 10000;
        setTimeout(poll, Math.min(120000, base * (1 + Math.min(fails, 5))));
    }
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') { poll(); } });
    schedule();
})(jQuery);
