<?php
if (! defined('ABSPATH')) exit;

class WPAISB_AdminPage
{

    public function render()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wpaisb'));
        }

        $settings = get_option('wpaisb_settings', []);
        $current_provider = $settings['provider'] ?? 'gemini';
        if ($current_provider !== 'gemini' && $current_provider !== 'openai_compat') {
            $current_provider = 'gemini';
        }
        $created_page_id = 0;
        $export_json_url = '';
        $error_msg = '';
        $notice_info = '';

        if (isset($_POST['wpaisb_action']) && $_POST['wpaisb_action'] === 'generate') {



            check_admin_referer('wpaisb_generate_page', 'wpaisb_nonce');

            $title  = sanitize_text_field($_POST['wpaisb_title'] ?? '');
            $use_blocks = true; // Always aim for editable Gutenberg blocks

            // Handle optional mock design image upload
            $image_url = '';
            $image_path = '';
            $image_mime = '';
            if (! empty($_FILES['wpaisb_mock']['name'])) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $attachment_id = media_handle_upload('wpaisb_mock', 0);
                if (is_wp_error($attachment_id)) {
                    $error_msg = $attachment_id->get_error_message();
                } else {
                    $image_url = wp_get_attachment_url($attachment_id);
                    $image_path = get_attached_file($attachment_id);
                    $image_mime = get_post_mime_type($attachment_id) ?: 'image/jpeg';

                    // Prefer a resized variant (to keep API payloads small and fast)
                    $meta = wp_get_attachment_metadata($attachment_id);
                    if ($meta && !empty($meta['sizes'])) {
                        $candidates = ['1536x1536', 'large', 'medium_large', 'medium'];
                        foreach ($candidates as $size) {
                            if (!empty($meta['sizes'][$size]['file'])) {
                                $candidate_path = trailingslashit(dirname($image_path)) . $meta['sizes'][$size]['file'];
                                if (file_exists($candidate_path)) {
                                    $image_path = $candidate_path;
                                    break;
                                }
                            }
                        }
                    }
                    // If still very large, generate a temp 1280px wide image
                    if (file_exists($image_path) && filesize($image_path) > 3 * 1024 * 1024) {
                        $editor = wp_get_image_editor($image_path);
                        if (!is_wp_error($editor)) {
                            $editor->resize(1280, null, false);
                            $resized = $editor->generate_filename('ai');
                            $saved = $editor->save($resized);
                            if (!is_wp_error($saved) && !empty($saved['path']) && file_exists($saved['path'])) {
                                $image_path = $saved['path'];
                                $image_mime = $saved['mime-type'] ?? $image_mime;
                            }
                        }
                    }
                }
            }

            if (empty($error_msg) && (empty($title) || empty($image_path))) {
                $error_msg = 'Please enter a page title and upload a design image.';
            }

            if (empty($error_msg)) {
                $client = new WPAISB_AIClient($settings);
                $effective_use_blocks = true;
                // Build an instruction from the title; include image URL to help AI place it in blocks
                $instruction = $this->build_prompt_from_title($title, $effective_use_blocks, $image_url);
                $request = [
                    'text' => $instruction,
                    'image_path' => $image_path,
                    'image_mime' => $image_mime,
                ];

                $ai = $client->generate($request);
                $content = '';
                $fallback_used = false;
                if (is_wp_error($ai)) {
                    // Fallback: create a simple editable block layout from the image with placeholders
                    $content = $this->build_default_block_layout($title, $image_url);




                    dd($content);







                    $fallback_used = true;
                    $err_detail = sanitize_text_field($ai->get_error_message());
                    if (strlen($err_detail) > 140) {
                        $err_detail = substr($err_detail, 0, 140) . '…';
                    }
                    $notice_info = 'AI generation failed (' . $err_detail . '); created a starter layout from the image with placeholders.';
                } else {
                    $content = $ai['content'];
                    $title   = $title ?: ($ai['title'] ?: 'AI Generated Page');
                }

                // Common: ensure we have Gutenberg-editable content and defaults
                if ($effective_use_blocks) {
                    $has_block_markup = (strpos($content, '<!-- wp:') !== false);
                    if (! $has_block_markup) {
                        $content = "<!-- wp:html -->\n" . $content . "\n<!-- /wp:html -->";
                    }
                    // Apply default design styles and placeholders post-processing
                    $content = $this->post_process_blocks($content);
                } else {
                    // For raw HTML, still ensure placeholder images and add a style tag
                    $content = $this->inject_default_styles($content);
                    $content = $this->apply_image_placeholders($content);
                }

                // Force all images/placeholders to use our placeholder for import parity
                $content = $this->force_placeholder_images_everywhere($content);

                // Create a downloadable JSON for manual import as a reusable block/pattern
                $export = $this->create_reusable_block_export($title, $content);
                if (is_wp_error($export)) {
                    // Don't fail the page creation solely due to export write issues; append to notice
                    $notice_info .= ($notice_info ? ' ' : '') . 'Export JSON not created: ' . sanitize_text_field($export->get_error_message()) . '.';
                } else {
                    $export_json_url = $export['url'];
                }

                $page_id = wp_insert_post([
                    'post_title'   => $title,
                    'post_content' => $content,
                    'post_status'  => 'draft',
                    'post_type'    => 'page'
                ], true);

                if (is_wp_error($page_id)) {
                    $error_msg = $page_id->get_error_message();
                } else {
                    $created_page_id = (int)$page_id;
                }
            }
        }

