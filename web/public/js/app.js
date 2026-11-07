/* Loft — small progressive enhancements. Every page works without this file. */
(function () {
	'use strict';

	// <form data-confirm="Are you sure?"> asks before submitting.
	document.addEventListener('submit', function (e) {
		var msg = e.target.getAttribute('data-confirm');
		if (msg && !window.confirm(msg)) e.preventDefault();
	});

	// Admin sidebar toggle on small screens.
	document.querySelectorAll('[data-menu-toggle]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var bar = btn.closest('.sidebar');
			var open = bar.classList.toggle('open');
			btn.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
	});

	// Studio signup: suggest a web address from the studio name until it's edited by hand.
	var name = document.querySelector('[data-slug-source]');
	var slug = document.querySelector('[data-slug-target]');
	if (name && slug) {
		var touched = slug.value !== '';
		slug.addEventListener('input', function () { touched = true; });
		name.addEventListener('input', function () {
			if (touched) return;
			slug.value = name.value.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '')
				.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60);
		});
	}

	// Show only the fields that apply: <div data-show-when="kind=pack,dropin">.
	document.querySelectorAll('[data-show-when]').forEach(function (el) {
		var parts = el.getAttribute('data-show-when').split('=');
		var input = document.querySelectorAll('[name="' + parts[0] + '"]');
		var values = parts[1].split(',');
		function sync() {
			var current = '';
			input.forEach(function (i) { if ((i.type !== 'radio' && i.type !== 'checkbox') || i.checked) current = i.value; });
			el.hidden = values.indexOf(current) === -1;
		}
		input.forEach(function (i) { i.addEventListener('change', sync); });
		sync();
	});

	// Waiver signature pad: draws into a canvas and stores a PNG data URL in the hidden input.
	document.querySelectorAll('[data-sigpad]').forEach(function (canvas) {
		var input = document.getElementById(canvas.getAttribute('data-sigpad'));
		var ctx = canvas.getContext('2d');
		function resize() {
			var ratio = window.devicePixelRatio || 1;
			var rect = canvas.getBoundingClientRect();
			canvas.width = rect.width * ratio;
			canvas.height = rect.height * ratio;
			ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
			ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111';
			if (input) input.value = '';
		}
		resize();
		var drawing = false;
		function pos(e) {
			var r = canvas.getBoundingClientRect();
			var p = e.touches ? e.touches[0] : e;
			return [p.clientX - r.left, p.clientY - r.top];
		}
		function start(e) { drawing = true; ctx.beginPath(); ctx.moveTo.apply(ctx, pos(e)); e.preventDefault(); }
		function move(e) { if (!drawing) return; ctx.lineTo.apply(ctx, pos(e)); ctx.stroke(); e.preventDefault(); }
		function end() { if (!drawing) return; drawing = false; if (input) input.value = canvas.toDataURL('image/png'); }
		canvas.addEventListener('mousedown', start); canvas.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
		canvas.addEventListener('touchstart', start, { passive: false }); canvas.addEventListener('touchmove', move, { passive: false }); canvas.addEventListener('touchend', end);
		var clear = document.querySelector('[data-sigpad-clear="' + canvas.id + '"]');
		if (clear) clear.addEventListener('click', function () { ctx.clearRect(0, 0, canvas.width, canvas.height); if (input) input.value = ''; });
	});
})();
