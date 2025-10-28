<?php
if (! defined('ABSPATH')) exit;

class WPAISB_AIClient
{
    private $settings;

    public function __construct($settings)
    {
        $this->settings = $settings;
    }

    /**
     * Accepts either a plain string prompt or a spec array:
     * [ 'text' => string, 'image_path' => string, 'image_mime' => string ]
     * Returns array { 'title' => string|null, 'content' => string } or WP_Error
     */
    public function generate($input)
    {
        $provider = $this->settings['provider'] ?? 'gemini';
        if ($provider !== 'gemini' && $provider !== 'openai_compat') {
            $provider = 'gemini';
        }
        if ($provider === 'gemini') {
            return $this->call_gemini($input);
        }
        // OpenAI-compatible: only text is supported; coerce to string
        $text = is_array($input) ? ($input['text'] ?? '') : (string)$input;
        return $this->call_openai_compat($text);
    }

    private function call_gemini($input)
    {
        $model = $this->settings['gemini_model'] ?? '';
        $key   = $this->settings['gemini_api_key'] ?? '';
        if (empty($model) || empty($key)) {
            return new WP_Error('wpaisb_gemini_missing', 'Gemini model or API key is not set.');
        }

        $base = 'https://generativelanguage.googleapis.com/v1beta';
        $url = trailingslashit($base) . 'models/' . rawurlencode($model) . ':generateContent' . '?key=' . rawurlencode($key);

        $generationConfig = [
            'temperature' => 0.15,
            // Slightly lower to reduce latency and truncation risk
            'maxOutputTokens' => 2048,
            // Gemini allows only specific MIME types; use plain text and steer via prompt
            'response_mime_type' => 'text/plain'
        ];

        $text = is_array($input) ? ($input['text'] ?? '') : (string)$input;
        $contents = [['role' => 'user', 'parts' => [['text' => $text]]]];
        // If an image was provided, add it as inlineData for proper multimodal guidance
        if (is_array($input) && !empty($input['image_path']) && file_exists($input['image_path'])) {
            $mime = !empty($input['image_mime']) ? $input['image_mime'] : 'image/jpeg';
            $data = @file_get_contents($input['image_path']);
            if ($data !== false) {
                $contents[0]['parts'][] = [
                    'inlineData' => [
                        'mimeType' => $mime,
                        'data' => base64_encode($data)
                    ]
                ];
            }
        }

        // Overall deadline to keep total work under PHP max_execution_time
        $overall_deadline = microtime(true) + 70; // seconds from now

        $send = function (array $contents, $timeout) use ($url, $generationConfig) {
            $payload = [
                'contents' => $contents,
                'generationConfig' => $generationConfig
            ];
            $response = wp_remote_post($url, [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode($payload),
                // Per-attempt timeout (computed against remaining deadline)
                'timeout' => max(5, (int)$timeout),
                'redirection' => 3
            ]);
            return $response;
        };

        // Retry wrapper with overall deadline awareness for timeouts / 5xx / 429
        $request_with_retry = function (array $contents, $deadline, $max_attempts = 2) use ($send) {
            $attempt = 0;
            $delay = 2; // seconds
            $last = null;
            while ($attempt < $max_attempts) {
                $attempt++;
                $remaining = $deadline - microtime(true);
                if ($remaining <= 5) {
                    break;
                }
                $timeout = min(30, max(5, (int)floor($remaining - 2)));
                $resp = $send($contents, $timeout);
                $last = $resp;
                if (is_wp_error($resp)) {
                    $msg = $resp->get_error_message();
                    // Retry only on timeouts / connection issues
                    if ($attempt < $max_attempts && (stripos($msg, 'timed out') !== false || stripos($msg, 'cURL error 28') !== false)) {
                        if (($deadline - microtime(true)) > ($delay + 5)) {
                            sleep($delay);
                        }
                        $delay = min($delay * 2, 8);
                        continue;
                    }
                } else {
                    $code = wp_remote_retrieve_response_code($resp);
                    if ($code == 429 || ($code >= 500 && $code <= 599)) {
                        if ($attempt < $max_attempts) {
                            if (($deadline - microtime(true)) > ($delay + 5)) {
                                sleep($delay);
                            }
                            $delay = min($delay * 2, 8);
                            continue;
                        }
                    }
                }
                break; // success or non-retryable error
            }
            return $last;
        };

        $response = $request_with_retry($contents, $overall_deadline);
        if (is_wp_error($response)) {
            return new WP_Error('wpaisb_gemini_timeout', 'Gemini request failed: ' . $response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if ($code >= 400) {
            $msg = isset($json['error']['message']) ? $json['error']['message'] : 'Gemini API error.';
            return new WP_Error('wpaisb_gemini_http', $msg);
        }

        // Extract text from candidates -> content -> parts[] (robust)
        $generated = '';
        $finishReason = '';
        $lastModelContent = null;
        if (isset($json['candidates']) && is_array($json['candidates'])) {
            foreach ($json['candidates'] as $candidate) {
                if (isset($candidate['content'])) {
                    $lastModelContent = $candidate['content'];
                }
                if (isset($candidate['content']['parts']) && is_array($candidate['content']['parts'])) {
                    foreach ($candidate['content']['parts'] as $part) {
                        if (isset($part['text']) && is_string($part['text'])) {
                            $generated .= (string) $part['text'];
                        }
                    }
                }
                if (isset($candidate['finishReason']) && is_string($candidate['finishReason'])) {
                    $finishReason = $candidate['finishReason'];
                }
                if ($generated !== '') break;
            }
        }

        // If cut off by tokens, try to continue up to 3 times
        $attempts = 0;
        while ($finishReason === 'MAX_TOKENS' && $attempts < 3) {
            $attempts++;
            // Keep the conversation short: original user prompt + last model content + continue request
            $continueContents = [$contents[0]];
            if ($lastModelContent) {
                $continueContents[] = $lastModelContent;
            }
            $continueContents[] = ['role' => 'user', 'parts' => [['text' => 'Continue the last response exactly where it stopped. Do not repeat any content. Return only the remaining content.']]];

            $response = $request_with_retry($continueContents, $overall_deadline);
            if (is_wp_error($response)) break;
            $code = wp_remote_retrieve_response_code($response);
            $json = json_decode(wp_remote_retrieve_body($response), true);
            if ($code >= 400) break;

            $chunk = '';
            $finishReason = '';
            $lastModelContent = null;
            if (isset($json['candidates']) && is_array($json['candidates'])) {
                foreach ($json['candidates'] as $candidate) {
                    if (isset($candidate['content'])) {
                        $lastModelContent = $candidate['content'];
                    }
                    if (isset($candidate['content']['parts']) && is_array($candidate['content']['parts'])) {
                        foreach ($candidate['content']['parts'] as $part) {
                            if (isset($part['text']) && is_string($part['text'])) {
                                $chunk .= (string) $part['text'];
                            }
                        }
                    }
                    if (isset($candidate['finishReason']) && is_string($candidate['finishReason'])) {
                        $finishReason = $candidate['finishReason'];
                    }
                    if ($chunk !== '') break;
                }
            }
            if ($chunk === '' && $finishReason !== 'MAX_TOKENS') break;
            $generated .= $chunk;
        }

        if ($generated === '') {
            $finish = $finishReason ? ' (finishReason: ' . $finishReason . ')' : '';
            return new WP_Error('wpaisb_gemini_no_text', 'Gemini returned no text' . $finish . '. Try a shorter prompt or a different model.');
        }

        $parsed = $this->parse_output($generated);
        return $parsed;
    }



    private function call_openai_compat($prompt)
    {
        $base  = trim($this->settings['openai_compat_base'] ?? '');
        $key   = $this->settings['openai_compat_key'] ?? '';
        $model = $this->settings['openai_compat_model'] ?? '';
        if (empty($base) || empty($key) || empty($model)) {
            return new WP_Error('wpaisb_oai_missing', 'OpenAI-compatible base URL, key, or model missing.');
        }

        $url = rtrim($base, '/') . '/chat/completions';
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a senior WordPress/frontend expert. Output only HTML/CSS for the page body.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.2,
            'max_tokens' => 2000
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 60
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $json = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            $msg = isset($json['error']['message']) ? $json['error']['message'] : 'OpenAI-compatible API error.';
            return new WP_Error('wpaisb_oai_http', $msg);
        }

        $content = $json['choices'][0]['message']['content'] ?? '';
        $parsed = $this->parse_output($content);
        return $parsed;
    }

    private function parse_output($text)
    {
        // Try to extract a title (e.g., from <h1> or first line).
        $title = null;
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $text, $m)) {
            $title = wp_strip_all_tags($m[1]);
        } else {
            $lines = preg_split('/\r\n|\r|\n/', trim($text));
            if (!empty($lines)) {
                $maybe = wp_strip_all_tags($lines[0]);
                if (strlen($maybe) > 0 && strlen($maybe) < 80) $title = $maybe;
            }
        }

        // Remove markdown fences if the model wrapped code
        $text = preg_replace('/```[a-zA-Z0-9]*\s*/m', '', $text);
        $text = str_replace('```', '', $text);

        return [
            'title'   => $title,
            'content' => trim($text)
        ];
    }
}
