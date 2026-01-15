<?php
/**
 * REST API class for Stickiness Analytics
 * Handles API endpoints for AJAX and external access
 */

if (!defined('ABSPATH')) {
    exit;
}

class SA_REST_API {
    
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        $namespace = 'stickiness-analytics/v1';
        
        // Public tracking endpoint
        register_rest_route($namespace, '/track', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_track'],
            'permission_callback' => '__return_true',
        ]);
        
        // Overview stats
        register_rest_route($namespace, '/overview', [
            'methods' => 'GET',
            'callback' => [$this, 'get_overview'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args' => [
                'days' => [
                    'default' => 30,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0 && $param <= 365;
                    }
                ]
            ]
        ]);
        
        // Stickiness trend
        register_rest_route($namespace, '/trend', [
            'methods' => 'GET',
            'callback' => [$this, 'get_trend'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args' => [
                'days' => [
                    'default' => 30,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0 && $param <= 90;
                    }
                ]
            ]
        ]);
        
        // Page stickiness
        register_rest_route($namespace, '/pages', [
            'methods' => 'GET',
            'callback' => [$this, 'get_page_stickiness'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args' => [
                'days' => ['default' => 30],
                'limit' => ['default' => 50],
            ]
        ]);
        
        // Demographics
        register_rest_route($namespace, '/demographics', [
            'methods' => 'GET',
            'callback' => [$this, 'get_demographics'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);
        
        // Segment stickiness
        register_rest_route($namespace, '/segment/(?P<type>[a-z]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_segment_stickiness'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);
        
        // Retention cohorts
        register_rest_route($namespace, '/cohorts', [
            'methods' => 'GET',
            'callback' => [$this, 'get_cohorts'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);
        
        // AI Insights
        register_rest_route($namespace, '/insights', [
            'methods' => 'POST',
            'callback' => [$this, 'generate_insights'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);
        
        // Export data
        register_rest_route($namespace, '/export', [
            'methods' => 'GET',
            'callback' => [$this, 'export_data'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);
    }
    
    /**
     * Check if user has admin permissions
     */
    public function check_admin_permission() {
        return current_user_can('manage_options');
    }
    
    /**
     * Handle tracking request via REST
     */
    public function handle_track($request) {
        $tracker = new SA_Tracker();
        
        // Simulate POST data from REST params
        $_POST = array_merge($_POST, $request->get_params());
        
        // Generate and return visitor hash
        $visitor_hash = $this->get_visitor_hash($request);
        
        return new WP_REST_Response([
            'success' => true,
            'visitor_hash' => $visitor_hash,
        ], 200);
    }
    
    /**
     * Get overview statistics
     */
    public function get_overview($request) {
        $analytics = new SA_Analytics();
        $days = intval($request->get_param('days'));
        
        return new WP_REST_Response(
            $analytics->get_overview_stats($days),
            200
        );
    }
    
    /**
     * Get stickiness trend data
     */
    public function get_trend($request) {
        $analytics = new SA_Analytics();
        $days = intval($request->get_param('days'));
        
        return new WP_REST_Response(
            $analytics->get_stickiness_trend($days),
            200
        );
    }
    
    /**
     * Get page stickiness rankings
     */
    public function get_page_stickiness($request) {
        $analytics = new SA_Analytics();
        $days = intval($request->get_param('days'));
        $limit = intval($request->get_param('limit'));
        
        return new WP_REST_Response(
            $analytics->get_page_stickiness($days, $limit),
            200
        );
    }
    
    /**
     * Get demographics data
     */
    public function get_demographics($request) {
        $analytics = new SA_Analytics();
        $days = intval($request->get_param('days') ?: 30);
        
        return new WP_REST_Response(
            $analytics->get_demographics($days),
            200
        );
    }
    
    /**
     * Get stickiness by segment
     */
    public function get_segment_stickiness($request) {
        $demographics = new SA_Demographics();
        $type = $request->get_param('type');
        $days = intval($request->get_param('days') ?: 30);
        
        return new WP_REST_Response(
            $demographics->get_stickiness_by_segment($type, $days),
            200
        );
    }
    
    /**
     * Get retention cohorts
     */
    public function get_cohorts($request) {
        $analytics = new SA_Analytics();
        $weeks = intval($request->get_param('weeks') ?: 8);
        
        return new WP_REST_Response(
            $analytics->get_retention_cohorts($weeks),
            200
        );
    }
    
    /**
     * Generate AI insights
     */
    public function generate_insights($request) {
        $provider = get_option('sa_ai_provider', 'openai');
        
        if ($provider === 'mistral') {
            $api_key = get_option('sa_mistral_api_key', '');
            if (empty($api_key)) {
                return new WP_REST_Response([
                    'error' => 'Mistral AI API key not configured. Please add your API key in Settings.',
                ], 400);
            }
        } else {
            $api_key = get_option('sa_openai_api_key', '');
            if (empty($api_key)) {
                return new WP_REST_Response([
                    'error' => 'OpenAI API key not configured. Please add your API key in Settings.',
                ], 400);
            }
        }
        
        // Gather data for analysis
        $analytics = new SA_Analytics();
        $demographics = new SA_Demographics();
        
        $data = [
            'overview' => $analytics->get_overview_stats(30),
            'top_pages' => $analytics->get_page_stickiness(30, 10),
            'device_stickiness' => $demographics->get_stickiness_by_segment('device', 30),
            'country_stickiness' => $demographics->get_stickiness_by_segment('country', 30),
            'new_vs_returning' => $demographics->get_new_vs_returning_engagement(30),
            'time_patterns' => $demographics->get_engagement_by_time(30),
        ];
        
        $question = $request->get_param('question');
        
        // Build the prompt
        $prompt = $this->build_insights_prompt($data, $question);
        
        $system_message = 'You are an expert web analytics consultant specializing in user engagement and retention metrics. Analyze the provided DAU/MAU and stickiness data and provide actionable, specific insights. Focus on identifying patterns, explaining why certain pages or segments perform better, and giving concrete recommendations. Format your response in clear sections with markdown headers.';
        
        // Call appropriate API
        if ($provider === 'mistral') {
            $response = $this->call_mistral_api($api_key, $system_message, $prompt);
        } else {
            $response = $this->call_openai_api($api_key, $system_message, $prompt);
        }
        
        if (is_wp_error($response)) {
            return new WP_REST_Response([
                'error' => $response->get_error_message(),
            ], 500);
        }
        
        return new WP_REST_Response([
            'insights' => $response,
            'provider' => $provider,
            'generated_at' => current_time('mysql'),
        ], 200);
    }
    
    /**
     * Call OpenAI API
     */
    private function call_openai_api($api_key, $system_message, $prompt) {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'model' => 'gpt-4-turbo-preview',
                'messages' => [
                    ['role' => 'system', 'content' => $system_message],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => 2000,
                'temperature' => 0.7,
            ]),
        ]);
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', 'Failed to connect to OpenAI: ' . $response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('api_error', 'OpenAI error: ' . $body['error']['message']);
        }
        
        return $body['choices'][0]['message']['content'] ?? 'No insights generated.';
    }
    
    /**
     * Call Mistral AI API
     */
    private function call_mistral_api($api_key, $system_message, $prompt) {
        $agent_id = get_option('sa_mistral_agent_id', '');
        
        // If agent ID is configured, use the Agents API
        if (!empty($agent_id)) {
            $response = wp_remote_post('https://api.mistral.ai/v1/agents/completions', [
                'timeout' => 60,
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'agent_id' => $agent_id,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]),
            ]);
        } else {
            // Fallback to standard chat completions
            $response = wp_remote_post('https://api.mistral.ai/v1/chat/completions', [
                'timeout' => 60,
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'model' => 'mistral-small-latest',
                    'messages' => [
                        ['role' => 'system', 'content' => $system_message],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => 2000,
                    'temperature' => 0.7,
                ]),
            ]);
        }
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', 'Failed to connect to Mistral AI: ' . $response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('api_error', 'Mistral AI error: ' . ($body['error']['message'] ?? json_encode($body['error'])));
        }
        
        return $body['choices'][0]['message']['content'] ?? 'No insights generated.';
    }
    
    /**
     * Build the insights prompt with data
     */
    private function build_insights_prompt($data, $question = null) {
        $prompt = "# Website Stickiness Analytics Data\n\n";
        
        // Overview
        $prompt .= "## Overall Metrics (Last 30 Days)\n";
        $prompt .= "- DAU/MAU Stickiness Ratio: {$data['overview']['stickiness']['value']}%\n";
        $prompt .= "- Daily Active Users (today): {$data['overview']['dau']['today']}\n";
        $prompt .= "- Monthly Active Users (30-day): {$data['overview']['mau']['value']}\n";
        $prompt .= "- Unique Visitors: {$data['overview']['unique_visitors']['current']}\n";
        $prompt .= "- Total Pageviews: {$data['overview']['pageviews']['current']}\n";
        $prompt .= "- Bounce Rate: {$data['overview']['bounce_rate']}%\n";
        $prompt .= "- Avg Time on Page: {$data['overview']['avg_time_on_page']} seconds\n";
        $prompt .= "- New Visitors: {$data['overview']['new_visitors']}\n";
        $prompt .= "- Returning Visitors: {$data['overview']['returning_visitors']}\n\n";
        
        // Top Pages
        $prompt .= "## Top Sticky Pages\n";
        foreach ($data['top_pages'] as $i => $page) {
            $prompt .= ($i + 1) . ". **{$page['page_title']}** - Stickiness: {$page['stickiness_score']}%, ";
            $prompt .= "Visitors: {$page['unique_visitors']}, Bounce: {$page['bounce_rate']}%, ";
            $prompt .= "Avg Time: {$page['avg_time_on_page']}s\n";
        }
        $prompt .= "\n";
        
        // Device Stickiness
        $prompt .= "## Stickiness by Device\n";
        foreach ($data['device_stickiness'] as $device) {
            $prompt .= "- {$device['segment']}: {$device['stickiness']}% stickiness, ";
            $prompt .= "{$device['unique_visitors']} visitors, {$device['pages_per_visitor']} pages/visit\n";
        }
        $prompt .= "\n";
        
        // New vs Returning
        $prompt .= "## New vs Returning Visitors\n";
        foreach ($data['new_vs_returning'] as $type => $stats) {
            $prompt .= "- " . ucfirst($type) . ": {$stats['visitors']} visitors, ";
            $prompt .= "{$stats['pages_per_visitor']} pages/visitor, {$stats['bounce_rate']}% bounce\n";
        }
        $prompt .= "\n";
        
        // Top Countries
        $prompt .= "## Top Countries by Stickiness\n";
        foreach (array_slice($data['country_stickiness'], 0, 5) as $country) {
            $prompt .= "- {$country['segment']}: {$country['stickiness']}% ({$country['unique_visitors']} visitors)\n";
        }
        $prompt .= "\n";
        
        // Question or default
        if ($question) {
            $questions_map = [
                'why_sticky' => 'Analyze why the top pages have high stickiness scores. What characteristics do they share? What can we learn from them?',
                'improve_retention' => 'Based on this data, what are the top 5 specific, actionable recommendations to improve visitor retention and increase the DAU/MAU ratio?',
                'device_differences' => 'Why might there be differences in stickiness between device types? What does this suggest about the mobile vs desktop experience?',
                'best_times' => 'When are users most engaged? What does the time pattern data suggest about the best times to publish content or send notifications?',
            ];
            $prompt .= "## Analysis Question\n";
            $prompt .= $questions_map[$question] ?? $question;
        } else {
            $prompt .= "## Analysis Request\n";
            $prompt .= "Please provide a comprehensive analysis of this website's stickiness metrics including:\n";
            $prompt .= "1. Overall assessment of the DAU/MAU ratio and what it means for this site\n";
            $prompt .= "2. What makes the top pages sticky - identify common patterns\n";
            $prompt .= "3. Key opportunities to improve user retention\n";
            $prompt .= "4. Notable demographic insights (devices, locations)\n";
            $prompt .= "5. Top 3 specific, actionable recommendations\n";
        }
        
        return $prompt;
    }
    
    /**
     * Export data as CSV
     */
    public function export_data($request) {
        $type = $request->get_param('type') ?: 'pages';
        $days = intval($request->get_param('days') ?: 30);
        
        $analytics = new SA_Analytics();
        
        switch ($type) {
            case 'pages':
                $data = $analytics->get_page_stickiness($days, 1000);
                break;
            case 'trend':
                $data = $analytics->get_stickiness_trend($days);
                break;
            case 'demographics':
                $data = $analytics->get_demographics($days);
                break;
            default:
                $data = $analytics->get_overview_stats($days);
        }
        
        return new WP_REST_Response([
            'data' => $data,
            'exported_at' => current_time('mysql'),
            'type' => $type,
            'days' => $days,
        ], 200);
    }
    
    /**
     * Generate visitor hash
     */
    private function get_visitor_hash($request) {
        $fingerprint = $request->get_param('fingerprint');
        
        if ($fingerprint) {
            return hash('sha256', $fingerprint . wp_salt());
        }
        
        $ip = $this->get_client_ip();
        $ua = $request->get_header('User-Agent') ?: '';
        
        return hash('sha256', $ip . $ua . wp_salt());
    }
    
    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }
}
