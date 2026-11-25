/* Alertes « nouvelle commande » (COD Tracker CRM) : module commun à toutes les pages de l'admin.
 * window.codcrmAlert.announce(items, opts) : anti-doublon (tous onglets), pastille, son, bandeau, notification de bureau. */
(function ($) {
    'use strict';
    var ic = function (n, c) { return window.codcrmIcons ? window.codcrmIcons.svg(n, c) : ''; };
    var C = window.codcrmNotify;
    if (!C) { return; }
    var NOTIF = 'codcrm_notified', CUR = 'codcrm_notify_cursor', UNSEEN = 'codcrm_unseen', MUTE = 'codcrm_mute', LATER = 'codcrm_alert_later';
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
    function perm() { return 'Notification' in window ? Notification.permission : 'unsupported'; }
    /** L'agent ne regarde pas cette page : autre onglet, autre site, autre fenêtre. */
    function away() { return document.hidden || (typeof document.hasFocus === 'function' && !document.hasFocus()); }

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

    // ── Son : bloqué par le navigateur tant qu'on n'a pas cliqué sur la page ──
    function soundState() {
        var off = C.sound !== 'none' && ls(MUTE) !== '1' && !(window.codcrmSound && window.codcrmSound.ready());
        $('#wp-admin-bar-codcrm-notify').toggleClass('codcrm-snd-off', off)
            .children('a').attr('title', off ? i18n.soundOff : i18n.title);
    }
    function unlockAll() {
        if (window.codcrmSound) { window.codcrmSound.unlock().then(soundState); }
    }
    $(document).one('click keydown touchstart', unlockAll);
    setTimeout(soundState, 300);

    // ── Bandeau « Activer les alertes » (tant que les notifications ne sont pas autorisées) ──
    function banner() {
        $('#codcrm-alert-bar').remove();
        var p = perm();
        if (p === 'granted' || p === 'unsupported' || (parseInt(ls(LATER) || '0', 10) > Date.now())) { return; }
        var $b = $('<div id="codcrm-alert-bar" role="region"></div>');
        if (p === 'denied') {
            $b.html('<span>' + ic('bell-slash') + ' ' + i18n.blocked + '</span>');
        } else {
            $b.html('<span>' + ic('bell') + ' ' + i18n.bannerText + '</span>');
            $('<button type="button" class="button button-primary"></button>').text(i18n.enableAll).appendTo($b).on('click', function () {
                unlockAll();
                Notification.requestPermission().then(function () { banner(); soundState(); if (perm() === 'granted') { window.codcrmAlert.test(); } });
            });
        }
        $('<button type="button" class="button-link codcrm-alert-later"></button>').text(i18n.later).appendTo($b).on('click', function () {
            ls(LATER, String(Date.now() + 7 * 86400000)); $b.remove();
        });
        $('#wpbody-content').prepend($b);
    }
    banner();
    function permItem() {
        var $li = $('#wp-admin-bar-codcrm-notify-perm');
        if (perm() === 'granted') { $li.hide(); } else if (perm() === 'default') { $li.show(); } else { $li.show().find('a').text(i18n.blocked); }
    }
    permItem();
    $(document).on('click', '#wp-admin-bar-codcrm-notify-perm a', function (e) {
        e.preventDefault(); unlockAll();
        if (perm() === 'denied' || perm() === 'unsupported') { window.alert(i18n.blocked); return; }
        Notification.requestPermission().then(function () { permItem(); banner(); });
    });

    // ── Annonce ───────────────────────────────────────────────
    function toast(it, more) {
        var label = it.abandoned ? i18n.abandoned : i18n['new'];
        var $t = $('<a class="codcrm-notify-toast" role="alert"></a>').attr('href', C.queueUrl)
            .html(ic('bell') + ' ').append(document.createTextNode(label + ' #' + it.number + (it.phone ? ' — ' + it.phone : '') + (more ? ' (+' + more + ')' : '')));
        $('.codcrm-notify-toast').slice(2).remove();
        $t.appendTo('body'); setTimeout(function () { $t.addClass('is-in'); }, 10);
        setTimeout(function () { $t.removeClass('is-in'); setTimeout(function () { $t.remove(); }, 300); }, 8000);
    }
    function desktop(it, more) {
        if (perm() !== 'granted') { return false; }
        try {
            var nt = new Notification((it.abandoned ? i18n.abandoned : i18n['new']) + ' #' + it.number, {
                body: (it.phone || '') + (more ? ' (+' + more + ')' : ''), tag: 'codcrm-' + it.id, renotify: true,
                requireInteraction: true, silent: false, icon: C.icon || undefined
            });
            nt.onclick = function () { window.focus(); if (!C.onQueue) { window.location.href = C.queueUrl; } nt.close(); };
            return true;
        } catch (e) { return false; }
    }
    window.codcrmAlert = {
        seen: function (id) { return !!notified()[id]; },
        mark: markNotified,
        /** items : [{id, number, phone, abandoned}] ; opts : {toast:true, badge:true} */
        announce: function (items, opts) {
            opts = $.extend({ toast: true, badge: true }, opts || {});
            var n = notified(), fresh = $.grep(items, function (it) { return !n[it.id]; });
            if (!fresh.length) { return []; }
            $.each(fresh, function (_, it) { markNotified(it.id); });
            var it0 = fresh[0], more = fresh.length - 1;
            if (opts.badge) { ls(UNSEEN, String(unseen() + fresh.length)); badge(); }
            if (opts.toast) { toast(it0, more); }
            var allAb = $.grep(fresh, function (it) { return it.abandoned; }).length === fresh.length;
            var wantSound = C.sound !== 'none' && ls(MUTE) !== '1' && (C.soundAbandoned || !allAb);
            // Onglet / fenêtre en arrière-plan : notification de bureau (avec le son du système, qui marche
            // même si le navigateur bloque le son de la page).
            if (away()) { desktop(it0, more); }
            if (wantSound && window.codcrmSound) { window.codcrmSound.play(C.sound, C.volume); }
            soundState();
            return fresh;
        },
        test: function () { desktop({ id: 'test', number: '—', phone: i18n.testBody, abandoned: false }, 0); },
        /** Minuterie non ralentie en arrière-plan (Web Worker), repli sur setTimeout. */
        loop: function (run, delay) {
            var w = null;
            try {
                w = new Worker(URL.createObjectURL(new Blob(['onmessage=function(e){setTimeout(function(){postMessage(1);},e.data);};'], { type: 'text/javascript' })));
            } catch (e) { w = null; }
            function next() { var ms = delay(); if (w) { w.postMessage(ms); } else { setTimeout(go, ms); } }
            function go() { run(next); }
            if (w) { w.onmessage = go; w.onerror = function () { w = null; next(); }; }
            return { start: next };
        }
    };

    // ── Sondage des autres pages (« Mes suivis » a sa propre liste en direct) ──
    if (C.onQueue) { return; }
    var cursor = null;
    try { cursor = JSON.parse(ls(CUR) || 'null'); } catch (e) { cursor = null; }
    if (!cursor || !cursor.utc) { cursor = C.now; }
    var fails = 0;
    var loop = window.codcrmAlert.loop(function (next) {
        $.post(C.ajax, { action: 'codcrm_notify_poll', nonce: C.nonce, since_utc: cursor.utc, since_db: cursor.db })
            .done(function (r) {
                if (r && r.success) { fails = 0; cursor = r.data.now; ls(CUR, JSON.stringify(cursor)); window.codcrmAlert.announce(r.data.items || []); }
                else { fails++; }
            }).fail(function () { fails++; })
            .always(next);
    }, function () { return Math.min(120000, (document.hidden ? 15000 : 10000) * (1 + Math.min(fails, 5))); });
    loop.start();
})(jQuery);
