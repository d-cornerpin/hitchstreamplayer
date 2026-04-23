<?php
/**
 * Plugin Name: HitchStream_CloudFlare
 * Plugin URI: https://www.hitchsteam.com/HitchStream_Cloudflare
 * Description: Custom plugin for controlling HitchStream's Cloudflare streaming interface.
 * Version: 1.0.1
 * Author: David Cliff
 * Author URI: https://davecliff.io
 */

function get_max_execution_time() {
    $max_execution_time = ini_get('max_execution_time'); // Fetches the max execution time in seconds

    // Format the time for display
    if ($max_execution_time > 60) {
        $minutes = floor($max_execution_time / 60);
        $seconds = $max_execution_time % 60;
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ($seconds > 0 ? ' and ' . $seconds . ' seconds' : '');
    } else {
        return $max_execution_time . ' seconds';
    }
}

function HSCF_list_cloudflare_videos() {
    $api_key = get_option("HSCF_cloudflare_api_key");
    $account_id = get_option("HSCF_cloudflare_account_id");
    $email = get_option("HSCF_cloudflare_email");

    $url = "https://api.cloudflare.com/client/v4/accounts/$account_id/stream";
    $response = wp_remote_get($url, [
        'headers' => [
            'X-Auth-Email' => $email,
            'X-Auth-Key' => $api_key,
            'Content-Type' => 'application/json'
        ]
    ]);

    if (is_wp_error($response)) {
        return "Error fetching videos: " . $response->get_error_message();
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['success']) && $data['success']) {
        return $data['result'];
    } else {
        return "Failed to fetch videos.";
    }
}



function get_formatted_max_upload_size() {
    $max_upload = ini_get('upload_max_filesize');
    $max_post = ini_get('post_max_size');

    $max_upload_bytes = convertPHPSizeToBytes($max_upload);
    $max_post_bytes = convertPHPSizeToBytes($max_post);

    $max_file_size = min($max_upload_bytes, $max_post_bytes);
    return formatSizeUnits($max_file_size);
}

function convertPHPSizeToBytes($size) {
    $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
    $size = preg_replace('/[^0-9\.]/', '', $size);
    if ($unit) {
        return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
    }
    return round($size);
}

function formatSizeUnits($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return $bytes . ' byte';
    } else {
        return '0 bytes';
    }
}



function my_plugin_templates($post_type) {
    $templates = array();

    if ('page' === $post_type) {
        $templates[] = 'hitchstreamcontrols.php';
    }

    return $templates;
}
add_filter('theme_template_compat', 'my_plugin_templates');

function my_plugin_register_template() {
    $filepath = plugin_dir_path(__FILE__) . 'hitchstreamcontrols.php';

    if (is_readable($filepath)) {
        locate_template($filepath, true, false);
    }
}
add_action('init', 'my_plugin_register_template');


add_action("admin_menu", "HitchStream_CloudFlare_setup_menu");

function HSCF_upload_video() {
    if (!empty($_FILES['video_file']) && $_FILES['video_file']['error'] == UPLOAD_ERR_OK) {
        $api_key = get_option("HSCF_cloudflare_api_key");
        $account_id = get_option("HSCF_cloudflare_account_id");
        $email = get_option("HSCF_cloudflare_email");

        // Prepare to get the TUS endpoint from Cloudflare
        $ch = curl_init("https://api.cloudflare.com/client/v4/accounts/$account_id/media");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "X-Auth-Email: $email",
            "X-Auth-Key: $api_key",
            "Tus-Resumable: 1.0.0",
            "Upload-Length: " . $_FILES['video_file']['size'],
            "Upload-Metadata: filename " . base64_encode($_FILES['video_file']['name'])
        ]);

        $response = curl_exec($ch);
        $info = curl_getinfo($ch);

        if ($info['http_code'] == 201) {
            $location = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            wp_send_json_success(['location' => $location]);
        } else {
            wp_send_json_error('Error creating TUS session: ' . $response);
        }
        curl_close($ch);
    }
}
add_action('wp_ajax_hscf_upload_video', 'HSCF_upload_video');



function HitchStream_CloudFlare_setup_menu()
{
    add_menu_page("CloudFlare Setup for HitchStream", "HS CloudFlare", "manage_options", "HitchStream_Cloudflare", "HSCF_Admin");
    add_action("admin_init", "HSCF_register_settings");
}

function HSCF_register_settings()
{
    // Register a new setting for Cloudflare API key and Account ID
    register_setting("HSCF_settings", "HSCF_cloudflare_email");
    register_setting("HSCF_settings", "HSCF_cloudflare_api_key");
    register_setting("HSCF_settings", "HSCF_cloudflare_account_id");

    // Add field for Cloudflare Email
    add_settings_field("HSCF_cloudflare_email_field", "CloudFlare Email", "HSCF_cloudflare_email_field_callback", "HitchStream_Cloudflare", "HSCF_cloudflare_settings_section");

    // Add a new section in the admin page
    add_settings_section("HSCF_cloudflare_settings_section", "CloudFlare API Settings", "HSCF_settings_section_callback", "HitchStream_Cloudflare");

    // Add fields for API key and Account ID
    add_settings_field("HSCF_cloudflare_api_key_field", "CloudFlare API Key", "HSCF_cloudflare_api_key_field_callback", "HitchStream_Cloudflare", "HSCF_cloudflare_settings_section");

    add_settings_field("HSCF_cloudflare_account_id_field", "CloudFlare Account ID", "HSCF_cloudflare_account_id_field_callback", "HitchStream_Cloudflare", "HSCF_cloudflare_settings_section");
}

function HSCF_settings_section_callback()
{
}

function HSCF_cloudflare_email_field_callback()
{
    $setting = get_option("HSCF_cloudflare_email");
    echo "<input type='email' name='HSCF_cloudflare_email' value='" . esc_attr($setting) . "' />";
}

