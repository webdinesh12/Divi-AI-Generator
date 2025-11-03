<?php
if (!defined('ABSPATH')) {
    exit;
}

class Custom_Auction
{
    public static function init()
    {
        add_action('init', [__CLASS__, 'register_post_type']);
        add_action('add_meta_boxes', [__CLASS__, 'register_meta_boxes']);
        add_action('save_post_auction', [__CLASS__, 'save_meta_boxes']);

        // Shortcodes
        add_shortcode('auction_list', [__CLASS__, 'shortcode_list']);
        add_shortcode('auction', [__CLASS__, 'shortcode_single']);
        add_shortcode('auction_market', [__CLASS__, 'shortcode_market']);

        // Scripts
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);

        // AJAX
        add_action('wp_ajax_custom_auction_place_bid', [__CLASS__, 'ajax_place_bid']);
        add_action('wp_ajax_nopriv_custom_auction_place_bid', [__CLASS__, 'ajax_place_bid']);
        add_action('wp_ajax_custom_auction_get_data', [__CLASS__, 'ajax_get_data']);
        add_action('wp_ajax_nopriv_custom_auction_get_data', [__CLASS__, 'ajax_get_data']);

        // Admin bids page
        add_action('admin_menu', [__CLASS__, 'register_admin_pages']);

        // Bids / cron hooks
        Custom_Auction_Bids::hooks();

