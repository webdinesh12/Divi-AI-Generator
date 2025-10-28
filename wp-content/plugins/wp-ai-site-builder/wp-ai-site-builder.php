<?php

/**
 * Plugin Name: WP AI Site Builder
 * Description: Generate pages from admin prompts using Gemini or any OpenAI-compatible endpoint.
 * Version: 1.0.0
 * Author: ChatGPT (Partha's helper)
 * License: GPLv2 or later
 */

if (! defined('ABSPATH')) exit;

define('WPAISB_VER', '1.0.0');
define('WPAISB_DIR', plugin_dir_path(__FILE__));
define('WPAISB_URL', plugin_dir_url(__FILE__));
// Default placeholder image used when AI omits image URLs
define('WPAISB_PLACEHOLDER_URL', WPAISB_URL . 'assets/no-image.svg');

require_once WPAISB_DIR . 'includes/AdminPage.php';
require_once WPAISB_DIR . 'includes/AIClient.php';

class WPAISB_Bootstrap
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        register_activation_hook(__FILE__, [$this, 'activate']);
    }

    public function activate()
    {
        // Sensible defaults
        if (!get_option('wpaisb_settings')) {
            $defaults = [
                'provider' => 'gemini', // 'openai_compat' or 'gemini'
                'openai_compat_base' => '',
                'openai_compat_key' => '',
                'openai_compat_model' => 'gpt-4o-mini',
                'gemini_api_key' => '',
                'gemini_model' => 'gemini-2.5-flash',
            ];
            add_option('wpaisb_settings', $defaults);
        }
    }

    public function register_menu()
    {
        add_menu_page(
            'AI Site Builder',
            'AI Site Builder',
            'manage_options',
            'wpaisb',
            function () {
                (new WPAISB_AdminPage())->render();
            },
            'dashicons-art',
            58
        );
    }

    public function assets($hook)
    {
        // Only load on your plugin’s main admin page
        if ($hook !== 'toplevel_page_wpaisb') return;

        // CSS
        wp_enqueue_style(
            'wpaisb-admin',
            WPAISB_URL . 'assets/admin.css',
            [],
            WPAISB_VER
        );

        // JS
        wp_enqueue_script(
            'wpaisb-admin',
            WPAISB_URL . 'assets/admin.js',
            ['jquery'],
            WPAISB_VER,
            true
        );

        // 👇 Localized JS object available in your admin.js
        wp_localize_script('wpaisb-admin', 'WPAISB', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('wpaisb_nonce'),
            'nonce2'   => wp_create_nonce('wpaisb_generate_page'),
        ]);
    }
}

add_action('wp_ajax_wpaisb_save_settings', 'wpaisb_save_settings_callback');

function wpaisb_save_settings_callback()
{
    check_ajax_referer('wpaisb_save_settings', 'nonce');

    $existing = get_option('wpaisb_settings', []);
    $provider = sanitize_text_field($_POST['provider'] ?? 'gemini');
    $new = $existing;
    $new['provider'] = $provider;

    if ($provider === 'openai_compat') {
        $new['openai_compat_base']  = esc_url_raw($_POST['openai_compat_base'] ?? '');
        $new['openai_compat_key']   = sanitize_text_field($_POST['openai_compat_key'] ?? '');
        $new['openai_compat_model'] = sanitize_text_field($_POST['openai_compat_model'] ?? '');
    } elseif ($provider === 'gemini') {
        $new['gemini_model']  = sanitize_text_field($_POST['gemini_model'] ?? '');
        $new['gemini_api_key'] = sanitize_text_field($_POST['gemini_api_key'] ?? '');
    }

    update_option('wpaisb_settings', $new);

    wp_send_json_success([
        'message'  => 'Settings saved successfully!',
        'settings' => $new,
    ]);
}

add_action('wp_ajax_wpaisb_generate_page', 'wpaisb_generate_page_callback');