function HSCF_cloudflare_api_key_field_callback()
{
    // Get the value of the setting we've registered with register_setting()
    $setting = get_option("HSCF_cloudflare_api_key");
    // Output the field
    echo "<input type='text' name='HSCF_cloudflare_api_key' value='" . esc_attr($setting) . "' />";
}

function HSCF_cloudflare_account_id_field_callback()
{
    $setting = get_option("HSCF_cloudflare_account_id");
    echo "<input type='text' name='HSCF_cloudflare_account_id' value='" . esc_attr($setting) . "' />";
}

function HSCF_get_live_inputs()
{
    $api_key = get_option("HSCF_cloudflare_api_key");
    $account_id = get_option("HSCF_cloudflare_account_id");
    $email = get_option("HSCF_cloudflare_email");

    if (!$api_key || !$account_id || !$email) {
        return "API Key, Account ID, and/or Email is missing.";
    }

    $url = "https://api.cloudflare.com/client/v4/accounts/$account_id/stream/live_inputs";

    $response = wp_remote_get($url, ["headers" => ["X-Auth-Key" => $api_key, "X-Auth-Email" => $email, "Content-Type" => "application/json"]]);

    if (is_wp_error($response)) {
        return "Error fetching live inputs: " . $response->get_error_message();
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body);

    if (!$data || !$data->success) {
        return "Failed to fetch live inputs.";
    }

    foreach ($data->result as $input) {
        $detail_url = "https://api.cloudflare.com/client/v4/accounts/$account_id/stream/live_inputs/" . $input->uid;
        $detail_response = wp_remote_get($detail_url, ["headers" => ["X-Auth-Key" => $api_key, "X-Auth-Email" => $email, "Content-Type" => "application/json"]]);
        
        if (!is_wp_error($detail_response)) {
            $detail_body = wp_remote_retrieve_body($detail_response);
            $detail_data = json_decode($detail_body);

            if ($detail_data && $detail_data->success && is_object($detail_data->result)) {
                $input->srt_details = isset($detail_data->result->srt) ? $detail_data->result->srt : null;
                $input->rtmp_details = isset($detail_data->result->rtmps) ? $detail_data->result->rtmps : null;
                $input->status_details = isset($detail_data->result->status->current->state) ? $detail_data->result->status->current->state : "Status Unavailable";
            }
        }
    }

    return $data->result;
}

function HSCF_ajax_get_live_inputs() {
    $live_inputs = HSCF_get_live_inputs();
    
    if (is_array($live_inputs)) {
        $inputs_data = array();
        foreach ($live_inputs as $input) {
            $input_name = isset($input->meta) && isset($input->meta->name) ? $input->meta->name : "Unnamed Input";
            $rtmpKey = isset($input->rtmp_details) ? esc_html($input->rtmp_details->streamKey) : "";
            
            // Combine UID and RTMP key with a delimiter
            $value = $input->uid . "|" . $rtmpKey;

            $inputs_data[] = array(
                'name' => $input_name,
                'value' => $value // UID and RTMP key combined
            );
        }
        wp_send_json_success($inputs_data);
    } else {
        wp_send_json_error('Failed to fetch live inputs.');
    }
}

add_action('wp_ajax_get_live_inputs', 'HSCF_ajax_get_live_inputs');

function HSCF_delete_live_input($input_id)
{
    $api_key = get_option("HSCF_cloudflare_api_key");
    $account_id = get_option("HSCF_cloudflare_account_id");
    $email = get_option("HSCF_cloudflare_email");

    if (!$api_key || !$account_id || !$email) {
        return "API Key, Account ID, and/or Email is missing.";
    }

    $url = "https://api.cloudflare.com/client/v4/accounts/$account_id/stream/live_inputs/$input_id";

    $response = wp_remote_request($url, ["method" => "DELETE", "headers" => ["X-Auth-Key" => $api_key, "X-Auth-Email" => $email, "Content-Type" => "application/json"]]);

    if (is_wp_error($response)) {
        return "Error deleting live input: " . $response->get_error_message();
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body);

    if (!$data) {
        return "Failed to decode response.";
    }

    if ($data->success) {
        return "Live input deleted successfully.";
    } else {
        return "Failed to delete live input: " . json_encode($data->errors);
    }
}

function HSCF_create_live_input($stream_name)
{
    $api_key = get_option("HSCF_cloudflare_api_key");
    $account_id = get_option("HSCF_cloudflare_account_id");
    $email = get_option("HSCF_cloudflare_email");

    $url = "https://api.cloudflare.com/client/v4/accounts/$account_id/stream/live_inputs";

    $body = ["meta" => ["name" => $stream_name], "recording" => ["mode" => "automatic"]];

    $response = wp_remote_post($url, ["headers" => ["X-Auth-Key" => $api_key, "X-Auth-Email" => $email, "Content-Type" => "application/json"], "body" => json_encode($body)]);

    if (is_wp_error($response)) {
        // Handle error appropriately
        error_log("Error creating live input: " . $response->get_error_message());
        return;
    }

    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body);

    if (!$data || !$data->success) {
        // Handle failure appropriately
        error_log("Failed to create live input");
        return;
    }

    // Optionally handle the successful creation response
}

function HSCF_enqueue_scripts_admin() {
wp_enqueue_script("hscf-admin-script", plugin_dir_url(__FILE__) . "js/hscf-admin.js", ["jquery"], null, true);
wp_localize_script("hscf-admin-script", "ajax_object", ["ajax_url" => admin_url("admin-ajax.php")]);
}

function HSCF_enqueue_scripts_frontend() {
    if (current_user_can('manage_options')) { // Checks if the user is an admin
        wp_enqueue_script("hscf-admin-script-frontend", plugin_dir_url(__FILE__) . "js/hscf-admin.js", ["jquery"], null, true);
        wp_localize_script("hscf-admin-script-frontend", "ajax_object", ["ajax_url" => admin_url("admin-ajax.php")]);
    }
}

