<?php
function get_status_ajax_callback() {
    global $post;
    $post_id = intval( $_POST['post_id'] );
    $post = get_post( $post_id );
    setup_postdata( $post );
    $IsComplete = get_status();
    wp_reset_postdata();
    echo $IsComplete;
    wp_die();
}
 
add_action('wp_ajax_get_status_ajax_', 'get_status_ajax_callback');
add_action('wp_ajax_nopriv_get_status_ajax_', 'get_status_ajax_callback'); // For non-logged-in users.

function hide_admin_bar_on_specific_page() {
    if (is_page_template('hitchstreamcontrols.php')) { // Replace 'hitchstreamcontrols.php' with the correct template file name if different
        show_admin_bar(false);
    }
}
add_action('template_redirect', 'hide_admin_bar_on_specific_page');

function enqueue_scripts() {
    global $post;
    wp_enqueue_script('my-script', get_stylesheet_directory_uri() . '/js/status.js', array('jquery'), '1.0', true);
    wp_localize_script('my-script', 'jsData', array('ajaxurl' => admin_url('admin-ajax.php'), 'post_id' => $post->ID));
}

add_action('wp_enqueue_scripts', 'enqueue_scripts');


//the rest of functions.php goes here

function display_current_date_shortcode() {
    return date('F j, Y'); // You can customize the date format here
}
add_shortcode('current_date', 'display_current_date_shortcode');

function populate_current_date_for_hidden_field($tag) {
    if ($tag['name'] == 'entered_date') {
        $tag['values'] = array(date('F j, Y')); // Example: August 19, 2024
    }
    return $tag;
}
add_filter('wpcf7_form_tag', 'populate_current_date_for_hidden_field', 10, 1);

function cf7_get_text_shortcode($atts) {
    $key = isset($atts['key']) ? $atts['key'] : '';

    // Set default prices for the packages
    $default_prices = [
        'The Perfect Day' => '3400',
        'The I Do' => '999',
        'The Vow' => '1500',
        'The Kiss' => '2150',
    ];

    // Get the package from the URL
    $package = isset($_GET['package']) ? esc_html($_GET['package']) : '';

    // Get the OR value from the URL
    $value = isset($_GET[$key]) ? esc_html($_GET[$key]) : '';

    // If no OR value provided, set default based on the package
    if ($key === 'OR' && empty($value) && !empty($package) && isset($default_prices[$package])) {
        $value = $default_prices[$package];
    }

    return $value;
}
add_shortcode('cf7_get_text', 'cf7_get_text_shortcode');


function cf7_get_hidden_field( $atts ) {
    $key = isset($atts['key']) ? $atts['key'] : '';
    return isset($_POST[$key]) ? esc_html($_POST[$key]) : '';
}
add_shortcode('cf7_get_hidden', 'cf7_get_hidden_field');

function my_theme_enqueue_styles() {

    $parent_style = 'parent-style';

    wp_enqueue_style( $parent_style, get_template_directory_uri() . '/style.css' );
    wp_enqueue_style( 'child-style',
        get_stylesheet_directory_uri() . '/style.css',
        array( $parent_style ),
        wp_get_theme()->get('Version')
    );
}
add_action( 'wp_enqueue_scripts', 'my_theme_enqueue_styles' );

