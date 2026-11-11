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

	// <button data-copy="#input"> copies that field's text, and says so.
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-copy]');
		var field = btn && document.querySelector(btn.getAttribute('data-copy'));
		if (!field) return;
		var done = function () {
			var label = btn.textContent;
			btn.textContent = 'Copied';
			setTimeout(function () { btn.textContent = label; }, 1600);
		};
		field.select();
		if (navigator.clipboard) navigator.clipboard.writeText(field.value).then(done, function () { document.execCommand('copy'); done(); });
		else { document.execCommand('copy'); done(); }
	});

	// "Try the demo": building one can take a few seconds when no spare is ready, so say so.
	document.querySelectorAll('[data-demo-start]').forEach(function (form) {
		form.addEventListener('submit', function () {
			var btn = form.querySelector('button');
			if (btn) { btn.disabled = true; btn.textContent = 'Setting up your studio…'; }
		});
	});

	// The demo's waiting page: ask every few seconds whether a studio is ready, then open it.
	var wait = document.querySelector('[data-demo-wait]');
	if (wait && window.fetch) {
		var form = wait.querySelector('form');
		var button = form.querySelector('button');
		var started = Date.now();
		var open = function () { button.disabled = false; button.textContent = 'Opening your studio…'; form.submit(); };
		var check = function () {
			fetch(wait.getAttribute('data-demo-wait'), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
				.then(function (r) { return r.ok ? r.json() : { ready: false }; })
				.then(function (d) {
					if (d.ready) return open();
					if (Date.now() - started > 120000) wait.querySelector('[data-demo-slow]').hidden = false;
					setTimeout(check, 2500);
				}, function () { setTimeout(check, 5000); });
		};
		if (!button.disabled) open(); else setTimeout(check, 1500);
	}

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

	// Home page savings calculator: a year of the studio's current software, plus any fee it adds to
	// card sales, against a year of Loft. The same sum as PlatformController::home, which fills in
	// the first answer so the page reads right without this.
	document.querySelectorAll('[data-calc]').forEach(function (form) {
		var monthly = parseFloat(form.getAttribute('data-monthly')) || 0;
		var loftFee = parseFloat(form.getAttribute('data-fee')) || 0;
		var money;
		try {
			money = new Intl.NumberFormat('en-US', { style: 'currency', currency: form.getAttribute('data-currency') || 'USD', maximumFractionDigits: 0 });
		} catch (err) {
			money = { format: function (n) { return '$' + Math.round(n).toLocaleString('en-US'); } };
		}
		function num(name) {
			var v = parseFloat(form.elements[name].value);
			return isFinite(v) && v > 0 ? v : 0;
		}
		function out(name) { return form.querySelector('[data-out="' + name + '"]'); }
		function show(el, amount) { el.firstChild.nodeValue = money.format(amount); }
		function sync() {
			var sales = num('sales');
			var now = 12 * (num('software') + sales * Math.min(num('percent'), 100) / 100);
			var loft = 12 * (monthly + sales * loftFee / 100);
			var saved = now - loft;
			show(out('now'), now);
			show(out('loft'), loft);
			show(out('saved'), Math.abs(saved));
			out('saved-label').textContent = saved >= 0 ? 'You’d keep' : 'Loft costs more by';
			out('saved').parentNode.classList.toggle('more', saved < 0);
		}
		form.addEventListener('input', sync);
		form.addEventListener('submit', function (e) { e.preventDefault(); sync(); });
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
