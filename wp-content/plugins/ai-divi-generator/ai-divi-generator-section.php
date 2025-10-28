<?php
if (!defined('ABSPATH')) exit;

class AIDiviTools
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_tools_submenu']);
    }

    public function add_tools_submenu()
    {
        add_submenu_page(
            'ai-divi',
            'AI → Divi Tools',
            'Tools',
            'edit_pages',
            'ai-divi-tools',
            [$this, 'render_tools_page']
        );
    }

    public function render_tools_page()
    {
        echo '<div class="wrap"><h1>AI → Divi Tools</h1><p>Future utilities will go here.</p></div>';
    }
}
