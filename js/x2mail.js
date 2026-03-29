/**
 * Nextcloud - X2Mail plugin
 */

// Do the following things once the document is fully loaded.
document.onreadystatechange = () => {
	if (document.readyState === 'complete') {
		watchIFrameTitle();
		passThemesToIFrame();
		let form = document.querySelector('form.x2mail');
		form && X2MailFormHelper(form);
		setupUnifiedSearchListener();
	}
};

// Pass Nextcloud themes and theme attributes to X2Mail on
// first load and when the X2Mail iframe is reloaded.
function passThemesToIFrame() {
	const iframe = document.getElementById('rliframe');
	if (!iframe) return;

	let firstLoad = true;

	iframe.addEventListener('load', event => {
		// repass theme styles when iframe is reloaded
		if (!firstLoad) {
			passThemes(event.target);
		}
		firstLoad = false;
	});

	passThemes(iframe);
}

// Pass Nextcloud themes and theme attributes to X2Mail.
function passThemes(iframe) {
	if (!iframe) return;

	const target = iframe.contentWindow.document;

	const ncStylesheets = [...document.querySelectorAll('link.theme')];
	ncStylesheets.forEach(ncSheet => {
		const smSheet = target.importNode(ncSheet, true);
		target.head.appendChild(smSheet);
	});

	const themes = [...document.body.attributes].filter(att => att.name.startsWith('data-theme'));
	themes.forEach(theme => target.body.setAttribute(theme.name, theme.value));
}

// The X2Mail application is already configured to modify the <title> element
// of its root document with the number of unread messages in the inbox.
// However, its document is the X2Mail iframe. This function sets up a
// Mutation Observer to watch the <title> element of the iframe for changes in
// the unread message count and propagates that to the parent <title> element,
// allowing the unread message count to be displayed in the NC tab's text when
// the X2Mail app is selected.
function watchIFrameTitle() {
	let iframe = document.getElementById('rliframe');
	if (!iframe) {
		return;
	}
	let target = iframe.contentDocument.getElementsByTagName('title')[0];
	let config = {
		characterData: true,
		childList: true,
		subtree: true
	};
	let observer = new MutationObserver(mutations => {
		let title = mutations[0].target.innerText;
		if (title) {
			let matches = title.match(/\(([0-9]+)\)/);
			if (matches) {
				document.title = '('+ matches[1] + ') ' + t('x2mail', 'Email') + ' - Nextcloud';
			} else {
				document.title = t('x2mail', 'Email') + ' - Nextcloud';
			}
		}
	});
	observer.observe(target, config);
}

function X2MailFormHelper(oForm)
{
	try
	{
		var
			oSubmit = document.getElementById('x2mail-save-button'),
			sSubmitValue = oSubmit.textContent,
			oDesc = oForm.querySelector('.x2mail-result-desc')
		;

		oForm.addEventListener('submit', oEvent => {
			oEvent.preventDefault();

			oForm.classList.add('x2mail-fetch')
			oForm.classList.remove('x2mail-error')
			oForm.classList.remove('x2mail-success')

			oDesc.textContent = '';
			oSubmit.textContent = '...';

			let data = new FormData(oForm);
			data.set('appname', 'x2mail');

			fetch(OC.generateUrl('/apps/x2mail/fetch/' + oForm.getAttribute('action')), {
				mode: 'same-origin',
				cache: 'no-cache',
				redirect: 'error',
				referrerPolicy: 'no-referrer',
				credentials: 'same-origin',
				method: 'POST',
				headers: {},
				body: data
			})
			.then(response => response.json())
			.then(oData => {
				let bResult = 'success' === oData?.status;
				oForm.classList.remove('x2mail-fetch');
				oSubmit.textContent = sSubmitValue;
				if (oData?.Message) {
					oDesc.textContent = t('x2mail', oData.Message);
				}
				if (bResult) {
					oForm.classList.add('x2mail-success');
				} else {
					oForm.classList.add('x2mail-error');
					if ('' === oDesc.textContent) {
						oDesc.textContent = t('x2mail', 'Error');
					}
				}
			});

			return false;
		});
	} catch (e) {
		console.error(e);
	}
}

function setupUnifiedSearchListener() {
	const iframe = document.getElementById('rliframe');
	if (!iframe || !iframe.contentWindow) return;

	addEventListener('hashchange', (event) => {
		const hashIndex = event.newURL.indexOf('#/mailbox/');
		if (hashIndex !== -1) {
			const hash = event.newURL.substring(hashIndex + 1);
			if (/\/[\w-]+\/[\w-]+\/\w\d+\/.{0,24}/.test(hash)) {
				iframe.contentWindow.location.hash = hash;
			}
		}
	});
}
