<script>
function relayPost(action, data, done) {
  data.csrf_token = csrf;
  return $.post('/plugins/unraid-aicliagents/AICliAjax.php?action='+action+'&csrf_token='+encodeURIComponent(csrf), data, done, 'json');
}
function relayLoadTopics() {
  $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=relay_get_topics&csrf_token='+encodeURIComponent(csrf), function(r) {
    if (r.status !== 'ok') { $('#relay-topic-list').html('<span style="color:#b91c1c">'+$('<span>').text(r.message || 'Could not load topics.').html()+'</span>'); return; }
    var list=$('#relay-topic-list').empty(), select=$('#relay-topic').empty();
    relayOwnerTopics = [];
    r.topics.filter(function(t){ return !t.managed; }).forEach(function(t) {
      var suffix=t.builtin ? ' <span style="opacity:.65">Default</span>' : (t.state === 'archived' ? ' <span style="color:#b45309">Archived</span>' : '');
      var buttons = t.builtin ? ' <button type="button" class="aicli-btn-slim" data-remove="'+t.topic+'">Remove</button>' : (t.state === 'active' ? ' <button type="button" class="aicli-btn-slim" data-archive="'+t.topic+'">Archive</button>' : ' <button type="button" class="aicli-btn-slim" data-remove="'+t.topic+'">Remove</button>');
      // The action button is pushed to a fixed right edge. Letting it follow
      // variable-length descriptions inline left every row's button at a
      // different x position, which made the list hard to scan.
      // flex-wrap must stay: without it these rows cannot reflow on a narrow
      // viewport and the action button is clipped off the right edge.
      list.append('<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;"><code style="flex:0 0 auto">'+t.topic+'</code>'
        + '<span style="font-size:12px;opacity:.75;flex:1 1 auto;min-width:0;">'+$('<span>').text(t.description || '').html()+'</span>'
        + '<span style="flex:0 0 auto">'+suffix+'</span>'
        + '<span style="flex:0 0 auto;margin-left:auto">'+buttons+'</span></div>');
      if (t.state === 'active') { select.append($('<option>').val(t.topic).text(t.topic)); if (!t.subscription_only) relayOwnerTopics.push(t); }
    });
    $('[data-archive]').off('click').on('click', function(){ relayArchiveTopic($(this).data('archive')); });
    $('[data-remove]').off('click').on('click', function(){ relayRemoveTopic($(this).data('remove')); });
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=relay_get_settings&csrf_token='+encodeURIComponent(csrf), function(settings) { if (settings.status === 'ok') { $('#relay-notifications-mirrored').prop('checked', !!settings.notifications_mirrored); $('#relay-mcp-enabled').prop('checked', !!settings.mcp_enabled); } });
    relayRenderOwnerTable();
  });
}
function relaySaveNotificationsMirrored() { relayPost('relay_set_notifications_mirrored',{enabled:$('#relay-notifications-mirrored').is(':checked')?'1':'0'},function(r){ if(r.status==='ok') swal(r.enabled ? 'Notifications will be mirrored' : 'Notification mirroring off', r.enabled ? 'Unraid notifications from this plugin now go to '+r.topic+'.' : 'Unraid notifications stay in the Unraid UI only.','success'); else swal('Setting not saved',r.message || 'Could not save the notification setting.','error'); }); }
// ---------- restart notice ----------
// Agent CLIs read their tool list once, when they start. Workspaces launched
// from now on are configured before their agent starts, so only sessions that
// were ALREADY running need action — say exactly that rather than a blanket
// "restart everything".
function relayFlatSessions(affected) {
  var out = [];
  (affected || []).forEach(function (a) {
    (a.sessions || []).forEach(function (s) { out.push({ agentId: a.agentId, id: s.id, path: s.path || '' }); });
  });
  return out;
}
function relayReloadSession(sessionId) {
  return $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=agent_signal_reload&id='+encodeURIComponent(sessionId)+'&csrf_token='+encodeURIComponent(csrf));
}
function relayRenderRestartNotice(sessions) {
  var box = $('#relay-restart-notice');
  if (!sessions.length) { box.hide().empty(); return; }
  var list = sessions.map(function (s) {
    return '<div style="display:flex;gap:8px;align-items:center;margin-top:4px;">'
      + '<code style="font-size:11px;">'+$('<span>').text(s.id + (s.path ? ' — ' + s.path : '')).html()+'</code>'
      + '<button type="button" class="aicli-btn-slim" data-relay-reload="'+$('<span>').text(s.id).html()+'">Restart</button>'
      + '</div>';
  }).join('');
  box.html('<div style="padding:12px 14px;border-radius:6px;border-left:3px solid var(--orange,#e68a00);background:var(--mild-background-color,#f6f6f6);">'
    + '<div style="font-weight:700;margin-bottom:2px;">'+sessions.length+' running workspace'+(sessions.length===1?'':'s')+' still using the old settings</div>'
    + '<div style="font-size:12px;opacity:.75;">Agents load their tools when they start, so these need a restart to pick up the change now. '
    + 'Leave them and they will pick it up next time they start on their own.</div>'
    + list
    + '<button type="button" class="aicli-btn-slim" style="margin-top:10px" id="relay-reload-all">Restart all '+sessions.length+'</button>'
    + '</div>').show();
  box.find('[data-relay-reload]').off('click').on('click', function () {
    var btn=$(this); btn.prop('disabled',true);
    relayReloadSession(btn.data('relay-reload')).always(function(){ btn.text('Restart sent'); });
  });
  box.find('#relay-reload-all').off('click').on('click', function () {
    var btn=$(this); btn.prop('disabled',true).text('Restarting…');
    $.when.apply($, sessions.map(function (s) { return relayReloadSession(s.id); }))
      .always(function () { btn.text('Restart sent to all'); box.find('[data-relay-reload]').prop('disabled',true).text('Restart sent'); });
  });
}
function relayHandleAffected(affected) {
  var sessions = relayFlatSessions(affected);
  relayRenderRestartNotice(sessions);
  if (!sessions.length) return false;
  swal({
    title: 'Restart ' + sessions.length + ' running workspace' + (sessions.length===1?'':'s') + '?',
    text: 'Agents load their Relay tools when they start, so workspaces already running are still using the old settings. '
        + 'New workspaces are configured automatically.\n\nRestart them now to apply the change immediately?',
    type: 'warning', showCancelButton: true, confirmButtonText: 'Restart now', cancelButtonText: 'Later'
  }, function (ok) { if (ok) $('#relay-reload-all').trigger('click'); });
  return true;
}
function relaySaveMcpEnabled() { relayPost('relay_set_mcp_enabled',{enabled:$('#relay-mcp-enabled').is(':checked')?'1':'0'},function(r){
  if(r.status!=='ok') { swal('Setting not saved',r.message || 'Could not update the Relay tools setting.','error'); return; }
  relayLoadStatus();
  if (relayHandleAffected(r.affected_sessions)) return;   // the overlay is the confirmation
  swal(r.enabled ? 'Relay tools on' : 'Relay tools off', r.enabled ? 'Every supported agent now gets Relay tools when it starts.' : 'Relay tools were removed. Agents can still use the Relay command line.','success');
}); }
// The endpoint is the plugin-owned listener, NOT the AICliAjax URL this used to
// advertise: Unraid's web interface requires a login, so a bearer-only client
// was always redirected there and could never connect (#113).
var relayHttpListener={enabled:false,port:8237,bind:'0.0.0.0'};
function relayHttpEndpointUrl() { return 'https://'+window.location.hostname+':'+relayHttpListener.port+'/'; }
function relayRenderHttpEndpoint() {
  $('#relay-http-enabled').prop('checked', !!relayHttpListener.enabled);
  $('#relay-http-port').val(relayHttpListener.port);
  $('#relay-http-bind').val(relayHttpListener.bind);
  $('#relay-http-endpoint').text(relayHttpListener.enabled ? 'Endpoint: '+relayHttpEndpointUrl() : 'The external endpoint is off. External clients cannot reach Relay.');
}
function relayLoadHttpListener() {
  $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=relay_get_http_listener&csrf_token='+encodeURIComponent(csrf), function(r) {
    if (r.status === 'ok' && r.listener) { relayHttpListener=r.listener; relayRenderHttpEndpoint(); }
  });
}
function relaySaveHttpListener() {
  relayPost('relay_set_http_listener',{enabled:$('#relay-http-enabled').is(':checked')?'1':'0',port:$('#relay-http-port').val(),bind:$('#relay-http-bind').val()},function(r){
    if (r.status==='ok') { relayHttpListener={enabled:!!r.enabled,port:r.port,bind:r.bind}; relayRenderHttpEndpoint();
      swal(r.enabled ? 'External Relay endpoint enabled' : 'External Relay endpoint disabled', r.enabled ? 'Clients can connect at '+relayHttpEndpointUrl()+' using a bearer token.' : 'The listener has been stopped.','success');
    } else { relayLoadHttpListener(); swal('Endpoint setting not saved', r.message || 'Could not update the external Relay endpoint.','error'); }
  }).fail(function(xhr){ var msg=(xhr.responseJSON && xhr.responseJSON.message) || 'The Relay request failed. Check the Debug Console and retry.'; swal('Endpoint setting not saved',msg,'error'); });
}
// ---------- remotes (RELAY_REMOTE_CLIENTS.md) ----------
// Only masked tokens load with the page; a secret is fetched only when the
// administrator clicks Copy on that row. Row one keeps the ids
// relay-token-masked / relay-token-copy that the Relay UI playbooks assert.
var relayRemotes = [];
function relayEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function relayRemoteSeen(iso) {
  if (!iso) return 'never';
  var t = Date.parse(iso); if (isNaN(t)) return iso;
  var s = Math.max(0, Math.round((Date.now() - t) / 1000));
  if (s < 60) return 'just now'; if (s < 3600) return Math.round(s / 60) + ' min ago';
  if (s < 86400) return Math.round(s / 3600) + ' h ago'; return Math.round(s / 86400) + ' d ago';
}
function relayRenderRemotes() {
  var box = $('#relay-remotes'); box.empty();
  if (!relayRemotes.length) { box.append('<span style="opacity:.7;font-size:12px;">No remotes yet. Add one below; its token is created with it.</span>'); return; }
  var head = '<div style="display:grid;grid-template-columns:minmax(120px,1.2fr) minmax(110px,1fr) minmax(170px,1.4fr) 90px auto;gap:8px;font-size:11px;opacity:.6;padding:0 4px;"><span>Name</span><span>Identity</span><span>Access token</span><span>Last used</span><span></span></div>';
  box.append(head);
  relayRemotes.forEach(function (c, i) {
    var maskedId = i === 0 ? ' id="relay-token-masked"' : '';
    var copyId = i === 0 ? ' id="relay-token-copy"' : '';
    var dot = '<span style="display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:6px;background:' + (c.online ? '#4caf50' : '#888') + '" title="' + (c.online ? 'authenticated in the last 5 minutes' : 'not seen recently') + '"></span>';
    var row = '<div class="relay-remote-row" data-id="' + relayEsc(c.id) + '" style="display:grid;grid-template-columns:minmax(120px,1.2fr) minmax(110px,1fr) minmax(170px,1.4fr) 90px auto;gap:8px;align-items:center;font-size:12px;padding:6px 4px;border-top:1px solid var(--border-color,#e0e0e0);">'
      + '<span>' + dot + '<strong>' + relayEsc(c.name) + '</strong></span>'
      + '<code style="font-size:11px;">' + relayEsc(c.id) + '</code>'
      + '<code' + maskedId + ' style="font-size:11px;padding:3px 6px;border-radius:4px;background:var(--mild-background-color,#f6f6f6);">' + (c.has_token ? relayEsc(c.masked) : 'no token — rotate to create one') + '</code>'
      + '<span style="opacity:.75;">' + relayEsc(relayRemoteSeen(c.last_seen_at)) + '</span>'
      + '<span style="display:flex;gap:4px;flex-wrap:wrap;">'
      + '<button type="button" class="aicli-btn-slim relay-remote-copy"' + copyId + ' data-id="' + relayEsc(c.id) + '" title="Copy this remote\'s token to the clipboard" aria-label="Copy access token"' + (c.has_token ? '' : ' disabled') + '><i class="fa fa-clipboard" aria-hidden="true"></i> Copy</button>'
      + '<button type="button" class="aicli-btn-slim relay-remote-rotate" data-id="' + relayEsc(c.id) + '" title="Replace this remote\'s token; the current one stops working">Rotate</button>'
      + '<button type="button" class="aicli-btn-slim relay-remote-rename" data-id="' + relayEsc(c.id) + '" data-name="' + relayEsc(c.name) + '" title="Rename this remote">Rename</button>'
      + '<button type="button" class="aicli-btn-slim relay-remote-remove" data-id="' + relayEsc(c.id) + '" title="Remove this remote; its token stops working">Remove</button>'
      + '</span></div>';
    box.append(row);
  });
}
function relayLoadHttpClient() {
  $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=relay_get_http_clients&csrf_token='+encodeURIComponent(csrf), function (r) {
    if (r.status !== 'ok') return;
    relayRemotes = Array.isArray(r.clients) ? r.clients : [];
    relayRenderRemotes();
  });
}
function relayCopyToken(id, btn) {
  var original = btn.html();
  $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=relay_reveal_http_token&id='+encodeURIComponent(id)+'&csrf_token='+encodeURIComponent(csrf), function (r) {
    if (r.status !== 'ok' || !r.token) { swal('Nothing to copy', r.message || 'No access token exists for that remote yet.', 'error'); return; }
    var done = function (ok) {
      btn.html(ok ? '<i class="fa fa-check"></i> Copied' : '<i class="fa fa-clipboard"></i> Press Ctrl+C');
      setTimeout(function () { btn.html(original); }, 2500);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(r.token).then(function () { done(true); }, function () { relayCopyFallback(r.token, done); });
    } else {
      // Unraid over plain http is not a secure context, so the async clipboard
      // API is unavailable; fall back to a selected off-screen field.
      relayCopyFallback(r.token, done);
    }
  });
}
function relayCopyFallback(text, done) {
  var ta = $('<textarea>').css({position:'fixed',top:'-1000px',opacity:0}).val(text).appendTo('body');
  ta[0].select();
  var ok = false;
  try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
  ta.remove();
  done(ok);
}
function relayRemoteFail(title) { return function (xhr) { var msg=(xhr.responseJSON && xhr.responseJSON.message) || 'The Relay request failed. Check the Debug Console and retry.'; swal(title, msg, 'error'); }; }
function relayAddRemote() {
  var name = $('#relay-remote-name').val();
  relayPost('relay_create_http_client', {name: name}, function (r) {
    if (r.status === 'ok') { $('#relay-remote-name').val(''); relayLoadHttpClient(); swal('Remote added', 'Use its Copy button to put the new token on your clipboard.', 'success'); }
    else swal('Remote not added', r.message || 'Could not create the remote.', 'error');
  }).fail(relayRemoteFail('Remote not added'));
}
function relayRotateRemote(id, name) {
  swal({title:'Replace the token for ' + name + '?', text:'The current token stops working immediately. The machine using it will need the new one.', type:'warning', showCancelButton:true, confirmButtonText:'Replace token'}, function (ok) {
    if (!ok) return;
    relayPost('relay_rotate_http_client', {id: id}, function (r) {
      if (r.status === 'ok') { relayLoadHttpClient(); swal('Token replaced', 'Use the Copy button on that row to put the new token on your clipboard.', 'success'); }
      else swal('Token not replaced', r.message || 'Could not rotate the token.', 'error');
    }).fail(relayRemoteFail('Token not replaced'));
  });
}
function relayRenameRemote(id, current) {
  swal({title:'Rename remote', text:'Shown to your workspaces as this contact name.', type:'input', inputValue: current, showCancelButton:true, confirmButtonText:'Rename', closeOnConfirm:false}, function (val) {
    if (val === false) return; if (!val || !String(val).trim()) { swal.showInputError('Enter a name.'); return; }
    relayPost('relay_rename_http_client', {id: id, name: String(val).trim()}, function (r) {
      if (r.status === 'ok') { relayLoadHttpClient(); swal('Renamed', 'This remote is now "' + r.name + '".', 'success'); }
      else swal('Not renamed', r.message || 'Could not rename the remote.', 'error');
    }).fail(relayRemoteFail('Not renamed'));
  });
}
function relayRemoveRemote(id, name) {
  swal({title:'Remove ' + name + '?', text:'Its token stops working immediately and it disappears from your workspaces\' contacts. Past messages are kept.', type:'warning', showCancelButton:true, confirmButtonText:'Remove'}, function (ok) {
    if (!ok) return;
    relayPost('relay_delete_http_client', {id: id}, function (r) {
      if (r.status === 'ok') { relayLoadHttpClient(); swal('Remote removed', name + ' can no longer connect.', 'success'); }
      else swal('Not removed', r.message || 'Could not remove the remote.', 'error');
    }).fail(relayRemoteFail('Not removed'));
  });
}
$(document).on('click', '.relay-remote-copy', function () { relayCopyToken($(this).data('id'), $(this)); });
$(document).on('click', '.relay-remote-rotate', function () { var id=$(this).data('id'); var c=relayRemotes.filter(function(x){return x.id===id;})[0]||{}; relayRotateRemote(id, c.name||id); });
$(document).on('click', '.relay-remote-rename', function () { relayRenameRemote($(this).data('id'), $(this).data('name')); });
$(document).on('click', '.relay-remote-remove', function () { var id=$(this).data('id'); var c=relayRemotes.filter(function(x){return x.id===id;})[0]||{}; relayRemoveRemote(id, c.name||id); });
// Compat: the old single-token "Rotate" entry point rotates row one.
function relayCreateHttpClient() { var c = relayRemotes[0]; if (!c) { relayAddRemote(); return; } relayRotateRemote(c.id, c.name); }
// ---------- topic owners table ----------
// One row per topic so every owner is visible at once. Both the catalogue and
// the owner map arrive asynchronously, so each loader records what it got and
// asks to render; the render only runs once both are present.
var relayActors={}, relayActorWorkspaces=[], relayOwnerTopics=null, relayOwnersLoaded=false, relayOwnerRunning={}, relayOwnerKnown={};

