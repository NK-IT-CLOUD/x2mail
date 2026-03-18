/**
 * X2Mail Setup Wizard — Admin panel domain configuration.
 *
 * Loads existing domain configs, allows editing, preflight checks, save/delete.
 */

document.addEventListener('DOMContentLoaded', () => {
	const wizard = document.getElementById('x2mail-wizard');
	if (!wizard) return;

	const el = id => document.getElementById(id);
	const baseUrl = OC.generateUrl('/apps/x2mail/setup');

	let wizardData = { domains: {}, oidc: {} };
	let currentDomain = null;

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

	function getFormValues() {
		return {
			domain: el('wiz-domain').value.trim(),
			imap_host: el('wiz-imap-host').value.trim(),
			imap_port: parseInt(el('wiz-imap-port').value) || 143,
			imap_ssl: el('wiz-imap-ssl').value,
			smtp_host: el('wiz-smtp-host').value.trim(),
			smtp_port: parseInt(el('wiz-smtp-port').value) || 25,
			smtp_ssl: el('wiz-smtp-ssl').value,
			smtp_auth: el('wiz-smtp-auth').checked ? '1' : '0',
			auth_type: el('wiz-auth-type').value,
			sieve: el('wiz-sieve').checked ? '1' : '0',
		};
	}

	// ─── OIDC field visibility ──────────────────────────

	function updateOidcVisibility() {
		const authType = el('wiz-auth-type').value;
		const isOAuth = authType === 'oauthbearer' || authType === 'xoauth2';
		el('wiz-oidc-provider').style.display = isOAuth ? '' : 'none';
		el('wiz-oidc-label').style.display = isOAuth ? '' : 'none';
	}

	el('wiz-auth-type').addEventListener('change', updateOidcVisibility);

	// ─── Populate form ──────────────────────────────────

	function populateForm(domain, cfg) {
		el('wiz-domain').value = domain || '';
		el('wiz-imap-host').value = cfg?.imap_host || '';
		el('wiz-imap-port').value = cfg?.imap_port || 143;
		el('wiz-imap-ssl').value = normSsl(cfg?.imap_ssl);
		el('wiz-smtp-host').value = cfg?.smtp_host || '';
		el('wiz-smtp-port').value = cfg?.smtp_port || 25;
		el('wiz-smtp-ssl').value = normSsl(cfg?.smtp_ssl);
		el('wiz-smtp-auth').checked = !!cfg?.smtp_auth;
		el('wiz-auth-type').value = cfg?.auth_type || 'plain';
		el('wiz-sieve').checked = !!cfg?.sieve;
		updateOidcVisibility();

		el('wiz-delete-btn').style.display = domain ? '' : 'none';

		// Clear previous results
		const results = el('wiz-preflight-results');
		results.style.display = 'none';
		results.textContent = '';
		setStatus('', '');
	}

	// ─── Domain tabs ────────────────────────────────────

	function renderTabs() {
		const tabs = el('wizard-domain-tabs');
		tabs.textContent = '';

		Object.keys(wizardData.domains).forEach(d => {
			const btn = document.createElement('button');
			btn.textContent = d;
			btn.className = 'button' + (d === currentDomain ? ' active' : '');
			btn.addEventListener('click', e => {
				e.preventDefault();
				currentDomain = d;
				populateForm(d, wizardData.domains[d]);
				renderTabs();
			});
			tabs.appendChild(btn);
		});

		const addBtn = document.createElement('button');
		addBtn.textContent = '+ ' + t('x2mail', 'New Domain');
		addBtn.className = 'button' + (currentDomain === null ? ' active' : '');
		addBtn.addEventListener('click', e => {
			e.preventDefault();
			currentDomain = null;
			populateForm('', null);
			renderTabs();
		});
		tabs.appendChild(addBtn);
	}

	// ─── Load config ────────────────────────────────────

	function loadConfig() {
		fetch(baseUrl + '/config', {
			credentials: 'same-origin',
			headers: { 'requesttoken': OC.requestToken },
		})
		.then(r => r.json())
		.then(data => {
			wizardData = data;

			// Update OIDC provider dropdown
			if (data.oidc) {
				const provSel = el('wiz-oidc-provider');
				Array.from(provSel.options).forEach(opt => {
					if (opt.value === 'user_oidc') opt.disabled = !data.oidc.user_oidc;
					if (opt.value === 'oidc_login') opt.disabled = !data.oidc.oidc_login;
				});
				if (data.oidc.provider && data.oidc.provider !== 'none') {
					provSel.value = data.oidc.provider;
				}
			}

			const domains = Object.keys(data.domains || {});
			if (domains.length > 0) {
				currentDomain = domains[0];
				populateForm(currentDomain, data.domains[currentDomain]);
			} else {
				currentDomain = null;
				populateForm('', null);
			}
			renderTabs();
		})
		.catch(err => {
			console.error('Setup wizard: failed to load config', err);
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
					results.appendChild(buildCheckLine('ok',
						'IMAP: Connected to ' + vals.imap_host + ':' + vals.imap_port));
					if (data.imap.capabilities?.length) {
						results.appendChild(buildCheckLine('ok',
							'  Capabilities: ' + data.imap.capabilities.slice(0, 8).join(', ')));
					}
					if (data.imap.oauth_supported === false) {
						results.appendChild(buildCheckLine('fail',
							'  OAUTHBEARER/XOAUTH2 not advertised by server'));
						results.appendChild(buildCheckLine('warn',
							'  Available AUTH: ' + (data.imap.auth_methods?.join(', ') || 'none')));
						hasError = true;
					} else if (data.imap.oauth_supported === true) {
						results.appendChild(buildCheckLine('ok',
							'  OAUTHBEARER/XOAUTH2 supported'));
					}
				} else {
					results.appendChild(buildCheckLine('fail',
						'IMAP: Connection failed \u2014 ' + (data.imap.error || 'unknown error')));
					hasError = true;
				}
			}

			// SMTP
			if (data.smtp) {
				if (data.smtp.connected) {
					let msg = 'SMTP: Connected to ' + (vals.smtp_host || vals.imap_host) + ':' + vals.smtp_port;
					if (data.smtp.banner) msg += ' (' + data.smtp.banner.substring(0, 60) + ')';
					results.appendChild(buildCheckLine('ok', msg));
				} else {
					results.appendChild(buildCheckLine('fail',
						'SMTP: Connection failed \u2014 ' + (data.smtp.error || 'unknown error')));
					hasError = true;
				}
			}

			// OIDC
			if (data.oidc) {
				if (data.oidc.any_installed) {
					const prov = data.oidc.user_oidc ? 'user_oidc' : 'oidc_login';
					results.appendChild(buildCheckLine('ok', 'OIDC: ' + prov + ' installed'));
					if (data.oidc.store_login_token === false) {
						results.appendChild(buildCheckLine('warn',
							'  store_login_token not enabled (will be set on save)'));
					}
				} else {
					results.appendChild(buildCheckLine('fail',
						'OIDC: No provider installed (need user_oidc or oidc_login)'));
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
				setStatus(data.message || t('x2mail', 'Saved'), 'ok');
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
		if (!domain) return;

		if (!confirm(t('x2mail', 'Delete domain configuration for "{domain}"?').replace('{domain}', domain))) {
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

	loadConfig();
});
