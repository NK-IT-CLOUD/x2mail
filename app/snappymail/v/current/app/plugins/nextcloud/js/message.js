(rl => {
//	if (rl.settings.get('Nextcloud'))
	const templateId = 'MailMessageView';

	// Format ICS datetime (20260327T140000Z or VALUE=DATE:20260327) to locale string
	const formatICSDate = (raw) => {
		if (!raw) return '';
		let val = raw.replace(/^[^:]*:/, '').replace(/^VALUE=DATE:?/, '');
		const m = val.match(/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})(Z)?)?$/);
		if (!m) return raw;
		const dt = m[7]
			? new Date(Date.UTC(+m[1], m[2]-1, +m[3], +m[4]||0, +m[5]||0, +m[6]||0))
			: new Date(+m[1], m[2]-1, +m[3], +m[4]||0, +m[5]||0, +m[6]||0);
		return m[4]
			? dt.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
			: dt.toLocaleDateString(undefined, { dateStyle: 'medium' });
	};

	// Extract clean email/name from ORGANIZER/ATTENDEE value
	const formatCalAddress = (raw) => {
		if (!raw) return '';
		const cn = raw.match(/CN=([^;:]+)/i);
		const mailto = raw.match(/mailto:([^\s;]+)/i);
		return cn ? cn[1] + (mailto ? ' <' + mailto[1] + '>' : '') : (mailto ? mailto[1] : raw);
	};

	addEventListener('rl-view-model.create', e => {
		if (templateId === e.detail.viewModelTemplateID) {

			const
				template = document.getElementById(templateId),
				cfg = rl.settings.get('Nextcloud'),
				attachmentsControls = template.content.querySelector('.attachmentsControls'),
				msgMenu = template.content.querySelector('#more-view-dropdown-id + menu');

			if (attachmentsControls) {
				attachmentsControls.append(Element.fromHTML(`<span>
					<i class="fontastic iconcolor-red" data-bind="visible: saveNextcloudError">✖</i>
					<i class="fontastic" data-bind="visible: !saveNextcloudError(),
						css: {'icon-spinner': saveNextcloudLoading()}">💾</i>
					<span class="g-ui-link" data-bind="click: saveNextcloud" data-i18n="NEXTCLOUD/SAVE_ATTACHMENTS"></span>
				</span>`));

				// https://github.com/nextcloud/calendar/issues/4684
				if (cfg.CalDAV) {
					attachmentsControls.append(Element.fromHTML(`<span data-bind="visible: nextcloudICS" data-icon="📅">
						<span class="g-ui-link" data-bind="click: nextcloudSaveICS" data-i18n="NEXTCLOUD/SAVE_ICS"></span>
					</span>`));
				}
			}

			// ICS Event Card — shown prominently above attachments
			if (cfg.CalDAV) {
				const attachmentsPlace = template.content.querySelector('.attachmentsPlace');
				if (attachmentsPlace) {
					const i = k => rl.i18n(k);
					attachmentsPlace.before(Element.fromHTML(`
					<div class="sm-nc-event-card" data-bind="if: nextcloudICS, visible: nextcloudICS,
						css: {'sm-nc-event-card--cancelled': nextcloudICS() && nextcloudICS().isCancelled()}">
						<div class="sm-nc-event-card__header">
							<span class="sm-nc-event-card__title">📅 <span data-bind="text: nextcloudICS()?.SUMMARY || ''"></span></span>
							<!-- ko if: nextcloudICS() && nextcloudICS().isCancelled() -->
							<span class="sm-nc-event-card__badge">${i('NEXTCLOUD/EVENT_CARD_CANCELLED')}</span>
							<!-- /ko -->
						</div>
						<div class="sm-nc-event-card__rows">
							<!-- ko if: nextcloudICS()?.DTSTART -->
							<span class="sm-nc-event-card__label">${i('NEXTCLOUD/EVENT_CARD_WHEN')}:</span>
							<span class="sm-nc-event-card__value" data-bind="text: nextcloudICSWhen"></span>
							<!-- /ko -->
							<!-- ko if: nextcloudICS()?.ORGANIZER -->
							<span class="sm-nc-event-card__label">${i('NEXTCLOUD/EVENT_CARD_ORGANIZER')}:</span>
							<span class="sm-nc-event-card__value" data-bind="text: nextcloudICSOrganizer"></span>
							<!-- /ko -->
							<!-- ko if: nextcloudICS()?.LOCATION -->
							<span class="sm-nc-event-card__label">${i('NEXTCLOUD/EVENT_CARD_WHERE')}:</span>
							<span class="sm-nc-event-card__value" data-bind="text: nextcloudICS().LOCATION"></span>
							<!-- /ko -->
							<!-- ko if: nextcloudICS()?.ATTENDEE?.length -->
							<span class="sm-nc-event-card__label">${i('NEXTCLOUD/EVENT_CARD_ATTENDEES')}:</span>
							<span class="sm-nc-event-card__value" data-bind="text: nextcloudICSAttendees"></span>
							<!-- /ko -->
						</div>
						<div class="sm-nc-event-card__actions">
							<button class="sm-nc-event-card__save"
								data-bind="click: nextcloudSaveICS, disable: nextcloudICSSaved() || (nextcloudICS() && nextcloudICS().isCancelled())">
								<span data-bind="text: nextcloudICSSaved() ? '✓ ${i('NEXTCLOUD/EVENT_CARD_SAVED')}' : '📅 ${i('NEXTCLOUD/EVENT_CARD_SAVE')}'"></span>
							</button>
						</div>
					</div>`));
				}
			}

			if (msgMenu) {
				msgMenu.append(Element.fromHTML(`<li role="presentation">
					<a href="#" tabindex="-1" data-icon="📥" data-bind="click: nextcloudSaveMsg" data-i18n="NEXTCLOUD/SAVE_EML"></a>
				</li>`));
			}

			let view = e.detail;
			view.saveNextcloudError = ko.observable(false).extend({ falseTimeout: 7000 });
			view.saveNextcloudLoading = ko.observable(false);
			view.saveNextcloud = () => {
				const
					hashes = (view.message()?.attachments || [])
					.map(item => item?.checked() /*&& !item?.isLinked()*/ ? item.download : '')
					.filter(v => v);
				if (hashes.length) {
					view.saveNextcloudLoading(true);
					rl.nextcloud.selectFolder().then(folder => {
						if (folder) {
							rl.fetchJSON('./?/Json/&q[]=/0/', {}, {
								Action: 'AttachmentsActions',
								target: 'nextcloud',
								hashes: hashes,
								NcFolder: folder
							})
							.then(result => {
								view.saveNextcloudLoading(false);
								if (result?.Result) {
									// success
								} else {
									view.saveNextcloudError(true);
								}
							})
							.catch(() => {
								view.saveNextcloudLoading(false);
								view.saveNextcloudError(true);
							});
						} else {
							view.saveNextcloudLoading(false);
						}
					});
				}
			};

			view.nextcloudSaveMsg = () => {
				rl.nextcloud.selectFolder().then(folder => {
					let msg = view.message();
					folder && rl.pluginRemoteRequest(
						(iError, data) => {
							console.dir({
								iError:iError,
								data:data
							});
						},
						'NextcloudSaveMsg',
						{
							'msgHash': msg.requestHash,
							'folder': folder,
							'filename': msg.subject()
						}
					);
				});
			};

			view.nextcloudICS = ko.observable(null);
			view.nextcloudICSSaved = ko.observable(false);

			// Computed display values for event card
			view.nextcloudICSWhen = ko.computed(() => {
				const ev = view.nextcloudICS();
				if (!ev) return '';
				const start = formatICSDate(ev.DTSTART);
				const end = formatICSDate(ev.DTEND);
				return end ? start + ' — ' + end : start;
			});
			view.nextcloudICSOrganizer = ko.computed(() => {
				const ev = view.nextcloudICS();
				return ev ? formatCalAddress(ev.ORGANIZER) : '';
			});
			view.nextcloudICSAttendees = ko.computed(() => {
				const ev = view.nextcloudICS();
				if (!ev?.ATTENDEE) return '';
				const list = Array.isArray(ev.ATTENDEE) ? ev.ATTENDEE : [ev.ATTENDEE];
				return list.map(a => formatCalAddress(a)).join(', ');
			});

			view.nextcloudSaveICS = () => {
				let VEVENT = view.nextcloudICS();
				if (!VEVENT) return;
				rl.nextcloud.selectCalendar()
				.then(href => {
					if (href) {
						rl.nextcloud.calendarPut(href, VEVENT);
						view.nextcloudICSSaved(true);
					}
				});
			};

			view.message.subscribe(msg => {
				view.nextcloudICS(null);
				view.nextcloudICSSaved(false);
				if (msg && cfg.CalDAV) {
//					let ics = msg.attachments.find(attachment => 'application/ics' == attachment.mimeType);
					let ics = msg.attachments.find(attachment => 'text/calendar' == attachment.mimeType);
					if (ics && ics.download) {
						// fetch it and parse the VEVENT
						rl.fetch(ics.linkDownload())
						.then(response => (response.status < 400) ? response.text() : Promise.reject(new Error({ response })))
						.then(text => {
							let VEVENT,
								VALARM,
								multiple = ['ATTACH','ATTENDEE','CATEGORIES','COMMENT','CONTACT','EXDATE',
									'EXRULE','RSTATUS','RELATED','RESOURCES','RDATE','RRULE'],
								lines = text.split(/\r?\n/),
								i = lines.length;
							while (i--) {
								let line = lines[i];
								if (VEVENT) {
									while (line.startsWith(' ') && i--) {
										line = lines[i] + line.slice(1);
									}
									if (line.startsWith('END:VALARM')) {
										VALARM = {};
										continue;
									} else if (line.startsWith('BEGIN:VALARM')) {
										VEVENT.VALARM || (VEVENT.VALARM = []);
										VEVENT.VALARM.push(VALARM);
										VALARM = null;
										continue;
									} else if (line.startsWith('BEGIN:VEVENT')) {
										break;
									}
									line = line.match(/^([^:;]+)[:;](.+)$/);
									if (line) {
										if (VALARM) {
											VALARM[line[1]] = line[2];
										} else if (multiple.includes(line[1]) || 'X-' == line[1].slice(0,2)) {
											VEVENT[line[1]] || (VEVENT[line[1]] = []);
											VEVENT[line[1]].push(line[2]);
										} else {
											VEVENT[line[1]] = line[2];
										}
									}
								} else if (line.startsWith('END:VEVENT')) {
									VEVENT = {};
								}
							}
							if (VEVENT) {
								VEVENT.rawText = text;
								VEVENT.isCancelled = () => VEVENT.STATUS?.includes('CANCELLED');
								VEVENT.isConfirmed = () => VEVENT.STATUS?.includes('CONFIRMED');
								VEVENT.shouldReply = () => VEVENT.METHOD?.includes('REPLY');
								view.nextcloudICS(VEVENT);
							}
						});
					}
				}
			});
		}
	});

})(window.rl);
