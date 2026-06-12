jQuery(document).ready(function ($) {

    getVideoFiles();
    initVideoUploader();

    function updateLiveInputStatus() {
        var ids = $('.hscf-ph-btn').map(function () { return $(this).attr('data-input-id'); }).get();
        if (!ids.length) return;
        $.ajax({
            url: hscf_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'hscf_check_live_input_status',
                _wpnonce: hscf_ajax.nonce,
                ids: ids
            },
            success: function (response) {
                if (!response || !response.success) return;
                $.each(response.data, function (uid, info) {
                    var status = (info && typeof info === 'object') ? info.status : info;
                    var $badge = $('#badge-' + uid);
                    if (!$badge.length) return;
                    var connected = (status === 'connected');
                    var disconnected = (status === 'disconnected');
                    $badge.removeClass('hscf-badge--live hscf-badge--off hscf-badge--unknown')
                          .addClass(connected ? 'hscf-badge--live' : (disconnected ? 'hscf-badge--off' : 'hscf-badge--unknown'));
                    $badge.find('.dashicons').attr('class', 'dashicons ' + (connected ? 'dashicons-controls-play' : 'dashicons-controls-pause'));
                    $badge.find('.hscf-badge__text').text(status);
                });
            }
        });
    }

    function initVideoUploader() {
        $('#video-upload-form').off('submit').on('submit', function (e) {
            e.preventDefault();
            var fileInput = $('#video-upload-form input[type=file]')[0];
            var file = fileInput && fileInput.files[0];
            if (!file) { sendToModal('Choose a video file first.'); return; }
            if (!/\.(mp4|mov|m4v|mkv|webm|avi)$/i.test(file.name)) {
                sendToModal('Unsupported file type. Upload mp4, mov, m4v, mkv, webm, or avi.');
                return;
            }

            var $cont = $('#progress-container'), $bar = $('#progress-bar'), $pct = $('#upload-percentage');
            $cont.show(); $bar.css('width', '0%'); $pct.text('Uploading… 0%');

            var fd = new FormData();
            fd.append('action', 'hscf_upload_video');
            fd.append('_wpnonce', hscf_ajax.nonce);
            fd.append('video_file', file);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', hscf_ajax.ajax_url, true);
            // Progress reflects the browser→WordPress leg; once that's at 100%,
            // WordPress is uploading on to Cloudflare (TUS) before it responds.
            xhr.upload.onprogress = function (ev) {
                if (!ev.lengthComputable) return;
                var p = Math.round(ev.loaded / ev.total * 100);
                $bar.css('width', p + '%');
                $pct.text(p < 100 ? ('Uploading… ' + p + '%') : 'Sending to Cloudflare…');
            };
            xhr.onload = function () {
                var resp = null; try { resp = JSON.parse(xhr.responseText); } catch (err) {}
                if (xhr.status === 200 && resp && resp.success) {
                    $bar.css('width', '100%'); $pct.text('Done ✓');
                    fileInput.value = '';
                    sendToModal((resp.data && resp.data.message) || 'Upload complete.');
                    // Refresh the library so the new (processing) video shows up.
                    setTimeout(function () { location.reload(); }, 1800);
                } else {
                    $cont.hide();
                    sendToModal('Upload failed: ' + ((resp && resp.data) || xhr.statusText || ('HTTP ' + xhr.status)));
                }
            };
            xhr.onerror = function () { $cont.hide(); sendToModal('Upload failed: connection error.'); };
            xhr.send(fd);
        });
    }






    jQuery(document).ready(function ($) {
        initVideoUploader(); // Initialize the uploader when document is ready
    });




    // ── SRT settings (OBS / vMix) — one modal, all fields visible, output
    //    computed live below as you type. ──
    $(document).on('click', '.generateSRTobs', function () {
        var baseSRTUrl = this.getAttribute('data-base-srt-url');
        var host = this.getAttribute('data-host') || 'the streaming server';
        var html =
            '<h2 class="hscf-modal-title">OBS SRT Settings</h2>' +
            '<div class="hscf-srt-grid">' +
              '<label>Ping to ' + host + ' (ms)<input type="number" class="srt-ping" value="100" min="1"></label>' +
              '<label>Upload speed (MB)<input type="number" class="srt-upload" value="5" min="1"></label>' +
              '<label>Buffer size (MB)<input type="number" class="srt-buffer" value="64" min="1"></label>' +
            '</div>' +
            '<div class="hscf-srt-out">' +
              srtOutField('Stream URL', 'srt-url-val') +
              srtOutField('Bitrate (kbps)', 'srt-bitrate-val') +
            '</div>';
        openModalHTML(html, true);
        var $m = $('#cfModal');
        function recompute() {
            var ping = parseInt($m.find('.srt-ping').val(), 10);
            var upload = parseInt($m.find('.srt-upload').val(), 10);
            var buffer = parseInt($m.find('.srt-buffer').val(), 10);
            if (isNaN(ping) || isNaN(upload) || isNaN(buffer)) {
                $m.find('.srt-url-val').text('Enter numeric values in all fields.');
                $m.find('.srt-bitrate-val').text('—');
                return;
            }
            var latency = Math.min(8000000, ping * 1000 * 60000);
            var maxbw = upload * 1000000 * 0.9;
            var buf = buffer * 1000000;
            var bitrate = Math.min(6500, upload * 1000 * 0.9);
            $m.find('.srt-url-val').text(baseSRTUrl + '&latency=' + latency + '&maxbw=' + maxbw + '&sndbuf=' + buf + '&rcvbuf=' + buf);
            $m.find('.srt-bitrate-val').text(bitrate);
        }
        $m.find('.srt-ping, .srt-upload, .srt-buffer').on('input', recompute);
        recompute();
    });

    $(document).on('click', '.generateSRTvmix', function () {
        var simplifiedSRTUrl = this.dataset.simplifiedSrtUrl;
        var portSRT = this.dataset.portSrt;
        var passphrase = this.dataset.passphrase;
        var streamId = this.dataset.streamId;
        var host = this.dataset.simplifiedSrtUrl || 'the streaming server';
        var html =
            '<h2 class="hscf-modal-title">vMix SRT Settings</h2>' +
            '<div class="hscf-srt-grid">' +
              '<label>Ping to ' + host + ' (ms)<input type="number" class="srt-ping" value="100" min="1"></label>' +
              '<label>Upload speed (MB)<input type="number" class="srt-upload" value="5" min="1"></label>' +
            '</div>' +
            '<div class="hscf-srt-out">' +
              srtOutField('Hostname', 'v-host') +
              srtOutField('Port', 'v-port') +
              srtOutField('Latency', 'v-lat') +
              srtOutField('Passphrase', 'v-pass') +
              srtOutField('Stream ID', 'v-sid') +
              srtOutField('Bitrate (kbps)', 'v-bit') +
            '</div>';
        openModalHTML(html, true);
        var $m = $('#cfModal');
        function recompute() {
            var ping = parseInt($m.find('.srt-ping').val(), 10);
            var upload = parseInt($m.find('.srt-upload').val(), 10);
            $m.find('.v-host').text(simplifiedSRTUrl);
            $m.find('.v-port').text(portSRT);
            $m.find('.v-lat').text(isNaN(ping) ? '—' : Math.min(8000, ping * 60));
            $m.find('.v-pass').text(passphrase);
            $m.find('.v-sid').text(streamId);
            $m.find('.v-bit').text(isNaN(upload) ? '—' : Math.min(6500, upload * 1000 * 0.9));
        }
        $m.find('.srt-ping, .srt-upload').on('input', recompute);
        recompute();
    });

    // Copy a computed SRT field's value (with the green flash).
    $(document).on('click', '.srt-copy', function () {
        var src = $(this).data('src');
        var text = $('#cfModal').find('.' + src).first().text();
        copyToClipboard(text, this);
    });


    // Copy RTMP URL
    $(document).on('click', '.copy-rtmp-url-btn', function () {
        var rtmpURL = $(this).data('rtmp-url');
        copyToClipboard(rtmpURL, this);
    });

    // Copy RTMP Key
    $(document).on('click', '.copy-rtmp-key-btn', function () {
        var rtmpKey = $(this).data('rtmp-key');
        copyToClipboard(rtmpKey, this);
    });

    // Copy Live Input ID
    $(document).on('click', '.copy-input-id-btn', function () {
        var inputID = $(this).data('input-id');
        copyToClipboard(inputID, this);
    });

    // Copy Embed Code
    $(document).on('click', '.copy-embed-code-btn', function () {
        var inputId = $(this).data('input-id');

        // Generate the embed code with proper formatting
        var embedCode = "<div style=\"position: relative;\">\n" +
            "    <iframe src=\"" + hscf_ajax.player_url + "?live=true&amp;inputId=" + inputId + "\"\n" +
            "            style=\"border: none; width: 100%; aspect-ratio: 16 / 9;\"\n" +
            "            allow=\"fullscreen; accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture\"\n" +
            "            allowfullscreen=\"\">\n" +
            "    </iframe>\n" +
            "</div>";

        copyToClipboard(embedCode, this);
    });

    // Delete Output
    $(document).on('click', '.delete-output-link', function (e) {
        e.preventDefault();

        if (confirm('Are you sure you want to delete this output?')) {
            var outputId = $(this).data('output-id');
            var inputId = $(this).data('input-id');

            $.ajax({
                url: hscf_ajax.ajax_url,
                type: 'POST',
                data: {
                    'action': 'hscf_delete_output',
                    'output_id': outputId,
                    'input_id': inputId,
                    '_wpnonce': hscf_ajax.nonce
                },
                success: function (response) {
                    if (response.success) {
                        var $row = $('.hscf-output[data-output-id="' + outputId + '"]');
                        var $list = $row.closest('.hscf-output-list');
                        $row.fadeOut(180, function () {
                            $(this).remove();
                            if ($list.find('.hscf-output').length === 0) {
                                $list.append('<p class="description hscf-no-outputs">No restreams yet.</p>');
                            }
                        });
                    } else {
                        sendToModal("Failed to delete output: " + response.data);
                    }
                }
            });
        }
    });

    // ── Social-stream outputs: add via a provider icon, edit via the pencil ──
    // Both open the same modal; the only difference is add posts to
    // hscf_create_output and edit posts to hscf_update_output.
    function openOutputModal(ctx) {
        var isEdit = ctx.mode === 'edit';
        var title = (isEdit ? 'Edit ' : 'Add ') + (ctx.providerLabel || 'RTMP') + ' output';
        var html =
            '<h2 class="hscf-modal-title">' + escHtml(title) + '</h2>' +
            '<form class="hscf-output-modal-form">' +
              '<label>Name<input type="text" class="ho-name" placeholder="' + escHtml(ctx.providerLabel || 'Output') + '"></label>' +
              '<label>Server / Ingest URL<input type="text" class="ho-url" placeholder="rtmp://…"></label>' +
              '<label>Stream key<input type="text" class="ho-key" placeholder="' + (isEdit ? 'Leave blank to keep current key' : 'Paste your stream key') + '"></label>' +
              '<p class="ho-error" role="alert"></p>' +
              '<div class="hscf-output-modal-actions">' +
                '<button type="submit" class="button button-primary">' + (isEdit ? 'Save changes' : 'Add output') + '</button>' +
              '</div>' +
            '</form>';
        openModalHTML(html, true);
        var $m = $('#cfModal');
        $m.find('.ho-name').val(ctx.name || '');
        $m.find('.ho-url').val(ctx.url || ctx.ingestUrl || '');
        $m.find('.ho-key').val(ctx.streamKey || '');
        // Stash context on the form for the submit handler.
        $m.find('.hscf-output-modal-form').data('ctx', ctx);
        $m.find('.ho-name').trigger('focus');
    }

    // Add: click a provider icon.
    $(document).on('click', '.hscf-add-output', function () {
        openOutputModal({
            mode: 'add',
            inputId: $(this).data('input-id'),
            provider: $(this).data('provider'),
            providerLabel: $(this).data('provider-label'),
            ingestUrl: $(this).data('ingest-url'),
            name: $(this).data('provider-label')
        });
    });

    // Edit: click the pencil on a row.
    $(document).on('click', '.edit-output-link', function (e) {
        e.preventDefault();
        var $row = $(this).closest('.hscf-output');
        openOutputModal({
            mode: 'edit',
            inputId: $row.data('input-id'),
            outputId: $row.data('output-id'),
            provider: $row.data('provider'),
            providerLabel: $row.find('.hscf-output__name').text() || $row.data('provider'),
            name: $row.data('name'),
            url: String($row.data('url') || ''),
            streamKey: String($row.data('stream-key') || ''),
            origUrl: String($row.data('url') || ''),
            origKey: String($row.data('stream-key') || ''),
            enabled: String($row.data('enabled')) === '1'
        });
    });

    // Submit (add or edit).
    $(document).on('submit', '.hscf-output-modal-form', function (e) {
        e.preventDefault();
        var $form = $(this), ctx = $form.data('ctx') || {};
        var name = $form.find('.ho-name').val().trim();
        var url = $form.find('.ho-url').val().trim();
        var key = $form.find('.ho-key').val().trim();
        var $err = $form.find('.ho-error');
        $err.text('');
        if (!url || (ctx.mode === 'add' && !key)) {
            $err.text('Please fill in the URL and stream key.');
            return;
        }
        var $btn = $form.find('button[type=submit]').prop('disabled', true).text('Saving…');
        var data = {
            _wpnonce: hscf_ajax.nonce,
            input_id: ctx.inputId,
            provider: ctx.provider,
            name: name,
            url: url,
            stream_key: key
        };
        if (ctx.mode === 'edit') {
            data.action = 'hscf_update_output';
            data.output_id = ctx.outputId;
            data.orig_url = ctx.origUrl;
            data.orig_stream_key = ctx.origKey;
            data.enabled = ctx.enabled ? '1' : '0';
        } else {
            data.action = 'hscf_create_output';
        }
        $.ajax({ url: hscf_ajax.ajax_url, type: 'POST', data: data })
            .done(function (resp) {
                if (resp && resp.success && resp.data && resp.data.html) {
                    var $list = $('.hscf-output-list[data-input-id="' + ctx.inputId + '"]');
                    $list.find('.hscf-no-outputs').remove();
                    if (ctx.mode === 'edit') {
                        $list.find('.hscf-output[data-output-id="' + ctx.outputId + '"]').replaceWith(resp.data.html);
                    } else {
                        $list.append(resp.data.html);
                    }
                    cfmodal.style.display = 'none';
                    clearModal();
                } else {
                    $btn.prop('disabled', false).text(ctx.mode === 'edit' ? 'Save changes' : 'Add output');
                    $err.text((resp && resp.data) ? resp.data : 'Something went wrong.');
                }
            })
            .fail(function (xhr) {
                $btn.prop('disabled', false).text(ctx.mode === 'edit' ? 'Save changes' : 'Add output');
                $err.text('Request failed: ' + (xhr.responseText || 'unknown error'));
            });
    });

    $(document).on('change', '.output-toggle', function () {
        var $cb = $(this);
        var outputId = $cb.data('output-id');
        var inputId = $cb.data('input-id');
        var isEnabled = $cb.is(':checked');

        $.ajax({
            url: hscf_ajax.ajax_url,
            type: 'POST',
            data: {
                'action': 'hscf_toggle_output',
                'output_id': outputId,
                'input_id': inputId,
                'enabled': isEnabled,
                '_wpnonce': hscf_ajax.nonce
            },
            success: function (response) {
                if (response.success) {
                    // Keep the row's stored state in sync so Edit prefills correctly.
                    $cb.closest('.hscf-output').attr('data-enabled', isEnabled ? '1' : '0');
                } else {
                    $cb.prop('checked', !isEnabled); // revert the switch
                    sendToModal("Failed to update output: " + response.data);
                }
            },
            error: function (xhr, status, error) {
                $cb.prop('checked', !isEnabled);
                sendToModal("Error: " + xhr.responseText);
            }
        });
    });


    // Recording MP4 download. Cloudflare generates the MP4 on demand and gives
    // no "ready" notification, so checking is unavoidable — but we only do it
    // while you're actively waiting: click → if already downloadable the link
    // just downloads; otherwise kick off generation and poll with backoff for
    // ~2 min, then stop and let you click again to re-check. No background
    // polling, no modals.
    var DL_DELAYS = [4000, 7000, 12000, 20000, 30000, 45000]; // ~2 min, backing off

    function dlSetIcon($link, cls) { $link.find('.dashicons').attr('class', 'dashicons ' + cls); }
    function dlPreparing($link, pct) {
        var t = (typeof pct === 'number' && pct >= 0) ? ('Preparing MP4… ' + Math.round(pct) + '%') : 'Preparing MP4…';
        $link.addClass('is-preparing').attr('title', t);
        dlSetIcon($link, 'dashicons-update hscf-spin');
    }
    function dlReady($link, url) {
        // Append ?filename= so the browser saves a real name, not "default.mp4".
        var fn = $link.attr('data-filename');
        if (fn && url.indexOf('filename=') === -1) {
            url += (url.indexOf('?') !== -1 ? '&' : '?') + 'filename=' + encodeURIComponent(fn);
        }
        $link.removeClass('is-preparing').attr('href', url).attr('title', 'Download MP4').css('color', '#008a20');
        dlSetIcon($link, 'dashicons-download');
    }
    function dlRecheck($link) { $link.removeClass('is-preparing').attr('title', 'Still preparing — click to check again').css('color', ''); dlSetIcon($link, 'dashicons-cloud'); }

    function pollDownload(videoId, $link, attempt) {
        $.post(hscf_ajax.ajax_url, { action: 'hscf_check_download_status', video_id: videoId, _wpnonce: hscf_ajax.nonce })
        .done(function (resp) {
            // Ready: success envelope carries the URL.
            if (resp && resp.success && resp.data && resp.data.download_url) {
                dlReady($link, resp.data.download_url);
                return;
            }
            // Still generating: the error envelope carries percentComplete.
            var pct = resp && resp.data && resp.data.percent;
            dlPreparing($link, typeof pct === 'number' ? pct : undefined);
            if (attempt < DL_DELAYS.length) { setTimeout(function () { pollDownload(videoId, $link, attempt + 1); }, DL_DELAYS[attempt]); }
            else { dlRecheck($link); }
        }).fail(function () {
            if (attempt < DL_DELAYS.length) { setTimeout(function () { pollDownload(videoId, $link, attempt + 1); }, DL_DELAYS[attempt]); }
            else { dlRecheck($link); }
        });
    }

    $(document).on('click', '.create-download-link', function (e) {
        var $link = $(this);
        var href = $link.attr('href') || '';
        if (href && href !== '#' && href.indexOf('.mp4') !== -1) return; // already downloadable → let it download

        e.preventDefault();
        if ($link.hasClass('is-preparing')) return; // already working on it
        var videoId = $link.data('video-id');
        dlPreparing($link);
        $.post(hscf_ajax.ajax_url, { action: 'hscf_create_download', video_id: videoId, _wpnonce: hscf_ajax.nonce })
        .done(function (resp) {
            if (resp && resp.success) { pollDownload(videoId, $link, 0); }
            else { dlRecheck($link); }
        }).fail(function () { dlRecheck($link); });
    });





    $(document).on('click', '.delete-video-link', function (e) {
        e.preventDefault();
        var $this = $(this);
        var videoId = $this.data('video-id');
        if (!confirm('Delete this video? This cannot be undone.')) return;

        var $row = $this.closest('.hscf-recording, .video-container');
        $row.css('opacity', '0.5');
        $.ajax({
            url: hscf_ajax.ajax_url,
            type: 'POST',
            data: { action: 'hscf_delete_recording', video_id: videoId, '_wpnonce': hscf_ajax.nonce },
            success: function (response) {
                if (response.success) {
                    $row.fadeOut(200, function () { $(this).remove(); }); // no success modal — just remove it
                } else {
                    $row.css('opacity', '1');
                    sendToModal('Failed to delete recording: ' + response.data);
                }
            },
            error: function (xhr) {
                $row.css('opacity', '1');
                sendToModal('Error: ' + xhr.responseText);
            }
        });
    });




    $(document).on('click', '.generate-videoid', function (e) {
        e.preventDefault();
        var $this = $(this);
        var videoId = $(this).data('video-id');
        copyToClipboard(videoId, this);
    });

    // Function to escape HTML characters
    function escapeHtml(html) {
        return html.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    // Event listener to generate embed code and send to clipboard
    $(document).on('click', '.generate-embed-link', function (e) {
        e.preventDefault();
        var $this = $(this);
        var videoId = $(this).data('video-id');

        // Format the embed code using the video UID
        var embedCode = `<div style="position: relative"><iframe src="${hscf_ajax.player_url}?live=false&inputId=${videoId}" style="border: none; width: 100%; aspect-ratio: 16 / 9;" allow="fullscreen; accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>`;

        copyToClipboard(escapeHtml(embedCode), this);
    });


    // ── Placeholder stream: one button that cycles play → ⟳ → stop → ⟳ ──
    // Collapse / expand a live-input card down to its grey title bar.
    $(document).on('click', '.hscf-stream__chevron', function (e) {
        e.preventDefault();
        $(this).closest('.hscf-stream').toggleClass('is-collapsed');
    });

    // Open the player for a live input in its own (16:9) window. Falls back to
    // the link's target=_blank if the popup is blocked.
    $(document).on('click', '.hscf-preview-pop', function (e) {
        var url = $(this).attr('href');
        if (!url) return;
        var w = window.open(url, '', 'width=960,height=540,resizable=yes,scrollbars=yes');
        if (w) { e.preventDefault(); w.focus(); }
    });

    function setPhStatus($el, text, kind) {
        var color = kind === 'err' ? '#b32d2e' : (kind === 'ok' ? '#008a20' : '#646970');
        $el.text(text).css('color', color);
    }
    function phErr(x) {
        return (x && x.responseJSON && x.responseJSON.data) ? x.responseJSON.data : 'Request failed';
    }

    // Morph the button: 'idle' = green play, 'running' = red stop, 'busy' = spinner.
    function setPhButton(inputId, state) {
        var $btn = $('.hscf-ph-btn[data-input-id="' + inputId + '"]');
        if (!$btn.length) return;
        $btn.removeClass('is-idle is-running is-busy').data('phstate', state);
        if (state === 'idle') {
            $btn.addClass('is-idle').prop('disabled', false).attr('title', 'Start placeholder stream')
                .html('<span class="dashicons dashicons-controls-play"></span>');
        } else if (state === 'running') {
            $btn.addClass('is-running').prop('disabled', false).attr('title', 'Stop placeholder stream')
                .html('<span class="hscf-stop-sq"></span>');
        } else { // busy
            $btn.addClass('is-busy').prop('disabled', true).attr('title', 'Working…')
                .html('<span class="dashicons dashicons-update hscf-spin"></span>');
        }
    }

    $(document).on('click', '.hscf-ph-btn', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var inputId = $btn.attr('data-input-id');
        var state = $btn.data('phstate') || 'idle';
        var $status = $('#phstatus-' + inputId);

        if (state === 'idle') {
            var videoFile = $('.hscf-ph-video[data-input-id="' + inputId + '"]').val();
            if (!videoFile) { setPhStatus($status, 'Pick a video first.', 'err'); return; }
            setPhButton(inputId, 'busy');
            setPhStatus($status, 'Starting…', 'muted');
            $.post(hscf_ajax.ajax_url, {
                action: 'start_placeholderstream', _wpnonce: hscf_ajax.nonce,
                id: inputId, videoFile: videoFile,
                rtmpsUrl: $btn.attr('data-rtmp-url'), rtmpsKey: $btn.attr('data-rtmp-key')
            }).done(function (resp) {
                if (resp && resp.success) { pollPhUntil(inputId, 'running'); }
                else { setPhStatus($status, (resp && resp.data) || 'Failed to start', 'err'); setPhButton(inputId, 'idle'); }
            }).fail(function (x) { setPhStatus($status, phErr(x), 'err'); setPhButton(inputId, 'idle'); });
        } else if (state === 'running') {
            setPhButton(inputId, 'busy');
            setPhStatus($status, 'Stopping…', 'muted');
            $.post(hscf_ajax.ajax_url, {
                action: 'stop_placeholderstream', _wpnonce: hscf_ajax.nonce, id: inputId
            }).done(function (resp) {
                if (resp && resp.success) { pollPhUntil(inputId, 'idle'); }
                else { setPhStatus($status, (resp && resp.data) || 'Failed to stop', 'err'); setPhButton(inputId, 'running'); }
            }).fail(function (x) { setPhStatus($status, phErr(x), 'err'); setPhButton(inputId, 'running'); });
        }
    });

    // Poll until the input reaches the target state, then settle the button.
    function pollPhUntil(inputId, target) {
        var $status = $('#phstatus-' + inputId);
        var tries = 0;
        var iv = setInterval(function () {
            tries++;
            $.post(hscf_ajax.ajax_url, { action: 'check_stream_state', _wpnonce: hscf_ajax.nonce, id: inputId })
            .done(function (resp) {
                if (!resp || !resp.success) return;
                var status = (resp.data && resp.data.status) || 'idle';
                if (target === 'running') {
                    if (status === 'running') {
                        setPhButton(inputId, 'running'); setPhStatus($status, '● Streaming', 'ok'); clearInterval(iv);
                    } else if (status === 'error') {
                        setPhButton(inputId, 'idle'); setPhStatus($status, 'Error: ' + ((resp.data && resp.data.error) || 'failed to start'), 'err'); clearInterval(iv);
                    } else if (status === 'idle' && tries > 2) {
                        setPhButton(inputId, 'idle'); setPhStatus($status, 'Stopped unexpectedly', 'err'); clearInterval(iv);
                    }
                } else { // target idle (stopping)
                    if (status === 'idle' || status === 'stopped') {
                        setPhButton(inputId, 'idle'); $status.text(''); clearInterval(iv);
                    }
                }
            });
            if (tries >= 12) { // ~18s cap — settle to whatever the state implies
                clearInterval(iv);
                setPhButton(inputId, target === 'running' ? 'running' : 'idle');
            }
        }, 1500);
    }

    // Reflect actual per-input placeholder state on load / periodic refresh.
    function checkAndSetStreamState() {
        $.post(hscf_ajax.ajax_url, { action: 'check_stream_state', _wpnonce: hscf_ajax.nonce }, function (resp) {
            if (!resp || !resp.success) return;
            var streams = (resp.data && resp.data.streams) || {};
            $('.hscf-ph-btn').each(function () {
                var $btn = $(this);
                if ($btn.hasClass('is-busy')) return; // don't fight an in-progress transition
                var id = $btn.attr('data-input-id');
                var $status = $('#phstatus-' + id);
                var s = streams[id];
                if (s && (s.status === 'running' || s.status === 'starting')) {
                    setPhButton(id, 'running'); setPhStatus($status, '● Streaming', 'ok');
                } else if (s && s.status === 'error') {
                    setPhButton(id, 'idle'); setPhStatus($status, 'Error', 'err');
                } else {
                    setPhButton(id, 'idle'); $status.text('');
                }
            });
        });
    }

    function getVideoFiles() {
        $.post(hscf_ajax.ajax_url, {
            'action': 'get_video_files',
            '_wpnonce': hscf_ajax.nonce,
        }, function (response) {
            if (typeof response === 'string') {
                try {
                    response = JSON.parse(response);
                    if (Array.isArray(response)) {
                        const fileSelectors = document.querySelectorAll('.FileSelector');
                        populateFileSelectors(fileSelectors, response);
                    } else {
                        console.error('Failed to fetch video files:', response);
                    }
                } catch (e) {
                    console.log('Response:', response);
                }
            } else {
                if (Array.isArray(response)) {
                    const fileSelectors = document.querySelectorAll('.FileSelector');
                    populateFileSelectors(fileSelectors, response);
                } else {
                    console.error('Failed to fetch video files:', response);
                }
            }
        }).fail(function () {
            // Placeholder-video list needs the Streamer service configured; if it
            // isn't, leave the selector empty rather than spamming the console.
            console.warn('HitchStream: placeholder video list unavailable (Streamer service not configured?).');
        });
    }


    function populateFileSelectors(fileSelectors, files) {
        fileSelectors.forEach((fileSelector) => {
            files.forEach((file) => {
                const option = document.createElement('option');
                option.value = file;
                option.textContent = file;
                fileSelector.appendChild(option);
            });
        });
    }



    function copyToClipboard(text, btn) {
        var onOk = function () { flashCopied(btn); };
        // navigator.clipboard only exists in a secure context (https or
        // localhost). Over http on a LAN IP it's undefined, so fall back to the
        // legacy execCommand path, which works without a secure context.
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(onOk).catch(function () { fallbackCopy(text, onOk); });
        } else {
            fallbackCopy(text, onOk);
        }
    }

    // Briefly turn a copy button green with a check + "Copied!" as confirmation.
    function flashCopied(btn) {
        if (!btn) return;
        var $b = jQuery(btn);
        if ($b.data('hscfBusy')) { return; }
        var orig = $b.html();
        $b.data('hscfBusy', true)
          .css('min-width', $b.outerWidth() + 'px')
          .addClass('hscf-copied')
          .html('<span class="dashicons dashicons-yes" style="vertical-align:text-top;margin:0;"></span> Copied!');
        setTimeout(function () {
            $b.removeClass('hscf-copied').css('min-width', '').html(orig).removeData('hscfBusy');
        }, 1800);
    }

    function fallbackCopy(text, onOk) {
        try {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.top = '-9999px';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            var ok = document.execCommand('copy');
            document.body.removeChild(ta);
            if (ok) { onOk(); } else { sendToModal('Copy this manually:<br><textarea readonly class="modalcontentbox" style="width:100%;">' + text + '</textarea>'); }
        } catch (e) {
            sendToModal('Copy this manually:<br><textarea readonly class="modalcontentbox" style="width:100%;">' + text + '</textarea>');
        }
    }



    // Live-input status badges + placeholder-stream button states. Only poll on
    // the Streams tab (where the cards exist) and pause while the browser tab is
    // hidden — no point hammering AJAX on a background or unrelated tab.
    if ($('.hscf-stream').length) {
        updateLiveInputStatus();
        checkAndSetStreamState();
        setInterval(function () {
            if (document.hidden) return;
            updateLiveInputStatus();
            checkAndSetStreamState();
        }, 10000);
    }

});

