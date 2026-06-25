/* X2Mail Webmail (c) 2026 NK-IT Dev | Licensed under AGPL v3 */
(function () {
	'use strict';

	const
		// import { i18n } from 'Common/Translator';
		i18n = rl.i18n,

		// import { forEachObjectValue, forEachObjectEntry } from 'Common/Utils';
		forEachObjectValue = (obj, fn) => Object.values(obj).forEach(fn),
		forEachObjectEntry = (obj, fn) => Object.entries(obj).forEach(([key, value]) => fn(key, value)),

		// import { koArrayWithDestroy } from 'External/ko';
		// With this we don't need delegateRunOnDestroy
		koArrayWithDestroy = data => {
			data = ko.observableArray(data);
			data.subscribe(changes =>
				changes.forEach(item =>
					'deleted' === item.status && null == item.moved && item.value.onDestroy?.()
				)
			, data, 'arrayChange');
			return data;
		},

		// import { koComputable } from 'External/ko';
		koComputable = fn => ko.computed(fn, {'pure':true}),

		arrayToString = (arr, separator) =>
			(arr || []).map(item => item.toString?.() || item).join(separator),
	/*
		getNotificationMessage = code => {
			let key = getKeyByValue(Notifications, code);
			return key ? I18N_DATA.NOTIFICATIONS[i18nKey(key).replace('_NOTIFICATION', '_ERROR')] : '';
			rl.i18n('NOTIFICATIONS/')
		},
		getNotification = (code, message = '', defCode = 0) => {
			code = parseInt(code, 10) || 0;
			if (Notifications.ClientViewError === code && message) {
				return message;
			}

			return getNotificationMessage(code)
				|| getNotificationMessage(parseInt(defCode, 10))
				|| '';
		},
	*/
		getNotification = code => 'ERROR ' + code,

		Remote = rl.app.Remote,

		// capabilities
		capa = ko.observableArray(),

		// Sieve scripts SieveScriptModel
		scripts = koArrayWithDestroy(),

		loading = ko.observable(false),
		serverError = ko.observable(false),
		serverErrorDesc = ko.observable(''),
		setError = text => {
			serverError(true);
			serverErrorDesc(text);
		},

		getComparators = (validOnly = 0) => {
			let result = [
				// Default
				'i;ascii-casemap',
			];
			if (capa.includes('relational') || !validOnly) {
				result.push('i;octet');
			}
			if (capa.includes('comparator-i;ascii-numeric') || !validOnly) {
				result.push('i;ascii-numeric');
			}
			if (capa.includes('comparator-i;unicode-casemap') || !validOnly) {
				result.push('i;unicode-casemap');
			}
			return result;
		},

		getMatchTypes = (validOnly = 1) => {
			let result = [':is',':contains',':matches'];
			// https://datatracker.ietf.org/doc/html/rfc6134#section-2.3
			// Only available for tests with a key_list property
			if (capa.includes('extlists') || !validOnly) {
				result.push(':list');
			}
			if (capa.includes('relational') || !validOnly) {
				result.push(':value');
				result.push(':count');
			}
			return result;
		};

	function typeCast(curValue, newValue) {
		if (null != curValue) {
			switch (typeof curValue)
			{
			case 'boolean': return 0 != newValue && !!newValue;
			case 'number': return isFinite(newValue) ? parseFloat(newValue) : 0;
			case 'string': return null != newValue ? '' + newValue : '';
			case 'object':
				if (curValue.constructor.reviveFromJson) {
					return curValue.constructor.reviveFromJson(newValue);
				}
				if (Array.isArray(curValue) && !Array.isArray(newValue))
					return [];
			}
		}
		return newValue;
	}

	class AbstractModel {
		constructor() {
	/*
			if (new.target === AbstractModel) {
				throw Error("Can't instantiate AbstractModel!");
			}
	*/
			Object.defineProperty(this, 'disposables', {value: []});
		}

		addObservables(observables) {
			forEachObjectEntry(observables, (key, value) =>
				this[key] || (this[key] = /*isArray(value) ? ko.observableArray(value) :*/ ko.observable(value))
			);
		}

		addComputables(computables) {
			forEachObjectEntry(computables, (key, fn) => this[key] = koComputable(fn));
		}

		addSubscribables(subscribables) {
			forEachObjectEntry(subscribables, (key, fn) => this.disposables.push( this[key].subscribe(fn) ) );
		}

		/** Called by delegateRunOnDestroy */
		onDestroy() {
			/** dispose ko subscribables */
			this.disposables.forEach(disposable => {
				typeof disposable?.dispose === 'function' && disposable.dispose();
			});
			/** clear object entries */
	//		forEachObjectEntry(this, (key, value) => {
			forEachObjectValue(this, value => {
				/** clear CollectionModel */
				(ko.isObservableArray(value) ? value() : value)?.onDestroy?.();
				/** destroy ko.observable/ko.computed? */
	//			dispose(value);
				/** clear object value */
	//			this[key] = null; // TODO: issue with Contacts view
			});
	//		this.disposables = [];
		}

		/**
		 * @static
		 * @param {FetchJson} json
		 * @returns {boolean}
		 */
		static validJson(json) {
			return !!(json && ('Object/'+this.name.replace('Model', '') === json['@Object']));
		}

		/**
		 * @static
		 * @param {FetchJson} json
		 * @returns {*Model}
		 */
		static reviveFromJson(json) {
			let obj = this.validJson(json) ? new this() : null;
			obj?.revivePropertiesFromJson(json);
			return obj;
		}

		revivePropertiesFromJson(json) {
			const model = this.constructor,
				valid = model.validJson(json);
			valid && forEachObjectEntry(json, (key, value) => {
				if ('@' !== key[0]) try {
					key = key[0].toLowerCase() + key.slice(1);
					switch (typeof this[key])
					{
					case 'function':
						if (ko.isObservable(this[key])) {
							this[key](typeCast(this[key](), value));
	//						console.log('Observable ' + (typeof this[key]()) + ' ' + (model.name) + '.' + key + ' revived');
						}
	//					else console.log(model.name + '.' + key + ' is a function');
						break;
					case 'boolean':
					case 'number':
					case 'object':
					case 'string':
						this[key] = typeCast(this[key], value);
						break;
					case 'undefined':
						console.log(`Undefined ${model.name}.${key} not set`);
	//					this[key] = value;
						break;
	//				default:
	//					console.log((typeof this[key])+` ${model.name}.${key} not revived`);
	//					console.log((typeof this[key])+' '+(model.name)+'.'+key+' not revived');
					}
				} catch (e) {
					console.log(model.name + '.' + key);
					console.error(e);
				}
			});
			return valid;
		}

	}

	/**
	 * @enum {string}
	 */
	const FilterConditionField = {
		From: 'From',
		Recipient: 'Recipient',
		Subject: 'Subject',
		Header: 'Header',
		Body: 'Body',
		Size: 'Size'
	};

	/**
	 * @enum {string}
	 */
	const FilterConditionType = {
		Contains: 'Contains',
		NotContains: 'NotContains',
		EqualTo: 'EqualTo',
		NotEqualTo: 'NotEqualTo',
		Regex: 'Regex',
		Over: 'Over',
		Under: 'Under',
		Text: 'Text',
		Raw: 'Raw'
	};

	class FilterConditionModel extends AbstractModel {
		constructor() {
			super();

			this.addObservables({
				field: FilterConditionField.From,
				type: FilterConditionType.Contains,
				value: '',
				valueError: false,

				valueSecond: '',
				valueSecondError: false
			});

			this.template = koComputable(() => {
				const template = 'SettingsFiltersCondition';
				switch (this.field()) {
					case FilterConditionField.Body:
						return template + 'Body';
					case FilterConditionField.Size:
						return template + 'Size';
					case FilterConditionField.Header:
						return template + 'More';
					default:
						return template + 'Default';
				}
			});

			this.addSubscribables({
				field: () => {
					this.value('');
					this.valueSecond('');
				}
			});
		}

		verify() {
			if (!this.value()) {
				this.valueError(true);
				return false;
			}

			if (FilterConditionField.Header === this.field() && !this.valueSecond()) {
				this.valueSecondError(true);
				return false;
			}

			return true;
		}

		toJSON() {
			return {
				Field: this.field(),
				Type: this.type(),
				Value: this.value(),
				ValueSecond: this.valueSecond()
			};
		}

	//	static reviveFromJson(json) {}

		cloneSelf() {
			const filterCond = new FilterConditionModel();

			filterCond.field(this.field());
			filterCond.type(this.type());
			filterCond.value(this.value());
			filterCond.valueSecond(this.valueSecond());

			return filterCond;
		}
	}

	/**
	 * @enum {string}
	 */
	const FilterAction = {
		None: 'None',
		MoveTo: 'MoveTo',
		Discard: 'Discard',
		Vacation: 'Vacation',
		Reject: 'Reject',
		Forward: 'Forward'
	};

	/**
	 * @enum {string}
	 */
	const FilterRulesType = {
		All: 'All',
		Any: 'Any'
	};

	class FilterModel extends AbstractModel {
		constructor() {
			super();

			this.id = '';

			this.addObservables({
				enabled: true,
				askDelete: false,

				name: '',
				nameError: false,

				conditionsType: FilterRulesType.Any,

				// Actions
				actionValue: '',
				actionValueError: false,

				actionValueSecond: '',
				actionValueThird: '',

				actionValueFourth: '',
				actionValueFourthError: false,

				markAsRead: false,

				keep: true,
				stop: true,

				actionType: FilterAction.MoveTo
			});

			this.conditions = koArrayWithDestroy();

			const fGetRealFolderName = folderFullName => {
	//			const folder = getFolderFromCacheList(folderFullName);
	//			return folder?.fullName.replace('.' === folder.delimiter ? /\./ : /[\\/]+/, ' / ') : folderFullName;
				return folderFullName;
			};

			this.addComputables({
				nameSub: () => {
					let result = '';
					const actionValue = this.actionValue(), root = 'SETTINGS_FILTERS/SUBNAME_';

					switch (this.actionType()) {
						case FilterAction.MoveTo:
							result = rl.i18n(root + 'MOVE_TO', {
								FOLDER: fGetRealFolderName(actionValue)
							});
							break;
						case FilterAction.Forward:
							result = rl.i18n(root + 'FORWARD_TO', {
								EMAIL: actionValue
							});
							break;
						case FilterAction.Vacation:
							result = rl.i18n(root + 'VACATION_MESSAGE');
							break;
						case FilterAction.Reject:
							result = rl.i18n(root + 'REJECT');
							break;
						case FilterAction.Discard:
							result = rl.i18n(root + 'DISCARD');
							break;
						// no default
					}

					return result ? '(' + result + ')' : '';
				},

				actionTemplate: () => {
					const result = 'SettingsFiltersAction';
					switch (this.actionType()) {
						case FilterAction.Forward:
							return result + 'Forward';
						case FilterAction.Vacation:
							return result + 'Vacation';
						case FilterAction.Reject:
							return result + 'Reject';
						case FilterAction.None:
							return result + 'None';
						case FilterAction.Discard:
							return result + 'Discard';
						case FilterAction.MoveTo:
						default:
							return result + 'MoveToFolder';
					}
				}
			});

			this.addSubscribables({
				name: sValue => this.nameError(!sValue),
				actionValue: sValue => this.actionValueError(!sValue),
				actionType: () => {
					this.actionValue('');
					this.actionValueError(false);
					this.actionValueSecond('');
					this.actionValueThird('');
					this.actionValueFourth('');
					this.actionValueFourthError(false);
				}
			});
		}

		generateID() {
			this.id = Jua.randomId();
		}

		verify() {
			if (!this.name()) {
				this.nameError(true);
				return false;
			}

			if (this.conditions.length && this.conditions.find(cond => cond && !cond.verify())) {
				return false;
			}

			if (!this.actionValue()) {
				if ([
						FilterAction.MoveTo,
						FilterAction.Forward,
						FilterAction.Reject,
						FilterAction.Vacation
					].includes(this.actionType())
				) {
					this.actionValueError(true);
					return false;
				}
			}

			if (FilterAction.Forward === this.actionType() && !this.actionValue().includes('@')) {
				this.actionValueError(true);
				return false;
			}

			if (
				FilterAction.Vacation === this.actionType() &&
				this.actionValueFourth() &&
				!this.actionValueFourth().includes('@')
			) {
				this.actionValueFourthError(true);
				return false;
			}

			this.nameError(false);
			this.actionValueError(false);

			return true;
		}

		addCondition() {
			this.conditions.push(new FilterConditionModel());
		}

		removeCondition(oConditionToDelete) {
			this.conditions.remove(oConditionToDelete);
		}

		toJSON() {
			return {
				ID: this.id,
				Enabled: this.enabled(),
				Name: this.name(),
				Conditions: this.conditions(),
				ConditionsType: this.conditionsType(),
				ActionType: this.actionType(),
				ActionValue: this.actionValue(),
				ActionValueSecond: this.actionValueSecond(),
				ActionValueThird: this.actionValueThird(),
				ActionValueFourth: this.actionValueFourth(),
				Keep: this.keep(),
				Stop: this.stop(),
				MarkAsRead: this.markAsRead()
			};
		}

		/**
		 * @static
		 * @param {FetchJsonFilter} json
		 * @returns {?FilterModel}
		 */
		static reviveFromJson(json) {
			json.id = json.ID;
			delete json.ID;
			const filter = super.reviveFromJson(json);
			if (filter) {
				filter.id = '' + (filter.id || '');
				filter.conditions(
					(json.Conditions || json.conditions || []).map(condition => {
						condition['@Object'] = 'Object/FilterCondition';
						return FilterConditionModel.reviveFromJson(condition)
					}).filter(v => v)
				);
			}
			return filter;
		}

		assignTo(target) {
			const filter = target || new FilterModel();

			filter.id = this.id;

			filter.enabled(this.enabled());

			filter.name(this.name());
			filter.nameError(this.nameError());

			filter.conditionsType(this.conditionsType());

			filter.markAsRead(this.markAsRead());

			filter.actionType(this.actionType());

			filter.actionValue(this.actionValue());
			filter.actionValueError(this.actionValueError());

			filter.actionValueSecond(this.actionValueSecond());
			filter.actionValueThird(this.actionValueThird());
			filter.actionValueFourth(this.actionValueFourth());

			filter.keep(this.keep());
			filter.stop(this.stop());

			filter.conditions(this.conditions.map(item => item.cloneSelf()));

			return filter;
		}
	}

	const SIEVE_BOOTSTRAP_NAME = 'x2mail.user';
	const FILTER_FENCE_START = '# --- X2Mail Filter (nicht bearbeiten) ---';
	const FILTER_FENCE_END = '# --- Ende X2Mail Filter ---';

	// collectionToFileString — pure, reads plain def props (no ko observables)
	function filterDefsToSieve(defs, untouched)
	{
		let eol = '\r\n',
			require = {},
			blocks = [];

		const quote = string => '"' + string.replace(/(\\|")/g, '\\$1') + '"';
		const StripSpaces = string => string.replace(/\s+/, ' ');

		// conditionToSieveScript
		const conditionToString = (condition, require) =>
		{
			let result = '',
				type = condition.Type,
				field = condition.Field,
				value = condition.Value,
				valueSecond = condition.ValueSecond;

			if (value.length && ('Header' !== field || valueSecond.length)) {
				switch (type)
				{
					case 'NotEqualTo':
						result += 'not ';
						type = ':is';
						break;
					case 'EqualTo':
						type = ':is';
						break;
					case 'NotContains':
						result += 'not ';
						type = ':contains';
						break;
					case 'Text':
					case 'Raw':
					case 'Over':
					case 'Under':
					case 'Contains':
						type = ':' + type.toLowerCase();
						break;
					case 'Regex':
						type = ':regex';
						require.regex = 1;
						break;
					default:
						return '/* @Error: unknown type value ' + type + '*/ false';
				}

				switch (field)
				{
					case 'From':
						result += 'header ' + type + ' ["From"]';
						break;
					case 'Recipient':
						result += 'header ' + type + ' ["To", "CC"]';
						break;
					case 'Subject':
						result += 'header ' + type + ' ["Subject"]';
						break;
					case 'Header':
						result += 'header ' + type + ' [' + quote(valueSecond) + ']';
						break;
					case 'Body':
						// :text :raw :content
						result += 'body ' + type + ' :contains';
						require.body = 1;
						break;
					case 'Size':
						result += 'size ' + type;
						break;
					default:
						return '/* @Error: unknown field value ' + field + ' */ false';
				}

				if (('From' === field || 'Recipient' === field) && value.includes(',')) {
					result += ' [' + value.split(',').map(value => quote(value)).join(', ') + ']';
				} else if ('Size' === field) {
					result += ' ' + value;
				} else {
					result += ' ' + quote(value);
				}

				return StripSpaces(result);
			}

			return '/* @Error: empty condition value */ false';
		};

		// filterToSieveScript
		const defToString = (def, require) =>
		{
			let sTab = '    ',
				block = true,
				result = [],
				conditions = def.Conditions;

			const errorAction = type => result.push(sTab + '# @Error (' + type + '): empty action value');

			// Conditions
			if (1 < conditions.length) {
				result.push('Any' === def.ConditionsType
					? 'if anyof('
					: 'if allof('
				);
				result.push(conditions.map(condition => sTab + conditionToString(condition, require)).join(',' + eol));
				result.push(')');
			} else if (conditions.length) {
				result.push('if ' + conditionToString(conditions[0], require));
			} else {
				block = false;
			}

			// actions
			block ? result.push('{') : (sTab = '');

			if (def.MarkAsRead && ['None','MoveTo','Forward'].includes(def.ActionType)) {
				require.imap4flags = 1;
				result.push(sTab + 'addflag "\\\\Seen";');
			}

			let value = def.ActionValue;
			value = value.length ? quote(value) : 0;
			switch (def.ActionType)
			{
				case 'None':
					break;
				case 'Discard':
					result.push(sTab + 'discard;');
					break;
				case 'Vacation':
					if (value) {
						require.vacation = 1;

						let days = 1,
							subject = '',
							addresses = '',
							paramValue = def.ActionValueSecond;

						if (paramValue.length) {
							subject = ':subject ' + quote(StripSpaces(paramValue)) + ' ';
						}

						paramValue = ('' + (def.ActionValueThird || ''));
						if (paramValue.length) {
							days = Math.max(1, parseInt(paramValue, 10));
						}

						paramValue = ('' + (def.ActionValueFourth || ''));
						if (paramValue.length) {
							paramValue = paramValue.split(',').map(email =>
								email.length ? quote(email) : ''
							).filter(email => email.length);
							if (paramValue.length) {
								addresses = ':addresses [' + paramValue.join(', ') + '] ';
							}
						}

						result.push(sTab + 'vacation :days ' + days + ' ' + addresses + subject + value + ';');
					} else {
						errorAction('vacation');
					}
					break;
				case 'Reject': {
					if (value) {
						require.reject = 1;
						result.push(sTab + 'reject ' + value + ';');
					} else {
						errorAction('reject');
					}
					break; }
				case 'Forward':
					if (value) {
						if (def.Keep) {
							require.fileinto = 1;
							result.push(sTab + 'fileinto "INBOX";');
						}
						result.push(sTab + 'redirect ' + value + ';');
					} else {
						errorAction('redirect');
					}
					break;
				case 'MoveTo':
					if (value) {
						require.fileinto = 1;
						result.push(sTab + 'fileinto ' + value + ';');
					} else {
						errorAction('fileinto');
					}
					break;
			}

			def.Stop && result.push(sTab + 'stop;');

			block && result.push('}');

			return result.join(eol);
		};

		defs.forEach(def => {
			blocks.push([
				'# ' + (def.Name || 'Filter'),
				def.Enabled ? '' : '/* @Filter is disabled ',
				defToString(def, require),
				def.Enabled ? '' : '*/'
			].filter(line => line.length).join(eol));
		});

		require = Object.keys(require);

		untouched = (untouched || '').replace(/\r?\n/g, eol).trim();

		let out = [];
		if (blocks.length) {
			if (untouched.length) {
				// Foreign content present: hoist our `require` into its own fence at
				// the very top (Sieve requires all `require` before any command),
				// keep foreign content verbatim in the middle, our rules at the bottom.
				if (require.length) {
					out.push(FILTER_FENCE_START, 'require ' + JSON.stringify(require) + ';', FILTER_FENCE_END);
				}
				out.push(untouched);
				out.push(FILTER_FENCE_START, blocks.join(eol), FILTER_FENCE_END);
			} else {
				// No foreign content: a single block, `require` inside it (still on top).
				out.push(FILTER_FENCE_START);
				if (require.length) {
					out.push('require ' + JSON.stringify(require) + ';');
				}
				out.push(blocks.join(eol), FILTER_FENCE_END);
			}
		} else if (untouched.length) {
			out.push(untouched);
		}
		// Every line — including the final comment — must end with CRLF, else the
		// GUI parser reads an unterminated hash-comment as stray commands.
		return out.length ? out.join(eol) + eol : '';
	}

	function filterModelToDef(filter) {
		return {
			Enabled: filter.enabled(), Name: filter.name(),
			Conditions: filter.conditions().map(c => ({
				Field: c.field(), Type: c.type(), Value: c.value(), ValueSecond: c.valueSecond()
			})),
			ConditionsType: filter.conditionsType(),
			ActionType: filter.actionType(), ActionValue: filter.actionValue(),
			ActionValueSecond: filter.actionValueSecond(),
			ActionValueThird: filter.actionValueThird(),
			ActionValueFourth: filter.actionValueFourth(),
			Keep: filter.keep(), Stop: filter.stop(), MarkAsRead: filter.markAsRead()
		};
	}
	function filtersToSieveScript(filters, untouched) {
		return filterDefsToSieve((filters || []).map(filterModelToDef), untouched);
	}

	function splitSieveScript(body) {
		const { defs, untouched } = sieveToFilterDefs(body);
		const filters = [];
		defs.forEach(json => {
			json['@Object'] = 'Object/Filter';
			const f = FilterModel.reviveFromJson(json);
			f && filters.push(f);
		});
		return { filters, untouched };
	}

	class SieveScriptModel extends AbstractModel
	{
		constructor() {
			super();

			this.addObservables({
				name: '',
				active: false,
				body: '',
				untouched: '',

				exists: false,
				nameError: false,
				askDelete: false,
				hasChanges: false
			});

			this.filters = koArrayWithDestroy();
	//		this.saving = ko.observable(false).extend({ debounce: 200 });

			this.addSubscribables({
				name: () => this.hasChanges(true),
				filters: () => this.hasChanges(true),
				body: () => this.hasChanges(true)
			});
		}

		filtersToRaw() {
			return filtersToSieveScript(this.filters, this.untouched());
		}

		verify() {
			this.nameError(!this.name());
			return !this.nameError();
		}

		toJSON() {
			return {
				name: this.name,
				active: this.active,
				body: this.body
	//			body: this.allowFilters() ? this.body() : this.filtersToRaw()
			};
		}

		/**
		 * The simple-filter editor binds to the server's active script.
		 */
		allowFilters() {
			return this.active();
		}

		/**
		 * @static
		 * @param {FetchJsonScript} json
		 * @returns {?SieveScriptModel}
		 */
		static reviveFromJson(json) {
			const script = super.reviveFromJson(json);
			if (script) {
				// Parse filters for EVERY script at load, not only the active one,
				// so switching the active script shows its filters immediately
				// without a page reload. allowFilters()/active() still controls
				// which script renders the inline simple-filter UI.
				const parts = splitSieveScript(script.body());
				script.filters(parts.filters);
				script.untouched(parts.untouched);
				script.exists(true);
				script.hasChanges(false);
			}
			return script;
		}

	}

	const
		// import { defaultOptionsAfterRender } from 'Common/Utils';
		defaultOptionsAfterRender = (domItem, item) =>
			item && undefined !== item.disabled && domItem?.classList.toggle('disabled', domItem.disabled = item.disabled),

		// import { folderListOptionsBuilder } from 'Common/Folders';
		/**
		 * @returns {Array}
		 */
		folderListOptionsBuilder = () => {
			const aResult = [{
					id: '',
					name: '',
					system: false,
					disabled: false
				}],
				sDeepPrefix = '\u00A0\u00A0\u00A0',
				foldersWalk = folders => {
					folders.forEach(oItem => {
						{
							aResult.push({
								id: oItem.fullName,
								name: sDeepPrefix.repeat(oItem.deep) + oItem.detailedName(),
								system: false,
								disabled: !oItem.selectable()
							});
						}

						if (oItem.subFolders.length) {
							foldersWalk(oItem.subFolders());
						}
					});
				};


			// FolderUserStore.folderList()
			foldersWalk(window.Sieve.folderList() || []);

			return aResult;
		};

	class FilterPopupView extends rl.pluginPopupView {
		constructor() {
			super('Filter');

			this.addObservables({
				isNew: true,
				filter: null,
				allowMarkAsRead: false,
				selectedFolderValue: ''
			});

			this.defaultOptionsAfterRender = defaultOptionsAfterRender;
			this.folderSelectList = koComputable(folderListOptionsBuilder);

			this.selectedFolderValue.subscribe(() => this.filter().actionValueError(false));

			['actionTypeOptions','fieldOptions','typeOptions','typeOptionsSize','typeOptionsBody'].forEach(
				key => this[key] = ko.observableArray()
			);

			this.populateOptions();
		}

		saveFilter() {
			if (FilterAction.MoveTo === this.filter().actionType()) {
				this.filter().actionValue(this.selectedFolderValue());
			}

			if (this.filter().verify()) {
				this.fTrueCallback();
				this.close();
			}
		}

		populateOptions() {
			this.actionTypeOptions([]);

			let i18nFilter = key => i18n('POPUPS_FILTER/SELECT_' + key);

			this.fieldOptions([
				{ id: FilterConditionField.From, name: i18n('GLOBAL/FROM') },
				{ id: FilterConditionField.Recipient, name: i18nFilter('FIELD_RECIPIENTS') },
				{ id: FilterConditionField.Subject, name: i18n('GLOBAL/SUBJECT') },
				{ id: FilterConditionField.Size, name: i18nFilter('FIELD_SIZE') },
				{ id: FilterConditionField.Header, name: i18nFilter('FIELD_HEADER') }
			]);

			this.typeOptions([
				{ id: FilterConditionType.Contains, name: i18nFilter('TYPE_CONTAINS') },
				{ id: FilterConditionType.NotContains, name: i18nFilter('TYPE_NOT_CONTAINS') },
				{ id: FilterConditionType.EqualTo, name: i18nFilter('TYPE_EQUAL_TO') },
				{ id: FilterConditionType.NotEqualTo, name: i18nFilter('TYPE_NOT_EQUAL_TO') }
			]);

			// this.actionTypeOptions.push({id: FilterAction.None,
			// name: i18n('GLOBAL/NONE')});
			if (capa) {
				this.allowMarkAsRead(capa.includes('imap4flags'));

				if (capa.includes('fileinto')) {
					this.actionTypeOptions.push({
						id: FilterAction.MoveTo,
						name: i18nFilter('ACTION_MOVE_TO')
					});
				}

				// redirect command
				this.actionTypeOptions.push({
					id: FilterAction.Forward,
					name: i18nFilter('ACTION_FORWARD_TO')
				});

				if (capa.includes('reject')) {
					this.actionTypeOptions.push({ id: FilterAction.Reject, name: i18nFilter('ACTION_REJECT') });
				}

				if (capa.includes('vacation')) {
					this.actionTypeOptions.push({
						id: FilterAction.Vacation,
						name: i18nFilter('ACTION_VACATION_MESSAGE')
					});
				}

				if (capa.includes('body')) {
					this.fieldOptions.push({ id: FilterConditionField.Body, name: i18nFilter('FIELD_BODY') });
				}

				if (capa.includes('regex')) {
					this.typeOptions.push({ id: FilterConditionType.Regex, name: 'Regex' });
				}
			}

			this.actionTypeOptions.push({ id: FilterAction.Discard, name: i18nFilter('ACTION_DISCARD') });

			this.typeOptionsSize([
				{ id: FilterConditionType.Over, name: i18nFilter('TYPE_OVER') },
				{ id: FilterConditionType.Under, name: i18nFilter('TYPE_UNDER') }
			]);

			this.typeOptionsBody([
				{ id: FilterConditionType.Text, name: i18nFilter('TYPE_TEXT') },
				{ id: FilterConditionType.Raw, name: i18nFilter('TYPE_RAW') }
			]);
		}

		removeCondition(oConditionToDelete) {
			this.filter().removeCondition(oConditionToDelete);
		}

		beforeShow(oFilter, fTrueCallback, bEdit) {
	//	onShow(oFilter, fTrueCallback, bEdit) {
			this.populateOptions();

			this.isNew(!bEdit);

			this.fTrueCallback = fTrueCallback;
			this.filter(oFilter);

			this.selectedFolderValue(oFilter.actionValue());
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-8
	 */

	const /**************************************************
		 * https://tools.ietf.org/html/rfc5228#section-8.1
		 **************************************************/

		/**
		 * octet-not-crlf = %x01-09 / %x0B-0C / %x0E-FF
		 * a single octet other than NUL, CR, or LF
		 */
		OCTET_NOT_CRLF = '[^\\x00\\r\\n]',

		/**
		 * octet-not-period = %x01-09 / %x0B-0C / %x0E-2D / %x2F-FF
		 * a single octet other than NUL, CR, LF, or period
		 */
		OCTET_NOT_PERIOD = '[^\\x00\\r\\n\\.]',

		/**
		 * octet-not-qspecial = %x01-09 / %x0B-0C / %x0E-21 / %x23-5B / %x5D-FF
		 * a single octet other than NUL, CR, LF, double-quote, or backslash
		 */
		OCTET_NOT_QSPECIAL = '[^\\x00\\r\\n"\\\\]',

		/**
		 * hash-comment = "#" *octet-not-crlf CRLF
		 */
		HASH_COMMENT = '#' + OCTET_NOT_CRLF + '*\\r\\n',

		/**
		 * QUANTIFIER = "K" / "M" / "G"
		 */
		QUANTIFIER = '[KMGkmg]',

		/**
		 * quoted-safe = CRLF / octet-not-qspecial
		 * either a CRLF pair, OR a single octet other than NUL, CR, LF, double-quote, or backslash
		 */
		QUOTED_SAFE = '\\r\\n|' + OCTET_NOT_QSPECIAL,

		/**
		 * quoted-special = "\" (DQUOTE / "\")
		 * represents just a double-quote or backslash
		 */
		QUOTED_SPECIAL = '\\\\\\\\|\\\\"',

		/**
		 * quoted-text = *(quoted-safe / quoted-special / quoted-other)
		 */
		QUOTED_TEXT = '(?:' + QUOTED_SAFE + '|' + QUOTED_SPECIAL + ')*',

		/**
		 * multiline-literal = [ octet-not-period *octet-not-crlf ] CRLF
		 */
		MULTILINE_LITERAL = OCTET_NOT_PERIOD + OCTET_NOT_CRLF + '*\\r\\n',

		/**
		 * multiline-dotstart = "." 1*octet-not-crlf CRLF
			; A line containing only "." ends the multi-line.
			; Remove a leading '.' if followed by another '.'.
		 */
		MULTILINE_DOTSTART = '\\.' + OCTET_NOT_CRLF + '+\\r\\n',

		/**
		 * not-star = CRLF / %x01-09 / %x0B-0C / %x0E-29 / %x2B-FF
		 * either a CRLF pair, OR a single octet other than NUL, CR, LF, or star
		 */
	//	NOT_STAR: '\\r\\n|[^\\x00\\r\\n*]',

		/**
		 * not-star-slash = CRLF / %x01-09 / %x0B-0C / %x0E-29 / %x2B-2E / %x30-FF
		 * either a CRLF pair, OR a single octet other than NUL, CR, LF, star, or slash
		 */
	//	NOT_STAR_SLASH: '\\r\\n|[^\\x00\\r\\n*\\\\]',

		/**
		 * STAR = "*"
		 */
	//	STAR = '\\*',

		/**
		 * bracket-comment = "/*" *not-star 1*STAR *(not-star-slash *not-star 1*STAR) "/"
		 */
		BRACKET_COMMENT = '/\\*[\\s\\S]*?\\*/',

		/**
		 * identifier = (ALPHA / "_") *(ALPHA / DIGIT / "_")
		 */
		IDENTIFIER = '[a-zA-Z_][a-zA-Z0-9_]*',

		/**
		 * multi-line = "text:" *(SP / HTAB) (hash-comment / CRLF)
			*(multiline-literal / multiline-dotstart)
			"." CRLF
		 */
		MULTI_LINE = 'text:[ \\t]*(?:' + HASH_COMMENT + ')?\\r\\n'
			+ '(?:' + MULTILINE_LITERAL + '|' + MULTILINE_DOTSTART + ')*'
			+ '\\.\\r\\n',

		/**
		 * number = 1*DIGIT [ QUANTIFIER ]
		 */
		NUMBER = '[0-9]+' + QUANTIFIER + '?',

		/**
		 * quoted-string = DQUOTE quoted-text DQUOTE
		 */
		QUOTED_STRING = '"' + QUOTED_TEXT + '"',

		/**
		 * tag = ":" identifier
		 */
		TAG = ':[a-zA-Z_][a-zA-Z0-9_]*',

		/**
		 * comment = bracket-comment / hash-comment
		 */
	//	COMMENT = BRACKET_COMMENT + '|' + HASH_COMMENT;

		/**************************************************
		 * https://tools.ietf.org/html/rfc5228#section-8.2
		 **************************************************/

		/**
		 * string = quoted-string / multi-line
		 */
		STRING = QUOTED_STRING + '|' + MULTI_LINE,

		/**
		 * string-list = "[" string *("," string) "]" / string
		 * if there is only a single string, the brackets are optional
		 */
		STRING_LIST = '\\[\\s*(?:' + STRING + ')(?:\\s*,\\s*(?:' + STRING + '))*\\s*\\]';

		/**
		 * arguments = *argument [ test / test-list ]
		 * This is not possible with regular expressions
		 */
	//	ARGUMENTS = '(?:\\s+' . self::ARGUMENT . ')*(\\s+?:' . self::TEST . '|' . self::TEST_LIST . ')?',

		/**
		 * block = "{" commands "}"
		 * This is not possible with regular expressions
		 */
	//	BLOCK = '{' . self::COMMANDS . '}',

		/**
		 * command = identifier arguments (";" / block)
		 * This is not possible with regular expressions
		 */
	//	COMMAND = self::IDENTIFIER . self::ARGUMENTS . '\\s+(?:;|' . self::BLOCK . ')',

		/**
		 * commands = *command
		 * This is not possible with regular expressions
		 */
	//	COMMANDS = '(?:' . self::COMMAND . ')*',

		/**
		 * start = commands
		 * This is not possible with regular expressions
		 */
	//	START = self::COMMANDS,

		/**
		 * test = identifier arguments
		 * This is not possible with regular expressions
		 */
	//	TEST = self::IDENTIFIER . self::ARGUMENTS,

		/**
		 * test-list = "(" test *("," test) ")"
		 * This is not possible with regular expressions
		 */
	//	TEST_LIST = '\\(\\s*' . self::TEST . '(?:\\s*,\\s*' . self::TEST . ')*\\s*\\)',

		/**************************************************
		 * https://tools.ietf.org/html/rfc5228#section-8.3
		 **************************************************/

		/**
		 * ADDRESS-PART = ":localpart" / ":domain" / ":all"
		 */
	//	ADDRESS_PART = ':localpart|:domain|:all',

		/**
		 * COMPARATOR = ":comparator" string
		 */
	//	COMPARATOR = ':comparator\\s+(?:' + STRING + ')';

		/**
		 * MATCH-TYPE = ":is" / ":contains" / ":matches"
		 */
	//	MATCH_TYPE = ':is|:contains|:matches'

	/**
	 * https://tools.ietf.org/html/rfc5228#section-8.2
	 */

	/**
	 * abstract
	 */
	class GrammarString /*extends String*/
	{
		constructor(value = '')
		{
			this._value = value.toString ? value.toString() : value;
		}

		toString()
		{
			return this._value;
		}

		get value()
		{
			return this._value;
		}

		set value(value)
		{
			this._value = value;
		}

		get length()
		{
			return this._value.length;
		}
	}

	/**
	 * abstract
	 */
	class GrammarComment extends GrammarString
	{
	/*
		constructor()
		{
			if (this.constructor == GrammarComment) {
				throw Error("Abstract class can't be instantiated.");
			}
		}
	*/
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-2.9
	 */
	const cmdNameSuffix = /(test|command|action)$/;
	class GrammarCommand
	{
		constructor(identifier)
		{
	/*
			if (this.constructor == GrammarCommand) {
				throw Error("Abstract class can't be instantiated.");
			}
	*/
			this.identifier = identifier || this.constructor.name.toLowerCase().replace(cmdNameSuffix, '');
		}

		toString()
		{
			let result = this.identifier;
			if (this.arguments?.length) {
				result += ' ' + arrayToString(this.arguments, ' ');
			}
			return result + ';';
		}

		pushArguments(args)
		{
			this.arguments = args;
		}
	}

	class GrammarCommands extends Array
	{
		toString()
		{
			return this.length
				? '{\r\n\t' + arrayToString(this, '\r\n\t') + '\r\n}'
				: '{}';
		}

		push(value)
		{
			if (value instanceof GrammarCommand || value instanceof GrammarComment) {
				super.push(value);
			}
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-3
	 */
	class ControlCommand extends GrammarCommand
	{
	/*
		constructor(identifier)
		{
			if (this.constructor == ControlCommand) {
				throw Error("Abstract class can't be instantiated.");
			}
			super(identifier);
		}
	*/
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-4
	 */
	class ActionCommand extends GrammarCommand
	{
	/*
		constructor(identifier)
		{
			if (this.constructor == ActionCommand) {
				throw Error("Abstract class can't be instantiated.");
			}
			super(identifier);
		}
	*/
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-5
	 */
	class TestCommand extends GrammarCommand
	{
		constructor(identifier)
		{
	/*
			if (this.constructor == TestCommand) {
				throw Error("Abstract class can't be instantiated.");
			}
	*/
			super(identifier);
			// Almost every test has a comparator and match_type, so define them here
			this._comparator = '';
			this._match_type = '';
			this.relational_match = ''; // GrammarQuotedString DQUOTE ( "gt" / "ge" / "lt" / "le" / "eq" / "ne" ) DQUOTE
		}

		get require() { return /:value|:count/.test(this._match_type) ? 'relational' : ''; }

		get match_type()
		{
			return this._match_type;
		}
		set match_type(value)
		{
			// default?
			if (':is' == value) {
				value = '';
			}
			if (value.length && !getMatchTypes(0).includes(value)) {
				throw 'Unsupported match-type ' + value;
			}
			if (':list' == value) {
				this._comparator = '';
			}
			if (':count' != value && ':value' != value) {
				this.relational_match = '';
			}
			this._match_type = value;
		}

		get comparator()
		{
			return this._comparator;
		}
		set comparator(value)
		{
			if (!(value instanceof GrammarQuotedString)) {
				value = new GrammarQuotedString(value);
			}
			// default?
			if (value.length && 'i;ascii-casemap' != value.value) {
				if (':list' == this._match_type) {
					throw 'Comparator not allowed when using :list';
				}
				if (!getComparators().includes(value.value)) {
					throw 'Unsupported comparator ' + value;
				}
				this._comparator = value;
			} else {
				this._comparator = '';
			}
		}

		toString()
		{
			return (this.identifier
				+ (this._comparator ? ' :comparator ' + this._comparator : '')
				+ (this._match_type ? ' ' + this._match_type : '')
				+ (this.relational_match ? ' ' + this.relational_match : '')
				+ ' ' + arrayToString(this.arguments, ' ')).trim();
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-5.2
	 * https://tools.ietf.org/html/rfc5228#section-5.3
	 */
	class GrammarTestList extends Array
	{
		toString()
		{
			if (1 < this.length) {
	//			return '(\r\n\t' + arrayToString(this, ',\r\n\t') + '\r\n)';
				return '(' + this.join(', ') + ')';
			}
			return this.length ? this[0].toString() : '';
		}

		push(value)
		{
			if (!(value instanceof TestCommand)) {
				throw 'Not an instanceof Test';
			}
			super.push(value);
		}
	}

	class GrammarBracketComment extends GrammarComment
	{
		toString()
		{
			return '/* ' + super.toString() + ' */';
		}
	}

	class GrammarHashComment extends GrammarComment
	{
		toString()
		{
			return '# ' + super.toString();
		}
	}

	class GrammarNumber /*extends Number*/
	{
		constructor(value = '0')
		{
			this._value = value;
		}

		toString()
		{
			return this._value;
		}

		get value()
		{
			return this._value;
		}

		set value(value)
		{
			this._value = value;
		}
	}

	class GrammarStringList extends Array
	{
		toString()
		{
			// if there is only a single string, the brackets are optional
			if (1 < this.length) {
				return '[' + this.join(',') + ']';
			}
			return this.length ? this[0].toString() : '';
		}

		push(value)
		{
			if (!(value instanceof GrammarQuotedString)) {
				value = new GrammarQuotedString(value);
			}
			super.push(value);
		}
	}

	const StringListRegEx = RegExp('(?:^\\s*|\\s*,\\s*)(?:"(' + QUOTED_TEXT + ')"|text:[ \\t]*('
		+ HASH_COMMENT + ')?\\r\\n'
		+ '((?:' + MULTILINE_LITERAL + '|' + MULTILINE_DOTSTART + ')*)'
		+ '\\.\\r\\n)', 'gm');
	GrammarStringList.fromString = list => {
		let string,
			obj = new GrammarStringList;
		list = list.replace(/^[\r\n\t[]+/, '');
		while ((string = StringListRegEx.exec(list))) {
			if (string[3]) {
				obj.push(new GrammarMultiLine(string[3], string[2]));
			} else {
				obj.push(new GrammarQuotedString(string[1]));
			}
		}
		return obj;
	};

	class GrammarQuotedString extends GrammarString
	{
		constructor(value = '')
		{
			super(value instanceof GrammarQuotedString ? value.value : value);
		}

		toString()
		{
			return '"' + this._value.replace(/[\\"]/g, '\\$&') + '"';
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-8.1
	 */
	class GrammarMultiLine extends GrammarString
	{
		constructor(value, comment = '')
		{
			super();
			this.value = value;
			this.comment = comment;
		}

		toString()
		{
			return 'text:'
				+ (this.comment ? '# ' + this.comment : '') + "\r\n"
				+ this.value
				+ "\r\n.\r\n";
		}
	}

	const MultiLineRegEx = RegExp('text:[ \\t]*(' + HASH_COMMENT + ')?\\r\\n'
		+ '((?:' + MULTILINE_LITERAL + '|' + MULTILINE_DOTSTART + ')*)'
		+ '\\.\\r\\n', 'm');
	GrammarMultiLine.fromString = string => {
		string = string.match(MultiLineRegEx);
		if (string[2]) {
			return new GrammarMultiLine(string[2].replace(/\r\n$/, ''), string[1]);
		}
		return new GrammarMultiLine();
	};

	/**
	 * https://datatracker.ietf.org/doc/html/rfc5228#section-4
	 * Action commands do not take tests or blocks as arguments.
	 */

	/**
	 * https://tools.ietf.org/html/rfc5228#section-4.1
	 */
	class FileIntoCommand extends ActionCommand
	{
		constructor()
		{
			super();
			// QuotedString / MultiLine
			this._mailbox = new GrammarQuotedString();
			// https://datatracker.ietf.org/doc/html/rfc3894
			this.copy = false;
			// https://datatracker.ietf.org/doc/html/rfc5490#section-3.2
			this.create = false;
		}

		get require() { return 'fileinto'; }

		toString()
		{
			return 'fileinto'
				+ ((this.copy && capa.includes('copy')) ? ' :copy' : '')
				+ ((this.create && capa.includes('mailbox')) ? ' :create' : '')
				+ ' ' + this._mailbox
				+ ';';
		}

		get mailbox()
		{
			return this._mailbox.value;
		}

		set mailbox(value)
		{
			this._mailbox.value = value;
		}

		pushArguments(args)
		{
			if (args[0] instanceof GrammarString) {
				this._mailbox = args[0];
			}
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-4.2
	 */
	class RedirectCommand extends ActionCommand
	{
		constructor()
		{
			super();
			// QuotedString / MultiLine
			this._address = new GrammarQuotedString();
			// https://datatracker.ietf.org/doc/html/rfc3894
			this.copy = false;
			// https://datatracker.ietf.org/doc/html/rfc6134#section-2.3
			this.list = null;
		}

		toString()
		{

			return 'redirect'
	//			+ ((this.list && capa.includes('extlists')) ? ' :list ' + this.list : '')
				+ ((this.copy && capa.includes('copy')) ? ' :copy' : '')
				+ ' ' + this._address
				+ ';';
		}

		get address()
		{
			return this._address.value;
		}

		set address(value)
		{
			this._address.value = value;
		}

		pushArguments(args)
		{
			if (args[0] instanceof GrammarString) {
				this._address = args[0];
			}
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-4.3
	 */
	class KeepCommand extends ActionCommand
	{
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-4.4
	 */
	class DiscardCommand extends ActionCommand
	{
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-2.9
	 * A control structure is a control command that ends with a block instead of a semicolon.
	 */

	/**
	 * https://tools.ietf.org/html/rfc5228#section-3.1
	 * Usage:
	 *    if <test1: test> <block1: block>
	 *    elsif <test2: test> <block2: block>
	 *    else <block3: block>
	 */
	class ConditionalCommand extends ControlCommand
	{
		constructor()
		{
	/*
			if (this.constructor == ConditionalCommand) {
				throw Error("Abstract class can't be instantiated.");
			}
	*/
			super();
			this.commands = new GrammarCommands;
		}
	}

	class IfCommand extends ConditionalCommand
	{
		constructor()
		{
			super();
			this._test = null; // must be descendent instanceof TestCommand
		}

		get test()
		{
			return this._test;
		}

		set test(value)
		{
	/*
			if (!value instanceof TestCommand) {
				throw Error("test must be descendent instanceof TestCommand.");
			}
	*/
			this._test = value;
		}

		toString()
		{
	/*
			if (!this._test instanceof TestCommand) {
				throw Error("test must be descendent instanceof TestCommand.");
			}
	*/
			return this.identifier + ' ' + this._test + ' ' + this.commands;
		}
	}

	class ElsIfCommand extends IfCommand
	{
	}

	class ElseCommand extends ConditionalCommand
	{
		toString()
		{
			return this.identifier + ' ' + this.commands;
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-3.2
	 */
	class RequireCommand extends ControlCommand
	{
		constructor()
		{
			super();
			this.capabilities = new GrammarStringList();
		}

		toString()
		{
			return 'require ' + this.capabilities + ';';
		}

		pushArguments(args)
		{
			if (args[0] instanceof GrammarStringList) {
				this.capabilities = args[0];
			} else if (args[0] instanceof GrammarQuotedString) {
				this.capabilities.push(args[0]);
			}
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-3.3
	 */
	class StopCommand extends ControlCommand
	{
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-5
	 */

	const
		isAddressPart = tag => ':localpart' === tag || ':domain' === tag || ':all' === tag || isSubAddressPart(tag),
		// https://tools.ietf.org/html/rfc5233
		isSubAddressPart = tag => ':user' === tag || ':detail' === tag,

		asStringList = arg => {
			if (arg instanceof GrammarStringList) {
				return arg;
			}
			let args = new GrammarStringList();
			if (arg instanceof GrammarString) {
				args.push(arg.value);
			}
			return args;
		};

	/**
	 * https://tools.ietf.org/html/rfc5228#section-5.1
	 */
	class AddressTest extends TestCommand
	{
		constructor()
		{
			super();
			this.address_part = ':all';
			this.header_list  = new GrammarStringList;
			this.key_list     = new GrammarStringList;
			// rfc5260#section-6
			this.index        = new GrammarNumber('');
			this.last         = false;
			// rfc5703#section-6
	//		this.mime
	//		this.anychild
		}

		get require() {
			let requires = [];
			isSubAddressPart(this.address_part) && requires.push('subaddress');
			(this.last || (this.index && this.index.value)) && requires.push('index');
			(this.mime || this.anychild) && requires.push('mime');
			return requires;
		}

		toString()
		{
			let result = 'address';
			if (capa.includes('mime')) {
				if (this.mime) {
					result += ' :mime';
				}
				if (this.anychild) {
					result += ' :anychild';
				}
			}
			return result
				+ (this.last ? ' :last' : (this.index.value ? ' :index ' + this.index : ''))
				+ (this._comparator ? ' :comparator ' + this._comparator : '')
				+ ' ' + this.address_part
				+ (this._match_type ? ' ' + this._match_type : '')
				+ (this.relational_match ? ' ' + this.relational_match : '')
				+ ' ' + this.header_list
				+ ' ' + this.key_list;
		}

		pushArguments(args)
		{
			this.key_list = asStringList(args.pop());
			this.header_list = asStringList(args.pop());
			args.forEach((arg, i) => {
				if (isAddressPart(arg)) {
					this.address_part = arg;
				} else if (':last' === arg) {
					this.last = true;
				} else if (':mime' === arg) {
					this.mime = true;
				} else if (':anychild' === arg) {
					this.anychild = true;
				} else if (i && ':index' === args[i-1]) {
					this.index.value = arg.value;
				}
			});
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-5.2
	 */
	class AllOfTest extends TestCommand
	{
		constructor()
		{
			super();
			this.tests = new GrammarTestList;
		}

		toString()
		{
			return 'allof ' + this.tests;
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-5.3
	 */
	class AnyOfTest extends TestCommand
	{
		constructor()
		{
			super();
			this.tests = new GrammarTestList;
		}

		toString()
		{
			return 'anyof ' + this.tests;
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-5.4
	 */
	class EnvelopeTest extends TestCommand
	{
		constructor()
		{
			super();
			this.address_part = ':all';
			this.envelope_part = new GrammarStringList;
			this.key_list      = new GrammarStringList;
		}

		get require() { return isSubAddressPart(this.address_part) ? ['envelope','subaddress'] : 'envelope'; }

		toString()
		{
			return 'envelope'
				+ (this._comparator ? ' :comparator ' + this._comparator : '')
				+ ' ' + this.address_part
				+ (this._match_type ? ' ' + this._match_type : '')
				+ (this.relational_match ? ' ' + this.relational_match : '')
				+ ' ' + this.envelope_part
				+ ' ' + this.key_list;
		}

		pushArguments(args)
		{
			this.key_list = asStringList(args.pop());
			this.envelope_part = asStringList(args.pop());
			args.forEach(arg => {
				if (isAddressPart(arg)) {
					this.address_part = arg;
				}
			});
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-5.5
	 */
	class ExistsTest extends TestCommand
	{
		constructor()
		{
			super();
			this.header_names = new GrammarStringList;
			// rfc5703#section-6
	//		this.mime
	//		this.anychild
		}

		get require() {
			return (this.mime || this.anychild) ? ['mime'] : null;
		}

		toString()
		{
			let result = 'exists';
			if (capa.includes('mime')) {
				if (this.mime) {
					result += ' :mime';
				}
				if (this.anychild) {
					result += ' :anychild';
				}
			}
			return result + ' ' + this.header_names;
		}

		pushArguments(args)
		{
			this.header_names = asStringList(args.pop());
			args.forEach(arg => {
				if (':mime' === arg) {
					this.mime = true;
				} else if (':anychild' === arg) {
					this.anychild = true;
				}
			});
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-5.6
	 */
	class FalseTest extends TestCommand
	{
		toString()
		{
			return "false";
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-5.7
	 */
	class HeaderTest extends TestCommand
	{
		constructor()
		{
			super();
			this.address_part = ':all';
			this.header_names = new GrammarStringList;
			this.key_list     = new GrammarStringList;
			// rfc5260#section-6
			this.index        = new GrammarNumber('');
			this.last         = false;
			// rfc5703#section-6
			this.mime         = false;
			this.anychild     = false;
			// when ":mime" is used:
			this.type         = false;
			this.subtype      = false;
			this.contenttype  = false;
			this.param        = new GrammarStringList;
		}

		get require() {
			let requires = [];
			isSubAddressPart(this.address_part) && requires.push('subaddress');
			(this.last || (this.index && this.index.value)) && requires.push('index');
			(this.mime || this.anychild) && requires.push('mime');
			return requires;
		}

		toString()
		{
			let result = 'header';
			if (capa.includes('mime')) {
				if (this.mime) {
					result += ' :mime';
					if (this.type) {
						result += ' :type';
					}
					if (this.subtype) {
						result += ' :subtype';
					}
					if (this.contenttype) {
						result += ' :contenttype';
					}
					if (this.param.length) {
						result += ' :param ' + this.param;
					}
				}
				if (this.anychild) {
					result += ' :anychild';
				}
			}
			return result
				+ (this.last ? ' :last' : (this.index.value ? ' :index ' + this.index : ''))
				+ (this._comparator ? ' :comparator ' + this._comparator : '')
				+ (this._match_type ? ' ' + this._match_type : '')
				+ (this.relational_match ? ' ' + this.relational_match : '')
				+ ' ' + this.header_names
				+ ' ' + this.key_list;
		}

		pushArguments(args)
		{
			this.key_list = asStringList(args.pop());
			this.header_names = asStringList(args.pop());
			args.forEach((arg, i) => {
				if (isAddressPart(arg)) {
					this.address_part = arg;
				} else if (':last' === arg) {
					this.last = true;
				} else if (i && ':index' === args[i-1]) {
					this.index.value = arg.value;
				}
			});
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-5.8
	 */
	class NotTest extends TestCommand
	{
		constructor()
		{
			super();
			this.test = new TestCommand;
		}

		toString()
		{
			return 'not ' + this.test;
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-5.9
	 */
	class SizeTest extends TestCommand
	{
		constructor()
		{
			super();
			this.mode  = ':over'; // :under
			this.limit = 0;
		}

		toString()
		{
			return 'size ' + this.mode + ' ' + this.limit;
		}

		pushArguments(args)
		{
			args.forEach(arg => {
				if (':over' === arg || ':under' === arg) {
					this.mode = arg;
				} else if (arg instanceof GrammarNumber) {
					this.limit = arg;
				}
			});
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5228#section-5.10
	 */
	class TrueTest extends TestCommand
	{
		toString()
		{
			return 'true';
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5173
	 */

	class BodyTest extends TestCommand
	{
		constructor()
		{
			super();
			this.body_transform = ''; // :raw, :content <string-list>, :text
			this.key_list = new GrammarStringList;
		}

		get require() { return 'body'; }

		toString()
		{
			return 'body'
				+ (this._comparator ? ' :comparator ' + this._comparator : '')
				+ (this._match_type ? ' ' + this._match_type : '')
				+ (this.relational_match ? ' ' + this.relational_match : '')
				+ ' ' + this.body_transform
				+ ' ' + this.key_list;
		}

		pushArguments(args)
		{
			args.forEach((arg, i) => {
				if (':raw' === arg || ':text' === arg) {
					this.body_transform = arg;
				} else if (arg instanceof GrammarStringList || arg instanceof GrammarString) {
					if (i && ':content' === args[i-1]) {
						this.body_transform = ':content ' + arg;
					} else {
						this[args[i+1] ? 'content_list' : 'key_list'] = arg;
					}
				}
			});
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5183
	 */

	class EnvironmentTest extends TestCommand
	{
		constructor()
		{
			super();
			this._name    = new GrammarQuotedString;
			this.key_list = new GrammarStringList;
		}

		get name() { return this._name.value; }
		set name(v) { this._name.value = v; }

		get require() { return 'environment'; }

		toString()
		{
			return 'environment'
				+ (this._comparator ? ' :comparator ' + this._comparator : '')
				+ (this._match_type ? ' ' + this._match_type : '')
				+ (this.relational_match ? ' ' + this.relational_match : '')
				+ ' ' + this._name
				+ ' ' + this.key_list;
		}

		pushArguments(args)
		{
			this.key_list = args.pop();
			this._name    = args.pop();
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5229
	 */

	class SetCommand extends ActionCommand
	{
		constructor()
		{
			super();
			this.modifiers = [];
			this._name    = new GrammarQuotedString;
			this._value   = new GrammarQuotedString;
		}

		get require() { return 'variables'; }

		get name()     { return this._name.value; }
		set name(str)  { this._name.value = str; }

		get value()    { return this._value.value; }
		set value(str) { this._value.value = str; }

		toString()
		{
			return 'set'
				+ ' ' + this.modifiers.join(' ')
				+ ' ' + this._name
				+ ' ' + this._value;
		}

		pushArguments(args)
		{
			[':lower', ':upper', ':lowerfirst', ':upperfirst', ':quotewildcard', ':length'].forEach(modifier => {
				args.includes(modifier) && this.modifiers.push(modifier);
			});
			this._value = args.pop();
			this._name  = args.pop();
		}
	}

	class StringTest extends TestCommand
	{
		constructor()
		{
			super();
			this.source   = new GrammarStringList;
			this.key_list = new GrammarStringList;
		}

		toString()
		{
			return 'string'
				+ (this._match_type ? ' ' + this._match_type : '')
				+ (this.relational_match ? ' ' + this.relational_match : '')
				+ (this._comparator ? ' :comparator ' + this._comparator : '')
				+ ' ' + this.source
				+ ' ' + this.key_list;
		}

		pushArguments(args)
		{
			this.key_list = args.pop();
			this.source   = args.pop();
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5230
	 * https://tools.ietf.org/html/rfc6131
	 */

	class VacationCommand extends ActionCommand
	{
		constructor()
		{
			super();
			this._days      = new GrammarNumber;
			this._seconds   = new GrammarNumber;
			this._subject   = new GrammarQuotedString;
			this._from      = new GrammarQuotedString;
			this.addresses  = new GrammarStringList;
			this.mime       = false;
			this._handle    = new GrammarQuotedString;
			this._reason    = new GrammarQuotedString; // QuotedString / MultiLine
		}

	//	get require() { return ['vacation','vacation-seconds']; }
		get require() { return 'vacation'; }

		get days()      { return this._days.value; }
		get seconds()   { return this._seconds.value; }
		get subject()   { return this._subject.value; }
		get from()      { return this._from.value; }
		get handle()    { return this._handle.value; }
		get reason()    { return this._reason.value; }

		set days(int)    { this._days.value = int; }
		set seconds(int) { this._seconds.value = int; }
		set subject(str) { this._subject.value = str; }
		set from(str)    { this._from.value = str; }
		set handle(str)  { this._handle.value = str; }
		set reason(str)  { this._reason.value = str; }

		toString()
		{
			let result = 'vacation';
			if (0 < this._seconds.value && capa.includes('vacation-seconds')) {
				result += ' :seconds ' + this._seconds;
			} else if (0 < this._days.value) {
				result += ' :days ' + this._days;
			}
			if (this._subject.length) {
				result += ' :subject ' + this._subject;
			}
			if (this._from.length) {
				result += ' :from ' + this._from;
			}
			if (this.addresses.length) {
				result += ' :addresses ' + this.addresses;
			}
			if (this.mime) {
				result += ' :mime';
			}
			if (this._handle.length) {
				result += ' :handle ' + this._handle;
			}
			return result + ' ' + this._reason;
		}

		pushArguments(args)
		{
			this._reason.value = args.pop().value; // GrammarQuotedString
			args.forEach((arg, i) => {
				if (':mime' === arg) {
					this.mime = true;
				} else if (i && ':addresses' === args[i-1]) {
					this.addresses = arg; // GrammarStringList
				} else if (i && ':' === args[i-1][0]) {
					// :days, :seconds, :subject, :from, :handle
					let p = args[i-1].replace(':','_');
					this[p] ? (this[p].value = arg.value) : console.log('Unknown VacationCommand :' + p);
				}
			});
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5232
	 */

	class FlagCommand extends ActionCommand
	{
		constructor()
		{
			super();
			this._variablename = new GrammarQuotedString;
			this.list_of_flags = new GrammarStringList;
		}

		get require() { return 'imap4flags'; }

		toString()
		{
			let name = this._variablename;
			return this.identifier + (name.length ? ' ' + this.variablename : '') + ' ' + this.list_of_flags + ';';
		}

		get variablename()
		{
			return this._variablename.value;
		}

		set variablename(value)
		{
			this._variablename.value = value;
		}

		pushArguments(args)
		{
			if (args[1]) {
				if (args[0] instanceof GrammarQuotedString) {
					this._variablename = args[0];
				}
				args[0] = args[1];
			}
			if (args[0] instanceof GrammarStringList) {
				this.list_of_flags = args[0];
			} else if (args[0]) {
				this.list_of_flags.push(args[0]);
			}
		}
	}

	class SetFlagCommand extends FlagCommand
	{
	}

	class AddFlagCommand extends FlagCommand
	{
	}

	class RemoveFlagCommand extends FlagCommand
	{
	}

	class HasFlagTest extends TestCommand
	{
		constructor()
		{
			super();
			this.variable_list = new GrammarStringList;
			this.list_of_flags = new GrammarStringList;
		}

		get require() { return 'imap4flags'; }

		toString()
		{
			return 'hasflag'
				+ (this._match_type ? ' ' + this._match_type : '')
				+ (this.relational_match ? ' ' + this.relational_match : '')
				+ (this._comparator ? ' :comparator ' + this._comparator : '')
				+ ' ' + this.variable_list
				+ ' ' + this.list_of_flags;
		}

		pushArguments(args)
		{
			args.forEach((arg, i) => {
				if (arg instanceof GrammarStringList || arg instanceof GrammarString) {
					this[args[i+1] ? 'variable_list' : 'list_of_flags'] = arg;
				}
			});
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5235
	 */

	class SpamTestTest extends TestCommand
	{
		constructor()
		{
			super();
			this.percent = false, // 0 - 100 else 0 - 10
			this._value = new GrammarQuotedString;
		}

	//	get require() { return this.percent ? 'spamtestplus' : 'spamtest'; }
		get require() { return /:value|:count/.test(this._match_type) ? ['spamtestplus','relational'] : 'spamtestplus'; }

		get value() { return this._value.value; }
		set value(v) { this._value.value = v; }

		toString()
		{
			return 'spamtest'
				+ (this.percent ? ' :percent' : '')
				+ (this._comparator ? ' :comparator ' + this._comparator : '')
				+ (this._match_type ? ' ' + this._match_type : '')
				+ (this.relational_match ? ' ' + this.relational_match : '')
				+ ' ' + this._value;
		}

		pushArguments(args)
		{
			args.forEach(arg => {
				if (':percent' === arg) {
					this.percent = true;
				} else if (arg instanceof GrammarQuotedString) {
					this._value = arg;
				}
			});
		}
	}

	class VirusTestTest extends TestCommand
	{
		constructor()
		{
			super();
			this._value = new GrammarQuotedString; // 1 - 5
		}

		get require() { return ':value' == this._match_type ? ['virustest','relational'] : 'virustest'; }

		get value() { return this._value.value; }
		set value(v) { this._value.value = v; }

		toString()
		{
			return 'virustest'
				+ (this._comparator ? ' :comparator ' + this._comparator : '')
				+ (this._match_type ? ' ' + this._match_type : '')
				+ (this.relational_match ? ' ' + this.relational_match : '')
				+ ' ' + this._value;
		}

		pushArguments(args)
		{
			args.forEach(arg => {
				if (arg instanceof GrammarQuotedString) {
					this._value = arg;
				}
			});
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5260
	 */

	class DateTest extends TestCommand
	{
		constructor()
		{
			super();
			this._zone        = new GrammarQuotedString;
			this.originalzone = false;
			this._header_name = new GrammarQuotedString;
			this._date_part   = new GrammarQuotedString;
			this.key_list     = new GrammarStringList;
			// rfc5260#section-6
			this.index        = new GrammarNumber;
			this.last         = false;
		}

	//	get require() { return ['date','index']; }
		get require() { return 'date'; }

		get zone() { return this._zone.value; }
		set zone(v) { this._zone.value = v; }

		get header_name() { return this._header_name.value; }
		set header_name(v) { this._header_name.value = v; }

		get date_part() { return this._date_part.value; }
		set date_part(v) { this._date_part.value = v; }

		toString()
		{
			return 'date'
				+ (this.last ? ' :last' : (this.index.value ? ' :index ' + this.index : ''))
				+ (this.originalzone ? ' :originalzone' : (this._zone.length ? ' :zone ' + this._zone : ''))
				+ (this._comparator ? ' :comparator ' + this._comparator : '')
				+ (this._match_type ? ' ' + this._match_type : '')
				+ (this.relational_match ? ' ' + this.relational_match : '')
				+ ' ' + this._header_name
				+ ' ' + this._date_part
				+ ' ' + this.key_list;
		}

		pushArguments(args)
		{
			this.key_list = args.pop();
			this._date_part = args.pop();
			this._header_name = args.pop();
			args.forEach((arg, i) => {
				if (':originalzone' === arg) {
					this.originalzone = true;
				} else if (':last' === arg) {
					this.last = true;
				} else if (i && ':zone' === args[i-1]) {
					this._zone.value = arg.value;
				} else if (i && ':index' === args[i-1]) {
					this.index.value = arg.value;
				}
			});
		}
	}

	class CurrentDateTest extends TestCommand
	{
		constructor()
		{
			super();
			this._zone      = new GrammarQuotedString;
			this._date_part = new GrammarQuotedString;
			this.key_list   = new GrammarStringList;
		}

		get require() { return 'date'; }

		get zone() { return this._zone.value; }
		set zone(v) { this._zone.value = v; }

		get date_part() { return this._date_part.value; }
		set date_part(v) { this._date_part.value = v; }

		toString()
		{
			return 'currentdate'
				+ (this._zone.length ? ' :zone ' + this._zone : '')
				+ (this._comparator ? ' :comparator ' + this._comparator : '')
				+ (this._match_type ? ' ' + this._match_type : '')
				+ (this.relational_match ? ' ' + this.relational_match : '')
				+ ' ' + this._date_part
				+ ' ' + this.key_list;
		}

		pushArguments(args)
		{
			this.key_list = args.pop();
			this._date_part = args.pop();
			args.forEach((arg, i) => {
				if (i && ':zone' === args[i-1]) {
					this._zone.value = arg.value;
				}
			});
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5293
	 */

	class AddHeaderCommand extends ActionCommand
	{
		constructor()
		{
			super();
			this.last       = false;
			this._field_name = new GrammarQuotedString;
			this._value      = new GrammarQuotedString;
		}

		get require() { return 'editheader'; }

		get field_name() { return this._field_name.value; }
		set field_name(v) { this._field_name.value = v; }

		get value() { return this._value.value; }
		set value(v) { this._value.value = v; }

		toString()
		{
			return this.identifier
				+ (this.last ? ' :last' : '')
				+ ' ' + this._field_name
				+ ' ' + this._value + ';';
		}

		pushArguments(args)
		{
			this._value = args.pop();
			this._field_name = args.pop();
			this.last = args.includes(':last');
		}
	}

	class DeleteHeaderCommand extends ActionCommand
	{
		constructor()
		{
			super();
			this.index          = new GrammarNumber;
			this.last           = false;
			this.comparator     = '',
			this.match_type     = ':is',
			this._field_name    = new GrammarQuotedString;
			this.value_patterns = new GrammarStringList;
		}

		get require() { return 'editheader'; }

		get field_name() { return this._field_name.value; }
		set field_name(v) { this._field_name.value = v; }

		toString()
		{
			return this.identifier
				+ (this.last ? ' :last' : (this.index.value ? ' :index ' + this.index : ''))
				+ (this.comparator ? ' :comparator ' + this.comparator : '')
				+ ' ' + this.match_type
				+ ' ' + this._field_name
				+ ' ' + this.value_patterns + ';';
		}

		pushArguments(args)
		{
			let l = args.length - 1;
			args.forEach((arg, i) => {
				if (':last' === arg) {
					this.last = true;
				} else if (i && ':index' === args[i-1]) {
					this.index.value = arg.value;
					args[i] = null;
				}
			});

			if (l && args[l-1] instanceof GrammarString) {
				this._field_name = args[l-1];
				this.value_patterns = args[l];
			} else {
				this._field_name = args[l];
			}
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5429
	 */

	class /*abstract*/ rfc5429Command extends ActionCommand
	{
		constructor()
		{
			super();
			this._reason = new GrammarQuotedString;
		}

		toString()
		{
			return this.require + ' ' + this._reason + ';';
		}

		get reason()
		{
			return this._reason.value;
		}

		set reason(value)
		{
			this._reason.value = value;
		}

		pushArguments(args)
		{
			if (args[0] instanceof GrammarString) {
				this._reason = args[0];
			}
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5429#section-2.1
	 */
	class ErejectCommand extends rfc5429Command
	{
		get require() { return 'ereject'; }
	}

	/**
	 * https://tools.ietf.org/html/rfc5429#section-2.2
	 */
	class RejectCommand extends rfc5429Command
	{
		get require() { return 'reject'; }
	}

	/**
	 * https://tools.ietf.org/html/rfc5435
	 */

	/**
	 * https://datatracker.ietf.org/doc/html/rfc5435#section-3
	 */
	class NotifyCommand extends ActionCommand
	{
		constructor()
		{
			super();
			this._method = new GrammarQuotedString;
			this._from = new GrammarQuotedString;
			this._importance = new GrammarNumber;
			this.options = new GrammarStringList;
			this._message = new GrammarQuotedString;
		}

		get method()     { return this._method.value; }
		set method(str)  { this._method.value = str; }

		get from()       { return this._from.value; }
		set from(str)    { this._from.value = str; }

		get importance() { return this._importance.value; }
		set importance(int) { this._importance.value = int; }

		get message()    { return this._message.value; }
		set message(str) { this._message.value = str; }

		get require() { return 'enotify'; }

		toString()
		{
			let result = 'notify';
			if (this._from.value) {
				result += ' :from ' + this._from;
			}
			if (0 < this._importance.value) {
				result += ' :importance ' + this._importance;
			}
			if (this.options.length) {
				result += ' :options ' + this.options;
			}
			if (this._message.value) {
				result += ' :message ' + this._message;
			}
			return result + ' ' + this._method;
		}

		pushArguments(args)
		{
			this._method.value = args.pop().value; // GrammarQuotedString
			args.forEach((arg, i) => {
				if (i && ':options' === args[i-1]) {
					this.options = arg; // GrammarStringList
				} else if (i && ':' === args[i-1][0]) {
					// :from, :importance, :message
					let p = args[i-1].replace(':','_');
					this[p] ? (this[p].value = arg.value) : console.log('Unknown VacationCommand :' + p);
				}
			});
		}
	}

	/**
	 * https://datatracker.ietf.org/doc/html/rfc5435#section-4
	 */
	class ValidNotifyMethodTest extends TestCommand
	{
		constructor()
		{
			super();
			this.notification_uris = new GrammarStringList;
		}

		toString()
		{
			return 'valid_notify_method ' + this.notification_uris;
		}

		pushArguments(args)
		{
			this.notification_uris = args.pop();
		}
	}

	/**
	 * https://datatracker.ietf.org/doc/html/rfc5435#section-5
	 */
	class NotifyMethodCapabilityTest extends TestCommand
	{
		constructor()
		{
			super();
			this._notification_uri = new GrammarQuotedString;
			this._notification_capability = new GrammarQuotedString;
			this.key_list = new GrammarStringList;
		}

		get notification_uri() { return this._notification_uri.value; }
		set notification_uri(v) { this._notification_uri.value = v; }

		get notification_capability() { return this._notification_capability.value; }
		set notification_capability(v) { this._notification_capability.value = v; }

		toString()
		{
			return 'valid_notify_method '
				+ (this._comparator ? ' :comparator ' + this._comparator : '')
				+ (this._match_type ? ' ' + this._match_type : '')
				+ (this.relational_match ? ' ' + this.relational_match : '')
				+ this._notification_uri
				+ this._notification_capability
				+ this.key_list;
		}

		pushArguments(args)
		{
			this.key_list = args.pop();
			this._notification_capability = args.pop();
			this._notification_uri = args.pop();
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5463
	 */

	/**
	 * https://datatracker.ietf.org/doc/html/rfc5463#section-4
	 */
	class IHaveTest extends TestCommand
	{
		constructor()
		{
			super();
			this.capabilities = new GrammarStringList;
		}

		get require() { return 'ihave'; }

		toString()
		{
			return 'ihave ' + this.capabilities;
		}

		pushArguments(args)
		{
			this.capabilities = args.pop();
		}
	}

	/**
	 * https://datatracker.ietf.org/doc/html/rfc5463#section-5
	 */
	class ErrorCommand extends ControlCommand
	{
		constructor()
		{
			super();
			this._message = new GrammarQuotedString;
		}

		get require() { return 'ihave'; }

		get message() { return this._message.value; }
		set message(v) { this._message.value = v; }

		toString()
		{
			return 'error ' + this._message + ';';
		}

		pushArguments(args)
		{
			this._message = args.pop();
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5490
	 */

	/**
	 * https://datatracker.ietf.org/doc/html/rfc5490#section-3.1
	 */
	class MailboxExistsTest extends TestCommand
	{
		constructor()
		{
			super();
			this.mailbox_names = new GrammarStringList;
		}

		get require() { return 'mailbox'; }

		toString()
		{
			return 'mailboxexists ' + this.mailbox_names + ';';
		}

		pushArguments(args)
		{
			if (args[0] instanceof GrammarStringList) {
				this.mailbox_names = args[0];
			}
		}
	}

	/**
	 * https://datatracker.ietf.org/doc/html/rfc5490#section-3.3
	 */
	class MetadataTest extends TestCommand
	{
		constructor()
		{
			super();
			this._mailbox = new GrammarQuotedString;
			this._annotation_name = new GrammarQuotedString;
			this.key_list = new GrammarStringList;
		}

		get require() { return 'mboxmetadata'; }

		get mailbox() { return this._mailbox.value; }
		set mailbox(v) { this._mailbox.value = v; }

		get annotation_name() { return this._annotation_name.value; }
		set annotation_name(v) { this._annotation_name.value = v; }

		toString()
		{
			return 'metadata '
				+ ' ' + this._match_type
				+ (this.relational_match ? ' ' + this.relational_match : '')
				+ (this._comparator ? ' :comparator ' + this._comparator : '')
				+ ' ' + this._mailbox
				+ ' ' + this._annotation_name
				+ ' ' + this.key_list;
		}

		pushArguments(args)
		{
			this.key_list = args.pop();
			this._annotation_name = args.pop();
			this._mailbox = args.pop();
		}
	}

	/**
	 * https://datatracker.ietf.org/doc/html/rfc5490#section-3.4
	 */
	class MetadataExistsTest extends TestCommand
	{
		constructor()
		{
			super();
			this._mailbox = new GrammarQuotedString;
			this.annotation_names = new GrammarStringList;
		}

		get require() { return 'mboxmetadata'; }

		get mailbox() { return this._mailbox.value; }
		set mailbox(v) { this._mailbox.value = v; }

		toString()
		{
			return 'metadataexists '
				+ ' ' + this._mailbox
				+ ' ' + this.annotation_names;
		}

		pushArguments(args)
		{
			this.annotation_names = args.pop();
			this._mailbox = args.pop();
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc5703
	 */

	/**
	 * https://datatracker.ietf.org/doc/html/rfc5703#section-3
	 */
	class ForEveryPartCommand extends ControlCommand
	{
		constructor()
		{
			super();
			this.name = new GrammarString;
			this.commands = new GrammarCommands;
		}

		get require() { return 'foreverypart'; }

		toString()
		{
			let result = 'foreverypart';
			if (this.name.length) {
				result += ' :name ' + this.name;
			}
			return result + ' ' + this.commands;
		}

		pushArguments(args)
		{
			args.forEach((arg, i) => {
				if (':name' === arg) {
					this.name.value = args[i+1].value;
				}
			});
		}
	}

	/**
	 * Must be inside foreverypart
	 */
	class BreakCommand extends ForEveryPartCommand
	{
		toString()
		{
			let result = 'break';
			if (this.name.length) {
				result += ' :name ' + this.name;
			}
			return result + ';';
		}
	}

	/**
	 * https://datatracker.ietf.org/doc/html/rfc5703#section-5
	 */
	class ReplaceCommand extends ActionCommand
	{
		constructor()
		{
			super();
			this.mime         = false;
			this._subject     = new GrammarQuotedString;
			this._from        = new GrammarQuotedString;
			this._replacement = new GrammarQuotedString;
		}

		get require() { return 'replace'; }

		get subject()     { return this._subject.value; }
		set subject(str)  { this._subject.value = str; }

		get from()        { return this._from.value; }
		set from(str)     { this._from.value = str; }

		get replacement()    { return this._replacement.value; }
		set replacement(str) { this._replacement.value = str; }

		toString()
		{
			let result = 'replace';
			if (this.mime) {
				result += ' :mime';
			}
			if (this._subject.length) {
				result += ' :subject ' + this._subject;
			}
			if (this._from.length) {
				result += ' :from ' + this._from;
			}
			return result + this._replacement + ';';
		}

		pushArguments(args)
		{
			this._replacement = args.pop();
			args.forEach((arg, i) => {
				if (':mime' === arg) {
					this.mime = true;
				} else if (i && ':' === args[i-1][0]) {
					// :subject, :from
					let p = args[i-1].replace(':','_');
					this[p] ? (this[p].value = arg.value) : console.log('Unknown VacationCommand :' + p);
				}
			});
		}
	}

	/**
	 * https://datatracker.ietf.org/doc/html/rfc5703#section-6
	 */
	class EncloseCommand extends ActionCommand
	{
		constructor()
		{
			super();
			this._subject    = new GrammarQuotedString;
			this.headers     = new GrammarStringList;
		}

		get require() { return 'enclose'; }

		get subject()  { return this._subject.value; }
		set subject(v) { this._subject.value = v; }

		toString()
		{
			let result = 'enclose';
			if (this._subject.length) {
				result += ' :subject ' + this._subject;
			}
			if (this.headers.length) {
				result += ' :headers ' + this.headers;
			}
			return result + ' :text;';
		}

		pushArguments(args)
		{
			args.forEach((arg, i) => {
				if (i && ':' === args[i-1][0]) {
					// :subject, :headers
					let p = args[i-1].replace(':','_');
					this[p] ? (this[p].value = arg.value) : console.log('Unknown VacationCommand :' + p);
				}
			});
		}
	}

	/**
	 * https://datatracker.ietf.org/doc/html/rfc5703#section-7
	 * Should be inside foreverypart, else empty and flagged as a compilation error
	 */
	class ExtractTextCommand extends ActionCommand
	{
		constructor()
		{
			super();
			this.modifiers = [];
			this._first    = new GrammarNumber;
			this._varname  = new GrammarQuotedString;
		}

		get varname()  { return this._varname.value; }
		set varname(v) { this._varname.value = v; }

		get require() { return 'extracttext'; }

		toString()
		{
			let result = 'extracttext '
				+ this.modifiers.join(' ');
			if (0 < this._first.value) {
				result += ' :first ' + this._first;
			}
			return result + ' ' + this._varname + ';';
		}

		pushArguments(args)
		{
			this._varname = args.pop();
			[':lower', ':upper', ':lowerfirst', ':upperfirst', ':quotewildcard', ':length'].forEach(modifier => {
				args.includes(modifier) && this.modifiers.push(modifier);
			});
			args.forEach((arg, i) => {
				if (i && ':' === args[i-1][0]) {
					// :first
					this[args[i-1].replace(':','_')].value = arg.value;
				}
			});
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc6134
	 */

	/**
	 * https://datatracker.ietf.org/doc/html/rfc6134#section-2.7
	 */
	class ValidExtListTest extends TestCommand
	{
		constructor()
		{
			super('valid_ext_list');
			this.ext_list_names = new GrammarStringList;
		}

		get require() { return 'foreverypart'; }

		toString()
		{
			return 'valid_ext_list ' + this.ext_list_names;
		}

		pushArguments(args)
		{
			this.ext_list_names = args.pop();
		}
	}

	/**
	 * https://tools.ietf.org/html/rfc6609
	 */

	class IncludeCommand extends ControlCommand
	{
		constructor()
		{
			super();
			this.global = false; // ':personal' / ':global';
			this.once = false;
			this.optional = false;
			this._value = new GrammarQuotedString;
		}

		get require() { return 'include'; }

		get value() { return this._value.value; }
		set value(v) { this._value.value = v; }

		toString()
		{
			return 'include'
				+ (this.global ? ' :global' : '')
				+ (this.once ? ' :once' : '')
				+ (this.optional ? ' :optional' : '')
				+ ' ' + this._value + ';';
		}

		pushArguments(args)
		{
			args.forEach(arg => {
				if (':global' === arg || ':once' === arg || ':optional' === arg) {
					this[arg.slice(1)] = true;
				} else if (arg instanceof GrammarQuotedString) {
					this._value = arg;
				}
			});
		}
	}

	class ReturnCommand extends ControlCommand
	{
		get require() { return 'include'; }
	}

	class GlobalCommand extends ControlCommand
	{
		constructor()
		{
			super();
			this.value = new GrammarStringList;
		}

		get require() { return ['include', 'variables']; }

		toString()
		{
			return 'global ' + this.value + ';';
		}

		pushArguments(args)
		{
			this.value = args.pop();
		}
	}

	const
		getIdentifier = (cmd, type) => {
			const obj = new cmd, requires = obj.require;
			return (
				(!type || obj instanceof type)
				&& (!requires || (Array.isArray(requires) ? requires : [requires]).every(string => capa.includes(string)))
			)
				? obj.identifier
				: null;
		},

		AllCommands = [
			// Control commands
			IfCommand,
			ElsIfCommand,
			ElseCommand,
			RequireCommand,
			StopCommand,
			// Action commands
			DiscardCommand,
			FileIntoCommand,
			KeepCommand,
			RedirectCommand,
			// Test commands
			AddressTest,
			AllOfTest,
			AnyOfTest,
			EnvelopeTest,
			ExistsTest,
			FalseTest,
			HeaderTest,
			NotTest,
			SizeTest,
			TrueTest,
			// rfc5173
			BodyTest,
			// rfc5183
			EnvironmentTest,
			// rfc5229
			SetCommand,
			StringTest,
			// rfc5230
			VacationCommand,
			// rfc5232
			SetFlagCommand,
			AddFlagCommand,
			RemoveFlagCommand,
			HasFlagTest,
			// rfc5235
			SpamTestTest,
			VirusTestTest,
			// rfc5260
			DateTest,
			CurrentDateTest,
			// rfc5293
			AddHeaderCommand,
			DeleteHeaderCommand,
			// rfc5429
			ErejectCommand,
			RejectCommand,
			// rfc5435
			NotifyCommand,
			ValidNotifyMethodTest,
			NotifyMethodCapabilityTest,
			// rfc5463
			IHaveTest,
			ErrorCommand,
			// rfc5490
			MailboxExistsTest,
			MetadataTest,
			MetadataExistsTest,
			// rfc5703
			ForEveryPartCommand,
			BreakCommand,
			ReplaceCommand,
			EncloseCommand,
			ExtractTextCommand,
			// rfc6134
			ValidExtListTest,
			// rfc6609
			IncludeCommand,
			ReturnCommand,
			GlobalCommand
		],

		availableCommands = () => {
			let commands = {}, id;
			AllCommands.forEach(cmd => {
				id = getIdentifier(cmd);
				if (id) {
					commands[id] = cmd;
				}
			});
			return commands;
		},

		unavailableCommands = () => {
			let commands = {};
			AllCommands.forEach(cmd => {
				const obj = new cmd, requires = obj.require;
				if (requires && !(Array.isArray(requires) ? requires : [requires]).every(string => capa.includes(string))) {
					commands[obj.identifier] = cmd;
				}
			});
			return commands;
		},

		availableActions = () => {
			let actions = {}, id;
			AllCommands.forEach(cmd => {
				id = getIdentifier(cmd, ActionCommand);
				if (id) {
					actions[id] = cmd;
				}
			});
			return actions;
		},

		availableControls = () => {
			let controls = {}, id;
			AllCommands.forEach(cmd => {
				id = getIdentifier(cmd, ControlCommand);
				if (id) {
					controls[id] = cmd;
				}
			});
			return controls;
		},

		availableTests = () => {
			let tests = {}, id;
			AllCommands.forEach(cmd => {
				id = getIdentifier(cmd, TestCommand);
				if (id) {
					tests[id] = cmd;
				}
			});
			return tests;
		};

	/**
	 * https://tools.ietf.org/html/rfc5228#section-8
	 */

	const
		T_UNKNOWN           = 0,
		T_STRING_LIST       = 1,
		T_QUOTED_STRING     = 2,
		T_MULTILINE_STRING  = 3,
		T_HASH_COMMENT      = 4,
		T_BRACKET_COMMENT   = 5,
		T_BLOCK_START       = 6,
		T_BLOCK_END         = 7,
		T_LEFT_PARENTHESIS  = 8,
		T_RIGHT_PARENTHESIS = 9,
		T_COMMA             = 10,
		T_SEMICOLON         = 11,
		T_TAG               = 12,
		T_IDENTIFIER        = 13,
		T_NUMBER            = 14,
		T_WHITESPACE        = 15,

		TokensRegEx = '(' + [
			/* T_STRING_LIST       */ STRING_LIST,
			/* T_QUOTED_STRING     */ QUOTED_STRING,
			/* T_MULTILINE_STRING  */ MULTI_LINE,
			/* T_HASH_COMMENT      */ HASH_COMMENT,
			/* T_BRACKET_COMMENT   */ BRACKET_COMMENT,
			/* T_BLOCK_START       */ '\\{',
			/* T_BLOCK_END         */ '\\}',
			/* T_LEFT_PARENTHESIS  */ '\\(', // anyof / allof
			/* T_RIGHT_PARENTHESIS */ '\\)', // anyof / allof
			/* T_COMMA             */ ',',
			/* T_SEMICOLON         */ ';',
			/* T_TAG               */ TAG,
			/* T_IDENTIFIER        */ IDENTIFIER,
			/* T_NUMBER            */ NUMBER,
			/* T_WHITESPACE        */ '(?: |\\r\\n|\\t)+',
			/* T_UNKNOWN           */ '[^ \\r\\n\\t]+'
		].join(')|(') + ')',

		TokenError = [
			/* T_STRING_LIST       */ '',
			/* T_QUOTED_STRING     */ '',
			/* T_MULTILINE_STRING  */ '',
			/* T_HASH_COMMENT      */ '',
			/* T_BRACKET_COMMENT   */ '',
			/* T_BLOCK_START       */ 'Block start not part of control command',
			/* T_BLOCK_END         */ 'Block end has no matching block start',
			/* T_LEFT_PARENTHESIS  */ 'Test start not part of anyof/allof test',
			/* T_RIGHT_PARENTHESIS */ 'Test end not part of test-list',
			/* T_COMMA             */ 'Comma not part of test-list',
			/* T_SEMICOLON         */ 'Semicolon not at end of command',
			/* T_TAG               */ '',
			/* T_IDENTIFIER        */ '',
			/* T_NUMBER            */ '',
			/* T_WHITESPACE        */ '',
			/* T_UNKNOWN           */ ''
		];

	const parseScript = (script, name = 'script.sieve') => {
		script = script.replace(/\r?\n/g, '\r\n');

		// Only activate available commands
		const Commands = availableCommands();
		const disabledCommands = unavailableCommands();

		let match,
			line = 1,
			tree = [],

			// Create one regex to find the tokens
			// Use exec() to forward since lastIndex
			regex = RegExp(TokensRegEx, 'gm'),

			levels = [],
			command = null,
			requires = [],
			args = [];

		const
			error = message => {
	//			throw new SyntaxError(message + ' at ' + regex.lastIndex + ' line ' + line, name, line)
				throw new SyntaxError(message + ' on line ' + line
					+ ' around:\n\n' + script.slice(regex.lastIndex - 20, regex.lastIndex + 10), name, line)
			},
			pushArg = arg => {
				command || error('Argument not part of command');
				let prev_arg = args[args.length-1];
				if (getMatchTypes(0).includes(arg)) {
					command.match_type = arg;
				} else if (getMatchTypes(0).includes(prev_arg)) {
					--args.length;
					if (':value' === prev_arg || ':count' === prev_arg) {
						// Sieve relational [RFC5231] match types
						/^"(gt|ge|lt|le|eq|ne)"$/.test(arg) || error('Invalid relational match-type ' + arg);
						command.relational_match = arg;
	//					requires.push('relational');
						return;
					}
				} else if (':comparator' === prev_arg) {
					command.comparator = arg;
					--args.length;
				}
				args.push(arg);
			},
			pushArgs = () => {
				if (args.length) {
					command && command.pushArguments(args);
					args = [];
				}
			};

		levels.up = () => {
			levels.pop();
			return levels[levels.length - 1];
		};

		while ((match = regex.exec(script))) {
			// the last element in match will contain the matched value and the key will be the type
			let type = match.findIndex((v,i) => 0 < i && undefined !== v),
				value = match[type];

			// create the part
			switch (type)
			{
			case T_IDENTIFIER: {
				pushArgs();
				value = value.toLowerCase();
				let new_command;
				if (Commands[value]) {
					if ('elsif' === value || 'else' === value) {
						let valid = false, cmd = (command ? command?.commands : tree), i = cmd?.length;
						while (i) {
							cmd[--i];
							if (cmd[i] instanceof IfCommand) {
								valid = true;
								break;
							} else if (typeof cmd[i] !== 'string' && !(cmd[i] instanceof GrammarComment)) {
								break;
							}
						}
						valid || error('Not after IF/ELSIF condition');
					}
					new_command = new Commands[value]();
				} else if (disabledCommands[value]) {
					console.error('Unsupported command: ' + value);
					new_command = new disabledCommands[value]();
				} else {
					console.error('Unknown command: ' + value);
					new_command = new GrammarCommand(value);
				}

				if (new_command instanceof TestCommand) {
					if (command instanceof ConditionalCommand || command instanceof NotTest) {
						// if/elsif/else new_command
						// not new_command
						command.test = new_command;
					} else if (command.tests instanceof GrammarTestList) {
						// allof/anyof .tests[] new_command
						command.tests.push(new_command);
					} else {
						error('Test "' + value + '" not allowed in "' + command.identifier + '" command');
					}
				} else if (command) {
					if (command.commands) {
						command.commands.push(new_command);
					} else {
						error('command "' + new_command.identifier + '" not allowed in "' + command.identifier + '" command');
					}
				} else {
					tree.push(new_command);
				}
				levels.push(new_command);
				command = new_command;
				if (command.require) {
					(Array.isArray(command.require) ? command.require : [command.require])
						.forEach(string => requires.push(string));
				}
				if (command.comparator) {
					requires.push('comparator-' + command.comparator);
				}
				break; }

			// Arguments
			case T_TAG:
				pushArg(value.toLowerCase());
				break;
			case T_STRING_LIST:
				pushArg(GrammarStringList.fromString(value));
				break;
			case T_MULTILINE_STRING:
				pushArg(GrammarMultiLine.fromString(value));
				break;
			case T_QUOTED_STRING:
				try { value = JSON.parse(value); } catch(e) { console.error(e, value); }
				pushArg(new GrammarQuotedString(value));
				break;
			case T_NUMBER:
				pushArg(new GrammarNumber(value));
				break;

			// Comments
			case T_BRACKET_COMMENT:
			case T_HASH_COMMENT: {
				let obj = (T_HASH_COMMENT == type)
					? new GrammarHashComment(value.slice(1).trim())
					: new GrammarBracketComment(value.slice(2, -2));
				if (command) {
					if (!command.comments) {
						command.comments = [];
					}
					(command.commands || command.comments).push(obj);
				} else {
					tree.push(obj);
				}
				break; }

			case T_WHITESPACE:
	//			(command ? command.commands : tree).push(value.trim());
				command || tree.push(value.trim());
				break;

			// Command end
			case T_SEMICOLON:
				command || error(TokenError[type]);
				pushArgs();
				if (command instanceof RequireCommand) {
					command.capabilities.forEach(string => requires.push(string.value));
				}
				command = levels.up();
				break;

			// Command block
			case T_BLOCK_START:
				pushArgs();
				// https://tools.ietf.org/html/rfc5228#section-2.9
				// Action commands do not take tests or blocks
				while (command && !(command instanceof ConditionalCommand)) {
					command = levels.up();
				}
				command || error(TokenError[type]);
				break;
			case T_BLOCK_END:
				(command instanceof ConditionalCommand) || error(TokenError[type]);
				command = levels.up();
				break;

			// anyof / allof ( ... , ... )
			case T_LEFT_PARENTHESIS:
			case T_RIGHT_PARENTHESIS:
			case T_COMMA:
				pushArgs();
				// Must be inside PARENTHESIS aka test-list
				while (command && !(command.tests instanceof GrammarTestList)) {
					command = levels.up();
				}
				command || error(TokenError[type]);
				break;

			case T_UNKNOWN:
				error('Invalid token ' + value);
			}

			// Set current script position
			line += (value.split('\n').length - 1); // (value.match(/\n/g) || []).length;
		}

		tree.requires = requires;
		tree.toString = () => tree.join('\r\n');
		return tree;
	};

	class SieveScriptPopupView extends rl.pluginPopupView {
		constructor() {
			super('SieveScript');

			this.addObservables({
				saveError: false,
				errorText: '',
				rawActive: false,
				script: null,
				saving: false,

				sieveCapabilities: '',
				availableActions: '',
				availableControls: '',
				availableTests: ''
			});

			this.filterForDeletion = ko.observable(null).askDeleteHelper();
		}

		validateScript() {
			try {
				this.errorText('');
				parseScript(this.script().body());
			} catch (e) {
				this.errorText(e.message);
			}
		}

		saveScript() {
			let self = this,
				script = self.script();
			if (!self.saving()/* && script.hasChanges()*/) {
				this.errorText('');
				self.saveError(false);

				if (!script.verify()) {
					return;
				}

				if (!script.exists() && scripts.find(item => item !== script && item.name() === script.name())) {
					script.nameError(true);
					return;
				}

				try {
					parseScript(script.body());
				} catch (e) {
					this.errorText(e.message);
					return;
				}

				self.saving(true);
				window.Sieve.saveScript(script, (iError, errorText) => {
					self.saving(false);
					if (iError) {
						self.saveError(true);
						self.errorText(errorText);
					}
				});
			}
		}

		deleteFilter(filter) {
			this.script().filters.remove(filter);
		}

		addFilter() {
			/* this = SieveScriptModel */
			const filter = new FilterModel();
			filter.generateID();
			FilterPopupView.showModal([
				filter,
				() => this.filters.push(filter.assignTo())
			]);
		}

		editFilter(filter) {
			const clonedFilter = filter.assignTo();
			FilterPopupView.showModal([
				clonedFilter,
				() => {
					clonedFilter.assignTo(filter);
					const script = this.script();
					script.hasChanges(script.body() != script.filtersToRaw());
				},
				true
			]);
		}

		toggleFiltersRaw() {
			const script = this.script(), notRaw = !this.rawActive();
			notRaw && script.body(script.filtersToRaw());
			this.rawActive(notRaw);
		}

		onBuild(oDom) {
			oDom.addEventListener('click', event => {
				const el = event.target.closestWithin('td.e-action', oDom),
					filter = el && ko.dataFor(el);
				filter && this.editFilter(filter);
			});
		}

		beforeShow(oScript) {
	//	onShow(oScript) {
			this.sieveCapabilities(capa.join(' '));
			this.availableActions([...Object.keys(availableActions())].join(', '));
			this.availableControls([...Object.keys(availableControls())].join(', '));
			this.availableTests([...Object.keys(availableTests())].join(', '));

			oScript = oScript || new SieveScriptModel();
			this.script(oScript);
			this.rawActive(!oScript.allowFilters());
			this.saveError(false);
			this.errorText('');

	/*
			// TODO: Sieve GUI
			let tree = parseScript(oScript.body(), oScript.name());
			console.dir(tree);
			console.log(tree.join('\r\n'));
	*/
		}
	}

	// SieveUserStore
	window.Sieve = {
		capa: capa,
		scripts: scripts,
		loading: loading,
		serverError: serverError,
		serverErrorDesc: serverErrorDesc,
		ScriptView: SieveScriptPopupView,
		FilterView: FilterPopupView,
		FilterModel: FilterModel,

		// saveScript(script, onDone)
		// onDone(iError, errorText) — called on both success and failure.
		// Handles body serialization, bootstrap push, and activate-on-create.
		saveScript: (script, onDone) => {
			if (script.allowFilters()) {
				script.body(script.filtersToRaw());
			}

			Remote.request('FiltersScriptSave',
				(iError, data) => {
					if (iError) {
						onDone && onDone(iError, data?.messageAdditional || getNotification(iError));
					} else {
						const wasNew = !script.exists();
						if (wasNew && !scripts.includes(script)) {
							scripts.push(script);
						}
						script.exists(true);
						script.hasChanges(false);
						if (wasNew && script.active()) {
							window.Sieve.setActiveScript(script.name());
						}
						onDone && onDone(0);
					}
				},
				script.toJSON()
			);
		},

		folderList: null,

		updateList: () => {
			if (!loading()) {
				loading(true);
				serverError(false);

				Remote.request('Filters', (iError, data) => {
					loading(false);
					scripts([]);

					if (iError) {
						capa([]);
						setError(getNotification(iError));
					} else {
						capa(data.Result.Capa);
	/*
						scripts(
							data.Result.Scripts.map(aItem => SieveScriptModel.reviveFromJson(aItem)).filter(v => v)
						);
	*/
						forEachObjectValue(data.Result.Scripts, value => {
							value = SieveScriptModel.reviveFromJson(value);
							value && (value.allowFilters() ? scripts.unshift(value) : scripts.push(value));
						});

						// Bootstrap: empty server has no script to merge into.
						// Offer a placeholder so the user can create the first
						// simple-filter script; it is created + activated on save.
						if (!scripts().length) {
							const placeholder = new SieveScriptModel();
							placeholder.name(SIEVE_BOOTSTRAP_NAME);
							placeholder.active(true);
							placeholder.exists(false);
							placeholder.hasChanges(false);
							scripts.unshift(placeholder);
						}
					}
				});
			}
		},

		deleteScript: script => {
			serverError(false);
			Remote.request('FiltersScriptDelete',
				(iError, data) =>
					iError
						? setError(data?.messageAdditional || getNotification(iError))
						: scripts.remove(script)
				,
				{name:script.name()}
			);
		},

		setActiveScript(name) {
			serverError(false);
			// Optimistic update: flip active state immediately so the UI (the
			// simple-filter row and its filter list) reflects the switch without
			// waiting for the server round-trip or needing a page reload.
			scripts.forEach(script => script.active(script.name() === name));
			Remote.request('FiltersScriptActivate',
				(iError, data) => {
					if (iError) {
						setError(data?.messageAdditional || iError);
						// Server rejected the switch: resync from the server.
						window.Sieve.updateList();
					}
				},
				{name:name}
			);
		}
	};

	// Parse a sieve string literal or list -> array of strings.
	function parseSieveStrings(s) {
		s = s.trim();
		const out = [];
		const re = /"((?:[^"\\]|\\.)*)"/g;
		let m;
		while ((m = re.exec(s))) out.push(m[1].replace(/\\(["\\])/g, '$1'));
		return out;
	}

	const MATCH_TO_TYPE = { ':is':'EqualTo', ':contains':'Contains', ':regex':'Regex',
		':text':'Text', ':raw':'Raw', ':over':'Over', ':under':'Under' };
	const NOT_TYPE = { EqualTo:'NotEqualTo', Contains:'NotContains' };

	function parseTest(str) {
		str = str.trim();
		let negate = false;
		if (str.startsWith('not ')) { negate = true; str = str.slice(4).trim(); }

		let m = str.match(/^header\s+(:[a-z]+)\s+(\[[^\]]*\]|"[^"]*")\s+(.+)$/);
		if (m) {
			let type = MATCH_TO_TYPE[m[1]];
			if (!type) return null;
			if (negate) type = NOT_TYPE[type] || type;
			const headers = parseSieveStrings(m[2]);
			const values = parseSieveStrings(m[3]);
			const hkey = headers.map(h => h.toLowerCase()).join(',');
			let Field, ValueSecond = '';
			if (hkey === 'from') Field = 'From';
			else if (hkey === 'to,cc') Field = 'Recipient';
			else if (hkey === 'subject') Field = 'Subject';
			else { Field = 'Header'; ValueSecond = headers[0] || ''; }
			return { Field, Type: type, Value: values.join(','), ValueSecond };
		}
		m = str.match(/^body\s+:[a-z]+\s+(:[a-z]+)\s+(.+)$/);
		if (m) {
			const type = MATCH_TO_TYPE[m[1]] || 'Contains';
			return { Field:'Body', Type:type, Value:parseSieveStrings(m[2]).join(','), ValueSecond:'' };
		}
		m = str.match(/^size\s+(:over|:under)\s+([0-9]+[KMG]?)\s*$/i);
		if (m) {
			return { Field:'Size', Type:MATCH_TO_TYPE[m[1].toLowerCase()], Value:m[2], ValueSecond:'' };
		}
		return null;
	}

	// Split "t1, t2, t3" at top level (commas not inside [...] or "...").
	function splitTopLevelCommas(s) {
		const parts = []; let depth = 0, inStr = false, cur = '';
		for (let i = 0; i < s.length; i++) {
			const ch = s[i];
			if (inStr) { cur += ch; if (ch === '"' && s[i-1] !== '\\') inStr = false; continue; }
			if (ch === '"') { inStr = true; cur += ch; continue; }
			if (ch === '[' || ch === '(') depth++;
			if (ch === ']' || ch === ')') depth--;
			if (ch === ',' && depth === 0) { parts.push(cur.trim()); cur = ''; continue; }
			cur += ch;
		}
		if (cur.trim()) parts.push(cur.trim());
		return parts;
	}

	function parseConditionClause(clause) {
		clause = clause.trim();
		let type = 'Any', inner = clause;
		const m = clause.match(/^(anyof|allof)\s*\(([\s\S]*)\)\s*$/i);
		if (m) {
			type = m[1].toLowerCase() === 'allof' ? 'All' : 'Any';
			inner = m[2];
		}
		const tests = splitTopLevelCommas(inner);
		const conditions = [];
		for (const t of tests) {
			const c = parseTest(t);
			if (!c) return null;
			conditions.push(c);
		}
		return conditions.length ? { ConditionsType: type, Conditions: conditions } : null;
	}

	function parseActionBlock(body) {
		// Split into statements on ';' at top level (ignore ';' inside strings).
		const stmts = [];
		let cur = '', inStr = false;
		for (let i = 0; i < body.length; i++) {
			const ch = body[i];
			if (inStr) { cur += ch; if (ch === '"' && body[i-1] !== '\\') inStr = false; continue; }
			if (ch === '"') { inStr = true; cur += ch; continue; }
			if (ch === ';') { if (cur.trim()) stmts.push(cur.trim()); cur = ''; continue; }
			cur += ch;
		}
		if (cur.trim()) stmts.push(cur.trim());

		const a = { ActionType:'None', ActionValue:'', ActionValueSecond:'', ActionValueThird:'',
			ActionValueFourth:'', Keep:true, Stop:false, MarkAsRead:false };
		let redirect = null, fileinto = null, sawInboxFileinto = false;

		for (const s of stmts) {
			let m;
			if (s === 'stop') { a.Stop = true; }
			else if (/^addflag\s+"\\\\Seen"$/.test(s)) { a.MarkAsRead = true; }
			else if ((m = s.match(/^fileinto\s+"((?:[^"\\]|\\.)*)"$/))) {
				const v = m[1].replace(/\\(["\\])/g,'$1');
				if (v === 'INBOX') sawInboxFileinto = true; else fileinto = v;
			}
			else if ((m = s.match(/^redirect\s+"((?:[^"\\]|\\.)*)"$/))) { redirect = m[1].replace(/\\(["\\])/g,'$1'); }
			else if (s === 'discard') { a.ActionType = 'Discard'; }
			else if ((m = s.match(/^reject\s+"((?:[^"\\]|\\.)*)"$/))) { a.ActionType = 'Reject'; a.ActionValue = m[1].replace(/\\(["\\])/g,'$1'); }
			else if (/^vacation\b/.test(s)) {
				a.ActionType = 'Vacation';
				const subj = s.match(/:subject\s+"((?:[^"\\]|\\.)*)"/); if (subj) a.ActionValueSecond = subj[1];
				const days = s.match(/:days\s+(\d+)/); if (days) a.ActionValueThird = days[1];
				const addr = s.match(/:addresses\s+\[([^\]]*)\]/); if (addr) a.ActionValueFourth = parseSieveStrings(addr[1]).join(',');
				const msg = s.match(/"((?:[^"\\]|\\.)*)"\s*$/); if (msg) a.ActionValue = msg[1];
			}
			else { return null; } // unsupported action
		}

		if (redirect !== null) { a.ActionType = 'Forward'; a.ActionValue = redirect; a.Keep = sawInboxFileinto; }
		else if (fileinto !== null) { a.ActionType = 'MoveTo'; a.ActionValue = fileinto; }
		return a;
	}

	function newDef() {
		return { Enabled:true, Name:'Filter', Conditions:[], ConditionsType:'Any',
			ActionType:'None', ActionValue:'', ActionValueSecond:'', ActionValueThird:'',
			ActionValueFourth:'', Keep:true, Stop:false, MarkAsRead:false };
	}

	// Count net { minus } in a string, ignoring characters inside double-quoted strings.
	function netBraces(s) {
		let depth = 0, inStr = false;
		for (let i = 0; i < s.length; i++) {
			const ch = s[i];
			if (inStr) { if (ch === '\\') { i++; } else if (ch === '"') { inStr = false; } continue; }
			if (ch === '"') { inStr = true; continue; }
			if (ch === '{') depth++;
			else if (ch === '}') depth--;
		}
		return depth;
	}

	// Try to parse one rule starting at lines[i] (already past any leading # name / /*).
	// Returns { def, next } where def may be null if a block was collected but could not
	// be parsed (caller must treat whole block as foreign). Returns null only when no
	// if-block starts at lines[i] and no block-less rule applies.
	function parseRuleAt(lines, i, name, enabled) {
		let line = lines[i].trim();
		// Collect an `if ... { ... }` possibly spanning multiple lines until matching brace.
		if (/^if\b/.test(line)) {
			let buf = lines[i], j = i, open = netBraces(lines[i]);
			let inBlock = open !== 0 || lines[i].includes('{');
			while (j + 1 < lines.length && (!inBlock || open > 0)) {
				j++; buf += '\n' + lines[j];
				open += netBraces(lines[j]);
				if (!inBlock && lines[j].includes('{')) inBlock = true;
			}
			const m = buf.match(/^\s*if\b([\s\S]*?){([\s\S]*)}\s*$/);
			if (!m) return { def: null, next: j + 1 };
			const cond = parseConditionClause(m[1].trim());
			const act = parseActionBlock(m[2].trim());
			if (!cond || !act) return { def: null, next: j + 1 };
			const def = Object.assign(newDef(), cond, act, { Name:name||'Filter', Enabled:enabled });
			return { def, next: j + 1 };
		}
		// Block-less (conditionless) rule — only when anchored by a name comment.
		if (name) {
			const act = parseActionBlock(line);
			if (act) { const def = Object.assign(newDef(), act, { Name:name, Enabled:enabled, Conditions:[] }); return { def, next: i + 1 }; }
		}
		return null;
	}

	function migrateLegacy(body) {
		if (!/X2MAIL:SIEVE|RAINLOOP:SIEVE|BEGIN:HEADER/.test(body)) return null;
		const defs = [];
		const re = /BEGIN:HEADER([\s\S]+?)END:HEADER/g;
		let m;
		while ((m = re.exec(body))) {
			try {
				const json = JSON.parse(decodeURIComponent(escape(atob(m[1].replace(/\s+/g, '')))));
				defs.push(Object.assign(newDef(), json));
			} catch (e) { /* ignore malformed */ }
		}
		// Re-emit as clean sieve; foreign (non-managed) lines dropped — legacy scripts
		// were fully x2mail-owned. A bare `keep;` baseline is implied by the server default.
		return filterDefsToSieve(defs, '');
	}

	function sieveToFilterDefs(body) {
		body = (body || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');

		// Migrate legacy base64/# FILTER metadata scripts to clean sieve before parsing.
		const migrated = migrateLegacy(body);
		if (migrated != null) body = migrated;

		// Strip our own fence markers and the require they contain so that
		// filterDefsToSieve(sieveToFilterDefs(s).defs, ...) can regenerate an
		// identical script (round-trip). Lines that are exactly the fence delimiters
		// are removed; require lines inside the fenced block are also skipped because
		// filterDefsToSieve regenerates them from the parsed defs.
		const fenceStartRe = new RegExp('^' + FILTER_FENCE_START.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + '$');
		const fenceEndRe   = new RegExp('^' + FILTER_FENCE_END.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + '$');
		{
			const raw = body.split('\n');
			const out = []; let inFence = false;
			for (const l of raw) {
				const t = l.trim();
				if (fenceStartRe.test(t)) { inFence = true; continue; }
				if (fenceEndRe.test(t))   { inFence = false; continue; }
				// Drop require lines that are inside our fence (we regenerate them).
				if (inFence && /^require\b/.test(t)) continue;
				out.push(l);
			}
			body = out.join('\n');
		}

		const lines = body.split('\n');
		const defs = [], foreign = [];
		let i = 0;
		while (i < lines.length) {
			const raw = lines[i];
			const t = raw.trim();
			if (t === '') { foreign.push(raw); i++; continue; }

			// Skip require — regenerated from defs.
			if (/^require\b/.test(t)) { foreign.push(raw); i++; continue; }

			// Optional name comment.
			let name = null, start = i;
			const nameM = t.match(/^#\s?(.*)$/);
			if (nameM) {
				// Peek: is the next non-blank line a rule or a /* disabled */ wrapper?
				let k = i + 1; while (k < lines.length && lines[k].trim() === '') k++;
				const nt = (lines[k] || '').trim();
				if (/^if\b/.test(nt) || /^\/\*/.test(nt) || /^(fileinto|redirect|discard|reject|vacation|addflag|stop)\b/.test(nt)) {
					name = nameM[1].trim(); i = k;
				} else { foreign.push(raw); i++; continue; }
			}

			// Optional disabled wrapper — handles both bare `/*` and `/* @Filter is disabled`.
			let enabled = true, ruleStart = i;
			if (/^\/\*/.test(lines[i].trim())) {
				// find closing */
				let k = i + 1; const inner = [];
				while (k < lines.length && lines[k].trim() !== '*/') { inner.push(lines[k]); k++; }
				if (k < lines.length) {
					const sub = parseRuleAt(inner.map(s=>s).concat([]), 0, name, false);
					if (sub && sub.def) { defs.push(sub.def); i = k + 1; continue; }
				}
				// not a parseable disabled rule -> foreign, preserve entire wrapper verbatim
				if (k < lines.length) {
					// closing */ was found at lines[k]
					for (let x = start; x <= k; x++) foreign.push(lines[x]); i = k + 1;
				} else {
					// unterminated /* — push everything to end
					for (let x = start; x < k; x++) foreign.push(lines[x]); i = k;
				}
				continue;
			}

			const res = parseRuleAt(lines, ruleStart, name, enabled);
			if (res && res.def) { defs.push(res.def); i = res.next; continue; }
			// Block collected but unparseable: skip all collected lines as foreign.
			if (res && !res.def) {
				for (let x = start; x < res.next; x++) foreign.push(lines[x]);
				i = res.next; continue;
			}

			// Foreign: emit everything from `start` (includes a consumed name comment).
			for (let x = start; x <= i; x++) foreign.push(lines[x]);
			i++;
		}

		return { defs, untouched: foreign.join('\n').trim() };
	}

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = { filterDefsToSieve, sieveToFilterDefs, parseTest, parseConditionClause, parseActionBlock };
	}

})();
