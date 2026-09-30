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
        /* 2026-09-30 (NATIVE_BUTTON_STYLE.md "Contrast in every theme"): the AA
           text token and 0.8 opacity. Unraid's text at 0.7 was 2.6:1 on azure
           and 2.36:1 on gray; now 5.7:1 or more in every theme. */
        padding: 10px 25px; background: var(--title-header-background-color, #222); color: var(--aicli-text, var(--text-color, #888));
        border-radius: 6px 6px 0 0; opacity: 0.8;
        cursor: pointer; font-weight: 800; font-size: 11px; text-transform: uppercase;
        border: 1px solid var(--border-color, #333); border-bottom: none; 
        transition: all 0.2s; position: relative; bottom: -1px;
        letter-spacing: 0.05em;
    }
    .aicli-tab-btn:hover { opacity: 1; color: var(--aicli-text, var(--text-color, #eee)); }
    /* WP #903 a11y: dark ink on the brand orange — white-on-#ff8c00 is 2.33:1
       (fails WCAG AA 4.5:1); #111 on #ff8c00 is ~8:1. Applies to every
       orange-filled control (active tab, slim buttons, active filter chip). */
    .aicli-tab-btn.active {
        background: var(--orange, #ff8c00); color: #111; border-color: var(--orange, #ff8c00); opacity: 1;
        box-shadow: 0 -4px 10px rgba(255,140,0,0.2);
        z-index: 2;
    }
    
    .aicli-tab-content { display: none !important; width: 100% !important; }
    .aicli-tab-content.active { display: flex !important; flex-direction: column !important; }

    .aicli-layout { gap: 20px !important; width: 100% !important; }
    .aicli-cards { width: 100%; display: flex; flex-direction: column; }
    /* Settings cards are laid out like the Unraid dashboard: a script
       (ManagerConfigTab.php, aicliConfigColumns) puts each card into the
       shortest of N equal columns, N from the page width, so every card is
       only as tall as its content and the full width is used. Before the
       script runs, and on a narrow screen, the cards stack in one column.
       (Operator requests 2026-09-12 and 2026-09-13; CSS multi-column
       balancing left half the page empty.) */
    .aicli-config-grid {
        display: block !important;
        width: 100% !important;
        max-width: 100% !important;
    }
    .aicli-config-grid > .aicli-card { margin: 0 0 20px 0; }
    .aicli-config-grid.aicli-config-grid--cols {
        display: flex !important;
        flex-direction: row !important;
        align-items: flex-start !important;
        gap: 20px !important;
    }
    .aicli-config-col {
        flex: 1 1 0;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }
    .aicli-config-col > .aicli-card { margin: 0; }
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
        /* The label column takes at most 38% of the card, so a long label such
           as "Voice (all devices)" wraps instead of pushing the field to the
           right edge (2026-09-13). */
        grid-template-columns: fit-content(38%) 1fr !important;
        gap: 10px 12px !important;
        /* The label lines up with the entry box on the first line of its
           row, not with the middle of the box plus its help text. */
        align-items: start !important;
        margin: 0 !important;
        padding: 5px 0 !important;
    }
    .aicli-card-body dl dt {
        color: var(--text-color) !important;
        opacity: 0.8 !important;
        font-weight: 600 !important;
        font-size: 0.85em !important;
        text-align: right !important;
        /* Centre the label on a 28 px entry box or button. */
        padding: 7px 0 0 0 !important;
        margin: 0 !important;
        line-height: 1.2 !important;
        white-space: normal !important;
    }
    /* Unraid 7.3 base CSS hides any EMPTY dt/dd inside .content (default-base.css,
       "Force remove any small empty space elements"). In a grid list that drops a
       cell and swaps every column after it (the Agent voice card, 2026-09-13).
       A label-less row therefore keeps its cell: the markup uses a non-breaking
       space, and this rule is the guard for any empty label that slips through. */
    .aicli-card-body dl dt:empty {
        display: block !important;
        margin: 0 !important;
        padding: 7px 0 0 0 !important;
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
    
    /* NATIVE_BUTTON_STYLE.md (2026-09-29): .aicli-btn and .aicli-btn-slim
       only set their layout here. Unraid's own button look (colour, frame,
       font, hover, disabled) comes from the "Native Unraid buttons" section
       after the WP #903 touch-target section below. */
    .aicli-btn {
        cursor: pointer; width: 100%;
        display: flex; align-items: center; justify-content: center; gap: 6px;
        text-align: center;
    }

    .aicli-btn-slim {
        cursor: pointer;
        display: inline-flex !important; align-items: center; justify-content: center; gap: 5px;
        flex-shrink: 0 !important; margin: 0 !important;
    }

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
    /* HOME_STORAGE_CARD_JOB_STATE.md (#247): a home with a queued/running/deferred
       supervisor job — the bar carries a moving stripe and the label names the job;
       the action icons are locked until the job ends. */
    .stat-bar-wrap.stat-bar-busy .stat-bar-dirty, .stat-bar-wrap.stat-bar-busy .stat-bar-base { opacity: 0.25; }
    .stat-bar-wrap.stat-bar-busy { opacity: 1 !important; } /* a job on an offline home must still read */
    .stat-bar-wrap.stat-bar-busy::after { content: ""; position: absolute; inset: 0;
        background: repeating-linear-gradient(45deg, rgba(255,140,0,0.8) 0 10px, rgba(255,140,0,0.25) 10px 20px);
        background-size: 28px 28px; animation: aicli-bar-busy 0.9s linear infinite; pointer-events: none; }
    .stat-bar-wrap.stat-bar-busy.stat-bar-deferred::after { animation-duration: 2.4s; opacity: 0.6; }
    @keyframes aicli-bar-busy { from { background-position: 0 0; } to { background-position: 28px 0; } }
    @media (prefers-reduced-motion: reduce) { .stat-bar-wrap.stat-bar-busy::after { animation: none; } }
    .stat-icon-btn.aicli-locked { opacity: 0.3; cursor: default; pointer-events: none; }
    .stat-bar-wrap.stat-bar-busy .stat-bar-text { position: relative; z-index: 1; }
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
    
    /* The All / Installed / Updates filter is a group of toggle buttons
       (aria-pressed). Its look is the "Native Unraid buttons" section below:
       the Unraid tab style, the pressed one with an orange frame, no fill. */
    .agent-filters { display: flex; gap: 5px; }

    /* Sort toggle — an action button with Unraid's own look (see the
       "Native Unraid buttons" section below). */
    .agent-sort-btn { white-space: nowrap; vertical-align: middle; margin: 0; }
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
    .log-header { background: #1a1a1a; padding: 4px 8px; display: flex; align-items: center; gap: 6px 12px; border-bottom: 1px solid #333; min-height: 36px; box-sizing: border-box; position: relative; }
    .log-body { height: 400px; overflow-y: auto !important; padding: 10px; font-family: 'Courier New', monospace; font-size: 11px; background: #000; color: #0f0; white-space: pre-wrap; position: relative; overscroll-behavior: contain; }
    .log-tab { padding: 0 12px; cursor: pointer; opacity: 0.7; color: #fff; font-size: 9px; font-weight: bold; text-transform: uppercase; line-height: 32px; border-right: 1px solid #333; transition: all 0.15s; letter-spacing: 0.03em; }
    .log-tab:hover { opacity: 1; background: #2a2a2a; }

    .log-action-btn {
        /* Auto-width text+icon button. Was a fixed 26x26 icon square, which made
           the labelled buttons (Reset, Download support bundle, Create GitHub
           issue, …) overflow their box and overlap each other in the Debug
           Console support row — every use of this class has a text label. */
        min-height: 26px; padding: 4px 10px; border-radius: 4px; cursor: pointer;
        background: #333; border: 1px solid #444; color: #ccc;
        display: inline-flex; align-items: center; justify-content: center; gap: 5px;
        font-size: 11px; line-height: 1; white-space: nowrap; transition: all 0.15s;
    }
    .log-action-btn:hover { background: #444; color: #fff; border-color: #666; }
    .log-action-btn:active { transform: scale(0.95); }
    .log-action-btn.danger { color: #f88; }
    .log-action-btn.danger:hover { background: #600; color: #fcc; border-color: #800; }
    /* Debug Console filter + support rows. The console is ALWAYS a dark terminal
       (.log-terminal/.log-body bg #000), so its text needs a FIXED light tone like
       .log-tab (#fff) and .log-action-btn (#ccc). The earlier var(--text-color)
       attempt was WRONG: on light Unraid themes (azure/white) --text-color is DARK,
       so these labels/selects/values rendered unreadable dark-on-black. */
    #log-filter-row, #log-filter-row label, #log-filter-row select, #log-filter-row input,
    #diag-support-row, #diag-support-row label, #diag-known-issues {
        color: #ccc;
    }
    #log-filter-row select, #log-filter-row input {
        background: #1a1a1a; border: 1px solid #444; border-radius: 3px; padding: 2px 4px;
    }
    #log-filter-row input::placeholder { color: #777; }
    .log-tab.active { opacity: 1; background: #333; color: #ff8c00; }
    /* DEBUG CONSOLE HEADER (2026-09-29): one compact row on a desktop.
       [Debug|Migration|Install|Uninstall]  Context [..] Level [..] Trace [..] Tail [..] (reset)
       ... (paused) (copy) (clear) [Support v]. It was three rows, 146 px tall; now
       36 px, and the log (#log-content) gets the height. The console stays dark:
       every colour here is a fixed hex. Unraid paints each <button type="button">
       (default-base.css: a 30 px frame, a 86 px minimum width, a 10px 12px margin);
       these rules keep that native paint and only make it compact (1,1,0 beats
       Unraid's button[type=button] 0,1,1). Phone rules: the PHONE (2026-09-29)
       section at the end of this file. Spec: docs/specs/NATIVE_BUTTON_STYLE.md. */
    #tab-debug .log-terminal { container: aicli-log / inline-size; }
    #tab-debug .log-header { flex-wrap: wrap; }
    #tab-debug .log-tabs { display: flex; align-items: stretch; border: 1px solid #333; border-radius: 4px; overflow: hidden; flex: 0 0 auto; }
    #tab-debug .log-tab { line-height: 26px; padding: 0 10px; }
    #tab-debug .log-tabs .log-tab:last-child { border-right: 0; }
    #log-filter-row { display: flex; align-items: center; gap: 4px 10px; flex: 0 0 auto; }
    #log-filter-row label { display: flex; align-items: center; gap: 4px; margin: 0; font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.03em; color: #999; white-space: nowrap; }
    #tab-debug #log-filter-row :is(select, input[type="text"]) {
        height: 26px; min-height: 0; margin: 0; padding: 0 4px; font-size: 11px; font-weight: normal;
        text-transform: none; letter-spacing: normal; line-height: 24px; box-sizing: border-box; min-width: 0;
    }
    #tab-debug #log-filter-ctx { width: 120px; }
    #tab-debug #log-filter-level { width: 76px; }
    #tab-debug #log-filter-trace { width: 72px; }
    #tab-debug #log-filter-tail { width: 58px; }
    #tab-debug .log-actions { display: flex; align-items: center; gap: 6px; margin-left: auto; flex: 0 0 auto; }
    #tab-debug .log-paused { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: bold; text-transform: uppercase; color: #0f0; white-space: nowrap; margin-right: 2px; }
    #tab-debug .log-paused-dot { width: 7px; height: 7px; border-radius: 50%; background: #0f0; }
    #tab-debug .log-action-btn { margin: 0; min-width: 0; height: 26px; min-height: 0; padding: 0 10px; box-sizing: border-box; line-height: 1; }
    #tab-debug .log-icon-btn { width: 28px; padding: 0; font-size: 12px; letter-spacing: 0; }
    #tab-debug .log-action-btn:focus-visible { outline: 2px solid #ff8c00; outline-offset: 1px; }
    #tab-debug .log-menu-wrap { position: relative; display: flex; }
    #tab-debug .log-menu-btn .fa-caret-down { margin-left: 2px; }
    #tab-debug .log-menu {
        position: absolute; top: calc(100% + 4px); right: 0; z-index: 30; min-width: 230px;
        display: flex; flex-direction: column; padding: 4px 0; background: #1a1a1a;
        border: 1px solid #444; border-radius: 4px; box-shadow: 0 6px 18px rgba(0,0,0,0.6);
    }
    #tab-debug .log-menu[hidden] { display: none; }
    #tab-debug .log-menu .log-menu-item {
        display: flex; justify-content: flex-start; gap: 8px; width: 100%; height: auto; min-height: 32px; padding: 0 12px;
        background: none; border: 0; border-radius: 0; color: #ddd; font-family: inherit; font-size: 12px;
        font-weight: normal; text-transform: none; letter-spacing: normal; text-align: left;
    }
    #tab-debug .log-menu .log-menu-item:is(:hover, :focus-visible) { background: #333; color: #fff; outline: none; }
    #tab-debug .log-menu .log-menu-item i { width: 14px; text-align: center; color: #ff8c00; }
    #tab-debug .log-menu-sep { height: 1px; margin: 4px 0; background: #333; }
    #tab-debug .log-menu-check { display: flex; align-items: center; gap: 8px; min-height: 32px; padding: 0 12px; margin: 0; font-size: 12px; color: #ccc; cursor: pointer; white-space: nowrap; }
    #tab-debug .log-menu-check input { margin: 0; }
    #tab-debug .log-menu-check:has(input:focus-visible) { background: #333; }
    /* Too narrow for one row (a console under 1100 px: a 1024 px window, or a
       side-bar theme on a small screen): first the Support button shows only
       its icon and the paused state only its dot (both keep their text for a
       screen reader); then the actions wrap to a second row. */
    @container aicli-log (min-width: 601px) and (max-width: 1100px) {
        #tab-debug :is(.log-menu-btn .log-btn-text, .log-paused-text) {
            position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap;
        }
        #tab-debug .log-menu-btn { padding: 0 8px; }
    }

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
    /* Folder picker buttons: Unraid's own button look (the "Native Unraid
       buttons" section below). NATIVE_BUTTON_STYLE.md. */
    .pp-btn-cancel, .pp-btn-confirm { cursor: pointer; }
    /* HOME_BACKUP.md 2026-09-24 follow-up: the folder browser's New folder row. */
    .pp-footer { flex-wrap: wrap; align-items: center; }
    .pp-footer .pp-new-folder { margin-right: auto; }
    .pp-new-row { margin-bottom: 12px; }
    .pp-new-row[hidden] { display: none !important; }
    .pp-new-fields { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
    /* input.pp-new-name inside .pp-modal: Unraid's input[type="text"] base rule is more specific than a bare class. */
    .pp-modal input.pp-new-name {
        flex: 1 1 160px; min-height: 28px; margin: 0; min-width: 0; box-sizing: border-box; padding: 4px 8px; font-size: 12px;
        border: 1px solid var(--border-color, #ccc); border-radius: 3px;
        background: var(--background-color, #fff); color: var(--text-color, inherit);
    }
    .pp-modal input.pp-new-name[aria-invalid="true"] { border-color: color-mix(in srgb, #dc2626 55%, var(--text-color, #1c1c1c)); }
    .pp-new-error { font-size: 11px; margin-top: 4px; overflow-wrap: anywhere; color: color-mix(in srgb, #dc2626 55%, var(--text-color, #1c1c1c)); }
    .pp-new-error:empty { display: none; }
    .pp-modal :focus-visible { outline: 2px solid var(--orange, #ff8c00); outline-offset: 2px; }

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
    /* NATIVE_BUTTON_STYLE.md (2026-09-29): the open chip no longer fills
       with orange. The chip row uses Unraid's own tab style (a thin frame,
       sentence case); the open chip has an orange frame, an orange bar at the
       bottom and a bold label. Those rules are in the "Native Unraid buttons"
       section after the WP #903 touch-target section. */
    .av2-chip[aria-expanded="true"] {
        border-top-left-radius: 6px; border-top-right-radius: 6px;
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
    .av2-al-fresh label { cursor: pointer; margin: 0; font-weight: normal; display: inline-flex; align-items: center; gap: 6px; }

    /* Buttons shared across panels. NATIVE_BUTTON_STYLE.md (2026-09-29): the
       colour, frame and font are Unraid's own (the "Native Unraid buttons"
       section below). .primary (Install, Upgrade) and .warn (Repair) look
       like every other button; .danger (Uninstall, Reset) has red text. */
    .av2-btn {
        cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    }

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
    /* NATIVE_BUTTON_STYLE.md: the chosen channel has an orange frame, an
       orange bar at the bottom and a bold label, not an orange fill. */
    .av2-seg input:checked + label {
        opacity: 1; font-weight: 700; color: var(--text-color, #e7e9ef);
        box-shadow: inset 0 0 0 1px var(--brand-orange, #ff8c2f), inset 0 -3px 0 var(--brand-orange, #ff8c2f);
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
        /* #166: the strip is a flex ITEM of the card's flex column. A flex item's
           default min-width:auto floors its width at its content's min-content
           (all 5 chips at natural width), which exceeds the card width — so the
           strip stretched WIDER than the card and, with the card overflow:visible,
           Terminal was clipped and Args poked off-card. `min-width: 0` on the strip
           lets it shrink to the card width; only THEN do the chips' own
           `flex:1 1 0; min-width:0` distribute the row evenly and the labels
           ellipsize. flex-wrap:nowrap kept (single row; MOBILE_RESPONSIVE.md). */
        .av2-strip {
            padding: 0 10px 10px;
            gap: 2px;
            flex-wrap: nowrap;
            min-width: 0;
            box-sizing: border-box;
            max-width: 100%;
        }
        /* The chips are <button>s; Unraid's global theme forces a min-width on
           buttons that beat a plain `min-width: 0`, so the chips would not shrink
           and the row overflowed the card (Terminal clipped, Args off-card). The
           extra `.av2-strip` scope (specificity 0,2,0) + !important defeats that
           floor so `flex: 1 1 0` distributes the five chips evenly across the row. */
        .av2-strip .av2-chip {
            flex: 1 1 0 !important;
            min-width: 0 !important;
            padding: 7px 3px;
            justify-content: center;
            overflow: hidden;
        }
        .av2-strip .av2-chip .av2-label {
            /* Sentence case (NATIVE_BUTTON_STYLE.md) is narrower than the old
               capitals, so the label can be 11 px. */
            font-size: 11px; letter-spacing: normal;
            min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
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
            flex: 1 1 calc(50% - 4px); min-width: 0 !important;
        }
    }

    /* =======================================================================
       WP #903 — 44x44 effective touch targets (WCAG 2.5.5 / L4 sweep).
       The Playwright sweep measures each interactive element's OWN bounding
       box (boundingBox() == border box), so pseudo-element hit-area hacks
       don't count. Instead the hit area is grown with REAL transparent
       block borders: the border box reaches >= 44 px tall (borders are
       genuine click area) while `background-clip: padding-box` keeps the
       painted pill at its original dense size.

       IMPORTANT cascade context: Unraid's default-base.css styles
       `button[type="button"]:where(:not(.unapi *))` at specificity (0,1,1),
       which BEATS our single-class rules (0,1,0). On rendered pages today
       that dynamix rule already wins border (none), padding (8px),
       margin (10px 12px 10px 0), min-width (86px) and the gradient-frame
       background on .av2-chip / .agent-sort-btn / .av2-btn. So:
         - every contested property here carries !important;
         - the transparent block borders REPLACE dynamix's 10px block
           margins (border eats the margin space -> identical outer
           geometry, identical painted position, zero visual change);
         - the dynamix gradient frame paints relative to the padding box
           (background-origin default), so it stays glued to the visible
           pill, not the enlarged hit box;
         - border-radius uses the `rx / ry` form so the PAINTED corner
           radius is unchanged (inner ry = outer ry - block border width).
       This section deliberately sits AFTER the mobile media query so the
       expansions also win at phone/tablet widths.
       ======================================================================= */

    /* Action buttons (NATIVE_BUTTON_STYLE.md, 2026-09-29): the painted button
       is 30 px tall, the height of Unraid's own buttons. 7 px transparent
       borders above and below make the border box, and so the tap area, 44 px.
       Negative block margins keep the layout at the painted 30 px. */
    .aicli-btn-slim, .av2-btn, .agent-sort-btn, .pp-btn-cancel, .pp-btn-confirm, .filter-btn {
        box-sizing: border-box !important;
        height: 44px !important;
        min-height: 44px !important;
        padding: 0 12px !important;
        border-top: 7px solid transparent !important;
        border-bottom: 7px solid transparent !important;
        border-radius: 4px / 11px !important; /* painted ry = 11 - 7 = 4 px, Unraid's radius */
        margin-top: -7px !important;
        margin-bottom: -7px !important;
        display: inline-flex !important; align-items: center; justify-content: center;
        vertical-align: middle;
    }
    /* In a row of other controls, keep the painted button clear of its neighbours. */
    .av2-foot .av2-btn, .agent-filter-bar .agent-sort-btn { margin-top: 0 !important; margin-bottom: 0 !important; }
    /* The consolidate button in the Resources panel spans the panel. */
    .aicli-btn {
        box-sizing: border-box !important;
        min-height: 44px !important;
        padding: 0 12px !important;
        border-top: 7px solid transparent !important;
        border-bottom: 7px solid transparent !important;
        border-radius: 4px / 11px !important;
        margin: 4px 0 0 0 !important;
        display: flex !important;
    }
    /* The filter buttons size to their label (Unraid gives every button an
       86 px minimum width), but never below the 44 px tap width. */
    .filter-btn { min-width: 44px !important; padding: 0 14px !important; margin-right: 0 !important; margin-left: 0 !important; }

    /* Store-card spec chips (dynamix-styled before 2026-09-29: 27 px = 8px
       padding + 11px label, no border, block margins 10px): 10px transparent
       borders -> 47 px hit box; block margins 10 -> 0 keeps outer rhythm at
       47 px. Block padding pinned to 8px for cross-viewport stability. */
    .av2-chip {
        border-top: 10px solid transparent !important;
        border-bottom: 10px solid transparent !important;
        border-left: 0 !important;
        border-right: 0 !important;
        padding-top: 8px !important;
        padding-bottom: 8px !important;
        background-clip: padding-box !important;
        border-radius: 4px / 14px !important; /* painted ry = 14 - 10 = 4 = Unraid's radius */
        margin-top: 0 !important;
        margin-bottom: 0 !important;
    }
    .av2-chip[aria-expanded="true"] {
        border-color: transparent !important;
        border-top-left-radius: 4px 14px !important;
        border-top-right-radius: 4px 14px !important;
    }

    /* =======================================================================
       Native Unraid buttons — NATIVE_BUTTON_STYLE.md (2026-09-29).
       The Manager's buttons look like Unraid's own buttons (Settings > Disk
       Settings: Default, Apply, Done). Unraid paints every <button> from
       theme variables in default-base.css: --button-text-color,
       --button-background, --button-background-size, --button-border and
       the --hover-button-* set. These rules use the same variables, so each
       theme gives its own look without a change here:
         - white and black (menu at the top): a thin red-to-orange frame,
           orange bold capitals, a full orange fill on hover;
         - azure and gray (.Theme--sidebar): a 1 px border, the theme's text,
           normal weight, sentence case.
       Two differences, both for the 44 px tap area above: the frame is
       painted in the padding box (background-clip), and the sidebar themes'
       1 px border is an inset box-shadow (a real border is the tap area).
       Not changed: the tab bar (.aicli-tab-btn), the Debug Console buttons
       (.log-action-btn, always dark like the console), the icon buttons.
       ======================================================================= */
    /* Contrast (2026-09-30, NATIVE_BUTTON_STYLE.md "Contrast in every
       theme"): Unraid's own button and text colours are below WCAG AA 4.5:1
       in some themes (azure button text #9f9180 on #edeaef 2.58:1, gray text
       #606e7f on #121510 3.54:1, the white theme's orange text 2.07:1). The
       owner decided "darken ours slightly": the look stays Unraid's, the text
       colour is mixed toward black (light themes) or white (dark themes)
       until it meets AA. The token block is the same as in
       ui-build/src/index.css (the terminal page); contrastTokens.test.ts and
       NativeButtonStyleTest keep the two copies equal. */
    /* AICLI-CONTRAST-TOKENS:BEGIN */
    :root {
      --aicli-text: var(--text-color, #1c1b1b);
      --aicli-btn-text: var(--button-text-color, #ff8c2f);
      --aicli-btn-hover-fill: linear-gradient(90deg, color-mix(in srgb, var(--brand-red, #e22828) 65%, #000) 0, color-mix(in srgb, var(--brand-orange, #ff8c2f) 65%, #000));
      --aicli-danger-text: color-mix(in srgb, #dc2626 70%, var(--aicli-text));
      --aicli-accent-dim: color-mix(in srgb, var(--aicli-accent-text) 60%, #000);
    }
    html.Theme--white {
      --aicli-btn-text: color-mix(in srgb, var(--button-text-color, #ff8c2f) 55%, #000);
    }
    html.Theme--black {
      --aicli-accent-dim: color-mix(in srgb, var(--aicli-accent-text) 80%, #fff);
      --aicli-danger-text: color-mix(in srgb, #dc2626 50%, var(--aicli-text));
    }
    html.Theme--azure {
      --aicli-text: color-mix(in srgb, var(--text-color, #606e7f) 50%, #000);
      --aicli-btn-text: color-mix(in srgb, var(--button-text-color, #9f9180) 65%, #000);
      --aicli-accent-text: color-mix(in srgb, var(--orange, #e68a00) 40%, var(--aicli-text));
    }
    html.Theme--gray {
      --aicli-accent-dim: color-mix(in srgb, var(--aicli-accent-text) 60%, #fff);
      --aicli-text: color-mix(in srgb, var(--text-color, #606e7f) 45%, #fff);
      --aicli-btn-text: color-mix(in srgb, var(--button-text-color, #606e7f) 70%, #fff);
      --aicli-danger-text: color-mix(in srgb, #dc2626 50%, var(--aicli-text));
    }
    /* AICLI-CONTRAST-TOKENS:END */
    .aicli-btn-slim, .aicli-btn, .av2-btn, .agent-sort-btn, .pp-btn-cancel, .pp-btn-confirm {
        font-family: clear-sans, sans-serif !important;
        font-size: 1.1rem !important;
        font-weight: bold !important;
        letter-spacing: 1.8px !important;
        text-transform: uppercase !important;
        line-height: 1.2 !important;
        white-space: nowrap;
        color: var(--aicli-btn-text, var(--button-text-color, #ff8c2f)) !important;
        background: var(--button-background, transparent) !important;
        background-size: var(--button-background-size, auto) !important;
        background-clip: padding-box !important;
        background-origin: padding-box !important;
        border-left: 0 !important;
        border-right: 0 !important;
        box-shadow: none !important;
        filter: none !important;
        transform: none !important;
        opacity: 1;
        transition: color .15s ease, background-color .15s ease, box-shadow .15s ease;
    }
    :is(.aicli-btn-slim, .aicli-btn, .av2-btn, .agent-sort-btn, .pp-btn-cancel, .pp-btn-confirm):hover:not([disabled]) {
        color: var(--hover-button-text-color, #fff) !important;
        /* Unraid's red-to-orange fill, 35 % darker: white text on it is 5:1. */
        background: var(--aicli-btn-hover-fill, var(--hover-button-background, #ff8c2f)) !important;
        background-size: 100% 100% !important;
        background-clip: padding-box !important;
    }
    .Theme--sidebar :is(.aicli-btn-slim, .aicli-btn, .av2-btn, .agent-sort-btn, .pp-btn-cancel, .pp-btn-confirm) {
        font-size: 1.2rem !important;
        font-weight: normal !important;
        letter-spacing: normal !important;
        text-transform: none !important;
        background: none !important;
        background-color: var(--button-background, transparent) !important;
        /* The "background" shorthand above resets the clip to the border box,
           which painted the 7 px transparent tap borders as a light rectangle
           around each button (azure, 2026-09-29). Keep the paint inside. */
        background-clip: padding-box !important;
        box-shadow: inset 0 0 0 1px var(--button-border, currentColor) !important;
    }
    .Theme--sidebar :is(.aicli-btn-slim, .aicli-btn, .av2-btn, .agent-sort-btn, .pp-btn-cancel, .pp-btn-confirm):hover:not([disabled]) {
        background: none !important;
        background-color: var(--hover-button-background, transparent) !important;
        background-clip: padding-box !important;
        box-shadow: inset 0 0 0 1px var(--hover-button-border, #0099ff) !important;
    }
    /* Disabled: Unraid's grey frame (top menu) or grey fill (sidebar), half opacity. */
    :is(.aicli-btn-slim, .aicli-btn, .av2-btn, .agent-sort-btn, .pp-btn-cancel, .pp-btn-confirm)[disabled] {
        opacity: 0.5 !important;
        cursor: default !important;
        color: var(--disabled-text-color, #808080) !important;
        background:
            linear-gradient(90deg, var(--gray-600, #404040) 0, var(--gray-500, #808080)) 0 0 no-repeat,
            linear-gradient(90deg, var(--gray-600, #404040) 0, var(--gray-500, #808080)) 0 100% no-repeat,
            linear-gradient(0deg, var(--gray-600, #404040) 0, var(--gray-600, #404040)) 0 100% no-repeat,
            linear-gradient(0deg, var(--gray-500, #808080) 0, var(--gray-500, #808080)) 100% 100% no-repeat !important;
        background-size: 100% 2px, 100% 2px, 2px 100%, 2px 100% !important;
        background-clip: padding-box !important;
    }
    .Theme--sidebar :is(.aicli-btn-slim, .aicli-btn, .av2-btn, .agent-sort-btn, .pp-btn-cancel, .pp-btn-confirm)[disabled] {
        background: none !important;
        background-color: var(--disabled-input-background-color, transparent) !important;
        background-clip: padding-box !important;
        box-shadow: inset 0 0 0 1px var(--disabled-input-border-color, #808080) !important;
    }
    /* Danger (Uninstall, Delete): Unraid marks a destructive action by its
       confirmation dialog, not by a red button. The plugin keeps the
       confirmation and adds red text, mixed with the theme text colour so it
       stays readable on a light and on a dark theme. */
    :is(.aicli-btn-slim, .av2-btn).danger:not([disabled]):not(:hover) {
        color: var(--aicli-danger-text, color-mix(in srgb, #dc2626 70%, var(--text-color, #1c1b1b))) !important;
    }

    /* Toggle buttons (the All / Installed / Updates filter and the store card's
       Channel / Envs / Resources / Terminal / Args row): Unraid's own tab style
       (default-base.css .tabs button[role="tab"]): the theme text on no fill,
       a thin grey frame; the chosen one has an orange frame, an orange bar
       at the bottom and a bold label. No orange fill. */
    .filter-btn, .av2-chip {
        font-family: clear-sans, sans-serif !important;
        font-size: 1.2rem !important;
        font-weight: normal !important;
        letter-spacing: normal !important;
        text-transform: none !important;
        line-height: 1.2 !important;
        cursor: pointer;
        color: var(--aicli-text, var(--text-color, #1c1b1b)) !important;
        background: none !important;
        background-color: transparent !important;
        background-clip: padding-box !important;
        box-shadow: inset 0 0 0 1px var(--disabled-input-border-color, #909090) !important;
        filter: none !important;
        opacity: 1;
    }
    .filter-btn:hover, .av2-chip:not(.disabled):hover {
        background-color: var(--mild-background-color, rgba(127,127,127,0.08)) !important;
        box-shadow: inset 0 0 0 1px var(--brand-orange, #ff8c2f) !important;
    }
    .filter-btn.active, .filter-btn[aria-pressed="true"], .av2-chip[aria-expanded="true"] {
        font-weight: bold !important;
        box-shadow: inset 0 0 0 1px var(--brand-orange, #ff8c2f), inset 0 -3px 0 var(--brand-orange, #ff8c2f) !important;
    }
    .av2-chip .av2-label {
        text-transform: none; letter-spacing: normal; font-size: inherit; font-weight: inherit; color: inherit;
    }
    .av2-chip.disabled { opacity: 0.5; }

    /* Keyboard focus: an inset ring on the painted button (an outline would
       circle the larger, invisible tap area). */
    :is(.aicli-btn-slim, .aicli-btn, .av2-btn, .agent-sort-btn, .pp-btn-cancel, .pp-btn-confirm, .filter-btn, .av2-chip):focus-visible {
        outline: none !important;
        box-shadow: inset 0 0 0 2px var(--hover-button-border, #0099ff) !important;
    }

    /* Release-notes link: inline element (dynamix button rule doesn't match
       bare <a>), so vertical borders expand its border box (and hit area)
       without affecting line layout at all. 15 px text + 2x15 px = 45 px. */
    .av2-changelog-link {
        border-top: 15px solid transparent;
        border-bottom: 15px solid transparent;
        background-clip: padding-box;
    }

    /* Config Hub — agent target selection (skills/commands/instructions/MCP).
       Uniform responsive tile grid replaces a ragged flex-wrap: agent name primary,
       config path a muted monospace second line, checked tiles accent in the brand
       orange, not-installed agents dimmed + disabled with a "not installed" pill.
       Neutral grey alphas + Dynamix theme vars keep it correct in dark AND light. */
    .hub-agent-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
        gap: 8px;
        margin-top: 6px;
    }
    .hub-agent-tile {
        display: flex; align-items: flex-start; gap: 8px;
        padding: 7px 10px; min-height: 38px; box-sizing: border-box;
        border: 1px solid rgba(128,128,128,0.22); border-radius: 6px;
        background: rgba(128,128,128,0.05); cursor: pointer;
        transition: border-color 0.12s ease, background 0.12s ease;
    }
    .hub-agent-tile:hover {
        border-color: rgba(128,128,128,0.45);
        background: rgba(128,128,128,0.11);
    }
    /* Selected: brand-orange accent (matches the plugin's primary controls). */
    .hub-agent-tile:has(input:checked) {
        border-color: var(--orange, #ff8c00);
        background: rgba(255,140,0,0.10);
    }
    .hub-agent-tile input { margin: 1px 0 0 0; flex: 0 0 auto; cursor: pointer; }
    .hub-agent-tile-body { display: flex; flex-direction: column; min-width: 0; flex: 1 1 auto; }
    .hub-agent-tile-name { font-size: 12px; font-weight: 600; line-height: 1.3; }
    .hub-agent-tile-path {
        font-size: 10px; font-family: monospace; opacity: 0.55;
        line-height: 1.3; margin-top: 1px; max-width: 100%;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    /* Not installed: dimmed, non-interactive, with an explicit pill (not colour alone). */
    .hub-agent-tile[data-off] { opacity: 0.5; cursor: default; background: transparent; }
    .hub-agent-tile[data-off]:hover { border-color: rgba(128,128,128,0.22); background: transparent; }
    .hub-agent-tile[data-off] input { cursor: default; }
    .hub-agent-tile-badge {
        align-self: center; flex: 0 0 auto; white-space: nowrap;
        font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
        padding: 2px 6px; border-radius: 8px; background: rgba(128,128,128,0.25);
    }

    /* #165 rev 2026-09-04: the all-cards bake pill styles were removed with the
       pill itself (docs/specs/AGENT_CARD_BAKE_INDICATOR.md). */

    /* =======================================================================
       HOME_BACKUP.md "2026-09-24 redesign (#287)": the per-home backup line on
       each Home card and the Backup dialog it opens. Error/ok text mixes the
       hue toward the theme text colour (the .aicli-sp-chip pattern) so it
       reads at 4.5:1 on the light and the dark theme. The dialog sits at
       z-index 10004: above Unraid's header (10002) and the Activity tray pill
       (10003, which covered the dialog's Close button on a phone), and below
       the folder browser (.pp-backdrop, 2000000) and sweet-alert confirms
       (99999), which open on top of it.
       ======================================================================= */
    .storage-entity-card .se-backup {
        display: flex; align-items: center; justify-content: space-between; gap: 8px;
        padding: 4px 4px 4px 8px; border: 1px solid var(--border-color, rgba(128,128,128,0.25));
        border-radius: 4px; font-size: 11px;
    }
    .storage-entity-card .se-backup-status { min-width: 0; overflow-wrap: anywhere; line-height: 1.4; }
    .storage-entity-card .se-backup-unset .se-backup-status { opacity: 0.85; }
    .storage-entity-card .se-backup-failed .se-backup-status,
    .hb-state.hb-state-failed, .hb-err, .hb-error {
        color: color-mix(in srgb, #dc2626 55%, var(--text-color, #1c1c1c));
    }
    .storage-entity-card .se-backup-ok .se-backup-status .fa,
    .hb-ok { color: color-mix(in srgb, #16a34a 50%, var(--text-color, #1c1c1c)); }
    .storage-entity-card .se-backup-running .se-backup-status { color: color-mix(in srgb, #ff8c00 45%, var(--text-color, #1c1c1c)); }

    .hb-backdrop {
        position: fixed; inset: 0; z-index: 10004;
        display: flex; align-items: center; justify-content: center;
        background: rgba(0,0,0,0.5);
    }
    .hb-dialog {
        width: min(560px, calc(100vw - 32px)); max-height: calc(100dvh - 48px);
        display: flex; flex-direction: column; box-sizing: border-box; overflow: hidden;
        background: var(--background-color, #fff); color: var(--text-color, inherit);
        border: 1px solid var(--border-color, #ccc); border-radius: 8px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    }
    .hb-header {
        display: flex; align-items: center; justify-content: space-between; gap: 8px;
        padding: 0 4px 0 14px; min-height: 44px;
        background: var(--title-header-background-color, var(--mild-background-color, #ededed));
        border-bottom: 1px solid var(--border-color, #ccc);
    }
    .hb-title {
        margin: 0; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
        display: flex; align-items: center; gap: 8px; min-width: 0; overflow-wrap: anywhere;
    }
    .hb-title .fa { color: var(--orange, #e68a00); }
    #aicli-home-backup-dialog .hb-close {
        width: 44px !important; height: 44px !important; min-width: 44px !important; flex: 0 0 44px;
        margin: 0 !important; padding: 0 !important; border: none !important; border-radius: 4px !important;
        background: transparent !important; color: var(--text-color, inherit) !important;
        font-size: 24px !important; line-height: 1 !important; font-weight: 400 !important;
        text-transform: none !important; box-shadow: none !important; cursor: pointer;
    }
    #aicli-home-backup-dialog .hb-close:hover { background: var(--mild-background-color, rgba(0,0,0,0.06)) !important; }
    #aicli-home-backup-dialog :focus-visible { outline: 2px solid var(--orange, #ff8c00); outline-offset: 2px; }
    /* The heading takes focus when the dialog opens (tabindex=-1, HOME_BACKUP.md
       2026-09-24 follow-up): it is not a control, so it never shows a ring. */
    #aicli-home-backup-dialog #hb-title:focus { outline: none; }
    /* A slim button is a 26 px pill inside a 44 px transparent hit box, so an
       outline drew a large rounded ring around empty space. The keyboard ring
       is drawn on the pill itself (an inset shadow sits inside the padding box). */
    #aicli-home-backup-dialog .aicli-btn-slim:focus-visible {
        outline: none;
        box-shadow: inset 0 0 0 2px var(--text-color, #111), inset 0 0 0 4px var(--background-color, #fff) !important;
    }
    .hb-suggest {
        display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 6px;
        padding: 6px 8px; border-radius: 4px; font-size: 11px; overflow-wrap: anywhere;
        border: 1px dashed var(--border-color, rgba(128,128,128,0.4));
    }
    .hb-suggest[hidden] { display: none !important; }
    .hb-suggest code { font-size: 11px; overflow-wrap: anywhere; }
    .hb-snapshots .aicli-snapshot-actions { display: flex; gap: 6px; flex: 0 0 auto; }
    .hb-body {
        padding: 12px 14px; overflow-y: auto; flex: 1 1 auto; min-height: 0;
        display: flex; flex-direction: column; gap: 14px; font-size: 12px;
    }
    .hb-status { display: flex; flex-direction: column; gap: 4px; }
    .hb-state { font-weight: 700; overflow-wrap: anywhere; }
    .hb-muted, .hb-help { font-size: 11px; opacity: 0.85; overflow-wrap: anywhere; }
    .hb-help { margin-top: 4px; }
    .hb-progress { font-size: 11px; color: color-mix(in srgb, #ff8c00 45%, var(--text-color, #1c1c1c)); }
    .hb-fields { border: 0; margin: 0; padding: 0; min-width: 0; display: flex; flex-direction: column; gap: 14px; }
    .hb-fields[disabled] { opacity: 0.6; }
    .hb-field { border: 0; margin: 0; padding: 0; min-width: 0; }
    .hb-label { font-size: 11px; font-weight: 700; padding: 0; }
    .hb-label-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 4px; }
    .hb-radios legend { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
    .hb-save { font-size: 11px; font-weight: 400; }
    .hb-save.hb-saving { opacity: 0.85; }
    .hb-target-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .hb-target {
        flex: 1 1 200px; min-width: 0; box-sizing: border-box;
        font-family: monospace; font-size: 11px; padding: 6px 8px; overflow-wrap: anywhere;
        border: 1px solid var(--border-color, #ccc); border-radius: 4px;
        background: var(--mild-background-color, rgba(0,0,0,0.03));
    }
    .hb-target.hb-empty { font-family: inherit; font-style: italic; }
    .hb-check { font-size: 11px; margin-top: 4px; overflow-wrap: anywhere; min-height: 14px; }
    .hb-radios label, .hb-check-label {
        display: flex; align-items: center; gap: 6px; font-size: 12px; cursor: pointer; min-height: 28px;
    }
    .hb-radios input, .hb-check-label input { margin: 0 !important; flex: 0 0 auto; }
    .hb-inline { display: flex; gap: 8px; align-items: center; }
    .hb-wrap { flex-wrap: wrap; }
    .hb-dialog select, .hb-dialog input[type="number"], .hb-dialog input[type="time"] {
        margin: 0 !important; min-width: 0 !important; max-width: 100%;
    }
    #hb-keep { width: 72px; }
    /* Own open/closed marker: a flex summary (phone rule) loses the native one. */
    .hb-details summary { cursor: pointer; font-size: 11px; font-weight: 700; padding: 4px 0; list-style: none; }
    .hb-details summary::-webkit-details-marker { display: none; }
    .hb-details summary::before { content: '\25B8'; display: inline-block; width: 1em; }
    .hb-details[open] summary::before { content: '\25BE'; }
    .hb-details textarea {
        display: block; width: 100%; box-sizing: border-box; margin: 4px 0 0;
        font-family: monospace; font-size: 11px;
    }
    .hb-snapshots { display: flex; flex-direction: column; gap: 4px; margin-top: 4px; }
    .hb-snapshots .aicli-snapshot-row span { overflow-wrap: anywhere; min-width: 0; }
    .hb-footer {
        display: flex; justify-content: flex-end; gap: 8px; padding: 8px 14px;
        background: var(--title-header-background-color, var(--mild-background-color, #ededed));
        border-top: 1px solid var(--border-color, #ccc);
    }
    /* .hb-secondary (Cancel, Close) looks like every other button now
       (NATIVE_BUTTON_STYLE.md); the disabled look is in the native section. */

    /* AUTO_CONTINUE_PATTERNS.md: Settings > Auto-continue patterns and its two
       dialogs (they reuse the .hb-dialog shell of the Backup dialog). */
    /* Own class, not .hb-details: the home backup dialog finds its one
       summary by that class. The same open/closed marker. */
    .acp-details summary { cursor: pointer; font-size: 11px; font-weight: 700; padding: 4px 0; list-style: none; }
    .acp-details summary::-webkit-details-marker { display: none; }
    .acp-details summary::before { content: '\25B8'; display: inline-block; width: 1em; }
    .acp-details[open] summary::before { content: '\25BE'; }
    /* .aicli-btn-slim sets display, which beats the hidden attribute. */
    .acp-report-open[hidden] { display: none !important; }
    /* A touch screen wider than a phone (a tablet) also needs 44 px targets. */
    @media (pointer: coarse) {
        .acp-toggle { min-height: 44px; min-width: 44px; box-sizing: border-box; padding: 0 6px; }
        .acp-details summary { min-height: 44px; box-sizing: border-box; display: flex; align-items: center; }
    }
    .acp-help { font-size: 11px; opacity: 0.85; margin: 0 0 8px; }
    .acp-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
    .acp-h { margin: 0; font-size: 12px; font-weight: 700; }
    .acp-status { font-size: 11px; min-height: 14px; margin: 4px 0; overflow-wrap: anywhere; }
    .acp-err { color: color-mix(in srgb, #dc2626 55%, var(--text-color, #1c1c1c)); }
    .acp-list { list-style: none; margin: 0 0 8px; padding: 0; display: flex; flex-direction: column; gap: 6px; }
    .acp-empty { font-size: 11px; opacity: 0.8; }
    .acp-row { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; padding: 6px 8px; border: 1px solid var(--border-color, #ccc); border-radius: 6px; }
    .acp-row-main { display: flex; flex-direction: column; gap: 2px; flex: 1 1 220px; min-width: 0; }
    .acp-row-name { font-size: 12px; overflow-wrap: anywhere; }
    .acp-row-meta { font-size: 11px; opacity: 0.8; }
    .acp-row-re, .acp-bi-re, .acp-mono { font-family: monospace; font-size: 11px; overflow-wrap: anywhere; word-break: break-all; }
    .acp-row-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .acp-toggle { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; cursor: pointer; }
    .acp-toggle input { margin: 0 !important; }
    .acp-row-status { flex: 1 1 100%; font-size: 11px; min-height: 0; overflow-wrap: anywhere; }
    .acp-row-status:empty { display: none; }
    .acp-confirm { flex: 1 1 100%; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 11px; font-weight: 700; }
    .acp-builtin-list { display: flex; flex-direction: column; gap: 10px; margin-top: 6px; }
    .acp-bi-h { margin: 0 0 4px; font-size: 12px; font-weight: 700; }
    .acp-bi-kind { margin: 0 0 6px 8px; }
    .acp-bi-kind-h { font-size: 11px; font-weight: 700; opacity: 0.85; }
    .acp-bi-rules { list-style: none; margin: 2px 0 0; padding: 0; display: flex; flex-direction: column; gap: 2px; }
    .acp-bi-rules li { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
    .acp-bi-id { font-size: 11px; font-weight: 600; }
    .acp-dialog { width: min(640px, calc(100vw - 32px)); }
    .acp-dbody { padding: 12px 14px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; }
    .acp-field { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
    .acp-field input, .acp-field select, .acp-field textarea { width: 100%; box-sizing: border-box; margin: 0 !important; }
    .acp-two { display: flex; gap: 10px; flex-wrap: wrap; }
    .acp-two > .acp-field { flex: 1 1 180px; }
    .acp-testbox { display: flex; flex-direction: column; gap: 6px; padding: 8px; border: 1px dashed var(--border-color, #ccc); border-radius: 6px; }
    .acp-test-out { font-size: 11px; overflow-wrap: anywhere; }
    .acp-test-lines { margin: 4px 0 0; padding: 4px 4px 4px 34px; max-height: 220px; overflow: auto; font-family: monospace; font-size: 11px; background: var(--mild-background-color, rgba(0,0,0,0.04)); border-radius: 4px; }
    .acp-test-lines li { white-space: pre-wrap; overflow-wrap: anywhere; }
    .acp-test-lines li.acp-hit { background: color-mix(in srgb, #16a34a 22%, transparent); font-weight: 700; }
    .acp-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
    .acp-error:empty, .acp-saved:empty { display: none; }
    .acp-dialog .acp-x {
        width: 44px !important; height: 44px !important; min-width: 44px !important; flex: 0 0 44px;
        margin: 0 !important; padding: 0 !important; border: none !important; border-radius: 4px !important;
        background: transparent !important; color: var(--text-color, inherit) !important;
        font-size: 24px !important; line-height: 1 !important; font-weight: 400 !important;
        text-transform: none !important; box-shadow: none !important; cursor: pointer;
    }
    .acp-dialog :focus-visible { outline: 2px solid var(--orange, #ff8c00); outline-offset: 2px; }
    .acp-dialog .hb-title:focus { outline: none; }
    .acpr-fields { display: grid; grid-template-columns: max-content 1fr; gap: 3px 10px; margin: 0; font-size: 11px; }
    .acpr-fields dt { font-weight: 700; }
    .acpr-fields dd { margin: 0; min-width: 0; overflow-wrap: anywhere; }
    .acpr-open[aria-disabled="true"] { opacity: 0.55; cursor: progress; }
    a.acpr-open { text-decoration: none !important; }

    /* =======================================================================
       Epic #307 — Manager overlays at phone size (docs/specs/MOBILE_RESPONSIVE.md).
       Every rule is inside max-width: 600px, so the desktop page is unchanged.
       Sweet-alert is Unraid's shared dialog: its rules are scoped to
       body.aicli-mgr, a class ManagerLayout.php sets on this page only, so no
       other plugin or Unraid page gets a restyled dialog.
       ======================================================================= */
    @media (max-width: 600px) {
        /* Store card panels: the Resources panel's "Consolidate (N MB unsaved)"
           button (shown only while an agent has unsaved data) was 32 px. */
        .av2-panel .aicli-btn { min-height: 44px; box-sizing: border-box; }

        /* Tab bar: each tab is a 44 px touch target (was 31 px). */
        .aicli-tab-btn {
            min-height: 44px; box-sizing: border-box;
            display: inline-flex; align-items: center;
        }

        /* Sweet-alert on this page: 44 px buttons, and a label that wraps a
           check box or radio is the touch target, so it is 44 px tall. */
        body.aicli-mgr .sweet-alert .sa-button-container button,
        body.aicli-mgr .sweet-alert .sa-confirm-button-container button {
            min-height: 44px !important; min-width: 44px !important; box-sizing: border-box !important;
        }
        body.aicli-mgr .sweet-alert { padding: 1.25rem 1rem; }
        body.aicli-mgr .sweet-alert label:has(input[type="checkbox"]),
        body.aicli-mgr .sweet-alert label:has(input[type="radio"]) {
            min-height: 44px; box-sizing: border-box; align-items: center;
        }

        /* Directory picker: full width with a margin, list fills the height. */
        .pp-modal { width: calc(100vw - 24px); max-height: calc(100dvh - 24px); }
        .pp-dir-list { height: auto; min-height: 160px; max-height: 50dvh; }
        .pp-dir-item { min-height: 44px; box-sizing: border-box; }
        .pp-btn-cancel, .pp-btn-confirm { min-height: 44px; min-width: 44px; padding-left: 16px; padding-right: 16px; }
        .pp-path-bar input { min-height: 32px; font-size: 16px !important; }
        .pp-modal input.pp-new-name { min-height: 44px; font-size: 16px !important; flex-basis: 100%; }
        .pp-new-fields .pp-btn-confirm, .pp-new-fields .pp-btn-cancel { flex: 1 1 0; }

        /* Store card panels: segmented channel control, the free-form
           Envs rows' delete button, the auto-launch check box labels. */
        .av2-seg label {
            min-height: 44px; box-sizing: border-box;
            display: flex; align-items: center; justify-content: center;
        }
        .av2-ff-row .av2-ff-del { min-width: 44px !important; min-height: 44px !important; }
        /* Form fields in the panels: 44 px tall, and 16 px text so iOS
           Safari does not zoom the page when a field gets focus. */
        .av2-panel input:not([type="checkbox"]):not([type="radio"]),
        .av2-panel select {
            min-height: 44px; box-sizing: border-box; font-size: 16px !important;
        }
        .av2-ff-row input { min-width: 0; }
        .av2-al-toggle, .av2-al-fresh label { min-height: 44px; box-sizing: border-box; }

        /* Config Hub and Relay forms: inline minimum widths (190-320 px) are
           desktop sizes; on a phone each field takes the full row instead. */
        #tab-relay label[style*="min-width"], #tab-hub div[style*="min-width"] {
            min-width: 0 !important; flex-basis: 100% !important;
        }
        #tab-hub .hub-skill-file-path { flex: 1 1 auto; min-width: 0; width: auto !important; }
        #tab-hub .hub-skill-file-row button { min-width: 44px !important; min-height: 44px !important; }
        #tab-hub input[type="text"], #tab-hub input[type="password"],
        #tab-relay input:not([type="checkbox"]):not([type="radio"]) {
            max-width: 100%; box-sizing: border-box;
        }

        /* Config Hub editors: faded hint text and agent config paths were
           3.2-3.7:1; raise them to readable contrast on a phone. */
        #tab-hub span[style*="opacity:0.5"] { opacity: 0.8 !important; }
        .hub-agent-tile-path { opacity: 0.85; font-size: 11px; }

        /* Home Storage cards: the grid track was minmax(400px, 1fr), wider
           than a phone, so each card and its action icons ran past the right
           edge (clipped, not scrollable). One full-width column instead. */
        .storage-entity-grid { grid-template-columns: minmax(0, 1fr) !important; }
        /* Home Storage card actions (Persist, Consolidate, Repair, Delete)
           open the home dialogs: 44 px icons instead of 20 px. */
        .storage-entity-card .se-actions .stat-icon-btn { width: 44px; height: 44px; font-size: 16px; }

        /* Boot-integrity banner (Home Storage) and migrate progress card:
           orange-on-tint headings were 2.3:1 and faded notes 4.2-4.3:1.
           The border keeps the warning colour; the text uses the theme's. */
        #aicli-boot-integrity-banner [style*="color:#e67e22"],
        #aicli-boot-integrity-banner [style*="color:#c0392b"] { color: var(--text-color, #222) !important; }
        #aicli-boot-integrity-banner [style*="opacity:0.6"],
        #aicli-boot-integrity-banner [style*="opacity:0.75"] { opacity: 0.9 !important; }
        #migrate-file { opacity: 0.9 !important; }

        /* Storage target pickers (Configuration and Home Storage backup). */
        .aicli-storage-picker label { min-height: 44px; box-sizing: border-box; }
        /* Status chips (recommended / pool / free space / warnings): the light
           hues on tinted grounds were 1.7-3.1:1. Mixing the hue toward the
           theme text colour keeps it recognisable and reaches 4.5:1 in light
           and dark themes; 11 px instead of 9 px. */
        .aicli-sp-chip {
            color: color-mix(in srgb, var(--chip-fg, currentColor) 40%, var(--text-color, #1c1c1c)) !important;
            font-size: 11px !important;
        }
        /* HOME_BACKUP.md #287: the per-home Backup dialog on a phone — full
           width with a 12 px margin, 44 px targets, 16 px field text (no iOS
           zoom), footer buttons share the row. */
        .hb-dialog { width: calc(100vw - 24px); max-height: calc(100dvh - 24px); }
        .hb-radios label, .hb-check-label, .hb-details summary { min-height: 44px; box-sizing: border-box; }
        .hb-details summary { display: flex; align-items: center; }
        .hb-dialog input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]),
        .hb-dialog select, .hb-dialog textarea {
            min-height: 44px; box-sizing: border-box; font-size: 16px !important;
        }
        #hb-keep { width: 96px; }
        .hb-footer .aicli-btn-slim { flex: 1 1 0 !important; }
        .hb-target-row .aicli-btn-slim { flex: 1 1 auto !important; }
        .hb-suggest .aicli-btn-slim { flex: 1 1 auto !important; }
        .hb-snapshots .aicli-snapshot-row { flex-wrap: wrap; }
        .hb-snapshots .aicli-snapshot-actions { flex: 1 1 100%; justify-content: flex-end; }
        .storage-entity-card .se-backup { flex-wrap: wrap; }
        .storage-entity-card .se-backup-btn { margin-left: auto !important; }
        /* AUTO_CONTINUE_PATTERNS.md on a phone: full-width dialogs, 44 px
           targets, 16 px field text (no iOS zoom), actions share the row. */
        .acp-dialog { width: calc(100vw - 24px); max-height: calc(100dvh - 24px); }
        .acp-dialog input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]),
        .acp-dialog select, .acp-dialog textarea { min-height: 44px; box-sizing: border-box; font-size: 16px !important; }
        .acp-footer { flex-wrap: wrap; }
        .acp-footer .aicli-btn-slim { flex: 1 1 auto !important; }
        /* Full width lands on a sub-pixel row (43.99 px); 46 px keeps it a 44 px target. */
        .acp-testbox .aicli-btn-slim { width: 100%; height: 46px !important; }
        .acp-toggle { min-height: 44px; min-width: 44px; box-sizing: border-box; padding: 0 6px; }
        .acp-row-actions { width: 100%; }
        .acp-row-actions .aicli-btn-slim { flex: 1 1 auto !important; }
        .acp-details summary { min-height: 44px; box-sizing: border-box; display: flex; align-items: center; }
        .acpr-fields { grid-template-columns: 1fr; }
        /* NATIVE_BUTTON_STYLE.md: the search box takes its own row, so the
           filter buttons and the sort button fit the phone width (the sort
           button was clipped at the right edge). */
        .agent-filter-bar { flex-wrap: wrap; gap: 8px 10px; }
        .agent-search { flex: 1 1 100%; }

        /* PHONE (2026-09-29): Config Hub + Agent Relay (MOBILE_OVERLAYS.md,
           "2026-09-29 — Config Hub and Agent Relay on a phone"). Owner
           report from an iPhone: header buttons ran off the right edge, row
           buttons sat as wide boxes beside the content, and the top-menu
           capitals were too wide. Scoped to #tab-hub and #tab-relay (the
           Relay Permissions dialog is inside #tab-relay). */

        /* Buttons: Unraid has no phone rule of its own, so its capitals stay
           (top-menu themes); only the letter spacing goes from 1.8 px to
           0.5 px, as on the drawer buttons (NATIVE_BUTTON_STYLE.md). Unraid's
           86 px minimum width becomes the 44 px tap width, so an icon-only
           button is a 44 px square. */
        html:not(.Theme--sidebar) :is(#tab-hub, #tab-relay) .aicli-btn-slim { letter-spacing: 0.5px !important; }
        :is(#tab-hub, #tab-relay) .aicli-btn-slim { min-width: 44px !important; padding: 0 10px !important; }

        /* Card headers: the title takes its own line; the buttons wrap and
           share the next line. An icon-only button (Refresh) stays 44 px. */
        #tab-hub .aicli-card-header { flex-wrap: wrap; row-gap: 12px; }
        #tab-hub .aicli-card-header > span:first-child { flex: 1 1 100%; min-width: 0; }
        #tab-hub .aicli-card-header > span:last-child:has(> .aicli-btn-slim) { flex: 1 1 100%; flex-wrap: wrap; row-gap: 8px; min-width: 0; }
        #tab-hub .aicli-card-header > span:last-child:has(> .aicli-btn-slim) > .aicli-btn-slim { flex: 1 1 auto !important; }
        #tab-hub .aicli-card-header > span:last-child:has(> .aicli-btn-slim) > .aicli-btn-slim[title="Refresh"] { flex: 0 0 44px !important; }

        /* Rows with actions (MCP servers, skills, commands, drift keys, git
           files): the content takes the full width, the actions go under it
           and share one line. */
        #tab-hub .hub-row, #tab-hub .hub-git-file, #tab-hub .hub-apply-session { flex-wrap: wrap; row-gap: 8px; }
        #tab-hub .hub-row-info { flex: 1 1 100% !important; }
        #tab-hub .hub-row-actions { display: flex !important; flex: 1 1 100%; gap: 8px; min-width: 0; }
        #tab-hub .hub-row-actions > .aicli-btn-slim { flex: 1 1 0 !important; min-width: 0 !important; }
        #tab-hub .hub-row-cmd { white-space: normal !important; word-break: break-all; }
        #tab-hub .hub-drift-file, #tab-hub .hub-drift-key { flex: 1 1 100%; min-width: 0; word-break: break-all; }
        #tab-hub .hub-git-file-path { flex: 1 1 100% !important; white-space: normal !important; word-break: break-all; }
        #tab-hub .hub-apply-session-id { flex: 1 1 100%; min-width: 0; word-break: break-all; }
        #tab-hub .hub-apply-session > .aicli-btn-slim { flex: 1 1 100% !important; }

        /* Git history: a commit row is a 44 px tap target and wraps. */
        #tab-hub .hub-git-sum { min-height: 44px; box-sizing: border-box; flex-wrap: wrap; row-gap: 2px; }
        #tab-hub .hub-git-sum > span:last-child { flex: 1 1 100% !important; }
        #tab-hub #hub-git-card .aicli-card-header > span:first-child > span { display: block; }
        #tab-hub #hub-git-enabled-ui label { flex: 1 1 100%; min-width: 0; }
        #tab-hub #hub-git-enabled-ui label input { width: 100% !important; }
        #tab-hub #hub-git-enabled-ui .aicli-btn-slim { flex: 1 1 auto !important; }

        /* Editors: environment rows wrap (key on its own line), the Save and
           Cancel row shares the width. */
        #tab-hub .hub-env-row { flex-wrap: wrap; }
        #tab-hub .hub-env-row .hub-env-key { flex: 1 1 100%; width: auto !important; }
        #tab-hub .hub-env-row .hub-env-val { flex: 1 1 0 !important; min-width: 0; max-width: none !important; }
        :is(#hub-editor, #hub-skill-editor, #hub-command-editor) div[style*="display:flex; gap:8px"] > .aicli-btn-slim { flex: 1 1 0 !important; }
        #tab-hub #hub-editor label { flex: 1 1 100%; }
        #tab-hub #hub-f-name, #tab-hub #hub-skill-name, #tab-hub #hub-command-name { width: 100% !important; }

        /* Summaries (details) are 44 px tap targets. A text-only summary
           stays a list item (display:flex would drop its open/closed
           triangle) and gets a 44 px line; the git commit rows are flex. */
        #tab-hub .hub-drift-values, #tab-relay details > summary {
            display: list-item !important; min-height: 44px; line-height: 22px; padding: 11px 0; box-sizing: border-box;
        }

        /* Form fields: 44 px tall, 16 px text so iOS Safari does not zoom
           the page when a field gets focus. */
        :is(#tab-hub, #tab-relay) select,
        :is(#tab-hub, #tab-relay) input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]) {
            min-height: 44px; box-sizing: border-box; font-size: 16px !important; max-width: 100%;
        }
        :is(#tab-hub, #tab-relay) textarea { font-size: 16px !important; box-sizing: border-box; max-width: 100%; }
        #tab-relay label:has(> input[type="checkbox"]), #tab-relay label:has(> input[type="radio"]) { min-height: 44px; box-sizing: border-box; }

        /* Small text: 9-11 px desktop sizes read at 12 px on a phone. */
        #tab-hub :is([style*="font-size:9px"], [style*="font-size:10px"], [style*="font-size:11px"], [style*="font-size: 9px"], [style*="font-size: 10px"], [style*="font-size: 11px"]),
        #tab-relay :is([style*="font-size:10px"], [style*="font-size:11px"], [style*="font-size: 10px"], [style*="font-size: 11px"]) { font-size: 12px !important; }

        #tab-hub .hub-agent-tile-path, #tab-relay .relay-peer-label, #tab-relay .relay-peer-pill { font-size: 12px !important; }

        /* Relay: topic rows get a divider; the restart notice rows wrap. */
        #tab-relay .relay-topic-row { padding: 6px 0; border-bottom: 1px solid var(--border-color, rgba(128,128,128,.2)); }
        #tab-relay .relay-topic-row code { overflow-wrap: anywhere; min-width: 0; flex-shrink: 1 !important; }
        #tab-relay .relay-restart-row { flex-wrap: wrap; }
        #tab-relay .relay-restart-row code { flex: 1 1 100%; word-break: break-all; }
        #tab-relay .relay-restart-row .aicli-btn-slim, #relay-reload-all { flex: 1 1 100% !important; width: 100%; }

        /* Topic owners: a stacked card per topic. The topic and the owner
           select take the full width; "Start at boot" (its label is the tap
           target) and Start now share the last line. */
        #relay-owner-table { grid-template-columns: minmax(0, 1fr) auto !important; }
        #relay-owner-table [role="columnheader"] { display: none !important; }
        #relay-owner-table .relay-owner-topic-cell { grid-column: 1 / -1; padding-top: 10px !important; }
        #relay-owner-table .relay-owner-select-cell { grid-column: 1 / -1; flex-wrap: wrap; }
        #relay-owner-table .relay-owner-select-cell select { flex: 1 1 100%; width: 100%; }
        #relay-owner-table .relay-owner-boot-cell { justify-content: flex-start !important; padding-bottom: 10px !important; }
        #relay-owner-table .relay-owner-act-cell { padding-bottom: 10px !important; }
        #relay-owner-table .relay-owner-boot { min-height: 44px; gap: 6px; }
        #relay-owner-table .relay-owner-boot-text { display: inline !important; }
        #relay-owner-table .relay-owner-boot input { width: 20px; height: 20px; }

        /* Activity history: the header wraps; the time moves right, the
           preview takes its own line. */
        #tab-relay .relay-hist-head { flex-wrap: wrap; min-height: 44px; box-sizing: border-box; row-gap: 2px; }
        #tab-relay .relay-hist-who { flex: 1 1 auto !important; max-width: calc(100% - 20px) !important; }
        #tab-relay .relay-hist-when { margin-left: auto; }
        #tab-relay .relay-hist-preview { flex: 1 1 100% !important; }

        /* Relay forms: each field and button row fills the width. */
        #tab-relay details label[style*="display:grid"] { flex: 1 1 100%; }
        #tab-relay details label[style*="display:grid"] :is(input, select) { width: 100% !important; }
        /* END PHONE (2026-09-29): Config Hub + Agent Relay */

        /* PHONE (2026-09-29): Configuration, Store, Home Storage, Debug */
        /* MOBILE_OVERLAYS.md, "Settings phone review — Configuration, Store,
           Home Storage, Debug". Measured on the live page at 360, 375 and
           390 px, white theme and azure (a side bar takes 80 px). Every rule
           is scoped to these four tabs, the Manager tab bar and header, or a
           dialog that they open. The Config Hub and Agent Relay tabs are not
           changed here. */

        /* Tab bar: a grid of whole labels instead of a strip that scrolls and
           cuts "Home Storage" at the screen edge. The health chip goes to its
           own line at the right. */
        div:has(> .aicli-tabs) { flex-wrap: wrap; }
        .aicli-tabs {
            display: grid !important;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 4px; flex: 1 1 100%;
            overflow: visible !important;
            padding: 0 0 4px !important;
        }
        .aicli-tabs .aicli-tab-btn {
            justify-content: center; text-align: center;
            white-space: normal; line-height: 1.2;
            padding: 4px 6px; margin: 0; bottom: 0;
            font-size: 11px; letter-spacing: 0.02em;
            border-radius: 4px; border-bottom: 1px solid var(--border-color, #333);
        }
        #aicli-health-chip { margin-left: auto; min-height: 44px; }

        /* Native Unraid buttons on a phone. Unraid's own rule (default-base.css)
           has no phone size: 1.8 px letter spacing, an 86 px minimum width and
           a 12 px right margin. Here: 0.5 px spacing (as the drawer buttons),
           a 44 px minimum, no side margin (each row has a gap), and a button
           can shrink in a narrow row. */
        :is(#tab-config, #tab-store, #tab-storage, #tab-debug, .hb-dialog, .pp-modal, #migration-overlay)
            :is(.aicli-btn-slim, .aicli-btn, .av2-btn, .agent-sort-btn, .pp-btn-cancel, .pp-btn-confirm, .filter-btn, .av2-chip, .log-action-btn) {
            letter-spacing: 0.5px !important;
            min-width: 44px !important;
            max-width: 100%;
            margin-left: 0 !important; margin-right: 0 !important;
            flex-shrink: 1;
        }
        .Theme--sidebar :is(#tab-config, #tab-store, #tab-storage, #tab-debug, .hb-dialog, .pp-modal, #migration-overlay)
            :is(.aicli-btn-slim, .aicli-btn, .av2-btn, .agent-sort-btn, .pp-btn-cancel, .pp-btn-confirm, .filter-btn, .av2-chip, .log-action-btn) {
            letter-spacing: normal !important;
        }

        /* Card headers with buttons (AI Agent Marketplace, SSH Keys): the
           title has its own line, the buttons share the next line. */
        :is(#tab-config, #tab-store, #tab-storage) .aicli-card-header:has(> button) {
            flex-wrap: wrap; row-gap: 4px; padding: 8px 12px !important;
        }
        :is(#tab-config, #tab-store, #tab-storage) .aicli-card-header:has(> button) > span:first-child { flex: 1 1 100%; }
        :is(#tab-config, #tab-store, #tab-storage) .aicli-card-header > button { flex: 1 1 auto; font-size: 1.1rem !important; }
        :is(#tab-config, #tab-store, #tab-storage) .aicli-card-body { padding: 12px; }

        /* Settings lists: the label goes above its field. Side by side, the
           label took up to 38 % of the card, so a field was 73 px wide
           ("/boot/co") and a row of controls ran past the card edge. */
        :is(#tab-config, #tab-store, #tab-storage) .aicli-card-body dl {
            grid-template-columns: minmax(0, 1fr) !important;
            gap: 4px !important;
        }
        :is(#tab-config, #tab-store, #tab-storage) .aicli-card-body dl dt {
            text-align: left !important; padding: 10px 0 0 !important;
        }
        :is(#tab-config, #tab-store, #tab-storage) .aicli-card-body dl dt:empty { display: none !important; }
        /* A row of controls wraps: a field keeps a usable width and its
           buttons go to the next line, full width. */
        :is(#tab-config, #tab-store, #tab-storage) .input-row { flex-wrap: wrap !important; row-gap: 8px !important; }
        :is(#tab-config, #tab-store, #tab-storage) .input-row > :is(select, input[type="text"], input[type="password"]):not([style*="px !important"]) { flex: 1 1 160px !important; min-width: 0 !important; }
        :is(#tab-config, #tab-store, #tab-storage) .input-row > input[readonly] { flex-basis: 100% !important; text-overflow: ellipsis; }
        :is(#tab-config, #tab-store, #tab-storage) .input-row > .aicli-btn-slim { flex: 0 1 auto; }
        :is(#tab-config, #tab-store, #tab-storage) .input-row > input[readonly] ~ .aicli-btn-slim { flex: 1 1 auto; }
        /* Fields: 44 px tall and 16 px text, so iOS Safari does not zoom
           the page when a field gets focus. */
        :is(#tab-config, #tab-store, #tab-storage, #tab-debug) :is(input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]), select) {
            height: auto !important; min-height: 44px !important;
            font-size: 16px !important; box-sizing: border-box;
        }
        :is(#tab-config, #tab-store, #tab-storage, #tab-debug) textarea { font-size: 16px !important; box-sizing: border-box; }
        /* Small help text (9-11 px) is 12 px on a phone. */
        :is(#tab-config, #tab-storage) :is([style*="font-size:9px"], [style*="font-size:10px"], [style*="font-size:11px"], [style*="font-size: 9px"], [style*="font-size: 10px"], [style*="font-size: 11px"]):not(.aicli-sp-chip):not(textarea):not(input):not(select) {
            font-size: 12px !important;
        }
        #aicli-voice-setup .aicli-vs-note, .acp-help, .acp-row-meta, .acp-empty, .acp-status { font-size: 12px; }
        /* Storage target chips wrap inside the card instead of running off it. */
        .aicli-sp-chip { white-space: normal !important; max-width: 100%; overflow-wrap: anywhere; box-sizing: border-box; }
        #tab-config .acp-toolbar .aicli-btn-slim { flex: 1 1 auto; }
        #tab-config .acp-builtin-list, #tab-config .acp-bi-kind { margin-left: 0; }
        #pane-input-rules-section > summary { min-height: 44px; display: flex; align-items: center; box-sizing: border-box; }

        /* Agent Store. Unraid's 12 px button margin took 60 px from the five
           chips, so "Resources" and "Terminal" were cut to "Reso..." and
           "Termi...". With no margin they fit at 360 px. On a side-bar theme
           the card is about 240 px wide, too narrow for five 44 px chips with
           a label, so there the row wraps to three and two. */
        #tab-store .av2-strip { gap: 4px; }
        #tab-store .av2-strip .av2-chip { padding-left: 2px !important; padding-right: 2px !important; }
        .Theme--sidebar #tab-store .av2-strip { flex-wrap: wrap !important; }
        .Theme--sidebar #tab-store .av2-strip .av2-chip { flex: 1 1 30% !important; }
        #tab-store .agent-filters { flex: 1 1 auto; }
        #tab-store .agent-filters .filter-btn { flex: 1 1 auto; }
        #tab-store .av2-foot .av2-buttons .av2-btn { flex: 1 1 calc(50% - 4px) !important; }
        #tab-store .av2-panel { padding: 12px; }
        /* Panel rows (Envs, Terminal): the label goes above the field, and
           the (i) help sits beside the field as a 44 px target. Before, the
           120 px label column pushed the field and the (i) past the card. */
        #tab-store .av2-row { grid-template-columns: minmax(0, 1fr) 46px; row-gap: 2px; }
        #tab-store .av2-row > label { grid-column: 1 / -1; align-self: end; padding-top: 6px; }
        #tab-store .av2-row:has(> .av2-row-control) > label { padding-top: 6px; }
        #tab-store .av2-row > :not(label):not(.av2-info) { min-width: 0; }
        #tab-store .av2-row > .av2-info {
            box-sizing: content-box; width: 18px; height: 18px; /* + 2 x 14 px = 46 px (44 px lands on 43.99) */
            border: 14px solid transparent; background-clip: padding-box;
            box-shadow: inset 0 0 0 1px rgba(127,127,127,0.5);
        }
        #tab-store .av2-info[data-tip]::after { max-width: min(280px, 70vw); }
        #tab-store .av2-panel-footer { flex-wrap: wrap; align-items: center; }
        #tab-store .av2-panel-footer .av2-btn { flex: 1 1 100%; }
        #tab-store .av2-row input, #tab-store .av2-row select { height: auto; }
        /* Envs variables and secrets: the name has its own line (it was cut
           to "HUB_GI"), the value and the remove button share the next one. */
        #tab-store .av2-ff-row { flex-wrap: wrap; }
        #tab-store .av2-ff-row .av2-ff-name { flex: 1 1 100%; }
        #tab-store .av2-ff-row .av2-ff-eq { display: none; }
        #tab-store .av2-ff-row .av2-ff-val { flex: 1 1 0; min-width: 0; }
        #tab-store .av2-ff-block h4 { flex-wrap: wrap; }

        /* Debug Console: the one desktop row wraps. The log tabs, the filters
           and the actions each take a full-width line (they ran 170 px past the
           card before); 44 px targets. The Support menu opens under its button,
           inside the console. */
        #tab-debug .log-header { flex-wrap: wrap; height: auto; padding: 4px 6px; gap: 4px 6px; }
        #tab-debug .log-tabs { flex: 1 1 100%; }
        #tab-debug .log-tab { flex: 1 1 0; min-height: 44px; display: flex; align-items: center; justify-content: center; padding: 0 4px; font-size: 11px; line-height: 1.2; }
        #tab-debug #log-filter-row { flex: 1 1 100%; flex-wrap: wrap; }
        #tab-debug #log-filter-row label { min-height: 44px; flex: 1 1 40%; font-size: 11px; }
        #tab-debug #log-filter-row :is(select, input[type="text"]) { flex: 1 1 auto; width: auto !important; min-width: 0 !important; }
        #tab-debug .log-actions { flex: 1 1 100%; justify-content: flex-end; margin-left: 0; }
        #tab-debug .log-action-btn { min-height: 44px; height: auto; box-sizing: border-box; }
        #tab-debug .log-icon-btn { width: 44px !important; flex: 0 0 44px; }
        #tab-debug .log-menu-btn { flex: 0 1 auto; }
        #tab-debug .log-menu { min-width: min(260px, calc(100vw - 48px)); }
        #tab-debug .log-menu .log-menu-item, #tab-debug .log-menu-check { min-height: 44px; font-size: 14px; }
        #tab-debug #autoscroll-status { font-size: 11px; margin-right: auto; }
        #tab-debug .log-body { font-size: 12px; height: min(710px, 75vh) !important; }
    }
</style>
