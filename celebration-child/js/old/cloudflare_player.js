document.addEventListener('DOMContentLoaded', (event) => {
    const playerElement = document.getElementById('stream-player');
    const player = Stream(playerElement);

    const debugMode = false; // Set to false to disable console logs

    const debugLog = (...messages) => {
        if (debugMode) {
            console.log(...messages);
        }
    };

    const posterImage = document.getElementById('poster');
    let seekingCounter = 0; // Initialize the seeking counter
    let waitingCounter = 0; // Initialize the waiting counter


    // Complete list of known events from Cloudflare Stream player documentation
    const playerEvents = [
        'abort', 'canplaythrough', 'durationchange', 'error', 'loadeddata', 'loadedmetadata', 'loadstart', 'pause', 'progress', 'ratechange', 'seeked', 'seeking', 'suspend', 'timeupdate', 'volumechange', 'play', 'playing', 'ended', 'canplay', 'stalled', 'waiting'
    ];

    // Generic event handler for all player events
    const handlePlayerEvent = (e) => {
        debugLog(`Event: ${e.type}`, e);

        // Reset seekingCounter for specific events to avoid it being reset for 'seeking' event
        if (['play', 'playing', 'ended', 'canplay', 'stalled', 'waiting', 'progress'].includes(e.type)) {
            seekingCounter = 0;
        }

        switch (e.type) {
            case 'play':
                seekingCounter = 17;
                break;
            case 'playing':
                debugLog('Hiding Poster');
                posterImage.style.display = 'none';
                break;
            case 'ended':
                debugLog('Showing Ended poster');
                posterImage.src = 'https://hitchstream.com/wp-content/uploads/2024/01/hs_blank.png';
                posterImage.style.display = 'block';
                break;
            case 'canplay':
                debugLog('Can Play. Playing and Hiding Poster');
                player.play();
                posterImage.style.display = 'none';
                break;
            case 'stalled':
                debugLog('Stalled : Doing nothing');
                // posterImage.src = 'https://hitchstream.com/wp-content/uploads/2024/01/connecting.png';
                // posterImage.style.display = 'block';
                break;
            case 'error':
                debugLog('Error : Showing Blank poster');
                posterImage.src = 'https://hitchstream.com/wp-content/uploads/2024/01/hs_blank.png';
                posterImage.style.display = 'block';
                break;
            case 'waiting':
                waitingCounter++; // Increment the waiting counter
                debugLog('Waiting : Event, waitingCounter:', waitingCounter);
                if (waitingCounter >= 14) {
                    tryRecoveringStream(); // Run recovery after every 4th waiting event
                    waitingCounter = 0; // Reset the counter
                }
                break;
            case 'seeking':
                seekingCounter++;
                debugLog('Seeking : Event, seekingCounter:', seekingCounter);
                if (seekingCounter > 17) {
                    tryRecoveringStream();
                    seekingCounter = 0;
                }
                break;
            default:
                // No action for the other events
                break;
        }
    };

    // Register generic event listeners for all known events
    playerEvents.forEach(eventType => {
        player.addEventListener(eventType, handlePlayerEvent);
    });

    // Function to fetch the current video UID and update the player source if it has changed
    const fetchCurrentUIDAndUpdatePlayer = () => {
        debugLog('Checking for new UID...');
        jQuery.ajax({
            url: ajaxurl, // Make sure ajaxurl is correctly defined
            type: 'POST',
            dataType: 'text', // Expecting a plain text response containing the UID
            data: {
                'action': 'fetch_video_uid',
                'live_input_id': liveInputId // Use the variable passed from PHP
            },
            success: (response) => {
                const newUID = response.trim(); // Trim the response to ensure no whitespace issues
                const currentSrc = playerElement.getAttribute('src');
                const newSrc = `https://customer-juu1r5es4cbffqjf.cloudflarestream.com/${newUID}/iframe?poster=https%3A%2F%2Fhitchstream.com%2Fwp-content%2Fuploads%2F2024%2F01%2Fhs_blank.png&autoplay=true`;

                // Update the iframe src only if the UID has changed
                if (!currentSrc.includes(newUID)) {
                    playerElement.setAttribute('src', newSrc);
                }
            },
            error: (error) => {
                console.error('Error fetching current video UID:', error);
            }
        });
    };

    // Optionally, call this function periodically to check for live stream updates
    setInterval(fetchCurrentUIDAndUpdatePlayer, 30000); // Check every 30 seconds



    player.play().catch(() => {
        debugLog('playback failed, muting to try again');
        //        player.muted = true;
        player.play();
    });


    const tryRecoveringStream = () => {
        debugLog('Attempting to recover the stream by reloading the player iframe');

        const playerIframe = document.getElementById('stream-player');

        if (playerIframe) {
            const playerSrc = playerIframe.src;

            playerIframe.src = '';
            playerIframe.src = playerSrc;
        } else {
            console.error('Player iframe not found');
        }
    };



});
