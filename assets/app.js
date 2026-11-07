/* Loft Studio front-end. Vanilla JS, no build step. */
(function () {
	'use strict';
	if (typeof LOFT === 'undefined') return;

	/* ------------------------------------------------------------ helpers */
	const h = (tag, attrs, ...children) => {
		const el = document.createElement(tag);
		if (attrs) for (const [k, v] of Object.entries(attrs)) {
			if (k === 'class') el.className = v;
			else if (k === 'html') el.innerHTML = v;
			else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
			else if (v !== null && v !== undefined && v !== false) el.setAttribute(k, v === true ? '' : v);
		}
		for (const c of children.flat()) {
			if (c === null || c === undefined || c === false) continue;
			el.append(c instanceof Node ? c : document.createTextNode(String(c)));
		}
		return el;
	};
	const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const fmt = (n) => LOFT.i18n.spotsLeft.replace('%d', n);

	async function api(path, opts = {}) {
		const res = await fetch(LOFT.rest + path, {
			method: opts.method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': LOFT.nonce },
			credentials: 'same-origin',
			body: opts.body ? JSON.stringify(opts.body) : undefined,
		});
		let data = null;
		try { data = await res.json(); } catch (e) { /* empty */ }
		if (!res.ok) {
			const err = new Error((data && data.message) || res.statusText);
			err.code = data && data.code;
			err.data = data && data.data;
			err.status = res.status;
			throw err;
		}
		return data;
	}

	function toast(msg, kind = 'ok') {
		let host = document.querySelector('.loft-toasts');
		if (!host) { host = h('div', { class: 'loft-toasts' }); document.body.append(host); }
		const t = h('div', { class: 'loft-toast loft-toast-' + kind }, msg);
		host.append(t);
		setTimeout(() => t.classList.add('show'), 10);
		setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 4000);
	}

	function modal(title, bodyEl, actions = []) {
		const close = () => wrap.remove();
		const wrap = h('div', { class: 'loft-modal-wrap', onclick: (e) => { if (e.target === wrap) close(); } },
			h('div', { class: 'loft-modal', role: 'dialog', 'aria-modal': 'true' },
				h('div', { class: 'loft-modal-head' }, h('h3', null, title), h('button', { class: 'loft-x', 'aria-label': 'Close', onclick: close }, '×')),
				h('div', { class: 'loft-modal-body' }, bodyEl),
				actions.length ? h('div', { class: 'loft-modal-actions' }, ...actions.map(a => h('button', { class: 'loft-btn' + (a.secondary ? ' loft-btn-secondary' : ''), onclick: async () => { const r = await a.onclick(close); if (r !== false && !a.keep) close(); } }, a.label))) : null
			)
		);
		document.body.append(wrap);
		return { close, el: wrap };
	}

	/* ------------------------------------------------------------ shared: me cache */
	let meCache = null;
	async function getMe(force = false) {
		if (!LOFT.loggedIn) return null;
		if (!meCache || force) meCache = await api('me');
		return meCache;
	}

	/* ------------------------------------------------------------ booking flow (shared by schedule + account) */
	async function startBooking(session, onDone) {
		if (!LOFT.loggedIn) { window.location.href = LOFT.loginUrl; return; }
		const me = await getMe();
		if (!me.attendees.length) {
			modal('Who is attending?', h('p', null, 'Add yourself or a family member to your account first.'), [
				{ label: 'Add attendee', onclick: () => { window.location.href = LOFT.accountUrl + '#family'; } },
			]);
			return;
		}
		const already = new Set((session.mine || []).map(m => m.attendee_id));
		const eligible = me.attendees.filter(a => !already.has(a.id));
		if (!eligible.length) { toast('Everyone on your account is already booked.', 'warn'); return; }

		const choose = (attendee) => bookFor(session, attendee, onDone);
		if (eligible.length === 1) return choose(eligible[0]);

		const list = h('div', { class: 'loft-attendee-pick' }, ...eligible.map(a =>
			h('button', { class: 'loft-pick', onclick: () => { m.close(); choose(a); } },
				h('span', null, a.name), a.waiver && !a.waiver.signed ? h('small', { class: 'loft-warn' }, 'Waiver needed') : null)
		));
		const m = modal('Who is attending?', list);
	}

	async function bookFor(session, attendee, onDone) {
		try {
			const r = await api('bookings', { method: 'POST', body: { session_id: session.id, attendee_id: attendee.id } });
			toast(r.booking.status === 'waitlisted' ? `${attendee.name} is #${r.booking.position} on the waitlist.` : `${attendee.name} is booked!`);
			meCache = null;
			onDone && onDone();
		} catch (e) {
			if (e.code === 'waiver') {
				modal('Waiver required', h('p', null, `${attendee.name} needs a signed waiver before booking.`), [
					{ label: 'Sign waiver', onclick: () => { window.location.href = LOFT.waiverUrl + '?attendee=' + attendee.id + '&return=' + encodeURIComponent(location.href); } },
				]);
			} else if (e.code === 'needs_plan') {
				showPlanPicker(e.data.plans || [], session, attendee);
			} else {
				toast(e.message || 'Could not book.', 'error');
			}
		}
	}

	function planCard(p, onBuy) {
		const meta = [];
		if (p.kind === 'subscription') meta.push('per month' + (p.weekly_limit ? ` · up to ${p.weekly_limit}/week` : ' · unlimited'));
		if (p.kind === 'season') meta.push('per month' + (p.season ? ` · ${p.season.name}` : '') + (p.registration_fee_cents ? ` · +$${(p.registration_fee_cents / 100).toFixed(0)} registration` : ''));
		if (p.kind === 'pack') meta.push(`${p.credits} class credits` + (p.credit_validity_days ? ` · valid ${p.credit_validity_days} days` : ''));
		if (p.kind === 'dropin') meta.push('single class');
		return h('div', { class: 'loft-plan loft-card' },
			h('div', { class: 'loft-plan-kind' }, { subscription: 'Membership', season: 'Seasonal tuition', pack: 'Class pack', dropin: 'Drop-in' }[p.kind]),
			h('h4', null, p.name),
			h('div', { class: 'loft-plan-price' }, p.price_label, h('small', null, meta.join(' '))),
			p.description ? h('p', { class: 'loft-muted' }, p.description) : null,
			h('button', { class: 'loft-btn', disabled: !p.stripe_ready, onclick: () => onBuy(p) }, p.stripe_ready ? 'Choose' : 'Unavailable')
		);
	}

	function showPlanPicker(plans, session, attendee) {
		const body = h('div', null,
			h('p', null, `You need a membership or credits to book ${session ? '“' + session.program_title + '”' : 'this class'}. Pick an option and you'll be sent to secure checkout; the booking completes automatically after payment.`),
			plans.length ? h('div', { class: 'loft-plans-grid' }, ...plans.map(p => planCard(p, (plan) => checkout(plan, session, attendee)))) : h('p', { class: 'loft-muted' }, 'No plans are available for this class yet. Please contact the studio.')
		);
		modal('Choose a plan', body);
	}

	async function checkout(plan, session, attendee) {
		try {
			const r = await api('checkout', { method: 'POST', body: { plan_id: plan.id, session_id: session ? session.id : 0, attendee_id: attendee ? attendee.id : 0, return_to: location.href.split('?')[0] } });
			window.location.href = r.url;
		} catch (e) { toast(e.message || 'Checkout failed', 'error'); }
	}

	async function cancelBooking(b, onDone) {
		const late = b.is_late;
		const body = h('p', null, late ? `This is within the late-cancellation window, so the class credit may not be returned. Cancel anyway?` : `Cancel ${b.attendee_name}'s spot in ${b.program_title} on ${b.date_label}?`);
		modal('Cancel booking', body, [
			{ label: 'Keep it', secondary: true, onclick: () => {} },
			{ label: 'Cancel booking', onclick: async () => {
				try { await api('bookings/' + b.id, { method: 'DELETE' }); toast('Cancelled.'); meCache = null; onDone && onDone(); }
				catch (e) { toast(e.message, 'error'); }
			} },
		]);
	}

	/* ------------------------------------------------------------ view: schedule */
	async function viewSchedule(root, atts) {
		const weeks = parseInt(atts.weeks || 2, 10);
		const state = { group: atts.group || '', level: atts.level || '', offset: 0 };
		const q = new URLSearchParams(location.search);
		if (q.get('group')) state.group = q.get('group');

		async function load() {
			root.innerHTML = '<div class="loft-loading">Loading…</div>';
			const from = new Date(); from.setDate(from.getDate() + state.offset * 7 * weeks);
			const to = new Date(from); to.setDate(to.getDate() + 7 * weeks);
			const iso = (d) => d.toISOString().slice(0, 10);
			const params = new URLSearchParams({ from: iso(from), to: iso(to) });
			if (state.group) params.set('group', state.group);
			if (state.level) params.set('level', state.level);
			if (atts.tag) params.set('tag', atts.tag);
			if (atts.program_id) params.set('program_id', atts.program_id);
			let data;
			try { data = await api('schedule?' + params); } catch (e) { root.innerHTML = '<p class="loft-alert loft-alert-error">Could not load schedule.</p>'; return; }
			render(data);
		}

		function render(data) {
			root.innerHTML = '';
			if (atts.filters !== 'no') {
				const bar = h('div', { class: 'loft-filters' });
				const chip = (label, key, val) => h('button', { class: 'loft-chip' + (state[key] === val ? ' active' : ''), onclick: () => { state[key] = val; load(); } }, label);
				bar.append(chip('All', 'group', ''));
				data.filters.groups.forEach(g => bar.append(chip(g.name, 'group', g.slug)));
				if (data.filters.levels.length) {
					bar.append(h('span', { class: 'loft-sep' }));
					bar.append(chip('Any level', 'level', ''));
					data.filters.levels.forEach(l => bar.append(chip(l.name, 'level', l.slug)));
				}
				root.append(bar);
			}
			const nav = h('div', { class: 'loft-nav' },
				h('button', { class: 'loft-btn loft-btn-secondary', disabled: state.offset <= 0, onclick: () => { state.offset--; load(); } }, '‹ Earlier'),
				h('span', { class: 'loft-range' }, `${data.from} → ${data.to}`),
				h('button', { class: 'loft-btn loft-btn-secondary', onclick: () => { state.offset++; load(); } }, 'Later ›')
			);
			root.append(nav);

			if (!data.sessions.length) { root.append(h('p', { class: 'loft-empty' }, 'No classes in this range.')); return; }

			// Group by date
			const byDay = {};
			data.sessions.forEach(s => { (byDay[s.starts_at.slice(0, 10)] ||= []).push(s); });
			for (const [day, list] of Object.entries(byDay)) {
				const d = new Date(list[0].starts_at);
				root.append(h('h3', { class: 'loft-day' }, d.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' })));
				const grid = h('div', { class: 'loft-sessions' });
				list.forEach(s => grid.append(sessionCard(s)));
				root.append(grid);
			}
		}

		function sessionCard(s) {
			const mine = s.mine || [];
			const tags = [...s.age_groups.map(t => t.name), ...s.levels.map(t => t.name)];
			const spots = s.is_full ? h('span', { class: 'loft-badge loft-badge-full' }, s.waitlist ? `Full · ${s.waitlist} waiting` : 'Full') : h('span', { class: 'loft-badge' + (s.spots_left <= 2 ? ' loft-badge-low' : '') }, s.spots_left === 1 ? LOFT.i18n.oneSpot : fmt(s.spots_left));
			const btn = h('button', { class: 'loft-btn' + (s.is_full ? ' loft-btn-secondary' : ''), onclick: () => startBooking(s, load) },
				LOFT.loggedIn ? (s.is_full ? LOFT.i18n.waitlist : LOFT.i18n.book) : LOFT.i18n.signIn);
			return h('div', { class: 'loft-session loft-card' + (s.program_type === 'event' ? ' loft-session-event' : '') },
				s.image ? h('img', { src: s.image, alt: '', class: 'loft-session-img', loading: 'lazy' }) : null,
				h('div', { class: 'loft-session-body' },
					h('div', { class: 'loft-session-time' }, s.time_label, s.instructor ? h('span', { class: 'loft-muted' }, ' · ' + s.instructor) : null),
					h('h4', null, s.program_url ? h('a', { href: s.program_url }, s.program_title) : s.program_title),
					tags.length ? h('div', { class: 'loft-tags' }, ...tags.map(t => h('span', { class: 'loft-tag' }, t))) : null,
					s.price_cents !== null && !s.members_only ? h('div', { class: 'loft-muted' }, `Drop-in $${(s.price_cents / 100).toFixed(2)}`) : null,
					mine.length ? h('div', { class: 'loft-mine' }, ...mine.map(m => h('span', { class: 'loft-badge loft-badge-mine' }, m.status === 'waitlisted' ? `${m.name}: waitlist` : `${m.name}: booked`))) : null,
					h('div', { class: 'loft-session-foot' }, spots, btn)
				)
			);
		}
		load();
	}

	/* ------------------------------------------------------------ view: plans */
	async function viewPlans(root, atts) {
		let data;
		try { data = await api('plans'); } catch (e) { root.innerHTML = '<p class="loft-alert loft-alert-error">Could not load plans.</p>'; return; }
		let plans = data.plans;
		if (atts.kind) plans = plans.filter(p => atts.kind.split(',').includes(p.kind));
		root.innerHTML = '';
		if (!plans.length) { root.append(h('p', { class: 'loft-empty' }, 'No plans available right now.')); return; }
		root.append(h('div', { class: 'loft-plans-grid' }, ...plans.map(p => planCard(p, async (plan) => {
			if (!LOFT.loggedIn) { window.location.href = LOFT.loginUrl; return; }
			checkout(plan, null, null);
		}))));
	}

	/* ------------------------------------------------------------ view: account */
	async function viewAccount(root, atts) {
		const q = new URLSearchParams(location.search);
		if (q.get('loft_checkout') === 'success') toast('Payment received — thank you! Your plan will appear below in a moment.');
		if (q.get('loft_checkout') === 'cancel') toast('Checkout cancelled.', 'warn');
		let tab = (location.hash || '').replace('#', '') || atts.tab || 'upcoming';

		async function load() {
			root.innerHTML = '<div class="loft-loading">Loading…</div>';
			let me;
			try { me = await getMe(true); } catch (e) { root.innerHTML = '<p class="loft-alert loft-alert-error">Could not load your account.</p>'; return; }
			render(me);
		}

		function render(me) {
			root.innerHTML = '';
			root.append(h('div', { class: 'loft-account-head' }, h('h2', null, `Hi ${me.user.first_name || me.user.name}`),
				h('div', { class: 'loft-tabs' }, ...[['upcoming', 'Upcoming'], ['family', 'Family'], ['billing', 'Plans & billing'], ['waivers', 'Waivers']].map(([k, l]) =>
					h('button', { class: 'loft-tab' + (tab === k ? ' active' : ''), onclick: () => { tab = k; history.replaceState(null, '', '#' + k); render(me); } }, l)))));
			const body = h('div', { class: 'loft-tab-body' });
			root.append(body);
			({ upcoming, family, billing, waivers })[tab](body, me);
		}

		function upcoming(body, me) {
			if (!me.bookings.length) { body.append(h('p', { class: 'loft-empty' }, 'No upcoming classes.'), h('p', null, h('a', { class: 'loft-btn', href: (window.LOFT_CFG && window.LOFT_CFG.schedule_url) || '/' }, 'Browse the schedule'))); return; }
			const list = h('div', { class: 'loft-list' });
			me.bookings.forEach(b => list.append(h('div', { class: 'loft-row loft-card' },
				h('div', null, h('strong', null, b.program_title), h('div', { class: 'loft-muted' }, `${b.date_label} · ${b.time_label} · ${b.attendee_name}`)),
				h('div', { class: 'loft-row-actions' },
					b.status === 'waitlisted' ? h('span', { class: 'loft-badge loft-badge-full' }, `Waitlist #${b.position}`) : h('span', { class: 'loft-badge loft-badge-mine' }, 'Booked'),
					b.can_cancel ? h('button', { class: 'loft-link', onclick: () => cancelBooking(b, load) }, 'Cancel') : null)
			)));
			body.append(list);
		}

		function family(body, me) {
			const list = h('div', { class: 'loft-list' });
			me.attendees.forEach(a => list.append(h('div', { class: 'loft-row loft-card' },
				h('div', null, h('strong', null, a.name, a.is_self ? h('span', { class: 'loft-muted' }, ' (you)') : null),
					h('div', { class: 'loft-muted' }, [a.age !== null ? `Age ${a.age}` : null, a.waiver.required ? (a.waiver.signed ? 'Waiver signed' : 'Waiver needed') : null].filter(Boolean).join(' · '))),
				h('div', { class: 'loft-row-actions' },
					a.waiver.required && !a.waiver.signed ? h('a', { class: 'loft-btn loft-btn-small', href: LOFT.waiverUrl + '?attendee=' + a.id }, 'Sign waiver') : null,
					h('button', { class: 'loft-link', onclick: () => editAttendee(a) }, 'Edit'))
			)));
			body.append(list, h('button', { class: 'loft-btn loft-btn-secondary', onclick: () => editAttendee(null) }, '+ Add family member'));
		}

		function editAttendee(a) {
			const f = (name, label, type = 'text', val = '') => h('label', null, label, h('input', { name, type, value: val || '' }));
			const form = h('form', { class: 'loft-form' },
				h('div', { class: 'loft-grid-2' }, f('first_name', 'First name', 'text', a?.first_name), f('last_name', 'Last name', 'text', a?.last_name)),
				f('dob', 'Date of birth', 'date', a?.dob),
				f('emergency_contact', 'Emergency contact (name & phone)', 'text', a?.emergency_contact),
				h('label', null, 'Medical notes / allergies', h('textarea', { name: 'medical_notes' }, a?.medical_notes || '')),
				a ? null : h('label', { class: 'loft-check' }, h('input', { type: 'checkbox', name: 'is_self' }), ' This is me')
			);
			modal(a ? 'Edit attendee' : 'Add family member', form, [
				a ? { label: 'Archive', secondary: true, onclick: async () => { await api('attendees/' + a.id, { method: 'PATCH', body: { status: 'archived' } }); load(); } } : null,
				{ label: 'Save', onclick: async () => {
					const fd = new FormData(form); const body = Object.fromEntries(fd.entries()); body.is_self = fd.get('is_self') ? 1 : 0;
					try { await api(a ? 'attendees/' + a.id : 'attendees', { method: a ? 'PATCH' : 'POST', body }); load(); }
					catch (e) { toast(e.message, 'error'); return false; }
				} },
			].filter(Boolean));
		}

		function billing(body, me) {
			if (!me.entitlements.length) body.append(h('p', { class: 'loft-empty' }, 'No active plans.'));
			me.entitlements.forEach(e => {
				const status = { active: 'Active', past_due: 'Payment failed — please update your card', cancelled: 'Cancelled' }[e.status] || e.status;
				let detail = '';
				if (e.kind === 'credits') detail = `${e.credits_remaining} of ${e.credits_total} credits left` + (e.expires_at ? ` · expires ${new Date(e.expires_at).toLocaleDateString()}` : '');
				else detail = (e.current_period_end ? `Renews ${new Date(e.current_period_end).toLocaleDateString()}` : '') + (e.expires_at ? ` · ends ${new Date(e.expires_at).toLocaleDateString()}` : '') + (e.weekly_limit ? ` · ${e.weekly_limit}/week` : '');
				body.append(h('div', { class: 'loft-row loft-card' },
					h('div', null, h('strong', null, e.plan_name || 'Plan'), h('div', { class: 'loft-muted' }, detail)),
					h('span', { class: 'loft-badge' + (e.status === 'past_due' ? ' loft-badge-full' : ' loft-badge-mine') }, status)));
			});
			const actions = h('div', { class: 'loft-actions' },
				h('a', { class: 'loft-btn', href: LOFT.plansUrl }, 'Buy a plan or credits'),
				me.has_stripe ? h('button', { class: 'loft-btn loft-btn-secondary', onclick: async () => { try { const r = await api('portal', { method: 'POST' }); location.href = r.url; } catch (e) { toast(e.message, 'error'); } } }, 'Manage billing & cards') : null);
			body.append(actions);
		}

		function waivers(body, me) {
			if (!me.waiver) { body.append(h('p', { class: 'loft-empty' }, 'No waiver is required right now.')); return; }
			body.append(h('p', null, `Current waiver: `, h('strong', null, me.waiver.title), ` (version ${me.waiver.version})`));
			me.attendees.forEach(a => body.append(h('div', { class: 'loft-row loft-card' },
				h('div', null, h('strong', null, a.name), h('div', { class: 'loft-muted' }, a.waiver.signed ? `Signed ${new Date(a.waiver.signed_at + 'Z').toLocaleDateString()}` + (a.waiver.expires_at ? ` · valid until ${new Date(a.waiver.expires_at + 'Z').toLocaleDateString()}` : '') : 'Not signed')),
				a.waiver.signed ? h('span', { class: 'loft-badge loft-badge-mine' }, 'Signed') : h('a', { class: 'loft-btn loft-btn-small', href: LOFT.waiverUrl + '?attendee=' + a.id }, 'Sign now'))));
		}
		load();
	}

	/* ------------------------------------------------------------ view: waiver */
	async function viewWaiver(root, atts) {
		const q = new URLSearchParams(location.search);
		const attendeeId = parseInt(q.get('attendee') || atts.attendee || 0, 10);
		const ret = q.get('return');
		let me;
		try { me = await getMe(true); } catch (e) { root.innerHTML = '<p class="loft-alert loft-alert-error">Could not load.</p>'; return; }
		if (!me.waiver) { root.innerHTML = '<p class="loft-empty">No waiver is required right now.</p>'; return; }
		let attendee = me.attendees.find(a => a.id === attendeeId) || me.attendees.find(a => !a.waiver.signed) || me.attendees[0];
		root.innerHTML = '';
		if (!attendee) { root.append(h('p', null, 'Add an attendee on your account page first.'), h('a', { class: 'loft-btn', href: LOFT.accountUrl + '#family' }, 'Go to account')); return; }

		const select = h('select', { onchange: (e) => { attendee = me.attendees.find(a => a.id === parseInt(e.target.value, 10)); nameInput.placeholder = attendee.is_minor ? 'Parent/guardian full name' : 'Your full name'; } },
			...me.attendees.map(a => h('option', { value: a.id, selected: a.id === attendee.id }, a.name + (a.waiver.signed ? ' (signed)' : ''))));
		const nameInput = h('input', { type: 'text', required: true, placeholder: attendee.is_minor ? 'Parent/guardian full name' : 'Your full name' });
		const agree = h('input', { type: 'checkbox', required: true });

		// Signature pad
		const canvas = h('canvas', { width: 600, height: 180, class: 'loft-sigpad' });
		const ctx = canvas.getContext('2d'); ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#111';
		let drawing = false, drew = false;
		const pos = (e) => { const r = canvas.getBoundingClientRect(); const p = e.touches ? e.touches[0] : e; return [(p.clientX - r.left) * (canvas.width / r.width), (p.clientY - r.top) * (canvas.height / r.height)]; };
		const start = (e) => { drawing = true; drew = true; ctx.beginPath(); ctx.moveTo(...pos(e)); e.preventDefault(); };
		const move = (e) => { if (!drawing) return; ctx.lineTo(...pos(e)); ctx.stroke(); e.preventDefault(); };
		const end = () => { drawing = false; };
		canvas.addEventListener('mousedown', start); canvas.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
		canvas.addEventListener('touchstart', start, { passive: false }); canvas.addEventListener('touchmove', move, { passive: false }); canvas.addEventListener('touchend', end);

		root.append(
			h('div', { class: 'loft-card loft-waiver' },
				h('h2', null, me.waiver.title),
				h('label', null, 'Signing for', select),
				h('div', { class: 'loft-waiver-text', html: me.waiver.html }),
				h('label', null, 'Type full legal name', nameInput),
				h('div', null, h('span', { class: 'loft-muted' }, 'Draw your signature (optional)'), canvas, h('button', { class: 'loft-link', type: 'button', onclick: () => { ctx.clearRect(0, 0, canvas.width, canvas.height); drew = false; } }, 'Clear')),
				h('label', { class: 'loft-check' }, agree, ' I have read and agree to this waiver. If signing for a minor, I confirm I am their parent or legal guardian.'),
				h('button', { class: 'loft-btn', onclick: async () => {
					if (!agree.checked || nameInput.value.trim().length < 3) { toast('Please type your full name and tick the box.', 'warn'); return; }
					try {
						await api('waivers/' + attendee.id + '/sign', { method: 'POST', body: { signed_name: nameInput.value.trim(), agree: 1, signature_png: drew ? canvas.toDataURL('image/png') : null } });
						toast('Waiver signed. Thank you!');
						meCache = null;
						setTimeout(() => { location.href = ret || LOFT.accountUrl + '#waivers'; }, 800);
					} catch (e) { toast(e.message, 'error'); }
				} }, 'Sign waiver')
			)
		);
	}

	/* ------------------------------------------------------------ view: staff check-in */
	async function viewCheckin(root) {
		const state = { date: new Date().toISOString().slice(0, 10), session: null };
		async function load() {
			root.innerHTML = '<div class="loft-loading">Loading…</div>';
			let data;
			try { data = await api('admin/sessions?date=' + state.date); } catch (e) { root.innerHTML = '<p class="loft-alert loft-alert-error">Could not load.</p>'; return; }
			root.innerHTML = '';
			const dateInput = h('input', { type: 'date', value: state.date, onchange: (e) => { state.date = e.target.value; state.session = null; load(); } });
			root.append(h('div', { class: 'loft-nav' }, h('button', { class: 'loft-btn loft-btn-secondary', onclick: () => shift(-1) }, '‹'), dateInput, h('button', { class: 'loft-btn loft-btn-secondary', onclick: () => shift(1) }, '›')));
			if (!data.sessions.length) { root.append(h('p', { class: 'loft-empty' }, 'No classes this day.')); return; }
			const tabs = h('div', { class: 'loft-tabs loft-tabs-wrap' }, ...data.sessions.map(s => h('button', { class: 'loft-tab' + (state.session === s.id ? ' active' : '') + (s.status === 'cancelled' ? ' loft-strike' : ''), onclick: () => { state.session = s.id; roster(); } }, `${s.time_label.split(' – ')[0]} ${s.program_title} (${s.booked}/${s.capacity})`)));
			root.append(tabs, h('div', { class: 'loft-roster' }));
			if (!state.session) state.session = data.sessions[0].id;
			roster();
		}
		function shift(n) { const d = new Date(state.date); d.setDate(d.getDate() + n); state.date = d.toISOString().slice(0, 10); state.session = null; load(); }
		async function roster() {
			const host = root.querySelector('.loft-roster'); if (!host) return;
			host.innerHTML = '<div class="loft-loading">Loading…</div>';
			let data;
			try { data = await api('admin/roster?session_id=' + state.session); } catch (e) { host.innerHTML = 'Error'; return; }
			host.innerHTML = '';
			root.querySelectorAll('.loft-tab').forEach(t => t.classList.remove('active'));
			host.append(h('h3', null, `${data.session.program_title} · ${data.session.time_label}`, h('span', { class: 'loft-muted' }, ` · ${data.session.booked}/${data.session.capacity} booked`)));
			if (!data.roster.length) { host.append(h('p', { class: 'loft-empty' }, 'Nobody booked.')); return; }
			data.roster.forEach(r => {
				const mark = async (status) => { try { await api('admin/attendance', { method: 'POST', body: { booking_id: r.id, status } }); roster(); } catch (e) { toast(e.message, 'error'); } };
				host.append(h('div', { class: 'loft-row loft-card loft-roster-row loft-status-' + r.status },
					h('div', null, h('strong', null, r.attendee_name, r.age !== null ? h('span', { class: 'loft-muted' }, ` · ${r.age}`) : null, r.waiver_ok ? null : h('span', { class: 'loft-badge loft-badge-full' }, 'No waiver')),
						h('div', { class: 'loft-muted' }, [r.account_name, r.account_phone].filter(Boolean).join(' · ')),
						r.medical_notes ? h('div', { class: 'loft-warn' }, '⚠ ' + r.medical_notes) : null),
					r.status === 'waitlisted' ? h('span', { class: 'loft-badge' }, `Waitlist #${r.position}`) :
						h('div', { class: 'loft-row-actions' },
							h('button', { class: 'loft-btn loft-btn-small' + (r.status === 'attended' ? '' : ' loft-btn-secondary'), onclick: () => mark(r.status === 'attended' ? 'booked' : 'attended') }, 'Here'),
							h('button', { class: 'loft-btn loft-btn-small' + (r.status === 'no_show' ? '' : ' loft-btn-secondary'), onclick: () => mark(r.status === 'no_show' ? 'booked' : 'no_show') }, 'No-show'))
				));
			});
		}
		load();
	}

	/* ------------------------------------------------------------ mount */
	const views = { schedule: viewSchedule, account: viewAccount, plans: viewPlans, waiver: viewWaiver, checkin: viewCheckin };
	async function mountAll() {
		try { const cfg = await api('config'); window.LOFT_CFG = cfg; } catch (e) { /* ignore */ }
		document.querySelectorAll('.loft-app[data-loft-view]').forEach(el => {
			const view = el.dataset.loftView;
			let atts = {};
			try { atts = JSON.parse(el.dataset.loftAtts || '{}'); } catch (e) { /* ignore */ }
			if (views[view]) views[view](el, atts);
		});
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountAll); else mountAll();
})();
