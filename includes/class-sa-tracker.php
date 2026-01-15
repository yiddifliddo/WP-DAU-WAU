<?php
/**
 * Tracker class for Stickiness Analytics
 * Handles incoming tracking requests
 */

if (!defined('ABSPATH')) {
    exit;
}

class SA_Tracker {
    
    private $database;
    
    public function __construct() {
        $this->database = new SA_Database();
        
        add_action('wp_ajax_sa_pv', [$this, 'handle_pageview']);
        add_action('wp_ajax_nopriv_sa_pv', [$this, 'handle_pageview']);
        add_action('wp_ajax_sa_eng', [$this, 'handle_engagement']);
        add_action('wp_ajax_nopriv_sa_eng', [$this, 'handle_engagement']);
        add_action('sa_daily_cleanup', [$this, 'daily_cleanup']);
    }
    
    /**
     * Handle incoming pageview tracking request
     */
    public function handle_pageview() {
        check_ajax_referer('sa_nonce', 'nonce');
        
        $visitor_hash = $this->get_visitor_hash();
        $visitor_data = $this->collect_visitor_data();
        
        // Get or create visitor
        $visitor_id = $this->database->get_or_create_visitor($visitor_hash, $visitor_data);
        
        // Record the pageview
        $pageview_data = [
            'page_id' => isset($_POST['page_id']) ? intval($_POST['page_id']) : null,
            'page_url' => isset($_POST['page_url']) ? esc_url_raw($_POST['page_url']) : '',
            'page_title' => isset($_POST['page_title']) ? sanitize_text_field($_POST['page_title']) : '',
            'post_type' => isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : '',
            'referrer_url' => isset($_POST['referrer']) ? esc_url_raw($_POST['referrer']) : '',
            'session_id' => isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '',
            'entry_page' => isset($_POST['entry_page']) ? 1 : 0,
        ];
        
        $pageview_id = $this->database->record_pageview($visitor_id, $pageview_data);
        
        wp_send_json_success([
            'visitor_id' => $visitor_id,
            'pageview_id' => $pageview_id,
            'visitor_hash' => $visitor_hash,
        ]);
    }
    
    /**
     * Handle engagement tracking (time on page, scroll depth, etc.)
     */
    public function handle_engagement() {
        check_ajax_referer('sa_nonce', 'nonce');
        
        global $wpdb;
        $pageviews_table = $this->database->get_pageviews_table();
        
        $pageview_id = isset($_POST['pageview_id']) ? intval($_POST['pageview_id']) : 0;
        
        if (!$pageview_id) {
            wp_send_json_error('Invalid pageview ID');
        }
        
        $update_data = [];
        
        if (isset($_POST['time_on_page'])) {
            $update_data['time_on_page'] = intval($_POST['time_on_page']);
        }
        
        if (isset($_POST['scroll_depth'])) {
            $update_data['scroll_depth'] = min(100, max(0, intval($_POST['scroll_depth'])));
        }
        
        if (isset($_POST['is_bounce'])) {
            $update_data['is_bounce'] = intval($_POST['is_bounce']) ? 1 : 0;
        }
        
        if (isset($_POST['exit_page'])) {
            $update_data['exit_page'] = 1;
        }
        
        if (!empty($update_data)) {
            $wpdb->update(
                $pageviews_table,
                $update_data,
                ['id' => $pageview_id]
            );
        }
        
        wp_send_json_success();
    }
    
    /**
     * Generate a unique hash for the visitor
     */
    private function get_visitor_hash() {
        // Check for existing cookie
        if (isset($_COOKIE['sa_uid'])) {
            return sanitize_text_field($_COOKIE['sa_uid']);
        }
        
        // Check for logged-in user
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            return hash('sha256', 'user_' . $user_id . wp_salt());
        }
        
        // Generate fingerprint-based hash from POST data
        if (isset($_POST['fingerprint'])) {
            return hash('sha256', sanitize_text_field($_POST['fingerprint']) . wp_salt());
        }
        
