<?php
/* Template Name: HitchStream Player */

// B5.4: Content-Security-Policy — restrict the player page to only what it needs.
// 'self' is required on connect-src so the player can poll its own live-state
// endpoint on hitchstream.com. img-src is explicit so default-src 'none' does
// not block poster images. media-src includes 'self' for self-hosted fallback
// assets.
header("Content-Security-Policy: "
    . "default-src 'none';"
    . "script-src 'self' 'unsafe-inline';"
    . "style-src 'self' 'unsafe-inline';"
    . "font-src 'self';"
    . "img-src 'self' https:;"
    . "frame-src https://hitchstream.com;"
    . "connect-src 'self' https://*.cloudflarestream.com;"
    . "media-src 'self' https://*.cloudflarestream.com;",
    true
);

// Generate a short-lived nonce and endpoint URL for the client.
$hs_player_nonce  = wp_create_nonce('hs_player_action');
$live_state_url   = esc_url(get_stylesheet_directory_uri() . '/endpoints/live-state.php');

// Read player defaults from admin settings. NO silent fallback to a hardcoded
// customer code — if HSCF_customer_id is unset, the player will fatal loudly,
// surfacing the misconfiguration to the operator.
$cf_customer_id   = get_option('HSCF_customer_id', '');
$poster_initial   = get_option('HSCF_poster_initial', 'https://hitchstream.com/wp-content/uploads/2024/04/Poster_Initial_Default.png');
$poster_idle      = get_option('HSCF_poster_idle', 'https://hitchstream.com/wp-content/uploads/2024/04/Poster_Idle_Default.png');
$poster_fatal     = get_option('HSCF_poster_fatal', 'https://hitchstream.com/wp-content/uploads/2025/09/Poster_fatal_2.png');

// Determine server-side live state from webhook cache (hint to the player; not authoritative).
$input_id_for_server = get_query_var('inputId') ?: '';
if ($input_id_for_server && function_exists('hs_compute_server_live_state')) {
    $server_is_live = (hs_compute_server_live_state($input_id_for_server)) ? 'true' : 'false';
} else {
    $server_is_live = 'false';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <script src="<?php echo get_stylesheet_directory_uri(); ?>/js/vendor/hls-1.6.16.min.js"></script>
    <script type="module" src="<?php echo get_stylesheet_directory_uri(); ?>/js/HSPlayer/index.js"></script>
    <script>
        // window.HSPlayerConfig is the contract surface the new modular player
        // reads at startup. Shape per HitchStream_Player_v2.md §4 / §5.
        window.HSPlayerConfig = {
            endpoints: {
                liveState: <?php echo wp_json_encode($live_state_url); ?>
            },
            cloudflare: {
                customerCode: <?php echo wp_json_encode($cf_customer_id); ?>
            },
            posters: {
                initial: <?php echo wp_json_encode($poster_initial); ?>,
                idle:    <?php echo wp_json_encode($poster_idle); ?>,
                fatal:   <?php echo wp_json_encode($poster_fatal); ?>
            },
            server: {
                isLive: <?php echo $server_is_live === 'true' ? 'true' : 'false'; ?>
            },
            errorMessages: {
                ERR_STORAGE_QUOTA_EXHAUSTED: "This stream has temporarily exceeded its storage limit. Please contact the host.",
                ERR_MISSING_SUBSCRIPTION: "This stream's subscription is inactive. Please contact the host."
            },
            debug: /[?&]debug=1\b/.test(window.location.search)
        };
    </script>

    <style>
        /* Self-hosted Josefin Sans (variable, latin) — served from the theme so
           the strict CSP only needs font-src 'self'. Used by the player's
           under-logo messages (font-family declared in the shadow DOM). */
        @font-face {
            font-family: 'Josefin Sans';
            font-style: normal;
            font-weight: 100 700;
            font-display: swap;
            src: url('<?php echo get_stylesheet_directory_uri(); ?>/fonts/josefin-sans-latin.woff2') format('woff2');
        }
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

            function getURLParameter(name) {
                return new URLSearchParams(window.location.search).get(name);
            }

            const inputId          = getURLParameter('inputId');
            const initialPosterURL = getURLParameter('initialposterURL');
            const idlePosterURL    = getURLParameter('idleposterURL');
            const fatalPosterURL   = getURLParameter('posterFatalURL');
            const live             = getURLParameter('live') === 'true';
            const autoplay         = getURLParameter('autoplay') !== 'false'; // default true

            // URL params override server defaults for posters.
            if (initialPosterURL) hsVideo.setAttribute('poster-initial', initialPosterURL);
            if (idlePosterURL)    hsVideo.setAttribute('poster-idle', idlePosterURL);
            if (fatalPosterURL)   hsVideo.setAttribute('poster-fatal', fatalPosterURL);

            if (hsVideo && typeof hsVideo.setApiInfo === 'function') {
                hsVideo.setApiInfo({
                    inputId: inputId,
                    isLive: live,
                    autoplay: autoplay,
                    posterInitialURL: initialPosterURL || undefined,
                    posterIdleURL:    idlePosterURL    || undefined,
                    posterFatalURL:   fatalPosterURL   || undefined
                });
            }
        });
    </script>

</body>

</html>