// Enqueue script for the admin dashboard
add_action("admin_enqueue_scripts", "HSCF_enqueue_scripts_admin");

// Enqueue script for the front-end for admin users
add_action("wp_enqueue_scripts", "HSCF_enqueue_scripts_frontend");




function hscf_check_live_input_status()
{
    $live_inputs = HSCF_get_live_inputs();

    if (is_string($live_inputs)) {
        // If the function returned an error string, send an error response
        wp_send_json_error($live_inputs);
        return;
    }

    $statuses = [];
    foreach ($live_inputs as $input) {
        // Assuming $input includes 'uid' and 'status_details'
        $statuses[$input->uid] = isset($input->status_details) ? $input->status_details : "Status Unavailable";
    }

    wp_send_json_success($statuses);
}
add_action("wp_ajax_hscf_check_live_input_status", "hscf_check_live_input_status");

function HSCF_get_live_input_outputs($input_id)
{
    $api_key = get_option("HSCF_cloudflare_api_key");
    $account_id = get_option("HSCF_cloudflare_account_id");
    $email = get_option("HSCF_cloudflare_email");

    if (!$api_key || !$account_id || !$email) {
        return "API Key, Account ID, and/or Email is missing.";
    }

    $url = "https://api.cloudflare.com/client/v4/accounts/$account_id/stream/live_inputs/$input_id/outputs";

    $response = wp_remote_get($url, ["headers" => ["X-Auth-Key" => $api_key, "X-Auth-Email" => $email, "Content-Type" => "application/json"]]);

    if (is_wp_error($response)) {
        return "Error fetching outputs: " . $response->get_error_message();
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body);

    if (!$data || !$data->success) {
        return "Failed to fetch outputs.";
    }

    return $data->result;
}

add_action("wp_ajax_hscf_delete_output", "hscf_delete_output");

function hscf_delete_output()
{
    $output_id = sanitize_text_field($_POST["output_id"]);
    $input_id = sanitize_text_field($_POST["input_id"]);
    $api_key = get_option("HSCF_cloudflare_api_key");
    $account_id = get_option("HSCF_cloudflare_account_id");
    $email = get_option("HSCF_cloudflare_email");

    $url = "https://api.cloudflare.com/client/v4/accounts/$account_id/stream/live_inputs/$input_id/outputs/$output_id";

    $response = wp_remote_request($url, ["method" => "DELETE", "headers" => ["X-Auth-Key" => $api_key, "X-Auth-Email" => $email, "Content-Type" => "application/json"]]);

    if (is_wp_error($response)) {
        wp_send_json_error("Error deleting output: " . $response->get_error_message());
        return;
    }

    wp_send_json_success("Output deleted successfully.");
}

add_action("wp_ajax_hscf_create_output", "hscf_create_output");

function hscf_create_output()
{
    $streamKey = sanitize_text_field($_POST["stream_key"]);
    $url = sanitize_text_field($_POST["stream_url"]); // Corrected from 'url' to 'stream_url'
    $input_id = sanitize_text_field($_POST["input_id"]);
    $api_key = get_option("HSCF_cloudflare_api_key");
    $account_id = get_option("HSCF_cloudflare_account_id");
    $email = get_option("HSCF_cloudflare_email");

    if (!$api_key || !$account_id || !$email || !$streamKey || !$url) {
        wp_send_json_error("Missing required information");
        return;
    }

    $api_url = "https://api.cloudflare.com/client/v4/accounts/$account_id/stream/live_inputs/$input_id/outputs";
    $body = json_encode(["enabled" => true, "streamKey" => $streamKey, "url" => $url]);

    $response = wp_remote_post($api_url, ["headers" => ["X-Auth-Key" => $api_key, "X-Auth-Email" => $email, "Content-Type" => "application/json"], "body" => $body]);

    if (is_wp_error($response)) {
        wp_send_json_error("Error creating output: " . $response->get_error_message());
        return;
    }

    $response_body = json_decode(wp_remote_retrieve_body($response), true);

    if ($response_body && $response_body["success"]) {
        wp_send_json_success("Output created successfully.");
    } else {
        wp_send_json_error("Failed to create output");
    }
}

add_action("wp_ajax_hscf_toggle_output", "hscf_toggle_output");

function hscf_toggle_output()
{
    $output_id = sanitize_text_field($_POST["output_id"]);
    $input_id = sanitize_text_field($_POST["input_id"]);
    $enabled = filter_var($_POST["enabled"], FILTER_VALIDATE_BOOLEAN);
    $api_key = get_option("HSCF_cloudflare_api_key");
    $account_id = get_option("HSCF_cloudflare_account_id");
    $email = get_option("HSCF_cloudflare_email");

    if (!$api_key || !$account_id || !$email || !$output_id || $input_id === null) {
        wp_send_json_error("Missing required information");
        return;
    }

    $api_url = "https://api.cloudflare.com/client/v4/accounts/$account_id/stream/live_inputs/$input_id/outputs/$output_id";
    $body = json_encode(["enabled" => $enabled]);

    $response = wp_remote_request($api_url, ["method" => "PUT", "headers" => ["X-Auth-Key" => $api_key, "X-Auth-Email" => $email, "Content-Type" => "application/json"], "body" => $body]);

    if (is_wp_error($response)) {
        wp_send_json_error("Error updating output: " . $response->get_error_message());
        return;
    }

    $response_body = wp_remote_retrieve_body($response);
    $decoded_response = json_decode($response_body, true);

    if (!$decoded_response) {
        wp_send_json_error("Failed to decode response body: " . $response_body);
        return;
    }

    if (isset($decoded_response["success"]) && $decoded_response["success"]) {
        wp_send_json_success("Output updated successfully.");
    } else {
        $error_message = isset($decoded_response["errors"]) ? json_encode($decoded_response["errors"]) : "Unknown error";
        wp_send_json_error("Failed to update output: " . $error_message);
    }
}

