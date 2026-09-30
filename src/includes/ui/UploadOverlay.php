<?php
/**
 * <module_context>
 * Description: HTML layout for the AICliAgents drag-and-drop file upload overlay.
 * Dependencies: TerminalStyles.php.
 * Constraints: Atomic UI fragment (< 70 lines).
 * </module_context>
 *
 * docs/specs/WORKSPACE_UPLOAD_MULTI_CHUNKED.md: the preview area now holds a
 * LIST of pending files (#upload-file-list), one row per file, built by
 * TerminalUploadScripts.php — not a single image. The filename field
 * (#file-name-input-area) is shown by that script only when exactly one file
 * is pending. #upload-progress-label carries the "n of m — name" text; the
 * bar underneath it tracks bytes across the whole run.
 *
 * Epic #307: the <style> below makes the overlay fit a phone (<= 640 px): the
 * card fills the screen, the body scrolls, every button is a 44 px target.
 * Desktop keeps TerminalStyles.php's 550 px card unchanged.
 */
?>
<style>
@media (max-width: 640px) {
    #upload-overlay.aicli-upload-overlay { align-items: stretch; backdrop-filter: none; }
    #upload-overlay .aicli-upload-card {
        width: 100%; max-width: 100%; max-height: 100%; box-sizing: border-box;
        overflow-y: auto; border-radius: 0; border: 0; padding: 16px;
        padding-top: calc(16px + env(safe-area-inset-top, 0px));
        padding-bottom: calc(16px + env(safe-area-inset-bottom, 0px));
    }
    #upload-overlay .aicli-drop-zone { padding: 24px 12px; margin: 12px 0; }
    #upload-overlay .aicli-upload-actions { flex-wrap: wrap; }
    #upload-overlay .aicli-upload-actions button { min-height: 44px; min-width: 44px !important; margin: 0; flex: 1 1 120px; }
    #upload-overlay #upload-file-list button { min-width: 44px; min-height: 44px; margin: 0; }
}
/* Every width: the card never runs wider than the window. */
#upload-overlay .aicli-upload-card { max-width: calc(100vw - 16px); box-sizing: border-box; }
</style>
<div id="upload-overlay" class="aicli-upload-overlay" role="dialog" aria-modal="true" aria-labelledby="upload-overlay-title">
    <div class="aicli-upload-card">
        <h3 id="upload-overlay-title" style="margin:0; font-size:1.4em;"><i class="fa fa-cloud-upload"></i> Upload to Workspace</h3>
        <p id="upload-target-info" style="font-size:12px; opacity:0.85; margin-top:8px; font-family:monospace;"></p>

        <div id="drop-zone" class="aicli-drop-zone" onclick="document.getElementById('file-input').click()">
            <i class="fa fa-file-image-o fa-4x" style="color:#ff8c00; opacity:0.5;"></i>
            <p style="margin-top:20px; font-size:1.1em;">Drag & Drop files here</p>
            <p style="font-size:0.9em; opacity:0.85;">or click to browse, or <b>Paste (Ctrl+V)</b> any image</p>
            <input type="file" id="file-input" style="display:none" multiple aria-label="Select files to upload">
        </div>

        <div id="upload-preview" style="display:none; margin-bottom:20px; text-align:left;">
            <div style="font-size:10px; text-transform:uppercase; color:#ff8c00; margin-bottom:8px; font-weight:bold; text-align:center;">Upload Preview</div>
            <div id="upload-file-list" style="max-height:220px; overflow-y:auto;"></div>
            <div id="file-name-input-area" style="margin-top:15px; display:none;">
                <label for="upload-filename" class="sr-only">Filename</label>
                <input type="text" id="upload-filename" placeholder="filename.png" aria-label="Upload filename" style="width:100%; background:var(--background-color, #111); border:1px solid var(--orange, #ff8c00); color:var(--text-color, #fff); padding:10px; border-radius:4px; font-family:monospace;">
            </div>
        </div>

        <div id="upload-progress-label" style="display:none; font-size:11px; opacity:0.75; margin-top:16px; text-align:left; font-family:monospace;"></div>
        <div id="upload-progress-container" class="aicli-upload-progress"><div id="upload-bar" class="aicli-upload-bar"></div></div>

        <div class="aicli-upload-actions" style="display:flex; gap:12px; margin-top:25px; justify-content:center;">
            <button id="cancel-upload" class="aicli-btn" style="background:#444 !important; min-width:120px;">Cancel</button>
            <button id="confirm-upload" class="aicli-btn" style="display:none; min-width:120px;">Upload Now</button>
        </div>
    </div>
</div>
