<?php
/**
 * <module_context>
 * Description: CSS styles for the AICliAgents Terminal and Upload Overlay.
 * Dependencies: Unraid 7.2 Flexbox.
 * Constraints: Atomic CSS (< 100 lines).
 * </module_context>
 */
?>
<style>
/* Layout Resets scoped to terminal page to prevent global CSS pollution */
.aicli-terminal-page, .aicli-terminal-page body {
    overflow: hidden !important; height: 100vh !important; max-height: 100vh !important;
    margin: 0 !important; padding: 0 !important; box-sizing: border-box !important; width: 100% !important;
}
.aicli-terminal-page #displaybox, .aicli-terminal-page #sb-body, .aicli-terminal-page #sb-container, .aicli-terminal-page .gemini-ui, .aicli-terminal-page main, .aicli-terminal-page #main-content, .aicli-terminal-page .main-section, .aicli-terminal-page .main-row, .aicli-terminal-page #form {
    overflow: hidden !important; overflow-y: hidden !important; overflow-x: hidden !important; overflow: clip !important;
    scrollbar-width: none !important; -ms-overflow-style: none !important; scrollbar-gutter: none !important;
    margin: 0 !important; padding: 0 !important; box-sizing: border-box !important; width: 100% !important; max-width: 100% !important; border: none !important;
}
.aicli-terminal-page body::-webkit-scrollbar, .aicli-terminal-page #displaybox::-webkit-scrollbar, .aicli-terminal-page #sb-body::-webkit-scrollbar, .aicli-terminal-page .gemini-ui::-webkit-scrollbar, .aicli-terminal-page main::-webkit-scrollbar, .aicli-terminal-page #main-content::-webkit-scrollbar, .aicli-terminal-page #aicliagents-root::-webkit-scrollbar, .aicli-terminal-page .main-section::-webkit-scrollbar {
    display: none !important; width: 0 !important; height: 0 !important;
}
.aicli-terminal-page div.title, .aicli-terminal-page .title, .aicli-terminal-page .title-row, .aicli-terminal-page .title-bar, .aicli-terminal-page .header-row { display: none !important; }
.aicli-terminal-page #displaybox, .aicli-terminal-page #main-content { position: relative !important; left: 0 !important; right: 0 !important; }
#aicliagents-root { width: 100% !important; display: flex; flex-direction: column; margin: 0 !important; padding: 0 !important; min-height: 400px; box-sizing: border-box !important; overflow: hidden !important; position: relative !important; }

/* Enhanced Upload Overlay Styles */
.aicli-upload-overlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.6); z-index: 2000001;
    display: none; align-items: center; justify-content: center;
    backdrop-filter: blur(8px);
}
.aicli-upload-card {
    background: var(--background-color, #1e1e1e); 
    border: 1px solid var(--border-color, #ff8c00); 
    border-radius: 12px;
    width: 550px; padding: 30px; box-shadow: 0 20px 50px rgba(0,0,0,0.4);
    color: var(--text-color, #eee); text-align: center;
}
.aicli-drop-zone {
    border: 2px dashed #ff8c00; border-radius: 8px; padding: 50px 20px;
    margin: 20px 0; transition: all 0.2s; cursor: pointer;
    background: var(--title-header-background-color, rgba(255,140,0,0.03));
}
.aicli-drop-zone.dragover { border-color: #fff; background: rgba(255,140,0,1); transform: scale(1.02); }
.aicli-upload-progress { height: 6px; background: var(--title-header-background-color, #333); border-radius: 3px; margin-top: 20px; overflow: hidden; display: none; }
.aicli-upload-bar { height: 100%; background: #ff8c00; width: 0%; transition: width 0.2s; }

.aicli-btn {
    padding: 10px 25px; border-radius: 4px; cursor: pointer; font-weight: 700; font-size: 0.95em;
    transition: all 0.15s ease; background: #ff8c00 !important; border: none !important; color: #fff !important;
    text-transform: uppercase; margin-top: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.3);
}
.aicli-btn:hover { background: #e67e00 !important; transform: translateY(-1px); box-shadow: 0 6px 14px rgba(0,0,0,0.4); }
.aicli-btn:active { transform: translateY(0); box-shadow: 0 2px 6px rgba(0,0,0,0.3); }

/* D-404: Hover and active states for React CSS-in-JS buttons (can't define pseudo-classes in JS objects) */
/* These target buttons rendered by React components via the aicli-ui root */
#aicliagents-root button { transition: all 0.15s ease; }
#aicliagents-root button:not(:disabled):hover { filter: brightness(1.1); transform: translateY(-1px); }
#aicliagents-root button:not(:disabled):active { filter: brightness(0.95); transform: translateY(0); }

/* Drawer action buttons (orange) — stronger hover effect */
#aicliagents-root [data-drawer="true"] button:not(:disabled):hover { box-shadow: 0 3px 10px rgba(255,140,0,0.3); }
#aicliagents-root [data-drawer="true"] button:not(:disabled):active { box-shadow: 0 1px 4px rgba(255,140,0,0.2); }

/* Modal footer buttons */
#aicliagents-root button[style*="ff8c00"]:not(:disabled):hover { box-shadow: 0 4px 12px rgba(255,140,0,0.4); }

/* Close tab X icon in drawer */
#aicliagents-root [data-drawer="true"] i.fa-times { transition: all 0.15s; }
#aicliagents-root [data-drawer="true"] i.fa-times:hover { opacity: 1 !important; color: #ff4444; transform: scale(1.2); }

#upload-filename {
    width: 100%; background: var(--background-color, #111) !important; 
    border: 1px solid var(--border-color, #ff8c00) !important; 
    color: var(--text-color, #fff) !important; 
    padding: 10px; border-radius: 4px; font-family: monospace;
}
</style>