// jQuery runs in noConflict mode in wp-admin, so the global `$` is NOT jQuery.
// The code below this point is at top level (outside the ready wrapper above),
// so alias it — otherwise `$(...)` throws "$ is not a function" and halts the
// rest of the script (which is why the modal never initialised).
var $ = jQuery;

// --- Webhook Settings ---

function HSCF_cf_api_call(action, callback, extra) {
    $.ajax({
        url: hscf_ajax.ajax_url,
        type: 'POST',
        data: $.extend({ action: action, _wpnonce: hscf_ajax.nonce }, extra || {}),
        success: function(response) {
            if (response.success) {
                callback(null, response.data);
            } else {
                callback(response.data, null);
            }
        },
        error: function(xhr) {
            callback('AJAX error: ' + xhr.statusText, null);
        }
    });
}

$(document).on('click', '#btn-register-webhook', function() {
    var $btn = $(this);
    var orig = $btn.html();
    $btn.prop('disabled', true).text('Setting up…');

    HSCF_cf_api_call('hscf_register_webhook', function(err, data) {
        $btn.prop('disabled', false).html(orig);
        if (err) {
            sendToModal('Set up failed: ' + escHtml(err));
            return;
        }
        $('#btn-fetch-webhook-status').trigger('click');
        sendToModal(escHtml((data && data.message) || 'Live webhook configured.'));
    });
});