if ( ! function_exists( 'hs_get_icon_html' ) ) {
	function hs_get_icon_html( $icon, $url, $el_class, $target = '', $el_style = '' ) {
		
		$icon_set = substr( $icon, 0, 2 );
		$icon = substr( $icon, 3 );
		
		if( substr( $url, 0, 3 ) == 'www') $url = 'http://' . $url;

		$link = '';
		
		if ( $url != '' && $url != '#' && substr( $url, 0, 4 ) != 'http' && substr( $url, 0, 5 ) != 'https'  && substr( $url, 0, 6 ) != 'mailto' ) {
			$link_tmp = get_posts(
				array(
					'name'      => $url,
					'post_type' => 'page'
				)
			);
			if ( ( is_array( $link_tmp ) && count( $link_tmp ) > 0 && isset( $link_tmp[0]->ID ) ) ) {
				if ( substr( $url, 0, 4 ) == 'http' ) {
					$link = $url;
				} else {
					$link = get_permalink( $link_tmp[0]->ID );
				}
				
			} else {
				$link = $url;
			}
		} else {
			$link = $url;
		}
		

		if ( $target != '' ) $target = ' target = "' . ( $target ) . '"';

		$ico_tag = 'a ';
		if ( $link == '' ) {
			$ico_tag = 'span ';
			$ico_tag_end = 'span';	
		} else {
			$ico_tag = 'a href="' . esc_url_raw( $link ) . '" ' . ( $target );
			$ico_tag_end = 'a';
		}

		$style_attr = '';
		if ( $el_style != '' ) {
			$style_attr = ' ' . 'style="color:inherit; ' . esc_attr( $el_style ) . '"';
		}

		return '<span class="hsIco ' . esc_attr( $el_class ) . '" ' . $style_attr . '><' . $ico_tag . ' data-ico-' . esc_attr( $icon_set ) . '="&#x' . esc_attr( $icon ) . ';" class="hsIcoHolder" style="color:inherit;"></' . $ico_tag_end . '></span>';
	}
}


if (  ! function_exists( 'get_couplename' ) ) {
	function get_couplename() {
		//Get filtered content of post
        ob_start();
        the_content();
        $content = ob_get_clean();

        //Define matching pattern: 
        $find_pattern = '~<cont_couplename>(.*)</cont_couplename>~';

        //Variable to hold results
        $content_matches = array();

        //Search content for pattern
        preg_match( $find_pattern, $content, $content_matches );

        //First match will be 2nd item in array (first is entire content, if any matches)
        if ( count( $content_matches ) > 1 ){
            return $content_matches[1];
        }
        $nomatches = "";
        //Fallback: return post title
        return $nomatches;
    }
}

if (  ! function_exists( 'get_colorblocks' ) ) {
	function get_colorblocks() {
		//Get filtered content of post
        ob_start();
        the_content();
        $content = ob_get_clean();

        //Define matching pattern: 
        $find_pattern = '~<cont_colors>(.*)</cont_colors>~';

        //Variable to hold results
        $content_matches = array();

        //Search content for pattern
        preg_match( $find_pattern, $content, $content_matches );

        //First match will be 2nd item in array (first is entire content, if any matches)
        if ( count( $content_matches ) > 1 ){
            return $content_matches[1];
        }
        $nomatches = "";
        //Fallback: return post title
        return $nomatches;
    }
}

if (  ! function_exists( 'get_background' ) ) {
	function get_background() {
		//Get filtered content of post
        ob_start();
        the_content();
        $content = ob_get_clean();

        //Define matching pattern: 
        $find_pattern = '~<cont_background>(.*)</cont_background>~';

        //Variable to hold results
        $content_matches = array();

        //Search content for pattern
        preg_match( $find_pattern, $content, $content_matches );

        //First match will be 2nd item in array (first is entire content, if any matches)
        if ( count( $content_matches ) > 1 ){
            return $content_matches[1];
        }
        $nomatches = "";
        //Fallback: return post title
        return $nomatches;
    }
}

if (  ! function_exists( 'get_coupleimage' ) ) {
	function get_coupleimage() {
		//Get filtered content of post
        ob_start();
        the_content();
        $content = ob_get_clean();

        //Define matching pattern: 
        $find_pattern = '~<cont_coupleimage>(.*)</cont_coupleimage>~';

        //Variable to hold results
        $content_matches = array();

        //Search content for pattern
        preg_match( $find_pattern, $content, $content_matches );

        //First match will be 2nd item in array (first is entire content, if any matches)
        if ( count( $content_matches ) > 1 ){
            return $content_matches[1];
        }
        $nomatches = "";
        //Fallback: return post title
        return $nomatches;
    }
}

