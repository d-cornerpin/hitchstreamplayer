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
                        //                        sendToModal("Output deleted successfully.");
                        location.reload(); // Reload the page to update the output list
                    } else {
                        sendToModal("Failed to delete output: " + response.data);
                    }
                }
            });
        }
    });

    // Create Output
    $(document).on('submit', '.create-output-form', function (e) {
        e.preventDefault();

        var form = $(this);
        var streamKey = form.find('#stream-key-input').val().trim();
        var streamUrl = form.find('#stream-url-input').val().trim();
        var inputId = form.find('input[name="input_id"]').val();

        if (!streamKey || !streamUrl) {
            sendToModal('Please enter both Stream Key and Stream URL.');
            return;
        }

        $.ajax({
            url: hscf_ajax.ajax_url,
            type: 'POST',
            data: {
                'action': 'hscf_create_output',
                'input_id': inputId,
                'stream_key': streamKey,
                'stream_url': streamUrl,
                '_wpnonce': hscf_ajax.nonce
            },
            success: function (response) {
                if (response.success) {
                    //                    sendToModal("Output Created Successfully");
                    location.reload(); // Reload page to show new output
                } else {
                    sendToModal("Failed to Create Output: " + response.data);
                }
            },
            error: function (xhr, status, error) {
                sendToModal("Error: " + xhr.responseText);
            }
        });
    });

    $(document).on('change', '.output-toggle', function () {
        var outputId = $(this).data('output-id');
        var inputId = $(this).data('input-id');
        var isEnabled = $(this).is(':checked');

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
                    // Display message based on toggle state
                    var message = isEnabled ? "Output Enabled" : "Output Disabled";
                    sendToModal(message);
                } else {
                    sendToModal("Failed to update output: " + response.data);
                }
            },
            error: function (xhr, status, error) {
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
    $btn.prop('disabled', true).val('Registering...');

    HSCF_cf_api_call('hscf_register_webhook', function(err, data) {
        $btn.prop('disabled', false).val('Register Webhook with Cloudflare');
        if (err) {
            sendToModal('Registration failed: ' + err);
            return;
        }
        // Update the secret + URL fields with the values from Cloudflare
        $('input[name=HSCF_webhook_secret]').val(data.secret || '');
        if (data.url) { $('input[name=HSCF_webhook_url]').val(data.url); }
        sendToModal('Webhook registered successfully!<br>URL: <div class="modalcontentbox">' + escHtml(data.url || '') + '</div>Secret: <div class="modalcontentbox">' + escHtml(data.secret || '') + '</div>');
    }, { webhook_url: ($('input[name=HSCF_webhook_url]').val() || '') });
});

$(document).on('click', '#btn-delete-webhook', function() {
    if (!confirm('Are you sure you want to delete the Cloudflare webhook?')) return;

    var $btn = $(this);
    $btn.prop('disabled', true).val('Deleting...');

    HSCF_cf_api_call('hscf_delete_webhook', function(err, data) {
        $btn.prop('disabled', false).val('Delete Webhook');
        if (err) {
            sendToModal('Deletion failed: ' + err);
            return;
        }
        $('#HSCF_webhook_secret').val('');
        sendToModal('Webhook deleted. Status: ' + (data.status || 'unknown'));
    });
});

$(document).on('click', '#btn-fetch-webhook-status', function() {
    HSCF_cf_api_call('hscf_fetch_webhooks', function(err, data) {
        if (err) {
            $('#webhook-status').show().html('<span style="color:#dc2626;">Error: ' + escHtml(err) + '</span>');
            return;
        }
        var html = '<strong>Webhook Status:</strong><br>';
        if (data && data.result && data.result.notification_url) {
            html += 'Registered URL: <div class="modalcontentbox">' + escHtml(data.result.notification_url || data.result.notificationUrl || '') + '</div>';
            html += '<br>Modified: ' + escHtml(data.result.modified || 'N/A');
            html += '<br><span style="color:#16a34a;">&#10003; Webhook is registered</span>';
        } else {
            html += '<span style="color:#dc2626;">No webhook currently registered.</span>';
        }
        $('#webhook-status').show().html(html);
    });
});

// Helper to escape HTML for modal display
function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Fetch webhook status on page load
$(document).ready(function() {
    // Auto-populate webhook URL if not set
    var currentUrl = $('#HSCF_webhook_url').val();
    if (!currentUrl) {
        $('#HSCF_webhook_url').val(window.location.origin + '/wp-content/themes/celebration-child/endpoints/cf-live-webhook.php');
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