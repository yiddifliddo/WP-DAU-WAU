<?php
/**
 * Plugin Name: Stickiness Analytics - DAU/MAU Tracker
 * Plugin URI: https://danlee.nyc
 * Description: Track Daily Active Users, Monthly Active Users, page-level stickiness, and user demographics with AI-powered insights.
 * Version: 1.1.0
 * Author: Dan Lee
 * License: GPL v2 or later
 * Text Domain: stickiness-analytics
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SA_VERSION', '1.1.0');
define('SA_PLUGIN_FILE', __FILE__);
define('SA_PLUGIN_DIR', plugin_dir_path(SA_PLUGIN_FILE));
define('SA_PLUGIN_URL', plugin_dir_url(SA_PLUGIN_FILE));
define('SA_DB_VERSION', '1.1.0');

// Include required files - using absolute paths
$sa_includes = array(
    SA_PLUGIN_DIR . 'includes/class-sa-database.php',
    SA_PLUGIN_DIR . 'includes/class-sa-tracker.php',
    SA_PLUGIN_DIR . 'includes/class-sa-analytics.php',
    SA_PLUGIN_DIR . 'includes/class-sa-demographics.php',
    SA_PLUGIN_DIR . 'includes/class-sa-admin.php',
    SA_PLUGIN_DIR . 'includes/class-sa-rest-api.php',
);

foreach ($sa_includes as $sa_include_file) {
    if (file_exists($sa_include_file)) {
        require_once $sa_include_file;
    } else {
        // Log error for debugging
        error_log('Stickiness Analytics: Missing file - ' . $sa_include_file);
    }
}

/**
 * Main plugin class
 */
class Stickiness_Analytics {
    
    private static $instance = null;
    
    public $database;
    public $tracker;
    public $analytics;
    public $demographics;
    public $admin;
    public $rest_api;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        add_action('plugins_loaded', [$this, 'init']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
    }
    
    public function init() {
        // Check if database update is needed
        $this->maybe_update_database();
        
        $this->database = new SA_Database();
        $this->tracker = new SA_Tracker();
        $this->analytics = new SA_Analytics();
        $this->demographics = new SA_Demographics();
        $this->admin = new SA_Admin();
        $this->rest_api = new SA_REST_API();
    }
    
    /**
     * Check and apply database updates when version changes
     */
    private function maybe_update_database() {
        $current_db_version = get_option('sa_db_version', '1.0.0');
        
        if (version_compare($current_db_version, SA_DB_VERSION, '<')) {
            $database = new SA_Database();
            $database->create_tables();
            update_option('sa_db_version', SA_DB_VERSION);
        }
    }
    
    public function activate() {
        $database = new SA_Database();
        $database->create_tables();
        update_option('sa_db_version', SA_DB_VERSION);

        // Set default options
        add_option('sa_tracking_enabled', 1);
        add_option('sa_exclude_admins', 1);
        add_option('sa_cookie_duration', 365);
        add_option('sa_data_retention_days', 90);
        add_option('sa_ai_provider', 'openai');
        add_option('sa_openai_api_key', '');
        add_option('sa_mistral_api_key', '');
        add_option('sa_mistral_agent_id', '');
        
        // Schedule cleanup cron
        if (!wp_next_scheduled('sa_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'sa_daily_cleanup');
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook('sa_daily_cleanup');
    }
    
    public function enqueue_frontend_scripts() {
        if (!get_option('sa_tracking_enabled', 1)) {
            return;
        }
        
        // Skip tracking for admins if configured
        if (get_option('sa_exclude_admins', 1) && current_user_can('manage_options')) {
            return;
        }
        
        wp_enqueue_script(
            'sa-frontend',
            SA_PLUGIN_URL . 'assets/js/sa-frontend.js',
            [],
            SA_VERSION,
            true
        );
        
        // Use post-specific data only on singular pages; handle archives/404s differently
        if (is_singular()) {
            $page_id    = get_the_ID();
            $page_url   = get_permalink();
            $page_title = get_the_title();
            $post_type  = get_post_type();
        } else {
            $page_id    = 0;
            $page_url   = home_url(add_query_arg(null, null));
            $page_title = wp_get_document_title();
            $post_type  = is_front_page() ? 'front_page'
                        : (is_home()      ? 'blog_index'
                        : (is_category()  ? 'category'
                        : (is_tag()       ? 'tag'
                        : (is_archive()   ? 'archive'
                        : (is_search()    ? 'search'
                        : (is_404()       ? '404' : 'other'))))));
        }

        wp_localize_script('sa-frontend', 'saConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('stickiness-analytics/v1/'),
            'nonce' => wp_create_nonce('sa_nonce'),
            'pageId' => $page_id,
            'pageUrl' => $page_url,
            'pageTitle' => $page_title,
            'postType' => $post_type,
            'cookieDuration' => get_option('sa_cookie_duration', 365),
        ]);
    }
}

// Initialize the plugin
function stickiness_analytics() {
    return Stickiness_Analytics::get_instance();
}

stickiness_analytics();
