<?php

/**
 * Plugin Name: AI → Divi Page Generator (Google Gemini)
 * Description: Generate or modify Divi pages dynamically from natural-language brief using Google Gemini API (AJAX ready).
 * Version: 1.0.1
 * Author: Programmer
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


function dd(...$args)
{
    echo '<pre>';
    foreach ($args as $arg) {
        print_r($arg);
    }
}
function ddx(...$args)
{
    dd($args);
    exit;
}

function ai_clean_json($text)
{
    $text = preg_replace('/[\x00-\x1F\x7F\xA0\xAD\x{200B}-\x{200F}\x{FEFF}]/u', '', $text);
    $text = preg_replace('/^```json\s*/i', '', $text);
    $text = preg_replace('/^```\s*/', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);
    $text = str_replace('```', '', $text);
    return json_decode(trim($text), true);
}
