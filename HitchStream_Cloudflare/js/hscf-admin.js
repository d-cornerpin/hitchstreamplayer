jQuery(document).ready(function ($) {

    getVideoFiles();
    initVideoUploader();

    function updateLiveInputStatus() {
        $.ajax({
            url: hscf_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'hscf_check_live_input_status',
                _wpnonce: hscf_ajax.nonce
            },
            success: function (response) {
                if (response.success) {
                    $.each(response.data, function (uid, status) {
                        var statusSpan = $('#status-' + uid);
                        if (status === 'connected') {
                            statusSpan.removeClass('status-disconnected').addClass('status-connected');
                        } else if (status === 'disconnected') {
                            statusSpan.removeClass('status-connected').addClass('status-disconnected');
                        }
                        statusSpan.text(status);
                    });
                }
            }
        });
    }

    function initVideoUploader() {
        $('#video-upload-form').off('submit').on('submit', function (e) {
            e.preventDefault();
            var fileInput = $('#video-upload-form input[type=file]')[0];
            var filePath = fileInput.value;
            if (filePath) {
                var allowedExtensions = /(\.mp4|\.mov)$/i;
                if (!allowedExtensions.exec(filePath)) {
                    sendToModal('MP4 or MOV file is required.');
                    return;
                }
            }

            var formData = new FormData(this);
            formData.append('action', 'hscf_upload_video');
            formData.append('_wpnonce', hscf_ajax.nonce);

            // Initial message with progress bar HTML and a placeholder for percentage
            sendToModal('<div id="progress-container" style="display: block; width: 100%; background: #eee;">' +
                '<div id="progress-bar" style="height: 20px; width: 0%; background-color: #2271b1;"></div>' +
                '</div><p id="upload-percentage">Uploading... 0%</p>');

            // Create an XMLHttpRequest to send data
            var xhr = new XMLHttpRequest();
            xhr.open('POST', hscf_ajax.ajax_url, true);

            xhr.upload.onprogress = function (event) {
                if (event.lengthComputable) {
                    var percentComplete = (event.loaded / event.total) * 100;
                    $('#progress-bar').width(percentComplete + '%'); // Update the width of the progress bar
                    $('#upload-percentage').text('Uploading... ' + percentComplete.toFixed(2) + '%'); // Update the percentage text
                }
            };

            xhr.onload = function () {
                if (xhr.status === 200) {
                    var response = JSON.parse(xhr.responseText);
                    sendToModal(response.data); // Display success or error message in modal
                } else {
                    sendToModal("Error in upload: " + xhr.statusText); // Display error message in modal
                }
            };

            xhr.onerror = function () {
                sendToModal("Error in upload: Failed to connect."); // Display error message in modal
            };

            xhr.send(formData);
        });
    }






    jQuery(document).ready(function ($) {
        initVideoUploader(); // Initialize the uploader when document is ready
    });




    $(document).on('click', '.generateSRTobs', function () {
        var baseSRTUrl = this.getAttribute('data-base-srt-url');

        // Ask for Ping time in milliseconds
        var pingTimeInMs = prompt("Ping time to live.hitchstream.com in ms?", "100");
        var maxBandwidthInMB = prompt("Upload Speed in MB?", "5");
        var bufferSizeInMB = prompt("Buffer size in MB?", "64");

        // Convert ping time to microseconds and perform calculations
        var pingTimeInMicroseconds = parseInt(pingTimeInMs, 10) * 1000; // Convert ms to microseconds
        var calculatedLatency = (pingTimeInMicroseconds * 60000); // Apply given formula
        calculatedLatency = Math.min(8000000, calculatedLatency);

        var maxBandwidthInBytes = parseInt(maxBandwidthInMB, 10) * 1000000 * 0.9; // Assuming 1MB = 1,000,000 bytes
        var bufferSizeInBytes = parseInt(bufferSizeInMB, 10) * 1000000;

        var maxBandwidthInKBytes = Math.min(6500, parseInt(maxBandwidthInMB, 10) * 1000 * 0.9);

        if (isNaN(calculatedLatency) || isNaN(maxBandwidthInBytes) || isNaN(bufferSizeInBytes)) {
            sendToModal("Invalid input. Please enter numeric values.");
            return;
        }

        // Construct the full SRT URL with the new latency and other parameters
        var fullSRTUrl = baseSRTUrl + "&latency=" + calculatedLatency + "&maxbw=" + maxBandwidthInBytes + "&sndbuf=" + bufferSizeInBytes + "&rcvbuf=" + bufferSizeInBytes;
        var ModalMessage = "<div><strong>OBS SRT Settings:</strong><div><br>Stream URL:<br><div class='modalcontentbox'>" + fullSRTUrl + "</div><br>Bitrate:<br><div class='modalcontentbox'>" + maxBandwidthInKBytes + "</div>";
        // Present a prompt with the full SRT URL for the user to copy
        sendToModal(ModalMessage);
    });

    $(document).on('click', '.generateSRTvmix', function () {
        var simplifiedSRTUrl = this.dataset.simplifiedSrtUrl;
        var portSRT = this.dataset.portSrt;
        var passphrase = this.dataset.passphrase;
        var streamId = this.dataset.streamId;

        // Ask for Ping time in milliseconds
        var pingTimeInMs = prompt("Ping time to live.hitchstream.com in ms?", "100");
        var maxBandwidthInMB = prompt("Upload Speed in MB?", "5");

        var calculatedLatency = (pingTimeInMs * 60);
        calculatedLatency = Math.min(8000, calculatedLatency);

        var maxBandwidthInKBytes = Math.min(6500, parseInt(maxBandwidthInMB, 10) * 1000 * 0.9);

        if (isNaN(calculatedLatency) || isNaN(maxBandwidthInKBytes)) {
            sendToModal("Invalid input. Please enter numeric values.");
            return;
        }

        // Construct the full SRT URL with the new latency and other parameters
        var ModalMessage = "<div><strong>Vmix SRT Settings:</strong><div><br>Hostname:<div class='modalcontentbox'>" + simplifiedSRTUrl + "</div><br>Port:<div class='modalcontentbox'>" + portSRT + "</div><br>Latency:<div class='modalcontentbox'>" + calculatedLatency + "</div><br>Passphrase:<div class='modalcontentbox'>" + passphrase + "</div><br>Stream ID:<div class='modalcontentbox'>" + streamId + "</div><br>Bitrate:<div class='modalcontentbox'>" + maxBandwidthInKBytes + "</div><br>";
        // Present a prompt with the full SRT URL for the user to copy
        sendToModal(ModalMessage);
    });


    // Copy RTMP URL
    $(document).on('click', '.copy-rtmp-url-btn', function () {
        var rtmpURL = $(this).data('rtmp-url');
        copyToClipboard(rtmpURL);
    });

    // Copy RTMP Key
    $(document).on('click', '.copy-rtmp-key-btn', function () {
        var rtmpKey = $(this).data('rtmp-key');
        copyToClipboard(rtmpKey);
    });

    // Copy Live Input ID
    $(document).on('click', '.copy-input-id-btn', function () {
        var inputID = $(this).data('input-id');
        copyToClipboard(inputID);
    });

    // Copy Embed Code
    $(document).on('click', '.copy-embed-code-btn', function () {
        var inputId = $(this).data('input-id');

        // Generate the embed code with proper formatting
        var embedCode = "<div style=\"position: relative;\">\n" +
            "    <iframe src=\"https://hitchstream.com/player?live=true&amp;inputId=" + inputId + "\"\n" +
            "            style=\"border: none; width: 100%; aspect-ratio: 16 / 9;\"\n" +
            "            allow=\"fullscreen; accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture\"\n" +
            "            allowfullscreen=\"\">\n" +
            "    </iframe>\n" +
            "</div>";

        // Copy the formatted embed code to the clipboard and display it in the modal
        copyToClipboard(embedCode);
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


    $(document).on('click', '.create-download-link', function (e) {
        var $this = $(this);
        var videoId = $(this).data('video-id');
        var $icon = $(this).find('img'); // Assuming the icon is an <img> inside the link
        var currentHref = $(this).attr('href');

        // Log current state
        console.log('Button clicked. Video ID:', videoId);
        console.log('Current href:', currentHref);

        if (currentHref && currentHref.endsWith('.mp4')) {
            // If the download link points to an MP4 file, keep the title as "Download"
            $icon.attr('title', 'Download');
            return;
        }

        e.preventDefault(); // Prevent default action since we're handling the click event

        $.ajax({
            url: hscf_ajax.ajax_url,
            type: 'POST',
            data: {
                'action': 'hscf_create_download',
                'video_id': videoId,
                '_wpnonce': hscf_ajax.nonce
            },
            beforeSend: function () {
                $icon.attr('src', 'https://hitchstream.com/wp-content/uploads/2024/01/loading_icon2.gif')
                    .css({
                        'width': '24px',
                        'height': '24px'
                    })
                    .attr('title', 'Creating download...'); // Update the title to "Creating download..."
                sendToModal("Making the recording downloadable. This may take a while.");
            },
            success: function (response) {
                if (response.success) {
                    var downloadStatusInterval = setInterval(function () {
                        checkDownloadStatus(videoId, downloadStatusInterval, $icon);
                    }, 10000);
                } else {
                    sendToModal("Failed to create download: " + response.data);
                    $icon.attr('src', 'https://hitchstream.com/wp-content/uploads/2024/01/downloadmp4_start.png')
                        .attr('title', 'Create download'); // Revert icon back to initial state with title "Create download"
                }
            },
            error: function (xhr, status, error) {
                sendToModal("Error: " + xhr.responseText);
                $icon.attr('src', 'https://hitchstream.com/wp-content/uploads/2024/01/downloadmp4_start.png')
                    .attr('title', 'Create download'); // Revert icon back to initial state with title "Create download"
            }
        });
    });

    function checkDownloadStatus(videoId, intervalId, iconElement) {
        console.log("Checking download status for video ID:", videoId);
        $.ajax({
            url: hscf_ajax.ajax_url,
            type: 'POST',
            data: {
                'action': 'hscf_check_download_status',
                'video_id': videoId,
                '_wpnonce': hscf_ajax.nonce
            },
            success: function (response) {
                console.log("Received response:", response);
                if (response.success && response.data.download_url) {
                    console.log("Download URL available:", response.data.download_url);
                    setTimeout(function () {
                        clearInterval(intervalId);
                        iconElement.attr('src', 'https://hitchstream.com/wp-content/uploads/2024/01/downloadmp4_finish.png')
                            .attr('title', 'Download'); // Update the title to "Download"
                        iconElement.closest('a').attr('href', response.data.download_url);
                    }, 120000); // Delay of 2 minutes
                }
            },
            error: function (xhr, status, error) {
                console.error("Error: " + xhr.responseText);
            }
        });
    }





    $(document).on('click', '.delete-video-link', function (e) {
        e.preventDefault();
        var $this = $(this);
        var videoId = $(this).data('video-id');

        if (confirm('Are you sure you want to delete this video?')) {
            $.ajax({
                url: hscf_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'hscf_delete_recording',
                    video_id: videoId,
                    '_wpnonce': hscf_ajax.nonce
                },
                success: function (response) {
                    if (response.success) {
                        sendToModal('Video deleted successfully.');
                        // Determine the correct element to remove
                        var container = $(e.target).closest('.video-container');
                        if (container.length) { // Check if the container is a video-container
                            container.remove(); // Remove the .video-container
                        } else {
                            $(e.target).closest('div').remove(); // Otherwise, remove the closest div
                        }
                    } else {
                        sendToModal('Failed to delete video: ' + response.data);
                    }
                },
                error: function (xhr) {
                    sendToModal('Error: ' + xhr.responseText);
                }
            });
        }
    });




    $(document).on('click', '.generate-videoid', function (e) {
        e.preventDefault();
        var $this = $(this);
        var videoId = $(this).data('video-id');

        // Copy video ID (UID) to clipboard
        navigator.clipboard.writeText(videoId).then(function () {
            copyToClipboard(videoId);
        }).catch(function (error) {
            sendToModal("Error copying video ID: " + error);
        });
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
        var embedCode = `<div style="position: relative"><iframe src="https://hitchstream.com/player?live=false&inputId=${videoId}" style="border: none; width: 100%; aspect-ratio: 16 / 9;" allow="fullscreen; accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>`;

        // Send the formatted and escaped embed code to be copied to clipboard
        copyToClipboard(escapeHtml(embedCode));
    });


    $(document).on('change', '.stream-toggle', function () {
        // Disable the toggle to prevent multiple clicks
        $(this).attr('disabled', true);

        var isChecked = $(this).is(':checked');
        var videoFile;
        var data;
        var rtmpsKey = $(this).data('rtmp-key'); // Get the RTMP key from the data attribute

        // Check if we are starting or stopping the stream
        if (isChecked) {
            // Prepare data for starting the stream
            videoFile = $('.FileSelector').val();
            data = {
                action: 'start_placeholderstream',
                videoFile: videoFile,
                rtmpsUrl: 'rtmps://live.hitchstream.com:443/live/', // Hardcoded as it doesn't change
                rtmpsKey: rtmpsKey, // Use the RTMP key from the data attribute
                _wpnonce: hscf_ajax.nonce
            };
        } else {
            // Prepare data for stopping the stream
            data = {
                action: 'stop_placeholderstream',
                _wpnonce: hscf_ajax.nonce
            };
        }

        jQuery.post(ajaxurl, data, function (response) {
            console.log('Raw response from server:', response); // Log the raw response from the server
            response = JSON.parse(response);
            console.log('Parsed response:', response); // Log the parsed response
            if ((isChecked && response.message === "Streaming started") || (!isChecked && response.message === "Streaming stopped successfully")) {
                console.log('Server response:', response.message);
                // New: Check actual stream state after server response
                var streamStateData = {
                    action: 'check_stream_state',
                    _wpnonce: hscf_ajax.nonce
                };
                jQuery.post(ajaxurl, streamStateData, function (streamStateResponse) {
                    streamStateResponse = JSON.parse(streamStateResponse);
                    console.log('Actual state from API:', streamStateResponse);
                    const actualStreamState = streamStateResponse.isStreaming;
                    console.log('Actual Stream State:', actualStreamState);
                    console.log('Expected Stream State (isChecked):', isChecked);
                    if (actualStreamState !== isChecked) {
                        // Revert toggle state if actual stream state doesn't match expected
                        $('.stream-toggle').prop('checked', !isChecked);
                        sendToModal('Stream state mismatch. Please try again.');
                    }
                }).error(function (jqXHR, textStatus, errorThrown) {
                    // Handle error for stream state check
                    console.log('Error checking stream state:', errorThrown);
                    sendToModal('Error checking stream state. Please try again.');
                });
            } else {
                // If the response is not successful, revert the toggle state
                $('.stream-toggle').prop('checked', !isChecked);
                console.log('Stream did not ' + (isChecked ? 'start' : 'stop') + ' successfully.'); // Log the failure message
                sendToModal('Stream did not ' + (isChecked ? 'start' : 'stop') + ' successfully.');
            }
        }).error(function (jqXHR, textStatus, errorThrown) {
            console.log('Server error:', errorThrown); // Log the error from the server
            // Extract error message from response if available, otherwise use errorThrown
            var errorMessage = jqXHR.responseJSON && jqXHR.responseJSON.message ? jqXHR.responseJSON.message : errorThrown;
            sendToModal('Error: ' + errorMessage); // Show the actual error message from the endpoint
            // Revert the toggle state in case of an error
            $('.stream-toggle').prop('checked', !isChecked);
        }).always(function () {
            // Re-enable the toggle after processing is complete
            $('.stream-toggle').attr('disabled', false);
        });
    });

    function checkAndSetStreamState() {
        $.post(hscf_ajax.ajax_url, {
            'action': 'check_stream_state',
            '_wpnonce': hscf_ajax.nonce,
        }, function (response) {
            response = JSON.parse(response);
            if (response && response.hasOwnProperty('isStreaming')) {
                $('.stream-toggle').prop('checked', response.isStreaming);
            } else {
                console.error('Failed to fetch the stream state:', response);
            }
        }).fail(function (jqXHR, textStatus, errorThrown) {
            console.error('Error fetching stream state:', errorThrown);
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
        }).fail(function (jqXHR, textStatus, errorThrown) {
            console.error('Error fetching video files:', errorThrown);
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



    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(function () {
            // Create a message with a textarea containing the copied text
            var message = "Copied to clipboard:<br><textarea readonly class='modalcontentbox' style='width:100%;' id='clip-textarea'>" + text + "</textarea>";
            sendToModal(message);

            // Adjust the height of the textarea after the modal is displayed
            setTimeout(function () {
                var textarea = document.getElementById('clip-textarea');
                if (textarea) {
                    textarea.style.height = 'auto'; // Reset the height to calculate properly
                    textarea.style.height = textarea.scrollHeight + 'px'; // Set the height to scroll height
                }
            }, 0); // Timeout set to 0 to ensure it runs after the DOM update
        }).catch(function (error) {
            sendToModal("Error copying text: " + error);
        });
    }



    updateLiveInputStatus();
    checkAndSetStreamState();

    // Poll every 10 seconds
    setInterval(function () {
        updateLiveInputStatus();
        checkAndSetStreamState();
    }, 10000);

});

// --- Webhook Settings ---

function HSCF_cf_api_call(action, callback) {
    $.ajax({
        url: hscf_ajax.ajax_url,
        type: 'POST',
        data: { action: action, _wpnonce: hscf_ajax.nonce },
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
        // Update the secret field with the value from Cloudflare
        $('#HSCF_webhook_secret').val(data.secret || '');
        sendToModal('Webhook registered successfully!<br>Secret: <div class="modalcontentbox">' + escHtml(data.secret || '') + '</div>');
    });
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
}

function sendToModal(message) {
    clearModal();
    var cfmodalContent = cfmodal.querySelector('.cfmodal-content');
    cfmodalContent.innerHTML = `<p>${message}</p>`;
    cfmodal.style.display = "block";
}