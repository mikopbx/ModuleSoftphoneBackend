/*
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 11 2018
 *
 */
const idUrl    		= 'module-softphone-backend';
const idForm    		= 'module-softphone-backend-form';
const className 		= 'ModuleSoftphoneBackend';
const inputClassName = 'mikopbx-module-input';

/* global globalRootUrl, globalTranslate, Form, Config */
const ModuleSoftphoneBackend = {
	$formObj: $('#'+idForm),
	$checkBoxes: $('#'+idForm+' .ui.checkbox'),
	$dropDowns: $('#'+idForm+' .ui.dropdown'),
	$disabilityFields: $('#'+idForm+'  .disability'),
	$statusToggle: $('#module-status-toggle'),
	$moduleStatus: $('#status'),
	// WebSocket: contacts channel (Nchan subscriber)
	_contactsWs: null,
	_contactsWsReconnectAttempt: 0,
	_contactsWsReconnectTimer: null,
	_contactsWsTokenTimer: null,
	_contactsWsLog: [],
	// WebSocket: active-calls channel (Nchan subscriber)
	_activeCallsWs: null,
	_activeCallsWsReconnectAttempt: 0,
	_activeCallsWsReconnectTimer: null,
	_activeCallsWsTokenTimer: null,
	/**
	 * Field validation rules
	 * https://semantic-ui.com/behaviors/form.html
	 */
	validateRules: {

	},
	/**
	 * Check JWT access token expiry (best-effort).
	 * @param {number} leewaySeconds
	 * @returns {boolean}
	 */
	isAccessTokenExpired(leewaySeconds = 0) {
		try {
			const token = $('#access_token').val();
			if (!token || typeof token !== 'string') return true;
			const parts = token.split('.');
			if (parts.length < 2) return true;
			const payloadB64Url = parts[1];
			const payloadB64 = payloadB64Url.replace(/-/g, '+').replace(/_/g, '/');
			const json = atob(payloadB64.padEnd(payloadB64.length + (4 - (payloadB64.length % 4)) % 4, '='));
			const payload = JSON.parse(json);
			const exp = Number(payload?.exp || 0);
			if (!exp) return false; // token without exp: treat as non-expiring
			const now = Math.floor(Date.now() / 1000);
			return now + Number(leewaySeconds || 0) >= exp;
		} catch (e) {
			return true;
		}
	},
	/**
	 * Schedule reconnect with exponential backoff.
	 * @param {string} source
	 * @param {boolean} forceReAuth
	 * @returns {void}
	 */
	scheduleContactsWsReconnect(source, forceReAuth) {
		try {
			if (this._contactsWsReconnectTimer) {
				clearTimeout(this._contactsWsReconnectTimer);
				this._contactsWsReconnectTimer = null;
			}

			// If token is expired/invalid, the only safe action is to retry later.
			// The admin UI can refresh token by reloading page (server-side render).
			if (forceReAuth) {
				this._contactsWsReconnectTimer = setTimeout(() => {
					try { window.location.reload(); } catch (e) { /* ignore */ }
				}, 1000);
				return;
			}

			const attempt = Number(this._contactsWsReconnectAttempt || 0);
			const baseDelay = 500; // ms
			const maxDelay = 30000; // ms
			const delay = Math.min(maxDelay, baseDelay * Math.pow(2, attempt));
			this._contactsWsReconnectAttempt = attempt + 1;

			this._contactsWsReconnectTimer = setTimeout(() => {
				this.connectContactsWs();
			}, delay);

			console.log('contacts ws reconnect scheduled', { source, attempt: this._contactsWsReconnectAttempt, delay });
		} catch (e) {
			// ignore
		}
	},
	/**
	 * Schedule reconnect for active-calls with exponential backoff.
	 * @param {string} source
	 * @param {boolean} forceReAuth
	 * @returns {void}
	 */
	scheduleActiveCallsWsReconnect(source, forceReAuth) {
		try {
			if (this._activeCallsWsReconnectTimer) {
				clearTimeout(this._activeCallsWsReconnectTimer);
				this._activeCallsWsReconnectTimer = null;
			}

			if (forceReAuth) {
				this._activeCallsWsReconnectTimer = setTimeout(() => {
					try { window.location.reload(); } catch (e) { /* ignore */ }
				}, 1000);
				return;
			}

			const attempt = Number(this._activeCallsWsReconnectAttempt || 0);
			const baseDelay = 500;
			const maxDelay = 30000;
			const delay = Math.min(maxDelay, baseDelay * Math.pow(2, attempt));
			this._activeCallsWsReconnectAttempt = attempt + 1;

			this._activeCallsWsReconnectTimer = setTimeout(() => {
				this.connectActiveCallsWs();
			}, delay);

			console.log('active-calls ws reconnect scheduled', { source, attempt: this._activeCallsWsReconnectAttempt, delay });
		} catch (e) {
			// ignore
		}
	},
	/**
	 * Close contacts WS and clear timers.
	 * @returns {void}
	 */
	disconnectContactsWs() {
		try {
			if (this._contactsWsTokenTimer) {
				clearTimeout(this._contactsWsTokenTimer);
				this._contactsWsTokenTimer = null;
			}
			if (this._contactsWsReconnectTimer) {
				clearTimeout(this._contactsWsReconnectTimer);
				this._contactsWsReconnectTimer = null;
			}
			if (this._contactsWs) {
				try { this._contactsWs.close(1000, 'client_close'); } catch (e) { /* ignore */ }
			}
			this._contactsWs = null;
		} catch (e) {
			// ignore
		}
	},
	/**
	 * Close active-calls WS and clear timers.
	 * @returns {void}
	 */
	disconnectActiveCallsWs() {
		try {
			if (this._activeCallsWsTokenTimer) {
				clearTimeout(this._activeCallsWsTokenTimer);
				this._activeCallsWsTokenTimer = null;
			}
			if (this._activeCallsWsReconnectTimer) {
				clearTimeout(this._activeCallsWsReconnectTimer);
				this._activeCallsWsReconnectTimer = null;
			}
			if (this._activeCallsWs) {
				try { this._activeCallsWs.close(1000, 'client_close'); } catch (e) { /* ignore */ }
			}
			this._activeCallsWs = null;
		} catch (e) {
			// ignore
		}
	},
	/**
	 * Connect WebSocket to contacts subscriber endpoint.
	 * Token is taken from $('#access_token').val().
	 * @returns {void}
	 */
	connectContactsWs() {
		try {
			const accessToken = $('#access_token').val();
			if (!accessToken) return;

			// Avoid reconnecting if already connected/connecting
			if (this._contactsWs && (this._contactsWs.readyState === WebSocket.OPEN || this._contactsWs.readyState === WebSocket.CONNECTING)) {
				return;
			}
			// Reset backoff on explicit connect attempt
			this._contactsWsReconnectAttempt = 0;

			const wsProto = window.location.protocol === 'https:' ? 'wss' : 'ws';
			const wsHost = window.location.host; // host:port of current page
			const tokenParam = encodeURIComponent(accessToken);
			const wsUrl = `${wsProto}://${wsHost}/pbxcore/api/module-softphone-backend/v1/sub/contacts?authorization=${tokenParam}`;

			this._contactsWs = new WebSocket(wsUrl);
			this._contactsWs.onopen = () => {
				console.log('contacts ws connected');
				this.appendContactsWsLog('connected');

				// Reconnect shortly before token expires (best-effort).
				try {
					const parts = String(accessToken).split('.');
					if (parts.length >= 2) {
						const payloadB64 = parts[1].replace(/-/g, '+').replace(/_/g, '/');
						const json = atob(payloadB64.padEnd(payloadB64.length + (4 - (payloadB64.length % 4)) % 4, '='));
						const payload = JSON.parse(json);
						const exp = Number(payload?.exp || 0);
						if (exp) {
							const now = Math.floor(Date.now() / 1000);
							const secondsToRefresh = Math.max(5, exp - now - 30);
							if (this._contactsWsTokenTimer) clearTimeout(this._contactsWsTokenTimer);
							this._contactsWsTokenTimer = setTimeout(() => {
								this.scheduleContactsWsReconnect('token_expiring', true);
							}, secondsToRefresh * 1000);
						}
					}
				} catch (e) {
					// ignore
				}
			};
			this._contactsWs.onmessage = (event) => {
				const raw = event?.data;
				console.log(raw);
				let line = '';
				try {
					const parsed = JSON.parse(String(raw));
					line = JSON.stringify(parsed);
				} catch (e) {
					line = String(raw ?? '');
				}
				this.appendContactsWsLog(line);
			};
			this._contactsWs.onerror = (event) => {
				console.log('contacts ws error', event);
				this.appendContactsWsLog('error');
			};
			this._contactsWs.onclose = (event) => {
				const code = event?.code;
				const reason = event?.reason;
				console.log('contacts ws closed', { code, reason });
				this.appendContactsWsLog(`closed code=${code ?? ''} reason=${reason ?? ''}`);

				if (this._contactsWsTokenTimer) {
					clearTimeout(this._contactsWsTokenTimer);
					this._contactsWsTokenTimer = null;
				}

				// 1000 = normal close -> reconnect; auth closes vary by server implementation.
				const authCloseCodes = new Set([1008, 4001, 4401, 4403]);
				const forceReAuth = authCloseCodes.has(code) || this.isAccessTokenExpired(0);
				this.scheduleContactsWsReconnect('close', forceReAuth);
			};
		} catch (e) {
			console.log('contacts ws init error', e);
			this.appendContactsWsLog('init_error');
			this.scheduleContactsWsReconnect('init_error', this.isAccessTokenExpired(0));
		}
	},
	/**
	 * Connect WebSocket to active-calls subscriber endpoint.
	 * Token is taken from $('#access_token').val().
	 * Writes ONLY last message to #active_calls_ws_last.
	 * @returns {void}
	 */
	connectActiveCallsWs() {
		try {
			const accessToken = $('#access_token').val();
			if (!accessToken) return;

			if (this._activeCallsWs && (this._activeCallsWs.readyState === WebSocket.OPEN || this._activeCallsWs.readyState === WebSocket.CONNECTING)) {
				return;
			}
			this._activeCallsWsReconnectAttempt = 0;

			const wsProto = window.location.protocol === 'https:' ? 'wss' : 'ws';
			const wsHost = window.location.host;
			const tokenParam = encodeURIComponent(accessToken);
			const wsUrl = `${wsProto}://${wsHost}/pbxcore/api/module-softphone-backend/v1/sub/active-calls?authorization=${tokenParam}`;

			this._activeCallsWs = new WebSocket(wsUrl);
			this._activeCallsWs.onopen = () => {
				console.log('active-calls ws connected');
				this.setActiveCallsWsLast('connected');

				// Reconnect shortly before token expires (best-effort).
				try {
					const parts = String(accessToken).split('.');
					if (parts.length >= 2) {
						const payloadB64 = parts[1].replace(/-/g, '+').replace(/_/g, '/');
						const json = atob(payloadB64.padEnd(payloadB64.length + (4 - (payloadB64.length % 4)) % 4, '='));
						const payload = JSON.parse(json);
						const exp = Number(payload?.exp || 0);
						if (exp) {
							const now = Math.floor(Date.now() / 1000);
							const secondsToRefresh = Math.max(5, exp - now - 30);
							if (this._activeCallsWsTokenTimer) clearTimeout(this._activeCallsWsTokenTimer);
							this._activeCallsWsTokenTimer = setTimeout(() => {
								this.scheduleActiveCallsWsReconnect('token_expiring', true);
							}, secondsToRefresh * 1000);
						}
					}
				} catch (e) {
					// ignore
				}
			};
			this._activeCallsWs.onmessage = (event) => {
				const raw = event?.data;
				console.log(raw);
				let line = '';
				try {
					const parsed = JSON.parse(String(raw));
					line = JSON.stringify(parsed);
				} catch (e) {
					line = String(raw ?? '');
				}
				this.setActiveCallsWsLast(line);
			};
			this._activeCallsWs.onerror = (event) => {
				console.log('active-calls ws error', event);
				this.setActiveCallsWsLast('error');
			};
			this._activeCallsWs.onclose = (event) => {
				const code = event?.code;
				const reason = event?.reason;
				console.log('active-calls ws closed', { code, reason });
				this.setActiveCallsWsLast(`closed code=${code ?? ''} reason=${reason ?? ''}`);

				if (this._activeCallsWsTokenTimer) {
					clearTimeout(this._activeCallsWsTokenTimer);
					this._activeCallsWsTokenTimer = null;
				}

				const authCloseCodes = new Set([1008, 4001, 4401, 4403]);
				const forceReAuth = authCloseCodes.has(code) || this.isAccessTokenExpired(0);
				this.scheduleActiveCallsWsReconnect('close', forceReAuth);
			};
		} catch (e) {
			console.log('active-calls ws init error', e);
			this.setActiveCallsWsLast('init_error');
			this.scheduleActiveCallsWsReconnect('init_error', this.isAccessTokenExpired(0));
		}
	},
	/**
	 * Append a line to contacts WS log textarea (keep last 20).
	 * @param {string} message
	 * @returns {void}
	 */
	appendContactsWsLog(message) {
		try {
			const ts = new Date().toISOString();
			const line = `[${ts}] ${String(message ?? '')}`;
			if (!Array.isArray(this._contactsWsLog)) this._contactsWsLog = [];
			this._contactsWsLog.push(line);
			if (this._contactsWsLog.length > 20) {
				this._contactsWsLog = this._contactsWsLog.slice(-20);
			}
			const $ta = $('#contacts_ws_log');
			if ($ta && $ta.length) {
				$ta.val(this._contactsWsLog.join('\n'));
				// Autoscroll to bottom
				const el = $ta.get(0);
				if (el) el.scrollTop = el.scrollHeight;
			}
		} catch (e) {
			// ignore
		}
	},
	/**
	 * Set last active-calls WS message in textarea.
	 * @param {string} message
	 * @returns {void}
	 */
	setActiveCallsWsLast(message) {
		try {
			const line = String(message ?? '');
			const $ta = $('#active_calls_ws_last');
			if ($ta && $ta.length) {
				$ta.val(line);
				const el = $ta.get(0);
				if (el) el.scrollTop = el.scrollHeight;
			}
		} catch (e) {
			// ignore
		}
	},
	/**
	 * On page load we init some Semantic UI library
	 */
	initialize() {
		// инициализируем чекбоксы и выподающие менюшки
		window[className].$checkBoxes.checkbox();
		window[className].$dropDowns.dropdown();
		window[className].initializeForm();
		$('.menu .item').tab();
		// Try connect WS if token is already present in DOM
		window[className].connectContactsWs();
		window[className].connectActiveCallsWs();
	},

	/**
	 * Change some form elements classes depends of module status
	 */
	checkStatusToggle() {
		if (window[className].$statusToggle.checkbox('is checked')) {
			window[className].$disabilityFields.removeClass('disabled');
			window[className].$moduleStatus.show();
		} else {
			window[className].$disabilityFields.addClass('disabled');
			window[className].$moduleStatus.hide();
		}
	},
	/**
	 * We can modify some data before form send
	 * @param settings
	 * @returns {*}
	 */
	cbBeforeSendForm(settings) {
		const result = settings;
		result.data = window[className].$formObj.form('get values');
		return result;
	},
	/**
	 * Some actions after forms send
	 */
	cbAfterSendForm() {
	},
	/**
	 * Initialize form parameters
	 */
	initializeForm() {
		Form.$formObj = window[className].$formObj;
		Form.url = `${globalRootUrl}${idUrl}/save`;
		Form.validateRules = window[className].validateRules;
		Form.cbBeforeSendForm = window[className].cbBeforeSendForm;
		Form.cbAfterSendForm = window[className].cbAfterSendForm;
		Form.initialize();
	},
	/**
	 * Update the module state on form label
	 * @param status
	 */
	changeStatus(status) {
		switch (status) {
			case 'Connected':
				window[className].$moduleStatus
					.removeClass('grey')
					.removeClass('red')
					.addClass('green');
				window[className].$moduleStatus.html(globalTranslate.mod_tpl_Connected);
				break;
			case 'Disconnected':
				window[className].$moduleStatus
					.removeClass('green')
					.removeClass('red')
					.addClass('grey');
				window[className].$moduleStatus.html(globalTranslate.mod_tpl_Disconnected);
				break;
			case 'Updating':
				window[className].$moduleStatus
					.removeClass('green')
					.removeClass('red')
					.addClass('grey');
				window[className].$moduleStatus.html(`<i class="spinner loading icon"></i>${globalTranslate.mod_tpl_UpdateStatus}`);
				break;
			default:
				window[className].$moduleStatus
					.removeClass('green')
					.removeClass('red')
					.addClass('grey');
				window[className].$moduleStatus.html(globalTranslate.mod_tpl_Disconnected);
				break;
		}
	},
};

$(document).ready(() => {
	// Ensure module is available via window[className]
	window[className] = ModuleSoftphoneBackend;
	window[className].initialize();
});

