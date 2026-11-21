/* global jQuery */
(function ($) {
    'use strict';

    // ── Horaires : copier le lundi ─────────────────────────────
    function copyMonday($agent, days) {
        var $mon = $agent.find('tr[data-day="1"] input[type=time]');
        $.each(days, function (_, d) {
            $agent.find('tr[data-day="' + d + '"] input[type=time]').each(function (i) {
                $(this).val($mon.eq(i).val());
            });
        });
    }
    $(document).on('click', '.codcrm-copy-monday', function () {
        copyMonday($(this).closest('.codcrm-agent'), [2, 3, 4, 5, 6, 7]);
    });
    $(document).on('click', '.codcrm-copy-weekdays', function () {
        copyMonday($(this).closest('.codcrm-agent'), [2, 3, 4, 5, 6]);
    });

    var nextIndex = 0; // prochain index de condition (onglet Règles)

    // ── Parts en pourcentage : total en direct ─────────────────
    function refreshTotal() {
        var $p = $('.codcrm-total-w');
        if (!$p.length) { return; }
        var total = 0;
        $('.codcrm-agent').each(function () {
            if ($(this).find('input[type=checkbox][name$="[active]"]').is(':checked')) {
                total += parseInt($(this).find('.codcrm-w').val(), 10) || 0;
            }
        });
        $p.find('.codcrm-total-v').text(total + ' %');
        $p.toggleClass('codcrm-total-bad', total !== 100);
    }
    function toggleWeights() {
        var weighted = $('input[name=method]:checked').val() === 'weighted';
        $('.codcrm-weight, .codcrm-total-w').toggle(weighted);
    }
    $(document).on('input change', '.codcrm-w, .codcrm-agent input[type=checkbox]', refreshTotal);
    $(document).on('change', 'input[name=method]', toggleWeights);

    // ── Règles : conditions ────────────────────────────────────
    function showWidget($row) {
        var type = $row.find('.codcrm-cond-type').val();
        $row.attr('data-type', type);
        $row.find('[class^="codcrm-w-"]').hide();
        $row.find('.codcrm-w-' + type).show();
    }

    $(document).on('change', '.codcrm-cond-type', function () {
        showWidget($(this).closest('.codcrm-cond'));
    });

    $(document).on('click', '.codcrm-cond-add', function () {
        var tpl = $('#codcrm-cond-tpl').html().replace(/__i__/g, String(nextIndex++));
        var $row = $(tpl);
        $('.codcrm-conds').append($row);
        showWidget($row);
        // Initialise les sélecteurs WooCommerce (select2) du nouveau bloc.
        $(document.body).trigger('wc-enhanced-select-init');
    });

    $(document).on('click', '.codcrm-cond-del', function () {
        if ($('.codcrm-cond').length > 1) {
            $(this).closest('.codcrm-cond').remove();
        }
    });

    $(document).on('click', '.codcrm-del', function (e) {
        if (!window.confirm('Supprimer cette règle ?')) { e.preventDefault(); }
    });

    // ── Onglet Messages : compteur de caractères / segments SMS ──
    var GSM7 = /^[A-Za-z0-9 \r\n@£$¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ!"#¤%&'()*+,\-.\/:;<=>?¡ÄÖÑÜ§¿äöñüà^{}\\\[~\]|€]*$/;
    function countTpl($ta) {
        var t = $ta.val() || '', $c = $ta.closest('.codcrm-tpl-box').find('.codcrm-tpl-count');
        if ($ta.data('ch') === 'sms') {
            var gsm = GSM7.test(t), single = gsm ? 160 : 70, multi = gsm ? 153 : 67;
            var parts = t.length <= single ? (t.length ? 1 : 0) : Math.ceil(t.length / multi);
            $c.text(t.length + ' car. · ' + parts + ' SMS' + (gsm ? '' : ' (arabe / caractères spéciaux)')).toggleClass('is-long', parts > 2);
        } else {
            $c.text(t.length + ' car.');
        }
    }
    $(document).on('input', '.codcrm-tpl-text', function () {
        countTpl($(this));
        // Toucher au texte décoche « rétablir » (sinon le texte saisi serait ignoré).
        $(this).closest('.codcrm-tpl-box').find('input[type=checkbox]').prop('checked', false);
    });

    // ── Initialisation d'un onglet (au chargement ET après chaque navigation) ──
    function initTab() {
        refreshTotal();
        toggleWeights();
        $('.codcrm-cond').each(function () { showWidget($(this)); });
        nextIndex = $('.codcrm-cond').length;
        $('.codcrm-tpl-text').each(function () { countTpl($(this)); });
        // Sélecteurs WooCommerce (select2 / recherche de produits) du nouveau contenu.
        $(document.body).trigger('wc-enhanced-select-init');
    }

    // ── Navigation sans rechargement : onglets, situations, « modifier / créer une règle » ──
    // Seuls les liens de la page de réglages (hors admin-post.php : suppression, activation…)
    // sont interceptés ; les formulaires et les actions gardent leur comportement normal.
    function isTabLink(a) {
        if (!a || !a.href || a.target === '_blank' || a.hasAttribute('download')) { return false; }
        var u;
        try { u = new URL(a.href, window.location.href); } catch (e) { return false; }
        return u.origin === window.location.origin
            && u.pathname.indexOf('admin.php') !== -1
            && u.searchParams.get('page') === 'codcrm-settings';
    }

    var navSeq = 0;
    function loadTab(url, push) {
        var seq = ++navSeq;
        var $wrap = $('.codcrm-settings');
        $wrap.addClass('codcrm-loading');

        window.fetch(url, { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) { throw new Error('http'); } return r.text(); })
            .then(function (html) {
                if (seq !== navSeq) { return; } // une navigation plus récente a pris le relais.
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var $newWrap = $(doc).find('.codcrm-settings').first();
                if (!$newWrap.length) { throw new Error('parse'); }

                $wrap.find('.nav-tab-wrapper').replaceWith($newWrap.find('.nav-tab-wrapper'));
                $wrap.find('.codcrm-tab').replaceWith($newWrap.find('.codcrm-tab'));
                $wrap.find('> .notice').remove(); // les notices « enregistré » concernent l'écran précédent.

                if (push) { window.history.pushState({ codcrm: 1 }, '', url); }
                initTab();
            })
            .catch(function () {
                if (seq === navSeq) { window.location.href = url; } // repli : navigation classique.
            })
            .then(function () { if (seq === navSeq) { $wrap.removeClass('codcrm-loading'); } });
    }

    $(document).on('click', '.codcrm-settings a', function (e) {
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.which > 1) { return; } // nouvel onglet : normal.
        if (!window.fetch || !window.DOMParser || !window.URL || !isTabLink(this)) { return; }
        e.preventDefault();
        loadTab(this.href, true);
    });

    // Bouton « Précédent / Suivant » du navigateur.
    window.addEventListener('popstate', function () {
        if ($('.codcrm-settings').length) { loadTab(window.location.href, false); }
    });

    initTab();
})(jQuery);