function relayOwnerOf(topic) {
  var a = relayActors[topic];
  return typeof a === 'string' ? a : (a && a.session_id) || '';
}
function relayStartsAtBoot(topic) {
  var a = relayActors[topic];
  return !(a && typeof a === 'object' && a.start_on_boot === false);
}
function relayWorkspaceLabel(w) {
  // The workspace name is already the folder name, so appending the full path
  // only pushed the useful part out of the visible width of the select.
  return (w.name || w.id) + ' · ' + (w.agentId || 'agent');
}
var relayOwnerSig = '';
function relayLoadTopicActors() {
  $.when(
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_workspaces&csrf_token='+encodeURIComponent(csrf)),
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=relay_get_actors&csrf_token='+encodeURIComponent(csrf))
  ).done(function(workspacesReply, actorsReply) {
    var workspaces=workspacesReply[0] || {}, actors=actorsReply[0] || {};
    // Only re-render when something actually changed, so a background poll never
    // disrupts an open dropdown / in-progress edit. Covers owner running-state so
    // the button returns from "Running" to "Start now" when the session closes.
    var sig = JSON.stringify([actors.actors||{}, actors.running||{}, actors.known||{},
                              (workspaces.sessions||[]).map(function(s){return (s.id||'')+':'+(s.name||'')+':'+(s.agentId||'');})]);
    if (relayOwnersLoaded && sig === relayOwnerSig) return;
    relayOwnerSig = sig;
    relayActors = actors.actors || {};
    relayOwnerRunning = actors.running || {};
    relayOwnerKnown = actors.known || {};
    relayActorWorkspaces = workspaces.sessions || [];
    relayOwnersLoaded = true;
    relayRenderOwnerTable();
  });
}
function relayOwnerCell(styleExtra) {
  return $('<div>').attr('role','cell').css({padding:'7px 8px', display:'flex', alignItems:'center', gap:'8px', minWidth:0}).css(styleExtra || {});
}
function relayRenderOwnerTable() {
  var box = $('#relay-owner-table');
  if (!box.length || relayOwnerTopics === null || !relayOwnersLoaded) return;
  box.empty().css('gridTemplateColumns', 'minmax(160px,1.4fr) minmax(180px,2fr) auto auto');

  var head = $('<div>').attr('role','row').css('display','contents');
  box.append(head);
  ['Topic', 'Owner workspace', 'Start at boot', ''].forEach(function (h) {
    head.append($('<div>').attr('role','columnheader')
      .css({padding:'6px 8px', fontWeight:700, fontSize:'12px', opacity:.75, borderBottom:'1px solid var(--divider-color,#ddd)'})
      .text(h));
  });

  if (!relayOwnerTopics.length) {
    box.css('gridTemplateColumns','1fr').empty()
       .append($('<div>').css({padding:'8px', opacity:.7}).text('No topics can take an owner yet. Add a topic above.'));
    return;
  }

  relayOwnerTopics.forEach(function (t, i) {
    var owner = relayOwnerOf(t.topic);
    var stripe = i % 2 ? 'transparent' : 'var(--mild-background-color,#f6f6f6)';
    // display:contents lets a real role="row" wrapper carry the table semantics
    // while its cells still lay out in the parent grid.
    var row = $('<div>').attr('role','row').css('display','contents');
    box.append(row);

    var cTopic = relayOwnerCell({background:stripe});
    cTopic.append($('<code>').css({fontSize:'12px', overflowWrap:'anywhere'}).text(t.topic));
    row.append(cTopic);

    var cOwner = relayOwnerCell({background:stripe});
    var sel = $('<select>').attr('aria-label','Owner workspace for '+t.topic).css({maxWidth:'100%'})
      .append($('<option>').val('').text('No owner'));
    relayActorWorkspaces.forEach(function (w) { sel.append($('<option>').val(w.id).text(relayWorkspaceLabel(w))); });
    // An owner whose tab has been closed must still be shown, or the row would
    // silently read as unowned. Its identity is retained, so present the human
    // name/agent (and full path on hover) rather than a bare session id (#127).
    var closed = false;
    if (owner && !sel.find('option[value="'+owner+'"]').length) {
      closed = true;
      var k = relayOwnerKnown[owner];
      var opt = $('<option>').val(owner);
      if (k) { opt.text((k.name || owner) + ' · ' + (k.agentId || 'agent')); if (k.path) opt.attr('title', k.path); }
      else { opt.text(owner + ' · not in drawer'); }
      sel.append(opt);
    }
    sel.val(owner).on('change', function () { relaySaveOwnerRow(t.topic, $(this).val(), relayStartsAtBoot(t.topic)); });
    cOwner.append(sel);
    if (closed) {
      var kk = relayOwnerKnown[owner];
      cOwner.append($('<span>').css({fontSize:'11px', opacity:.7, whiteSpace:'nowrap'})
        .attr('title', kk && kk.path ? kk.path : '')
        .text(kk ? '· closed, reopens on Start' : ''));
    }
    row.append(cOwner);

    var cBoot = relayOwnerCell({background:stripe, justifyContent:'center'});
    var boot = $('<input>').attr({type:'checkbox', 'aria-label':'Start '+t.topic+' owner at boot'})
      .prop('checked', relayStartsAtBoot(t.topic)).prop('disabled', !owner)
      .on('change', function () { relaySaveOwnerRow(t.topic, relayOwnerOf(t.topic), $(this).is(':checked')); });
    cBoot.append(boot);
    row.append(cBoot);

    var cAct = relayOwnerCell({background:stripe});
    if (owner) {
      var up = !!relayOwnerRunning[owner];
      var startBtn = $('<button>').attr({type:'button','class':'aicli-btn-slim'}).css('margin',0);
      if (up) {
        // Already running: offering Start now would do nothing, so show state.
        startBtn.html('<i class="fa fa-check"></i> Running').prop('disabled', true)
          .css({opacity:.45, cursor:'default'}).attr('title', 'This workspace is already running');
      } else {
        startBtn.html('<i class="fa fa-play"></i> Start now').attr('title', 'Start this workspace now')
          .on('click', function () { relayStartOwner(owner); });
      }
      cAct.append(startBtn);
    } else if (!relayActorWorkspaces.length) {
      // Nothing to assign: point at where workspaces are created rather than
      // leaving an empty dropdown with no way forward.
      cAct.append($('<button>').attr({type:'button','class':'aicli-btn-slim'}).css('margin',0)
        .html('<i class="fa fa-plus"></i> Create a workspace')
        .on('click', relayOpenWorkspaceCreator));
    }
    row.append(cAct);
  });
}
function relaySaveOwnerRow(topic, sessionId, startOnBoot) {
  relayPost('relay_set_actor', {topic: topic, session_id: sessionId || '', start_on_boot: startOnBoot ? '1' : '0'}, function (r) {
    if (r.status !== 'ok') { swal('Owner not saved', r.message || 'Could not save the topic owner.', 'error'); relayLoadTopicActors(); return; }
    if (sessionId) relayActors[topic] = {session_id: sessionId, start_on_boot: !!startOnBoot};
    else delete relayActors[topic];
    relayRenderOwnerTable();
    relayLoadStatus();
    // Saving an owner also starts that workspace when it is stopped, so say so
    // rather than letting a session appear out of nowhere.
    if (!sessionId) swal('Owner cleared', 'No workspace is accountable for ' + topic + ' now.', 'success');
    else if (r.actor_started) swal('Owner saved and started', topic + ' is now owned by this workspace, which was stopped and has been started.', 'success');
    else swal('Owner saved', topic + ' is now owned by this workspace.', 'success');
  }).fail(function () { swal('Owner not saved', 'The Relay request failed. Check the Debug Console and retry.', 'error'); relayLoadTopicActors(); });
}
// Workspaces are created in the drawer on the AI CLI Agents page — a separate
// React surface. The #new-workspace hash route opens its picker directly, so
// this lands on the real creation flow rather than just the page.
function relayOpenWorkspaceCreator() {
  swal({
    title: 'Create a workspace first',
    text: 'Topic owners are chosen from your saved workspaces, and you do not have any yet. '
        + 'This opens the workspace picker; come back here to assign it once it exists.',
    type: 'info', showCancelButton: true, confirmButtonText: 'Create a workspace', cancelButtonText: 'Stay here'
  }, function (ok) { if (ok) window.location.href = '/AICliAgents#new-workspace'; });
}
function relayStartOwner(sessionId) {
  relayPost('relay_launch_actor', {session_id: sessionId}, function (r) {
    if (r.status === 'ok') {
      var msg = r.restored
        ? 'This workspace was reopened in the drawer from its saved details and started. It will start automatically when its topic needs it.'
        : 'This workspace will start automatically again when its topic needs it.';
      swal(r.restored ? 'Topic owner reopened and started' : 'Topic owner started', msg, 'success');
      relayLoadTopicActors();
    } else swal('Owner not started', r.message || 'Could not start the topic owner.', 'error');
  });
}
// Tabular, collapsible history row: a compact header (click to expand) over the full
// message body. Supports topic events, direct requests, and direct messages.
function relayHistoryRow(record, kind) {
  var when=record.created_at || '';
  var summary=record.summary || '(no summary)';
  var head;
  if (kind==='direct') head=(record.sender_name || record.sender_session || '?')+' → '+(record.recipient_session || '?');
  else if (kind==='event') head='topic '+(record.topic || '')+(record.severity ? ' · '+record.severity : '');
  else head='request '+(record.topic || '')+' · '+(record.state || 'pending');
  var body=$('<div>').css({padding:'0 10px 8px 24px',whiteSpace:'pre-wrap',wordBreak:'break-word',opacity:'.9',fontSize:'12px',display:'none'})
    .text(summary+(kind==='request' && record.note ? '\nOwner note: '+record.note : ''));
  var caret=$('<i class="fa fa-chevron-right">').css({fontSize:'9px',opacity:'.5',width:'9px',flexShrink:0});
  var preview=$('<span>').css({flex:'1',minWidth:'0',opacity:'.65',overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}).text(summary);
  var header=$('<div>').css({display:'flex',alignItems:'center',gap:'6px',minWidth:'0',padding:'5px 8px',cursor:'pointer',fontSize:'12px'})
    .append(caret)
    .append($('<span>').css({flexShrink:0,fontWeight:600,maxWidth:'220px',overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}).text(head))
    .append(preview)
    .append($('<span>').css({opacity:'.45',whiteSpace:'nowrap',flexShrink:0,fontSize:'10px'}).text(when));
  header.on('click',function(){ var open=body.is(':visible'); body.toggle(!open); preview.toggle(open); caret.attr('class', open ? 'fa fa-chevron-right' : 'fa fa-chevron-down'); });
  return $('<div>').css({borderBottom:'1px solid var(--divider-color,rgba(128,128,128,.14))'}).append(header).append(body);
}
function relayLoadHistory() {
  var holder=$('#relay-history').empty().append($('<span>').css('opacity','.7').text('Loading history…'));
  $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=relay_get_history&limit='+encodeURIComponent($('#relay-history-limit').val() || '100')+'&csrf_token='+encodeURIComponent(csrf),function(r) {
    holder.empty(); if (r.status!=='ok') { holder.append($('<span>').css('color','#b91c1c').text(r.message || 'Could not load Relay history.')); return; }
    var h=r.history || {}, events=h.events || [], requests=h.requests || [], directs=h.direct_messages || [];
    holder.append($('<strong>').text('Direct messages ('+directs.length+')'));
    if (directs.length) directs.forEach(function(dm){ holder.append(relayHistoryRow(dm,'direct')); }); else holder.append($('<div>').css({opacity:'.7',marginBottom:'6px'}).text('No direct messages yet.'));
    holder.append($('<strong>').css('margin-top','10px').text('Topic events ('+events.length+')'));
    if (events.length) events.forEach(function(event){ holder.append(relayHistoryRow(event,'event')); }); else holder.append($('<div>').css({opacity:'.7',marginBottom:'6px'}).text('No published Relay events yet.'));
    holder.append($('<strong>').css('margin-top','10px').text('Direct requests ('+requests.length+')'));
    if (requests.length) requests.forEach(function(request){ holder.append(relayHistoryRow(request,'request')); }); else holder.append($('<div>').css({opacity:'.7'}).text('No direct requests yet.'));
  }).fail(function(){ holder.empty().append($('<span>').css('color','#b91c1c').text('Could not load Relay history.')); });
}
function relaySaveTopic() {
  var topic=$('#relay-new-topic').val().trim(), description=$('#relay-topic-description').val().trim();
  relayPost('relay_save_topic',{topic:topic,description:description},function(r){ if(r.status==='ok'){ $('#relay-new-topic,#relay-topic-description').val(''); relayLoadTopics(); } else swal('Topic not saved',r.message || 'Could not save topic.','error'); });
}
function relayArchiveTopic(topic) { swal({title:'Archive topic?',text:'Existing subscriptions are retained, but no new events can use it.',type:'warning',showCancelButton:true},function(ok){ if(ok) relayPost('relay_archive_topic',{topic:topic},function(r){ if(r.status==='ok') relayLoadTopics(); else swal('Topic not archived',r.message || 'Could not archive topic.','error'); }); }); }
function relayRemoveTopic(topic) { swal({title:'Remove topic?',text:'Default topics are removed from every workspace subscription; custom topics must first be unsubscribed everywhere.',type:'warning',showCancelButton:true},function(ok){ if(ok) relayPost('relay_remove_topic',{topic:topic},function(r){ if(r.status==='ok') relayLoadTopics(); else swal('Topic not removed',r.message || 'Could not remove topic.','error'); }); }); }
function relayPublishTest() {
  var summary = document.getElementById('relay-summary').value.trim();
  if (!summary) { swal('Summary required', 'Enter a concise test summary.', 'warning'); return; }
  swal({title:'Publish test event?',text:'Subscribed inboxes will receive this message. A topic owner gets only a short notice telling it to check its inbox.',type:'warning',showCancelButton:true}, function(ok) {
    if (!ok) return;
    $.post('/plugins/unraid-aicliagents/AICliAjax.php?action=relay_publish_test&csrf_token='+encodeURIComponent(csrf), {topic:$('#relay-topic').val(),severity:$('#relay-severity').val(),summary:summary,csrf_token:csrf}, function(r) {
      if (r.status === 'ok') { swal('Test event published', 'Delivered to '+r.recipients+' subscribed inbox(es).', 'success'); document.getElementById('relay-summary').value=''; }
      else swal('Relay error', r.message || 'Could not publish the event.', 'error');
    }, 'json');
  });
}
// ---------- status ----------
// The page leads with what Relay is doing, so an administrator can answer
// "is this working?" without reading any of the controls below.
function relayLoadStatus() {
  $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=relay_get_status&csrf_token='+encodeURIComponent(csrf), function (r) {
    var box=$('#relay-status').empty();
    if (r.status !== 'ok') { box.html('<span style="color:#b91c1c">Could not read Relay status.</span>'); return; }
    var s=r.relay || {};
    var on=!!s.mcp_enabled;
    box.css('border-left-color', on ? 'var(--green,#3d9970)' : 'var(--orange,#e68a00)');
    var head=on ? 'Agent Relay is active' : 'Agent Relay is running with built-in tools turned off';
    var bits=[];
    bits.push(s.workspaces + ' workspace' + (s.workspaces===1?'':'s') + ' connected');
    bits.push(s.topics + ' topic' + (s.topics===1?'':'s'));
    if (s.owners) bits.push(s.owners + ' owned');
    if (s.events_24h !== undefined) bits.push(s.events_24h + ' message' + (s.events_24h===1?'':'s') + ' in 24h');
    box.append($('<div>').css({fontWeight:700}).text(head));
    box.append($('<div>').css({fontSize:'12px',opacity:.75,width:'100%'}).text(bits.join(' · ')));
  });
}
$(relayLoadStatus);
$(relayLoadTopics);
$(relayLoadTopicActors);
$(relayLoadHttpListener);
$(relayLoadHttpClient);
$(relayLoadHistory);
// Live-refresh the owner table + status while the Relay tab is visible, so a
// topic owner's button returns from "Running" to "Start now" when its session
// closes (and reflects a workspace started elsewhere) without a manual refresh.
// EVENT_FIRST_RECONCILIATION.md fact table: "Workspace … 30s" is the reconcile
// cadence; a cheap aicli_workspaces subscription (below) refreshes at once on
// any lifecycle event instead of waiting on the timer.
function relayRefreshIfVisible() {
  if ($('#tab-relay').is(':visible')) { relayLoadTopicActors(); relayLoadStatus(); }
}
setInterval(relayRefreshIfVisible, 30000);
if (typeof NchanSubscriber !== 'undefined') {
  try {
    var relayWorkspacesSub = new NchanSubscriber('/sub/aicli_workspaces', {subscriber: 'websocket', reconnectTimeout: 5000});
    relayWorkspacesSub.on('message', relayRefreshIfVisible);
    relayWorkspacesSub.start();
  } catch (e) { /* the 30s poll above still covers this tab */ }
}
// ---------- #157: terminal-input safety rules (block-list + per-agent idle allow-list) ----------
// Governs TmuxService::paneAcceptsInput() — a Relay notice, Continue, and Reload,
// not only Relay (renamed from "relay gate" 2026-09-09). The control now lives on
// the Configuration tab (Session & Environment); this script stays in the Relay
// bundle since it is loaded on every tab regardless of file location, and moving
// it added no behaviour change, only file-churn risk.
// The UI edits the same overlay file a coding agent can write by hand
// (pane-input-rules.json): {version, block:{add,disable}, idle:{<agent>:{add,disable}}}.
var paneInput = { rules: null, overlay: null, agents: {}, loaded: false };
function paneInputEsc(s) { return $('<span>').text(s == null ? '' : String(s)).html(); }
function paneInputLoad(force) {
  if (paneInput.loaded && !force) return;
  $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=pane_input_get_rules&csrf_token='+encodeURIComponent(csrf), function(r) {
    if (r.status !== 'ok') { $('#pane-input-block').html('<span style="color:#b91c1c">'+paneInputEsc(r.message || 'Could not load rules.')+'</span>'); return; }
    paneInput.loaded = true;
    paneInput.rules = { block: r.block || [], idle: r.idle || {} };
    paneInput.overlay = r.overlay && typeof r.overlay === 'object' ? r.overlay : {};
    paneInput.agents = r.agents || {};
    var sel = $('#pane-input-agent').empty();
    Object.keys(paneInput.agents).sort().forEach(function(id) { sel.append($('<option>').val(id).text(paneInput.agents[id] || id)); });
    if (sel.find('option[value="claude-code"]').length) sel.val('claude-code');
    paneInputShowErrors(r.errors || []);
    paneInputRender();
    paneInputLoadSessions();
  });
}
function paneInputShowErrors(errors) {
  var box = $('#pane-input-errors');
  if (!errors || !errors.length) { box.hide().empty(); return; }
  box.show().html(errors.map(function(e){ return '<div>&#9888; '+paneInputEsc(e)+'</div>'; }).join(''));
}
function paneInputRow(kind, agent, rule) {
  var tag = rule.source === 'baked' ? '<span style="opacity:.6">built-in</span>' : '<span style="color:#2563eb">yours</span>';
  var key = kind + '|' + (agent || '') + '|' + rule.id;
  return '<div role="listitem" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;'+(rule.disabled?'opacity:.55;':'')+'">'
    + '<label style="display:flex;gap:6px;align-items:center;flex:0 0 auto;"><input type="checkbox" data-pane-input-toggle="'+paneInputEsc(key)+'" '+(rule.disabled?'':'checked')+'> <code>'+paneInputEsc(rule.id)+'</code></label>'
    + '<code style="flex:1 1 200px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+paneInputEsc(rule.re)+'">'+paneInputEsc(rule.re)+'</code>'
    + '<span style="flex:0 0 auto;font-size:11px;">'+tag+'</span>'
    + (rule.source === 'user' ? '<button type="button" class="aicli-btn-slim" data-pane-input-remove="'+paneInputEsc(key)+'" title="Remove this rule">Remove</button>' : '')
    + '</div>';
}
function paneInputRender() {
  if (!paneInput.rules) return;
  var block = $('#pane-input-block').empty();
  if (!paneInput.rules.block.length) block.html('<span style="opacity:.7">No block rules.</span>');
  paneInput.rules.block.forEach(function(rule){ block.append(paneInputRow('block', '', rule)); });
  paneInputRenderIdle();
  $('[data-pane-input-toggle]').off('change').on('change', function(){ paneInputToggle($(this).data('pane-input-toggle'), !$(this).is(':checked')); });
  $('[data-pane-input-remove]').off('click').on('click', function(){ paneInputRemove($(this).data('pane-input-remove')); });
}
function paneInputRenderIdle() {
  if (!paneInput.rules) return;
  var agent = $('#pane-input-agent').val() || '';
  var idle = $('#pane-input-idle').empty();
  var rows = paneInput.rules.idle[agent] || [];
  if (!rows.length) idle.html('<span style="opacity:.7">No idle rules for this agent: block rules only (a message is delivered whenever no block rule matches). Add a pattern that matches this agent\'s empty prompt to hold delivery on any other screen.</span>');
  rows.forEach(function(rule){ idle.append(paneInputRow('idle', agent, rule)); });
  $('[data-pane-input-toggle]').off('change').on('change', function(){ paneInputToggle($(this).data('pane-input-toggle'), !$(this).is(':checked')); });
  $('[data-pane-input-remove]').off('click').on('click', function(){ paneInputRemove($(this).data('pane-input-remove')); });
}
function paneInputSection(kind, agent) {
  var o = paneInput.overlay;
  if (kind === 'block') { o.block = o.block || {add:[],disable:[]}; o.block.add = o.block.add || []; o.block.disable = o.block.disable || []; return o.block; }
  o.idle = o.idle || {}; o.idle[agent] = o.idle[agent] || {add:[],disable:[]}; o.idle[agent].add = o.idle[agent].add || []; o.idle[agent].disable = o.idle[agent].disable || [];
  return o.idle[agent];
}
function paneInputFind(kind, agent) { return kind === 'block' ? paneInput.rules.block : (paneInput.rules.idle[agent] = paneInput.rules.idle[agent] || []); }
function paneInputToggle(key, disabled) {
  var p = String(key).split('|'), kind = p[0], agent = p[1], id = p[2];
  var sec = paneInputSection(kind, agent);
  sec.disable = sec.disable.filter(function(x){ return x !== id; });
  if (disabled) sec.disable.push(id);
  paneInputFind(kind, agent).forEach(function(r){ if (r.id === id) r.disabled = disabled; });
  paneInputRender();
}
function paneInputRemove(key) {
  var p = String(key).split('|'), kind = p[0], agent = p[1], id = p[2];
  var sec = paneInputSection(kind, agent);
  sec.add = sec.add.filter(function(x){ return x.id !== id; });
  var rows = paneInputFind(kind, agent);
  for (var i = rows.length - 1; i >= 0; i--) if (rows[i].id === id && rows[i].source === 'user') rows.splice(i, 1);
  paneInputRender();
}
function paneInputAdd(kind) {
  var input = $(kind === 'block' ? '#pane-input-block-new' : '#pane-input-idle-new');
  var re = $.trim(input.val() || '');
  if (!re) return;
  if (!/^\/.*\/[imsuxU]*$/.test(re)) { swal('Pattern not added', 'Write the pattern as /body/flags, for example /^\\s*>\\s*$/m', 'error'); return; }
  var agent = kind === 'block' ? '' : ($('#pane-input-agent').val() || '');
  var sec = paneInputSection(kind, agent);
  var id = 'user-' + (kind === 'block' ? 'block' : agent) + '-' + Date.now().toString(36);
  sec.add.push({ id: id, re: re });
  paneInputFind(kind, agent).push({ id: id, re: re, source: 'user', disabled: false });
  input.val('');
  paneInputRender();
}
function paneInputSave() {
  relayPost('pane_input_save_rules', { rules: JSON.stringify(paneInput.overlay || {}) }, function(r) {
    if (r.status !== 'ok') { swal('Rules not saved', r.message || 'Could not save the rules.', 'error'); return; }
    paneInputShowErrors(r.errors || []);
    swal((r.errors && r.errors.length) ? 'Saved with warnings' : 'Rules saved', (r.errors && r.errors.length) ? 'Some patterns were rejected and dropped; see the notes above the list.' : 'The plugin now uses the updated rules before it types into a terminal.', (r.errors && r.errors.length) ? 'warning' : 'success');
    paneInputLoad(true);
  });
}
function paneInputReset() { paneInputLoad(true); }
function paneInputLoadSessions() {
  $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_workspaces&csrf_token='+encodeURIComponent(csrf), function(r) {
    var sel = $('#pane-input-probe-session').empty();
    ((r && r.sessions) || []).forEach(function(s) { sel.append($('<option>').val(s.id + '|' + (s.agentId || '')).text((s.name || s.path || s.id) + ' (' + (paneInput.agents[s.agentId] || s.agentId || '?') + ')')); });
    if (!sel.children().length) sel.append($('<option>').val('').text('No workspaces'));
  });
}
function paneInputProbe() {
  var v = String($('#pane-input-probe-session').val() || ''); if (!v) return;
  var p = v.split('|');
  $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=pane_input_probe&csrf_token='+encodeURIComponent(csrf)+'&session_id='+encodeURIComponent(p[0])+'&agent_id='+encodeURIComponent(p[1]), function(r) {
    var out = $('#pane-input-probe-result');
    if (r.status !== 'ok') { out.html('<span style="color:#b91c1c">'+paneInputEsc(r.message || 'Could not read the screen.')+'</span>'); return; }
    out.html(r.ready ? '<span style="color:#15803d">Would type into the pane now ('+paneInputEsc(r.reason)+')</span>' : '<span style="color:#b45309">Would hold: '+paneInputEsc(r.reason)+'</span>');
  });
}
$(function(){ $('#pane-input-rules-section').on('toggle', function(){ if (this.open) paneInputLoad(false); }); });
</script>
