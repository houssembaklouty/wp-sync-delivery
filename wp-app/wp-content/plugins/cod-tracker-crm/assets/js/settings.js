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
    refreshTotal();
    toggleWeights();

    // ── Règles : conditions ────────────────────────────────────
    function showWidget($row) {
        var type = $row.find('.codcrm-cond-type').val();
        $row.attr('data-type', type);
        $row.find('[class^="codcrm-w-"]').hide();
        $row.find('.codcrm-w-' + type).show();
    }
    $('.codcrm-cond').each(function () { showWidget($(this)); });

    $(document).on('change', '.codcrm-cond-type', function () {
        showWidget($(this).closest('.codcrm-cond'));
    });

    var nextIndex = $('.codcrm-cond').length;
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
})(jQuery);