        // Auto UI on single auction pages
        add_filter('the_content', [__CLASS__, 'filter_the_content']);
    }

    public static function register_post_type()
    {
        $labels = [
            'name'               => __('Auctions', 'custom-auction'),
            'singular_name'      => __('Auction', 'custom-auction'),
            'add_new'            => __('Add New', 'custom-auction'),
            'add_new_item'       => __('Add New Auction', 'custom-auction'),
            'edit_item'          => __('Edit Auction', 'custom-auction'),
            'new_item'           => __('New Auction', 'custom-auction'),
            'view_item'          => __('View Auction', 'custom-auction'),
            'search_items'       => __('Search Auctions', 'custom-auction'),
            'not_found'          => __('No auctions found', 'custom-auction'),
            'not_found_in_trash' => __('No auctions found in Trash', 'custom-auction'),
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true,
            'show_in_menu'       => true,
            'menu_position'      => 20,
            'menu_icon'          => 'dashicons-hammer',
            'supports'           => ['title', 'editor', 'thumbnail'],
            'has_archive'        => true,
            'rewrite'            => ['slug' => 'auctions'],
            'show_in_rest'       => true,
        ];
        register_post_type('auction', $args);
    }

    public static function register_meta_boxes()
    {
        add_meta_box(
            'auction_details',
            __('Auction Details', 'custom-auction'),
            [__CLASS__, 'render_meta_box'],
            'auction',
            'normal',
            'default'
        );
    }

    public static function render_meta_box($post)
    {
        wp_nonce_field('save_auction_meta', 'auction_meta_nonce');
        $start_price   = get_post_meta($post->ID, '_auction_start_price', true);
        $min_increment = get_post_meta($post->ID, '_auction_min_increment', true);
        $start_time    = get_post_meta($post->ID, '_auction_start_time', true);
        $end_time      = get_post_meta($post->ID, '_auction_end_time', true);
        $buy_now       = get_post_meta($post->ID, '_auction_buy_now', true);
        $rules         = get_post_meta($post->ID, '_auction_increment_rules', true);
        if (!is_array($rules)) {
            $rules = [];
        }
        echo '<p><label>' . esc_html__('Start Price', 'custom-auction') . '</label><br/>';
        echo '<input type="number" step="0.01" name="auction_start_price" value="' . esc_attr($start_price) . '" class="widefat" /></p>';
        echo '<p><label>' . esc_html__('Fallback Minimum Increment (used if no range matches)', 'custom-auction') . '</label><br/>';
        echo '<input type="number" step="0.01" name="auction_min_increment" value="' . esc_attr($min_increment) . '" class="widefat" /></p>';
        echo '<p><label>' . esc_html__('Start Time (YYYY-MM-DD HH:MM)', 'custom-auction') . '</label><br/>';
        echo '<input type="text" name="auction_start_time" value="' . esc_attr($start_time) . '" class="widefat" placeholder="2025-10-29 10:00" /></p>';
        echo '<p><label>' . esc_html__('End Time (YYYY-MM-DD HH:MM)', 'custom-auction') . '</label><br/>';
        echo '<input type="text" name="auction_end_time" value="' . esc_attr($end_time) . '" class="widefat" placeholder="2025-10-30 10:00" /></p>';
        echo '<p><label>' . esc_html__('Buy Now Price (optional)', 'custom-auction') . '</label><br/>';
        echo '<input type="number" step="0.01" name="auction_buy_now" value="' . esc_attr($buy_now) . '" class="widefat" /></p>';

        // Repeater for price range increments
        echo '<hr/><h3>' . esc_html__('Price Range Based Increments', 'custom-auction') . '</h3>';
        echo '<p>' . esc_html__('Define minimum increments per price range. The first matching range (by order) will be used. If none match, the fallback increment above applies.', 'custom-auction') . '</p>';
        echo '<table class="widefat striped" id="auction-increment-rules" style="margin-bottom:8px">';
        echo '<thead><tr><th>' . esc_html__('Min Price', 'custom-auction') . '</th><th>' . esc_html__('Max Price', 'custom-auction') . '</th><th>' . esc_html__('Min Increment', 'custom-auction') . '</th><th></th></tr></thead><tbody>';
        if (empty($rules)) {
            $rules = [['min' => '', 'max' => '', 'inc' => '']];
        }
        foreach ($rules as $i => $r) {
            $min = isset($r['min']) ? $r['min'] : '';
            $max = isset($r['max']) ? $r['max'] : '';
            $inc = isset($r['inc']) ? $r['inc'] : '';
            echo '<tr>';
            echo '<td><input type="number" step="0.01" name="auction_increment_rules[min][]" value="' . esc_attr($min) . '" /></td>';
            echo '<td><input type="number" step="0.01" name="auction_increment_rules[max][]" value="' . esc_attr($max) . '" /></td>';
            echo '<td><input type="number" step="0.01" name="auction_increment_rules[inc][]" value="' . esc_attr($inc) . '" /></td>';
            echo '<td><button class="button remove-rule">' . esc_html__('Remove', 'custom-auction') . '</button></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<button type="button" class="button" id="add-increment-rule">' . esc_html__('Add Range', 'custom-auction') . '</button>';
        echo '<script>(function(){
            const table = document.getElementById("auction-increment-rules");
            const addBtn = document.getElementById("add-increment-rule");
            if(addBtn){
                addBtn.addEventListener("click", function(){
                    const tr = document.createElement("tr");
                    tr.innerHTML = "<td><input type=\"number\" step=\"0.01\" name=\"auction_increment_rules[min][]\" /></td>"+
                                   "<td><input type=\"number\" step=\"0.01\" name=\"auction_increment_rules[max][]\" /></td>"+
                                   "<td><input type=\"number\" step=\"0.01\" name=\"auction_increment_rules[inc][]\" /></td>"+
                                   "<td><button class=\"button remove-rule\">' . esc_js(__('Remove', 'custom-auction')) . '</button></td>";
                    table.querySelector("tbody").appendChild(tr);
                });
            }
            table && table.addEventListener("click", function(e){
                if(e.target && e.target.classList.contains("remove-rule")){
                    e.preventDefault();
                    const tr = e.target.closest("tr");
                    if(tr){ tr.remove(); }
                }
            });
        })();</script>';
    }

    public static function save_meta_boxes($post_id)
    {
        if (!isset($_POST['auction_meta_nonce']) || !wp_verify_nonce($_POST['auction_meta_nonce'], 'save_auction_meta')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $start_price   = isset($_POST['auction_start_price']) ? floatval(wp_unslash($_POST['auction_start_price'])) : '';
        $min_increment = isset($_POST['auction_min_increment']) ? floatval(wp_unslash($_POST['auction_min_increment'])) : '';
        $start_time    = isset($_POST['auction_start_time']) ? sanitize_text_field(wp_unslash($_POST['auction_start_time'])) : '';
        $end_time      = isset($_POST['auction_end_time']) ? sanitize_text_field(wp_unslash($_POST['auction_end_time'])) : '';
        $buy_now       = isset($_POST['auction_buy_now']) ? floatval(wp_unslash($_POST['auction_buy_now'])) : '';
        $rules_in      = isset($_POST['auction_increment_rules']) ? (array) $_POST['auction_increment_rules'] : [];

        update_post_meta($post_id, '_auction_start_price', $start_price);
        update_post_meta($post_id, '_auction_min_increment', $min_increment);
        update_post_meta($post_id, '_auction_start_time', $start_time);
        update_post_meta($post_id, '_auction_end_time', $end_time);
        update_post_meta($post_id, '_auction_buy_now', $buy_now);

        // Parse and save rules
        $rules = [];
        if (!empty($rules_in) && is_array($rules_in)) {
            $mins = isset($rules_in['min']) ? (array)$rules_in['min'] : [];
            $maxs = isset($rules_in['max']) ? (array)$rules_in['max'] : [];
            $incs = isset($rules_in['inc']) ? (array)$rules_in['inc'] : [];
            $count = max(count($mins), count($maxs), count($incs));
            for ($i = 0; $i < $count; $i++) {
                $min = isset($mins[$i]) ? floatval(wp_unslash($mins[$i])) : null;
                $max = isset($maxs[$i]) ? floatval(wp_unslash($maxs[$i])) : null;
                $inc = isset($incs[$i]) ? floatval(wp_unslash($incs[$i])) : null;
                if ($min === null || $max === null || $inc === null) continue;
                if ($min === '' || $max === '' || $inc === '') continue;
                if ($inc < 0) continue;
                // allow open upper bound if max is 0? We stick to min<=price<=max. Ignore invalid ranges
                if ($max < $min) continue;
                $rules[] = ['min' => $min, 'max' => $max, 'inc' => $inc];
            }
        }
        update_post_meta($post_id, '_auction_increment_rules', $rules);
    }

    public static function register_assets()
    {
        wp_register_script(
            'custom-auction-js',
            CUSTOM_AUCTION_URL . 'assets/js/auction.js',
            ['jquery'],
            CUSTOM_AUCTION_VERSION,
            true
        );
        wp_register_style(
            'custom-auction-css',
            CUSTOM_AUCTION_URL . 'assets/css/auction.css',
            [],
            CUSTOM_AUCTION_VERSION
        );
    }

    public static function enqueue_assets($auction_id = 0)
    {
        wp_enqueue_script('custom-auction-js');
        wp_enqueue_style('custom-auction-css');
        wp_localize_script('custom-auction-js', 'CustomAuction', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('custom_auction_nonce'),
            'auctionId' => (int)$auction_id,
        ]);
    }

    public static function enqueue_for_auction($auction_id = 0)
    {
        self::enqueue_assets($auction_id);
    }

    public static function shortcode_list($atts)
    {
        $atts = shortcode_atts([
            'limit' => 12,
        ], $atts, 'auction_list');

        $now = current_time('mysql');
        $q = new WP_Query([
            'post_type'      => 'auction',
            'posts_per_page' => (int)$atts['limit'],
            'post_status'    => 'publish',
            'meta_query'     => [
                [
                    'key'     => '_auction_start_time',
                    'value'   => $now,
                    'compare' => '<=',
                    'type'    => 'DATETIME',
                ],
                [
                    'key'     => '_auction_end_time',
                    'value'   => $now,
                    'compare' => '>=',
                    'type'    => 'DATETIME',
                ],
            ],
        ]);

        ob_start();
        echo '<div class="auction-list">';
        if ($q->have_posts()) {
            while ($q->have_posts()) {
                $q->the_post();
                $auction_id = get_the_ID();
                $highest = Custom_Auction_Bids::get_highest_bid($auction_id);
                $current = $highest ? $highest->amount : (float)get_post_meta($auction_id, '_auction_start_price', true);
                echo '<div class="auction-card">';
                echo '<h3><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></h3>';
                if (has_post_thumbnail()) {
                    echo get_the_post_thumbnail($auction_id, 'medium');
                }
                echo '<div class="auction-meta">' . esc_html__('Current Bid: ', 'custom-auction') . esc_html(number_format_i18n($current, 2)) . '</div>';
                echo '<a class="button" href="' . esc_url(get_permalink()) . '">' . esc_html__('View & Bid', 'custom-auction') . '</a>';
                echo '</div>';
            }
        } else {
            echo '<p>' . esc_html__('No active auctions right now.', 'custom-auction') . '</p>';
        }
        echo '</div>';
        wp_reset_postdata();
        return ob_get_clean();
    }

    // public static function shortcode_single($atts, $content = '') {
    //     $atts = shortcode_atts([
    //         'id' => 0,
    //     ], $atts, 'auction');
    //     $auction_id = (int)$atts['id'];
    //     if (!$auction_id) {
    //         if (get_post_type() === 'auction') {
    //             $auction_id = get_the_ID();
    //         } else {
    //             return '<p>' . esc_html__('Auction not specified.', 'custom-auction') . '</p>';
    //         }
    //     }
    //     return self::render_single_block($auction_id, true);
    // }

    public static function shortcode_single($atts, $content = '')
    {
        $atts = shortcode_atts([
            'id' => 0,
        ], $atts, 'auction');
        $auction_id = (int)$atts['id'];
        if (!$auction_id) {
            if (get_post_type() === 'auction') {
                $auction_id = get_the_ID();
            } else {
                return '<p>' . esc_html__('Auction not specified.', 'custom-auction') . '</p>';
            }
        }

        self::enqueue_for_auction($auction_id);

        $start_price   = (float)get_post_meta($auction_id, '_auction_start_price', true);
        $start_time    = get_post_meta($auction_id, '_auction_start_time', true);
        $end_time      = get_post_meta($auction_id, '_auction_end_time', true);
        $buy_now       = (float)get_post_meta($auction_id, '_auction_buy_now', true);
        $highest       = Custom_Auction_Bids::get_highest_bid($auction_id);
        $current       = $highest ? (float)$highest->amount : $start_price;
        $min_increment = self::compute_min_increment($auction_id, $current);

        ob_start();
        echo '<div class="auction-single" data-auction-id="' . esc_attr($auction_id) . '">';
        echo '<h3>' . esc_html(get_the_title($auction_id)) . '</h3>';
        echo apply_filters('the_content', get_post_field('post_content', $auction_id));
        echo '<div class="auction-stats">';
        echo '<div><strong>' . esc_html__('Current Bid:', 'custom-auction') . '</strong> <span class="auction-current">' . esc_html(number_format_i18n($current, 2)) . '</span></div>';
        echo '<div><strong>' . esc_html__('Min Increment:', 'custom-auction') . '</strong> ' . esc_html(number_format_i18n($min_increment, 2)) . '</div>';
        echo '<div><strong>' . esc_html__('Ends:', 'custom-auction') . '</strong> <span class="auction-end">' . esc_html($end_time) . '</span></div>';
        echo '</div>';

        $now = current_time('mysql');
        $active = ($start_time && $end_time && $start_time <= $now && $now <= $end_time);
        if ($active) {
            echo '<form class="auction-bid-form">';
            echo '<input type="number" step="0.01" min="' . esc_attr($current + $min_increment) . '" name="bid_amount" class="bid-amount" placeholder="' . esc_attr(number_format_i18n($current + $min_increment, 2)) . '" required /> ';
            echo '<button type="submit" class="button button-primary">' . esc_html__('Place Bid', 'custom-auction') . '</button>';
            echo '<div class="auction-message" style="margin-top:8px"></div>';
            echo '</form>';
        } else {
            echo '<p><em>' . esc_html__('This auction is not active.', 'custom-auction') . '</em></p>';
        }

        // Recent bids
        $bids = Custom_Auction_Bids::get_bids($auction_id, 10);
        echo '<h4>' . esc_html__('Recent Bids', 'custom-auction') . '</h4>';
        echo '<ul class="auction-bids">';
        if ($bids) {
            foreach ($bids as $bid) {
                $user = get_userdata($bid->user_id);
                $name = $user ? $user->display_name : __('Unknown', 'custom-auction');
                echo '<li>' . esc_html($name) . ' - ' . esc_html(number_format_i18n($bid->amount, 2)) . ' <small>(' . esc_html($bid->created_at) . ')</small></li>';
            }
        } else {
            echo '<li>' . esc_html__('No bids yet.', 'custom-auction') . '</li>';
        }
        echo '</ul>';
        echo '</div>';
        return ob_get_clean();
    }

    public static function shortcode_market($atts)
    {
        $atts = shortcode_atts([
            'per_page' => 12,
            'status'   => '', // active|upcoming|ended|all; default from URL or active
            'show_filters' => '1',
            'search'  => '',
        ], $atts, 'auction_market');

        self::enqueue_assets(0);

        $now = current_time('mysql');
        $status = isset($_GET['auction_status']) ? sanitize_text_field(wp_unslash($_GET['auction_status'])) : $atts['status'];
        if (!$status) {
            $status = 'active';
        }
        $s = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : $atts['search'];

        $meta_query = [];
        if ($status === 'active') {
            $meta_query = [
                ['key' => '_auction_start_time', 'value' => $now, 'compare' => '<=', 'type' => 'DATETIME'],
                ['key' => '_auction_end_time', 'value' => $now, 'compare' => '>=', 'type' => 'DATETIME'],
            ];
        } elseif ($status === 'upcoming') {
            $meta_query = [
                ['key' => '_auction_start_time', 'value' => $now, 'compare' => '>', 'type' => 'DATETIME'],
            ];
        } elseif ($status === 'ended') {
            $meta_query = [
                ['key' => '_auction_end_time', 'value' => $now, 'compare' => '<', 'type' => 'DATETIME'],
            ];
        }

        $q_args = [
            'post_type'      => 'auction',
            'posts_per_page' => (int)$atts['per_page'],
            'post_status'    => 'publish',
            's'              => $s,
        ];
        if (!empty($meta_query)) {
            $q_args['meta_query'] = $meta_query;
        }

        $q = new WP_Query($q_args);
        ob_start();

        echo '<div class="auction-market" data-status="' . esc_attr($status) . '">';

        if ($atts['show_filters'] === '1') {
            $base_url = remove_query_arg(['auction_status', 's']);
            echo '<div class="auction-market__filters">';
            $tabs = [
                'active' => __('Active', 'custom-auction'),
                'upcoming' => __('Upcoming', 'custom-auction'),
                'ended' => __('Ended', 'custom-auction'),
            ];
            echo '<div class="auction-tabs">';
            foreach ($tabs as $key => $label) {
                $url = esc_url(add_query_arg(['auction_status' => $key], $base_url));
                $class = ($status === $key) ? ' class="is-active"' : '';
                echo '<a href="' . $url . '"' . $class . '>' . esc_html($label) . '</a>';
            }
            echo '</div>';
            echo '<form class="auction-search" method="get">';
            echo '<input type="hidden" name="auction_status" value="' . esc_attr($status) . '" />';
            echo '<input type="search" name="s" value="' . esc_attr($s) . '" placeholder="' . esc_attr__('Search auctions...', 'custom-auction') . '" />';
            echo '<button class="button">' . esc_html__('Search', 'custom-auction') . '</button>';
            echo '</form>';
            echo '</div>';
        }

        echo '<div class="auction-grid">';
        if ($q->have_posts()) {
            while ($q->have_posts()) {
                $q->the_post();
                $auction_id = get_the_ID();
                $start_price   = (float)get_post_meta($auction_id, '_auction_start_price', true);
                $start_time    = get_post_meta($auction_id, '_auction_start_time', true);
                $end_time      = get_post_meta($auction_id, '_auction_end_time', true);
                $highest       = Custom_Auction_Bids::get_highest_bid($auction_id);
                $current       = $highest ? (float)$highest->amount : $start_price;
                $min_increment = self::compute_min_increment($auction_id, $current);
                $now = current_time('mysql');
                $is_active = ($start_time && $end_time && $start_time <= $now && $now <= $end_time);
                echo '<div class="auction-card">';
                echo '<div class="auction-card__media">';
                if (has_post_thumbnail($auction_id)) {
                    echo get_the_post_thumbnail($auction_id, 'medium');
                }
                echo '</div>';
                echo '<div class="auction-card__body">';
                echo '<h3 class="auction-card__title"><a href="' . esc_url(get_permalink($auction_id)) . '">' . esc_html(get_the_title($auction_id)) . '</a></h3>';
                echo '<div class="auction-card__meta">';
                echo '<span class="label">' . esc_html__('Current:', 'custom-auction') . '</span> <span class="auction-current">' . esc_html(number_format_i18n($current, 2)) . '</span>';
                if ($is_active) {
                    echo ' <span class="sep">|</span> <span class="label">' . esc_html__('Ends in:', 'custom-auction') . '</span> <span class="auction-countdown" data-end="' . esc_attr($end_time) . '"></span>';
                } else {
                    $status_label = ($end_time && $end_time < $now) ? __('Ended', 'custom-auction') : __('Upcoming', 'custom-auction');
                    echo ' <span class="sep">|</span> <span class="label">' . esc_html__('Status:', 'custom-auction') . '</span> <span class="auction-status">' . esc_html($status_label) . '</span>';
                }
                echo '</div>';
                if ($is_active) {
                    echo '<form class="auction-bid-form">';
                    echo '<div class="auction-bid-row">';
                    echo '<input type="number" step="0.01" min="' . esc_attr($current + $min_increment) . '" name="bid_amount" class="bid-amount" placeholder="' . esc_attr(number_format_i18n($current + $min_increment, 2)) . '" required /> ';
                    echo '<button type="submit" class="button button-primary">' . esc_html__('Place Bid', 'custom-auction') . '</button>';
                    echo '</div>';
                    echo '<div class="auction-message" style="margin-top:8px"></div>';
                    echo '</form>';
                } else {
                    echo '<a class="button" href="' . esc_url(get_permalink($auction_id)) . '">' . esc_html__('View Auction', 'custom-auction') . '</a>';
                }
                echo '</div>';
                echo '<div class="auction-single" data-auction-id="' . esc_attr($auction_id) . '" style="display:none"></div>';
                echo '</div>';
            }
        } else {
            echo '<p>' . esc_html__('No auctions found for this filter.', 'custom-auction') . '</p>';
        }
        echo '</div>';
        echo '</div>';
        wp_reset_postdata();
        return ob_get_clean();
    }

    public static function filter_the_content($content)
    {
        if (is_singular('auction') && in_the_loop() && is_main_query()) {
            $auction_id = get_the_ID();
            if (class_exists('Custom_Auction_Logger')) {
                Custom_Auction_Logger::debug('Render single auction UI', ['auction_id' => $auction_id]);
            }
            $ui = self::render_single_block($auction_id, false);
            return $content . $ui;
        }
        return $content;
    }

    protected static function validate_bid($auction_id, $user_id, $amount)
    {
        if (!$user_id || !is_user_logged_in()) {
            if (class_exists('Custom_Auction_Logger')) {
                Custom_Auction_Logger::warning('Bid blocked: not logged in', ['auction_id' => $auction_id]);
            }
            return new WP_Error('not_logged_in', __('You must be logged in to bid.', 'custom-auction'));
        }
        $post = get_post($auction_id);
        if (!$post || $post->post_type !== 'auction' || $post->post_status !== 'publish') {
            if (class_exists('Custom_Auction_Logger')) {
                Custom_Auction_Logger::warning('Bid blocked: invalid auction', ['auction_id' => $auction_id]);
            }
            return new WP_Error('invalid_auction', __('Invalid auction.', 'custom-auction'));
        }
        $start_time = get_post_meta($auction_id, '_auction_start_time', true);
        $end_time   = get_post_meta($auction_id, '_auction_end_time', true);
        $now        = current_time('mysql');
        if (!$start_time || !$end_time || $start_time > $now || $end_time < $now) {
            if (class_exists('Custom_Auction_Logger')) {
                Custom_Auction_Logger::info('Bid blocked: auction not active', ['auction_id' => $auction_id, 'now' => $now, 'start' => $start_time, 'end' => $end_time]);
            }
            return new WP_Error('not_active', __('Auction is not active.', 'custom-auction'));
        }
        $start_price   = (float)get_post_meta($auction_id, '_auction_start_price', true);
        $highest       = Custom_Auction_Bids::get_highest_bid($auction_id);
        $current       = $highest ? (float)$highest->amount : $start_price;
        $min_increment = self::compute_min_increment($auction_id, $current);
        $min_required  = $current + max($min_increment, 0);
        if ($amount < $min_required) {
            if (class_exists('Custom_Auction_Logger')) {
                Custom_Auction_Logger::info('Bid blocked: amount too low', ['auction_id' => $auction_id, 'amount' => $amount, 'min_required' => $min_required]);
            }
            return new WP_Error('amount_too_low', sprintf(__('Bid must be at least %s', 'custom-auction'), number_format_i18n($min_required, 2)));
        }
        return true;
    }

    public static function ajax_place_bid()
    {
        check_ajax_referer('custom_auction_nonce', 'nonce');
        $auction_id = isset($_POST['auction_id']) ? absint($_POST['auction_id']) : 0;
        $amount     = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $user_id    = get_current_user_id();

        $valid = self::validate_bid($auction_id, $user_id, $amount);
        if (is_wp_error($valid)) {
            if (class_exists('Custom_Auction_Logger')) {
                Custom_Auction_Logger::warning('Bid validation failed', ['auction_id' => $auction_id, 'user_id' => $user_id, 'amount' => $amount, 'error' => $valid->get_error_code()]);
            }
            wp_send_json_error(['message' => $valid->get_error_message()]);
        }

        if (!Custom_Auction_Bids::place_bid($auction_id, $user_id, $amount)) {
            global $wpdb;
            if (class_exists('Custom_Auction_Logger')) {
                Custom_Auction_Logger::error('Bid insert failed', ['auction_id' => $auction_id, 'user_id' => $user_id, 'amount' => $amount, 'db_error' => isset($wpdb) ? $wpdb->last_error : '']);
            }
            wp_send_json_error(['message' => __('Unable to place bid. Try again.', 'custom-auction')]);
        }

        if (class_exists('Custom_Auction_Logger')) {
            Custom_Auction_Logger::info('Bid placed', ['auction_id' => $auction_id, 'user_id' => $user_id, 'amount' => $amount]);
        }
        $highest = Custom_Auction_Bids::get_highest_bid($auction_id);
        $current = $highest ? (float)$highest->amount : $amount;
        $min_increment = self::compute_min_increment($auction_id, $current);
        wp_send_json_success([
            'current' => $current,
            'min_increment' => $min_increment,
            'message' => __('Bid placed successfully!', 'custom-auction'),
        ]);
    }

    public static function ajax_get_data()
    {
        $auction_id = isset($_REQUEST['auction_id']) ? absint($_REQUEST['auction_id']) : 0;
        if (!$auction_id && class_exists('Custom_Auction_Logger')) {
            Custom_Auction_Logger::warning('AJAX get_data without auction_id');
        }
        $start_price   = (float)get_post_meta($auction_id, '_auction_start_price', true);
        $highest       = Custom_Auction_Bids::get_highest_bid($auction_id);
        $current       = $highest ? (float)$highest->amount : $start_price;
        $end_time      = get_post_meta($auction_id, '_auction_end_time', true);
        $min_increment = self::compute_min_increment($auction_id, $current);
        wp_send_json_success([
            'current'  => $current,
            'end_time' => $end_time,
            'min_increment' => $min_increment,
        ]);
    }

    public static function register_admin_pages()
    {
        add_submenu_page(
            'edit.php?post_type=auction',
            __('Auction Bids', 'custom-auction'),
            __('Bids', 'custom-auction'),
            'edit_posts',
            'auction-bids',
            [__CLASS__, 'render_bids_page']
        );
    }

    public static function render_bids_page()
    {
        if (!current_user_can('edit_posts')) return;
        $auction_id = isset($_GET['auction_id']) ? absint($_GET['auction_id']) : 0;
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Auction Bids', 'custom-auction') . '</h1>';

        // Filter
        echo '<form method="get" style="margin-bottom:16px">';
        echo '<input type="hidden" name="post_type" value="auction" />';
        echo '<input type="hidden" name="page" value="auction-bids" />';
        echo '<label>' . esc_html__('Select Auction: ', 'custom-auction') . '</label>';
        echo '<select name="auction_id">';
        echo '<option value="0">' . esc_html__('All', 'custom-auction') . '</option>';
        $auctions = get_posts(['post_type' => 'auction', 'numberposts' => -1, 'post_status' => 'any']);
        foreach ($auctions as $a) {
            echo '<option value="' . esc_attr($a->ID) . '" ' . selected($auction_id, $a->ID, false) . '>' . esc_html($a->post_title) . '</option>';
        }
        echo '</select> <button class="button">' . esc_html__('Filter', 'custom-auction') . '</button>';
        echo '</form>';

        // Table
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr><th>' . esc_html__('Auction', 'custom-auction') . '</th><th>' . esc_html__('User', 'custom-auction') . '</th><th>' . esc_html__('Amount', 'custom-auction') . '</th><th>' . esc_html__('Time', 'custom-auction') . '</th></tr></thead><tbody>';
        global $wpdb;
        $table = Custom_Auction_Bids::table_name();
        if ($auction_id) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE auction_id = %d ORDER BY id DESC LIMIT 200", $auction_id));
        } else {
            $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 200");
        }
        if ($rows) {
            foreach ($rows as $row) {
                $auction = get_post($row->auction_id);
                $user = get_userdata($row->user_id);
                echo '<tr>';
                echo '<td>' . esc_html($auction ? $auction->post_title : ('#' . $row->auction_id)) . '</td>';
                echo '<td>' . esc_html($user ? $user->display_name : ('#' . $row->user_id)) . '</td>';
                echo '<td>' . esc_html(number_format_i18n($row->amount, 2)) . '</td>';
                echo '<td>' . esc_html($row->created_at) . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="4">' . esc_html__('No bids found.', 'custom-auction') . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    protected static function compute_min_increment($auction_id, $current_price)
    {
        $rules = get_post_meta($auction_id, '_auction_increment_rules', true);
        $fallback = (float)get_post_meta($auction_id, '_auction_min_increment', true);
        if (is_array($rules) && !empty($rules)) {
            foreach ($rules as $r) {
                $min = isset($r['min']) ? (float)$r['min'] : null;
                $max = isset($r['max']) ? (float)$r['max'] : null;
                $inc = isset($r['inc']) ? (float)$r['inc'] : null;
                if ($min === null || $max === null || $inc === null) continue;
                if ($current_price >= $min && $current_price <= $max) {
                    return max(0, (float)$inc);
                }
            }
        }
        return max(0, (float)$fallback);
    }

    protected static function render_single_block($auction_id, $include_post_content = true)
    {
        self::enqueue_for_auction($auction_id);

        $start_price   = (float)get_post_meta($auction_id, '_auction_start_price', true);
        $start_time    = get_post_meta($auction_id, '_auction_start_time', true);
        $end_time      = get_post_meta($auction_id, '_auction_end_time', true);
        $buy_now       = (float)get_post_meta($auction_id, '_auction_buy_now', true);
        $highest       = Custom_Auction_Bids::get_highest_bid($auction_id);
        $current       = $highest ? (float)$highest->amount : $start_price;
        $min_increment = self::compute_min_increment($auction_id, $current);

        ob_start();
        echo '<div class="auction-single" data-auction-id="' . esc_attr($auction_id) . '">';
        echo '<h3>' . esc_html(get_the_title($auction_id)) . '</h3>';
        if ($include_post_content) {
            echo apply_filters('the_content', get_post_field('post_content', $auction_id));
        }
        echo '<div class="auction-stats">';
        echo '<div><strong>' . esc_html__('Current Bid:', 'custom-auction') . '</strong> <span class="auction-current">' . esc_html(number_format_i18n($current, 2)) . '</span></div>';
        echo '<div><strong>' . esc_html__('Min Increment:', 'custom-auction') . '</strong> ' . esc_html(number_format_i18n($min_increment, 2)) . '</div>';
        echo '<div><strong>' . esc_html__('Ends:', 'custom-auction') . '</strong> <span class="auction-end auction-countdown" data-end="' . esc_attr($end_time) . '">' . esc_html($end_time) . '</span></div>';
        echo '</div>';

        $now = current_time('mysql');
        $active = ($start_time && $end_time && $start_time <= $now && $now <= $end_time);
        if ($active) {
            echo '<form class="auction-bid-form">';
            echo '<input type="number" step="0.01" min="' . esc_attr($current + $min_increment) . '" name="bid_amount" class="bid-amount" placeholder="' . esc_attr(number_format_i18n($current + $min_increment, 2)) . '" required /> ';
            echo '<button type="submit" class="button button-primary">' . esc_html__('Place Bid', 'custom-auction') . '</button>';
            echo '<div class="auction-message" style="margin-top:8px"></div>';
            echo '</form>';
        } else {
            echo '<p><em>' . esc_html__('This auction is not active.', 'custom-auction') . '</em></p>';
        }

        $bids = Custom_Auction_Bids::get_bids($auction_id, 10);
        echo '<h4>' . esc_html__('Recent Bids', 'custom-auction') . '</h4>';
        echo '<ul class="auction-bids">';
        if ($bids) {
            foreach ($bids as $bid) {
                $user = get_userdata($bid->user_id);
                $name = $user ? $user->display_name : __('Unknown', 'custom-auction');
                echo '<li>' . esc_html($name) . ' - ' . esc_html(number_format_i18n($bid->amount, 2)) . ' <small>(' . esc_html($bid->created_at) . ')</small></li>';
            }
        } else {
            echo '<li>' . esc_html__('No bids yet.', 'custom-auction') . '</li>';
        }
        echo '</ul>';
        echo '</div>';
        return ob_get_clean();
    }
}