        // Fallback to IP + User Agent
        $ip = $this->get_client_ip();
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        
        return hash('sha256', $ip . $ua . wp_salt());
    }
    
    /**
     * Collect visitor demographic data
     */
    private function collect_visitor_data() {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $parsed_ua = $this->parse_user_agent($ua);
        
        $data = [
            'user_id' => is_user_logged_in() ? get_current_user_id() : null,
            'device_type' => $parsed_ua['device_type'],
            'browser' => $parsed_ua['browser'],
            'browser_version' => $parsed_ua['browser_version'],
            'os' => $parsed_ua['os'],
            'os_version' => $parsed_ua['os_version'],
            'is_mobile' => $parsed_ua['is_mobile'] ? 1 : 0,
            'is_tablet' => $parsed_ua['is_tablet'] ? 1 : 0,
            'is_desktop' => $parsed_ua['is_desktop'] ? 1 : 0,
        ];
        
        // Get geolocation data from IP
        $ip = $this->get_client_ip();
        $geo = $this->get_geolocation($ip);
        
        if ($geo) {
            $data['country'] = $geo['country'];
            $data['region'] = $geo['region'];
            $data['city'] = $geo['city'];
            $data['timezone'] = $geo['timezone'];
        }
        
        // Get data from POST (collected by JavaScript)
        if (isset($_POST['screen_resolution'])) {
            $data['screen_resolution'] = sanitize_text_field($_POST['screen_resolution']);
        }
        
        if (isset($_POST['language'])) {
            $data['language'] = sanitize_text_field($_POST['language']);
        }
        
        if (isset($_POST['timezone'])) {
            $data['timezone'] = sanitize_text_field($_POST['timezone']);
        }
        
        // Parse referrer
        $referrer = isset($_POST['referrer']) ? esc_url_raw($_POST['referrer']) : '';
        if ($referrer) {
            $parsed = parse_url($referrer);
            $data['referrer_domain'] = isset($parsed['host']) ? $parsed['host'] : '';
            $data['referrer_type'] = $this->classify_referrer($data['referrer_domain']);
        }
        
        // UTM parameters
        if (isset($_POST['utm_source'])) {
            $data['utm_source'] = sanitize_text_field($_POST['utm_source']);
        }
        if (isset($_POST['utm_medium'])) {
            $data['utm_medium'] = sanitize_text_field($_POST['utm_medium']);
        }
        if (isset($_POST['utm_campaign'])) {
            $data['utm_campaign'] = sanitize_text_field($_POST['utm_campaign']);
        }
        
        // Ad platform click IDs (Google, Facebook, Microsoft, TikTok)
        if (!empty($_POST['gclid'])) {
            $data['gclid'] = sanitize_text_field($_POST['gclid']);
        }
        if (!empty($_POST['fbclid'])) {
            $data['fbclid'] = sanitize_text_field($_POST['fbclid']);
        }
        if (!empty($_POST['msclkid'])) {
            $data['msclkid'] = sanitize_text_field($_POST['msclkid']);
        }
        if (!empty($_POST['ttclid'])) {
            $data['ttclid'] = sanitize_text_field($_POST['ttclid']);
        }
        
        // Ad platform detection (auto-detected by frontend, or detected here from click IDs)
        if (!empty($_POST['ad_platform'])) {
            $data['ad_platform'] = sanitize_text_field($_POST['ad_platform']);
        } elseif (!empty($data['gclid'])) {
            $data['ad_platform'] = 'google_ads';
        } elseif (!empty($data['fbclid'])) {
            $data['ad_platform'] = 'facebook_ads';
        } elseif (!empty($data['msclkid'])) {
            $data['ad_platform'] = 'microsoft_ads';
        } elseif (!empty($data['ttclid'])) {
            $data['ad_platform'] = 'tiktok_ads';
        }
        
        // Auto-fill UTM source/medium from click IDs if not provided
        if (!empty($data['ad_platform']) && empty($data['utm_source'])) {
            switch ($data['ad_platform']) {
                case 'google_ads':
                    $data['utm_source'] = 'google';
                    $data['utm_medium'] = $data['utm_medium'] ?: 'cpc';
                    break;
                case 'facebook_ads':
                    $data['utm_source'] = 'facebook';
                    $data['utm_medium'] = $data['utm_medium'] ?: 'paid_social';
                    break;
                case 'microsoft_ads':
                    $data['utm_source'] = 'bing';
                    $data['utm_medium'] = $data['utm_medium'] ?: 'cpc';
                    break;
                case 'tiktok_ads':
                    $data['utm_source'] = 'tiktok';
                    $data['utm_medium'] = $data['utm_medium'] ?: 'paid_social';
                    break;
            }
        }
        
        return $data;
    }
    
    /**
     * Parse user agent string
     */
    private function parse_user_agent($ua) {
        $result = [
            'device_type' => 'desktop',
            'browser' => 'Unknown',
            'browser_version' => '',
            'os' => 'Unknown',
            'os_version' => '',
            'is_mobile' => false,
            'is_tablet' => false,
            'is_desktop' => true,
        ];
        
        // Detect device type
        if (preg_match('/Mobile|Android.*Mobile|iPhone|iPod/i', $ua)) {
            $result['device_type'] = 'mobile';
            $result['is_mobile'] = true;
            $result['is_desktop'] = false;
        } elseif (preg_match('/iPad|Android(?!.*Mobile)|Tablet/i', $ua)) {
            $result['device_type'] = 'tablet';
            $result['is_tablet'] = true;
            $result['is_desktop'] = false;
        }
        
        // Detect browser
        if (preg_match('/Edge\/(\d+)/i', $ua, $matches)) {
            $result['browser'] = 'Edge';
            $result['browser_version'] = $matches[1];
        } elseif (preg_match('/Edg\/(\d+)/i', $ua, $matches)) {
            $result['browser'] = 'Edge';
            $result['browser_version'] = $matches[1];
        } elseif (preg_match('/Chrome\/(\d+)/i', $ua, $matches)) {
            $result['browser'] = 'Chrome';
            $result['browser_version'] = $matches[1];
        } elseif (preg_match('/Firefox\/(\d+)/i', $ua, $matches)) {
            $result['browser'] = 'Firefox';
            $result['browser_version'] = $matches[1];
        } elseif (preg_match('/Safari\/(\d+)/i', $ua, $matches) && !preg_match('/Chrome/i', $ua)) {
            $result['browser'] = 'Safari';
            if (preg_match('/Version\/(\d+)/i', $ua, $v)) {
                $result['browser_version'] = $v[1];
            }
        } elseif (preg_match('/MSIE (\d+)/i', $ua, $matches) || preg_match('/Trident.*rv:(\d+)/i', $ua, $matches)) {
            $result['browser'] = 'Internet Explorer';
            $result['browser_version'] = $matches[1];
        }
        
        // Detect OS
        if (preg_match('/Windows NT (\d+\.\d+)/i', $ua, $matches)) {
            $result['os'] = 'Windows';
            $version_map = [
                '10.0' => '10/11',
                '6.3' => '8.1',
                '6.2' => '8',
                '6.1' => '7',
            ];
            $result['os_version'] = isset($version_map[$matches[1]]) ? $version_map[$matches[1]] : $matches[1];
        } elseif (preg_match('/Mac OS X (\d+[._]\d+)/i', $ua, $matches)) {
            $result['os'] = 'macOS';
            $result['os_version'] = str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/Android (\d+)/i', $ua, $matches)) {
            $result['os'] = 'Android';
            $result['os_version'] = $matches[1];
        } elseif (preg_match('/iOS (\d+)/i', $ua, $matches) || preg_match('/iPhone OS (\d+)/i', $ua, $matches)) {
            $result['os'] = 'iOS';
            $result['os_version'] = $matches[1];
        } elseif (preg_match('/Linux/i', $ua)) {
            $result['os'] = 'Linux';
        }
        
        return $result;
    }
    
    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip_headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];
        
        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Handle comma-separated list
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
    
    /**
     * Get geolocation from IP using free API
     */
    private function get_geolocation($ip) {
        // Skip for localhost
        if (in_array($ip, ['127.0.0.1', '::1', '0.0.0.0'])) {
            return null;
        }
        
        // Check transient cache first
        $cache_key = 'sa_geo_' . md5($ip);
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        // Use ip-api.com (free, 45 requests/minute)
        $response = wp_remote_get("http://ip-api.com/json/{$ip}?fields=status,country,countryCode,region,regionName,city,timezone", [
            'timeout' => 5,
        ]);
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || $data['status'] !== 'success') {
            return null;
        }
        
        $geo = [
            'country' => $data['countryCode'],
            'region' => $data['regionName'],
            'city' => $data['city'],
            'timezone' => $data['timezone'],
        ];
        
        // Cache for 24 hours
        set_transient($cache_key, $geo, DAY_IN_SECONDS);
        
        return $geo;
    }
    
    /**
     * Classify referrer type
     */
    private function classify_referrer($domain) {
        if (empty($domain)) {
            return 'direct';
        }
        
        // Check if internal
        $site_domain = parse_url(home_url(), PHP_URL_HOST);
        if ($domain === $site_domain) {
            return 'internal';
        }
        
        // Search engines
        $search_engines = ['google', 'bing', 'yahoo', 'duckduckgo', 'baidu', 'yandex'];
        foreach ($search_engines as $engine) {
            if (stripos($domain, $engine) !== false) {
                return 'organic_search';
            }
        }
        
        // Social networks
        $social_networks = ['facebook', 'twitter', 'linkedin', 'instagram', 'pinterest', 'reddit', 'youtube', 'tiktok'];
        foreach ($social_networks as $network) {
            if (stripos($domain, $network) !== false) {
                return 'social';
            }
        }
        
        return 'referral';
    }
    
    /**
     * Daily cleanup task
     */
    public function daily_cleanup() {
        $this->database->cleanup_old_data();
        
        // Trigger stats aggregation
        $analytics = new SA_Analytics();
        $analytics->aggregate_daily_stats(date('Y-m-d', strtotime('-1 day')));
    }
}
