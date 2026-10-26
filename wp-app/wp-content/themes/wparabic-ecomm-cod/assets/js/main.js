/**
 * WPArabicEcommCOD — comportements de l'interface.
 *
 * Reproduit le JS du theme de reference (hanout) sans ses dependances
 * (slick, sticky-kit) : chaque comportement tient en quelques lignes.
 *
 * @package wparabic-ecomm-cod
 */
(function ($) {
	'use strict';

	var cfg = window.wparabic_ajax || {};
	var I18N = cfg.i18n || {};

	// --- Selecteur de langue (inc/languages.php) -------------------------
	// Ouvre/ferme la liste ; ferme au clic exterieur et a Echap.
	(function () {
		var switchers = document.querySelectorAll('.lang-switcher');
		if (!switchers.length) {
			return;
		}

		function close(sw, focusToggle) {
			var toggle = sw.querySelector('.lang-switcher-toggle');
			sw.classList.remove('is-open');
			if (toggle) {
				toggle.setAttribute('aria-expanded', 'false');
				if (focusToggle) {
					toggle.focus();
				}
			}
		}

		Array.prototype.forEach.call(switchers, function (sw) {
			var toggle = sw.querySelector('.lang-switcher-toggle');
			sw.classList.add('js-ready');
			if (!toggle) {
				return;
			}
			toggle.addEventListener('click', function (event) {
				event.preventDefault();
				event.stopPropagation();
				var open = !sw.classList.contains('is-open');
				Array.prototype.forEach.call(switchers, function (other) {
					close(other, false);
				});
				if (open) {
					sw.classList.add('is-open');
					toggle.setAttribute('aria-expanded', 'true');
				}
			});
		});

		document.addEventListener('click', function (event) {
			Array.prototype.forEach.call(switchers, function (sw) {
				if (!sw.contains(event.target)) {
					close(sw, false);
				}
			});
		});

		document.addEventListener('keydown', function (event) {
			if (event.key !== 'Escape') {
				return;
			}
			Array.prototype.forEach.call(switchers, function (sw) {
				if (sw.classList.contains('is-open')) {
					close(sw, true);
				}
			});
		});
	})();
	var DECIMALS = typeof cfg.decimals !== 'undefined' ? parseInt(cfg.decimals, 10) : 2;

	function money(value) {
		var n = parseFloat(value);
		return isNaN(n) ? '0' : n.toFixed(DECIMALS);
	}

	function throttle(fn, wait) {
		var timer = null;
		return function () {
			var ctx = this;
			var args = arguments;
			clearTimeout(timer);
			timer = setTimeout(function () {
				fn.apply(ctx, args);
			}, wait);
		};
	}

	/* =====================================================================
	 * Barre haute : defilement continu (marquee)
	 * Le groupe de messages est duplique jusqu'a couvrir deux fois la largeur
	 * visible ; la piste glisse d'exactement un groupe puis recommence : la
	 * boucle est invisible. Vitesse en px/s (Personnaliser > الشريط العلوي),
	 * sens selon la langue : vers la droite en RTL, vers la gauche en LTR.
	 * ===================================================================== */
	function initTopMarquee() {
		var box = document.querySelector('.top-marquee');
		if (!box) {
			return;
		}
		var track = box.querySelector('.top-marquee-track');
		var group = box.querySelector('.top-marquee-group');
		if (!track || !group || !group.children.length) {
			return;
		}

		var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		if (reduce) {
			return; // texte fixe (CSS par defaut)
		}

		var speed = parseInt(box.getAttribute('data-speed'), 10) || 50;
		var rtl = (document.documentElement.getAttribute('dir') || '').toLowerCase() === 'rtl';

		var lastWidth = 0;

		function build(force) {
			// Le redimensionnement vertical (barre d'adresse mobile) ne doit
			// pas relancer le defilement : seule la largeur compte.
			if (force !== true && box.clientWidth === lastWidth) {
				return;
			}
			lastWidth = box.clientWidth;

			// Repart d'un seul groupe : la largeur visible a pu changer.
			Array.prototype.slice.call(track.querySelectorAll('.top-marquee-group[aria-hidden]')).forEach(function (clone) {
				track.removeChild(clone);
			});

			// .is-running pose white-space:nowrap : mesure sur une seule ligne.
			box.classList.add('is-running');
			track.style.animationName = 'none';

			var width = group.getBoundingClientRect().width;
			if (!width) {
				box.classList.remove('is-running');
				return;
			}

			var copies = Math.max(1, Math.ceil((box.clientWidth * 2) / width));
			for (var i = 0; i < copies; i++) {
				var clone = group.cloneNode(true);
				clone.setAttribute('aria-hidden', 'true');
				track.appendChild(clone);
			}

			track.style.setProperty('--marquee-shift', (rtl ? width : -width) + 'px');
			track.style.setProperty('--marquee-duration', (width / speed).toFixed(2) + 's');

			void track.offsetWidth; // relance l'animation depuis le debut
			track.style.animationName = '';
		}

		build(true);
		// Les polices (Cairo) changent la largeur du texte une fois chargees.
		if (document.fonts && document.fonts.ready) {
			document.fonts.ready.then(function () {
				build(true);
			});
		}
		$(window).on('resize', throttle(build, 200));
	}

	/* =====================================================================
	 * Section categories, modele « carrousel » (inc/home-widgets.php)
	 * Defilement natif au doigt ; les fleches (tablette et bureau) avancent
	 * d'environ une largeur. « Suivant » suit le sens de lecture : vers la
	 * gauche en RTL. Une fleche disparait en bout de course.
	 * ===================================================================== */
	function initCategoryCarousels() {
		var rtl = (document.documentElement.getAttribute('dir') || '').toLowerCase() === 'rtl';

		Array.prototype.forEach.call(document.querySelectorAll('[data-wparabic-carousel]'), function (box) {
			var track = box.querySelector('.wparabic-cats-track');
			var prev = box.querySelector('.wparabic-cats-nav--prev');
			var next = box.querySelector('.wparabic-cats-nav--next');
			if (!track || !prev || !next) {
				return;
			}

			function update() {
				var max = track.scrollWidth - track.clientWidth;
				var pos = Math.abs(track.scrollLeft); // negatif en RTL selon le navigateur
				prev.disabled = pos <= 2;
				next.disabled = pos >= max - 2;
			}

			function go(direction) {
				var step = track.clientWidth * 0.85 * direction;
				track.scrollBy({ left: rtl ? -step : step, behavior: 'smooth' });
			}

			prev.addEventListener('click', function () { go(-1); });
			next.addEventListener('click', function () { go(1); });
			track.addEventListener('scroll', throttle(update, 60), { passive: true });
			$(window).on('resize', throttle(update, 150));
			update();

			/* --- Defilement automatique (widgets produits, option « تحريك تلقائي »)
			 * data-autoplay-ms sur le wrapper. En boucle : en fin de piste on
			 * revient en douceur au debut. En pause au survol et pendant un
			 * appui (souris ou doigt), reprise deux secondes apres. On respecte
			 * prefers-reduced-motion : pas d'animation imposee. */
			if (box.getAttribute('data-autoplay') === '1' && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
				var delay = parseInt(box.getAttribute('data-autoplay-ms'), 10);
				if (!delay || delay < 1000) {
					delay = 4000;
				}
				var autoTimer = null;
				var resumeTimer = null;

				function autoStep() {
					var max = track.scrollWidth - track.clientWidth;
					var pos = Math.abs(track.scrollLeft);
					if (max <= 2) {
						return; // rien a faire defiler
					}
					if (pos >= max - 2) {
						track.scrollTo({ left: 0, behavior: 'smooth' }); // retour au debut
					} else {
						go(1);
					}
				}

				function autoStart() {
					window.clearTimeout(resumeTimer);
					if (!autoTimer) {
						autoTimer = window.setInterval(autoStep, delay);
					}
				}

				function autoStop() {
					window.clearInterval(autoTimer);
					autoTimer = null;
					window.clearTimeout(resumeTimer);
				}

				function autoPause() {
					autoStop();
					resumeTimer = window.setTimeout(autoStart, 2000);
				}

				box.addEventListener('mouseenter', autoStop);
				box.addEventListener('mouseleave', autoStart);
				box.addEventListener('pointerdown', autoPause, { passive: true });
				prev.addEventListener('click', autoPause);
				next.addEventListener('click', autoPause);
				autoStart();
			}
		});
	}

	/* =====================================================================
	 * Menu mobile — la classe attendue par le CSS est .open
	 * ===================================================================== */
	function initMobileMenu() {
		$('.mobile-nav-trigger').on('click', function () {
			$('#mobilemenu').toggleClass('open');
			$('.fly-menu-fade').toggleClass('active');
		});

		$('.fly-menu-fade, .close-btn').on('click', function () {
			$('#mobilemenu').removeClass('open');
			$('.fly-menu-fade').removeClass('active');
		});

		$('.mobile-menu-list .menu-item-has-children > a').on('click', function (event) {
			event.preventDefault();
			$(this).parent().children('.sub-menu').slideToggle();
			$(this).toggleClass('active');
		});
	}

	/* =====================================================================
	 * Recherche : voile deroulant
	 * ===================================================================== */
	function initSearchToggle() {
		$('.header-search-icon').on('click', function () {
			$('header.header .head-search-form-wrap').slideToggle(400);
		});
	}

	/* =====================================================================
	 * En-tete collant + bascule nom/prix du produit au defilement
	 * ===================================================================== */
	function initStickyHeader() {
		var $mast = $('.head-sticky');
		if (!$mast.length) {
			return;
		}

		$mast.css({ position: 'sticky', top: 0 });

		var $header = $('header.header');
		var triggerOffset = $header.length ? $header.offset().top : 0;
		var $productName = $('.head-mast-row .product-name, .head-mast-row .product-price');
		var $nav = $('.single-product .head-mast-row .head-nav, .single-product .head-mast-row .head-icons');
		var $branding = $('.single-product .head-mast-row .header-branding-wrap');
		var isSingleProduct = $('body').hasClass('single-product');
		var swapped = false;

		$(window).on('scroll', function () {
			var scrolled = $(window).scrollTop();

			$mast.toggleClass('is-stuck', scrolled > triggerOffset);

			if (!isSingleProduct || $mast.hasClass('thanks-active')) {
				return;
			}

			// Au dela de 300px on remplace la navigation par le nom et le prix.
			if (scrolled > triggerOffset + 300 && !swapped) {
				swapped = true;
				$nav.fadeOut(100, function () {
					$productName.fadeIn(100);
				});
				if ($(window).width() < 991) {
					$branding.fadeOut(100);
				}
			} else if (scrolled <= triggerOffset + 300 && swapped) {
				swapped = false;
				$productName.fadeOut(100, function () {
					$nav.fadeIn(100);
				});
				if ($(window).width() < 991) {
					$branding.fadeIn(100);
				}
			}
		});
	}

	/* =====================================================================
	 * Mini-panier
	 * ===================================================================== */
	function initMiniCart() {
		var $trigger = $('.header-mini-cart-trigger');
		var $contents = $('.header-mini-cart-contents');

		if (!$trigger.length) {
			return;
		}

		$trigger.on('click', function (event) {
			event.preventDefault();
			event.stopPropagation();
			$contents.fadeToggle(150);
		});

		$contents.on('click', function (event) {
			event.stopPropagation();
		});

		$(document).on('click', function () {
			$contents.fadeOut(150);
		});

		$(document).on('keyup', function (event) {
			if (event.key === 'Escape') {
				$contents.fadeOut(150);
			}
		});
	}

	/* =====================================================================
	 * Recherche AJAX de produits
	 * ===================================================================== */
	function initAjaxSearch() {
		$('.category-search-form.form-ajax-enabled').each(function () {
			var $form = $(this);
			var $input = $form.find('.category-search-input');
			var $select = $form.find('.category-search-select');
			var $results = $form.find('.category-search-results');
			var $spinner = $form.find('.category-search-spinner');
			var $template = $results.children('.category-search-results-item').first().clone();

			$results.empty().hide();

			function render(items) {
				$results.empty();

				if (!items.length) {
					var $empty = $template.clone();
					$empty.find('a').attr('href', '#');
					$empty.find('.category-search-results-item-title').text(cfg.no_results || '');
					$results.append($empty).show();
					return;
				}

				$.each(items, function (i, item) {
					var $row = $template.clone();
					$row.find('a').attr('href', item.url);
					$row.find('.category-search-results-item-title').text(item.title);
					$results.append($row);
				});

				$results.show();
			}

			var search = throttle(function () {
				var term = $.trim($input.val());

				if (term.length < 3) {
					$results.hide().empty();
					return;
				}

				$spinner.addClass('visible');

				$.get(cfg.url, {
					action: 'wparabic_search_products',
					s: term,
					product_cat: $select.val() || ''
				}).done(function (response) {
					render((response && response.data) ? response.data : []);
				}).always(function () {
					$spinner.removeClass('visible');
				});
			}, 500);

			$input.on('input', search);

			$input.on('keydown', function (event) {
				var $items = $results.children();
				if (!$items.length) {
					return;
				}

				var $current = $items.filter('.highlighted');
				var i = $items.index($current);

				if (event.key === 'ArrowDown') {
					event.preventDefault();
					$items.removeClass('highlighted');
					$items.eq(Math.min(i + 1, $items.length - 1)).addClass('highlighted');
				} else if (event.key === 'ArrowUp') {
					event.preventDefault();
					$items.removeClass('highlighted');
					$items.eq(Math.max(i - 1, 0)).addClass('highlighted');
				} else if (event.key === 'Enter' && $current.length) {
					var href = $current.find('a').attr('href');
					if (href && href !== '#') {
						event.preventDefault();
						window.location.href = href;
					}
				}
			});

			$(document).on('click', function (event) {
				if (!$form.is(event.target) && !$form.has(event.target).length) {
					$results.hide();
				}
			});
		});
	}

	/* =====================================================================
	 * Formulaire de commande COD
	 * ===================================================================== */
	function initCodForm() {
		var $checkout = $('#codplugin-checkout');
		if (!$checkout.length) {
			return;
		}

		var $form = $('#codplugin_woo_single_form');
		var $qtyInput = $('#codplugin_c_number');
		var $price = $('#codplugin_price');
		var $dPrice = $('#d_price');
		var $state = $('#codplugin_state');
		var $city = $('#codplugin_city');
		var $gif = $('#codplugin_gif');

		var delegations = cfg.delegations || {};

		function flashGif() {
			$gif.show();
			setTimeout(function () {
				$gif.hide();
			}, 500);
		}

		function qty() {
			return Math.max(1, parseInt($qtyInput.val(), 10) || 1);
		}

		function recalc() {
			var shipping = parseFloat($dPrice.val());
			var count = qty();

			// Ligne 1 : produit simple => badge = quantite globale, prix unitaire.
			// Produit variable => recalc() ne gere que le total ; chaque « مقطع »
			// affiche son propre prix dans les handlers found_variation/reset_data
			// ci-dessous badge « x 1 » par ligne.
			if (!$articles.length) {
				$('#codplugin_count_number_1').html(count);
			}

			if ($articles.length) {
				// Un prix par article : le total additionne les blocs visibles,
				// un seul article incomplet met le total en pause (pas de « 0.00 »).
				var complete = true;
				var unit = 0;

				$articles.slice(0, count).each(function () {
					var price = blockPrice($(this));
					if (price === null) {
						complete = false;
					} else {
						unit += price;
					}
				});

				if (!complete || isNaN(shipping)) {
					$('#codplugin_total_price').html('');
					return;
				}

				$('#codplugin_total_price').html(money(unit + shipping));
				return;
			}

			var unit = parseFloat($price.val());

			// Aucune combinaison choisie : surtout pas « 0.00 ».
			if (isNaN(unit)) {
				$('#codplugin_v_price_1').html('&mdash;');
				$('#codplugin_total_price').html('');
				return;
			}

			$('#codplugin_v_price_1').html(money(unit));

			if (isNaN(shipping)) {
				$('#codplugin_total_price').html('');
				return;
			}

			$('#codplugin_total_price').html(money(unit * count + shipping));
		}

		function setQty(next) {
			var value = Math.max(1, next);

			// Chaque article demande SON choix de variation : impossible d'aller
			// au-dela du nombre de blocs rendus (wparabic_cod_max_articles).
			if (maxArticles > 0) {
				value = Math.min(value, maxArticles);
			}

			$qtyInput.val(value);
			$('#codplugin_count_button').html(value);
			$('#codplugin_add_button').toggleClass('at-limit', maxArticles > 0 && value >= maxArticles);

			// Un bloc et sa ligne de recapitulatif par article ; ce qui depasse
			// est masque ET remis a zero : une combinaison choisie dans un bloc
			// masque ne doit jamais resurgir dans la soumission du POST.
			$articles.each(function () {
				var n = articleIndex($(this));

				if (n <= value) {
					$(this).removeClass('is-hidden');
					$('#codplugin_row_' + n).show();
				} else {
					$(this).addClass('is-hidden');
					$('#codplugin_row_' + n).hide();
					resetBlock($(this));
				}
			});

			refreshOrderable();
			recalc();
		}

		// --- Compteur de quantite ---------------------------------------
		$('#codplugin_add_button').on('click', function () {
			setQty(qty() + 1);
		});

		$('#codplugin_remove_button').on('click', function () {
			setQty(qty() - 1);
		});

		// --- Gouvernorat : frais de livraison + cascade des delegations ---
		$state.on('change', function () {
			var slug = $state.val();
			var shipping = $state.find('option:selected').data('shipping');

			// Delegations
			$city.empty().append($('<option>', {
				value: '',
				text: $city.data('placeholder') || '',
				selected: true
			}));

			if (slug && delegations[slug]) {
				$.each(delegations[slug], function (i, name) {
					$city.append($('<option>', { value: name, text: name }));
				});
				$city.prop('disabled', false);
			} else {
				$city.prop('disabled', true);
			}

			if (!slug || typeof shipping === 'undefined') {
				$dPrice.val('');
				$('#codplugin_d_price').html('<span class="summary-select-state">' + ($state.data('placeholder') || '') + '</span>');
				$('#codplugin_total_price').html('');
				return;
			}

			flashGif();

			shipping = parseFloat(shipping) || 0;
			$dPrice.val(shipping);

			if (shipping === 0) {
				$('#codplugin_d_has_price').hide();
				$('#codplugin_d_free').show();
			} else {
				$('#codplugin_d_has_price').show();
				$('#codplugin_d_free').hide();
				$('#codplugin_d_price').html(money(shipping));
			}

			$('.codplugin_currency').show();
			recalc();
		});

		// --- Variations WooCommerce : un bloc par « مقطع » ----------------
		// wparabic_cod_form() rend autant de blocs qu'il y a d'articles
		// possibles (wparabic_cod_max_articles). Chaque bloc porte SA variation
		// (.codplugin-item-var) et son prix (.codplugin-item-price). Handlers
		// delegues sur $checkout : jQuery.trigger() remonte, donc
		// found_variation / reset_data emis sur CHAQUE .variations_form
		// arrivent ici — on identifie le bloc via .closest().
		var $articles = $checkout.find('.codplugin-article');
		var maxArticles = $articles.length;
		var $vform = $checkout.find('.variations_form');
		var $headlinePrice = $('.summary > p.price').first();
		var $stickyPrice = $('.head-mast-row .product-price');
		var $savings = $('.price-savings');
		var booted = false;

		function articleIndex($block) {
			return parseInt($block.attr('data-index'), 10) || 1;
		}

		// Une ligne de recapitulatif par article reellement commande ; le
		// compteur n'en revele que la quantite choisie.
		function refreshRows() {
			if (!$articles.length) {
				return;
			}

			var count = qty();

			$articles.each(function () {
				var n = articleIndex($(this));
				$('#codplugin_row_' + n).toggle(n <= count);
			});
		}

		function blockVar($block) {
			return parseInt($block.find('.codplugin-item-var').val(), 10) || 0;
		}

		function blockPrice($block) {
			var value = parseFloat($block.find('.codplugin-item-price').val());
			return isNaN(value) ? null : value;
		}

		function variationLabel($vformEl) {
			var parts = [];

			$vformEl.find('.variations select').each(function () {
				if ($(this).val()) {
					parts.push($.trim($(this).find('option:selected').text()));
				}
			});

			return parts.join(' / ');
		}

		function completeSelection() {
			if (!$articles.length) {
				return true;
			}

			var ok = true;

			$articles.slice(0, qty()).each(function () {
				if (!blockVar($(this))) {
					ok = false;
				}
			});

			return ok;
		}

		function setOrderable(ok) {
			$('#nrwooconfirm').prop('disabled', !ok);
		}

		// Champs requis du formulaire (nom, tel, wilaya, delegation, adresse) :
		// le bouton de confirmation ne s'active qu'apres le remplissage, produit
		// variable et produit simple confondus.
		// Les selects de variation portent un required natif : leur completude
		// est deja verifiee par completeSelection(), blocs caches compris, et
		// le moindre select vide d'un bloc masque ferait bloquer le bouton.
		// fieldsComplete s'en tient aux champs visibles hors .codplugin-article.
		function fieldsComplete() {
			var ok = true;

			$checkout.find(':input[required]')
				.not('.codplugin-article :input')
				.filter(':visible')
				.each(function () {
					if (!String($(this).val() || '').trim()) {
						ok = false;
					}
				});

			return ok;
		}

		function refreshOrderable() {
			setOrderable(completeSelection() && fieldsComplete());
		}

		// Toute frappe ou changement sur un champ requis reevalue le bouton
		// (evenements delegues : les selects n'existent pas tous au boot).
		$checkout.on('input change', ':input[required]', function () {
			refreshOrderable();
		});

		// Vidange d'un bloc : le <select> natif reste la source de verite, WooCommerce
		// declenche reset_data en reponse et le bloc se rajuste tout seul.
		function resetBlock($block) {
			$block.find('select').val('').trigger('change');
		}

		function syncSwatches() {
			$vform.find('.wparabic-swatches').each(function () {
				var $wrap = $(this);
				var $select = $wrap.siblings('select').first();
				var current = $select.val() || '';

				$wrap.find('.wparabic-swatch').each(function () {
					var $s = $(this);
					var value = String($s.attr('data-value'));
					var $opt = $select.find('option').filter(function () {
						return this.value === value;
					});

					$s.toggleClass('is-active', value === current)
						.toggleClass('is-disabled', $opt.length === 0 || $opt.prop('disabled') === true)
						.attr('aria-pressed', value === current ? 'true' : 'false');
				});

				// Valeur choisie a cote du libelle : « اللون: أسود ».
				var $label = $wrap.closest('tr').find('th.label label');
				var $chosen = $label.find('.wparabic-chosen');
				if (!$chosen.length) {
					$chosen = $('<span class="wparabic-chosen"></span>').appendTo($label);
				}
				var $active = $wrap.find('.wparabic-swatch.is-active');
				$chosen.text($active.length ? $active.attr('data-label') : '');
			});
		}

		$checkout.on('found_variation', '.variations_form', function (event, variation) {
			var $block = $(this).closest('.codplugin-article');

			// Repli : une variations_form hors bloc COD (extension tierce) ne
			// doit pas perturber ce formulaire. Exception : une page servie
			// depuis un cache avec l'ancien HTML (un unique #var_id, aucun
			// bloc) doit quand meme alimenter le chemin legacy du serveur.
			if (!$block.length) {
				var $legacyId = $('#var_id');

				if ($legacyId.length) {
					var legacyOk = !!(variation.is_in_stock && variation.is_purchasable);
					$legacyId.val(legacyOk ? variation.variation_id : 0);
					$price.val(legacyOk ? variation.display_price : '');
				}

				return;
			}

			var ok = !!(variation.is_in_stock && variation.is_purchasable);
			var n = articleIndex($block);

			$block.find('.codplugin-item-var').val(ok ? variation.variation_id : 0);
			$block.find('.codplugin-item-price').val(ok ? variation.display_price : '');
			$block.find('.codplugin-variation-hint').toggle(!ok);
			$block.toggleClass('is-complete', ok);

			// Ligne du recapitulatif reservee a cet article.
			$('#codplugin_variation_label_' + n).text(ok ? variationLabel($(this)) : '');
			$('#codplugin_v_price_' + n).html(ok ? money(parseFloat(variation.display_price)) : '&mdash;');

			// Le prix « global » suit l'article 1 : POST historique + recalcul.
			if (n === 1) {
				$price.val(ok ? variation.display_price : '');
			}

			// price_html est vide quand toutes les variations partagent le meme
			// prix : dans ce cas on laisse le prix rendu par PHP.
			if (variation.price_html) {
				$headlinePrice.html(variation.price_html);
				$stickyPrice.html(variation.price_html);
			}

			// wparabic_savings_percent() est fige sur le prix minimum et peut
			// melanger deux variations : on recalcule sur celle qui est choisie.
			if ($savings.length) {
				var regular = parseFloat(variation.display_regular_price);
				var current = parseFloat(variation.display_price);

				if (regular > 0 && current > 0 && current < regular) {
					$savings.text(Math.round(((regular - current) / regular) * 100) + '%').show();
				} else {
					$savings.hide();
				}
			}

			refreshOrderable();
			syncSwatches();
			if (booted) {
				flashGif();
			}
			recalc();
		});

		$checkout.on('reset_data', '.variations_form', function () {
			var $block = $(this).closest('.codplugin-article');

			if (!$block.length) {
				// Repli page en cache (ancien HTML) : remise a zero du var_id
				// legacy, symetrique du found_variation ci-dessus.
				if ($('#var_id').length) {
					$('#var_id').val(0);
					$price.val('');
				}
				return;
			}

			var n = articleIndex($block);

			$block.find('.codplugin-item-var').val(0);
			$block.find('.codplugin-item-price').val('');
			$block.find('.codplugin-variation-hint').show();
			$block.removeClass('is-complete');

			$('#codplugin_variation_label_' + n).text('');
			$('#codplugin_v_price_' + n).html('&mdash;');

			if (n === 1) {
				$price.val('');
			}

			refreshOrderable();
			syncSwatches();
			recalc();
		});

		$checkout.on('woocommerce_update_variation_values', '.variations_form', syncSwatches);

		// La pastille ecrit dans le <select> et declenche change : le
		// variations_form reste la seule source de verite.
		$checkout.on('click', '.wparabic-swatch', function (event) {
			event.preventDefault();

			var $swatch = $(this);
			if ($swatch.hasClass('is-disabled')) {
				return;
			}

			var $select = $swatch.closest('.wparabic-swatches').siblings('select').first();
			if ($select.length) {
				$select.val($swatch.attr('data-value')).trigger('change');
			}
		});

		refreshOrderable();
		refreshRows();
		syncSwatches();
		booted = true;

		// --- Bascule du recapitulatif ------------------------------------
		$('#codplugin_order_history').on('click', function () {
			$('#codplugin_show_hide').slideToggle(200);
			$('#codplugin_h_left i').toggleClass('fa-chevron-down fa-chevron-up');
		});

		// --- Commande par WhatsApp ---------------------------------------
		$('#whatsapp-orders').on('click', function (event) {
			event.preventDefault();

			// .clone().children().remove() : on veut le nom du produit sans le
			// <small> du libelle de variation, qu'on ajoute nous-memes ensuite.
			var product = $.trim(
				$('#codplugin_show_hide .summary-product-title').clone().children().remove().end().text()
			);
			var labels = [];

			// Produit variable : la liste des articles, ligne par ligne.
			if ($articles.length) {
				$articles.slice(0, qty()).each(function () {
					var label = $.trim($('#codplugin_variation_label_' + articleIndex($(this))).text());
					if (label) {
						labels.push(label);
					}
				});
			} else {
				var variant = $.trim($('#codplugin_variation_label_1').text());
				if (variant) {
					labels.push(variant);
				}
			}

			var text = (I18N.want_order || 'أريد طلب') + ' ' + product
				+ (labels.length ? ' (' + labels.join('، ') + ')' : '') + ' × ' + qty();

			window.open(
				'https://api.whatsapp.com/send?phone=' + (cfg.whatsapp || '') + '&text=' + encodeURIComponent(text),
				'_blank'
			);
		});

		// --- Effet de mise en avant de la carte ---------------------------
		function pulse() {
			var rect = $checkout[0].getBoundingClientRect();
			var middle = rect.top + rect.height / 2;
			var inView = middle > 0 && middle < window.innerHeight;

			$checkout.css({
				transform: inView ? 'scale(1.04)' : 'scale(1)',
				transition: 'transform .3s ease, box-shadow .3s ease',
				boxShadow: inView ? '0 10px 30px rgba(37,155,234,.18)' : 'none'
			});
		}

		$(window).on('scroll resize', pulse);
		pulse();

		/**
		 * Remet la page dans son etat normal apres un echec, pour qu'un clic ne
		 * puisse jamais rester sans effet visible.
		 */
		function abortSubmission(message) {
			$('#codplugin-thanks').hide();
			// Retire le recapitulatif clone, en gardant le titre de la boite.
			$('#thanks-order-summary').children(':not(.order-summary-title)').remove();
			$('.head-mast').removeClass('thanks-active');
			$('.main, .footer').show();
			// Re-evaluation : un echec avec des champs vides ne doit PAS
			// laisser le bouton de confirmation active.
			refreshOrderable();

			if (!message) {
				return;
			}

			// Les hints « choisis ta combinaison » ont un role par bloc ; les
			// erreurs serveur vont dans un message dedie, unique au formulaire.
			var $target = $checkout.find('.codplugin-submit-error');

			if (!$target.length) {
				$target = $('<p class="codplugin-variation-hint codplugin-submit-error"></p>')
					.insertBefore($checkout.find('.form-footer'));
			}

			// Annonce aux lecteurs d'ecran et focus : le message est visible
			// meme si le client avait defile.
			$target.attr({ role: 'alert', tabindex: '-1' }).text(message).show();
			$('html, body').animate({ scrollTop: $target.offset().top - 120 }, 300);
			$target.trigger('focus');
		}

		// --- Soumission ---------------------------------------------------
		$form.on('submit', function (event) {
			event.preventDefault();

			// Tout le corps est protege : preventDefault() ayant deja annule la
			// soumission native, la moindre exception laisserait le bouton sans
			// effet et sans le moindre retour a l'utilisateur.
			try {
				// #nrwooconfirm[disabled] bloque le clic, mais la soumission
				// implicite (Entree dans un champ texte) declenche quand meme
				// submit : on garde avant de reveler l'ecran de remerciement.
				// Produit variable : TOUT article visible exige SON choix.
				if ($articles.length) {
					var $incomplete = $articles.slice(0, qty()).filter(function () {
						return !blockVar($(this));
					});

					if ($incomplete.length) {
						setOrderable(false);
						$('html, body').animate({ scrollTop: $incomplete.first().offset().top - 120 }, 300);
						return;
					}
				}

				$('#nrwooconfirm').prop('disabled', true);
				flashGif();

				// FormData/serialize ne collectent PAS les champs relies par
				// l'attribut form=, il faut donc les ajouter explicitement.
				var data = $form.serializeArray();
				data.push({ name: 'action', value: 'codplugin_submit' });
				data.push({ name: 'codplugin_price', value: $price.val() });
				data.push({ name: 'codplugin_c_number', value: qty() });
				data.push({ name: 'd_price', value: $dPrice.val() });

				// Un var_id par article, dans l'ordre des blocs visibles : le
				// serveur regroupe les combinaisons identiques en lignes de
				// commande et revalide chaque variation de son cote.
				$articles.slice(0, qty()).each(function (i) {
					data.push({ name: 'pairs[' + i + '][var_id]', value: String(blockVar($(this))) });
				});

				// Repli page en cache (ancien HTML) : aucun bloc, le formulaire
				// passe par le chemin legacy du serveur. Pause explicitement,
				// si le input n'est pas relie au <form>.
				if (!$articles.length && $('#var_id').length) {
					data.push({ name: 'var_id', value: String($('#var_id').val() || 0) });
				}

				// Le formulaire reste visible pendant l'envoi (bouton desactive +
				// animation) : il ne disparait qu'une fois la commande creee. En
				// cas d'erreur le client corrige et renvoie sans recharger.
				$checkout.find('.codplugin-submit-error').hide();

				var send = function (retried) {
					$.post(cfg.url, $.param(data))
						.done(function (response) {
							if (response && response.success && response.data && response.data.redirect) {
								// Ecran de remerciement pendant la redirection vers la
								// vraie page « commande recue » de WooCommerce : c'est
								// elle qui declenche woocommerce_thankyou (suivi de
								// conversion).
								$('.main, .footer').hide();
								$('.head-mast').addClass('thanks-active');
								$('html, body').animate({ scrollTop: 0 }, 'slow');
								$('#codplugin-thanks').show();
								$('#codplugin_show_hide').clone().appendTo('#thanks-order-summary');
								window.location.href = response.data.redirect;
								return;
							}

							var rd = (response && response.data) || {};

							// Page servie depuis un cache avec un nonce perime :
							// on en demande un frais et on renvoie une seule fois.
							if (rd.code === 'nonce' && !retried) {
								$.post(cfg.url, { action: 'wparabic_cod_nonce' })
									.done(function (r) {
										if (r && r.success && r.data && r.data.nonce) {
											$('#rwc-form-nonce').val(r.data.nonce);
											data = $.map(data, function (field) {
												return field.name === 'rwc-form-nonce' ? { name: field.name, value: r.data.nonce } : field;
											});
											send(true);
											return;
										}
										abortSubmission(rd.message || I18N.send_failed);
									})
									.fail(function () {
										abortSubmission(rd.message || I18N.send_failed);
									});
								return;
							}

							abortSubmission(rd.message || I18N.retry || 'يرجى المحاولة مرة أخرى أو الاتصال بنا.');
						})
						.fail(function () {
							abortSubmission(I18N.retry || 'يرجى المحاولة مرة أخرى أو الاتصال بنا.');
						});
				};

				send(false);
			} catch (error) {
				if (window.console && window.console.error) {
					window.console.error('[wparabic] echec de la soumission COD :', error);
				}
				abortSubmission(I18N.send_failed || 'تعذر إرسال الطلب. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
			}
		});

		/* --- Telephone : chiffres uniquement, 8 au maximum ----------------
		 * Confort de saisie seulement : le serveur revalide de toute facon
		 * (inc/cod-form.php). On accepte un prefixe +216 / 00216 colle depuis
		 * le presse-papiers et on le retire.
		 */
		$checkout.on('input paste', 'input[name="phone_number"]', function () {
			var input = this;

			// Sur « paste », la valeur n'est pas encore posee : on differe.
			window.setTimeout(function () {
				var digits = String(input.value).replace(/\D+/g, '');

				// +216 / 00216 suivi de 8 chiffres : on retire le prefixe.
				// Un numero de 8 chiffres qui commence par 216 (Ooredoo
				// 21 6xx xxx) est garde tel quel. Pendant la frappe d'un
				// numero avec prefixe, on laisse la place necessaire.
				if (/^(?:00)?216\d{8}$/.test(digits)) {
					digits = digits.replace(/^(?:00)?216/, '');
				} else {
					digits = digits.slice(0, /^00216/.test(digits) ? 13 : (/^216/.test(digits) ? 11 : 8));
				}

				if (input.value !== digits) {
					input.value = digits;
				}
			}, 0);
		});

		// Etat initial.
		$city.prop('disabled', true);
		recalc();
	}

	/* =====================================================================
	 * Bouton d'achat colle en bas de la fiche produit
	 * ===================================================================== */
	function initStickyAtc() {
		// Portee volontairement restreinte : un handler global sur
		// a[href^="#"] casserait les onglets WooCommerce, #reviews et #respond.
		$(document).on('click', '.sticky-atc-btn a[href^="#"]', function (event) {
			var $target = $($(this).attr('href'));
			if (!$target.length) {
				return;
			}
			event.preventDefault();
			$('html, body').animate({ scrollTop: $target.offset().top - 100 }, 500);
		});

		var $btn = $('.sticky-atc-btn');

		// L'option peut etre desactivee : le bouton n'est alors pas dans le DOM.
		if (!$btn.length) {
			return;
		}

		var confirmBtn = document.getElementById('nrwooconfirm');

		// La classe sur <body> sert au CSS a remonter le bouton d'appel
		// flottant, qui sinon chevauche la barre sur mobile.
		function toggleBar(show) {
			$btn.toggleClass('visible', show);
			$('body').toggleClass('has-sticky-atc', show);
		}

		/*
		 * La barre s'affiche quand le bouton de confirmation n'est PAS visible.
		 * IntersectionObserver plutot qu'un calcul d'offset : insensible aux
		 * changements de hauteur du formulaire (ouverture du recapitulatif,
		 * cascade gouvernorat -> delegation, choix d'une variation).
		 */
		if (confirmBtn && 'IntersectionObserver' in window) {
			new IntersectionObserver(function (entries) {
				toggleBar(!entries[0].isIntersecting);
			}, { threshold: 0 }).observe(confirmBtn);

			return;
		}

		// Repli : navigateur sans IntersectionObserver, ou produit sans
		// formulaire COD (produit simple).
		var $desc = $('.product-desc');

		if (!$desc.length) {
			return;
		}

		$(window).on('scroll', function () {
			toggleBar($(window).scrollTop() > $desc.offset().top + 200);
		});
	}

	$(function () {
		initTopMarquee();
		initCategoryCarousels();
		initMobileMenu();
		initSearchToggle();
		initStickyHeader();
		initMiniCart();
		initAjaxSearch();
		initCodForm();
		initStickyAtc();
	});
})(jQuery);
