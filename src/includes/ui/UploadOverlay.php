<?php
/**
 * <module_context>
 * Description: HTML layout for the AICliAgents drag-and-drop file upload overlay.
 * Dependencies: TerminalStyles.php.
 * Constraints: Atomic UI fragment (< 50 lines).
 * </module_context>
 */
?>
<div id="upload-overlay" class="aicli-upload-overlay">
    <div class="aicli-upload-card">
        <h3 style="margin:0; font-size:1.4em;"><i class="fa fa-cloud-upload"></i> Upload to Workspace</h3>
        <p id="upload-target-info" style="font-size:12px; opacity:0.7; margin-top:8px; font-family:monospace;"></p>
        
        <div id="drop-zone" class="aicli-drop-zone" onclick="document.getElementById('file-input').click()">
            <i class="fa fa-file-image-o fa-4x" style="color:#ff8c00; opacity:0.5;"></i>
            <p style="margin-top:20px; font-size:1.1em;">Drag & Drop files here</p>
            <p style="font-size:0.9em; opacity:0.6;">or click to browse, or <b>Paste (Ctrl+V)</b> any image</p>
            <input type="file" id="file-input" style="display:none" multiple aria-label="Select files to upload">
        </div>

        <div id="upload-preview" style="display:none; margin-bottom:20px;">
            <div style="font-size:10px; text-transform:uppercase; color:#ff8c00; margin-bottom:8px; font-weight:bold;">Upload Preview</div>
            <img id="paste-preview-img" style="max-width:100%; max-height:200px; border-radius:6px; border:1px solid #444; box-shadow: 0 4px 15px rgba(0,0,0,0.5);">
            <div id="file-name-input-area" style="margin-top:15px;">
                <label for="upload-filename" class="sr-only">Filename</label>
                <input type="text" id="upload-filename" placeholder="filename.png" aria-label="Upload filename" style="width:100%; background:#111; border:1px solid var(--orange, #ff8c00); color:#fff; padding:10px; border-radius:4px; font-family:monospace;">
            </div>
        </div>

        <div id="upload-progress-container" class="aicli-upload-progress"><div id="upload-bar" class="aicli-upload-bar"></div></div>
        
        <div style="display:flex; gap:12px; margin-top:25px; justify-content:center;">
            <button id="cancel-upload" class="aicli-btn" style="background:#444 !important; min-width:120px;">Cancel</button>
            <button id="confirm-upload" class="aicli-btn" style="display:none; min-width:120px;">Upload Now</button>
        </div>
    </div>
</div>