function wpaisb_generate_page_callback()
{
    check_ajax_referer('wpaisb_generate_page', 'wpaisb_nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $settings = get_option('wpaisb_settings', []);
    $title = sanitize_text_field($_POST['wpaisb_title'] ?? '');
    $brief = sanitize_text_field($_POST['wpaisb_brief'] ?? '');
    $uploaded_file = '';
    $parts = [];
    if (!empty($_FILES['wpaisb_mock']['tmp_name']) && $_FILES['wpaisb_mock']['error'] === UPLOAD_ERR_OK) {
        $uploaded_file = $_FILES['wpaisb_mock']['tmp_name'];
        $mime_type = mime_content_type($uploaded_file);
        $parts[] = ['inline_data' => ['mime_type' => $mime_type, 'data' => base64_encode(file_get_contents($uploaded_file))]];
    }

    if (empty($title) || empty($uploaded_file)) {
        wp_send_json_error(['message' => 'Please enter a title and upload a design image.']);
    }

    $slug = '';
    $existingCode = '';


    $prompt = "You are a WordPress Gutenberg layout reconstruction agent.
        You will receive a design image (JPG, PNG, JPEG) representing a page layout.
        Your task is to generate Gutenberg block code based on the user instructions.

        Instructions:
        - If existing code is provided, preserve it.
        - Add new sections, modules, or elements after existing content if appropriate.
        - Modify existing sections only if explicitly instructed.
        - If a title or slug is provided, use them exactly.
        - If the title or slug is blank, generate them based on the brief.
        - Maintain valid Gutenberg structure.
        - Use inline CSS only if necessary.
        - The 'layout' field must contain valid Gutenberg HTML that can be directly saved into WordPress post_content.
        - Output valid JSON only, no markdown or extra text.
        - Match exactly as designed. 100% pixel-perfect.

        Schema:
        {
        \"title\": string,
        \"slug\": string,
        \"layout\": string
        }

        Input:
        {
        \"image_url\": \"$image_url\",
        \"brief\": \"$brief\",
        \"existing_code\": \"$existingCode\",
        \"title\": \"" . (!empty($title) ? $title : 'GENERATE_TITLE_FROM_BRIEF') . "\",
        \"slug\": \"" . (!empty($slug) ? $slug : 'GENERATE_SLUG_FROM_TITLE') . "\"
        }

        The output must be valid JSON only. For example:

        {
        \"title\": \"" . (!empty($title) ? $title : 'GENERATE_TITLE_FROM_BRIEF') . "\",
        \"slug\": \"" . (!empty($slug) ? $slug : 'GENERATE_SLUG_FROM_TITLE') . "\",
        \"layout\": \"<!-- wp:paragraph --><p>Your content here</p><!-- /wp:paragraph -->\"
        }

        Do not include any text outside the JSON. Only output JSON.
        Start processing now.";

    $parts[] = [['text' => $prompt]];
    $payload = ['contents' => [['parts' => $parts]]];
    if ($settings['provider'] != 'gemini') {
        wp_send_json_error(['message' => 'Other providers are not supported yet.']);
    }

    $model = $settings['gemini_model'] ?? 'gemini-2.5-flash';
    $api_key = $settings['gemini_api_key'] ?? '';

    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    $resp = wp_remote_post($endpoint, [
        'headers' => ['Content-Type' => 'application/json', 'x-goog-api-key' => $api_key],
        'body' => wp_json_encode($payload),
        'timeout' => 600
    ]);

    if (is_wp_error($resp)) {
        wp_send_json_error(['message' => $resp->get_error_message()]);
    }

    $body = json_decode(wp_remote_retrieve_body($resp), true);

    $raw = trim($body['candidates'][0]['content']['parts'][0]['text'] ?? '');
    $raw = preg_replace('/^```(?:json)?|```$/m', '', $raw);
    $raw = trim($raw);
    $json = json_decode($raw, true);
    if (isset($json[0])) {
        $json = $json[0];
    }

    if (!$json || empty($json['layout'])) {
        wp_send_json_error(['message' => 'AI output not valid JSON. Output: ' . $raw]);
    }

    $page_title = !empty($title) ? $title : sanitize_text_field($json['title'] ?? 'AI Divi Page');
    $page_slug  = !empty($slug) ? $slug : sanitize_title($json['slug'] ?? $page_title);
    $layout     = wp_kses_post($json['layout']);

    // $layout_blocks = ''; // Convert JSON to Gutenberg HTML blocks
    // foreach ($json['layout_blocks'] as $block) {
    //     $layout_blocks .= convert_block_json_to_html($block); // you would define this
    // }

    // $post_content = $layout_blocks;

    // $post_id = wp_insert_post([
    //     'post_title'   => $page_title,
    //     'post_name'    => $page_slug,
    //     'post_content' => $post_content,
    //     'post_status'  => 'draft',
    //     'post_type'    => 'page'
    // ], true);

    $existing_page = get_page_by_path($page_slug, OBJECT, 'page');
    if ($existing_page) {
        $post_id = wp_update_post([
            'ID'           => $existing_page->ID,
            'post_title'   => $page_title,
            'post_name'    => $page_slug,
            'post_content' => $layout,
            'post_status'  => 'draft'
        ], true);
    } else {
        $post_id = wp_insert_post([
            'post_title'   => $page_title,
            'post_name'    => $page_slug,
            'post_content' => $layout,
            'post_status'  => 'draft',
            'post_type'    => 'page'
        ], true);
    }

    wp_send_json_success([
        'message' => 'Page generated successfully!',
        'page_id' => $post_id,
        'edit_url' => get_edit_post_link($post_id, ''),
    ]);
}

new WPAISB_Bootstrap();
