<?php
/**
 * Database class for Stickiness Analytics
 * Handles table creation and data management
 */

if (!defined('ABSPATH')) {
    exit;
}

class SA_Database {
    
    private $visitors_table;
    private $pageviews_table;
    private $daily_stats_table;
    private $page_stats_table;
    
    public function __construct() {
        global $wpdb;
        $this->visitors_table = $wpdb->prefix . 'sa_visitors';
        $this->pageviews_table = $wpdb->prefix . 'sa_pageviews';
        $this->daily_stats_table = $wpdb->prefix . 'sa_daily_stats';
        $this->page_stats_table = $wpdb->prefix . 'sa_page_stats';
    }
    
    /**
     * Create all required tables
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Visitors table - stores unique visitor profiles
        $sql_visitors = "CREATE TABLE {$this->visitors_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            visitor_hash varchar(64) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            first_visit datetime NOT NULL,
            last_visit datetime NOT NULL,
            total_visits int(11) NOT NULL DEFAULT 1,
            total_pageviews int(11) NOT NULL DEFAULT 0,
            country varchar(2) DEFAULT NULL,
            region varchar(100) DEFAULT NULL,
            city varchar(100) DEFAULT NULL,
            device_type varchar(20) DEFAULT NULL,
            browser varchar(50) DEFAULT NULL,
            browser_version varchar(20) DEFAULT NULL,
            os varchar(50) DEFAULT NULL,
            os_version varchar(20) DEFAULT NULL,
            screen_resolution varchar(20) DEFAULT NULL,
            language varchar(10) DEFAULT NULL,
            timezone varchar(50) DEFAULT NULL,
            is_mobile tinyint(1) DEFAULT 0,
            is_tablet tinyint(1) DEFAULT 0,
            is_desktop tinyint(1) DEFAULT 0,
            referrer_domain varchar(255) DEFAULT NULL,
            referrer_type varchar(50) DEFAULT NULL,
            utm_source varchar(100) DEFAULT NULL,
            utm_medium varchar(100) DEFAULT NULL,
            utm_campaign varchar(100) DEFAULT NULL,
            gclid varchar(255) DEFAULT NULL,
            fbclid varchar(255) DEFAULT NULL,
            msclkid varchar(255) DEFAULT NULL,
            ttclid varchar(255) DEFAULT NULL,
            ad_platform varchar(50) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY visitor_hash (visitor_hash),
            KEY user_id (user_id),
            KEY last_visit (last_visit),
            KEY country (country),
            KEY device_type (device_type)
        ) $charset_collate;";
        
        // Pageviews table - stores individual page visits
        $sql_pageviews = "CREATE TABLE {$this->pageviews_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            visitor_id bigint(20) unsigned NOT NULL,
            page_id bigint(20) unsigned DEFAULT NULL,
            page_url varchar(500) NOT NULL,
            page_title varchar(255) DEFAULT NULL,
            post_type varchar(50) DEFAULT NULL,
            visit_date date NOT NULL,
            visit_time datetime NOT NULL,
            time_on_page int(11) DEFAULT NULL,
            scroll_depth int(3) DEFAULT NULL,
            is_bounce tinyint(1) DEFAULT 1,
            referrer_url varchar(500) DEFAULT NULL,
            entry_page tinyint(1) DEFAULT 0,
            exit_page tinyint(1) DEFAULT 0,
            session_id varchar(64) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY visitor_id (visitor_id),
            KEY page_id (page_id),
            KEY visit_date (visit_date),
            KEY visit_time (visit_time),
            KEY post_type (post_type),
            KEY session_id (session_id)
        ) $charset_collate;";
        
        // Daily stats table - pre-aggregated daily statistics
        $sql_daily_stats = "CREATE TABLE {$this->daily_stats_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            stat_date date NOT NULL,
            dau int(11) NOT NULL DEFAULT 0,
            new_visitors int(11) NOT NULL DEFAULT 0,
            returning_visitors int(11) NOT NULL DEFAULT 0,
            total_pageviews int(11) NOT NULL DEFAULT 0,
            avg_session_duration decimal(10,2) DEFAULT NULL,
            avg_pages_per_session decimal(5,2) DEFAULT NULL,
            bounce_rate decimal(5,2) DEFAULT NULL,
            desktop_users int(11) DEFAULT 0,
            mobile_users int(11) DEFAULT 0,
            tablet_users int(11) DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY stat_date (stat_date)
        ) $charset_collate;";
        
        // Page stats table - per-page analytics
        $sql_page_stats = "CREATE TABLE {$this->page_stats_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            page_id bigint(20) unsigned DEFAULT NULL,
            page_url varchar(500) NOT NULL,
            page_title varchar(255) DEFAULT NULL,
            stat_date date NOT NULL,
            unique_visitors int(11) NOT NULL DEFAULT 0,
            total_views int(11) NOT NULL DEFAULT 0,
            return_visitors int(11) NOT NULL DEFAULT 0,
            avg_time_on_page decimal(10,2) DEFAULT NULL,
            avg_scroll_depth decimal(5,2) DEFAULT NULL,
            bounce_rate decimal(5,2) DEFAULT NULL,
            entry_rate decimal(5,2) DEFAULT NULL,
            exit_rate decimal(5,2) DEFAULT NULL,
            stickiness_score decimal(5,4) DEFAULT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY page_date (page_id, stat_date),
            KEY page_id (page_id),
            KEY stat_date (stat_date),
            KEY stickiness_score (stickiness_score)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($sql_visitors);
        dbDelta($sql_pageviews);
        dbDelta($sql_daily_stats);
        dbDelta($sql_page_stats);
        
        update_option('sa_db_version', SA_DB_VERSION);
    }
    
    /**
     * Get table names
     */
    public function get_visitors_table() {
        return $this->visitors_table;
    }
    