function HSCF_get_videos_by_stream_name($stream_name)
{
    $api_key = get_option("HSCF_cloudflare_api_key");
    $account_id = get_option("HSCF_cloudflare_account_id");
    $email = get_option("HSCF_cloudflare_email");

    $url = "https://api.cloudflare.com/client/v4/accounts/$account_id/stream";

    $response = wp_remote_get($url, ["headers" => ["X-Auth-Key" => $api_key, "X-Auth-Email" => $email, "Content-Type" => "application/json"]]);

    if (is_wp_error($response)) {
        // Handle error
        error_log("Error fetching videos: " . $response->get_error_message());
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (!$data || !$data["success"]) {
        // Handle failure to fetch videos
        error_log("Failed to fetch videos");
        return;
    }

    $filtered_videos = [];
    foreach ($data["result"] as $video) {
        if (isset($video["meta"]["name"]) && strpos(strtolower($video["meta"]["name"]), strtolower($stream_name)) !== false) {
            $video_detail_url = "https://api.cloudflare.com/client/v4/accounts/$account_id/stream/{$video["uid"]}/downloads";
            $detail_response = wp_remote_get($video_detail_url, ["headers" => ["X-Auth-Key" => $api_key, "X-Auth-Email" => $email, "Content-Type" => "application/json"]]);

            if (!is_wp_error($detail_response)) {
                $detail_body = wp_remote_retrieve_body($detail_response);
                $detail_data = json_decode($detail_body, true);

                if ($detail_data && $detail_data["success"]) {
                    foreach ($detail_data["result"] as $download) {
                        if (isset($download["url"]) && strpos($download["url"], ".mp4") !== false) {
                            $video["mp4_download_url"] = $download["url"];
                            break;
                        }
                    }
                }
            }

            $filtered_videos[] = $video;
        }
    }

    return $filtered_videos;
}

add_action("wp_ajax_hscf_create_download", "HSCF_create_download");

function HSCF_create_download()
{
    $video_id = sanitize_text_field($_POST["video_id"]);
    $api_key = get_option("HSCF_cloudflare_api_key");
    $account_id = get_option("HSCF_cloudflare_account_id");
    $email = get_option("HSCF_cloudflare_email");

    if (!$api_key || !$account_id || !$email || !$video_id) {
        wp_send_json_error("Missing required information");
        return;
    }

    $api_url = "https://api.cloudflare.com/client/v4/accounts/$account_id/stream/$video_id/downloads";

    $response = wp_remote_post($api_url, ["headers" => ["X-Auth-Key" => $api_key, "X-Auth-Email" => $email, "Content-Type" => "application/json"]]);

    if (is_wp_error($response)) {
        wp_send_json_error("Error creating download: " . $response->get_error_message());
        return;
    }

    wp_send_json_success("Download created successfully.");
}

add_action("wp_ajax_hscf_check_download_status", "hscf_check_download_status");

function hscf_check_download_status()
{
    $video_id = sanitize_text_field($_POST["video_id"]);
    $api_key = get_option("HSCF_cloudflare_api_key");
    $account_id = get_option("HSCF_cloudflare_account_id");
    $email = get_option("HSCF_cloudflare_email");

    $api_url = "https://api.cloudflare.com/client/v4/accounts/$account_id/stream/$video_id/downloads";

    $response = wp_remote_get($api_url, ["headers" => ["X-Auth-Key" => $api_key, "X-Auth-Email" => $email, "Content-Type" => "application/json"]]);

    if (is_wp_error($response)) {
        wp_send_json_error("Error checking download status: " . $response->get_error_message());
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    // Check if there is an MP4 download URL
    if (!empty($data["result"]) && is_array($data["result"])) {
        foreach ($data["result"] as $download) {
            if (isset($download["url"]) && strpos($download["url"], ".mp4") !== false) {
                wp_send_json_success(["download_url" => $download["url"]]);
                return;
            }
        }
    }

    wp_send_json_error("MP4 download URL not available yet.");
}

function hscf_delete_recording()
{
    $video_id = sanitize_text_field($_POST["video_id"]);
    $api_key = get_option("HSCF_cloudflare_api_key");
    $account_id = get_option("HSCF_cloudflare_account_id");
    $email = get_option("HSCF_cloudflare_email");

    if (!$api_key || !$account_id || !$email || !$video_id) {
        wp_send_json_error("Missing required information");
        return;
    }

    $api_url = "https://api.cloudflare.com/client/v4/accounts/$account_id/stream/$video_id";

    $response = wp_remote_request($api_url, ['method' => 'DELETE', 'headers' => ['X-Auth-Key' => $api_key, 'X-Auth-Email' => $email, 'Content-Type' => 'application/json']]);

    if (is_wp_error($response)) {
        wp_send_json_error('Error deleting recording: ' . $response->get_error_message());
        return;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    // Send the entire response for debugging
    wp_send_json_success(['response_code' => $response_code, 'response_body' => $response_body]);
}

add_action('wp_ajax_hscf_delete_recording', 'hscf_delete_recording');

function get_video_files() {
    $apiUrl = 'https://streamer1.hitchstream.com/api/list-videos';
    $apiKey = '72c020a8d042a1f549b548311d1e4577';

    // Set headers
    $headers = [
        "X-API-KEY" => $apiKey,
        "Content-Type" => "application/json",
    ];

    // Send the GET request
    $response = wp_remote_get($apiUrl, [
        'headers' => $headers,
    ]);

    // Check for errors
    if (is_wp_error($response)) {
        // Send the error message as JSON
        wp_send_json_error($response->get_error_message());
    } else {
        // Send the response data as JSON
        wp_send_json($response['body']);
    }
}
add_action('wp_ajax_get_video_files', 'get_video_files');

function start_placeholderstream()
{
    $videoFile = $_POST['videoFile'];
    $rtmpsUrl = $_POST['rtmpsUrl'];
    $rtmpsKey = $_POST['rtmpsKey'];
    // API URL of your Node.js app
    $apiUrl = 'https://streamer1.hitchstream.com/api/start-streaming';

    // API Key
    $apiKey = '72c020a8d042a1f549b548311d1e4577';

    // Set headers
    $headers = [
        "X-API-KEY" => $apiKey,
        "Content-Type" => "application/json",
    ];

    // Prepare the payload
    $data = [
        'videoFile' => $videoFile,
        'rtmpsUrl' => $rtmpsUrl,
        'rtmpsKey' => $rtmpsKey,
    ];

    // Use wp_remote_post to send the request
    $response = wp_remote_post($apiUrl, [
        'headers' => $headers,
        'body' => json_encode($data),
    ]);

    // Check for errors
    if (is_wp_error($response)) {
        // Send the error message as JSON
        wp_send_json_error($response->get_error_message());
    } else {
        // Send the response data as JSON
        wp_send_json($response['body']);
    }
}
add_action('wp_ajax_start_placeholderstream', 'start_placeholderstream');

function stop_placeholderstream()
{
    // API URL of your Node.js app
    $apiUrl = 'https://streamer1.hitchstream.com/api/stop-streaming';

    // API Key
    $apiKey = '72c020a8d042a1f549b548311d1e4577';

    // Set headers
    $headers = [
        "X-API-KEY" => $apiKey,
        "Content-Type" => "application/json",
    ];

    // Use wp_remote_post to send the request
    $response = wp_remote_post($apiUrl, [
        'headers' => $headers,
    ]);

    // Check for errors
    if (is_wp_error($response)) {
        // Send the error message as JSON
        wp_send_json_error($response->get_error_message());
    } else {
        // Send the response data as JSON
        wp_send_json($response['body']);
    }
}
add_action('wp_ajax_stop_placeholderstream', 'stop_placeholderstream');

function check_stream_state()
{
    // API URL of your Node.js app
    $apiUrl = 'https://streamer1.hitchstream.com/api/stream-state';

    // API Key
    $apiKey = '72c020a8d042a1f549b548311d1e4577';

    // Set headers
    $headers = [
        "X-API-KEY" => $apiKey,
    ];

    // Use wp_remote_get to send the request
    $response = wp_remote_get($apiUrl, [
        'headers' => $headers,
    ]);

    // Check for errors
    if (is_wp_error($response)) {
        // Send the error message as JSON
        wp_send_json_error($response->get_error_message());
    } else {
        // Send the response data as JSON
        wp_send_json($response['body']);
    }
}
add_action('wp_ajax_check_stream_state', 'check_stream_state');

function HSCF_Admin()
{
    // Check for deletion and process it
    if (isset($_GET["delete_input"]) && isset($_GET["confirm_delete"]) && $_GET["confirm_delete"] == "yes") {
        HSCF_delete_live_input(sanitize_text_field($_GET["delete_input"]));
    }

    // Check for stream creation
    if (isset($_POST["create_stream"]) && !empty($_POST["stream_name"])) {
        HSCF_create_live_input(sanitize_text_field($_POST["stream_name"]));
    }
    
    // Check if the form has been submitted and handle the upload
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['video_file'])) {
        HSCF_upload_video();
    }
    ?>
<div class="wrap">
    <div id="cfModal" class="cfmodal">
        <div class="cfmodalbox">
            <span class="close">&times;</span>
            <div class="cfmodal-content">
                <p>...</p>
            </div>
        </div>
    </div>
    <h1>HitchStream CloudFlare Setup</h1>
    <div class="top-container">
        <div class="form-container">
            <h2>Create Stream</h2>
            <form method="post" action="">
                <input type="text" name="stream_name" placeholder="Enter stream name" required>
                <input type="submit" name="create_stream" value="Create" class="copy-btn">
            </form>
            <h2>Placeholder Video</h2>
            <select class="FileSelector"></select>
        </div>

        <div class="middle-img-container">
            <div class="middleitem">
                <a href="https://solo.liveu.tv/dashboard" target="_blank">
                    <img src="https://hitchstream.com/wp-content/uploads/2024/03/livedashboard.png" alt="LiveU Dashboard" />
                </a>
            </div>
            <div class="middleitem">
                <a href="https://streamer1.hitchstream.com" target="_blank">
                    <img src="https://hitchstream.com/wp-content/uploads/2024/04/placeholderstreamer.png" alt="Placeholder Streamer" />
                </a>
            </div>
            <!--
            <div class="speedtest middleitem" style="width:150px;height:150px;">
                <iframe style="border:none;width:100%;height:100%;border:none;border-radius:10px;overflow:hidden !important;" src="//openspeedtest.com/speedtest"></iframe>
            </div>
-->
            <div class="middleitem">
                <a href="https://hitchstream.com/control" target="_blank">
                    <img src="https://hitchstream.com/wp-content/uploads/2024/04/controller.png" alt="HitchStream Controller" />
                </a>
            </div>
            <div class="middleitem">
                <a href="https://dash.cloudflare.com/83584468f508b713b66fd41d85e58280/stream/videos" target="_blank">
                    <img src="https://hitchstream.com/wp-content/uploads/2024/03/cloudflaredashboard.png" alt="CloudFlare Dashboard" />
                </a>
            </div>
        </div>

        <div class="form-container right">
            <form method="post" action="options.php">
                <?php
                settings_fields("HSCF_settings");
                do_settings_sections("HitchStream_Cloudflare");
                submit_button();
                ?>
            </form>
        </div>
    </div>

    <h2>Current Streams</h2>
    <style>
        .FileSelector {
            width: 250px;
        }

        .cfmodal {
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
        .cfmodalbox {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 360px;
            border-radius: 10px;
            filter: drop-shadow(0px 5px 10px);
        }

        .cfmodal-content {
            overflow-wrap: break-word;
            padding-top: 10px;
        }

        .modalcontentbox {
            background-color: #e9e9e9;
            border: 1px solid #999999;
            color: black;
            padding: 5px;
            border-radius: 3px;
        }

        #progress-container {
            width: 100%;
            background: #eee;
            display: none;
        }

        #progress-bar {
            height: 20px;
            width: 0%;
            background-color: #2271b1;
        }



        /* The Close Button */
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }

        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        .top-container {
            display: flex;
            justify-content: space-between;
        }

        .form-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
        }

        .middle-img-container {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: flex-end;
        }

        .middleitem {
            padding: 0px 10px 0px 10px;

        }

        .form-container.right {
            align-items: flex-end;
        }

        .form-table th {
            padding: 5px;
            vertical-align: middle;
        }

        .form-table td {
            padding: 5px;
            vertical-align: middle;
        }

        .live-input-container {
            display: flex;
            margin-bottom: 20px;
            background-color: #e5e5e5;
        }

        .live-input-left,
        .live-input-middle-1,
        .live-input-middle-2,
        .live-input-right {
            flex: 1;
        }

        .status-connected {
            color: green;
        }

        .status-disconnected {
            color: red;
        }

        .copy-btn {
            background: #2271b1;
            border-color: #2271b1;
            color: #fff;
            text-decoration: none;
            text-shadow: none;
            font-size: 13px;
            line-height: 2.15384615;
            min-height: 30px;
            margin: 5px;
            padding: 0 10px;
            cursor: pointer;
            border-width: 1px;
            border-style: solid;
            -webkit-appearance: none;
            border-radius: 3px;
            white-space: nowrap;
            box-sizing: border-box;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 30px;
            height: 17px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            -webkit-transition: .4s;
            transition: .4s;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 17px;
            width: 16px;
            left: 0px;
            bottom: 0px;
            background-color: white;
            -webkit-transition: .4s;
            transition: .4s;
        }

        input:checked+.slider {
            background-color: #2271b1;
        }

        input:focus+.slider {
            box-shadow: 0 0 1px #2271b1;
        }

        input:checked+.slider:before {
            -webkit-transform: translateX(14px);
            -ms-transform: translateX(14px);
            transform: translateX(14px);
        }

        /* Rounded sliders */
        .slider.round {
            border-radius: 34px;
        }

        .slider.round:before {
            border-radius: 50%;
        }

        .video-container {
            width: 200px;
            height: auto;
            /* Adjusted to auto to accommodate variable content heights */
            margin: 5px;
            flex: 0 0 auto;
            /* Prevents shrinking and sets the basis to auto */
            display: flex;
            flex-direction: column;
            /* Organizes the child elements (name, player, buttons) in a column */
            align-items: center;
            /* Centers the content horizontally */
            overflow: hidden;
            /* Keeps all content neatly contained */
        }

        .video-container:nth-child(odd) {
            background-color: #e5e5e5;
            /* Sets a light grey background for odd-numbered video containers */
        }


        .video-name {
            height: auto;
            /* Allows the height to adjust based on content */
            min-height: 36px;
            /* Minimum height to accommodate at least one line of text */
            margin: 5px 0;
            /* Adds vertical spacing */
            text-align: center;
            /* Centers the text horizontally */
            word-wrap: break-word;
            /* Ensures words do not overflow the container */
            line-height: 18px;
            /* Line height to manage the spacing between lines of text */
        }

        #videos-panel {
            display: flex;
            flex-wrap: wrap;
            /* Allows items to wrap to the next line */
            align-items: flex-start;
            /* Aligns items at the start of the line */
            justify-content: center;
            /* Centers containers horizontally */
        }

        .video-buttons {
            display: flex;
            justify-content: center;
            margin-top: 10px;
            /* Space between video and buttons */
        }

        .video-buttons button {
            width: 24px;
            height: 24px;
            background-size: cover;
            border: none;
            cursor: pointer;
            margin: 0 5px;
            /* 10px space between buttons */
        }

    </style>
    <?php
    $live_inputs = HSCF_get_live_inputs();
    if (is_array($live_inputs)) {
        foreach ($live_inputs as $input) {
            $input_name = isset($input->meta) && isset($input->meta->name) ? $input->meta->name : "Unnamed Input";
            $delete_link = admin_url("admin.php?page=HitchStream_Cloudflare&delete_input=" . esc_attr($input->uid) . "&confirm_delete=yes");
            $status_class = isset($input->status_details) ? ($input->status_details === "connected" ? "status-connected" : "status-disconnected") : "";

            echo '<div class="live-input-container">';

            // Left Div
            echo '<div class="live-input-left">';
            echo "<strong>" .
                esc_html($input_name) .
                "</strong> | <span id='status-" .
                esc_attr($input->uid) .
                "' class='" .
                $status_class .
                "'>" .
                esc_html(isset($input->status_details) ? $input->status_details : "Status Unavailable") .
                "</span>";
            echo "<br><br><strong>For Streaming App :</strong><br>";
            if (isset($input->srt_details)) {
                $baseSRTUrl = "srt://live.hitchstream.com:778";
                $simplifiedSRTUrl = "live.hitchstream.com";
                $portSRT = "778";
                $passphrase = esc_html($input->srt_details->passphrase);
                $streamId = esc_html($input->srt_details->streamId);

                // Full SRT URL with all details
                $SRTLatency = 5000000;
                $SRTmaxbw = 3500000;
                $SRTsndbuf = 64777216;
                $SRTrcvbuf = 64777216;
                $fullSRTUrl = $baseSRTUrl . "?passphrase=" . $passphrase . "&streamid=" . $streamId . "&latency=" . $SRTLatency . "&maxbw=" . $SRTmaxbw . "&sndbuf=" . $SRTsndbuf . "&rcvbuf=" . $SRTrcvbuf;

                // Button for SRT URL
//                echo '<br><button class="copy-btn" data-srt-base-url="' . esc_attr($baseSRTUrl) . '">SRT URL</button>';

                // Button for Passphrase
//                echo '<button class="copy-btn" data-passphrase="' . esc_attr($passphrase) . '">SRT Passphrase</button>';

                // Button for StreamId
//                echo '<button class="copy-btn" data-streamid="' . esc_attr($streamId) . '">SRT StreamID</button>';

                // Button for copying the full SRT string
                //    echo '<button class="copy-btn" data-full-srt-url="' . esc_attr($fullSRTUrl) . '">SRT String</button><br>';
                echo '<button class="copy-btn generateSRTvmix" data-simplified-srt-url="' . $simplifiedSRTUrl . '" data-port-srt="' . $portSRT . '" data-passphrase="' . $passphrase . '" data-stream-id="' . $streamId . '">SRT (vMix)</button>';
                echo '<button class="copy-btn generateSRTobs" data-base-srt-url="' . htmlspecialchars($baseSRTUrl . "?passphrase=" . $passphrase . "&streamid=" . $streamId) . '">SRT (OBS)</button><br>';
            }

            if (isset($input->rtmp_details)) {
                $rtmpURL = "rtmps://live.hitchstream.com:443/live/";
                $rtmpKey = esc_html($input->rtmp_details->streamKey);
                echo '<button class="copy-btn copy-rtmp-url-btn" data-rtmp-url="' . esc_attr($rtmpURL) . '">RTMP URL</button>';
                echo '<button class="copy-btn copy-rtmp-key-btn" data-rtmp-key="' . esc_attr($rtmpKey) . '">RTMP Key</button>';
            }
            echo "<br><strong>For Customer Page:</strong>";
            if (isset($input->uid)) {
                echo '<br><button class="copy-btn copy-input-id-btn" data-input-id="' . esc_attr($input->uid) . '">Live Input ID</button>';
                echo '<br><button class="copy-btn copy-embed-code-btn" data-input-id="' . esc_attr($input->uid) . '">Embed Code</button>';
            }

            echo '<div class="stream-toggle-container">';
            echo '<span class="stream-toggle-title"><strong>Placeholder Stream: </strong></span>';
            echo '<label class="switch">';
            echo '<br><input type="checkbox" class="stream-toggle" id="streamToggle-' . esc_attr($input->uid) . '" data-rtmp-key="' . $rtmpKey . '">';
            echo '<span class="slider round"></span></label>';
            echo '</div>';

            echo '<br><a href="' . esc_url($delete_link) . '" onclick="return confirm(\'Are you sure you wish to delete ' . esc_js($input_name) . '?\')">DELETE STREAM</a>';
            echo "</div>"; // Close live-input-left
            // Middle Div 1 for Outputs
            echo '<div class="live-input-middle-1">';
            echo "<strong><br><br>New Social Stream:</strong>";
            echo '<form class="create-output-form" id="create-output-form">';
            echo '<input type="hidden" name="input_id" value="' . esc_attr($input->uid) . '">';
            echo '<input type="text" id="stream-key-input" name="stream_key" placeholder="Stream Key" required>';
            echo '<input type="text" id="stream-url-input" name="url" placeholder="URL" required>';
            echo '<input type="submit" class="copy-btn" value="Create Output">';
            echo "</form>";
            echo "<div><br><strong>Social Streams:</strong></div>";
            $outputs = HSCF_get_live_input_outputs($input->uid);
            if (is_array($outputs)) {
                foreach ($outputs as $output) {
                    echo "<div>";

                    // Check if URL contains "youtube" or "facebook"
                    if (strpos($output->url, "youtube") !== false) {
                        echo "YouTube ";
                    } elseif (strpos($output->url, "facebook") !== false) {
                        echo "Facebook ";
                    } else {
                        echo esc_html($output->url) . " ";
                    }

                    // Toggle switch
                    $checked = $output->enabled ? "checked" : "";
                    echo '<label class="switch"><input type="checkbox" class="output-toggle" data-output-id="' .
                        esc_attr($output->uid) .
                        '" data-input-id="' .
                        esc_attr($input->uid) .
                        '" ' .
                        $checked .
                        '><span class="slider round"></span></label> ';

                    // Delete link
                    echo '<a href="#" class="delete-output-link" data-output-id="' . esc_attr($output->uid) . '" data-input-id="' . esc_attr($input->uid) . '" title="Delete Output" style="position: relative;">';
                    echo '<img src="https://hitchstream.com/wp-content/uploads/2024/01/delete_icon.png" width="18px" height="18px" alt="Delete Output" style="vertical-align: middle;">';
                    echo '</a>';

                    echo "</div>";
                }
            } else {
                echo "<p>" . esc_html($outputs) . "</p>";
            }

            echo "</div>"; // Close live-input-middle-1

            // Middle Div 2
            echo '<div class="live-input-middle-2">';

            echo "<br><br><strong>Recordings (" . esc_html($input_name) . "):</strong><br>";
            $filteredVideos = HSCF_get_videos_by_stream_name($input_name);
            if (is_array($filteredVideos) && !empty($filteredVideos)) {
                foreach ($filteredVideos as $video) {
                    $nameParts = explode(" ", $video["meta"]["name"]);
                    $dateString = implode(" ", array_slice($nameParts, -5)); // Extract date part
                    try {
                        $date = new DateTime($dateString, new DateTimeZone("UTC"));
                        $date->setTimezone(new DateTimeZone("MST"));
                        $formattedDate = $date->format("M d Y h:iA T");
                    } catch (Exception $e) {
                        $formattedDate = $dateString; // Fallback to original if parsing fails
                    }

                    $videoUrl = "https://customer-juu1r5es4cbffqjf.cloudflarestream.com/" . esc_attr($video["uid"]) . "/watch";
                    $iconUrl = isset($video["mp4_download_url"]) ? "https://hitchstream.com/wp-content/uploads/2024/01/downloadmp4_finish.png" : "https://hitchstream.com/wp-content/uploads/2024/01/downloadmp4_start.png";
                    $downloadLink = isset($video["mp4_download_url"]) ? $video["mp4_download_url"] : "#";
                    $deleteIconUrl = "https://hitchstream.com/wp-content/uploads/2024/01/delete_icon.png";
                    $embedIconUrl = "https://hitchstream.com/wp-content/uploads/2024/01/embedcode.png"; // Embed icon URL
                    // Determine the title for the download icon
                    $downloadTitle = isset($video["mp4_download_url"]) ? "Download" : "Create download";

                    echo "<div style='display: flex; align-items: center;'>";
                    echo '<a href="' . $videoUrl . '" target="_blank">' . esc_html($formattedDate) . "</a>&nbsp;";
//                    GPT LOOK HERE DOWNLOAD BUTTON 1
                    echo '<a href="' . $downloadLink . '" class="create-download-link" data-video-id="' . esc_attr($video["uid"]) . '" style="margin-right: 10px;">'; // Add margin to the right
                    echo '<img src="' . $iconUrl . '" title="' . $downloadTitle . '">';
                    echo "</a>";
                    // Embed Icon
                    echo '<a href="#" class="generate-videoid" data-video-id="' . esc_attr($video["uid"]) . '" style="margin-right: 10px;">'; // Add margin to the right
                    echo '<img src="' . $embedIconUrl . '" title="Get Video ID">';
                    echo "</a>";
                    echo '<a href="#" class="delete-video-link" data-video-id="' . esc_attr($video["uid"]) . '">'; // No margin for the last icon
                    echo '<img src="' . $deleteIconUrl . '" title="Delete Video">';
                    echo "</a>";
                    echo "</div>";
                }
            } else {
                echo "<p>No recorded videos for this stream.</p>";
            }
            echo "</div>"; // Close live-input-middle-2

            // Right Div
            echo '<div class="live-input-right">';
            echo '<div style="position: relative;">';
            echo '<iframe src="https://hitchstream.com/player?live=true&inputId=' .
                esc_attr($input->uid) .
                '" style="border: none; width: 100%; aspect-ratio: 16 / 9;" 
                allow="fullscreen; accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture" 
                allowfullscreen>
                </iframe>';
            echo "</div>";
            echo "</div>"; // Close live-input-right
            echo "</div>"; // Close live-input-container
        }
    } else {
        echo "<p>" . esc_html($live_inputs) . "</p>";
        echo '<script src="https://unpkg.com/tus-js-client@2"></script>';
    }?>
    <div id="Videos Panel">
        <div class="form-container">
            <h2>Upload a Video (Max file size: <?php echo get_formatted_max_upload_size(); ?>, Max upload time: <?php echo get_max_execution_time(); ?>)</h2>
            <form id="video-upload-form" method="post" enctype="multipart/form-data">
                <input type="file" name="video_file" class="copy-btn" required>
                <input type="submit" name="upload_video" value="Upload Video" class="copy-btn">
                <img src="https://hitchstream.com/wp-content/uploads/2024/01/loading_icon2.gif" style="display: none; width: 10px; height: 10px;" id="loading-icon">
            </form>
        </div>
        <h2>Uploaded Videos</h2>
        <div id="videos-panel">
            <!-- ID for the container to apply flex styles -->
            <?php
