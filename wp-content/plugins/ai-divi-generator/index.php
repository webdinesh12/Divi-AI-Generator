<?php

/**
 * Plugin Name: AI → Divi Page Generator (Google Gemini)
 * Description: Generate or modify Divi pages dynamically from natural-language brief using Google Gemini API (AJAX ready).
 * Version: 1.0.1
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'ai-divi-generator-page.php';
require_once plugin_dir_path(__FILE__) . 'ai-divi-generator-section.php';

class AIDiviPluginBootstrap
{
    private $generator;
    private $tools;

    public function __construct()
    {
        // $this->generator = new AIDiviGenerator();
        $this->tools = new AIDiviTools();
    }
}

new AIDiviPluginBootstrap();
