/**
 * X2Mail Setup Wizard — Mail server configuration (single domain).
 *
 * SSO uses the email domain from the user's NC profile to resolve the
 * IMAP/SMTP config. One domain config is sufficient for all SSO users
 * sharing the same mail server.
 */

document.addEventListener('DOMContentLoaded', () => {
	const wizard = document.getElementById('x2mail-wizard');
	if (!wizard) return;

	const el = id => document.getElementById(id);
	const baseUrl = OC.generateUrl('/apps/x2mail/setup');

	let wizardData = { domains: {}, oidc: {} };

	// ─── Helpers ─────────────────────────────────────────

	function normSsl(v) {
		v = (v || '').toLowerCase();
		if (v === 'ssl' || v === 'ssl/tls') return 'ssl';
		if (v === 'starttls' || v === 'tls') return 'starttls';
		return 'none';
	}

	function escHtml(s) {
		const d = document.createElement('span');
		d.textContent = s;
		return d.innerHTML;
	}

	function setStatus(msg, type) {
		const s = el('wiz-status-msg');
		s.textContent = msg;
		s.className = 'status-msg' + (type === 'ok' ? ' ok' : type === 'err' ? ' err' : '');
	}

	function updateSieveFieldState() {
		const enabled = el('wiz-sieve').checked;
		['wiz-sieve-host', 'wiz-sieve-port', 'wiz-sieve-ssl'].forEach(id => {
			el(id).disabled = !enabled;
		});
	}

	function getFormValues() {
		return {
			domain: el('wiz-domain').value.trim(),
			imap_host: el('wiz-imap-host').value.trim(),
			imap_port: parseInt(el('wiz-imap-port').value) || 143,
			imap_ssl: el('wiz-imap-ssl').value,
			oidc_audience: el('wiz-oidc-audience').value.trim(),
			oidc_scopes: el('wiz-oidc-scopes').value.trim(),
			smtp_host: el('wiz-smtp-host').value.trim(),
			smtp_port: parseInt(el('wiz-smtp-port').value) || 587,
			smtp_ssl: el('wiz-smtp-ssl').value,
			sieve: el('wiz-sieve').checked ? '1' : '0',
			sieve_host: el('wiz-sieve-host').value.trim(),
			sieve_port: parseInt(el('wiz-sieve-port').value) || 4190,
			sieve_ssl: el('wiz-sieve-ssl').value,
			oidc_provider: el('wiz-oidc-provider').value,
		};
	}

	// ─── Populate form ──────────────────────────────────

	function populateForm(domain, cfg) {
		el('wiz-domain').value = domain || '';
		el('wiz-imap-host').value = cfg?.imap_host || '';
		el('wiz-imap-port').value = cfg?.imap_port || 143;
		el('wiz-oidc-audience').value = cfg?.oidc_audience || '';
		el('wiz-oidc-scopes').value = cfg?.oidc_scopes || '';
		el('wiz-imap-ssl').value = normSsl(cfg?.imap_ssl);
		el('wiz-smtp-host').value = cfg?.smtp_host || '';
		el('wiz-smtp-port').value = cfg?.smtp_port || 587;
		el('wiz-smtp-ssl').value = normSsl(cfg?.smtp_ssl);
		el('wiz-sieve').checked = !!cfg?.sieve;
		el('wiz-sieve-host').value = cfg?.sieve_host || '';
		el('wiz-sieve-port').value = cfg?.sieve_port || 4190;
		el('wiz-sieve-ssl').value = normSsl(cfg?.sieve_ssl);
		updateSieveFieldState();

		el('wiz-delete-btn').style.display = domain ? '' : 'none';

		// Clear previous results
		const results = el('wiz-preflight-results');
		results.style.display = 'none';
		results.textContent = '';
		const authResults = el('wiz-testauth-results');
		authResults.style.display = 'none';
		authResults.textContent = '';
		setStatus('', '');
	}

	// ─── Load config ────────────────────────────────────

	function loadConfig() {
		fetch(baseUrl + '/config', {
			credentials: 'same-origin',
			headers: { 'requesttoken': OC.requestToken },
		})
		.then(r => {
			if (!r.ok) throw new Error('HTTP ' + r.status);
			return r.json();
		})
			.then(data => {
			wizardData = data;

			// Update OIDC provider dropdown
			if (data.oidc) {
				const provSel = el('wiz-oidc-provider');
				Array.from(provSel.options).forEach(opt => {
					if (opt.value === 'user_oidc') opt.disabled = !data.oidc.user_oidc;
				});
				if (data.oidc.provider && data.oidc.provider !== 'none') {
					provSel.value = data.oidc.provider;
				}
			}

			const domains = Object.keys(data.domains || {});
			if (domains.length > 0) {
				populateForm(domains[0], data.domains[domains[0]]);
			} else {
				populateForm('', null);
				if (data.suggested_domain) {
					el('wiz-domain').value = data.suggested_domain;
					el('wiz-domain').placeholder = data.suggested_domain;
				}
			}
		})
		.catch(err => {
			console.error('Setup wizard: failed to load config', err);
			setStatus(t('x2mail', 'Failed to load configuration: {error}').replace('{error}', err.message), 'err');
		});
	}

	// ─── Preflight check ────────────────────────────────

	function buildCheckLine(type, text) {
		const icon = type === 'ok' ? '\u2713' : type === 'fail' ? '\u2717' : '\u26A0';
		const line = document.createElement('div');
		line.className = 'check-line check-' + type;
		line.textContent = icon + ' ' + text;
		return line;
	}

	el('wiz-preflight-btn').addEventListener('click', e => {
		e.preventDefault();
		const results = el('wiz-preflight-results');
		const vals = getFormValues();

		if (!vals.imap_host) {
			results.style.display = 'block';
			results.className = 'preflight-results error';
			results.textContent = t('x2mail', 'IMAP host is required');
			return;
		}

		results.style.display = 'block';
		results.className = 'preflight-results running';
		results.textContent = t('x2mail', 'Running checks...');

		fetch(baseUrl + '/preflight', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'requesttoken': OC.requestToken,
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams(vals),
		})
		.then(r => r.json())
		.then(data => {
			results.textContent = '';
			let hasError = false;

			// IMAP
			if (data.imap) {
				if (data.imap.connected) {
					const auth = data.imap.auth_methods?.length ? data.imap.auth_methods.join(', ') : 'no AUTH capability advertised';
					results.appendChild(buildCheckLine('ok',
						'IMAP  ' + vals.imap_host + ':' + vals.imap_port + ' (' + auth + ')'));
						if (data.imap.oauth_supported === false) {
							results.appendChild(buildCheckLine('fail',
								'IMAP server does not support OAUTHBEARER/XOAUTH2'));
							hasError = true;
						}
						if (data.imap.tls_warning) {
							results.appendChild(buildCheckLine('warn', data.imap.tls_warning));
						}
					} else {
						results.appendChild(buildCheckLine('fail',
							'IMAP  ' + vals.imap_host + ':' + vals.imap_port + ' \u2014 ' + (data.imap.error || 'connection failed')));
						hasError = true;
				}
			}

			// SMTP
			if (data.smtp) {
				if (data.smtp.connected) {
					const smtpAuth = data.smtp.auth_methods?.length ? data.smtp.auth_methods.join(', ') : 'no AUTH advertised';
					results.appendChild(buildCheckLine('ok',
						'SMTP  ' + (vals.smtp_host || vals.imap_host) + ':' + vals.smtp_port + ' (' + smtpAuth + ')'));
						if (data.smtp.oauth_supported === false) {
							results.appendChild(buildCheckLine('fail',
								'SMTP server does not support OAUTHBEARER/XOAUTH2 for authenticated sending'));
							hasError = true;
						}
						if (data.smtp.tls_warning) {
							results.appendChild(buildCheckLine('warn', data.smtp.tls_warning));
						}
					} else {
						results.appendChild(buildCheckLine('fail',
							'SMTP  ' + (vals.smtp_host || vals.imap_host) + ':' + vals.smtp_port + ' \u2014 ' + (data.smtp.error || 'connection failed')));
						hasError = true;
				}
			}

			// Sieve (only when filtering enabled \u2014 controller returns data.sieve then)
			if (data.sieve) {
				const sieveHost = vals.sieve_host || vals.imap_host;
				if (data.sieve.connected) {
					const sieveAuth = data.sieve.sasl_methods?.length ? data.sieve.sasl_methods.join(', ') : 'no SASL advertised';
					results.appendChild(buildCheckLine('ok',
						'Sieve ' + sieveHost + ':' + vals.sieve_port + ' (' + sieveAuth + ')'));
					if (data.sieve.oauth_supported === false) {
						results.appendChild(buildCheckLine('fail',
							'Sieve server does not advertise OAUTHBEARER/XOAUTH2'));
						hasError = true;
					}
					if (data.sieve.tls_warning) {
						results.appendChild(buildCheckLine('warn', data.sieve.tls_warning));
					}
				} else {
					results.appendChild(buildCheckLine('fail',
						'Sieve ' + sieveHost + ':' + vals.sieve_port + ' \u2014 ' + (data.sieve.error || 'connection failed')));
					hasError = true;
				}
			}

			// OIDC
			if (data.oidc) {
				if (data.oidc.any_installed) {
					let info = data.oidc.provider || data.oidc.requested_provider || 'unknown';
					if (data.oidc.provider_fallback) {
						info += ', fallback';
					}
					if (data.oidc.store_login_token === true) {
						info += ', token_store=ok';
					} else if (data.oidc.store_login_token === false) {
						info += ', token_store=will set';
					}
					results.appendChild(buildCheckLine('ok', 'OIDC  ' + info));

					if (data.oidc.provider_configured === false) {
						results.appendChild(buildCheckLine('warn',
							'OIDC  No provider configured in user_oidc (occ user_oidc:provider)'));
					}
					if (data.oidc.session_is_oidc && data.oidc.session_has_token) {
						results.appendChild(buildCheckLine('ok', 'SSO   Active session with valid token'));
						const t = data.oidc.token;
						if (t) {
							const lines = [];
							if (t.email) lines.push('email=' + t.email);
							if (t.aud) lines.push('aud=' + (Array.isArray(t.aud) ? t.aud.join(',') : t.aud));
							if (t.scope) lines.push('scope=' + t.scope);
							if (t.expires_in != null) lines.push('expires=' + Math.round(t.expires_in / 60) + 'min');
							if (lines.length) {
								results.appendChild(buildCheckLine('ok', 'TOKEN ' + lines.join(', ')));
							}
							if (!t.email) {
								results.appendChild(buildCheckLine('warn', 'TOKEN Missing email claim — IMAP login may fail'));
							}
							// Note: with token exchange configured, the login token is NOT
							// supposed to carry the mail audience — Test Login reports the
							// exchanged token, which is the authoritative diagnostic.
						}
					} else if (data.oidc.session_is_oidc) {
						results.appendChild(buildCheckLine('warn', 'SSO   OIDC session but no access token'));
					} else {
						results.appendChild(buildCheckLine('warn', 'SSO   Not logged in via OIDC (use SSO login to verify)'));
					}
				} else {
					results.appendChild(buildCheckLine('fail',
						'OIDC  user_oidc is not installed'));
					hasError = true;
				}
			}

			results.className = 'preflight-results ' + (hasError ? 'error' : 'success');
		})
		.catch(err => {
			results.className = 'preflight-results error';
			results.textContent = 'Request failed: ' + err.message;
		});
	});

	// ─── Test Login (SSO auth test) ─────────────────────

	el('wiz-testauth-btn').addEventListener('click', e => {
		e.preventDefault();
		const results = el('wiz-testauth-results');
		const vals = getFormValues();

		if (!vals.imap_host) {
			results.style.display = 'block';
			results.className = 'preflight-results error';
			results.textContent = t('x2mail', 'IMAP host is required');
			return;
		}

		results.style.display = 'block';
		results.className = 'preflight-results running';
		results.textContent = t('x2mail', 'Testing SSO mail login...');

		const body = new URLSearchParams({
			imap_host: vals.imap_host,
			imap_port: vals.imap_port,
			imap_ssl: vals.imap_ssl,
			oidc_audience: vals.oidc_audience,
			oidc_scopes: vals.oidc_scopes,
			smtp_host: vals.smtp_host,
			smtp_port: vals.smtp_port,
			smtp_ssl: vals.smtp_ssl,
			sieve: vals.sieve,
			sieve_host: vals.sieve_host,
			sieve_port: vals.sieve_port,
			sieve_ssl: vals.sieve_ssl,
		});

		fetch(baseUrl + '/test-auth', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'requesttoken': OC.requestToken,
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body,
		})
		.then(r => r.json())
		.then(data => {
			results.textContent = '';
			let hasError = false;

			if (data.error) {
				results.appendChild(buildCheckLine('fail', data.error));
				results.className = 'preflight-results error';
				return;
			}

			// Which token was actually used (exchange vs login token)
			if (data.token) {
				const t = data.token;
				const aud = Array.isArray(t.aud) ? t.aud.join(',') : (t.aud || '?');
				const mins = typeof t.expires_in === 'number' ? Math.round(t.expires_in / 60) + 'min' : '?';
				let info = 'aud=' + aud + (t.scope ? ', scope=' + t.scope : '') + ', expires=' + mins;
				if (t.exchanged) {
					results.appendChild(buildCheckLine('ok', 'TOKEN exchanged for "'
						+ t.audience_requested + '" — ' + info));
				} else if (t.audience_requested) {
					results.appendChild(buildCheckLine('warn', 'TOKEN exchange for "'
						+ t.audience_requested + '" failed, using login token — ' + info
						+ ' (check Nextcloud log)'));
				} else {
					results.appendChild(buildCheckLine('ok', 'TOKEN login token (no exchange) — ' + info));
				}
			}

			// IMAP result
			if (data.imap) {
				if (data.imap.authenticated) {
					results.appendChild(buildCheckLine('ok', 'IMAP login OK'));
				} else {
					results.appendChild(buildCheckLine('fail',
						'IMAP ' + (data.imap.error || 'Authentication failed')));
					hasError = true;
				}
			}

			// SMTP result
			if (data.smtp) {
				if (data.smtp.authenticated) {
					results.appendChild(buildCheckLine('ok', 'SMTP login OK'));
				} else {
					results.appendChild(buildCheckLine('fail',
						'SMTP ' + (data.smtp.error || 'Authentication failed')));
					hasError = true;
				}
			}

			// Sieve result (only present when sieve checkbox is checked)
			if (data.sieve) {
				if (data.sieve.authenticated) {
					results.appendChild(buildCheckLine('ok', 'Sieve login OK'));
				} else {
					results.appendChild(buildCheckLine('fail',
						'Sieve ' + (data.sieve.error || 'Authentication failed')));
					hasError = true;
				}
			}

			results.className = 'preflight-results ' + (hasError ? 'error' : 'success');
		})
		.catch(err => {
			results.className = 'preflight-results error';
			results.textContent = 'Request failed: ' + err.message;
		});
	});

	// ─── Save ───────────────────────────────────────────

	el('wiz-save-btn').addEventListener('click', e => {
		e.preventDefault();
		const vals = getFormValues();

		if (!vals.domain) { setStatus(t('x2mail', 'Domain is required'), 'err'); return; }
		if (!vals.imap_host) { setStatus(t('x2mail', 'IMAP host is required'), 'err'); return; }

		setStatus(t('x2mail', 'Saving...'), '');

		fetch(baseUrl + '/save', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'requesttoken': OC.requestToken,
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams(vals),
		})
		.then(r => r.json())
			.then(data => {
				if (data.status === 'success') {
					const message = data.cleanup_warnings?.length
						? (data.message || t('x2mail', 'Saved')) + ' ' + t('x2mail', 'Some previous domains could not be removed automatically.')
						: (data.message || t('x2mail', 'Saved'));
					setStatus(message, 'ok');
					loadConfig();
				} else {
					setStatus(data.message || t('x2mail', 'Save failed'), 'err');
				}
		})
		.catch(err => {
			setStatus('Error: ' + err.message, 'err');
		});
	});

	// ─── Delete ─────────────────────────────────────────

	el('wiz-delete-btn').addEventListener('click', e => {
		e.preventDefault();
		const domain = el('wiz-domain').value.trim();
		if (!domain || !confirm(t('x2mail', 'Delete domain "{domain}"?').replace('{domain}', domain))) {
			return;
		}

		setStatus(t('x2mail', 'Deleting...'), '');

		fetch(baseUrl + '/delete', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'requesttoken': OC.requestToken,
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams({ domain }),
		})
		.then(r => r.json())
		.then(data => {
			if (data.status === 'success') {
				setStatus(data.message || t('x2mail', 'Deleted'), 'ok');
				loadConfig();
			} else {
				setStatus(data.message || t('x2mail', 'Delete failed'), 'err');
			}
		})
		.catch(err => {
			setStatus('Error: ' + err.message, 'err');
		});
	});

	// ─── Init ───────────────────────────────────────────

	el('wiz-sieve').addEventListener('change', updateSieveFieldState);
	loadConfig();
});