if (  ! function_exists( 'get_venuelogo' ) ) {
	function get_venuelogo() {
		//Get filtered content of post
        ob_start();
        the_content();
        $content = ob_get_clean();

        //Define matching pattern: 
        $find_pattern = '~<cont_venuelogo>(.*)</cont_venuelogo>~';

        //Variable to hold results
        $content_matches = array();

        //Search content for pattern
        preg_match( $find_pattern, $content, $content_matches );

        //First match will be 2nd item in array (first is entire content, if any matches)
        if ( count( $content_matches ) > 1 ){
            return $content_matches[1];
        }
        $nomatches = "";
        //Fallback: return post title
        return $nomatches;
    }
}

if (  ! function_exists( 'get_venuephoto' ) ) {
	function get_venuephoto() {
		//Get filtered content of post
        ob_start();
        the_content();
        $content = ob_get_clean();

        //Define matching pattern: 
        $find_pattern = '~<cont_venuephoto>(.*)</cont_venuephoto>~';

        //Variable to hold results
        $content_matches = array();

        //Search content for pattern
        preg_match( $find_pattern, $content, $content_matches );

        //First match will be 2nd item in array (first is entire content, if any matches)
        if ( count( $content_matches ) > 1 ){
            return $content_matches[1];
        }
        $nomatches = "";
        //Fallback: return post title
        return $nomatches;
    }
}

if (  ! function_exists( 'get_player' ) ) {
	function get_player() {
		//Get filtered content of post
        ob_start();
        the_content();
        $content = ob_get_clean();

        //Define matching pattern: 
        $find_pattern = '~<cont_player>(.*)</cont_player>~';

        //Variable to hold results
        $content_matches = array();

        //Search content for pattern
        preg_match( $find_pattern, $content, $content_matches );

        //First match will be 2nd item in array (first is entire content, if any matches)
        if ( count( $content_matches ) > 1 ){
            return $content_matches[1];
        }
        $nomatches = "";
        //Fallback: return post title
        return $nomatches;
    }
}

if (  ! function_exists( 'get_datetime' ) ) {
	function get_datetime() {
		//Get filtered content of post
        ob_start();
        the_content();
        $content = ob_get_clean();

        //Define matching pattern: 
        $find_pattern = '~<cont_datetime>(.*)</cont_datetime>~';

        //Variable to hold results
        $content_matches = array();

        //Search content for pattern
        preg_match( $find_pattern, $content, $content_matches );

        //First match will be 2nd item in array (first is entire content, if any matches)
        if ( count( $content_matches ) > 1 ){
            return $content_matches[1];
        }
        $nomatches = "";
        //Fallback: return post title
        return $nomatches;
    }
}

if (  ! function_exists( 'get_aftertext' ) ) {
	function get_aftertext() {
		//Get filtered content of post
        ob_start();
        the_content();
        $content = ob_get_clean();

        //Define matching pattern:
        $find_pattern = '~<cont_aftertext>(.*)</cont_aftertext>~';

        //Variable to hold results
        $content_matches = array();

        //Search content for pattern
        preg_match( $find_pattern, $content, $content_matches );

        //First match will be 2nd item in array (first is entire content, if any matches)
        if ( count( $content_matches ) > 1 ){
            return $content_matches[1];
        }
        $nomatches = "";
        //Fallback: return post title
        return $nomatches;
    }
}
    
if (  ! function_exists( 'get_beforetext' ) ) {
	function get_beforetext() {
		//Get filtered content of post
        ob_start();
        the_content();
        $content = ob_get_clean();

        //Define matching pattern:
        $find_pattern = '~<cont_beforetext>(.*)</cont_beforetext>~';

        //Variable to hold results
        $content_matches = array();

        //Search content for pattern
        preg_match( $find_pattern, $content, $content_matches );

        //First match will be 2nd item in array (first is entire content, if any matches)
        if ( count( $content_matches ) > 1 ){
            return $content_matches[1];
        }
        $nomatches = "";
        //Fallback: return post title
        return $nomatches;
    }
}

