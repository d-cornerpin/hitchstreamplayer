<?php
/* Template Name: HitchStream Player */

// B5.4: Content-Security-Policy — restrict the player page to only what it needs.
// 'self' is required on connect-src so the player can poll its own live-state
// endpoint on hitchstream.com. img-src is explicit so default-src 'none' does
// not block poster images. media-src needs blob: because Hls.js plays through
// MediaSource Extensions, which assigns the <video> a blob: source — without it
// the stream is CSP-blocked and never plays. worker-src needs blob: too, since
// Hls.js demuxes in a Web Worker created from a blob: URL (else it's blocked and
// silently degrades to the main thread).
header("Content-Security-Policy: "
    . "default-src 'none';"
    . "script-src 'self' 'unsafe-inline';"
    . "worker-src 'self' blob:;"
    . "style-src 'self' 'unsafe-inline';"
    . "font-src 'self';"
    . "img-src 'self' https:;"
    . "frame-src https://hitchstream.com;"
    . "connect-src 'self' https://*.cloudflarestream.com;"
    . "media-src 'self' blob: https://*.cloudflarestream.com;",
    true
);

// Generate a short-lived nonce and endpoint URL for the client.
$hs_player_nonce  = wp_create_nonce('hs_player_action');
// Point pollers at the REST route directly. The old URL here was the legacy
// endpoints/live-state.php shim, which bootstraps WordPress just to 301 to
// this same REST route — doubling the PHP cost of every viewer poll.
$live_state_url   = esc_url(rest_url('hitchstream/v1/live-state'));

// Read player defaults from admin settings. NO silent fallback to a hardcoded
// customer code — if HSCF_customer_id is unset, the player will fatal loudly,
// surfacing the misconfiguration to the operator.
$cf_customer_id   = get_option('HSCF_customer_id', '');

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

        /* Slotted poster card — authored in the page (light DOM) so page CSS +
           the self-hosted font apply, even though the player uses shadow DOM.
           The logo is used as a mask (same-origin → allowed by img-src 'self')
           so a soft sheen can sweep across its actual shape. This replaces the
           default poster IMAGE: once the player sees slotted content it hides
           its image-poster layer, so the old Poster_*.png never shows. */
        .logo-shimmer {
            width: min(90%, 860px);
            aspect-ratio: 642.53 / 135.5;
            background-color: #fff;
            position: relative;
            -webkit-mask: url('<?php echo get_stylesheet_directory_uri(); ?>/img/hitchstream-logo-white.svg') no-repeat center / contain;
            mask: url('<?php echo get_stylesheet_directory_uri(); ?>/img/hitchstream-logo-white.svg') no-repeat center / contain;
            /* Slow, gentle "breath" in and out — a calm sign of life on the
               poster instead of the sliding sheen. */
            animation: hs-breathe 18s ease-in-out infinite;
        }
        @keyframes hs-breathe {
            0%, 100% { transform: scale(1); }
            50%      { transform: scale(1.045); }
        }
        @media (prefers-reduced-motion: reduce) {
            .logo-shimmer { animation: none; }
        }
    </style>
</head>

<body>

    <hs-video id="video" autoplay>
        <div slot="poster" class="logo-shimmer" role="img" aria-label="HitchStream"></div>
    </hs-video>

    <!-- The debug overlay is the single top-right panel inside the <hs-video>
         shadow DOM (see DebugPanel.js), shown only with ?debug=1. The old fixed
         bottom bar was removed — its useful fields now live in that one panel. -->

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const hsVideo = document.getElementById('video');

            function getURLParameter(name) {
                return new URLSearchParams(window.location.search).get(name);
            }

            const inputId          = getURLParameter('inputId');
            const live             = getURLParameter('live') === 'true';
            const autoplay         = getURLParameter('autoplay') !== 'false'; // default true

            // Note: legacy poster-image URL params (initialposterURL, idleposterURL,
            // posterFatalURL) are no longer read — the poster is the slotted logo
            // card + animated backdrop. Pages may still append them harmlessly.
            if (hsVideo && typeof hsVideo.setApiInfo === 'function') {
                hsVideo.setApiInfo({
                    inputId: inputId,
                    isLive: live,
                    autoplay: autoplay
                });
            }
        });
    </script>

</body>

</html>