$videos = HSCF_list_cloudflare_videos();
if (is_array($videos)) {
    foreach ($videos as $video) {
        
        $iconUrl = isset($video["mp4_download_url"]) ? "https://hitchstream.com/wp-content/uploads/2024/01/downloadmp4_finish.png" : "https://hitchstream.com/wp-content/uploads/2024/01/downloadmp4_start.png";
        $downloadLink = isset($video["mp4_download_url"]) ? $video["mp4_download_url"] : "#";
        
        $deleteIconUrl = "https://hitchstream.com/wp-content/uploads/2024/01/delete_icon.png";
        $embedIconUrl = "https://hitchstream.com/wp-content/uploads/2024/01/embedcode.png"; // Embed icon URL
        // Determine the title for the download icon
        $downloadTitle = isset($video["mp4_download_url"]) ? "Download" : "Create download";

        echo '<div class="video-container">';
        echo '<p class="video-name">' . esc_html($video['meta']['name']) . '</p>';
        echo '<iframe src="https://hitchstream.com/player?live=false&autoplay=false&inputId=' .
            esc_attr($video['uid']) .
            '" style="border: none; width: 100%; aspect-ratio: 16 / 9;" 
            allow="fullscreen; accelerometer; gyroscope; encrypted-media; picture-in-picture" 
            allowfullscreen>
            </iframe>';

        
        echo '<div class="video-buttons">';
//        GPT LOOK HERE DOWNLOAD BUTTON 2
                    echo '<a href="' . $downloadLink . '" class="create-download-link" data-video-id="' . esc_attr($video["uid"]) . '" style="margin-right: 10px;">'; // Add margin to the right
                    echo '<img src="' . $iconUrl . '" title="' . $downloadTitle . '">';
                    echo "</a>";
                    // Embed Icon
                    echo '<a href="#" class="generate-embed-link" data-video-id="' . esc_attr($video["uid"]) . '" style="margin-right: 10px;">'; // Add margin to the right
                    echo '<img src="' . $embedIconUrl . '" title="Get Embed Code">';
                    echo "</a>";
                    echo '<a href="#" class="delete-video-link" data-video-id="' . esc_attr($video["uid"]) . '">'; // No margin for the last icon
                    echo '<img src="' . $deleteIconUrl . '" title="Delete Video">';
                    echo "</a>";
        echo '</div>';
        echo '</div>';
    }
} else {
    echo '<p>' . esc_html($videos) . '</p>';
}


?>


        </div>
    </div>
    <?php
}
?>