    public function get_pageviews_table() {
        return $this->pageviews_table;
    }
    
    public function get_daily_stats_table() {
        return $this->daily_stats_table;
    }
    
    public function get_page_stats_table() {
        return $this->page_stats_table;
    }
    
    /**
     * Clean up old data based on retention settings
     */
    public function cleanup_old_data() {
        global $wpdb;
        
        $retention_days = get_option('sa_data_retention_days', 90);
        $cutoff_date = date('Y-m-d', strtotime("-{$retention_days} days"));
        
        // Delete old pageviews
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->pageviews_table} WHERE visit_date < %s",
            $cutoff_date
        ));
        
        // Delete old daily stats
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->daily_stats_table} WHERE stat_date < %s",
            $cutoff_date
        ));
        
        // Delete old page stats
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->page_stats_table} WHERE stat_date < %s",
            $cutoff_date
        ));
        
        // Clean up visitors with no recent activity
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->visitors_table} WHERE last_visit < %s",
            $cutoff_date
        ));
    }
    
    /**
     * Get or create visitor record
     */
    public function get_or_create_visitor($visitor_hash, $visitor_data = []) {
        global $wpdb;
        
        $visitor = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->visitors_table} WHERE visitor_hash = %s",
            $visitor_hash
        ));
        
        if ($visitor) {
            // Update last visit and increment visit count
            $wpdb->update(
                $this->visitors_table,
                [
                    'last_visit' => current_time('mysql'),
                    'total_visits' => $visitor->total_visits + 1,
                ],
                ['id' => $visitor->id]
            );
            return $visitor->id;
        }
        
        // Create new visitor
        $insert_data = array_merge([
            'visitor_hash' => $visitor_hash,
            'first_visit' => current_time('mysql'),
            'last_visit' => current_time('mysql'),
            'total_visits' => 1,
        ], $visitor_data);
        
        $wpdb->insert($this->visitors_table, $insert_data);
        
        return $wpdb->insert_id;
    }
    
    /**
     * Record a pageview
     */
    public function record_pageview($visitor_id, $pageview_data) {
        global $wpdb;
        
        $data = array_merge([
            'visitor_id' => $visitor_id,
            'visit_date' => current_time('Y-m-d'),
            'visit_time' => current_time('mysql'),
        ], $pageview_data);
        
        $wpdb->insert($this->pageviews_table, $data);
        
        // Update visitor pageview count
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->visitors_table} 
             SET total_pageviews = total_pageviews + 1 
             WHERE id = %d",
            $visitor_id
        ));
        
        return $wpdb->insert_id;
    }
}