$(document).on('click', '#btn-delete-webhook', function() {
    if (!confirm('Remove the live webhook (destination + notification policy) from Cloudflare?')) return;

    var $btn = $(this);
    var orig = $btn.html();
    $btn.prop('disabled', true).text('Removing…');

    HSCF_cf_api_call('hscf_delete_webhook', function(err, data) {
        $btn.prop('disabled', false).html(orig);
        if (err) {
            sendToModal('Removal failed: ' + escHtml(err));
            return;
        }
        $('#btn-fetch-webhook-status').trigger('click');
        sendToModal(escHtml((data && data.message) || 'Live webhook removed.'));
    });
});

$(document).on('click', '#btn-fetch-webhook-status', function() {
    HSCF_cf_api_call('hscf_fetch_webhooks', function(err, data) {
        if (err) {
            $('#webhook-status').show().html('<span style="color:#dc2626;">Error: ' + escHtml(err) + '</span>');
            return;
        }
        var html = '<strong>Live Webhook Status:</strong><br>';
        if (data && data.configured) {
            html += '<span style="color:#16a34a;">&#10003; Configured</span> — Cloudflare Notifications policy is routing live events.';
            html += '<br>Receiver: <div class="modalcontentbox">' + escHtml(data.url || '') + '</div>';
            html += 'Policy enabled: ' + (data.policy_enabled ? 'yes' : 'no');
        } else if (data && data.dest_id) {
            html += '<span style="color:#d97706;">Destination exists but no policy is routing events. Click “Set Up Live Webhook”.</span>';
        } else {
            html += '<span style="color:#dc2626;">Not set up. Click “Set Up Live Webhook”.</span>';
        }
        $('#webhook-status').show().html(html);
    });
});

