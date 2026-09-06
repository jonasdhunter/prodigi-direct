(function ($) {
	$(function () {
		var $panel = $('#prodigi_direct_panel');
		if (!$panel.length) { return; }
		if (location.hash === '#prodigi') { $('.prodigi_direct_options, a[href="#prodigi_direct_panel"]').trigger('click'); }
		$panel.on('change', '.prodigi-family', function () {
			$panel.find('.prodigi-choices[data-family="' + this.value + '"]').prop('hidden', !this.checked);
		});
		$('#prodigi-suggest').on('click', function () {
			var sizes = ($(this).data('sizes') + '').split(',').filter(Boolean), fams = ($(this).data('families') + '').split(',').filter(Boolean);
			$panel.find('.prodigi-size').each(function () { this.checked = sizes.indexOf(this.value) !== -1; });
			if (!$panel.find('.prodigi-family:checked').length) { $panel.find('.prodigi-family').each(function () { this.checked = fams.indexOf(this.value) !== -1; $(this).trigger('change'); }); }
		});
		$('#prodigi-build').on('click', function () {
			var $btn = $(this).prop('disabled', true), choices = {};
			$panel.find('.prodigi-choice:checked').each(function () { (choices[$(this).data('family')] = choices[$(this).data('family')] || []).push(this.value); });
			$('#prodigi-build-result').text('Working…');
			$.post(ProdigiDirect.ajax, {
				action: 'prodigi_direct_build', nonce: ProdigiDirect.nonce, product: $panel.data('product'),
				families: $panel.find('.prodigi-family:checked').map(function () { return this.value; }).get(),
				sizes: $panel.find('.prodigi-size:checked').map(function () { return this.value; }).get(),
				choices: choices
			}).done(function (r) {
				$('#prodigi-build-result').text(r.success ? r.data.message + ' Reloading…' : (r.data && r.data.message) || 'Something went wrong.');
				if (r.success) { setTimeout(function () { location.href = location.pathname + location.search + '#prodigi'; location.reload(); }, r.data.message.indexOf('Skipped') !== -1 ? 6000 : 800); }
			}).fail(function () { $('#prodigi-build-result').text('Something went wrong.'); }).always(function () { $btn.prop('disabled', false); });
		});
		$panel.on('change', '.prodigi-price', function () {
			var $in = $(this), $row = $in.closest('tr');
			$.post(ProdigiDirect.ajax, { action: 'prodigi_direct_set_price', nonce: ProdigiDirect.nonce, variation: $in.data('variation'), price: $in.val() })
				.done(function (r) {
					if (!r.success) { return; }
					$row.find('.prodigi-keep').html(r.data.keep || '');
					$row.toggleClass('prodigi-loss', !!r.data.loss);
					$row.find('td').last().text(r.data.status || '');
				});
		});
	});
})(jQuery);