?>
        <div class="wrap wpaisb-wrap">
            <h1><?php echo esc_html__('AI Site Builder', 'wpaisb'); ?></h1>

            <?php if ($error_msg): ?>
                <div class="notice notice-error">
                    <p><?php echo esc_html($error_msg); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($created_page_id): ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php echo esc_html__('Draft page created!', 'wpaisb'); ?>
                        <?php if (!empty($notice_info)) {
                            echo ' <em>(' . esc_html($notice_info) . ')</em> ';
                        } ?>
                        <a class="button button-primary" href="<?php echo esc_url(get_edit_post_link($created_page_id)); ?>">
                            <?php echo esc_html__('Edit Page', 'wpaisb'); ?>
                        </a>
                        <a class="button" href="<?php echo esc_url(get_permalink($created_page_id)); ?>" target="_blank">
                            <?php echo esc_html__('View', 'wpaisb'); ?>
                        </a>
                        <?php if (!empty($export_json_url)) : ?>
                            <a class="button" href="<?php echo esc_url($export_json_url); ?>" download>
                                <?php echo esc_html__('Download JSON', 'wpaisb'); ?>
                            </a>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="wpaisb-grid">
                <form class="wpaisb-card" method="post" enctype="multipart/form-data" id="wpaisb-generate-form">
                    <input type="hidden" name="wpaisb_action" value="generate" />

                    <h2><?php echo esc_html__('Generate Page', 'wpaisb'); ?></h2>

                    <label><?php echo esc_html__('Page Title (optional)', 'wpaisb'); ?></label>
                    <input type="text" name="wpaisb_title" class="regular-text" placeholder="Landing page" />

                    <label><?php echo esc_html__('Instruction (optional)', 'wpaisb'); ?></label>
                    <input type="text" name="wpaisb_brief" class="regular-text" placeholder="Create simple landing page with provided design" />

                    <label><?php echo esc_html__('Upload Design (required image)', 'wpaisb'); ?></label>
                    <input type="file" name="wpaisb_mock" accept="image/*" />

                    <p><button class="button button-primary" type="submit"><?php echo esc_html__('Generate Draft Page', 'wpaisb'); ?></button></p>

                    <div id="response-shown"></div>
                </form>

                <form class="wpaisb-card" method="post">
                    <?php wp_nonce_field('wpaisb_save_settings', 'wpaisb_settings_nonce'); ?>
                    <input type="hidden" name="wpaisb_settings_action" value="save" />
                    <h2><?php echo esc_html__('Settings', 'wpaisb'); ?></h2>

                    <?php
                    // Save settings (provider-specific updates)
                    if (isset($_POST['wpaisb_settings_action']) && $_POST['wpaisb_settings_action'] === 'save') {
                        check_admin_referer('wpaisb_save_settings', 'wpaisb_settings_nonce');
                        $existing = get_option('wpaisb_settings', []);
                        $provider = sanitize_text_field($_POST['provider'] ?? 'gemini');
                        $new = $existing;
                        $new['provider'] = $provider;

                        if ($provider === 'openai_compat') {
                            $new['openai_compat_base'] = esc_url_raw($_POST['openai_compat_base'] ?? ($existing['openai_compat_base'] ?? ''));
                            $new['openai_compat_key'] = sanitize_text_field($_POST['openai_compat_key'] ?? ($existing['openai_compat_key'] ?? ''));
                            $new['openai_compat_model'] = sanitize_text_field($_POST['openai_compat_model'] ?? ($existing['openai_compat_model'] ?? ''));
                        } elseif ($provider === 'gemini') {
                            $new['gemini_api_key'] = sanitize_text_field($_POST['gemini_api_key'] ?? ($existing['gemini_api_key'] ?? ''));
                            $new['gemini_model'] = sanitize_text_field($_POST['gemini_model'] ?? ($existing['gemini_model'] ?? ''));
                        }

                        update_option('wpaisb_settings', $new);
                        $settings = $new;

                        echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
                    }
                    ?>

                    <label><?php echo esc_html__('Provider', 'wpaisb'); ?></label>
                    <select name="provider" id="wpaisb-provider-select">
                        <option value="openai_compat" <?php selected($current_provider, 'openai_compat'); ?>>OpenAI-compatible (any endpoint)</option>
                        <option value="gemini" <?php selected($current_provider, 'gemini'); ?>>Gemini (Google AI Studio)</option>
                    </select>



                    <fieldset class="wpaisb-fieldset wpaisb-provider-group<?php echo ($current_provider === 'openai_compat') ? ' is-active' : ''; ?>" data-provider="openai_compat" <?php echo ($current_provider === 'openai_compat') ? '' : 'style="display:none"'; ?>>
                        <legend>OpenAI-compatible</legend>
                        <label>Base URL (e.g. https://api.openrouter.ai/v1)</label>
                        <input type="text" name="openai_compat_base" value="<?php echo esc_attr($settings['openai_compat_base'] ?? ''); ?>" class="regular-text" />
                        <label>API Key</label>
                        <input type="password" name="openai_compat_key" value="<?php echo esc_attr($settings['openai_compat_key'] ?? ''); ?>" class="regular-text" />
                        <label>Model (e.g. openrouter/anthropic/claude-3.5-sonnet)</label>
                        <input type="text" name="openai_compat_model" value="<?php echo esc_attr($settings['openai_compat_model'] ?? ''); ?>" class="regular-text" />
                        <p class="description">Use any provider that implements the OpenAI Chat Completions API.</p>
                    </fieldset>

                    <fieldset class="wpaisb-fieldset wpaisb-provider-group<?php echo ($current_provider === 'gemini') ? ' is-active' : ''; ?>" data-provider="gemini" <?php echo ($current_provider === 'gemini') ? '' : 'style="display:none"'; ?>>
                        <legend>Gemini</legend>
                        <label>Model (e.g. gemini-1.5-flash, gemini-1.5-pro)</label>
                        <input type="text" name="gemini_model" value="<?php echo esc_attr($settings['gemini_model'] ?? ''); ?>" class="regular-text" placeholder="gemini-2.5-flash" />
                        <label>API Key</label>
                        <input type="password" name="gemini_api_key" value="<?php echo esc_attr($settings['gemini_api_key'] ?? ''); ?>" class="regular-text" />
                        <p class="description">Create a key in Google AI Studio. Requests use the REST API with your key.</p>
                    </fieldset>

                    <p><button class="button button-secondary" type="submit">Save Settings</button></p>
                </form>
            </div>
            <div class="wpaisb-footnote">
                <p><strong>Tip:</strong> A reusable‑block JSON is also generated for import via Reusable Blocks → Import from JSON. Insert the imported block into any page.</p>
            </div>
        </div>
<?php
    }

    private function create_reusable_block_export($title, $content)
    {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return new WP_Error('wpaisb_uploads_error', $uploads['error']);
        }
        $dir = trailingslashit($uploads['basedir']) . 'wpaisb-exports';
        if (!file_exists($dir)) {
            if (!wp_mkdir_p($dir)) {
                return new WP_Error('wpaisb_export_mkdir', 'Failed to create export directory.');
            }
        }
        $slug = sanitize_title($title ?: 'ai-generated-page');
        if (!$slug) {
            $slug = 'ai-generated-page';
        }
        $file = $dir . '/' . $slug . '.json';

        $data = [
            '__file'     => 'wp_block',
            'title'      => $title ?: 'AI Generated Page',
            'content'    => $content,
            // unsynced makes it a static pattern-like block you can insert and edit freely
            'syncStatus' => 'unsynced',
        ];

        $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return new WP_Error('wpaisb_export_json', 'Failed to encode JSON export.');
        }
        $ok = @file_put_contents($file, $json);
        if ($ok === false) {
            return new WP_Error('wpaisb_export_write', 'Failed to write JSON export.');
        }
        $url = trailingslashit($uploads['baseurl']) . 'wpaisb-exports/' . basename($file);
        // Surface a success notice with a direct download link if available in the template
        echo '<div class="notice notice-info is-dismissible"><p>Reusable‑block JSON created. <a href="' . esc_url($url) . '" download>Download JSON</a></p></div>';
        return ['path' => $file, 'url' => $url];
    }

    private function force_placeholder_images_everywhere($content)
    {
        $placeholder = defined('WPAISB_PLACEHOLDER_URL') ? WPAISB_PLACEHOLDER_URL : '';
        if (!$placeholder) return $content;

        // Replace any cover block attribute url with placeholder
        $content = preg_replace_callback(
            '/(<!--\s*wp:cover\s*\{)([^}]*)\}(\s*-->)/i',
            function ($m) use ($placeholder) {
                $attrs = $m[2];
                // Replace existing url":"..." or add one if missing
                if (preg_match('/"url"\s*:\s*"[^"]*"/i', $attrs)) {
                    $attrs = preg_replace('/"url"\s*:\s*"[^"]*"/i', '"url":"' . esc_url($placeholder) . '"', $attrs);
                } else {
                    $attrs = rtrim($attrs);
                    if ($attrs !== '' && substr($attrs, -1) !== ',') {
                        $attrs .= ',';
                    }
                    $attrs .= '"url":"' . esc_url($placeholder) . '"';
                }
                return $m[1] . $attrs . '}' . $m[3];
            },
            $content
        );

        // Replace all <img ... src="..."> with placeholder
        $content = preg_replace_callback(
            '/<img([^>]*?)src\s*=\s*("|\")(.*?)(\2)([^>]*)>/i',
            function ($m) use ($placeholder) {
                return '<img' . $m[1] . 'src="' . esc_url($placeholder) . '"' . $m[5] . '>';
            },
            $content
        );

        return $content;
    }

    private function build_prompt_from_title($page_title, $use_blocks, $design_url = '')
    {
        $placeholder = defined('WPAISB_PLACEHOLDER_URL') ? WPAISB_PLACEHOLDER_URL : '';
        $system_blocks = "You are a senior WordPress and Gutenberg expert. Return ONLY WordPress Gutenberg block markup (serialized) using core blocks such as: wp:cover, wp:group, wp:columns, wp:column, wp:image, wp:heading, wp:paragraph, wp:list, wp:buttons, wp:button, wp:separator, wp:spacer. Use the standard block HTML comments, for example: <!-- wp:heading {\"level\":1} --><h1>Title</h1><!-- /wp:heading -->. Do not include <html>, <head>, or <body> tags. Do not include explanations or markdown fences. Ensure the result is valid block markup that Gutenberg can edit.";
        $img_hint = $design_url ? "Primary design image URL: {$design_url}. Use this URL for the main hero (e.g., wp:cover) or supporting wp:image blocks where appropriate." : "";
        $guidance = "Use the uploaded design image as the primary visual reference. {$img_hint} Infer section structure, color palette and typography from it. Create editable sections and components. Add headings, paragraphs, images, buttons, and groups as appropriate. Make it responsive and accessible. Keep the content safe, suitable for all audiences, and free of disallowed or sensitive material. Add a wp:html block at the very top that contains a <style> tag with the page styles. If an image url is not available for any wp:image block, use this placeholder URL: {$placeholder}. Keep a logical section order (hero, value proposition, features/benefits, call to action).";
        $title_hint = $page_title ? "Page title: {$page_title}." : '';
        if ($use_blocks) {
            return $system_blocks . "\n\n" . $guidance . "\n\n" . $title_hint . "\nReturn only the blocks.";
        }
        // Fallback (not expected): HTML mode
        $system_html = "You are a senior WordPress and frontend expert. Generate clean, responsive HTML/CSS (and minimal JS if needed). Prefer semantic HTML5 with a single self-contained <style> tag at the top. Avoid external CDNs. Keep CSS readable. If forms are requested, include accessible labels. Return ONLY the HTML/CSS to render the page body, no explanations. Do not include markdown code fences.";
        return $system_html . "\n\n" . $guidance . "\n\n" . $title_hint;
    }

    private function post_process_blocks($content)
    {
        // Wrap everything in a scoped group to minimize theme interference
        $content = $this->wrap_with_scope($content);
        // Always inject a default style block at the very top for consistent styling
        $content = $this->inject_default_styles($content);
        // Ensure images have placeholders when missing
        $content = $this->apply_image_placeholders($content);
        // Normalize block structure; fallback to safe HTML blocks if broken
        $content = $this->normalize_blocks($content);
        return $content;
    }

    private function inject_default_styles($content)
    {
        $css = '/* AI Page default styling (scoped) */
.wpaisb-scope { --wpaisb-max: 1140px; --wpaisb-pad: 20px; --wpaisb-text: #111827; --wpaisb-muted:#6b7280; --wpaisb-accent:#111827; --wpaisb-bg:#ffffff; }
.wpaisb-scope .wp-block-group, .wpaisb-scope .wp-block-columns, .wpaisb-scope .wp-block-cover, .wpaisb-scope .wp-block-separator, .wpaisb-scope .wp-block-buttons { max-width: var(--wpaisb-max); margin-left:auto; margin-right:auto; padding-left: var(--wpaisb-pad); padding-right: var(--wpaisb-pad); }
.wpaisb-scope .wp-block-heading { margin: 0 0 16px; line-height:1.2; }
.wpaisb-scope .wp-block-paragraph { margin: 0 0 14px; line-height: 1.75; color: var(--wpaisb-text); }
.wpaisb-scope .wp-block-buttons .wp-block-button__link { background:var(--wpaisb-accent); color:#fff; padding:12px 20px; border-radius:8px; text-decoration:none; }
.wpaisb-scope .wp-block-separator { margin: 32px 0; opacity:.2; }
.wpaisb-scope .wp-block-image img { width:100%; height:auto; object-fit:cover; border-radius:8px; display:block; }
.wpaisb-scope .wp-block-columns { gap: 24px; align-items: center; }
.wpaisb-scope .wp-block-cover__inner-container { padding-top:60px; padding-bottom:60px; }
.wpaisb-scope h1.wp-block-heading { font-size: clamp(28px, 4vw, 44px); }
.wpaisb-scope h2.wp-block-heading { font-size: clamp(22px, 3vw, 32px); }
.wpaisb-scope .wp-block-paragraph.is-style-lead { font-size: clamp(16px, 2.2vw, 20px); color: var(--wpaisb-muted); }
/* Responsive tweaks */
@media (max-width: 782px) { .wpaisb-scope .wp-block-columns { display:block; } }
';
        $style_block = "<!-- wp:html -->\n<style>\n" . $css . "\n</style>\n<!-- /wp:html -->\n";
        // Always prepend our scoped style so layout remains consistent
        $content = $style_block . $content;
        return $content;
    }

    private function wrap_with_scope($content)
    {
        // If already wrapped, skip
        if (strpos($content, 'wpaisb-scope') !== false) return $content;
        $opening = "<!-- wp:group {\"className\":\"wpaisb-scope\"} --><div class=\"wp-block-group wpaisb-scope\">\n";
        $closing = "\n</div><!-- /wp:group -->\n";
        return $opening . $content . $closing;
    }

    private function normalize_blocks($content)
    {
        // Remove stray BOM/whitespace before first block comment
        $content = ltrim($content, "\xEF\xBB\xBF\r\n\t ");
        if (!function_exists('parse_blocks') || !function_exists('serialize_blocks')) {
            // Older WP fallback: ensure content is at least wrapped
            if (strpos($content, '<!-- wp:') === false) {
                return "<!-- wp:html -->\n" . $content . "\n<!-- /wp:html -->";
            }
            return $content;
        }
        $blocks = parse_blocks($content);
        // If parsing yields no blocks, ensure we return valid block by wrapping
        if (empty($blocks)) {
            return "<!-- wp:html -->\n" . $content . "\n<!-- /wp:html -->";
        }
        $serialized = serialize_blocks($blocks);
        // If serialize lost all blocks or produced empty, fallback to HTML wrapper
        if (!$serialized || trim($serialized) === '') {
            return "<!-- wp:html -->\n" . $content . "\n<!-- /wp:html -->";
        }
        return $serialized;
    }

    private function apply_image_placeholders($content)
    {
        $placeholder = defined('WPAISB_PLACEHOLDER_URL') ? WPAISB_PLACEHOLDER_URL : '';
        if (!$placeholder) return $content;

        // 1) <img> with empty src
        $content = preg_replace_callback(
            '/<img([^>]*?)src\s*=\s*(["\"])\s*\2([^>]*)>/i',
            function ($m) use ($placeholder) {
                return '<img' . $m[1] . 'src="' . esc_url($placeholder) . '"' . $m[3] . '>';
            },
            $content
        );

        // 2) <img> with no src attribute
        $content = preg_replace_callback(
            '/<img(?![^>]*\bsrc\b)([^>]*)>/i',
            function ($m) use ($placeholder) {
                return '<img src="' . esc_url($placeholder) . '"' . $m[1] . '>';
            },
            $content
        );

        return $content;
    }


    private function build_default_block_layout($title, $image_url)
    {
        $title_safe = $title ? esc_html($title) : 'New Page';
        $placeholder = defined('WPAISB_PLACEHOLDER_URL') ? WPAISB_PLACEHOLDER_URL : '';
        $hero = $image_url ?: $placeholder;

        $blocks = '';
        // Hero cover
        $blocks .= "<!-- wp:cover {\"url\":\"" . esc_url($hero) . "\",\"dimRatio\":40,\"isDark\":false} -->\n";
        $blocks .= "<div class=\"wp-block-cover__inner-container\">\n";
        $blocks .= "<!-- wp:heading {\"textAlign\":\"center\",\"level\":1} --><h1 class=\"wp-block-heading has-text-align-center\">" . $title_safe . "</h1><!-- /wp:heading -->\n";
        $blocks .= "<!-- wp:paragraph {\"align\":\"center\"} --><p class=\"has-text-align-center\">Describe your value proposition here.</p><!-- /wp:paragraph -->\n";
        $blocks .= "</div>\n";
        $blocks .= "<!-- /wp:cover -->\n";

        // Two-column section
        $blocks .= "<!-- wp:group --><div class=\"wp-block-group\">\n";
        $blocks .= "<!-- wp:columns --><div class=\"wp-block-columns\">\n";
        $blocks .= "<!-- wp:column --><div class=\"wp-block-column\">\n";
        $blocks .= "<!-- wp:image --><figure class=\"wp-block-image\"><img src=\"" . esc_url($placeholder) . "\" alt=\"\"/></figure><!-- /wp:image -->\n";
        $blocks .= "</div><!-- /wp:column -->\n";
        $blocks .= "<!-- wp:column --><div class=\"wp-block-column\">\n";
        $blocks .= "<!-- wp:heading --><h2 class=\"wp-block-heading\">Section Heading</h2><!-- /wp:heading -->\n";
        $blocks .= "<!-- wp:paragraph --><p>Replace this placeholder text with content that matches your design.</p><!-- /wp:paragraph -->\n";
        $blocks .= "<!-- wp:buttons --><div class=\"wp-block-buttons\"><!-- wp:button --><div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"#\">Primary Action</a></div><!-- /wp:button --></div><!-- /wp:buttons -->\n";
        $blocks .= "</div><!-- /wp:column -->\n";
        $blocks .= "</div><!-- /wp:columns -->\n";
        $blocks .= "</div><!-- /wp:group -->\n";

        // Features grid
        $blocks .= "<!-- wp:separator --><hr class=\"wp-block-separator\" /><!-- /wp:separator -->\n";
        $blocks .= "<!-- wp:columns --><div class=\"wp-block-columns\">\n";
        for ($i = 0; $i < 3; $i++) {
            $blocks .= "<!-- wp:column --><div class=\"wp-block-column\">\n";
            $blocks .= "<!-- wp:image --><figure class=\"wp-block-image\"><img src=\"" . esc_url($placeholder) . "\" alt=\"\"/></figure><!-- /wp:image -->\n";
            $blocks .= "<!-- wp:heading {\"level\":3} --><h3 class=\"wp-block-heading\">Feature Title</h3><!-- /wp:heading -->\n";
            $blocks .= "<!-- wp:paragraph --><p>Short feature description goes here.</p><!-- /wp:paragraph -->\n";
            $blocks .= "</div><!-- /wp:column -->\n";
        }
        $blocks .= "</div><!-- /wp:columns -->\n";

        return $this->post_process_blocks($blocks);
    }
}
