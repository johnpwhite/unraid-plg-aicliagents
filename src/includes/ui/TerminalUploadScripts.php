<?php
/**
 * <module_context>
 * Description: JavaScript logic for file uploads (paste, drag-drop, chunks) in AICliAgents Terminal.
 * Dependencies: jQuery, SweetAlert, $csrf_token.
 * Constraints: Atomic UI fragment.
 * </module_context>
 */
?>
<script>
(function() {
    const overlay = $('#upload-overlay');
    const dropZone = $('#drop-zone');
    const confirmBtn = $('#confirm-upload');
    const filenameInput = $('#upload-filename');
    const previewArea = $('#upload-preview');
    let pendingBlob = null;
    // #41 (docs/specs/TMUX_IMAGE_PASTE_PATH.md): remembers whether the pending
    // upload came from a clipboard paste. Paste-initiated uploads deliver the
    // saved file's path into the active agent's tmux session on success.
    let pendingWasPaste = false;

    function logUpload(msg, level) {
        console.log('[AICli-Upload] ' + msg);
        if (typeof window.aicli_log_to_server === 'function') {
            window.aicli_log_to_server('[Upload] ' + msg, level || 2);
        }
    }

    function showUpload(targetPath) {
        window._aicli_target_path = targetPath || localStorage.getItem('aicli_last_path') || '/mnt/user';
        $('#upload-target-info').text(window._aicli_target_path);
        overlay.css('display', 'flex').hide().fadeIn(200);
        logUpload('Overlay opened. Target: ' + window._aicli_target_path, 3);
    }

    function hideUpload() {
        overlay.fadeOut(200, function() {
            previewArea.hide(); dropZone.show(); confirmBtn.hide();
            pendingBlob = null; $('#upload-progress-container').hide(); $('#upload-bar').css('width', '0%');
            $('#file-input').val('');
        });
    }

    $('#cancel-upload').on('click', function() { logUpload('Upload cancelled by user.', 3); hideUpload(); });

    document.addEventListener('paste', function(e) {
        if (e.target.id === 'upload-filename') return;
        const items = (e.clipboardData || window.clipboardData).items;
        for (let i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image') !== -1 || items[i].kind === 'file') {
                const blob = items[i].getAsFile();
                if (blob) {
                    e.preventDefault(); e.stopPropagation();
                    pendingBlob = blob;
                    logUpload('Paste detected: ' + (blob.name || 'clipboard image') + ' (' + blob.size + ' bytes, ' + blob.type + ')');
                    handleInputFile(blob, true, window._aicli_target_path);
                    break;
                }
            }
        }
    }, true);

    $(window).on('dragover', function(e) { e.preventDefault(); e.stopPropagation(); });
    $(window).on('dragenter', function(e) {
        e.preventDefault(); e.stopPropagation();
        if (e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.types.includes('Files')) showUpload();
    });

    dropZone.on('dragover', function() { $(this).addClass('dragover'); return false; })
            .on('dragleave', function() { $(this).removeClass('dragover'); return false; })
            .on('drop', function(e) {
                $(this).removeClass('dragover');
                const files = e.originalEvent.dataTransfer.files;
                if (files.length > 0) {
                    pendingBlob = files[0];
                    logUpload('Drop detected: ' + pendingBlob.name + ' (' + pendingBlob.size + ' bytes)');
                    handleInputFile(pendingBlob, false);
                }
                return false;
            });

    $('#file-input').on('change', function() {
        if (this.files.length > 0) {
            pendingBlob = this.files[0];
            logUpload('File selected: ' + pendingBlob.name + ' (' + pendingBlob.size + ' bytes)');
            handleInputFile(pendingBlob, false);
            // Reset so re-selecting the same file fires change again
            $(this).val('');
        }
    });

    function handleInputFile(blob, isPaste, path) {
        pendingWasPaste = !!isPaste;
        showUpload(path); dropZone.hide(); previewArea.show();
        let name = blob.name || ('pasted_image_' + new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19) + '.png');
        filenameInput.val(name); confirmBtn.show().text('Confirm Upload');
        if (blob.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = (e) => $('#paste-preview-img').attr('src', e.target.result).show();
            reader.readAsDataURL(blob);
        } else $('#paste-preview-img').hide();
    }

    confirmBtn.on('click', async function() {
        if (!pendingBlob) { logUpload('Confirm clicked but no pending file.', 1); return; }
        var fname = filenameInput.val();
        var path = window._aicli_target_path;
        logUpload('Upload starting: ' + fname + ' (' + pendingBlob.size + ' bytes) to ' + path);

        $('#upload-progress-container').show(); $(this).prop('disabled', true).text('Uploading...');
        try {
            if (pendingBlob.size === 0) throw new Error('File is empty (0 bytes).');
            var res = await uploadFileInChunks(pendingBlob, fname, path);
            logUpload('Upload complete: ' + fname);
            // #41: paste-initiated uploads deliver the saved path to the agent's
            // tmux session (bracketed paste via tmux_paste_text — no clipboard/
            // X11 access server-side). Use the server-confirmed sanitized
            // filename, not the raw input value. The helper returns false when
            // no agent session is active (file still saved, plain toast).
            var savedName = (res && res.filename) ? res.filename : fname;
            var savedPath = String(path).replace(/\/+$/, '') + '/' + savedName;
            var pathSent = pendingWasPaste && window.aicli_paste_saved_path
                ? window.aicli_paste_saved_path(savedPath) === true
                : false;
            hideUpload();
            setTimeout(function() {
                var msg = pathSent
                    ? savedName + " saved — path sent to terminal."
                    : savedName + " saved to workspace.";
                swal({ title: "Uploaded!", text: msg, type: "success", timer: 2000, showConfirmButton: false });
            }, 300);
        } catch (err) {
            logUpload('Upload FAILED: ' + (err.message || err), 0);
            hideUpload();
            setTimeout(function() { swal("Upload Failed", err.message || 'Unknown error', "error"); }, 300);
        }
        finally { $(this).prop('disabled', false).text('Confirm Upload'); }
    });

    async function uploadFileInChunks(file, filename, path) {
        if (!path) throw new Error('No workspace path set. Open a workspace first.');
        if (!filename) throw new Error('No filename specified.');

        // D-405: Unraid nginx hangs all POST requests (fetch and XHR) to standalone PHP scripts.
        // Use base64 encoding via jQuery $.ajax (which Unraid uses for its own file operations).
        logUpload('Reading file as base64 (' + file.size + ' bytes)...', 3);

        var b64data = await new Promise(function(resolve, reject) {
            var reader = new FileReader();
            reader.onload = function() {
                var result = reader.result;
                // Strip Data URL prefix if present
                var idx = result.indexOf(',');
                resolve(idx >= 0 ? result.substring(idx + 1) : result);
            };
            reader.onerror = function() { reject(new Error('Failed to read file')); };
            reader.readAsDataURL(file);
        });

        logUpload('Base64 encoded: ' + b64data.length + ' chars. Sending via $.ajax...', 3);
        $('#upload-bar').css('width', '50%');

        var res = await new Promise(function(resolve, reject) {
            $.ajax({
                url: '/plugins/unraid-aicliagents/AICliAjax.php?action=save_file&csrf_token=' + encodeURIComponent(window.csrf_token),
                method: 'POST',
                data: {
                    csrf_token: window.csrf_token,
                    path: path,
                    filename: filename,
                    filedata: b64data
                },
                dataType: 'json',
                timeout: 60000,
                success: function(data) {
                    logUpload('Server response: ' + JSON.stringify(data), 3);
                    resolve(data);
                },
                error: function(xhr, status, err) {
                    logUpload('$.ajax error: ' + status + ' / ' + err + ' / HTTP ' + xhr.status, 0);
                    reject(new Error('Upload failed: ' + (err || status)));
                }
            });
        });

        if (res.status !== 'ok') {
            logUpload('Server rejected: ' + JSON.stringify(res), 0);
            throw new Error(res.message || 'Server rejected the upload');
        }
        $('#upload-bar').css('width', '100%');
        return res;
    }

    /**
     * #41: DIRECT image paste — no upload overlay.
     *
     * Ctrl+V of an image in the terminal saves it silently into the workspace
     * under an auto-generated timestamped name, then bracketed-pastes the saved
     * path into the agent's tmux session (via aicli_paste_saved_path). The agent
     * reads the image from that path — it never touches the clipboard, so the
     * headless-server X11/xclip error can't occur.
     *
     * Explicit uploads (drag-drop, the Upload button, non-image pastes) keep the
     * overlay + confirm step; only a pasted IMAGE takes this silent path.
     */
    async function pasteImageDirect(blob, path) {
        if (!blob || !path) return false;
        // Always timestamp pasted images: clipboard blobs are usually named
        // "image.png", which would silently overwrite the previous paste.
        var name = 'pasted_image_' + new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19) + '.png';
        logUpload('Direct image paste: ' + blob.size + ' bytes -> ' + path + '/' + name);
        try {
            if (blob.size === 0) throw new Error('Pasted image is empty (0 bytes).');
            var res = await uploadFileInChunks(blob, name, path);
            var savedName = (res && res.filename) ? res.filename : name;
            var savedPath = String(path).replace(/\/+$/, '') + '/' + savedName;
            // Delivers the path + toasts. Returns false when no agent session is
            // active — the image is still on disk, so tell the user where it went.
            var sent = window.aicli_paste_saved_path
                ? window.aicli_paste_saved_path(savedPath) === true
                : false;
            if (!sent) {
                swal({ title: "Image saved", text: savedName + " saved to workspace.", type: "success", timer: 2000, showConfirmButton: false });
            }
            return sent;
        } catch (err) {
            logUpload('Direct image paste FAILED: ' + (err.message || err), 0);
            swal("Paste Failed", err.message || 'Could not save the pasted image', "error");
            return false;
        }
    }

    window.aicli_trigger_upload = showUpload;
    window.aicli_handle_file = handleInputFile;
    window.aicli_paste_image_direct = pasteImageDirect;
})();
</script>
