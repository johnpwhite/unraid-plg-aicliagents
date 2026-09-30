<?php /**
 * Agent Relay Manager tab.
 *
 * Relay is always on from first install, so this page leads with what the
 * feature is DOING, not with switches to turn it on. Everything an
 * administrator only touches occasionally — topics, owners, history, and the
 * opt-out — sits behind progressive disclosure, leaving one genuinely optional
 * decision (connecting another machine) in the open.
 */ ?>
<div id="tab-relay" class="aicli-tab-content aicli-layout">
  <div class="aicli-card" style="width:100%;max-width:1100px;">
    <div class="aicli-card-header"><i class="fa fa-exchange"></i> Agent Relay</div>
    <div style="padding:16px; display:grid; grid-template-columns:minmax(0,1fr); gap:18px;">

      <!-- Status first: the answer to "is this working?" -->
      <div id="relay-status" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:12px 14px;border-radius:6px;background:var(--mild-background-color,#f6f6f6);border-left:3px solid var(--green,#3d9970);">
        <span style="opacity:.7">Checking Relay status…</span>
      </div>

      <!-- Filled in after a change that running workspaces cannot pick up. -->
      <div id="relay-restart-notice" style="display:none;"></div>

      <p style="margin:0;opacity:.75;font-size:12px;">
        Your agents share updates and send each other work requests through a shared mailbox.
        Messages are saved to each workspace's inbox and are never typed into a running terminal.
      </p>

      <details style="border:1px solid var(--border-color,#e0e0e0);border-radius:6px;padding:10px 14px;">
        <summary style="cursor:pointer;font-weight:700;">Connect another machine</summary>
        <p style="margin:10px 0 10px;opacity:.7;font-size:12px;">
          Lets agents on other computers join the Relay, encrypted with the same certificate as this web
          interface — use the same address you use here. Anyone with a remote's access token can reach it
          without an Unraid login, so treat each token like a password. The endpoint is off until you
          explicitly enable it and apply the setting; a fresh install creates no external listener or token.
        </p>
        <!-- The switch gets its own row: mixing an inline checkbox with
             label-above-input fields put three controls on three different
             baselines and read as ragged. -->
        <label style="display:flex;gap:6px;align-items:center;margin-bottom:10px;"><input type="checkbox" id="relay-http-enabled"> Allow other machines to connect</label>
        <div style="display:flex;gap:14px;align-items:end;flex-wrap:wrap;">
          <label style="display:grid;gap:3px;font-size:12px;">Port
            <input id="relay-http-port" type="number" min="1024" max="65535" value="8237" style="width:100px">
          </label>
          <label style="display:grid;gap:3px;font-size:12px;">Listen on
            <input id="relay-http-bind" value="0.0.0.0" style="width:140px">
          </label>
          <button type="button" class="aicli-btn-slim" style="margin:0" onclick="relaySaveHttpListener()">Apply</button>
        </div>
        <div id="relay-http-endpoint" style="font-size:12px;opacity:.75;margin-top:8px;"></div>
        <div style="margin-top:12px;display:grid;gap:6px;">
          <span style="font-size:12px;font-weight:600;">Remotes</span>
          <span style="font-size:12px;opacity:.7;">
            One row per machine or agent that connects from outside; each has its own access token and appears
            to your workspaces as a contact. Paste the token into the remote agent's MCP configuration as a
            bearer token, and it can read its inbox, list contacts, and send direct messages.
          </span>
          <div id="relay-remotes" style="display:grid;gap:4px;"><span style="opacity:.7;font-size:12px;">Loading remotes…</span></div>
          <div style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:4px;">
            <label style="display:grid;gap:3px;font-size:12px;">New remote name
              <input id="relay-remote-name" placeholder="for example John's laptop" maxlength="40" style="width:200px">
            </label>
            <button type="button" class="aicli-btn-slim" style="margin:0" onclick="relayAddRemote()" title="Create a remote with its own access token">Add remote</button>
          </div>
        </div>
      </details>

      <!-- RELAY_LINKED_BOXES.md (#309): workspaces on two paired Unraid boxes
           send each other direct messages. Below Remotes, as the spec says.
           Phone rules (MOBILE_OVERLAYS.md): every control is 44 x 44 px below
           600 px, the peer table becomes stacked cards, and the pairing code
           wraps instead of scrolling the page sideways. -->
      <details id="relay-peers-section" class="relay-peers" style="border:1px solid var(--border-color,#e0e0e0);border-radius:6px;padding:10px 14px;">
        <summary style="cursor:pointer;font-weight:700;">Linked boxes</summary>
        <p style="margin:10px 0 10px;opacity:.75;font-size:12px;">
          Link this Unraid box with another box that runs AI CLI Agents. Workspaces on both boxes then see each
          other as contacts (for example <code>bravo @ tower</code>) and can send private messages. A message from
          another box is delivered like a local one and gives no authority. Nothing is sent until you pair the boxes
          with a one-time code. Both boxes need "Allow other machines to connect" turned on.
        </p>
        <div class="relay-peer-row">
          <label class="relay-peer-check"><input type="checkbox" id="relay-peer-links-enabled"> Allow linked boxes</label>
          <button type="button" class="aicli-btn-slim relay-peer-btn" onclick="relaySavePeerLinks()">Apply</button>
        </div>
        <div id="relay-peer-links-reason" class="relay-peer-note" hidden></div>
        <div id="relay-peer-self" class="relay-peer-note"></div>
        <div class="relay-peer-row">
          <button type="button" class="aicli-btn-slim relay-peer-btn" id="relay-peer-link-btn" onclick="relayPeerShowCode()"><i class="fa fa-link"></i> Link a box</button>
          <button type="button" class="aicli-btn-slim relay-peer-btn" id="relay-peer-enter-btn" onclick="relayPeerOpenEnter()"><i class="fa fa-keyboard-o"></i> Enter a pairing code</button>
        </div>

        <!-- Box A: the one-time code. -->
        <div id="relay-peer-code-panel" class="relay-peer-panel" hidden>
          <div style="font-weight:600;">Pairing code for this box</div>
          <p class="relay-peer-note">Copy this code and enter it on the other box within 15 minutes. It works one time only.</p>
          <label for="relay-peer-code" class="relay-peer-note">Code</label>
          <textarea id="relay-peer-code" class="relay-peer-code" readonly rows="3"></textarea>
          <div class="relay-peer-note" id="relay-peer-code-meta"></div>
          <div class="relay-peer-row">
            <button type="button" class="aicli-btn-slim relay-peer-btn" onclick="relayPeerCopyCode(this)"><i class="fa fa-clipboard"></i> Copy</button>
            <button type="button" class="aicli-btn-slim relay-peer-btn" onclick="relayPeerCancelCode()">Cancel code</button>
          </div>
        </div>

        <!-- Box B: enter the code, then confirm what it will link to. -->
        <div id="relay-peer-enter-panel" class="relay-peer-panel" hidden>
          <label for="relay-peer-enter-code" style="font-weight:600;">Pairing code from the other box</label>
          <textarea id="relay-peer-enter-code" class="relay-peer-code" rows="3" placeholder="AICLI-LINK1-…" autocomplete="off" spellcheck="false"></textarea>
          <div class="relay-peer-row">
            <button type="button" class="aicli-btn-slim relay-peer-btn" onclick="relayPeerPreview()">Check code</button>
            <button type="button" class="aicli-btn-slim relay-peer-btn" onclick="relayPeerCloseEnter()">Cancel</button>
          </div>
          <div id="relay-peer-confirm" hidden>
            <p class="relay-peer-note" id="relay-peer-confirm-text"></p>
            <label for="relay-peer-confirm-url" class="relay-peer-note">Address of the other box (you can edit it)</label>
            <input id="relay-peer-confirm-url" class="relay-peer-input" autocomplete="off" spellcheck="false">
            <div id="relay-peer-replace-wrap" hidden>
              <label for="relay-peer-replace" class="relay-peer-note">This box looks like one you linked before</label>
              <select id="relay-peer-replace" class="relay-peer-input"></select>
            </div>
            <div class="relay-peer-row">
              <button type="button" class="aicli-btn-slim relay-peer-btn" id="relay-peer-redeem-btn" onclick="relayPeerRedeem()"><i class="fa fa-link"></i> Link</button>
            </div>
          </div>
        </div>

        <div id="relay-peers" class="relay-peer-table" role="table" aria-label="Linked boxes"><span class="relay-peer-note">Loading linked boxes…</span></div>
      </details>

      <!-- Permissions for one linked box (one dialog for remotes and peers; spec §6). -->
      <div id="relay-perm-overlay" class="relay-perm-overlay" hidden>
        <div class="relay-perm-dialog" role="dialog" aria-modal="true" aria-labelledby="relay-perm-title">
          <h3 id="relay-perm-title" style="margin:0 0 6px;">Permissions</h3>
          <p class="relay-peer-note" id="relay-perm-intro"></p>
          <div id="relay-perm-peer-fields" class="relay-perm-fields">
            <label class="relay-peer-check"><input type="checkbox" id="relay-perm-dm"> Allow private messages to this box's workspaces</label>
            <fieldset class="relay-perm-set">
              <legend class="relay-peer-note">Workspaces the other box can see</legend>
              <label class="relay-peer-check"><input type="radio" name="relay-perm-scope" value="all"> All saved workspaces</label>
              <label class="relay-peer-check"><input type="radio" name="relay-perm-scope" value="list"> Only the workspaces I choose</label>
              <label class="relay-peer-check"><input type="radio" name="relay-perm-scope" value="none"> None</label>
              <div id="relay-perm-list" class="relay-perm-list"></div>
            </fieldset>
          </div>
          <!-- RELAY_LINKED_BOXES.md Phase 2 (#299): a remote (an MCP client with a
               token). The server re-checks every grant; this form only proposes. -->
          <div id="relay-perm-remote-fields" class="relay-perm-fields" hidden>
            <label class="relay-peer-check"><input type="checkbox" id="relay-perm-r-inbox"> Read its own inbox</label>
            <label class="relay-peer-check"><input type="checkbox" id="relay-perm-r-contacts"> See the list of workspaces</label>
            <label class="relay-peer-check"><input type="checkbox" id="relay-perm-r-dm"> Send private messages to workspaces</label>
            <fieldset class="relay-perm-set">
              <legend class="relay-peer-note">Requests to topic actors (off by default)</legend>
              <p class="relay-peer-note">A checked topic lets this remote send work requests to that topic's actor and read their status. The actor workspace can start for a request. Only the actor acts; request text is data, never a command.</p>
              <div id="relay-perm-topics" class="relay-perm-list relay-perm-topics"></div>
              <label class="relay-peer-check"><input type="checkbox" id="relay-perm-r-cancel"> May cancel its own requests</label>
            </fieldset>
          </div>
          <label for="relay-perm-rate" class="relay-peer-note">Messages and calls a minute (1–600)</label>
          <input id="relay-perm-rate" type="number" min="1" max="600" class="relay-peer-input" style="max-width:140px;">
          <div class="relay-peer-row relay-perm-actions">
            <button type="button" class="aicli-btn-slim relay-peer-btn" onclick="relayPermClose()">Cancel</button>
            <button type="button" class="aicli-btn-slim relay-peer-btn" onclick="relayPermSave()">Save</button>
          </div>
        </div>
      </div>

      <details style="border:1px solid var(--border-color,#e0e0e0);border-radius:6px;padding:10px 14px;">
        <summary style="cursor:pointer;font-weight:700;">Topics</summary>
        <div style="display:grid;gap:16px;margin-top:14px;">
          <div>
            <p style="margin:0 0 10px;opacity:.7;font-size:12px;">
              Topics are the channels agents subscribe to; built-in platform topics cannot be edited.
              Archive a topic to stop new messages while keeping its history.
            </p>
            <div id="relay-topic-list" style="display:grid;gap:6px;margin-bottom:10px;"><span style="opacity:.7">Loading topics…</span></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
              <label style="display:grid;gap:3px;font-size:12px;flex:1;min-width:190px;">New topic name
                <input id="relay-new-topic" maxlength="128" placeholder="local.llm.health">
              </label>
              <label style="display:grid;gap:3px;font-size:12px;flex:2;min-width:220px;">Description (optional)
                <input id="relay-topic-description" maxlength="280" placeholder="What this topic is for">
              </label>
              <button type="button" class="aicli-btn-slim" onclick="relaySaveTopic()"><i class="fa fa-plus"></i> Add topic</button>
            </div>
          </div>

          <div>
            <div style="font-weight:600;margin-bottom:4px;">Mirror Unraid notifications</div>
            <p style="margin:0 0 8px;opacity:.7;font-size:12px;">
              Copies this plugin's Unraid notifications into the Relay so agents see them. They always go to
              the fixed topic <code>platform.unraid.notification</code>, which is managed for you and is not
              listed above.
            </p>
            <label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" id="relay-notifications-mirrored"> Mirror notifications into the Relay</label>
            <button type="button" class="aicli-btn-slim" style="margin-top:8px" onclick="relaySaveNotificationsMirrored()">Apply</button>
          </div>
        </div>
      </details>

      <!-- CONTINUE_ON_RESTART.md (2026-09-09): "Continue after a restart" moved to
           Settings > Configuration > Session & Environment — it is session-restart
           behaviour, not Relay messaging, and did not belong on this tab. -->

      <!-- PANE_INPUT_HYBRID_ALLOWLIST.md (2026-09-09): "Relay delivery rules" moved to
           Settings > Configuration > Session & Environment and renamed to "Terminal
           input safety rules". It governs TmuxService::paneAcceptsInput() for every
           caller — a Relay notice, Continue, and Reload — not only Relay, so it did
           not belong on this tab either. -->

      <details style="border:1px solid var(--border-color,#e0e0e0);border-radius:6px;padding:10px 14px;">
        <summary style="cursor:pointer;font-weight:700;">Topic owners</summary>
        <p style="margin:10px 0 10px;opacity:.7;font-size:12px;">
          An owner is the one workspace responsible for a topic, for example your local LLM agent owning the
          LLM service topic; it receives work requests, is subscribed automatically, and can start on its own
          at boot. Every topic is listed below so you can see which ones have an owner, and changes save as
          you make them.
        </p>
        <!-- Every topic in one place. The previous single topic-plus-owner pair
             showed one topic at a time, so an owner set on another topic looked
             like it had not saved. Grid rather than <table> to stay consistent
             with the rest of the plugin; ARIA roles keep the table semantics. -->
        <div id="relay-owner-table" role="table" aria-label="Topic owners" style="display:grid;gap:0;font-size:13px;">
          <span style="opacity:.7">Loading topics…</span>
        </div>
      </details>

      <details style="border:1px solid var(--border-color,#e0e0e0);border-radius:6px;padding:10px 14px;">
        <summary style="cursor:pointer;font-weight:700;">Activity</summary>

        <!-- Sending a test message belongs with the record it produces: send one
             here and it appears in the list directly below. -->
        <div style="margin-top:12px;">
          <div style="font-weight:600;margin-bottom:4px;">Send a test message</div>
          <p style="margin:0 0 8px;opacity:.7;font-size:12px;">Checks that subscriptions and inboxes are working, and gives this list something to show. It cannot run commands or reach a terminal.</p>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <label style="display:grid;gap:3px;font-size:12px;">Topic <select id="relay-topic"></select></label>
            <label style="display:grid;gap:3px;font-size:12px;">Importance
              <select id="relay-severity"><option value="info">Info</option><option value="warning">Warning</option><option value="critical">Critical</option></select>
            </label>
            <label style="display:grid;gap:3px;font-size:12px;flex:1;min-width:260px;">Message
              <input id="relay-summary" maxlength="2048" placeholder="Test message">
            </label>
            <button type="button" class="aicli-btn-slim" style="margin:0" onclick="relayPublishTest()"><i class="fa fa-paper-plane"></i> Send</button>
          </div>
        </div>

        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:16px 0 8px;padding-top:14px;border-top:1px solid var(--divider-color,#ddd);">
          <label style="display:grid;gap:3px;font-size:12px;">Show
            <select id="relay-history-limit"><option value="25">Latest 25</option><option value="100" selected>Latest 100</option><option value="250">Latest 250</option></select>
          </label>
          <button type="button" class="aicli-btn-slim" style="margin:0" onclick="relayLoadHistory()"><i class="fa fa-refresh"></i> Refresh</button>
        </div>
        <div id="relay-history" style="display:grid;grid-template-columns:minmax(0,1fr);gap:12px;"><span style="opacity:.7">Loading activity…</span></div>
      </details>

      <details style="border:1px solid var(--border-color,#e0e0e0);border-radius:6px;padding:10px 14px;">
        <summary style="cursor:pointer;font-weight:700;">Advanced</summary>
        <p style="margin:10px 0 8px;opacity:.7;font-size:12px;">
          Relay tools are installed for every supported agent automatically. Turning them off removes the
          tools at each workspace's next start; agents can still use the Relay command line.
        </p>
        <label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" id="relay-mcp-enabled"> Give agents built-in Relay tools</label>
        <button type="button" class="aicli-btn-slim" style="margin-left:8px" onclick="relaySaveMcpEnabled()">Apply</button>
      </details>

    </div>
  </div>
</div>
