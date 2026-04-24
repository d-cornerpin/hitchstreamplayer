<?php
/* Template Name: HitchStream Player */
// Generate a short-lived nonce and endpoint URL for the client
$hs_player_nonce  = wp_create_nonce('hs_player_action');
$live_state_url   = esc_url(get_stylesheet_directory_uri() . '/endpoints/live-state.php');

// Read player defaults from admin settings (overridden by URL params in JS)
$cf_customer_id   = get_option('HSCF_customer_id', 'juu1r5es4cbffqjf');
$poster_initial   = get_option('HSCF_poster_initial', 'https://hitchstream.com/wp-content/uploads/2024/04/Poster_Initial_Default.png');
$poster_idle      = get_option('HSCF_poster_idle', 'https://hitchstream.com/wp-content/uploads/2024/04/Poster_Idle_Default.png');
$poster_fatal     = get_option('HSCF_poster_fatal', 'https://hitchstream.com/wp-content/uploads/2025/09/Poster_fatal_2.png');

// Determine server-side live state from webhook cache (used to set initial isLive flag).
$input_id_for_server = get_query_var('inputId') ?: '';
if ($input_id_for_server) {
    $server_is_live = (hs_compute_server_live_state($input_id_for_server)) ? 'true' : 'false';
} else {
    $server_is_live = 'false';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <script src="//cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <script src="<?php echo get_stylesheet_directory_uri(); ?>/js/HSPlayerElement.js"></script>
    <script>
        // Make endpoint URL and nonce available to the player
        var liveStateEndpoint = "<?php echo $live_state_url; ?>";
        var serverIsLive = "<?php echo esc_js($server_is_live); ?>";
        var hsPlayerNonce = "<?php echo esc_js($hs_player_nonce); ?>";
        // Player configurable defaults (overridden by URL params if provided)
        var playerDefaults = {
            customerID:   "<?php echo esc_js($cf_customer_id); ?>",
            posterFatal:  "<?php echo esc_js($poster_fatal); ?>",
        };
    </script>

    <style>
        html,
        body {
            margin: 0;
            padding: 0;
            height: 100%;
            width: 100%;
            overflow: hidden;
        }

    </style>
</head>

<body>

    <hs-video id="video" autoplay></hs-video>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const hsVideo = document.getElementById('video');

            // Read URL parameters helper
            function getURLParameter(name) {
                return new URLSearchParams(window.location.search).get(name);
            }

            // Fetch parameters from the URL
            const inputId = getURLParameter('inputId');
            const initialPosterURL = getURLParameter('initialposterURL');
            const idlePosterURL = getURLParameter('idleposterURL');
            const live = getURLParameter('live') === 'true';
            const autoplay = getURLParameter('autoplay') !== 'false'; // Default is true

            // Configure the player (no secrets sent to client)
            if (hsVideo && typeof hsVideo.setApiInfo === 'function') {
                hsVideo.setApiInfo({
                    inputId: inputId,
                    isLive: live,
                    autoplay: autoplay,
                    customerID: getURLParameter('customerID') || playerDefaults.customerID || '',
                    posterFatalURL: getURLParameter('posterFatalURL') || playerDefaults.posterFatal || ''
                });
            }

            // Apply poster images (URL params override server defaults)
            if (initialPosterURL) hsVideo.setAttribute('poster-initial', initialPosterURL);
            if (idlePosterURL) hsVideo.setAttribute('poster-idle', idlePosterURL);
            if (getURLParameter('posterFatalURL')) hsVideo.setAttribute('poster-fatal', getURLParameter('posterFatalURL'));
        });

    </script>

</body>

</html>