function collectAllgemeinSettingsBody() {
	return new URLSearchParams({
		menu_title: document.getElementById('x2m-menu-title')?.value.trim() ?? '',
		attachment_size_limit: document.getElementById('x2m-attachment-limit').value || '25',
		show_attachment_thumbnail: document.getElementById('x2m-thumbnails').checked ? '1' : '0',
		openpgp: document.getElementById('x2m-openpgp').checked ? '1' : '0',
		gnupg: document.getElementById('x2m-gnupg').checked ? '1' : '0',
	});
}

function collectAdvancedSettingsBody() {
	return new URLSearchParams({
		force_nc_lang: document.getElementById('x2mail-nc-lang').checked ? '1' : '0',
		app_path: document.getElementById('x2mail-app-path').value.trim(),
		engine_debug: document.getElementById('x2mail-debug').checked ? '1' : '0',
		x2mail_debug: document.getElementById('x2mail-debug-log').checked ? '1' : '0',
	});
}

(function () {
	const btn = document.getElementById('x2m-allgemein-save');
	if (!btn) {
		return;
	}
	const status = document.getElementById('x2m-allgemein-status');
	btn.addEventListener('click', async function () {
		status.className = 'x2m-status';
		status.textContent = '…';
		try {
			const resp = await fetch(OC.generateUrl('/apps/x2mail/setup') + '/admin-settings', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
					requesttoken: OC.requestToken,
				},
				body: collectAllgemeinSettingsBody().toString(),
			});
			const data = await resp.json();
			if (resp.ok) {
				status.className = 'x2m-status ok';
				status.textContent = '✓ ' + (window.t ? t('x2mail', 'Saved') : 'Saved');
			} else {
				status.className = 'x2m-status err';
				status.textContent = '✗ ' + (data.error || 'Save failed');
			}
		} catch (e) {
			status.className = 'x2m-status err';
			status.textContent = '✗ ' + e.message;
		}
	});
}());

(function () {
	const btn = document.getElementById('x2m-advanced-save');
	if (!btn) {
		return;
	}
	const status = document.getElementById('x2m-advanced-status');
	btn.addEventListener('click', async function () {
		status.className = 'x2m-status';
		status.textContent = '…';
		try {
			const resp = await fetch(OC.generateUrl('/apps/x2mail/setup') + '/admin-settings', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
					requesttoken: OC.requestToken,
				},
				body: collectAdvancedSettingsBody().toString(),
			});
			const data = await resp.json();
			if (resp.ok) {
				status.className = 'x2m-status ok';
				status.textContent = '✓ ' + (window.t ? t('x2mail', 'Saved') : 'Saved');
			} else {
				status.className = 'x2m-status err';
				status.textContent = '✗ ' + (data.error || 'Save failed');
			}
		} catch (e) {
			status.className = 'x2m-status err';
			status.textContent = '✗ ' + e.message;
		}
	});
}());
