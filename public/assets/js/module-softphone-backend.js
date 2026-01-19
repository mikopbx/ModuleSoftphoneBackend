"use strict";

/*
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 11 2018
 *
 */
var idUrl = 'module-softphone-backend';
var idForm = 'module-softphone-backend-form';
var className = 'ModuleSoftphoneBackend';
var inputClassName = 'mikopbx-module-input';

/* global globalRootUrl, globalTranslate, Form, Config */
var ModuleSoftphoneBackend = {
  $formObj: $('#' + idForm),
  $checkBoxes: $('#' + idForm + ' .ui.checkbox'),
  $dropDowns: $('#' + idForm + ' .ui.dropdown'),
  $disabilityFields: $('#' + idForm + '  .disability'),
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
  validateRules: {},
  /**
   * Check JWT access token expiry (best-effort).
   * @param {number} leewaySeconds
   * @returns {boolean}
   */
  isAccessTokenExpired: function isAccessTokenExpired() {
    var leewaySeconds = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : 0;
    try {
      var token = $('#access_token').val();
      if (!token || typeof token !== 'string') return true;
      var parts = token.split('.');
      if (parts.length < 2) return true;
      var payloadB64Url = parts[1];
      var payloadB64 = payloadB64Url.replace(/-/g, '+').replace(/_/g, '/');
      var json = atob(payloadB64.padEnd(payloadB64.length + (4 - payloadB64.length % 4) % 4, '='));
      var payload = JSON.parse(json);
      var exp = Number((payload === null || payload === void 0 ? void 0 : payload.exp) || 0);
      if (!exp) return false; // token without exp: treat as non-expiring
      var now = Math.floor(Date.now() / 1000);
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
  scheduleContactsWsReconnect: function scheduleContactsWsReconnect(source, forceReAuth) {
    var _this = this;
    try {
      if (this._contactsWsReconnectTimer) {
        clearTimeout(this._contactsWsReconnectTimer);
        this._contactsWsReconnectTimer = null;
      }

      // If token is expired/invalid, the only safe action is to retry later.
      // The admin UI can refresh token by reloading page (server-side render).
      if (forceReAuth) {
        this._contactsWsReconnectTimer = setTimeout(function () {
          try {
            window.location.reload();
          } catch (e) {/* ignore */}
        }, 1000);
        return;
      }
      var attempt = Number(this._contactsWsReconnectAttempt || 0);
      var baseDelay = 500; // ms
      var maxDelay = 30000; // ms
      var delay = Math.min(maxDelay, baseDelay * Math.pow(2, attempt));
      this._contactsWsReconnectAttempt = attempt + 1;
      this._contactsWsReconnectTimer = setTimeout(function () {
        _this.connectContactsWs();
      }, delay);
      console.log('contacts ws reconnect scheduled', {
        source: source,
        attempt: this._contactsWsReconnectAttempt,
        delay: delay
      });
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
  scheduleActiveCallsWsReconnect: function scheduleActiveCallsWsReconnect(source, forceReAuth) {
    var _this2 = this;
    try {
      if (this._activeCallsWsReconnectTimer) {
        clearTimeout(this._activeCallsWsReconnectTimer);
        this._activeCallsWsReconnectTimer = null;
      }
      if (forceReAuth) {
        this._activeCallsWsReconnectTimer = setTimeout(function () {
          try {
            window.location.reload();
          } catch (e) {/* ignore */}
        }, 1000);
        return;
      }
      var attempt = Number(this._activeCallsWsReconnectAttempt || 0);
      var baseDelay = 500;
      var maxDelay = 30000;
      var delay = Math.min(maxDelay, baseDelay * Math.pow(2, attempt));
      this._activeCallsWsReconnectAttempt = attempt + 1;
      this._activeCallsWsReconnectTimer = setTimeout(function () {
        _this2.connectActiveCallsWs();
      }, delay);
      console.log('active-calls ws reconnect scheduled', {
        source: source,
        attempt: this._activeCallsWsReconnectAttempt,
        delay: delay
      });
    } catch (e) {
      // ignore
    }
  },
  /**
   * Close contacts WS and clear timers.
   * @returns {void}
   */
  disconnectContactsWs: function disconnectContactsWs() {
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
        try {
          this._contactsWs.close(1000, 'client_close');
        } catch (e) {/* ignore */}
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
  disconnectActiveCallsWs: function disconnectActiveCallsWs() {
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
        try {
          this._activeCallsWs.close(1000, 'client_close');
        } catch (e) {/* ignore */}
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
  connectContactsWs: function connectContactsWs() {
    var _this3 = this;
    try {
      var accessToken = $('#access_token').val();
      if (!accessToken) return;

      // Avoid reconnecting if already connected/connecting
      if (this._contactsWs && (this._contactsWs.readyState === WebSocket.OPEN || this._contactsWs.readyState === WebSocket.CONNECTING)) {
        return;
      }
      // Reset backoff on explicit connect attempt
      this._contactsWsReconnectAttempt = 0;
      var wsProto = window.location.protocol === 'https:' ? 'wss' : 'ws';
      var wsHost = window.location.host; // host:port of current page
      var tokenParam = encodeURIComponent(accessToken);
      var wsUrl = "".concat(wsProto, "://").concat(wsHost, "/pbxcore/api/module-softphone-backend/v1/sub/contacts?authorization=").concat(tokenParam);
      this._contactsWs = new WebSocket(wsUrl);
      this._contactsWs.onopen = function () {
        console.log('contacts ws connected');
        _this3.appendContactsWsLog('connected');

        // Reconnect shortly before token expires (best-effort).
        try {
          var parts = String(accessToken).split('.');
          if (parts.length >= 2) {
            var payloadB64 = parts[1].replace(/-/g, '+').replace(/_/g, '/');
            var json = atob(payloadB64.padEnd(payloadB64.length + (4 - payloadB64.length % 4) % 4, '='));
            var payload = JSON.parse(json);
            var exp = Number((payload === null || payload === void 0 ? void 0 : payload.exp) || 0);
            if (exp) {
              var now = Math.floor(Date.now() / 1000);
              var secondsToRefresh = Math.max(5, exp - now - 30);
              if (_this3._contactsWsTokenTimer) clearTimeout(_this3._contactsWsTokenTimer);
              _this3._contactsWsTokenTimer = setTimeout(function () {
                _this3.scheduleContactsWsReconnect('token_expiring', true);
              }, secondsToRefresh * 1000);
            }
          }
        } catch (e) {
          // ignore
        }
      };
      this._contactsWs.onmessage = function (event) {
        var raw = event === null || event === void 0 ? void 0 : event.data;
        console.log(raw);
        var line = '';
        try {
          var parsed = JSON.parse(String(raw));
          line = JSON.stringify(parsed);
        } catch (e) {
          line = String(raw !== null && raw !== void 0 ? raw : '');
        }
        _this3.appendContactsWsLog(line);
      };
      this._contactsWs.onerror = function (event) {
        console.log('contacts ws error', event);
        _this3.appendContactsWsLog('error');
      };
      this._contactsWs.onclose = function (event) {
        var code = event === null || event === void 0 ? void 0 : event.code;
        var reason = event === null || event === void 0 ? void 0 : event.reason;
        console.log('contacts ws closed', {
          code: code,
          reason: reason
        });
        _this3.appendContactsWsLog("closed code=".concat(code !== null && code !== void 0 ? code : '', " reason=").concat(reason !== null && reason !== void 0 ? reason : ''));
        if (_this3._contactsWsTokenTimer) {
          clearTimeout(_this3._contactsWsTokenTimer);
          _this3._contactsWsTokenTimer = null;
        }

        // 1000 = normal close -> reconnect; auth closes vary by server implementation.
        var authCloseCodes = new Set([1008, 4001, 4401, 4403]);
        var forceReAuth = authCloseCodes.has(code) || _this3.isAccessTokenExpired(0);
        _this3.scheduleContactsWsReconnect('close', forceReAuth);
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
  connectActiveCallsWs: function connectActiveCallsWs() {
    var _this4 = this;
    try {
      var accessToken = $('#access_token').val();
      if (!accessToken) return;
      if (this._activeCallsWs && (this._activeCallsWs.readyState === WebSocket.OPEN || this._activeCallsWs.readyState === WebSocket.CONNECTING)) {
        return;
      }
      this._activeCallsWsReconnectAttempt = 0;
      var wsProto = window.location.protocol === 'https:' ? 'wss' : 'ws';
      var wsHost = window.location.host;
      var tokenParam = encodeURIComponent(accessToken);
      var wsUrl = "".concat(wsProto, "://").concat(wsHost, "/pbxcore/api/module-softphone-backend/v1/sub/active-calls?authorization=").concat(tokenParam);
      this._activeCallsWs = new WebSocket(wsUrl);
      this._activeCallsWs.onopen = function () {
        console.log('active-calls ws connected');
        _this4.setActiveCallsWsLast('connected');

        // Reconnect shortly before token expires (best-effort).
        try {
          var parts = String(accessToken).split('.');
          if (parts.length >= 2) {
            var payloadB64 = parts[1].replace(/-/g, '+').replace(/_/g, '/');
            var json = atob(payloadB64.padEnd(payloadB64.length + (4 - payloadB64.length % 4) % 4, '='));
            var payload = JSON.parse(json);
            var exp = Number((payload === null || payload === void 0 ? void 0 : payload.exp) || 0);
            if (exp) {
              var now = Math.floor(Date.now() / 1000);
              var secondsToRefresh = Math.max(5, exp - now - 30);
              if (_this4._activeCallsWsTokenTimer) clearTimeout(_this4._activeCallsWsTokenTimer);
              _this4._activeCallsWsTokenTimer = setTimeout(function () {
                _this4.scheduleActiveCallsWsReconnect('token_expiring', true);
              }, secondsToRefresh * 1000);
            }
          }
        } catch (e) {
          // ignore
        }
      };
      this._activeCallsWs.onmessage = function (event) {
        var raw = event === null || event === void 0 ? void 0 : event.data;
        console.log(raw);
        var line = '';
        try {
          var parsed = JSON.parse(String(raw));
          line = JSON.stringify(parsed);
        } catch (e) {
          line = String(raw !== null && raw !== void 0 ? raw : '');
        }
        _this4.setActiveCallsWsLast(line);
      };
      this._activeCallsWs.onerror = function (event) {
        console.log('active-calls ws error', event);
        _this4.setActiveCallsWsLast('error');
      };
      this._activeCallsWs.onclose = function (event) {
        var code = event === null || event === void 0 ? void 0 : event.code;
        var reason = event === null || event === void 0 ? void 0 : event.reason;
        console.log('active-calls ws closed', {
          code: code,
          reason: reason
        });
        _this4.setActiveCallsWsLast("closed code=".concat(code !== null && code !== void 0 ? code : '', " reason=").concat(reason !== null && reason !== void 0 ? reason : ''));
        if (_this4._activeCallsWsTokenTimer) {
          clearTimeout(_this4._activeCallsWsTokenTimer);
          _this4._activeCallsWsTokenTimer = null;
        }
        var authCloseCodes = new Set([1008, 4001, 4401, 4403]);
        var forceReAuth = authCloseCodes.has(code) || _this4.isAccessTokenExpired(0);
        _this4.scheduleActiveCallsWsReconnect('close', forceReAuth);
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
  appendContactsWsLog: function appendContactsWsLog(message) {
    try {
      var ts = new Date().toISOString();
      var line = "[".concat(ts, "] ").concat(String(message !== null && message !== void 0 ? message : ''));
      if (!Array.isArray(this._contactsWsLog)) this._contactsWsLog = [];
      this._contactsWsLog.push(line);
      if (this._contactsWsLog.length > 20) {
        this._contactsWsLog = this._contactsWsLog.slice(-20);
      }
      var $ta = $('#contacts_ws_log');
      if ($ta && $ta.length) {
        $ta.val(this._contactsWsLog.join('\n'));
        // Autoscroll to bottom
        var el = $ta.get(0);
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
  setActiveCallsWsLast: function setActiveCallsWsLast(message) {
    try {
      var line = String(message !== null && message !== void 0 ? message : '');
      var $ta = $('#active_calls_ws_last');
      if ($ta && $ta.length) {
        $ta.val(line);
        var el = $ta.get(0);
        if (el) el.scrollTop = el.scrollHeight;
      }
    } catch (e) {
      // ignore
    }
  },
  /**
   * On page load we init some Semantic UI library
   */
  initialize: function initialize() {
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
  checkStatusToggle: function checkStatusToggle() {
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
  cbBeforeSendForm: function cbBeforeSendForm(settings) {
    var result = settings;
    result.data = window[className].$formObj.form('get values');
    return result;
  },
  /**
   * Some actions after forms send
   */
  cbAfterSendForm: function cbAfterSendForm() {},
  /**
   * Initialize form parameters
   */
  initializeForm: function initializeForm() {
    Form.$formObj = window[className].$formObj;
    Form.url = "".concat(globalRootUrl).concat(idUrl, "/save");
    Form.validateRules = window[className].validateRules;
    Form.cbBeforeSendForm = window[className].cbBeforeSendForm;
    Form.cbAfterSendForm = window[className].cbAfterSendForm;
    Form.initialize();
  },
  /**
   * Update the module state on form label
   * @param status
   */
  changeStatus: function changeStatus(status) {
    switch (status) {
      case 'Connected':
        window[className].$moduleStatus.removeClass('grey').removeClass('red').addClass('green');
        window[className].$moduleStatus.html(globalTranslate.mod_tpl_Connected);
        break;
      case 'Disconnected':
        window[className].$moduleStatus.removeClass('green').removeClass('red').addClass('grey');
        window[className].$moduleStatus.html(globalTranslate.mod_tpl_Disconnected);
        break;
      case 'Updating':
        window[className].$moduleStatus.removeClass('green').removeClass('red').addClass('grey');
        window[className].$moduleStatus.html("<i class=\"spinner loading icon\"></i>".concat(globalTranslate.mod_tpl_UpdateStatus));
        break;
      default:
        window[className].$moduleStatus.removeClass('green').removeClass('red').addClass('grey');
        window[className].$moduleStatus.html(globalTranslate.mod_tpl_Disconnected);
        break;
    }
  }
};
$(document).ready(function () {
  // Ensure module is available via window[className]
  window[className] = ModuleSoftphoneBackend;
  window[className].initialize();
});
//# sourceMappingURL=module-softphone-backend.js.map