if (  ! function_exists( 'get_venueevent' ) ) {
	function get_venueevent() {
		//Get filtered content of post
        ob_start();
        the_content();
        $content = ob_get_clean();

        //Define matching pattern:
        $find_pattern = '~<cont_venueevent>(.*)</cont_venueevent>~';

        //Variable to hold results
        $content_matches = array();

        //Search content for pattern
        preg_match( $find_pattern, $content, $content_matches );

        //First match will be 2nd item in array (first is entire content, if any matches)
        if ( count( $content_matches ) > 1 ){
            return $content_matches[1];
        }
        $nomatches = "";
        //Fallback: return post title
        return $nomatches;
    }
}
    
if (  ! function_exists( 'get_socials' ) ) {
	function get_socials() {
		//Get filtered content of post
        ob_start();
        the_content();
        $content = ob_get_clean();

        //Define matching pattern: 
        $find_pattern = '~<cont_socials>(.*)</cont_socials>~';

        //Variable to hold results
        $content_matches = array();

        //Search content for pattern
        preg_match( $find_pattern, $content, $content_matches );

        //First match will be 2nd item in array (first is entire content, if any matches)
        if ( count( $content_matches ) > 1 ){
            return $content_matches[1];
        }
        $nomatches = "";
        //Fallback: return post title
        return $nomatches;
    }
} 

if (  ! function_exists( 'get_pagetimezone' ) ) {
	function get_pagetimezone() {
		//Get filtered content of post
        ob_start();
        the_content();
        $content = ob_get_clean();

        //Define matching pattern: 
        $find_pattern = '~<cont_pagetimezone>(.*)</cont_pagetimezone>~';

        //Variable to hold results
        $content_matches = array();

        //Search content for pattern
        preg_match( $find_pattern, $content, $content_matches );

        //First match will be 2nd item in array (first is entire content, if any matches)
        if ( count( $content_matches ) > 1 ){
            return $content_matches[1];
        }
        $nomatches = "";
        //Fallback: return post title
        return $nomatches;
    }
} 

if (  ! function_exists( 'get_venue' ) ) {
	function get_venue() {
		//Get filtered content of post
        ob_start();
        the_content();
        $content = ob_get_clean();

        //Define matching pattern: 
        $find_pattern = '~<cont_venue>(.*)</cont_venue>~';

        //Variable to hold results
        $content_matches = array();

        //Search content for pattern
        preg_match( $find_pattern, $content, $content_matches );

        //First match will be 2nd item in array (first is entire content, if any matches)
        if ( count( $content_matches ) > 1 ){
            return $content_matches[1];
        }
        $nomatches = "";
        //Fallback: return post title
        return $nomatches;
    }
} 

if (  ! function_exists( 'get_eventtitle' ) ) {
	function get_eventtitle() {
		//Get filtered content of post
        ob_start();
        the_content();
        $content = ob_get_clean();

        //Define matching pattern: 
        $find_pattern = '~<cont_eventtitle>(.*)</cont_eventtitle>~';

        //Variable to hold results
        $content_matches = array();

        //Search content for pattern
        preg_match( $find_pattern, $content, $content_matches );

        //First match will be 2nd item in array (first is entire content, if any matches)
        if ( count( $content_matches ) > 1 ){
            return $content_matches[1];
        }
        $nomatches = "";
        //Fallback: return post title
        return $nomatches;
    }
} 

function get_status() {
    global $post;
    $cache_key = 'status_content_' . $post->ID;
    $cached_value = get_transient($cache_key);

    if ($cached_value !== false) {
        return $cached_value;
    }

    // Get filtered content of the post
    ob_start();
    the_content();
    $content = ob_get_clean(); // Retrieve and clear the output buffer

    // Define matching pattern
    $find_pattern = '~<cont_status>(.*)</cont_status>~';

    // Variable to hold results
    $content_matches = array();

    // Search content for the pattern
    preg_match($find_pattern, $content, $content_matches);

    // First match will be the 2nd item in the array (first is the entire content, if any matches)
    if (count($content_matches) > 1) {
        set_transient($cache_key, $content_matches[1], 60*60); // Cache the result for 1 hour
        return $content_matches[1];
    }
    $nomatches = "Not finding it";
    // Fallback: return the message
    return $nomatches;
}

