<?php
/*
Template Name: HitchStream Controls
*/
if ( ! is_user_logged_in() ) {
    // Get the login URL and append the current page as the redirect parameter
    $login_url = wp_login_url(get_permalink());
    // Redirect to the login page
    wp_redirect($login_url);
    exit; // Always call exit after wp_redirect()
}

get_header('custom');
?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo("charset"); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=.75, user-scalable=no">
    <?php if (is_singular() && pings_open(get_queried_object())) { ?>
    <link rel="pingback" href="<?php bloginfo("pingback_url"); ?>">
    <?php } ?>
    <?php wp_head(); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;500;700&family=Montserrat:wght@200;500;700&display=swap" rel="stylesheet">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>

    <!-- Player Controls-->
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #cc4864 !important;
        }

        .ControlContainer {
            max-width: 650px;
            margin: 0 auto;
            padding: 5px;
        }


        .Selections {
            display: flex;
            width: 100%;
            justify-content: space-between;
            padding-top: 5px;
        }

        .pulldown {
            flex: 1;
            position: relative;
            left: 0;
            transform: none;
        }

        .pulldown:first-child {
            margin-right: 5px;
            /* Adds space to the right of the first pulldown */
        }

        .pulldown:last-child {
            margin-left: 5px;
            /* Adds space to the left of the last pulldown */
        }

        .pulldown select {
            -webkit-appearance: none !important;
            -moz-appearance: none !important;
            appearance: none !important;
            background-color: #0563af;
            color: white;
            padding: 12px;
            width: 100%;
            border: none;
            font-size: 15px;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.2);
            outline: none;
            overflow: hidden;
            padding-right: 40px;
        }

        .pulldown::before {
            content: "\f13a";
            font-family: FontAwesome;
            position: absolute;
            top: 0;
            right: 0;
            width: 20%;
            height: 100%;
            text-align: center;
            font-size: 28px;
            line-height: 52px;
            color: rgba(255, 255, 255, 0.5);
            background-color: rgba(255, 255, 255, 0.1);
            pointer-events: none;
        }

        .pulldown:hover::before {
            color: rgba(255, 255, 255, 0.6);
            background-color: rgba(255, 255, 255, 0.2);
        }

        .pulldown select option {
            padding: 30px;
        }

        .StreamContainer {
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: black;
            aspect-ratio: 16 / 9;
            width: 100%;
            color: white;
        }

        .StreamContainer p {
            margin: 0;
        }

        .controlmodal {
            display: none;
            /* Hidden by default */
            position: fixed;
            /* Stay in place */
            z-index: 1;
            /* Sit on top */
            left: 0;
            top: 0;
            width: 100%;
            /* Full width */
            height: 100%;
            /* Full height */
            overflow: auto;
            /* Enable scroll if needed */
            background-color: rgb(0, 0, 0);
            /* Fallback color */
            background-color: rgba(0, 0, 0, 0.4);
            /* Black w/ opacity */
        }

        /* Modal Content/Box */
        .controlmodalbox {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 360px;
            border-radius: 10px;
            /*            filter: drop-shadow(0px 5px 10px);*/
        }

        .controlmodal-content {
            overflow-wrap: break-word;
            padding-top: 10px;
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 48px;
            font-weight: bold;
            line-height: .2;
        }

        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        .StreamStatus {
            width: 100%;
            color: white;
            background-color: red;
            display: none;
            justify-content: center;
            align-items: center;
            text-align: center;
        }

        .StreamStatus p {
            margin: 0;
            /* Remove default margin of <p> tag to ensure perfect centering */
        }

    </style>
</head>

