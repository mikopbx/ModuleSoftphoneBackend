<form class="ui large grey segment form" id="module-softphone-backend">
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
</form>

