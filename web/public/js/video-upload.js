/* Admin → Videos: send a file straight to Mux. Loft makes the video and hands back a one-time
 * upload address; the browser PUTs the file there, then waits for Mux to say it's ready.
 * Without this script, the form still adds videos from a link. */
(function () {
	'use strict';

	var form = document.querySelector('[data-video-upload]');
	if (!form) return;
	var file = form.querySelector('[data-video-file]');
	var box = form.querySelector('[data-upload-progress]');
	var bar = box.querySelector('progress');
	var status = box.querySelector('[data-upload-status]');
	var button = form.querySelector('button');

	function source() {
		var picked = form.querySelector('input[name="source"]:checked');
		return picked ? picked.value : 'upload';
	}

	function say(text, isError) {
		box.hidden = false;
		status.textContent = text;
		status.classList.toggle('error-text', !!isError);
	}

	function busy(on) {
		button.disabled = on;
		form.querySelectorAll('input, select, textarea').forEach(function (el) { el.readOnly = on; });
	}

	function firstError(json) {
		var errors = (json && json.errors) || {};
		for (var key in errors) if (errors[key] && errors[key][0]) return errors[key][0];
		return (json && json.message) || 'That didn’t work. Try again.';
	}

	function waitUntilReady(statusUrl, editUrl) {
		say('Sent. Mux is getting it ready to stream…');
		bar.removeAttribute('value'); // indeterminate
		var tries = 0;
		(function poll() {
			tries++;
			fetch(statusUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (s) {
					if (s.ready || s.status === 'errored' || tries > 40) window.location.href = editUrl;
					else setTimeout(poll, 4000);
				})
				.catch(function () { setTimeout(poll, 8000); });
		})();
	}

	form.addEventListener('submit', function (e) {
		if (source() !== 'upload') return; // a link: an ordinary form post
		e.preventDefault();
		if (!file.files.length) { say('Choose a video file first.', true); return; }
		var chosen = file.files[0];
		busy(true);
		say('Getting ready…');

		fetch(form.action, {
			method: 'POST',
			headers: { Accept: 'application/json' },
			credentials: 'same-origin',
			body: new FormData(form) // the file input has no name, so the file isn't in here
		}).then(function (r) {
			return r.json().then(function (json) { return { ok: r.ok, json: json }; });
		}).then(function (res) {
			if (!res.ok) { busy(false); say(firstError(res.json), true); return; }
			var started = res.json;
			var xhr = new XMLHttpRequest();
			xhr.open('PUT', started.upload_url);
			xhr.upload.addEventListener('progress', function (ev) {
				if (!ev.lengthComputable) return;
				var pct = Math.round(ev.loaded / ev.total * 100);
				bar.value = pct;
				say('Uploading… ' + pct + '%');
			});
			xhr.addEventListener('load', function () {
				if (xhr.status >= 200 && xhr.status < 300) waitUntilReady(started.status_url, started.edit_url);
				else { busy(false); say('The upload didn’t finish (' + xhr.status + '). Open the video below to delete it, then try again.', true); }
			});
			xhr.addEventListener('error', function () {
				busy(false);
				say('The connection dropped during the upload. Open the video to delete it, then try again.', true);
			});
			window.addEventListener('beforeunload', function (ev) {
				if (xhr.readyState !== 4) { ev.preventDefault(); ev.returnValue = ''; }
			});
			xhr.send(chosen);
		}).catch(function () {
			busy(false);
			say('Couldn’t reach Loft. Check your connection and try again.', true);
		});
	});
})();