<body>
    <div id="controlmodal" class="controlmodal">
        <div class="controlmodalbox">
            <span class="close">&times;</span>
            <div class="controlmodal-content">
                <p>.</p>
            </div>
        </div>
    </div>
    <div class="ControlContainer">
        <div id="StreamContainer" class="StreamContainer">
            <p>Select a Stream</p>
        </div>
        <div class="StreamStatus">
            <p>Disconnected</p>
        </div>
        <div class="Selections">
            <div class="pulldown">
                <select id="StreamSelector1" class="StreamSelector1">
                    <option value="" disabled selected>Stream</option>
                </select>
            </div>
            <div class="pulldown">
                <select id="FileSelector1" class="FileSelector1">
                    <option value="" disabled selected>Placeholder</option>
                </select>
            </div>
        </div>
    </div>
</body>

</html>

<script type="text/javascript">
    function loadAndPopulateVideoFiles() {
        jQuery.ajax({
            url: ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'get_video_files',
            },
            success: function(response) {
                var videos = JSON.parse(response);
                var $selector = jQuery('#FileSelector1');
                $selector.empty(); // Clear existing options

                // Insert the "Placeholder" option first
                $selector.append(jQuery('<option>', {
                    value: "",
                    text: "Placeholder",
                    disabled: true,
                    selected: true
                }));

                // Append new options from AJAX call
                jQuery.each(videos, function(index, videoFileName) {
                    $selector.append(jQuery('<option>', {
                        value: videoFileName,
                        text: videoFileName
                    }));
                });

                // Append the "STOP" option at the end
                $selector.append(jQuery('<option>', {
                    value: "STOP",
                    text: "STOP"
                }));
            },
            error: function(error) {
                sendToModal('Error fetching video files: ' + error);
            }
        });
    }

    function loadAndPopulateLiveInputs() {
        jQuery.ajax({
            url: ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'get_live_inputs',
            },
            success: function(response) {
                if (response.success) {
                    var liveInputs = response.data;
                    var $streamSelector = jQuery('#StreamSelector1');
                    $streamSelector.empty(); // Clear existing options

                    // Insert the "Select a Stream" option first
                    $streamSelector.append(jQuery('<option>', {
                        value: "",
                        text: "Stream",
                        disabled: true,
                        selected: true
                    }));

                    // Append new options from AJAX call
                    jQuery.each(liveInputs, function(index, input) {
                        $streamSelector.append(jQuery('<option>', {
                            value: input.value, // UID and RTMP key combined
                            text: input.name
                        }));
                    });
                } else {
                    sendToModal('Error fetching live inputs: ' + response.data);
                }
            },
            error: function(error) {
                sendToModal('Error fetching live inputs: ' + error);
            }
        });
    }



    // Call functions on document load
    jQuery(document).ready(function($) {
        loadAndPopulateVideoFiles();
        loadAndPopulateLiveInputs();

        // Event listener for when a placeholder is selected
        $('#FileSelector1').change(function() {
            var fileSelected = $(this).val(); // The selected file name or "STOP"

            // Check if the StreamSelector has a value other than the placeholder
            var streamSelectorValue = $('#StreamSelector1').val();
            if (streamSelectorValue === "" || $('#StreamSelector1').find(":selected").text() === "Stream") {
                sendToModal('Please select a stream first');
                $(this).val(""); // Resetting back to the default placeholder value
                return; // Exit the function to prevent further execution
            }

            // Extracting UID and RTMP key from the selected stream value
            var parts = streamSelectorValue.split('|');
            var selectedRtmpKey = parts[1]; // RTMP key is now available in this scope

            // Determine the action based on the file selected
            if (fileSelected === "STOP") {
                // Make AJAX call to stop the stream
                jQuery.ajax({
                    url: ajax_object.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'stop_placeholderstream',
                    },
                    success: function(response) {
                        sendToModal('Stream stopped successfully.<br>Give it a few seconds to stop.');
                    },
                    error: function(error) {
                        sendToModal('Error stopping stream: ' + error);
                    }
                });
            } else {
                // Make AJAX call to start the stream with the selected file
                jQuery.ajax({
                    url: ajax_object.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'start_placeholderstream',
                        videoFile: fileSelected,
                        rtmpsUrl: 'rtmps://live.hitchstream.com:443/live/',
                        rtmpsKey: selectedRtmpKey
                    },
                    success: function(response) {
                        sendToModal('Stream started successfully with file: ' + fileSelected + ".<br>Give it a few seconds to start.");
                    },
                    error: function(error) {
                        sendToModal('Error starting stream: ' + error);
                    }
                });
            }
        });





        // Event listener for when a stream is selected
        $('#StreamSelector1').change(function() {
            $('#FileSelector1').find("option:first").prop("selected", true);
            var selectedValue = $(this).val(); // Combined UID and RTMP key
            var parts = selectedValue.split('|');
            var selectedStreamUid = parts[0];
            var selectedRtmpKey = parts[1];
            var streamContainer = $('#StreamContainer'); // Reference to the stream container

            // Clear the current content of the StreamContainer
            streamContainer.empty();
            streamContainer.css('background-color', '#cc4864');

            // Construct the iframe URL with the selected UID
            var iframeUrl = 'https://hitchstream.com/player?live=true&inputId=' +
                encodeURIComponent(selectedStreamUid);

            // Create the iframe element
            var iframe = $('<iframe>', {
                src: iframeUrl,
                style: 'border: none; aspect-ratio: 16/9; width: 100%; border: 4px solid white;',
                allow: 'accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;',
                allowfullscreen: 'true'
            });

            // Append the iframe to the StreamContainer
            streamContainer.append(iframe);

            //Show the status
            checkLiveInputStatus();
            $('.StreamStatus').css('display', 'flex');
        });

        //Modal Stuff

        // Get the modal
        var controlmodal = document.getElementById("controlmodal");
        // Get the <span> element that closes the modal
        var span = document.getElementsByClassName("close")[0];


        // When the user clicks on <span> (x), close the modal
        span.onclick = function() {
            controlmodal.style.display = "none";
            clearModal();
        }

        // When the user clicks anywhere outside of the modal, close it
        window.onclick = function(event) {
            if (event.target == controlmodal) {
                controlmodal.style.display = "none";
                clearModal();
            }
        }

        // Function to clear the content of the modal
        function clearModal() {
            var controlmodalContent = controlmodal.querySelector('.controlmodal-content');
            controlmodalContent.innerHTML = '';
        }

        function sendToModal(message) {
            clearModal();
            var controlmodalContent = controlmodal.querySelector('.controlmodal-content');
            controlmodalContent.innerHTML = `<p>${message}</p>`;
            controlmodal.style.display = "block";
        }


        function checkLiveInputStatus() {
            // Get the value of the currently selected option in StreamSelector1
            var selectedValue = $('#StreamSelector1').val();

            // Check if the placeholder "Stream" is selected or if the value is undefined
            if (!selectedValue || $('#StreamSelector1').find(":selected").text() === "Stream") {
                // Do nothing if the placeholder is selected or no value is present
                console.log('Placeholder "Stream" selected, doing nothing.');
                return;
            }

            // Proceed with splitting the value only after confirming it's not the placeholder
            var parts = selectedValue.split('|');
            var selectedStreamUid = parts[0]; // Extract the UID part from the split value


            // Proceed to check the status of the selected stream
            jQuery.ajax({
                url: ajax_object.ajax_url,
                type: 'POST',
                data: {
                    action: 'hscf_check_live_input_status', // The WordPress action to call
                    uid: selectedStreamUid // Passing the selected UID to PHP
                },
                success: function(response) {
                    if (response.success && response.data && response.data[selectedStreamUid]) {
                        var status = response.data[selectedStreamUid]; // Expected to be 'Connected' or 'Disconnected'
                        // Update the StreamStatus div
                        $('.StreamStatus p').text(status); // Replace text
                        $('.StreamStatus').css({
                            'display': 'flex', // Show div
                            'background-color': status === 'connected' ? 'green' : 'red' // Set color
                        });
                    } else {
                        console.error('Error or no status available for the selected stream.');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', status, error);
                }
            });
        }

        setInterval(checkLiveInputStatus, 5000);

    });

</script>




<?php
get_footer('custom'); 
?>
