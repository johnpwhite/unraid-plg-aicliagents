<?php
/**
 * <module_context>
 * Description: CSS styles for the AICliAgents Manager settings page.
 * Dependencies: Unraid Dynamix Base CSS.
 * Constraints: Atomic CSS (< 150 lines).
 * </module_context>
 */
?>
<style>
    /* Google Fonts — MUST be the first rule: CSS @import directives are only
       valid at the top of a stylesheet. Placed mid-file they're silently
       dropped by the browser. Fraunces is the mockup's display serif;
       JetBrains Mono is the values/mono-token face. display=swap so the
       fallback stack paints instantly while the custom faces load. */
    @import url('https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600&family=JetBrains+Mono:wght@400;500&display=swap');

    /* Tab Navigation */
    .aicli-tabs { display: flex; gap: 2px; margin-bottom: 0; border-bottom: 1px solid var(--border-color, #333); padding-left: 10px; }
    .aicli-tab-btn {
        padding: 10px 25px; background: var(--title-header-background-color, #222); color: var(--text-color, #888); 
        border-radius: 6px 6px 0 0; opacity: 0.7;
        cursor: pointer; font-weight: 800; font-size: 11px; text-transform: uppercase;
        border: 1px solid var(--border-color, #333); border-bottom: none; 
        transition: all 0.2s; position: relative; bottom: -1px;
        letter-spacing: 0.05em;
    }
    .aicli-tab-btn:hover { opacity: 1; color: var(--text-color, #eee); }
    .aicli-tab-btn.active {
        background: var(--orange, #ff8c00); color: #fff; border-color: var(--orange, #ff8c00); opacity: 1;
        box-shadow: 0 -4px 10px rgba(255,140,0,0.2);
        z-index: 2;
    }
    
    .aicli-tab-content { display: none !important; width: 100% !important; }
    .aicli-tab-content.active { display: flex !important; flex-direction: column !important; }

    .aicli-layout { gap: 20px !important; width: 100% !important; }
    .aicli-cards { width: 100%; display: flex; flex-direction: column; }
    .aicli-config-grid {
        display: grid !important;
        grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)) !important;
        gap: 20px !important;
        width: 100% !important;
        max-width: 100% !important;
    }

    .aicli-card {
        background: var(--background-color, #1e1e1e);
        border-radius: 8px;
        border: 1px solid var(--border-color, #333);
        margin-bottom: 20px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.5);
        overflow: hidden;
    }
    .aicli-card-header {
        background: var(--title-header-background-color, #2a2a2a);
        padding: 10px 15px;
        border-bottom: 1px solid var(--border-color, #333);
        font-weight: bold;
        font-size: 1.1em;
        color: var(--text-color, #eee);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .aicli-card-body { padding: 15px; color: var(--text-color, #ccc); overflow: hidden; }

    .aicli-card-body dl {
        display: grid !important;
        grid-template-columns: auto 1fr !important;
        gap: 10px 12px !important;
        align-items: center !important;
        margin: 0 !important;
        padding: 5px 0 !important;
    }
    .aicli-card-body dl dt {
        color: var(--text-color) !important;
        opacity: 0.8 !important;
        font-weight: 600 !important;
        font-size: 0.85em !important;
        text-align: right !important;
        padding: 0 !important;
        margin: 0 !important;
        line-height: 1.2 !important;
        white-space: nowrap !important;
    }
    .aicli-card-body dl dd {
        margin: 0 !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: center !important;
        gap: 6px !important;
        min-width: 0 !important;
    }

    .input-row {
        display: flex !important;
        align-items: center !important;
        gap: 6px !important;
        width: 100% !important;
        width: 100% !important;
        min-width: 0 !important;
    }

    .aicli-card-body input, .aicli-card-body select {
        background: var(--background-color, #111) !important;
        border: 1px solid var(--border-color, #444) !important;
        color: var(--text-color, #eee) !important;
        border-radius: 3px;
        font-size: 0.95em;
        padding: 4px 10px !important;
        height: 30px !important;
        box-sizing: border-box;
        margin: 0 !important;
        text-align: left !important;
    }
    
    .aicli-btn {
        padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: 700; font-size: 0.95em;
        transition: all 0.15s ease; background: var(--orange, #ff8c00) !important; border: none !important; color: #fff !important;
        text-transform: uppercase; width: 100%; margin-top: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.3);
        display: block; text-align: center;
    }
    .aicli-btn:hover { background: #e67e00 !important; transform: translateY(-1px); box-shadow: 0 6px 14px rgba(0,0,0,0.4); }
    .aicli-btn:active { transform: translateY(0); box-shadow: 0 2px 6px rgba(0,0,0,0.3); }

    .aicli-btn-slim {
        height: 26px !important; padding: 0 10px !important; border-radius: 4px; cursor: pointer;
        background: var(--orange, #ff8c00) !important; border: none !important; color: #fff !important;
        display: inline-flex !important; align-items: center; justify-content: center;
        font-size: 10px !important; font-weight: 800; text-transform: uppercase; gap: 5px;
        flex-shrink: 0 !important; transition: all 0.15s ease; margin: 0 !important;
    }
    .aicli-btn-slim:hover { background: #e67e00 !important; transform: translateY(-1px); box-shadow: 0 3px 8px rgba(0,0,0,0.3); }
    .aicli-btn-slim:active { transform: translateY(0); box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
    .aicli-btn-slim.danger { background: #600 !important; }
    .aicli-btn-slim.danger:hover { background: #800 !important; }
    .aicli-btn-slim.warning { background: #a60 !important; }
    .aicli-btn-slim.warning:hover { background: #c80 !important; }
    .aicli-btn-slim.info { background: #007bff !important; }
    
    .aicli-pill-btn {
        background: var(--mild-background-color, #333); border: 1px solid var(--border-color, #444); color: var(--text-color, #eee); padding: 2px 8px; border-radius: 10px;
        font-size: 9px; cursor: pointer; font-weight: bold; transition: all 0.2s;
    }
    .aicli-pill-btn:hover { background: var(--border-color, #444); border-color: #ff8c00; color: #ff8c00; }

    .stat-icon-btn {
        color: var(--text-color, #888); font-size: 12px; cursor: pointer; transition: all 0.2s;
        display: inline-flex; align-items: center; justify-content: center;
        width: 20px; height: 20px; border-radius: 4px; background: rgba(255,255,255,0.05);
    }
    .stat-icon-btn:hover { color: #ff8c00; background: rgba(255,140,0,0.1); transform: scale(1.1); }
    .stat-icon-btn i { pointer-events: none; }

    /* Storage & Bars */
    .stat-bar-wrap { width: 100%; height: 24px; background: var(--mild-background-color, #222); border-radius: 4px; overflow: hidden; position: relative; border: 1px solid var(--border-color, #333); display: flex; }
    .stat-bar-fill { height: 100%; width: 0%; transition: width 0.5s; }
    .stat-bar-base { height: 100%; background: #1e4976; transition: width 0.5s; position: relative; } /* Dark Blue: Flash */
    .stat-bar-dirty { height: 100%; background: var(--orange, #ff8c00); transition: width 0.5s; position: relative; } /* Orange: RAM Delta */
    .stat-bar-text { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 800; color: #fff; text-shadow: 0 1px 2px #000; z-index: 5; pointer-events: none; }
    
    /* Install Progress Bar (Marketplace) */
    .install-progress { flex: 1; display: none; flex-direction: column; justify-content: center; }
    .install-bar-wrap { width: 100%; height: 12px; background: var(--background-color, #000); border-radius: 6px; overflow: hidden; border: 1px solid var(--border-color, #444); margin-top: 4px; display: block !important; }
    .install-bar-fill { height: 100%; width: 0%; background: var(--orange, #ff8c00); transition: width 0.3s ease; box-shadow: 0 0 10px rgba(255,140,0,0.5); display: block !important; }

    .legend-item { display: inline-flex; align-items: center; gap: 4px; font-size: 9px; opacity: 0.7; }
    .legend-box { width: 8px; height: 8px; border-radius: 2px; }

    /* Storage Entity Grid (matches Marketplace card layout) */
    .storage-entity-grid {
        display: grid !important;
        grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)) !important;
        gap: 16px !important;
        width: 100% !important;
        max-width: 100% !important;
        margin-bottom: 8px;
    }
    .storage-entity-card {
        display: flex; flex-direction: column; padding: 0;
        border: 1px solid var(--border-color, #333); border-radius: 8px;
        background: var(--background-color, #222); overflow: hidden;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        border-bottom-width: 3px; border-bottom-color: #1e4976;
    }
    .storage-entity-card.has-dirty { border-bottom-color: var(--orange, #ff8c00); }
    .storage-entity-card.offline { border-bottom-color: #666; opacity: 0.7; }
    .storage-entity-card .se-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: 10px 12px; gap: 10px;
        background: linear-gradient(to bottom, rgba(128,128,128,0.05), transparent);
        border-bottom: 1px solid var(--border-color, rgba(255,255,255,0.05));
    }
    .storage-entity-card .se-header .se-title { font-weight: bold; font-size: 1em; color: var(--text-color, #eee); }
    .storage-entity-card .se-header .se-meta { font-size: 10px; opacity: 0.6; }
    .storage-entity-card .se-body { padding: 12px; flex: 1; display: flex; flex-direction: column; gap: 8px; }
    .storage-entity-card .se-actions {
        display: flex; align-items: center; justify-content: flex-end; gap: 6px;
        padding: 8px 12px;
        background: var(--title-header-background-color, rgba(0,0,0,0.4));
        border-top: 1px solid var(--border-color, rgba(255,255,255,0.05));
    }
    .storage-entity-card .se-mount-label {
        font-size: 10px; opacity: 0.5; font-family: monospace; word-break: break-all;
        display: flex; align-items: center; gap: 6px; margin-top: 4px;
    }
    .se-layer-list {
        margin-top: 6px; border: 1px solid var(--border-color, rgba(255,255,255,0.08)); border-radius: 4px;
        max-height: 120px; overflow-y: auto; font-size: 9px; font-family: monospace;
    }
    .se-layer-item {
        display: flex; align-items: center; gap: 6px; padding: 3px 8px;
        border-bottom: 1px solid var(--border-color, rgba(255,255,255,0.04));
    }
    .se-layer-item:last-child { border-bottom: none; }
    .se-layer-item i { color: var(--orange, #e68a00); opacity: 0.5; width: 12px; text-align: center; font-size: 10px; }
    .se-layer-path { flex: 1; opacity: 0.6; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .se-layer-size { opacity: 0.4; white-space: nowrap; }
    .storage-empty-state {
        grid-column: 1 / -1; padding: 30px; text-align: center; opacity: 0.5; font-size: 12px;
    }

    /* Marketplace */
    .agent-marketplace-grid {
        display: grid !important; 
        grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)) !important; 
        gap: 20px !important; 
        width: 100% !important; 
        max-width: 100% !important;
    }
    .agent-item { display: flex; flex-direction: column; padding: 0; border: 1px solid var(--border-color, #333); border-radius: 8px; background: var(--background-color, #222); overflow: hidden; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); position: relative; border-bottom-width: 3px; }
    .agent-item.installed { border-bottom-color: #2e7d32; }
    .agent-item.not-installed { border-bottom-color: #444; }
    .agent-item.has-update { border-bottom-color: #ff8c00; }

    .agent-header { display: flex; align-items: center; gap: 12px; padding: 12px; background: linear-gradient(to bottom, rgba(128,128,128,0.05), transparent); border-bottom: 1px solid var(--border-color, rgba(255,255,255,0.05)); }
    .agent-icon { width: 44px !important; height: 44px !important; border-radius: 8px; flex-shrink: 0; box-shadow: 0 2px 6px rgba(0,0,0,0.2); background: var(--title-header-background-color, #333); padding: 4px; object-fit: contain; }
    .agent-name { font-weight: bold; font-size: 1.1em; color: var(--text-color, #eee); }
    .agent-meta { display: flex; gap: 8px; margin-top: 2px; }
    
    .agent-status-badge { font-size: 9px; padding: 2px 6px; border-radius: 4px; font-weight: 800; text-transform: uppercase; display: inline-flex; align-items: center; gap: 4px; }
    .agent-status-badge.installed { background: rgba(46, 125, 50, 0.1); color: #4caf50; border: 1px solid rgba(46, 125, 50, 0.3); }
    .agent-status-badge.update-avail { background: rgba(255, 140, 0, 0.1); color: #ff8c00; border: 1px solid rgba(255, 140, 0, 0.3); }
    .agent-status-badge.not-installed { background: rgba(255, 255, 255, 0.05); color: #888; border: 1px solid rgba(255, 255, 255, 0.1); }

    .agent-description { padding: 12px; font-size: 11px; line-height: 1.5; color: var(--text-color, #aaa); opacity: 0.8; flex: 1; min-height: 44px; }
    
    .agent-filter-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding: 10px; background: rgba(255,255,255,0.02); border-radius: 6px; gap: 20px; }
    .agent-search { position: relative; flex: 1; }
    .agent-search i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); opacity: 0.5; }
    .agent-search input { width: 100%; padding-left: 35px !important; height: 34px !important; background: rgba(0,0,0,0.2) !important; }
    
    .agent-filters { display: flex; gap: 5px; }
    .filter-btn { padding: 6px 15px; border-radius: 4px; font-size: 10px; font-weight: bold; cursor: pointer; background: rgba(255,255,255,0.08); border: 1px solid var(--border-color, rgba(255,255,255,0.15)); transition: all 0.2s; text-transform: uppercase; color: var(--text-color, #ccc); }
    .filter-btn:hover { background: rgba(255,255,255,0.15); border-color: var(--orange, #ff8c00); }
    .filter-btn.active { background: var(--orange, #ff8c00); color: #fff; border-color: var(--orange, #ff8c00); }

    /* Sort toggle — a <button>, so reset the UA font/line-height to inherit
       and match .filter-btn's box model exactly so it sits as a 4th chip. */
    .agent-sort-btn { padding: 6px 15px; border-radius: 4px; font-size: 10px; font-weight: bold; font-family: inherit; line-height: inherit; cursor: pointer; background: rgba(255,255,255,0.08); border: 1px solid var(--border-color, rgba(255,255,255,0.15)); transition: all 0.2s; text-transform: uppercase; color: var(--text-color, #ccc); white-space: nowrap; vertical-align: middle; margin: 0; }
    .agent-sort-btn:hover { background: rgba(255,255,255,0.15); border-color: var(--orange, #ff8c00); }
    .agent-sort-btn i { margin-right: 6px; opacity: 0.85; }

    .config-toggle { padding: 8px 12px; font-size: 10px; font-weight: bold; cursor: pointer; opacity: 0.6; border-top: 1px solid rgba(255,255,255,0.03); display: flex; align-items: center; gap: 8px; transition: opacity 0.2s; }
    .config-toggle:hover { opacity: 1; color: #ff8c00; }
    .agent-config-panel { padding: 12px; background: rgba(0,0,0,0.15); border-top: 1px solid rgba(255,255,255,0.03); display: flex; flex-direction: column; gap: 10px; }
    .agent-config-panel.collapsed { display: none; }
    .config-field { display: flex; justify-content: space-between; align-items: center; }
    .config-field label { font-size: 10px; opacity: 0.7; font-weight: bold; }
    .config-field input, .config-field select { height: 24px !important; font-size: 10px !important; width: 120px !important; }

    .agent-footer { padding: 10px 12px; background: var(--title-header-background-color, rgba(0,0,0,0.4)); border-top: 1px solid var(--border-color, rgba(255,255,255,0.05)); display: flex; align-items: center; justify-content: space-between; min-height: 46px; }

    /* Log Viewer / Debug console.
       INTENTIONALLY ALWAYS DARK — do NOT theme this with var(--background-color)
       etc. It is a terminal surface with green (#0f0) monospace text; on a light
       Unraid theme a theme-aware background turns it into unreadable green-on-white.
       The v2026.05.29.02 theme audit themed these and broke it; keep them hardcoded
       dark so the console stays black regardless of the page theme. */
    .log-terminal { background: #000; border-radius: 4px; border: 1px solid #333; overflow: hidden; display: flex; flex-direction: column; }
    .log-header { background: #1a1a1a; padding: 0 10px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #333; height: 32px; }
    .log-body { height: 400px; overflow-y: auto !important; padding: 10px; font-family: 'Courier New', monospace; font-size: 11px; background: #000; color: #0f0; white-space: pre-wrap; position: relative; overscroll-behavior: contain; }
    .log-tab { padding: 0 12px; cursor: pointer; opacity: 0.7; color: #fff; font-size: 9px; font-weight: bold; text-transform: uppercase; line-height: 32px; border-right: 1px solid #333; transition: all 0.15s; letter-spacing: 0.03em; }
    .log-tab:hover { opacity: 1; background: #2a2a2a; }

    .log-action-btn {
        width: 26px; height: 26px; border-radius: 4px; cursor: pointer;
        background: #333; border: 1px solid #444; color: #ccc;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 11px; transition: all 0.15s;
    }
    .log-action-btn:hover { background: #444; color: #fff; border-color: #666; }
    .log-action-btn:active { transform: scale(0.95); }
    .log-action-btn.danger { color: #f88; }
    .log-action-btn.danger:hover { background: #600; color: #fcc; border-color: #800; }
    .log-tab.active { opacity: 1; background: #333; color: #ff8c00; }

    /* Upgrade "keep a copy" toggle. The backup now defaults OFF (opt-in), so when
       it's UNticked we pulse a soft orange glow in/out like a heartbeat to draw the
       user's eye to the opt-in. The pulse stops the moment they tick it (or when the
       toggle is disabled — insufficient space / bad path — where opting in isn't
       possible). prefers-reduced-motion gets a static outline instead of the animation. */
    @keyframes aicli-bk-heartbeat {
        0%, 100% { box-shadow: 0 0 0 0 rgba(255,140,0,0); }
        50%      { box-shadow: 0 0 7px 3px rgba(255,140,0,0.85); }
    }
    #aicli-bk-toggle { border-radius: 3px; }
    #aicli-bk-toggle:not(:checked):not(:disabled) {
        animation: aicli-bk-heartbeat 1.8s ease-in-out infinite;
        outline: 1px solid rgba(255,140,0,0.7);
        outline-offset: 1px;
    }
    #aicli-bk-toggle:checked, #aicli-bk-toggle:disabled { animation: none; outline: none; }
    @media (prefers-reduced-motion: reduce) {
        #aicli-bk-toggle:not(:checked):not(:disabled) { animation: none; outline: 2px solid rgba(255,140,0,0.85); }
    }
    
    .help-text { font-size: 0.85em; opacity: 0.6; font-style: italic; white-space: normal; text-align: left !important; width: 100%; }

    /* Path Picker Modal (theme-aware, matches WorkspaceBrowser) */
    .pp-backdrop {
        position: fixed; inset: 0; z-index: 2000000;
        display: flex; align-items: center; justify-content: center;
        background: rgba(0,0,0,0.5); backdrop-filter: blur(6px);
    }
    .pp-modal {
        width: 500px; max-height: 80vh; border-radius: 8px; overflow: hidden;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        border: 1px solid var(--border-color, #ccc);
        background: var(--background-color, #fff);
        color: var(--text-color, inherit);
        display: flex; flex-direction: column;
    }
    .pp-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: 8px 14px;
        background: var(--title-header-background-color, var(--mild-background-color, #ededed));
        border-bottom: 1px solid var(--border-color, #ccc);
    }
    .pp-title {
        font-weight: 700; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em;
        display: flex; align-items: center; gap: 8px;
    }
    .pp-body { padding: 12px 14px; flex: 1; overflow: hidden; display: flex; flex-direction: column; }
    .pp-path-bar {
        display: flex; align-items: center; gap: 8px; padding: 6px 10px; margin-bottom: 12px;
        font-size: 12px; font-family: monospace; opacity: 0.65; border-radius: 4px;
        border: 1px solid var(--border-color, #ccc);
        background: var(--mild-background-color, rgba(0,0,0,0.03));
    }
    .pp-dir-list {
        height: 280px; overflow-y: auto; border-radius: 4px;
        border: 1px solid var(--border-color, #ccc);
    }
    .pp-dir-item {
        display: flex; align-items: center; gap: 10px; padding: 8px 12px;
        cursor: pointer; font-size: 13px; transition: background-color 0.15s;
        border-bottom: 1px solid var(--border-color, rgba(0,0,0,0.06));
    }
    .pp-dir-item:hover { background: var(--title-header-background-color, rgba(0,0,0,0.06)); }
    .pp-dir-item.selected {
        background: var(--title-header-background-color, rgba(0,0,0,0.12));
        border-left: 3px solid var(--orange, #e68a00); font-weight: 700;
    }
    .pp-footer {
        display: flex; justify-content: flex-end; gap: 6px; padding: 8px 14px;
        background: var(--title-header-background-color, var(--mild-background-color, #ededed));
        border-top: 1px solid var(--border-color, #ccc);
    }
    .pp-btn-cancel {
        padding: 4px 12px; font-size: 11px; font-weight: 700; text-transform: uppercase;
        background: transparent; border: 1px solid var(--border-color, #ccc);
        border-radius: 3px; color: inherit; cursor: pointer; opacity: 0.7; transition: all 0.15s;
    }
    .pp-btn-cancel:hover { opacity: 1; background: var(--mild-background-color, rgba(0,0,0,0.05)); }
    .pp-btn-confirm {
        padding: 4px 16px; font-size: 11px; font-weight: 900; text-transform: uppercase;
        background: var(--orange, #ff8c00); border: none; border-radius: 3px;
        color: #fff; cursor: pointer; transition: all 0.15s;
        box-shadow: 0 2px 8px rgba(255, 140, 0, 0.4);
    }
    .pp-btn-confirm:hover { background: #e67e00; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(255, 140, 0, 0.5); }
    .pp-btn-confirm:active { transform: translateY(0); box-shadow: 0 1px 4px rgba(255, 140, 0, 0.3); }

    /* =====================================================================
       Agent Card v2 — refined-technical aesthetic. Distinctive display serif
       (Fraunces) for agent names, monospaced values (JetBrains Mono), subtle
       ambient gradients on the grid surface. All loaded via Google Fonts with
       display=swap so the fallback stack paints immediately and the custom
       faces slot in when ready (no FOUT jank, no license concerns).
       Scoped to .av2- prefix so the existing .agent-item block can coexist
       until the rewrite is verified, then removed in a follow-up cleanup.
       Theme-safe: uses Unraid CSS vars with conservative fallbacks.
       ===================================================================== */

    /* Ambient atmosphere behind the agent grid — two oversized soft radial
       gradients in opposite corners create depth without competing with card
       content. Low alpha so both Unraid dark and light themes look intentional. */
    .av2-grid {
        position: relative;
        padding: 2px;
    }
    .av2-grid::before {
        content: ''; position: absolute; inset: -20px; pointer-events: none; z-index: 0;
        background:
            radial-gradient(1000px 500px at 8% -8%, rgba(124,223,255,0.045), transparent 55%),
            radial-gradient(900px 480px at 108% 5%, rgba(255,140,0,0.04), transparent 55%);
    }
    .av2-grid > * { position: relative; z-index: 1; }
    .av2-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(460px, 1fr));
        gap: 18px; width: 100%;
        /* Don't stretch siblings to match the tallest card — when one card
           expands a panel, only that card should grow. Otherwise every card
           in the row gets empty filler space below its foot. */
        align-items: start;
    }
    .av2-card {
        /* overflow: visible so the (i) info tooltip can escape the card bounds
           on hover. The left rail pseudo-element below gets a matching border-
           radius so it doesn't visibly poke past the rounded corners. */
        position: relative; overflow: visible;
        background: var(--background-color, #1b1e25);
        border: 1px solid var(--border-color, #262a33);
        border-radius: 10px;
        min-height: 260px;
        display: flex; flex-direction: column;
        transition: border-color .2s ease, box-shadow .2s ease;
    }
    /* Foot sinks to the bottom so cards in the same row line up regardless of
       which panel is open or whether the card is installed vs not-installed. */
    .av2-card .av2-foot { margin-top: auto; }
    .av2-card::before {
        content: ''; position: absolute; inset: 0 auto 0 0; width: 3px;
        background: var(--border-color, #353a46); transition: background .25s ease;
        border-radius: 10px 0 0 10px;
    }
    .av2-card.state-ready::before       { background: #4ade80; }
    .av2-card.state-warn::before        { background: #f5b041; }
    .av2-card.state-info::before        { background: #60a5fa; }
    /* Not-installed gets a visible-but-muted gray rail — the default
       var(--border-color) often blends into the card border and reads as
       "no rail at all", which loses the signal. */
    .av2-card.state-notinstalled::before{ background: #6b7280; opacity: 0.55; }
    .av2-card:hover { border-color: var(--text-color, rgba(255,255,255,0.25)); }

    .av2-head {
        display: grid; grid-template-columns: 52px 1fr auto; gap: 14px;
        padding: 18px 20px 12px; align-items: start;
    }
    .av2-icon {
        width: 52px; height: 52px; border-radius: 11px;
        /* Uniform near-white pill — many vendor icons are black-on-transparent
           (codex, factory, nanocoder) and disappear against dark card bg without
           a light tile. The pill also normalises branding across agents. */
        background: rgba(255, 255, 255, 0.94);
        display: grid; place-items: center; overflow: hidden;
        border: 1px solid var(--border-color, #262a33);
        padding: 5px; box-sizing: border-box;
        box-shadow: 0 1px 3px rgba(0,0,0,0.12);
    }
    .av2-icon img { max-width: 100%; max-height: 100%; width: auto; height: auto; object-fit: contain; }
    /* Agent name: distinctive display serif. Fraunces's 400-weight optical size
       9 variant lends an editorial/refined tone that separates the agent
       identity from the rest of the mono/sans-styled card content. Letter-
       spacing tightens slightly for display-scale elegance. */
    .av2-title {
        font-family: 'Fraunces', Georgia, 'Times New Roman', serif;
        font-optical-sizing: auto;
        font-size: 22px; font-weight: 400; letter-spacing: -0.015em;
        line-height: 1.1; color: var(--text-color, #e7e9ef);
    }
    .av2-desc {
        margin-top: 6px; font-size: 12.5px; line-height: 1.5;
        color: var(--text-color, #9a9fae); opacity: 0.75; max-width: 48ch;
        /* Hard-cap at 2 lines with ellipsis so long descriptions don't push
           card-head heights out of sync across the grid. Agents with shorter
           copy still get the breathing room of their natural 1-2 lines. */
        display: -webkit-box; -webkit-line-clamp: 2;
        line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    }
    /* Badge: top row is dot + installed version. When an upgrade/downgrade is
       available the arrow indicator stacks on a second row — keeps the badge
       compact horizontally so the description text alongside it has more room.
       Not-installed cards use the second row for the "available" qualifier. */
    .av2-badge {
        justify-self: end; display: inline-flex; flex-direction: column;
        align-items: flex-end; gap: 3px;
        font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Consolas, monospace;
        font-size: 11.5px; padding: 5px 9px; border-radius: 6px;
        background: rgba(127,127,127,0.06);
        border: 1px solid var(--border-color, #262a33);
        color: var(--text-color, #e7e9ef); white-space: nowrap;
        line-height: 1.15;
    }
    .av2-badge .av2-badge-row {
        display: inline-flex; align-items: center; gap: 6px;
    }
    .av2-badge .av2-dot {
        width: 6px; height: 6px; border-radius: 50%;
        background: var(--border-color, #646978);
    }
    .av2-badge .av2-badge-upgrade {
        font-size: 10.5px; color: #60a5fa; font-weight: 500;
    }
    .state-ready .av2-badge .av2-dot        { background: #4ade80; box-shadow: 0 0 8px rgba(74,222,128,0.55); }
    .state-warn  .av2-badge .av2-dot        { background: #f5b041; animation: av2-pulse 1.8s ease-in-out 2; box-shadow: 0 0 8px rgba(245,176,65,0.55); }
    .state-info  .av2-badge .av2-dot        { background: #60a5fa; box-shadow: 0 0 8px rgba(96,165,250,0.55); }
    /* Warn dot pulses twice on page load to draw attention to cards needing
       config — one of those high-impact moments (per frontend-design guidance)
       where a single well-timed motion beats scattered micro-interactions. */
    @keyframes av2-pulse {
        0%,100% { box-shadow: 0 0 0 0 rgba(245,176,65,0.7), 0 0 8px rgba(245,176,65,0.55); }
        50%     { box-shadow: 0 0 0 8px rgba(245,176,65,0),   0 0 8px rgba(245,176,65,0.55); }
    }

    /* Spec strip: 5 chips that open panels below */
    .av2-strip {
        display: flex; flex-wrap: wrap; gap: 2px;
        padding: 0 18px 14px; margin-top: 2px;
    }
    .av2-chip {
        flex: 1 1 0; min-width: 0; display: inline-flex; align-items: center; gap: 7px;
        padding: 7px 9px; cursor: pointer; user-select: none;
        background: transparent; border: 1px solid transparent;
        border-bottom: 1px solid var(--border-color, #262a33);
        color: var(--text-color, #9a9fae); font-size: 11.5px; line-height: 1;
        transition: background .12s ease, color .12s ease, border-color .12s ease, box-shadow .12s ease;
        overflow: hidden;
    }
    .av2-chip:hover { color: var(--text-color, #e7e9ef); background: rgba(127,127,127,0.06); }
    /* Disabled chips (pre-install state on not-installed cards) — dimmed, no
       hover feedback, not focusable. Rendered as <span> so they can't receive
       click events. Only the Channel chip is active pre-install. */
    .av2-chip.disabled {
        cursor: default; opacity: 0.5; pointer-events: none;
    }
    .av2-chip.disabled:hover { background: transparent; color: inherit; }
    /* Active chip fills Unraid brand orange so open state reads like the
       "Check for Updates" button family — instantly obvious which panel
       is in focus, same UX vocabulary across buttons and chips. */
    .av2-chip[aria-expanded="true"] {
        background: var(--orange, #ff8c00); color: #fff;
        border-color: var(--orange, #ff8c00);
        border-top-left-radius: 6px; border-top-right-radius: 6px;
        box-shadow: 0 2px 6px rgba(255,140,0,0.25);
    }
    .av2-chip[aria-expanded="true"] .av2-k,
    .av2-chip[aria-expanded="true"] .av2-v { color: #fff; opacity: 1; }
    .av2-chip[aria-expanded="true"] .av2-v.warn,
    .av2-chip[aria-expanded="true"] .av2-v.ok,
    .av2-chip[aria-expanded="true"] .av2-v.bad {
        color: #fff; border-color: rgba(255,255,255,0.55);
        background: rgba(255,255,255,0.12);
    }
    /* Icon dropped from chips. */
    .av2-chip svg { display: none; }
    /* Single-line label — chips are nav only. State lives inside the panel.
       A small trailing dot indicates "needs config" (warn) / "configured" (ok)
       for at-a-glance signal without crowding the label. */
    .av2-chip .av2-label {
        display: block; text-align: center; font-size: 11px; font-weight: 600;
        text-transform: uppercase; letter-spacing: 0.13em;
        color: var(--text-color, #9a9fae);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .av2-chip {
        justify-content: center;
    }
    .av2-chip[aria-expanded="true"] .av2-label { color: #fff; }
    /* State dot: anchored to the top-right corner of the chip. Doesn't consume
       horizontal space so labels stay centered. */
    .av2-chip.has-warn::after,
    .av2-chip.has-ok::after,
    .av2-chip.has-custom::after {
        content: ''; position: absolute; top: 6px; right: 7px;
        width: 6px; height: 6px; border-radius: 50%;
    }
    .av2-chip.has-warn::after   { background: #f5b041; box-shadow: 0 0 6px rgba(245,176,65,0.6); }
    .av2-chip.has-ok::after     { background: #4ade80; box-shadow: 0 0 6px rgba(74,222,128,0.6); }
    .av2-chip.has-custom::after { background: #7cdfff; box-shadow: 0 0 6px rgba(124,223,255,0.6); }
    .av2-chip[aria-expanded="true"]::after { background: #fff !important; box-shadow: none; }
    /* Ensure the chip can position the state dot */
    .av2-chip { position: relative; }
    /* State-coloured chip values get a subtle pill behind them so "NOT SET" etc
       read as intentional status, not afterthoughts. Transparent bg + coloured
       border keeps contrast on both light and dark themes. */
    .av2-chip .av2-v.warn,
    .av2-chip .av2-v.ok,
    .av2-chip .av2-v.bad {
        padding: 2px 7px; border-radius: 10px; border: 1px solid currentColor;
        background: color-mix(in srgb, currentColor 10%, transparent);
        letter-spacing: 0.04em; font-weight: 600; font-size: 10px;
        text-transform: uppercase;
    }
    .av2-chip .av2-v.warn  { color: #f5b041; }
    .av2-chip .av2-v.ok    { color: #4ade80; }
    .av2-chip .av2-v.bad   { color: #ef4444; }
    .av2-chip .av2-v.muted { color: var(--text-color, #646978); opacity: 0.6; }

    /* Panels */
    .av2-panels {
        background: rgba(0,0,0,0.08); border-top: 1px solid var(--border-color, #262a33);
    }
    .av2-panel {
        display: none; padding: 16px 18px; border-top: 1px solid var(--border-color, #262a33);
    }
    .av2-panel.open {
        display: block;
        animation: av2-reveal .22s ease-out;
    }
    @keyframes av2-reveal {
        from { opacity: 0; transform: translateY(-3px); }
        to   { opacity: 1; transform: none; }
    }
    .av2-panel h4 {
        margin: 0 0 10px;
        font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.18em;
        color: var(--text-color, #646978); opacity: 0.7;
        display: flex; align-items: center; gap: 10px; font-weight: 700;
    }
    .av2-panel h4::after {
        content: ''; flex: 1; height: 1px;
        background: var(--border-color, #262a33);
    }

    /* Form rows — diff-detect visual language */
    .av2-row {
        display: grid; grid-template-columns: 120px 1fr 20px; gap: 8px;
        align-items: center; padding: 0; margin: 0; position: relative;
        min-height: 26px;
    }
    /* Secrets panel uses a wrapper around the control so input + inline help
       stack cleanly inside the middle grid cell. Without this, the help text
       was claiming a separate grid row and pushing the input out of the
       expected column alignment. */
    .av2-row > .av2-row-control {
        display: flex; flex-direction: column; gap: 4px; min-width: 0;
    }
    .av2-row > .av2-row-control > input,
    .av2-row > .av2-row-control > select { width: 100%; }
    .av2-row > .av2-row-control > .av2-help {
        margin-top: 0; font-size: 10.5px; line-height: 1.4;
    }
    /* Auto-save feedback note in the Terminal panel footer. Slots in where the
       manual Save button used to live. Transient "Saved ✓" / "Saving…" states. */
    .av2-save-note {
        font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Consolas, monospace;
        font-size: 10.5px; color: var(--text-color, #646978); opacity: 0.65;
        letter-spacing: 0.05em; margin-right: auto;
    }
    .av2-save-note.ok  { color: #4ade80; opacity: 1; }
    .av2-save-note.bad { color: #ef4444; opacity: 1; }
    .av2-row + .av2-row { margin-top: 1px; }
    .av2-row > label {
        font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Consolas, monospace;
        font-size: 10.5px; letter-spacing: 0.08em; text-transform: uppercase;
        color: var(--text-color, #9a9fae); opacity: 0.8;
        line-height: 1.1;
    }
    /* When a row uses the .av2-row-control stack (input + inline help text below),
       the row's effective height grows past the 24px input. Default align-items:center
       then centres the label against the FULL row — visually BELOW the input, because
       the help text is taller than the label. Anchor such labels to the top of the
       row and nudge down to the 24px input's vertical centre (~7px from top). Rows
       without the control wrapper keep the original centred behaviour, so PROVIDER
       and MODEL rows stay pixel-identical. */
    .av2-row:has(> .av2-row-control) > label {
        align-self: start;
        padding-top: 7px;
    }
    .av2-row::before {
        content: ''; position: absolute; left: -14px; top: 50%; width: 4px; height: 4px;
        border-radius: 50%; transform: translateY(-50%);
        background: transparent; transition: background .15s ease;
    }
    .av2-row.modified::before         { background: #f5b041; box-shadow: 0 0 6px rgba(245,176,65,0.5); }
    .av2-row.modified input,
    .av2-row.modified select          { border-color: #f5b041 !important; }
    .av2-row.modified > label         { color: #f5b041 !important; opacity: 1; }

    .av2-row input, .av2-row select {
        width: 100%; box-sizing: border-box; height: 24px; line-height: 22px;
        background: var(--background-color, #0f1013);
        color: var(--text-color, #e7e9ef);
        border: 1px solid var(--border-color, #262a33); border-radius: 4px;
        padding: 0 8px; margin: 0;
        font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Consolas, monospace;
        font-size: 11px;
    }
    /* Selects get an explicit chevron — default browser caret disappears when
       the select is reset into input-like styling, making the field read as an
       empty text input (observed for GOOSE_PROVIDER in the Secrets panel). */
    .av2-row select {
        appearance: none; -webkit-appearance: none; -moz-appearance: none;
        padding-right: 28px;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239a9fae' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'></polyline></svg>");
        background-repeat: no-repeat;
        background-position: right 10px center;
    }
    .av2-row input:focus, .av2-row select:focus {
        outline: none; border-color: var(--orange, #ff8c00);
    }

    .av2-reset-btn {
        opacity: 0; padding: 4px 6px; background: transparent; border: none;
        color: var(--text-color, #9a9fae); font-size: 13px; cursor: pointer;
        border-radius: 4px; transition: opacity .15s ease, background .15s ease;
    }
    /* Info (i) icon — replaces the per-row revert button. Hover surfaces a
       CSS-driven tooltip via the data-tip attribute. We don't rely on the
       native title tooltip because it's subject to OS delay and can be
       intercepted by legacy Unraid tooltip plugins. Muted circle that reads
       as help without competing with the field for attention. */
    .av2-info {
        position: relative;
        display: inline-flex; align-items: center; justify-content: center;
        width: 16px; height: 16px; border-radius: 50%; cursor: help;
        font-family: 'Fraunces', Georgia, serif;
        font-size: 10px; font-style: italic; font-weight: 500;
        color: var(--text-color, #9a9fae);
        border: 1px solid rgba(127,127,127,0.35);
        background: transparent;
        opacity: 0.55; transition: opacity .15s ease, border-color .15s ease, color .15s ease;
        line-height: 1; user-select: none;
    }
    .av2-info:hover, .av2-info:focus-visible {
        opacity: 1; border-color: var(--orange, #ff8c00); color: var(--orange, #ff8c00);
        outline: none;
    }
    /* Tooltip body: absolutely positioned above the icon, right-aligned so it
       stays within the card's right edge. Pointer triangle below. */
    .av2-info[data-tip]::after {
        content: attr(data-tip);
        position: absolute; bottom: calc(100% + 8px); right: -4px;
        max-width: 280px; width: max-content;
        padding: 7px 10px; border-radius: 6px;
        background: #0d0e12; color: #e7e9ef;
        border: 1px solid rgba(255,140,0,0.45);
        font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Consolas, monospace;
        font-size: 10.5px; font-style: normal; font-weight: 400;
        letter-spacing: 0.01em; line-height: 1.5;
        text-transform: none;
        white-space: normal; text-align: left;
        box-shadow: 0 6px 18px rgba(0,0,0,0.45);
        opacity: 0; transform: translateY(4px);
        pointer-events: none; z-index: 40;
        transition: opacity .12s ease, transform .12s ease;
    }
    .av2-info[data-tip]::before {
        content: ''; position: absolute; bottom: calc(100% + 2px); right: 4px;
        border: 6px solid transparent;
        border-top-color: rgba(255,140,0,0.55);
        opacity: 0; transition: opacity .12s ease;
        pointer-events: none; z-index: 41;
    }
    .av2-info:hover::after, .av2-info:focus-visible::after,
    .av2-info:hover::before, .av2-info:focus-visible::before {
        opacity: 1; transform: translateY(0);
    }
    .av2-row.modified .av2-reset-btn { opacity: 0.7; }
    .av2-row.modified .av2-reset-btn:hover {
        opacity: 1; background: rgba(127,127,127,0.08);
    }

    /* Panel footer */
    .av2-panel-footer {
        display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px;
    }
    .av2-help {
        font-size: 11px; color: var(--text-color, #9a9fae); opacity: 0.6;
        margin-top: 10px; font-style: italic; line-height: 1.5;
    }
    .av2-help code {
        font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Consolas, monospace;
        background: rgba(127,127,127,0.08); padding: 1px 4px; border-radius: 3px;
        font-size: 10.5px; font-style: normal;
    }

    /* ---------- Auto-launch panel (Terminal chip subsection) ----------
       Per-workspace arm/disarm. Mirrors the .av2-row diff-detect language:
       a 4px state dot on the left edge, mono-letterspaced eyebrow heading,
       green when armed (parallels the orange .modified dot used above). */
    .av2-al-section {
        margin-top: 14px; padding-top: 12px;
        border-top: 1px solid var(--border-color, #262a33);
    }
    .av2-al-eyebrow {
        font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Consolas, monospace;
        font-size: 10.5px; letter-spacing: 0.08em; text-transform: uppercase;
        color: var(--text-color, #9a9fae); opacity: 0.85;
        margin: 0 0 4px;
    }
    .av2-al-caption {
        font-size: 11px; line-height: 1.45;
        color: var(--text-color, #9a9fae); opacity: 0.7;
        margin: 0 0 10px; font-style: normal;
    }
    .av2-al-row {
        position: relative;
        display: grid; grid-template-columns: 1fr auto; align-items: center;
        gap: 10px; padding: 8px 10px 8px 16px;
        border: 1px solid var(--border-color, #262a33);
        border-radius: 4px;
        background: rgba(127,127,127,0.03);
        transition: border-color .15s ease, background .15s ease;
    }
    .av2-al-row + .av2-al-row { margin-top: 6px; }
    .av2-al-row:hover { border-color: rgba(127,127,127,0.4); }
    .av2-al-row::before {
        content: ''; position: absolute; left: 6px; top: 14px;
        width: 4px; height: 4px; border-radius: 50%;
        background: rgba(127,127,127,0.35);
        transition: background .15s ease, box-shadow .15s ease;
    }
    .av2-al-row.armed {
        border-color: rgba(74,222,128,0.35);
        background: rgba(74,222,128,0.04);
    }
    .av2-al-row.armed::before {
        background: #4ade80; box-shadow: 0 0 6px rgba(74,222,128,0.6);
    }
    .av2-al-id { display: flex; flex-direction: column; min-width: 0; }
    .av2-al-name {
        font-size: 12px; font-weight: 600; color: var(--text-color, #e7e9ef);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        line-height: 1.25;
    }
    .av2-al-row.armed .av2-al-name { color: #4ade80; }
    .av2-al-path {
        font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Consolas, monospace;
        font-size: 10px; color: var(--text-color, #9a9fae); opacity: 0.55;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        margin-top: 2px;
    }
    .av2-al-toggle {
        display: inline-flex; align-items: center; gap: 6px; cursor: pointer;
        font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Consolas, monospace;
        font-size: 10px; letter-spacing: 0.08em; text-transform: uppercase;
        color: var(--text-color, #9a9fae); opacity: 0.7;
        user-select: none; margin: 0;
    }
    .av2-al-row.armed .av2-al-toggle { color: #4ade80; opacity: 1; }
    .av2-al-toggle input[type=checkbox] { margin: 0; cursor: pointer; }

    .av2-al-fresh {
        display: none;
        grid-column: 1 / -1;
        margin-top: 8px; padding-top: 8px;
        border-top: 1px dashed rgba(127,127,127,0.18);
        align-items: center; gap: 6px;
        font-size: 11px; line-height: 1.3;
        color: var(--text-color, #9a9fae); opacity: 0.85;
    }
    .av2-al-row.armed .av2-al-fresh { display: flex; }
    .av2-al-fresh input[type=checkbox] { margin: 0; cursor: pointer; }
    .av2-al-fresh label { cursor: pointer; margin: 0; font-weight: normal; }

    /* Buttons shared across panels */
    .av2-btn {
        font-size: 11.5px; font-weight: 600; padding: 6px 12px; border-radius: 6px;
        cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        border: 1px solid var(--border-color, #353a46);
        background: var(--background-color, #16181d); color: var(--text-color, #e7e9ef);
        transition: all .15s ease;
        line-height: 1; /* kill inherited line-height so text centers vertically */
    }
    .av2-btn:hover { border-color: var(--orange, #ff8c00); color: var(--orange, #ff8c00); }
    .av2-btn.primary {
        background: var(--orange, #ff8c00); color: #fff; border-color: transparent;
    }
    .av2-btn.primary:hover { background: #ffa433; color: #fff; }
    .av2-btn.ghost { background: transparent; border-color: transparent; opacity: 0.7; }
    .av2-btn.ghost:hover { opacity: 1; color: var(--text-color, #e7e9ef); }

    /* WP #736 — free-form Variables / Secrets sub-sections in the ENVS panel. */
    .av2-ff-block { margin-top: 14px; padding-top: 12px; border-top: 1px solid rgba(128,128,128,0.18); }
    .av2-ff-block h4 { margin: 0 0 8px; font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.06em; opacity: 0.85; }
    .av2-ff-hint { display: block; margin-top: 2px; font-size: 10px; font-weight: 400; text-transform: none; letter-spacing: 0; opacity: 0.55; }
    .av2-ff-list { display: flex; flex-direction: column; gap: 6px; }
    .av2-ff-row { display: flex; align-items: center; gap: 6px; }
    .av2-ff-row .av2-ff-name { flex: 0 0 38%; }
    .av2-ff-row .av2-ff-val  { flex: 1 1 auto; }
    .av2-ff-row input {
        font-size: 12px; padding: 5px 8px; border-radius: 4px;
        border: 1px solid var(--border-color, rgba(128,128,128,0.35));
        background: var(--input-bg-color, rgba(255,255,255,0.03)); color: inherit;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    }
    .av2-ff-row input:focus { outline: none; border-color: var(--orange, #ff8c00); }
    .av2-ff-eq { opacity: 0.5; font-size: 12px; }
    .av2-ff-del {
        flex: 0 0 auto; background: transparent; border: none; cursor: pointer;
        color: var(--text-color, #e7e9ef); opacity: 0.45; font-size: 12px; padding: 4px 6px;
    }
    .av2-ff-del:hover { opacity: 1; color: var(--bad, #d65b5b); }
    .av2-ff-empty { font-size: 11px; opacity: 0.5; padding: 4px 2px; }
    /* Uninstall / Reset — bordered-red button. Idle state shows a clearly
       defined red border and soft red-tinted background so the button reads
       as a real interactive element, not a text link. Hover fills solid.
       Works against both Unraid light and dark themes because --bad is a
       brand token. The danger-ghost variant (transparent at idle) was too
       ambiguous — removed. */
    .av2-btn.danger {
        background: rgba(239,68,68,0.08); color: #ef4444;
        border-color: rgba(239,68,68,0.55); font-weight: 600;
    }
    .av2-btn.danger:hover {
        background: #ef4444; color: #fff; border-color: #ef4444;
        box-shadow: 0 2px 8px rgba(239,68,68,0.35);
    }
    /* WP #748 J / Phase B follow-up (b): contextual Repair button in the foot.
       Amber so it's visually distinct from the orange Upgrade (primary) and the
       red Uninstall (danger). Same outlined-on-rest, filled-on-hover treatment. */
    .av2-btn.warn {
        background: rgba(230,126,34,0.08); color: #e67e22;
        border-color: rgba(230,126,34,0.55); font-weight: 600;
    }
    .av2-btn.warn:hover {
        background: #e67e22; color: #fff; border-color: #e67e22;
        box-shadow: 0 2px 8px rgba(230,126,34,0.35);
    }

    /* Footer row: install progress + install/uninstall actions */
    .av2-foot {
        padding: 12px 18px; display: flex; justify-content: space-between; align-items: center;
        border-top: 1px solid var(--border-color, #262a33);
        background: linear-gradient(to bottom, transparent, rgba(0,0,0,0.1));
    }
    .av2-foot .av2-meta {
        font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Consolas, monospace;
        font-size: 10.5px; color: var(--text-color, #646978); opacity: 0.6;
    }
    .av2-foot .av2-actions { display: flex; gap: 8px; }

    /* Channel panel — segmented control with inset shadow + sharper active pill */
    .av2-seg {
        display: inline-grid; grid-auto-flow: column; grid-auto-columns: 1fr;
        width: 100%; max-width: 360px;
        background: var(--background-color, #0f1013);
        border: 1px solid var(--border-color, #262a33); border-radius: 7px;
        padding: 3px; gap: 2px;
        font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 11.5px;
    }
    .av2-seg input { display: none; }
    .av2-seg label {
        text-align: center; padding: 6px 10px; cursor: pointer; border-radius: 5px;
        color: var(--text-color, #9a9fae); opacity: 0.7;
        transition: background .12s ease, color .12s ease, opacity .12s ease;
        letter-spacing: 0.04em; font-weight: 600;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .av2-seg label:hover { opacity: 1; background: rgba(127,127,127,0.08); }
    .av2-seg input:checked + label {
        background: var(--orange, #ff8c00); color: #fff; opacity: 1;
        box-shadow: 0 1px 2px rgba(0,0,0,0.25), inset 0 -1px 0 rgba(0,0,0,0.15);
    }

    /* Stacked section inside the Channel panel — label (h4) above the control,
       matching the Release Channel header/seg-control pattern above. */
    .av2-chan-section { margin-top: 16px; }
    .av2-chan-section > h4 { margin-bottom: 8px; }
    .av2-chan-select {
        width: 100%; box-sizing: border-box; height: 32px;
        background: var(--background-color, #0f1013);
        color: var(--text-color, #e7e9ef);
        border: 1px solid var(--border-color, #262a33); border-radius: 6px;
        padding: 0 10px;
        font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Consolas, monospace;
        font-size: 12px;
        appearance: none; -webkit-appearance: none; -moz-appearance: none;
        padding-right: 30px;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239a9fae' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'></polyline></svg>");
        background-repeat: no-repeat;
        background-position: right 10px center;
    }
    .av2-chan-select:focus { outline: none; border-color: var(--orange, #ff8c00); }

    /* Channel stats — card-in-card treatment so the stats read as a result panel
       distinct from the control above it. */
    .av2-chan-stat {
        display: grid; grid-template-columns: auto 1fr; gap: 6px 14px; align-items: center;
        margin: 12px 0 0; padding: 10px 12px;
        font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 12px;
        background: rgba(127,127,127,0.04);
        border: 1px solid var(--border-color, #262a33); border-radius: 6px;
    }
    .av2-chan-stat dt {
        font-size: 9.5px; letter-spacing: 0.12em; text-transform: uppercase;
        color: var(--text-color, #646978); opacity: 0.7;
    }
    .av2-chan-stat dd { margin: 0; color: var(--text-color, #e7e9ef); }

    /* Install progress — full-width panel in the card body area (not squished
       into the footer actions row). When active, the chip strip and panels
       are hidden and this takes over the space between head and foot. */
    .av2-install-panel {
        display: none; padding: 18px 20px;
        border-top: 1px solid var(--border-color, #262a33);
        background: rgba(255,140,0,0.04);
        flex-direction: column; gap: 10px;
    }
    .av2-install-panel.active { display: flex; }
    .av2-install-panel .av2-install-status {
        font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Consolas, monospace;
        font-size: 11.5px; color: var(--text-color, #e7e9ef);
        letter-spacing: 0.02em; line-height: 1.3;
    }
    .av2-install-bar {
        height: 6px; background: var(--border-color, #262a33); border-radius: 4px; overflow: hidden;
    }
    .av2-install-bar span {
        display: block; height: 100%;
        background: linear-gradient(90deg, var(--orange, #ff8c00), #ffa433);
        transition: width .25s ease;
        box-shadow: 0 0 8px rgba(255,140,0,0.35);
    }
    /* When the install panel is active, collapse the chip strip and panels so
       the progress has the full body region. */
    .av2-card:has(.av2-install-panel.active) .av2-strip,
    .av2-card:has(.av2-install-panel.active) .av2-panels { display: none; }
    /* ------------------------------------------------------------------------
       Mobile responsive overrides (≤ 600 px viewport — phone portrait + most
       phone landscape). Targets the three surfaces that overflowed in the
       2026-05-13 mobile shots: the Settings sub-tab strip (Configuration /
       Agent Store / Home Storage / Debug Console), the Agent Store cards
       (icon-title-badge head grid + 5-chip strip + foot meta+buttons), and
       the version badge stack (badge truncating because column 3 of the head
       grid got pushed off-screen). See docs/specs/MOBILE_RESPONSIVE.md.
       ------------------------------------------------------------------------ */
    @media (max-width: 600px) {
        /* ROOT CAUSE of the 2026-05-14 card-overhang report: the Agent Store
           grid is `repeat(auto-fill, minmax(460px, 1fr))` and the Config grid
           is `repeat(auto-fill, minmax(400px, 1fr))` — at any sub-460 px / sub-
           400 px viewport (every phone) the grid track is wider than the
           viewport and the cards bleed off the right edge. Collapse to a
           single-column grid on mobile so each card fills the viewport width
           minus the page's natural padding, and belt-and-brace each card with
           max-width:100% + min-width:0 so a child can never re-introduce
           overflow. */
        .av2-grid,
        .aicli-config-grid {
            grid-template-columns: minmax(0, 1fr) !important;
            gap: 12px !important;
        }
        .av2-card,
        .aicli-card {
            max-width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }
        /* The state-stripe ::before bar (left edge of every av2-card) is 4 px
           on desktop — shrink to 3 px on mobile so it doesn't steal width
           from the head grid's middle column. */
        .av2-card::before { width: 3px !important; }

        /* Tab strip — horizontal scroll instead of overflow-clip. -webkit-
           overflow-scrolling for momentum on iOS Safari. flex-wrap:nowrap is
           explicit to override any framework default; the tab buttons keep
           their natural width and the user swipes to reach the rightmost ones. */
        .aicli-tabs {
            flex-wrap: nowrap;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            padding-left: 6px; padding-right: 6px;
        }
        .aicli-tab-btn {
            padding: 8px 14px; font-size: 10px;
            flex: 0 0 auto; letter-spacing: 0.04em;
        }

        /* Agent card — keep the desktop [icon | title-desc | badge top-right]
           grid; just shrink each column so it fits at mobile width. Title +
           desc get smaller fonts and the title clamps to one line; the badge
           stays in column 3 with tighter padding and smaller font so it can't
           push off-screen. minmax(0, 1fr) on the middle column lets the
           title/desc shrink-to-fit instead of forcing the badge column to
           wrap. */
        .av2-head {
            grid-template-columns: 40px minmax(0, 1fr) auto;
            gap: 8px;
            padding: 12px 12px 10px;
        }
        .av2-icon {
            width: 40px; height: 40px; border-radius: 8px; padding: 3px;
        }
        .av2-title {
            font-size: 15px; line-height: 1.15;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .av2-desc {
            font-size: 11px; line-height: 1.4; max-width: 100%;
            -webkit-line-clamp: 2; line-clamp: 2;
        }
        .av2-badge {
            padding: 4px 7px; font-size: 10px;
            gap: 2px;
        }
        .av2-badge .av2-badge-row { gap: 5px; font-size: 10px; }
        .av2-badge .av2-badge-upgrade { font-size: 9.5px; }
        .av2-badge .av2-dot { width: 5px; height: 5px; }
        .av2-health-pill {
            /* WP #748 J Phase B pill — when the stacked badge surfaces a
               non-healthy state alongside the version, keep its little chip
               compact so the badge column doesn't grow. */
            font-size: 8px !important;
            padding: 1px 5px !important;
            margin-top: 2px !important;
        }

        /* Chip strip — fit all 5 in a single row at mobile. With 5 chips at
           flex: 1 1 0 the row distributes available width evenly; the labels
           shrink to fit via overflow:hidden + ellipsis (chips were already
           overflow:hidden on desktop). Tighter padding + smaller letter-
           spacing keeps "TERMINAL" / "RESOURCES" readable instead of wrapping
           ARGS onto its own row like before. */
        .av2-strip {
            padding: 0 10px 10px;
            gap: 2px;
            flex-wrap: nowrap;
        }
        .av2-chip {
            flex: 1 1 0; min-width: 0;
            padding: 7px 3px;
        }
        .av2-chip .av2-label {
            font-size: 9.5px; letter-spacing: 0.04em;
        }

        /* Foot — stack the meta line over the action buttons. Buttons take the
           full row and share width 50/50 (or 33/33/33 when Repair/Clear-halt
           are surfaced under Phase B). Stops UNINSTALL being clipped right. */
        .av2-foot {
            flex-direction: column; align-items: stretch; gap: 10px;
            padding: 12px 14px;
        }
        .av2-foot .av2-meta {
            font-size: 10px; line-height: 1.45; word-break: break-all;
            white-space: normal;
        }
        .av2-foot .av2-actions { width: 100%; }
        .av2-foot .av2-buttons {
            width: 100%; flex-wrap: wrap !important;
        }
        .av2-foot .av2-buttons .av2-btn {
            flex: 1 1 calc(50% - 4px); min-width: 0;
            padding: 8px 6px; font-size: 11px;
        }
    }
</style>
