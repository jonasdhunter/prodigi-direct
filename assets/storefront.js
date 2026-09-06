/* Material cards → size chips → (framed only) a grid of frame-corner tiles, over WooCommerce's variation form. */
(function ($) {
	$(function () {
		var $picker = $('.pd-picker');
		if (!$picker.length) { return; }
		var opts = $picker.data('options') || [], groups = $picker.data('groups') || {}, attr = $picker.data('attr'), pick = parseInt($picker.data('pick'), 10) || 0;
		var $form = $picker.closest('form.variations_form'), $select = $form.find('select[name="attribute_' + attr + '"]');
		if (!$select.length) { $select = $form.find('.variations select').first(); }
		$form.find('table.variations').addClass('pd-hidden-table');
		var st = { grp: null, size: null, frame: null };
		var $size = $picker.find('.pd-step-size'), $frame = $picker.find('.pd-step-frame'), $chosen = $picker.find('.pd-chosen');

		function sizeOrder(a, b) { var pa = a.split('x'), pb = b.split('x'); return (pa[0] * pa[1]) - (pb[0] * pb[1]) || pa[0] - pb[0]; }
		function uniq(arr) { var s = {}; return arr.filter(function (x) { if (s[x]) { return false; } s[x] = 1; return true; }); }
		function money(n) { var any = opts[0] ? opts[0].price_h : ''; return any.replace(/[\d.,\s]/g, '') + n.toFixed(2); }
		function inGroup() { return opts.filter(function (o) { return o.grp === st.grp; }); }
		function inSize() { return inGroup().filter(function (o) { return o.size === st.size; }); }
		function framed() { return inGroup().some(function (o) { return o.frame; }); }
		function unframed(list) { return list.filter(function (o) { return !o.frame; })[0]; }
		function current() { var c = inSize(); if (!framed()) { return c[0]; } return st.frame ? c.filter(function (o) { return o.style_k === st.frame; })[0] : unframed(c); }

		function renderSizes() {
			var $c = $size.find('.pd-chips').empty();
			uniq(inGroup().map(function (o) { return o.size; })).sort(sizeOrder).forEach(function (s) {
				var prices = inGroup().filter(function (o) { return o.size === s; }).map(function (o) { return o.price; }), from = Math.min.apply(null, prices), multi = uniq(prices).length > 1;
				$('<button type="button" class="pd-chip"/>').attr('data-size', s).toggleClass('is-active', s === st.size)
					.html('<span class="pd-chip-size">' + s.replace('x', ' × ') + '"</span><span class="pd-chip-price">' + (multi ? 'from ' : '') + money(from) + '</span>').appendTo($c);
			});
			$size.prop('hidden', !st.grp);
		}
		function renderFrames() {
			if (!framed() || !st.size) { $frame.prop('hidden', true); return; }
			var $c = $frame.find('.pd-tiles').empty(), seen = {}, list = inSize().slice().sort(function (a, b) { return (a.frame ? 1 : 0) - (b.frame ? 1 : 0); });
			var cur = current();
			list.forEach(function (o) {
				if (seen[o.style_k]) { return; } seen[o.style_k] = 1;
				var $t = $('<button type="button" class="pd-tile"/>').attr('data-frame', o.frame ? o.style_k : '').toggleClass('is-active', !!cur && o.style_k === cur.style_k);
				$t.append(o.image ? $('<span class="pd-tile-img"/>').append($('<img alt="" loading="lazy"/>').attr('src', o.image)) : $('<span class="pd-tile-img pd-tile-img-empty" aria-hidden="true"><span></span></span>'));
				$t.append($('<span class="pd-tile-title"/>').text(o.frame || 'No frame')).append($('<span class="pd-tile-sub"/>').text(o.price_h)).appendTo($c);
			});
			$frame.find('.pd-acc-val').text(cur ? (cur.frame || 'No frame') : '');
			var open = !st.frameTouched;
			$frame.prop('hidden', false).toggleClass('is-open', open);
			$frame.find('.pd-acc-body').prop('hidden', !open);
			$frame.find('.pd-acc-head').attr('aria-expanded', open ? 'true' : 'false');
		}
		function apply() {
			var o = current();
			$picker.find('.pd-card').each(function () { $(this).toggleClass('is-active', $(this).data('group') === st.grp); });
			if (o) {
				$select.val(o.label).trigger('change');
				$chosen.prop('hidden', false).html('<span class="pd-chosen-label">' + o.size.replace('x', ' × ') + '" ' + (groups[o.grp] || {}).label + (o.frame ? ' · ' + o.frame : '') + '</span>' + (o.id === pick ? ' <span class="pd-badge">' + ($picker.find('.pd-pick-head').text() || 'Recommended') + '</span>' : ''));
			} else {
				$select.val('').trigger('change'); $chosen.prop('hidden', true).empty();
			}
			renderStage(o);
		}
		function renderAll() { renderSizes(); renderFrames(); apply(); }

		$picker.on('click', '.pd-card', function () { st.grp = $(this).data('group'); st.size = null; st.frame = null; st.frameTouched = false; renderAll(); $size[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' }); });
		$size.on('click', '.pd-chip', function () { st.size = String($(this).data('size')); st.frame = null; st.frameTouched = false; renderAll(); if (framed()) { $frame[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } });
		$frame.on('click', '.pd-tile', function () { st.frame = $(this).data('frame') || null; st.frameTouched = true; renderAll(); });
		$frame.on('click', '.pd-acc-head', function () { var open = $frame.find('.pd-acc-body').prop('hidden'); $frame.find('.pd-acc-body').prop('hidden', !open); $frame.toggleClass('is-open', open); $(this).attr('aria-expanded', open ? 'true' : 'false'); });

		function selectOption(o) { if (!o) { return; } st.grp = o.grp; st.size = o.size; st.frame = o.frame ? o.style_k : null; st.frameTouched = true; renderAll(); }
		$picker.on('click', '.pd-pick-choose', function () {
			var id = parseInt($(this).data('id'), 10); selectOption(opts.filter(function (o) { return o.id === id; })[0]);
			$form.find('.single_variation_wrap')[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
		});

		/* ---- The stage: the real product photo, framed in CSS to match the choice. ---- */
		var art = $picker.data('art'), useStage = $picker.data('stage') === 'yes' && art, $gallery = $('.woocommerce-product-gallery').first(), $stage = null;
		var FRAME = { black: '#1c1c1c', white: '#f3f1ec', natural: '#c8a874', brown: '#5b3a22', gold: '#b8912e', silver: '#b4b4b4', 'dark grey': '#4b4b4b', 'light grey': '#c2c2c2' };
		function buildStage() {
			if (!useStage || !$gallery.length || $stage) { return; }
			$stage = $('<div class="pd-stage"><div class="pd-stage-frame"><div class="pd-stage-mat"><div class="pd-stage-paper"><img alt="" /></div></div></div><div class="pd-stage-cap"></div></div>');
			$stage.find('img').attr('src', art);
			$gallery.prepend($stage).addClass('pd-has-stage');
			$stage.on('click', function () { $gallery.toggleClass('pd-has-stage'); $stage.toggleClass('is-collapsed'); });
		}
		function renderStage(o) {
			if (!$stage) { return; }
			var $f = $stage.find('.pd-stage-frame'), $m = $stage.find('.pd-stage-mat'), $p = $stage.find('.pd-stage-paper');
			$stage.attr('data-kind', o ? o.kind : 'none');
			if (!o || !o.w_in) { $f.css({ padding: 0, background: 'transparent', boxShadow: 'none' }); $m.css({ padding: 0 }); $p.css({ aspectRatio: 'auto', padding: 0 }); $stage.find('.pd-stage-cap').text(''); return; }
			var W = o.w_in, H = o.h_in;
			var frameIn = { classic: 0.75, box: 1.0, float: 0.6 }[o.kind] || 0, matIn = o.mat_in || 0, gapIn = o.kind === 'float' ? 0.3 : 0;
			var outerW = W + 2 * (frameIn + gapIn), pct = function (inches) { return (inches / outerW * 100) + '%'; };
			var colour = FRAME[o.choice] || '#333';
			$f.css({ padding: pct(frameIn), background: frameIn ? colour : 'transparent', boxShadow: frameIn ? '0 10px 30px rgba(0,0,0,.25), inset 0 0 0 1px rgba(0,0,0,.15)' : (o.kind === 'wrap' ? '8px 8px 0 rgba(0,0,0,.18), 0 12px 30px rgba(0,0,0,.25)' : '0 8px 24px rgba(0,0,0,.18)') });
			$f.toggleClass('is-wood', o.choice === 'natural' || o.choice === 'brown').toggleClass('is-metal', o.choice === 'gold' || o.choice === 'silver');
			$m.css({ padding: pct(gapIn), background: o.kind === 'float' ? '#fff' : 'transparent' });
			$p.css({ aspectRatio: W + ' / ' + H, padding: pct(matIn), background: '#fff', boxShadow: matIn ? 'inset 0 0 0 1px rgba(0,0,0,.06)' : 'none' });
			$stage.find('.pd-stage-cap').text(W + ' × ' + H + '" ' + (groups[o.grp] || {}).label + (o.frame ? ' · ' + o.frame : '') + (matIn ? ' · ' + matIn + '" mat' : ''));
		}
		buildStage();

		var start = opts.filter(function (o) { return o.id === pick; })[0];
		if (start) { selectOption(start); } else if (opts.length) { st.grp = opts[0].grp; renderAll(); }
	});
})(jQuery);