// Helper to escape HTML for modal display
function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Show live webhook status on page load (only when the panel is present).
$(document).ready(function() {
    if ($('#btn-fetch-webhook-status').length) {
        $('#btn-fetch-webhook-status').trigger('click');
    }
});

//Modal
// Get the modal
var cfmodal = document.getElementById("cfModal");

// Get the button that opens the modal
var btn = document.getElementById("testmodalbtn");

// Get the <span> element that closes the modal
var span = document.getElementsByClassName("close")[0];


// When the user clicks on <span> (x), close the modal
var closeSpanElement = document.querySelector('.close');
if (closeSpanElement) {
    closeSpanElement.onclick = function () {
        cfmodal.style.display = "none";
        clearModal();
    }
}

// When the user clicks anywhere outside of the modal, close it
window.onclick = function (event) {
    if (event.target == cfmodal) {
        cfmodal.style.display = "none";
        clearModal();
    }
}

// Function to clear the content of the modal
function clearModal() {
    var cfmodalContent = cfmodal.querySelector('.cfmodal-content');
    cfmodalContent.innerHTML = '';
    var box = cfmodal.querySelector('.cfmodalbox');
    if (box) { box.style.width = ''; }
}

function sendToModal(message) {
    clearModal();
    var cfmodalContent = cfmodal.querySelector('.cfmodal-content');
    cfmodalContent.innerHTML = `<p>${message}</p>`;
    cfmodal.style.display = "block";
}

// Set raw modal HTML (no <p> wrapper) — for form-based modals like the SRT
// generators. `wide` gives the box more room for a form + computed output.
function openModalHTML(html, wide) {
    clearModal();
    var box = cfmodal.querySelector('.cfmodalbox');
    if (box) { box.style.width = wide ? '560px' : ''; }
    cfmodal.querySelector('.cfmodal-content').innerHTML = html;
    cfmodal.style.display = "block";
}

// One output row in an SRT modal: label + copy button + value box.
function srtOutField(label, cls) {
    return '<div class="hscf-srt-field">' +
        '<div class="hscf-srt-label">' + label + '</div>' +
        '<div class="hscf-srt-row">' +
            '<div class="modalcontentbox ' + cls + '"></div>' +
            '<button type="button" class="button button-small srt-copy" data-src="' + cls + '">Copy</button>' +
        '</div></div>';
}