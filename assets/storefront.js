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
				$t.append(o.image ? $('<span class="pd-tile-img"/>').append($('<img alt="" decoding="async"/>').attr('src', o.image)) : $('<span class="pd-tile-img pd-tile-img-empty" aria-hidden="true"><span></span></span>'));
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

		/* ---- The stage: the real product photo, framed in CSS + SVG to match the choice. ---- */
		var art = $picker.data('art'), useStage = $picker.data('stage') === 'yes' && art, $gallery = $('.woocommerce-product-gallery').first(), $stage = null;
		var FRAME = { black: '#1e1e1e', white: '#f2efe8', natural: '#c9a97a', brown: '#5a3921', gold: '#b9922f', silver: '#b5b5b5', 'dark grey': '#4a4a4a', 'light grey': '#c3c3c3' };
		function hex2rgb(h) { h = h.replace('#', ''); return [parseInt(h.substr(0, 2), 16), parseInt(h.substr(2, 2), 16), parseInt(h.substr(4, 2), 16)]; }
		function shade(h, f) { var c = hex2rgb(h).map(function (v) { v = f < 0 ? v * (1 + f) : v + (255 - v) * f; return Math.max(0, Math.min(255, Math.round(v))); }); return 'rgb(' + c.join(',') + ')'; }
		function buildStage() {
			if (!useStage || !$gallery.length || $stage) { return; }
			$stage = $('<div class="pd-stage"><div class="pd-stage-frame"><svg class="pd-stage-moulding" aria-hidden="true"></svg><div class="pd-stage-mat"><div class="pd-stage-paper"><img alt="" /></div></div></div><div class="pd-stage-cap"></div></div>');
			$stage.find('img').attr('src', art);
			$gallery.prepend($stage).addClass('pd-has-stage');
			$stage.on('click', function () { $gallery.toggleClass('pd-has-stage'); $stage.toggleClass('is-collapsed'); });
		}
		/* Four mitred sides in inch coordinates; each side gets a profile gradient across its width. */
		function moulding(W, H, f, colour, kind) {
			var base = colour, hi = shade(base, kind === 'box' ? 0.18 : 0.42), mid = shade(base, 0.08), lo = shade(base, -0.35), lip = shade(base, -0.55), edge = shade(base, 0.25);
			var metal = colour === FRAME.gold || colour === FRAME.silver, wood = colour === FRAME.natural || colour === FRAME.brown;
			var stops = kind === 'box'
				? [[0, hi], [0.06, base], [0.85, base], [0.93, lo], [1, lip]]
				: metal
					? [[0, edge], [0.15, hi], [0.3, base], [0.5, hi], [0.65, base], [0.8, lo], [0.9, mid], [1, lip]]
					: [[0, edge], [0.12, hi], [0.3, base], [0.55, mid], [0.75, lo], [0.88, base], [1, lip]];
			function grad(id, x1, y1, x2, y2) {
				return '<linearGradient id="' + id + '" x1="' + x1 + '" y1="' + y1 + '" x2="' + x2 + '" y2="' + y2 + '">' + stops.map(function (st) { return '<stop offset="' + st[0] + '" stop-color="' + st[1] + '"/>'; }).join('') + '</linearGradient>';
			}
			var id = 'pdm' + Math.random().toString(36).slice(2, 7);
			var defs = grad(id + 't', 0, 0, 0, 1) + grad(id + 'l', 0, 0, 1, 0) + grad(id + 'b', 0, 1, 0, 0) + grad(id + 'r', 1, 0, 0, 0);
			if (wood) {
				defs += '<pattern id="' + id + 'gh" patternUnits="userSpaceOnUse" width="0.9" height="0.13"><rect width="0.9" height="0.13" fill="none"/><path d="M0 0.03 H0.9 M0 0.09 H0.6" stroke="rgba(0,0,0,.10)" stroke-width="0.012"/></pattern>';
				defs += '<pattern id="' + id + 'gv" patternUnits="userSpaceOnUse" width="0.13" height="0.9"><path d="M0.03 0 V0.9 M0.09 0 V0.6" stroke="rgba(0,0,0,.10)" stroke-width="0.012"/></pattern>';
			}
			var top = '0,0 ' + W + ',0 ' + (W - f) + ',' + f + ' ' + f + ',' + f;
			var left = '0,0 ' + f + ',' + f + ' ' + f + ',' + (H - f) + ' 0,' + H;
			var bottom = '0,' + H + ' ' + f + ',' + (H - f) + ' ' + (W - f) + ',' + (H - f) + ' ' + W + ',' + H;
			var right = W + ',0 ' + W + ',' + H + ' ' + (W - f) + ',' + (H - f) + ' ' + (W - f) + ',' + f;
			var g = function (pts, gid, pat) { return '<polygon points="' + pts + '" fill="url(#' + gid + ')"/>' + (pat ? '<polygon points="' + pts + '" fill="url(#' + pat + ')"/>' : ''); };
			return '<defs>' + defs + '</defs>' + g(top, id + 't', wood ? id + 'gh' : '') + g(bottom, id + 'b', wood ? id + 'gh' : '') + g(left, id + 'l', wood ? id + 'gv' : '') + g(right, id + 'r', wood ? id + 'gv' : '') +
				'<path d="M' + f + ',' + f + ' L' + (W - f) + ',' + f + ' L' + (W - f) + ',' + (H - f) + ' L' + f + ',' + (H - f) + ' Z" fill="none" stroke="rgba(0,0,0,.45)" stroke-width="' + (f * 0.06) + '"/>' +
				'<path d="M0,0 L' + f + ',' + f + ' M' + W + ',0 L' + (W - f) + ',' + f + ' M0,' + H + ' L' + f + ',' + (H - f) + ' M' + W + ',' + H + ' L' + (W - f) + ',' + (H - f) + '" stroke="rgba(0,0,0,.18)" stroke-width="' + (f * 0.04) + '"/>';
		}
		function renderStage(o) {
			if (!$stage) { return; }
			var $f = $stage.find('.pd-stage-frame'), $m = $stage.find('.pd-stage-mat'), $p = $stage.find('.pd-stage-paper'), $svg = $stage.find('.pd-stage-moulding');
			$stage.attr('data-kind', o ? o.kind : 'none');
			if (!o || !o.w_in) { $f.css({ padding: 0, boxShadow: 'none' }); $svg.hide(); $m.css({ padding: 0, boxShadow: 'none' }); $p.css({ aspectRatio: 'auto', padding: 0, boxShadow: 'none' }); $stage.find('.pd-stage-cap').text(''); return; }
			var W = o.w_in, H = o.h_in;
			// Prodigi's published mouldings: classic 20 mm face / 22 mm deep; box 20 mm face / 33 mm deep with an 8 mm shadow-line; float-framed canvas ≈ 15 mm face with a small gap.
			var frameIn = { classic: 0.79, box: 0.79, float: 0.6 }[o.kind] || 0, matIn = o.mat_in || 0, gapIn = { float: 0.2, box: 0.31 }[o.kind] || 0;
			var outerW = W + 2 * (frameIn + gapIn), outerH = H + 2 * (frameIn + gapIn), pct = function (inches) { return (inches / outerW * 100) + '%'; };
			var colour = FRAME[o.choice] || '#333';
			$f.css({ padding: pct(frameIn), boxShadow: frameIn ? '0 18px 40px -12px rgba(0,0,0,.45), 0 4px 10px rgba(0,0,0,.15)' : (o.kind === 'wrap' ? '9px 9px 0 -1px rgba(0,0,0,.16), 0 14px 32px rgba(0,0,0,.28)' : '0 8px 24px rgba(0,0,0,.18)') });
			if (frameIn) { $svg.show().attr('viewBox', '0 0 ' + outerW + ' ' + outerH).attr('preserveAspectRatio', 'none').html(moulding(outerW, outerH, frameIn, colour, o.kind)); } else { $svg.hide(); }
			// the frame's rebate casts a soft shadow onto whatever sits inside it
			var innerShadow = frameIn ? 'inset 0 0 ' + (o.kind === 'box' ? '18px 2px' : '10px 1px') + ' rgba(0,0,0,.28)' : 'none';
			$m.css({ padding: pct(gapIn), background: o.kind === 'float' ? '#faf9f6' : (o.kind === 'box' ? '#f6f4ef' : 'transparent'), boxShadow: gapIn ? innerShadow : 'none' });
			var bevel = matIn ? ', inset 0 0 0 1px rgba(0,0,0,.08), inset 0 0 0 3px rgba(255,255,255,.9), inset 0 0 0 4px rgba(0,0,0,.06)' : '';
			$p.css({ aspectRatio: W + ' / ' + H, padding: pct(matIn), background: matIn ? '#fbfaf7' : '#fff', boxShadow: (frameIn && !gapIn ? innerShadow : (gapIn ? '0 2px 6px rgba(0,0,0,.25)' : 'none')) + (matIn ? bevel : '') });
			$p.find('img').css({ objectFit: o.sizing === 'fillPrintArea' ? 'cover' : 'contain', boxShadow: matIn ? '0 0 0 1px rgba(0,0,0,.12), inset 0 0 6px rgba(0,0,0,.2)' : 'none' });
			$stage.find('.pd-stage-cap').text(W + ' × ' + H + '" ' + (groups[o.grp] || {}).label + (o.frame ? ' · ' + o.frame : '') + (matIn ? ' · ' + matIn + '" mat' : ''));
		}
		buildStage();

		var start = opts.filter(function (o) { return o.id === pick; })[0];
		if (start) { selectOption(start); } else if (opts.length) { st.grp = opts[0].grp; renderAll(); }
	});
})(jQuery);