function invalidate_status_cache($post_id) {
    $cache_key = 'status_content_' . $post_id;
    delete_transient($cache_key); // Remove the cached value
}

add_action('save_post', 'invalidate_status_cache');


if (  ! function_exists( 'hs_get_all' ) ) { 
    function hs_get_all() {
        $allcontent = get_socials() . ',,' . get_couplename() . ',,' . get_player() . ',,' . get_beforetext() . ',,' . get_aftertext() . ',,' . get_colorblocks() . ',,' . get_datetime() . ',,' . get_coupleimage() . ',,' . get_pagetimezone() . ',,' . get_venue() . ',,' . get_venuelogo() . ',,' . get_venuephoto() . ',,' . get_venueevent() . ',,' . get_background() . ',,' . get_eventtitle();
        return $allcontent;
    }
}

//Limit the size of the log file
function limit_debug_log_size() {
    $file = WP_CONTENT_DIR . '/debug.log';
    $max_size = 5000000; // 5 MB
    $buffer = 4096;

    if (file_exists($file) && filesize($file) > $max_size) {
        $read_fp = fopen($file, 'rb');
        $write_fp = fopen("{$file}.tmp", 'wb');

        fseek($read_fp, -$max_size, SEEK_END); // Set read position
        fgets($read_fp, $buffer); // Discard first partial line

        while (!feof($read_fp)) {
            fwrite($write_fp, fgets($read_fp, $buffer));
        }

        fclose($write_fp);
        fclose($read_fp);
        rename("{$file}.tmp", $file);
    }
}
add_action('init', 'limit_debug_log_size');

function fetch_current_video_uid($live_input_id) {
    $cloudflare_email = get_option('HSCF_cloudflare_email');
    $cloudflare_api_key = get_option('HSCF_cloudflare_api_key');
    $cloudflare_account_id = get_option('HSCF_cloudflare_account_id');

    $url = "https://api.cloudflare.com/client/v4/accounts/$cloudflare_account_id/stream/live_inputs/$live_input_id/videos";
    $args = array(
        'headers' => array(
            'X-Auth-Email' => $cloudflare_email,
            'X-Auth-Key' => $cloudflare_api_key,
            'Content-Type' => 'application/json'
        )
    );

    $response = wp_remote_get($url, $args);
    $uid = ''; // Default to empty UID

    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200) {
        $data = json_decode(wp_remote_retrieve_body($response), true);

        // First, check for a live-inprogress video
        foreach ($data['result'] as $video) {
            if ($video['status']['state'] === 'live-inprogress') {
                return $video['uid']; // Return immediately if a live video is found
            }
        }

        // If no live video, then look for the most recent recording in a "ready" state
        foreach ($data['result'] as $video) {
            if ($video['status']['state'] === 'ready') {
                return $video['uid']; // Return the first "ready" video found
            }
        }
    }

    return ''; // Return empty if no suitable video is found
}

// Expose UID fetching through AJAX for dynamic JavaScript access
add_action('wp_ajax_fetch_video_uid', 'ajax_fetch_current_video_uid');
add_action('wp_ajax_nopriv_fetch_video_uid', 'ajax_fetch_current_video_uid');

function ajax_fetch_current_video_uid() {
    $live_input_id = isset($_POST['live_input_id']) ? sanitize_text_field($_POST['live_input_id']) : '';
    echo fetch_current_video_uid($live_input_id);
    wp_die(); // Properly end the execution of AJAX
}

// ---------------------------------------------------------------------------
// HitchStream Player — Webhook helpers
// ---------------------------------------------------------------------------

/**
 * Verify HMAC-SHA256 signature on incoming Cloudflare webhooks.
 * Returns true if valid, or if no secret is configured (grace period).
 */
