<?php

/**
 * Plugin Name: AI → Divi Page Generator (Google Gemini)
 * Description: Generate or modify Divi pages dynamically from natural-language brief using Google Gemini API (AJAX ready).
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

class AIDiviGenerator
{
    const OPT_KEY = 'google_gemini_api_key';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_ai_divi_generate', [$this, 'ajax_generate_page']);
    }

    public function register_settings()
    {
        register_setting('ai_divi_group', self::OPT_KEY);
    }

    public function menu()
    {
        add_menu_page('AI → Divi', 'AI → Divi', 'edit_pages', 'ai-divi', [$this, 'screen'], 'dashicons-art', 58);
    }

    private function prompt_template($type = 'page_creation_by_design', $brief = '', $existingCode = '', $title = '', $slug = '')
    {
        $skeleton = [
            'page_creation_by_design' => "You are a WordPress Divi layout reconstruction agent.
                    You will receive a design image (JPG, PNG, JPEG) representing a page layout.
                    Your task is to generate or modify Divi shortcodes based on the user instructions.

                    Instructions:
                    - If existing code is provided, preserve it.
                    - Add new sections, modules, or elements after existing content if appropriate.
                    - Modify existing sections only if explicitly instructed.
                    - If a title or slug is provided, use them exactly in the AI output.
                    - If the title or slug is blank, use the brief as the title and generate a slug automatically based on that title.
                    - Maintain Divi structure: [et_pb_section], [et_pb_row], [et_pb_column], [et_pb_text], [et_pb_image], [et_pb_button], etc.
                    - Use inline CSS only if needed.
                    - Do NOT include [et_pb_text_inner] or [et_pb_toggle_content_inner].
                    - Output valid JSON only, no markdown or extra text.
                    - Match exactly as designed. 100% pixel-perfect.

                    Schema:
                    {
                        \"title\": string,
                        \"slug\": string,
                        \"layout\": string
                    }

                    Input example:
                    {
                        \"image_url\": \"https://example.com/design.jpg\",
                        \"brief\": \"$brief\",
                        \"existing_code\": \"$existingCode\",
                        \"title\": \"" . (!empty($title) ? $title : 'GENERATE_TITLE_FROM_BRIEF') . "\",
                        \"slug\": \"" . (!empty($slug) ? $slug : 'GENERATE_SLUG_FROM_TITLE') . "\"
                    }

                    The output must be:
                    {
                        \"title\": \"" . (!empty($title) ? $title : 'GENERATE_TITLE_FROM_BRIEF') . "\",
                        \"slug\": \"" . (!empty($slug) ? $slug : 'GENERATE_SLUG_FROM_TITLE') . "\",
                        \"layout\": \"[et_pb_section ...]...[/et_pb_section]\"
                    }

                    Start processing now:"
        ];

        return $skeleton[$type];
    }

    public function screen()
    {
        if (!current_user_can('edit_pages')) return;
?>
        <div class="wrap">
            <h1>AI → Divi Page Generator</h1>
            <form id="ai-divi-form" method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('ai_divi_gen'); ?>
                <table class="form-table">
                    <tr>
                        <th>Select Existing Page</th>
                        <td>
                            <select name="ai_divi_specific_page" class="form-control">
                                <option value="">-- Select a page to modify (optional) --</option>
                                <?php
                                $pages = get_pages(['post_status' => ['publish', 'draft'], 'sort_column' => 'post_title', 'sort_order' => 'ASC']);
                                foreach ($pages as $page) {
                                    echo '<option value="' . esc_attr($page->ID) . '">' . esc_html($page->post_title) . '</option>';
                                }
                                ?>
                            </select>
                            <p class="description">Optional: Select a page to modify; leave empty to create a new page.</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Page Title (Optional)</th>
                        <td><input type="text" name="ai_divi_title" class="regular-text" placeholder="Page title (optional)"></td>
                    </tr>

                    <tr>
                        <th>Page Slug (Optional)</th>
                        <td><input type="text" name="ai_divi_slug" class="regular-text" placeholder="Page slug (optional)"></td>
                    </tr>

                    <tr>
                        <th>Design Image (Upload or URL)</th>
                        <td>
                            <input type="file" name="design_image_file" accept="image/*" style="display:block; margin-bottom:10px;">
                            <input type="url" name="design_image_url" class="regular-text" placeholder="Or enter design image URL">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="ai_divi_brief">Your Brief / Instructions</label></th>
                        <td><textarea name="ai_divi_brief" rows="8" class="large-text" placeholder="e.g., Add Hero section + Features + Testimonials + CTA"></textarea></td>
                    </tr>

                </table>
                <button type="submit" class="button button-primary">Generate / Modify Page</button>
            </form>
            <div id="ai-divi-response" style="margin-top:20px;"></div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                $('#ai-divi-form').on('submit', function(e) {
                    e.preventDefault();
                    var formData = new FormData(this);
                    formData.append('action', 'ai_divi_generate');

                    $('#ai-divi-response').html('Processing...');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: formData,
                        contentType: false,
                        processData: false,
                        success: function(response) {
                            if (response.success) {
                                $('#ai-divi-response').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                            } else {
                                $('#ai-divi-response').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                            }
                        },
                        error: function(err) {
                            $('#ai-divi-response').html('<div class="notice notice-error"><p>AJAX error. See console.</p></div>');
                            console.log(err);
                        }
                    });
                });
            });
        </script>
<?php
    }

    public function ajax_generate_page()
    {
        check_ajax_referer('ai_divi_gen');

        $api_key = get_option(self::OPT_KEY, 'AIzaSyBfnQvS2K56zL7kRI8Z8ROwbt4mlk7afRg');
        if (!$api_key) {
            wp_send_json_error(['message' => 'Add your Google Gemini API key first.']);
        }

        $brief   = sanitize_textarea_field($_POST['ai_divi_brief'] ?? '');
        $image_url = esc_url_raw($_POST['design_image_url'] ?? '');
        $specificPage = intval($_POST['ai_divi_specific_page'] ?? '');
        $existingCode = '';
        $title = sanitize_text_field($_POST['ai_divi_title'] ?? '');
        $slug  = sanitize_title($_POST['ai_divi_slug'] ?? '');

        if ($specificPage) {
            $page = get_post($specificPage);
            if ($page && $page->post_type === 'page') {
                $existingCode = $page->post_content;
                if (empty($title)) $title = $page->post_title;
                if (empty($slug)) $slug = $page->post_name;
            }
        }

        $generator = $this;
        $prompt = $generator->prompt_template('page_creation_by_design', $brief, $existingCode, $title, $slug);

        $parts = [['text' => $prompt]];

        if (!empty($_FILES['design_image_file']['tmp_name']) && $_FILES['design_image_file']['error'] === UPLOAD_ERR_OK) {
            $uploaded_file = $_FILES['design_image_file']['tmp_name'];
            $mime_type = mime_content_type($uploaded_file);
            $parts[] = ['inline_data' => ['mime_type' => $mime_type, 'data' => base64_encode(file_get_contents($uploaded_file))]];
        } elseif (!empty($image_url)) {
            $image_contents = @file_get_contents($image_url);
            if ($image_contents !== false) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime_type = $finfo->buffer($image_contents);
                $parts[] = ['inline_data' => ['mime_type' => $mime_type, 'data' => base64_encode($image_contents)]];
            }
        }

        $payload = ['contents' => [['parts' => $parts]]];
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

        if (!$json || empty($json['layout'])) {
            wp_send_json_error(['message' => 'AI output not valid JSON. Output: ' . $raw]);
        }

        $page_title = !empty($title) ? $title : sanitize_text_field($json['title'] ?? 'AI Divi Page');
        $page_slug  = !empty($slug) ? $slug : sanitize_title($json['slug'] ?? $page_title);
        $layout     = wp_kses_post($json['layout']);

        if (strpos($layout, '[et_pb_section') === false) {
            wp_send_json_error(['message' => 'Generated content does not contain Divi shortcodes.']);
        }

        if ($specificPage) {
            // Update the selected page
            $post_id = wp_update_post([
                'ID'           => $specificPage,
                'post_title'   => $page_title,
                'post_name'    => $page_slug,
                'post_content' => $layout,
                'post_status'  => 'draft'
            ], true);
        } else {
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
        }

        // Always enable Divi builder
        if (!is_wp_error($post_id)) {
            update_post_meta($post_id, '_et_pb_use_builder', 'on');
            update_post_meta($post_id, 'et_pb_use_builder', 'on');
        }

        $edit_link = admin_url('post.php?action=edit&post=' . (int)$post_id);
        wp_send_json_success(['message' => 'Page saved successfully. <a href="' . $edit_link . '">Open in Divi Builder</a>']);
    }
}

new AIDiviGenerator();
