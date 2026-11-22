/* Envois groupés : progression en direct + liens WhatsApp tracés. */
(function ($) {
    'use strict';
    var cfg = window.codcrmBulk;
    if (!cfg || !cfg.job) { return; }

    function post(data) { return $.post(cfg.ajax, $.extend({ nonce: cfg.nonce }, data)); }

    var timer = null, $box = $('#codcrm-bulk-box');
    function poll() {
        post({ action: 'codcrm_bulk_status', job: cfg.job }).done(function (r) {
            if (!r || !r.success) { return; }
            var d = r.data, c = d.counts, done = c.sent + c.failed + c.skipped;
            $('#codcrm-bulk-status').text(d.label);
            $('#codcrm-bulk-box [data-k=sent]').text(c.sent);
            $('#codcrm-bulk-box [data-k=pending]').text(c.pending + c.sending);
            $('#codcrm-bulk-box [data-k=failed]').text(c.failed);
            $('#codcrm-bulk-box [data-k=skipped]').text(c.skipped);
            $('.codcrm-bulk-bar .codcrm-bar span').css('width', (d.total ? Math.min(100, Math.round(100 * done / d.total)) : 0) + '%');
            if (d.status !== $box.data('status')) {
                clearInterval(timer);
                // Changement d'état (préparation terminée, fin, pause) : recharge boutons et listes.
                location.reload();
            }
        });
    }
    var st0 = $box.data('status');
    if ($box.length && (st0 === 'preparing' || (st0 === 'running' && !$box.data('wa')))) {
        timer = setInterval(poll, 5000);
    }

    // WhatsApp : la fenêtre est ouverte dans le clic (sinon bloquée), puis dirigée vers wa.me.
    $(document).on('click', '.codcrm-bulk-wa', function (e) {
        e.preventDefault();
        var $b = $(this), $tr = $b.closest('tr'), w = window.open('', '_blank');
        $b.prop('disabled', true);
        post({ action: 'codcrm_bulk_wa', job: cfg.job, order_id: $tr.data('order') }).done(function (r) {
            if (r && r.success) {
                if (w) { w.location.href = r.data.url; } else { location.href = r.data.url; }
                $tr.find('.codcrm-bulk-st').text('✓');
                $b.text('↻').prop('disabled', false);
            } else {
                if (w) { w.close(); }
                alert((r && r.data && r.data.message) || cfg.error);
                $b.prop('disabled', false);
            }
        }).fail(function () { if (w) { w.close(); } alert(cfg.error); $b.prop('disabled', false); });
    });
})(jQuery);
