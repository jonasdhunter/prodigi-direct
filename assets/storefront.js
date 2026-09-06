/* Material → size → frame picker over WooCommerce's variation form. Progressive: the original select stays and is driven. */
(function ($) {
	$(function () {
		var $picker = $('.pd-picker');
		if (!$picker.length) { return; }
		var opts = $picker.data('options') || [], attr = $picker.data('attr'), pick = parseInt($picker.data('pick'), 10) || 0;
		var $form = $picker.closest('form.variations_form'), $select = $form.find('select[name="attribute_' + attr + '"]');
		if (!$select.length) { $select = $form.find('.variations select').first(); }
		$form.find('table.variations').addClass('pd-hidden-table');
		var state = { family: null, size: null, choice: null };
		var $size = $picker.find('.pd-step-size'), $frame = $picker.find('.pd-step-frame'), $chosen = $picker.find('.pd-chosen');

		function sizeOrder(a, b) { var pa = a.split('x'), pb = b.split('x'); return (pa[0] * pa[1]) - (pb[0] * pb[1]) || pa[0] - pb[0]; }
		function inFamily() { return opts.filter(function (o) { return o.family === state.family; }); }
		function needsChoice() { return inFamily().some(function (o) { return o.choice; }); }
		function current() {
			return opts.filter(function (o) { return o.family === state.family && o.size === state.size && (!needsChoice() || o.choice === state.choice); })[0];
		}
		function renderSizes() {
			var seen = {}, sizes = inFamily().map(function (o) { return o.size; }).filter(function (s) { if (seen[s]) { return false; } seen[s] = 1; return true; }).sort(sizeOrder);
			var $c = $size.find('.pd-chips').empty();
			sizes.forEach(function (s) {
				var o = inFamily().filter(function (x) { return x.size === s; })[0];
				$('<button type="button" class="pd-chip"/>').attr('data-size', s).toggleClass('is-active', s === state.size)
					.html('<span class="pd-chip-size">' + s.replace('x', ' × ') + '"</span><span class="pd-chip-price">' + (needsChoice() ? '' : o.price_h) + '</span>').appendTo($c);
			});
			$size.prop('hidden', false);
		}
		function renderFrames() {
			if (!needsChoice()) { $frame.prop('hidden', true); return; }
			var $c = $frame.find('.pd-chips').empty(), seen = {};
			inFamily().filter(function (o) { return o.size === state.size; }).forEach(function (o) {
				if (seen[o.choice]) { return; } seen[o.choice] = 1;
				$('<button type="button" class="pd-chip pd-swatch"/>').attr('data-choice', o.choice).toggleClass('is-active', o.choice === state.choice)
					.html('<span class="pd-swatch-dot pd-swatch-' + o.choice.replace(/\s+/g, '-') + '"></span>' + o.choice_l + ' <span class="pd-chip-price">' + o.price_h + '</span>').appendTo($c);
			});
			$frame.prop('hidden', !state.size);
		}
		function apply() {
			var o = current();
			$picker.find('.pd-card').each(function () { $(this).toggleClass('is-active', $(this).data('family') === state.family); });
			if (o) {
				$select.val(o.label).trigger('change');
				$chosen.prop('hidden', false).html('<span class="pd-chosen-label">' + o.size.replace('x', ' × ') + '" ' + o.material + (o.choice_l ? ', ' + o.choice_l : '') + '</span>' + (o.id === pick ? ' <span class="pd-badge">' + ($picker.find('.pd-pick-head').text() || 'Recommended') + '</span>' : ''));
			} else {
				$select.val('').trigger('change');
				$chosen.prop('hidden', true).empty();
			}
		}
		$picker.on('click', '.pd-card', function () {
			state.family = $(this).data('family'); state.size = null; state.choice = null;
			renderSizes(); renderFrames(); apply();
			$size[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
		});
		$size.on('click', '.pd-chip', function () {
			state.size = String($(this).data('size')); state.choice = null;
			renderSizes(); renderFrames(); apply();
		});
		$frame.on('click', '.pd-chip', function () {
			state.choice = String($(this).data('choice'));
			renderFrames(); apply();
		});
		function selectOption(o) {
			if (!o) { return; }
			state.family = o.family; state.size = o.size; state.choice = o.choice || null;
			renderSizes(); renderFrames(); apply();
		}
		$picker.on('click', '.pd-pick-choose', function () {
			var id = parseInt($(this).data('id'), 10);
			selectOption(opts.filter(function (o) { return o.id === id; })[0]);
			$form.find('.single_variation_wrap')[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
		});
		// Start on the recommendation, else the first family only.
		var start = opts.filter(function (o) { return o.id === pick; })[0];
		if (start) { selectOption(start); } else if (opts.length) { state.family = opts[0].family; renderSizes(); renderFrames(); apply(); }
	});
})(jQuery);
