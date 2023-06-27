<?php
/*
Plugin Name: VK Group Parser
Plugin URI: https://example.com/
Description: Parses posts with images from VK group to WordPress blog.
Version: 1.2
Author: John Doe
Author URI: https://example.com/
*/

// Add plugin settings page
add_action('admin_menu', 'vk_group_parser_add_settings_page');
function vk_group_parser_add_settings_page() {
    add_options_page('VK Group Parser Settings', 'VK Group Parser', 'manage_options', 'vk-group-parser', 'vk_group_parser_settings_page');
}

// Render plugin settings page
function vk_group_parser_settings_page() {
    // Save settings
    if (isset($_POST['submit'])) {
        update_option('vk_group_parser_api_group_id', $_POST['api_group_id']);
        update_option('vk_group_parser_api_access_token', $_POST['api_access_token']);
        update_option('vk_group_parser_post_count', $_POST['post_count']);
        update_option('vk_group_parser_auto_parse_interval', $_POST['auto_parse_interval']);
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    // Parse posts now
    if (isset($_POST['parse_now'])) {
        vk_group_parser_parse_posts();
        echo '<div class="updated"><p>Posts parsed.</p></div>';
    }

    // Get settings
    $api_group_id = get_option('vk_group_parser_api_group_id');
    $api_access_token = get_option('vk_group_parser_api_access_token');
    $post_count = get_option('vk_group_parser_post_count');
    $auto_parse_interval = get_option('vk_group_parser_auto_parse_interval');

    // Get last parse time
    $last_parse_time = get_option('vk_group_parser_last_parse_time');
    if (!$last_parse_time) {
        $last_parse_time = 'Never';
    } else {
        $last_parse_time = date('Y-m-d H:i:s', $last_parse_time);
    }

    // Render settings form
    echo '<div class="wrap">';
    echo '<h1>VK Group Parser Settings</h1>';
    echo '<form method="post">';
    echo '<table class="form-table">';
    echo '<tr><th>API Group ID:</th><td><input type="text" name="api_group_id" value="' . esc_attr($api_group_id) . '" /></td></tr>';
    echo '<tr><th>API Access Token:</th><td><input type="text" name="api_access_token" value="' . esc_attr($api_access_token) . '" /></td></tr>';
    echo '<tr><th>Post Count:</th><td><input type="number" name="post_count" value="' . esc_attr($post_count) . '" /></td></tr>';
    echo '<tr><th>Auto Parse Interval (minutes):</th><td><input type="number" name="auto_parse_interval" value="' . esc_attr($auto_parse_interval) . '" /></td></tr>';
    echo '</table>';
    echo '<p class="submit"><input type="submit" name="submit" class="button-primary" value="Save Settings" /> <input type="submit" name="parse_now" class="button-secondary" value="Parse Now" /></p>';
    echo '</form>';
    echo '<h2>Last Parse Time: ' . $last_parse_time . '</h2>';

    // Render logs
    $logs = get_option('vk_group_parser_logs');
    if ($logs) {
        echo '<h2>Logs:</h2>';
        echo '<ul>';
        foreach ($logs as $log) {
            echo '<li>' . $log . '</li>';
        }
        echo '</ul>';
    }

    echo '</div>';
}

// Add auto parse cron job
add_action('wp', 'vk_group_parser_add_auto_parse_cron_job');
function vk_group_parser_add_auto_parse_cron_job() {
    if (!wp_next_scheduled('vk_group_parser_auto_parse')) {
        $auto_parse_interval = get_option('vk_group_parser_auto_parse_interval');
        wp_schedule_event(time(), 'vk_group_parser_auto_parse_interval', 'vk_group_parser_auto_parse');
    }
}

// Parse VK group posts
function vk_group_parser_parse_posts() {
    
    // Get settings
    $api_group_id = get_option('vk_group_parser_api_group_id');
    $api_access_token = get_option('vk_group_parser_api_access_token');
    $post_count = get_option('vk_group_parser_post_count');

    // Build API request URL
    $url = 'https://api.vk.com/method/wall.get?owner_id=-' . $api_group_id . '&count=' . $post_count . '&access_token=' . $api_access_token . '&v=5.131';

    // Send API request
    $response = file_get_contents($url);

    // Decode JSON response
    $response = json_decode($response, true);

    // Check for errors
    if (isset($response['error'])) {
        $log = 'VK Group Parser Error: ' . $response['error']['error_msg'];
        error_log($log);
        $logs = get_option('vk_group_parser_logs');
        if (!$logs) {
            $logs = array();
        }
        array_push($logs, $log);
        update_option('vk_group_parser_logs', $logs);
        return;
    }

    // Parse posts
    foreach ($response['response']['items'] as $post) {
        // Check if post has photos
        if (!isset($post['attachments']) || !is_array($post['attachments'])) {
            continue;
        }

        // Get first photo
        $photo = null;
        foreach ($post['attachments'] as $attachment) {
            if ($attachment['type'] == 'photo') {
                $photo = $attachment['photo'];
                break;
            }
        }
        if (!$photo) {
            continue;
        }

        // Build post data
        $post_data = array(
            'post_title' => wp_trim_words($post['text'], 20),
            'post_content' => $post['text'],
            'post_status' => 'publish',
            'post_category' => array(get_cat_ID('News')),
            'meta_input' => array(
                'vk_group_parser_photo' => $photo['sizes'][0]['url']
            )
        );

        // Insert post
        $post_id = wp_insert_post($post_data);

        // Set featured image
        $image_url = $photo['sizes'][0]['url'];
        $image_data = file_get_contents($image_url);
        $filename = basename($image_url);
        $upload_dir = wp_upload_dir();
        $file = $upload_dir['path'] . '/' . $filename;
        file_put_contents($file, $image_data);
        $wp_filetype = wp_check_filetype($filename, null);
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => sanitize_file_name($filename),
            'post_content' => '',
            'post_status' => 'inherit'
        );
        $attachment_id = wp_insert_attachment($attachment, $file, $post_id);
        set_post_thumbnail($post_id, $attachment_id);
        
        
    
    }

    // Update last parse time
    update_option('vk_group_parser_last_parse_time', time());
}

// Add custom cron interval
add_filter('cron_schedules', 'vk_group_parser_add_custom_cron_interval');
function vk_group_parser_add_custom_cron_interval($schedules) {
    $schedules['vk_group_parser_auto_parse_interval'] = array(
        'interval' => get_option('vk_group_parser_auto_parse_interval') * 60,
        'display' => __('VK Group Parser Auto Parse Interval')
    );
    return $schedules;
}
