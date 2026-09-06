(function ($) {
	$(function () {
		// Material photos (Prints page): WordPress media picker per family.
		$('#prodigi-material-photos').on('click', '.prodigi-photo-pick', function () {
			var $row = $(this).closest('.prodigi-photo');
			var frame = wp.media({ title: 'Choose a photo of this material', multiple: false, library: { type: 'image' } });
			frame.on('select', function () {
				var a = frame.state().get('selection').first().toJSON();
				$row.find('input[type=hidden]').val(a.id);
				$row.find('.prodigi-photo-img').html('<img src="' + (a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url) + '" alt="" />');
			});
			frame.open();
		}).on('click', '.prodigi-photo-clear', function () {
			var $row = $(this).closest('.prodigi-photo'); $row.find('input[type=hidden]').val(0); $row.find('.prodigi-photo-img').html('<span class="description">no photo</span>');
		});
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
		$panel.on('change', '.prodigi-pick', function () {
			$.post(ProdigiDirect.ajax, { action: 'prodigi_direct_set_pick', nonce: ProdigiDirect.nonce, product: $panel.data('product'), variation: this.value })
				.done(function (r) { $('#prodigi-pick-result').text(r.success ? 'Recommendation saved.' : (r.data && r.data.message) || 'Could not save.'); });
		});
		var noteTimer;
		$panel.on('input', '#prodigi-pick-note', function () {
			clearTimeout(noteTimer); var note = this.value;
			noteTimer = setTimeout(function () {
				$.post(ProdigiDirect.ajax, { action: 'prodigi_direct_set_pick', nonce: ProdigiDirect.nonce, product: $panel.data('product'), note: note })
					.done(function (r) { $('#prodigi-pick-result').text(r.success ? 'Note saved.' : 'Could not save.'); });
			}, 800);
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