function hs_verify_webhook_signature($body, $signature) {
    $secret = get_option('HSCF_webhook_secret', '');
    if (!$secret) {
        return true; // Grace period — no secret configured yet.
    }
    if (!$signature) {
        return false;
    }
    $expected = hash_hmac('sha256', $body, $secret);
    return hash_equals($expected, $signature);
}

/**
 * Store a normalized live-state in a WordPress transient.
 * Debounces updates within a 3-second window per live input.
 */
function hs_update_live_state_transient($input_id, $state, $video_uid = '', $error_code = '') {
    $last_update = get_transient("hs_webhook_update_ts_{$input_id}");
    if ($last_update && (time() - $last_update) < 3) {
        return false; // Debounced.
    }

    $ttl = 300;
    if ($state === 'reconnecting') $ttl = 120;
    if ($state === 'error')         $ttl = 3600;

    $data = [
        'state'      => $state,
        'videoUID'   => $video_uid,
        'error_code' => $error_code,
        'ts'         => time(),
    ];

    set_transient("hs_live_state_{$input_id}", $data, $ttl);
    set_transient("hs_webhook_update_ts_{$input_id}", time(), 5);
    return true;
}

/**
 * Determine if a live input is currently live based on cached webhook state.
 * Returns false if no cached state or state is not live.
 */
function hs_compute_server_live_state($input_id) {
    $data = get_transient("hs_live_state_{$input_id}");
    if (!$data) return false;
    return in_array($data['state'], ['live', 'reconnected', 'new_configuration_accepted']);
}

/**
 * Register webhooks with Cloudflare for a given live input.
 * Call during setup or from an admin UI.
 */
function hs_register_cf_webhook($input_id, $callback_url, $secret) {
    $email    = get_option('HSCF_cloudflare_email', '');
    $api_key  = get_option('HSCF_cloudflare_api_key', '');
    $account  = get_option('HSCF_cloudflare_account_id', '');

    if (!$email || !$api_key || !$account) {
        return ['error' => 'Cloudflare credentials not configured'];
    }

    $ch = curl_init("https://api.cloudflare.com/client/v4/accounts/{$account}/stream/live_inputs/{$input_id}/webhooks");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Auth-Email: {$email}",
        "X-Auth-Key: {$api_key}",
        "Content-Type: application/json",
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'callback_uri' => $callback_url,
        'callback_auth' => [
            'strategy' => 'secret_header',
            'secret'   => $secret,
        ],
        'events' => ['live_input.connected', 'live_input.disconnected', 'live_input.errored'],
    ]));
    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code < 200 || $http_code >= 300) {
        return ['error' => 'Cloudflare API error', 'status' => $http_code];
    }

    return json_decode($resp, true);
}

/**
 * Remove a webhook from Cloudflare for a given live input.
 */
function hs_unregister_cf_webhook($input_id, $webhook_uid) {
    $email    = get_option('HSCF_cloudflare_email', '');
    $api_key  = get_option('HSCF_cloudflare_api_key', '');
    $account  = get_option('HSCF_cloudflare_account_id', '');

    if (!$email || !$api_key || !$account) {
        return ['error' => 'Cloudflare credentials not configured'];
    }

    $ch = curl_init("https://api.cloudflare.com/client/v4/accounts/{$account}/stream/live_inputs/{$input_id}/webhooks/{$webhook_uid}");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Auth-Email: {$email}",
        "X-Auth-Key: {$api_key}",
        "Content-Type: application/json",
    ]);
    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $http_code, 'body' => json_decode($resp, true)];
}


//Disable WordPress Deprecated Warnings
add_filter('deprecated_function_trigger_error', 'disable_all_deprecated_warnings');
add_filter('deprecated_argument_trigger_error', 'disable_all_deprecated_warnings');
add_filter('deprecated_file_trigger_error',     'disable_all_deprecated_warnings');
//Not to trigger any errors when a deprecated function or method is called.
add_filter( 'deprecated_hook_trigger_error',    'disable_all_deprecated_warnings');
function disable_all_deprecated_warnings($bolean) {
    return false;
}



    
