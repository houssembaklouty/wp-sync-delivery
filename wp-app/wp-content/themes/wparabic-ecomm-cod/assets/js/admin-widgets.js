/**
 * Selecteur d'image dans les formulaires de widgets.
 *
 * Les formulaires de widgets sont clones et re-rendus dynamiquement (ajout,
 * sauvegarde AJAX, personnalisateur) : tous les gestionnaires sont donc
 * DELEGUES sur document, jamais lies aux elements au chargement.
 *
 * @package wparabic-ecomm-cod
 */
(function ($) {
	'use strict';

	var frame = null;

	$(document).on('click', '.wparabic-media-choose', function (event) {
		event.preventDefault();

		var $field = $(this).closest('.wparabic-media-field');

		// Une seule instance de la mediatheque, reciblee a chaque ouverture.
		if (frame) {
			frame.close();
		}

		frame = wp.media({
			title: $(this).text(),
			library: { type: 'image' },
			button: { text: $(this).text() },
			multiple: false
		});

		frame.on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();
			var url = (attachment.sizes && attachment.sizes.medium)
				? attachment.sizes.medium.url
				: attachment.url;

			$field.find('.wparabic-media-id').val(attachment.id).trigger('change');
			$field.find('.wparabic-media-preview').attr('src', url).show();
			$field.find('.wparabic-media-remove').show();
		});

		frame.open();
	});

	$(document).on('click', '.wparabic-media-remove', function (event) {
		event.preventDefault();

		var $field = $(this).closest('.wparabic-media-field');

		$field.find('.wparabic-media-id').val('').trigger('change');
		$field.find('.wparabic-media-preview').attr('src', '').hide();
		$(this).hide();
	});
	/* --- Reglages propres a chaque modele d'affichage -------------------
	 * .wparabic-layout-fields[data-layouts="grid,circles"] n'est visible que
	 * si le modele choisi (select de .wparabic-layout-switch) est dans la
	 * liste. Delegue et rejoue a chaque (re)rendu de formulaire.
	 */
	function syncLayoutFields(root) {
		$(root).find('.wparabic-layout-switch select').each(function () {
			var layout = $(this).val();
			var $form = $(this).closest('.widget-content, form, .widget-inside');
			$form.find('.wparabic-layout-fields').each(function () {
				var allowed = String($(this).attr('data-layouts') || '').split(',');
				$(this).toggle(allowed.indexOf(layout) !== -1);
			});
		});
	}

	$(document).on('change', '.wparabic-layout-switch select', function () {
		syncLayoutFields($(this).closest('.widget-content, form, .widget-inside'));
	});
	$(document).on('widget-added widget-updated', function (event, $widget) {
		syncLayoutFields($widget);
	});
	$(function () {
		syncLayoutFields(document);
	});
	// Personnalisateur : les formulaires sont rendus a l'ouverture d'une section.
	$(document).on('expanded', '.customize-control-widget_form', function () {
		syncLayoutFields(this);
	});
	$(document).on('click', '.customize-control-widget_form .widget-top', function () {
		var control = $(this).closest('.customize-control-widget_form');
		setTimeout(function () { syncLayoutFields(control); }, 50);
	});
	/* --- Reglages du carrousel : visibles seulement si la case est cochee ----
	 * .wparabic-scroll-fields (auto-play, duree) suit la case
	 * [...][scroll]. Delegue et rejoue comme syncLayoutFields.
	 */
	function syncScrollFields(root) {
		$(root).find('input[type="checkbox"][name$="[scroll]"]').each(function () {
			var $form = $(this).closest('.widget-content, form, .widget-inside');
			$form.find('.wparabic-scroll-fields').toggle(this.checked);
		});
	}

	$(document).on('change', 'input[type="checkbox"][name$="[scroll]"]', function () {
		syncScrollFields($(this).closest('.widget-content, form, .widget-inside'));
	});

	$(document).on('widget-added widget-updated', function (event, $widget) {
		syncScrollFields($widget);
	});
	$(function () {
		syncScrollFields(document);
	});
	$(document).on('expanded', '.customize-control-widget_form', function () {
		syncScrollFields(this);
	});
	$(document).on('click', '.customize-control-widget_form .widget-top', function () {
		var control = $(this).closest('.customize-control-widget_form');
		setTimeout(function () { syncScrollFields(control); }, 50);
	});
})(jQuery);
