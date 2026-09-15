<?php
/**
 * <module_context>
 * Description: JavaScript logic for file uploads (paste, drag-drop, chunks) in AICliAgents Terminal.
 * Dependencies: jQuery, SweetAlert, $csrf_token.
 * Constraints: Atomic UI fragment.
 * </module_context>
 *
 * docs/specs/WORKSPACE_UPLOAD_MULTI_CHUNKED.md (2026-09-12) rewrote this
 * fragment for three reported faults: the overlay used to hide()/fadeIn()
 * on every window `dragenter`, closing itself while a file was still being
 * dragged; only the first of several picked/dropped files was ever sent; and
 * a big file was sent as one base64 POST field, which silently exceeded
 * PHP's post_max_size. See that spec for the full R1-R8 requirement list and
 * the two earlier upload specs it extends (UPLOAD_TARGET_ACTIVE_WORKSPACE.md,
 * DRAWER_UPLOAD_PATH_TO_CLIPBOARD.md).
 */
?>
<script>
(function() {
    const overlay = $('#upload-overlay');
    const dropZone = $('#drop-zone');
    const confirmBtn = $('#confirm-upload');
    const filenameInput = $('#upload-filename');
    const previewArea = $('#upload-preview');
    // R2: the pending upload is now a LIST, not a single blob. Sequential
    // upload runs off this array; a failed file keeps everything from that
    // file onward pending (see the confirm handler's catch block).
    let pendingFiles = [];
    // #41 (docs/specs/TMUX_IMAGE_PASTE_PATH.md): remembers whether the pending
    // upload came from a clipboard paste. Paste-initiated uploads deliver the
    // saved file's path into the active agent's tmux session on success.
    let pendingWasPaste = false;
    // R3: {post_max_bytes, chunk_bytes, max_file_bytes} — fetched once per
    // overlay open (cleared in hideUpload()) so every file in one run uses
    // the same limits without re-asking the server per file.
    let uploadLimits = null;
    // Logging (Decisions table): a best-effort label for WHY the overlay is
    // opening, read once by showUpload() when it actually opens. Calls that
    // arrive through window.aicli_trigger_upload (the drawer row menu, and
    // the ttyd-iframe AICLI_DRAG_ENTER bridge — both pass a plain path with
    // no way to tell them apart across the postMessage boundary) default to
    // 'drawer'; our own document-level drag counter and the paste listener
    // set a more specific reason immediately before they open the overlay.
    let _openReason = 'drawer';

    // DRAWER_UPLOAD_PATH_TO_CLIPBOARD: copy text, never throw. Secure-context
    // clipboard API first (https/localhost); plain-HTTP Unraid has no
    // navigator.clipboard, so fall back to the hidden-textarea execCommand copy.
    function aicliCopyTextToClipboard(text) {
        function legacy() {
            try {
                var ta = document.createElement("textarea");
                ta.value = text; ta.setAttribute("readonly", "");
                ta.style.position = "fixed"; ta.style.left = "-9999px"; ta.style.top = "0";
                document.body.appendChild(ta); ta.select();
                var ok = document.execCommand("copy");
                document.body.removeChild(ta);
                return ok;
            } catch (e) { return false; }
        }
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(function() { return true; }, function() { return legacy(); });
        }
        return Promise.resolve(legacy());
    }

    // Wraps a path in single quotes (POSIX shell) when it holds a space or a
    // quote character; used only for the one bracketed paste sent to the
    // terminal when several files were pasted/dropped at once.
    function aicliQuoteForShell(p) {
        return /[\s'"]/.test(p) ? ("'" + String(p).replace(/'/g, "'\\''") + "'") : p;
    }

    function fmtUploadBytes(n) {
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / 1024 / 1024).toFixed(1) + ' MB';
    }

    function logUpload(msg, level) {
        console.log('[AICli-Upload] ' + msg);
        if (typeof window.aicli_log_to_server === 'function') {
            window.aicli_log_to_server('[Upload] ' + msg, level || 2);
        }
    }

    // UPLOAD_TARGET_ACTIVE_WORKSPACE (#178): the drawer row menu passes an explicit
    // target for THIS open only (uploadTarget); drag-and-drop and paste fall back to
    // window._aicli_target_path, which the React app keeps equal to the ACTIVE
    // workspace path. Never default to /mnt/user — with no workspace open, refuse.
    var uploadTarget = '';
    function currentUploadTarget() { return uploadTarget || window._aicli_target_path || ''; }
    // R1: idempotent while the overlay is already open — a drag crossing a
    // child element must never re-trigger hide()+fadeIn(); only refresh the
    // shown target when one was actually passed.
    function showUpload(targetPath) {
        if (overlay.is(':visible')) {
            if (targetPath) { uploadTarget = targetPath; $('#upload-target-info').text(currentUploadTarget()); }
            return;
        }
        uploadTarget = targetPath || '';
        var target = currentUploadTarget();
        if (!target) {
            uploadTarget = '';
            logUpload('Upload refused: no workspace open (no target path).', 1);
            swal('No workspace open', 'Open a workspace first, then upload into it.', 'warning');
            return;
        }
        $('#upload-target-info').text(target);
        overlay.css('display', 'flex').hide().fadeIn(200);
        logUpload('Overlay opened (reason: ' + _openReason + '). Target: ' + target, 2);
    }

    function hideUpload() {
        overlay.fadeOut(200, function() {
            previewArea.hide(); dropZone.show(); confirmBtn.hide();
            pendingFiles = []; pendingWasPaste = false; uploadLimits = null;
            $('#upload-progress-container').hide(); $('#upload-bar').css('width', '0%');
            $('#upload-progress-label').hide().text('');
            $('#upload-file-list').empty();
            $('#file-name-input-area').hide();
            filenameInput.val('');
            $('#file-input').val('');
            uploadTarget = ''; // #178: an explicit drawer target must not leak into later drag/paste uploads
            dragDepth = 0;
            if (dragLeaveTimer) { clearTimeout(dragLeaveTimer); dragLeaveTimer = null; }
        });
    }

    $('#cancel-upload').on('click', function() { logUpload('Upload cancelled by user.', 2); hideUpload(); });

    // ------------------------------------------------------------------
    // R1: document-level drag enter/leave counter. A drag that crosses a
    // child element fires enter+leave pairs constantly; only the NET depth
    // (0 -> not dragging, >0 -> dragging over the page) matters. Leaving the
    // window with no drop brings the counter back to 0, and after a 300 ms
    // grace (in case the drag re-enters, e.g. crossing an iframe boundary)
    // the overlay closes itself.
    // ------------------------------------------------------------------
    var dragDepth = 0;
    var dragLeaveTimer = null;

    $(document).on('dragover', function(e) { e.preventDefault(); e.stopPropagation(); });
    $(document).on('dragenter', function(e) {
        e.preventDefault(); e.stopPropagation();
        if (!(e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.types.includes('Files'))) return;
        if (dragLeaveTimer) { clearTimeout(dragLeaveTimer); dragLeaveTimer = null; }
        dragDepth++;
        if (dragDepth === 1) { _openReason = 'drag'; showUpload(); }
    });
    $(document).on('dragleave', function(e) {
        e.preventDefault(); e.stopPropagation();
        dragDepth = Math.max(0, dragDepth - 1);
        if (dragDepth !== 0) return;
        if (dragLeaveTimer) clearTimeout(dragLeaveTimer);
        dragLeaveTimer = setTimeout(function() {
            dragLeaveTimer = null;
            if (dragDepth === 0 && overlay.is(':visible') && pendingFiles.length === 0) {
                logUpload('Drag left the window; closing overlay.', 2);
                hideUpload();
            }
        }, 300);
    });
    $(document).on('drop', function(e) {
        e.preventDefault(); e.stopPropagation();
        dragDepth = 0;
        if (dragLeaveTimer) { clearTimeout(dragLeaveTimer); dragLeaveTimer = null; }
        // The overlay is a full-viewport backdrop while shown, so any drop
        // anywhere on the page while it is open IS a drop "on the overlay".
        if (!overlay.is(':visible')) return;
        var files = e.originalEvent.dataTransfer ? e.originalEvent.dataTransfer.files : null;
        if (files && files.length > 0) {
            logUpload('Drop detected: ' + files.length + ' file(s).', 2);
            handleInputFiles(files, false);
        }
    });

    // Visual highlight only now — the drop itself is handled document-wide above.
    dropZone.on('dragover', function(e) { e.preventDefault(); $(this).addClass('dragover'); })
            .on('dragleave', function(e) { e.preventDefault(); $(this).removeClass('dragover'); });

    document.addEventListener('paste', function(e) {
        if (e.target.id === 'upload-filename') return;
        const items = (e.clipboardData || window.clipboardData).items;
        for (let i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image') !== -1 || items[i].kind === 'file') {
                const blob = items[i].getAsFile();
                if (blob) {
                    e.preventDefault(); e.stopPropagation();
                    logUpload('Paste detected: ' + (blob.name || 'clipboard image') + ' (' + blob.size + ' bytes, ' + blob.type + ')');
                    _openReason = 'paste';
                    handleInputFile(blob, true, currentUploadTarget());
                    break;
                }
            }
        }
    }, true);

    $('#file-input').on('change', function() {
        if (this.files.length > 0) {
            logUpload('Files selected: ' + this.files.length, 2);
            handleInputFiles(this.files, false);
            // Reset so re-selecting the same file fires change again
            $(this).val('');
        }
    });

    window.aicli_trigger_upload = function(path) { _openReason = 'drawer'; showUpload(path); };

    // ------------------------------------------------------------------
    // R2: pending-file list — render, add, remove.
    // ------------------------------------------------------------------
    function defaultPasteName() {
        return 'pasted_image_' + new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19) + '.png';
    }

    function renderFileList() {
        var list = $('#upload-file-list').empty();
        pendingFiles.forEach(function(f, idx) {
            var row = $('<div>').css({ display: 'flex', alignItems: 'center', gap: '10px', padding: '6px 4px', borderBottom: '1px solid rgba(255,255,255,0.08)' });
            if (f.type && f.type.indexOf('image/') === 0) {
                var thumb = $('<img alt="">').css({ width: '32px', height: '32px', objectFit: 'cover', borderRadius: '4px', flexShrink: '0' });
                var reader = new FileReader();
                reader.onload = function(e) { thumb.attr('src', e.target.result); };
                reader.readAsDataURL(f);
                row.append(thumb);
            } else {
                row.append($('<i class="fa fa-file-o"></i>').css({ width: '32px', textAlign: 'center', flexShrink: '0', opacity: '0.6' }));
            }
            row.append($('<div>').css({ flex: '1', minWidth: '0', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', fontSize: '12px' }).text(f.name || 'pasted image'));
            row.append($('<div>').css({ fontSize: '10px', opacity: '0.6', flexShrink: '0' }).text(fmtUploadBytes(f.size)));
            var rm = $('<button type="button">&times;</button>').attr('aria-label', 'Remove file')
                .css({ background: 'none', border: 'none', color: '#f87171', cursor: 'pointer', fontSize: '16px', flexShrink: '0' });
            rm.on('click', function() { removePendingFile(idx); });
            row.append(rm);
            list.append(row);
        });

        if (pendingFiles.length === 1) {
            filenameInput.val(pendingFiles[0].name || defaultPasteName());
            $('#file-name-input-area').show();
        } else {
            $('#file-name-input-area').hide();
        }
    }

    function removePendingFile(idx) {
        pendingFiles.splice(idx, 1);
        logUpload('Removed pending file at position ' + idx + '. ' + pendingFiles.length + ' remaining.', 2);
        if (pendingFiles.length === 0) { hideUpload(); return; }
        renderFileList();
    }

    // Edge case: the same file chosen twice adds two rows — no dedupe.
    function handleInputFiles(fileList, isPaste, path) {
        pendingWasPaste = !!isPaste;
        var files = Array.prototype.slice.call(fileList || []);
        if (files.length === 0) return;
        showUpload(path);
        dropZone.hide(); previewArea.show();
        for (var i = 0; i < files.length; i++) pendingFiles.push(files[i]);
        var totalBytes = pendingFiles.reduce(function(s, f) { return s + f.size; }, 0);
        logUpload('Files chosen: ' + files.length + ' (now ' + pendingFiles.length + ' pending, ' + totalBytes + ' bytes total).', 2);
        renderFileList();
        confirmBtn.show().text('Confirm Upload');
    }

    function handleInputFile(blob, isPaste, path) {
        handleInputFiles([blob], isPaste, path);
    }

    // ------------------------------------------------------------------
    // R3/R4: chunked transport. D-405: base64 fields through jQuery $.ajax
    // POST only — never multipart $_FILES, never fetch (nginx hangs POSTs to
    // standalone PHP scripts for fetch/XHR on this box).
    // ------------------------------------------------------------------
    function readAsBase64(blob) {
        return new Promise(function(resolve, reject) {
            var reader = new FileReader();
            reader.onload = function() {
                var result = reader.result;
                var idx = result.indexOf(',');
                resolve(idx >= 0 ? result.substring(idx + 1) : result);
            };
            reader.onerror = function() { reject(new Error('Failed to read file')); };
            reader.readAsDataURL(blob);
        });
    }

    function randomUploadId() {
        var bytes = new Uint8Array(8);
        (window.crypto || window.msCrypto).getRandomValues(bytes);
        return Array.prototype.map.call(bytes, function(b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }

    // R5: HTTP status + first 200 chars of a non-JSON body; a definite
    // transport failure (timeout, or a request that never reached the
    // server) is flagged so the chunk loop can retry it once.
    function postUpload(action, data, timeoutMs) {
        return new Promise(function(resolve, reject) {
            $.ajax({
                url: '/plugins/unraid-aicliagents/AICliAjax.php?action=' + action + '&csrf_token=' + encodeURIComponent(window.csrf_token),
                method: 'POST',
                data: data,
                dataType: 'json',
                timeout: timeoutMs || 60000,
                success: function(res) { resolve(res); },
                error: function(xhr, status, err) {
                    logUpload('$.ajax error: ' + status + ' / ' + err + ' / HTTP ' + xhr.status, 0);
                    var raw = xhr && xhr.responseText;
                    var isJson = false;
                    if (raw) { try { JSON.parse(raw); isJson = true; } catch (e) { isJson = false; } }
                    var msg = isJson
                        ? ('Upload failed: ' + (err || status) + ' (HTTP ' + xhr.status + ')')
                        : ('The server answered with HTML instead of JSON (HTTP ' + xhr.status + '): ' + String(raw || '').slice(0, 200));
                    var e2 = new Error(msg);
                    e2._transportError = (status === 'timeout') || (status === 'error' && (!xhr.status || xhr.status === 0));
                    reject(e2);
                }
            });
        });
    }

    function fetchUploadLimits() {
        if (uploadLimits) return Promise.resolve(uploadLimits);
        return new Promise(function(resolve, reject) {
            $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_upload_limits&csrf_token=' + encodeURIComponent(window.csrf_token))
                .done(function(data) {
                    if (data && data.status === 'ok') { uploadLimits = data; resolve(data); }
                    else reject(new Error((data && data.message) || 'Could not read the server upload limits.'));
                })
                .fail(function(xhr) { reject(new Error('Could not read the server upload limits (HTTP ' + xhr.status + ').')); });
        });
    }

    function uploadOneFileWhole(file, filename, path, onProgress) {
        logUpload('Sending "' + filename + '" in one request (' + file.size + ' bytes).', 3);
        return readAsBase64(file).then(function(b64data) {
            return postUpload('save_file', {
                csrf_token: window.csrf_token,
                path: path,
                filename: filename,
                filedata: b64data
            }, 60000);
        }).then(function(res) {
            if (res.status !== 'ok') throw new Error(res.message || 'Server rejected the upload');
            if (onProgress) onProgress(file.size);
            return res;
        });
    }

    function uploadOneFileChunked(file, filename, path, limits, onProgress) {
        var uploadId = randomUploadId();
        var chunkBytes = limits.chunk_bytes;
        var totalChunks = Math.max(1, Math.ceil(file.size / chunkBytes));
        logUpload('Sending "' + filename + '" in ' + totalChunks + ' chunk(s) of up to ' + chunkBytes + ' bytes (uploadId ' + uploadId + ').', 3);

        function sendChunk(index, attempt) {
            var start = index * chunkBytes;
            var end = Math.min(file.size, start + chunkBytes);
            return readAsBase64(file.slice(start, end)).then(function(b64) {
                return postUpload('save_file_chunk', {
                    csrf_token: window.csrf_token,
                    path: path,
                    filename: filename,
                    uploadId: uploadId,
                    chunkIndex: index,
                    totalChunks: totalChunks,
                    filedata: b64
                }, 60000).then(function(res) {
                    if (res.status !== 'ok') throw new Error(res.message || 'Server rejected the chunk');
                    if (onProgress) onProgress(end);
                    return res;
                }).catch(function(err) {
                    if (err._transportError && attempt < 1) {
                        logUpload('Retrying chunk ' + index + ' of "' + filename + '" after a transport error.', 2);
                        return sendChunk(index, attempt + 1);
                    }
                    throw err;
                });
            });
        }

        function loop(index) {
            return sendChunk(index, 0).then(function(res) {
                if (res.complete) return res;
                return loop(index + 1);
            });
        }

        return loop(0);
    }

    async function uploadOneFile(file, filename, path, limits, onProgress) {
        return (file.size <= limits.chunk_bytes)
            ? uploadOneFileWhole(file, filename, path, onProgress)
            : uploadOneFileChunked(file, filename, path, limits, onProgress);
    }

    // ------------------------------------------------------------------
    // Confirm: uploads every pending file in order. A failure on file N
    // stops the run, reports what was saved, and leaves file N onward
    // pending so the operator can retry (or remove a bad file and retry
    // the rest).
    // ------------------------------------------------------------------
    confirmBtn.on('click', async function() {
        if (!pendingFiles.length) { logUpload('Confirm clicked but no pending files.', 1); return; }
        var path = currentUploadTarget();
        if (!path) { swal('No workspace open', 'Open a workspace first, then upload into it.', 'warning'); return; }
        var singleName = (pendingFiles.length === 1) ? (filenameInput.val() || pendingFiles[0].name || defaultPasteName()) : null;

        $('#upload-progress-container').show();
        $('#upload-progress-label').show();
        $(this).prop('disabled', true).text('Uploading...');

        var totalBytes = pendingFiles.reduce(function(s, f) { return s + f.size; }, 0);
        var sentBeforeCurrent = 0;
        var savedPaths = [];

        logUpload('Upload starting: ' + pendingFiles.length + ' file(s), ' + totalBytes + ' bytes total, to ' + path, 2);

        try {
            var limits = await fetchUploadLimits();

            for (var i = 0; i < pendingFiles.length; i++) {
                var file = pendingFiles[i];
                if (file.size === 0) { var e0 = new Error('"' + (file.name || 'file') + '" is empty (0 bytes).'); e0._fileIndex = i; throw e0; }
                if (limits.max_file_bytes > 0 && file.size > limits.max_file_bytes) {
                    var e1 = new Error('"' + (file.name || 'file') + '" is ' + fmtUploadBytes(file.size) + ', over the ' + fmtUploadBytes(limits.max_file_bytes) + ' upload limit.');
                    e1._fileIndex = i;
                    throw e1;
                }
                var name = (pendingFiles.length === 1) ? singleName : file.name;
                $('#upload-progress-label').text((i + 1) + ' of ' + pendingFiles.length + ' — ' + name);
                logUpload('File ' + (i + 1) + '/' + pendingFiles.length + ' starting: ' + name + ' (' + file.size + ' bytes)', 2);

                var base = sentBeforeCurrent;
                var onProgress = function(sent) {
                    var overall = totalBytes > 0 ? (base + sent) / totalBytes : 1;
                    $('#upload-bar').css('width', Math.round(overall * 100) + '%');
                };

                var res;
                try {
                    res = await uploadOneFile(file, name, path, limits, onProgress);
                } catch (fileErr) {
                    fileErr._fileIndex = i;
                    throw fileErr;
                }

                var savedName = res.filename || name;
                var savedPath = String(path).replace(/\/+$/, '') + '/' + savedName;
                savedPaths.push(savedPath);
                sentBeforeCurrent += file.size;
                logUpload('File ' + (i + 1) + '/' + pendingFiles.length + ' done: ' + savedName + ' (' + file.size + ' bytes) -> ' + savedPath, 2);
            }

            logUpload('Upload complete: ' + savedPaths.length + ' file(s).', 2);
            pendingFiles = [];

            var savedPath = savedPaths.join('\n');
            var pathSent = pendingWasPaste && window.aicli_paste_saved_path
                ? window.aicli_paste_saved_path(savedPaths.map(aicliQuoteForShell).join(' ')) === true
                : false;
            hideUpload();
            if (pendingWasPaste) {
                setTimeout(function() {
                    var msg = pathSent
                        ? (savedPaths.length + " file(s) saved — path sent to terminal.")
                        : (savedPaths.length + " file(s) saved to workspace.");
                    swal({ title: "Uploaded!", text: msg, type: "success", timer: 2000, showConfirmButton: false });
                }, 300);
            } else {
                // DRAWER_UPLOAD_PATH_TO_CLIPBOARD: copies the saved path(s) to the
                // clipboard (one per line for several files); falls back to just
                // showing the path if the clipboard is unavailable.
                var copied = await aicliCopyTextToClipboard(savedPath);
                setTimeout(function() {
                    swal({
                        title: copied ? "File path copied to clipboard" : "Uploaded",
                        text: savedPath,
                        type: "success",
                        timer: 3000,
                        showConfirmButton: true,
                        confirmButtonText: "OK"
                    });
                }, 300);
            }
        } catch (err) {
            var idx = (typeof err._fileIndex === 'number') ? err._fileIndex : 0;
            // R2: keep file `idx` onward pending (it failed, but is retried,
            // not dropped) — only the successfully-saved prefix is removed.
            pendingFiles = pendingFiles.slice(idx);
            logUpload('Upload FAILED: ' + (err.message || err), 0);
            renderFileList();
            $('#upload-progress-container').hide();
            $('#upload-progress-label').hide();
            var failMsg = err.message || String(err);
            if (savedPaths.length > 0) failMsg = savedPaths.length + ' file(s) saved. ' + failMsg;
            setTimeout(function() { swal("Upload Failed", failMsg, "error"); }, 300);
        }
        finally { $(this).prop('disabled', false).text('Confirm Upload'); }
    });

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
        var name = defaultPasteName();
        logUpload('Direct image paste: ' + blob.size + ' bytes -> ' + path + '/' + name);
        try {
            if (blob.size === 0) throw new Error('Pasted image is empty (0 bytes).');
            var limits = await fetchUploadLimits();
            if (limits.max_file_bytes > 0 && blob.size > limits.max_file_bytes) {
                throw new Error('Pasted image is ' + fmtUploadBytes(blob.size) + ', over the ' + fmtUploadBytes(limits.max_file_bytes) + ' upload limit.');
            }
            var res = await uploadOneFile(blob, name, path, limits);
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

    window.aicli_handle_file = handleInputFile;
    window.aicli_paste_image_direct = pasteImageDirect;
})();
</script>
