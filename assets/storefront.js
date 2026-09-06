/* Medium → Size → Style picker (numbered steps) over WooCommerce's variation form. The original select stays and is driven. */
(function ($) {
	$(function () {
		var $picker = $('.pd-picker');
		if (!$picker.length) { return; }
		var opts = $picker.data('options') || [], media = $picker.data('media') || {}, attr = $picker.data('attr'), pick = parseInt($picker.data('pick'), 10) || 0;
		var $form = $picker.closest('form.variations_form'), $select = $form.find('select[name="attribute_' + attr + '"]');
		if (!$select.length) { $select = $form.find('.variations select').first(); }
		$form.find('table.variations').addClass('pd-hidden-table');
		var st = { medium: null, size: null, style: null };
		var $acc = { medium: $picker.find('.pd-acc[data-step=medium]'), size: $picker.find('.pd-acc[data-step=size]'), style: $picker.find('.pd-acc[data-step=style]') };
		var $preview = $picker.find('.pd-preview'), $chosen = $picker.find('.pd-chosen');

		function sizeOrder(a, b) { var pa = a.split('x'), pb = b.split('x'); return (pa[0] * pa[1]) - (pb[0] * pb[1]) || pa[0] - pb[0]; }
		function uniq(arr) { var s = {}; return arr.filter(function (x) { if (s[x]) { return false; } s[x] = 1; return true; }); }
		function money(n) { var any = opts[0] ? opts[0].price_h : ''; var sym = any.replace(/[\d.,\s]/g, ''); return sym + n.toFixed(2); }
		function byMedium() { return opts.filter(function (o) { return o.medium === st.medium; }); }
		function bySize() { return byMedium().filter(function (o) { return o.size === st.size; }); }
		function current() { return bySize().filter(function (o) { return o.style_k === st.style; })[0]; }

		function open(step) {
			Object.keys($acc).forEach(function (k) {
				var on = k === step; $acc[k].toggleClass('is-open', on);
				$acc[k].find('.pd-acc-head').attr('aria-expanded', on ? 'true' : 'false');
				$acc[k].find('.pd-acc-body').prop('hidden', !on);
			});
		}
		function showPreview(src, cap) {
			if (!src) { $preview.removeClass('is-on'); return; }
			$preview.find('img').attr('src', src).attr('alt', cap || ''); $preview.find('.pd-preview-cap').text(cap || ''); $preview.addClass('is-on');
		}
		function tile(cls, data, img, title, sub) {
			var $t = $('<button type="button" class="pd-tile"/>').addClass(cls || '');
			Object.keys(data).forEach(function (k) { $t.attr('data-' + k, data[k]); });
			if (img) { $t.append($('<span class="pd-tile-img"/>').append($('<img alt="" loading="lazy"/>').attr('src', img))); }
			else if (cls !== 'pd-tile-text') { $t.append($('<span class="pd-tile-img pd-tile-img-empty" aria-hidden="true"><span></span></span>')); }
			$t.append($('<span class="pd-tile-title"/>').text(title));
			if (sub) { $t.append($('<span class="pd-tile-sub"/>').text(sub)); }
			return $t;
		}
		function renderMedium() {
			var $c = $acc.medium.find('.pd-tiles').empty();
			uniq(opts.map(function (o) { return o.medium; })).forEach(function (m) {
				var info = media[m] || { label: m, image: '' }, from = Math.min.apply(null, opts.filter(function (o) { return o.medium === m; }).map(function (o) { return o.price; }));
				tile(m === st.medium ? 'is-active' : '', { medium: m, image: info.image, caption: info.label }, info.image, info.label, 'from ' + money(from)).appendTo($c);
			});
			$acc.medium.find('.pd-acc-val').text(st.medium ? (media[st.medium] || {}).label || st.medium : '');
		}
		function renderSize() {
			var $c = $acc.size.find('.pd-tiles').empty().addClass('pd-tiles-sizes');
			uniq(byMedium().map(function (o) { return o.size; })).sort(sizeOrder).forEach(function (s) {
				var from = Math.min.apply(null, byMedium().filter(function (o) { return o.size === s; }).map(function (o) { return o.price; }));
				tile(s === st.size ? 'is-active pd-tile-text' : 'pd-tile-text', { size: s }, '', s.replace('x', ' × ') + '"', 'from ' + money(from)).appendTo($c);
			});
			$acc.size.find('.pd-acc-val').text(st.size ? st.size.replace('x', ' × ') + '"' : '');
			$acc.size.toggleClass('is-disabled', !st.medium);
		}
		function renderStyle() {
			var $c = $acc.style.find('.pd-tiles').empty(), seen = {};
			bySize().forEach(function (o) {
				if (seen[o.style_k]) { return; } seen[o.style_k] = 1;
				tile(o.style_k === st.style ? 'is-active' : '', { style: o.style_k, image: o.image, caption: o.style + ' — ' + o.desc }, o.image, o.style, o.price_h).appendTo($c);
			});
			var cur = current();
			$acc.style.find('.pd-acc-val').text(cur ? cur.style : '');
			$acc.style.toggleClass('is-disabled', !st.size);
		}
		function apply() {
			var o = current();
			if (o) {
				$select.val(o.label).trigger('change');
				$chosen.prop('hidden', false).html('<span class="pd-chosen-label">' + o.size.replace('x', ' × ') + '" ' + (media[o.medium] || {}).label + ' · ' + o.style + '</span>' + (o.id === pick ? ' <span class="pd-badge">' + ($picker.find('.pd-pick-head').text() || 'Recommended') + '</span>' : ''));
				showPreview(o.image, o.style + ' — ' + o.desc);
			} else {
				$select.val('').trigger('change'); $chosen.prop('hidden', true).empty();
			}
		}
		function renderAll() { renderMedium(); renderSize(); renderStyle(); apply(); }

		$picker.on('click', '.pd-acc-head', function () { var $a = $(this).closest('.pd-acc'); if ($a.hasClass('is-disabled')) { return; } open($a.hasClass('is-open') ? null : $a.data('step')); });
		$acc.medium.on('click', '.pd-tile', function () { st.medium = $(this).data('medium'); st.size = null; st.style = null; renderAll(); open('size'); });
		$acc.size.on('click', '.pd-tile', function () { st.size = String($(this).data('size')); st.style = null; renderAll(); open('style'); });
		$acc.style.on('click', '.pd-tile', function () { st.style = $(this).data('style'); renderAll(); open(null); $form.find('.single_variation_wrap')[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' }); });
		$picker.on('mouseenter focus', '.pd-tile[data-image]', function () { showPreview($(this).data('image'), $(this).data('caption')); });
		$picker.on('mouseleave', '.pd-tiles', function () { var o = current(); if (o) { showPreview(o.image, o.style + ' — ' + o.desc); } else { showPreview(''); } });

		function selectOption(o) { if (!o) { return; } st.medium = o.medium; st.size = o.size; st.style = o.style_k; renderAll(); open(null); }
		$picker.on('click', '.pd-pick-choose', function () {
			var id = parseInt($(this).data('id'), 10); selectOption(opts.filter(function (o) { return o.id === id; })[0]);
			$form.find('.single_variation_wrap')[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
		});
		var start = opts.filter(function (o) { return o.id === pick; })[0];
		if (start) { selectOption(start); } else { renderAll(); open('medium'); }
	});
})(jQuery);
