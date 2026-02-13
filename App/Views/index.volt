<form class="ui large grey segment form" id="module-softphone-backend-form">

{{ form.render('id') }}

<h3 class="ui dividing header">{{ t._('mod_sfb_ExternalServerHeader') }}</h3>

<div class="field">
    <label>{{ t._('mod_sfb_ExternalPort') }}</label>
    {{ form.render('externalPort') }}
</div>

<div class="field">
    <div class="ui toggle checkbox">
        {{ form.render('useHttps') }}
        <label>{{ t._('mod_sfb_UseHttps') }}</label>
    </div>
</div>

<div class="field">
    <label>{{ t._('mod_sfb_UrlPrefix') }}</label>
    <div class="ui action input">
        {{ form.render('urlPrefix') }}
        <button class="ui icon button" type="button" id="regenerate-prefix-btn">
            <i class="sync icon"></i>
            {{ t._('mod_sfb_RegeneratePrefix') }}
        </button>
    </div>
    <div class="ui small info message">
        {{ t._('mod_sfb_UrlPrefixHint') }}
    </div>
</div>

<h3 class="ui dividing header">{{ t._('mod_sfb_WsDebugHeader') }}</h3>

<input id="access_token" type="hidden" name="access_token" class="noselection" value="{{ authDAta['access_token'] }}">

<div class="field">
    <label>Contacts WS (last 20 messages)</label>
    <textarea
        id="contacts_ws_log"
        class="ui fluid"
        readonly
        wrap="off"
        style="font-family: monospace; min-height: 220px;"
    ></textarea>
</div>

<div class="field">
    <label>Active calls WS (last message)</label>
    <textarea
        id="active_calls_ws_last"
        class="ui fluid"
        readonly
        wrap="off"
        style="font-family: monospace; min-height: 120px;"
    ></textarea>
</div>

{{ partial("partials/submitbutton", ['indexurl': '']) }}
</form>
