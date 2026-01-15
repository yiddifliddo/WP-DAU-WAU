<?php
/**
 * Demographics class for Stickiness Analytics
 * Handles detailed demographic analysis and segmentation
 */

if (!defined('ABSPATH')) {
    exit;
}

class SA_Demographics {
    
    private $database;
    
    public function __construct() {
        $this->database = new SA_Database();
    }
    
    /**
     * Get stickiness by demographic segment
     */
    public function get_stickiness_by_segment($segment_type, $days = 30) {
        global $wpdb;
        
        $visitors_table = $this->database->get_visitors_table();
        $pageviews_table = $this->database->get_pageviews_table();
        
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = current_time('Y-m-d');
        
        // Map segment type to column
        $column_map = [
            'device' => 'device_type',
            'browser' => 'browser',
            'country' => 'country',
            'os' => 'os',
            'referrer' => 'referrer_type',
        ];
        
        if (!isset($column_map[$segment_type])) {
            return [];
        }
        
        $column = $column_map[$segment_type];
        
        // Calculate stickiness for each segment
        $query = $wpdb->prepare(
            "SELECT 
                v.{$column} as segment,
                COUNT(DISTINCT pv.visitor_id) as unique_visitors,
                COUNT(DISTINCT CASE WHEN v.total_visits > 1 THEN pv.visitor_id END) as return_visitors,
                COUNT(*) as total_pageviews,
                COUNT(DISTINCT pv.visit_date) as active_days,
                AVG(pv.time_on_page) as avg_time_on_page,
                (COUNT(DISTINCT CASE WHEN v.total_visits > 1 THEN pv.visitor_id END) / COUNT(DISTINCT pv.visitor_id)) * 100 as stickiness
             FROM {$pageviews_table} pv
             JOIN {$visitors_table} v ON pv.visitor_id = v.id
             WHERE pv.visit_date BETWEEN %s AND %s
             AND v.{$column} IS NOT NULL AND v.{$column} != ''
             GROUP BY v.{$column}
             HAVING unique_visitors >= 3
             ORDER BY stickiness DESC",
            $start_date,
            $end_date
        );
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        foreach ($results as &$row) {
            $row['stickiness'] = round(floatval($row['stickiness']), 2);
            $row['avg_time_on_page'] = round(floatval($row['avg_time_on_page']), 0);
            $row['pages_per_visitor'] = round($row['total_pageviews'] / max(1, $row['unique_visitors']), 2);
        }
        
        return $results;
    }
    
    /**
     * Get page preferences by demographic
     */
    public function get_page_preferences_by_segment($segment_type, $segment_value, $days = 30, $limit = 10) {
        global $wpdb;
        
        $visitors_table = $this->database->get_visitors_table();
        $pageviews_table = $this->database->get_pageviews_table();
        
        $column_map = [
            'device' => 'device_type',
            'browser' => 'browser',
            'country' => 'country',
            'os' => 'os',
            'referrer' => 'referrer_type',
        ];
        
        if (!isset($column_map[$segment_type])) {
            return [];
        }
        
        $column = $column_map[$segment_type];
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = current_time('Y-m-d');
        
        $query = $wpdb->prepare(
            "SELECT 
                pv.page_id,
                pv.page_url,
                pv.page_title,
                COUNT(DISTINCT pv.visitor_id) as visitors,
                COUNT(*) as views,
                AVG(pv.time_on_page) as avg_time,
                AVG(pv.scroll_depth) as avg_scroll
             FROM {$pageviews_table} pv
             JOIN {$visitors_table} v ON pv.visitor_id = v.id
             WHERE pv.visit_date BETWEEN %s AND %s
             AND v.{$column} = %s
             AND pv.page_id IS NOT NULL
             GROUP BY pv.page_id, pv.page_url, pv.page_title
             ORDER BY visitors DESC
             LIMIT %d",
            $start_date,
            $end_date,
            $segment_value,
            $limit
        );
        
        return $wpdb->get_results($query, ARRAY_A);
    }
    
    /**
     * Get visitor journey patterns
     */
    public function get_visitor_journeys($days = 7, $limit = 100) {
        global $wpdb;
        
        $pageviews_table = $this->database->get_pageviews_table();
        
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        
        // Get sessions with multiple pages
        $query = $wpdb->prepare(
            "SELECT 
                session_id,
                GROUP_CONCAT(page_title ORDER BY visit_time SEPARATOR ' → ') as journey,
                COUNT(*) as pages_visited,
                MIN(visit_time) as session_start,
                MAX(visit_time) as session_end,
                SUM(COALESCE(time_on_page, 0)) as total_time
             FROM {$pageviews_table}
             WHERE visit_date >= %s
             AND session_id IS NOT NULL AND session_id != ''
             GROUP BY session_id
             HAVING pages_visited >= 2
             ORDER BY pages_visited DESC, total_time DESC
             LIMIT %d",
            $start_date,
            $limit
        );
        
        return $wpdb->get_results($query, ARRAY_A);
    }
    
    /**
     * Get common entry and exit pages
     */
    public function get_entry_exit_pages($days = 30, $limit = 10) {
        global $wpdb;
        
        $pageviews_table = $this->database->get_pageviews_table();
        
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = current_time('Y-m-d');
        
        // Entry pages
        $entry_pages = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                page_id,
                page_url,
                page_title,
                COUNT(*) as entry_count,
                AVG(CASE WHEN is_bounce = 1 THEN 100 ELSE 0 END) as bounce_rate
             FROM {$pageviews_table}
             WHERE visit_date BETWEEN %s AND %s
             AND entry_page = 1
             AND page_id IS NOT NULL
             GROUP BY page_id, page_url, page_title
             ORDER BY entry_count DESC
             LIMIT %d",
            $start_date,
            $end_date,
            $limit
        ), ARRAY_A);
        
        // Exit pages
        $exit_pages = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                page_id,
                page_url,
                page_title,
                COUNT(*) as exit_count
             FROM {$pageviews_table}
             WHERE visit_date BETWEEN %s AND %s
             AND exit_page = 1
             AND page_id IS NOT NULL
             GROUP BY page_id, page_url, page_title
             ORDER BY exit_count DESC
             LIMIT %d",
            $start_date,
            $end_date,
            $limit
        ), ARRAY_A);
        
        return [
            'entry_pages' => $entry_pages,
            'exit_pages' => $exit_pages,
        ];
    }
    
    /**
     * Get time-based engagement patterns
     */
    public function get_engagement_by_time($days = 30) {
        global $wpdb;
        
        $pageviews_table = $this->database->get_pageviews_table();
        
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = current_time('Y-m-d');
        
        // By hour of day
        $by_hour = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                HOUR(visit_time) as hour,
                COUNT(DISTINCT visitor_id) as visitors,
                COUNT(*) as pageviews,
                AVG(time_on_page) as avg_time
             FROM {$pageviews_table}
             WHERE visit_date BETWEEN %s AND %s
             GROUP BY HOUR(visit_time)
             ORDER BY hour",
            $start_date,
            $end_date
        ), ARRAY_A);
        
        // By day of week
        $by_day = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                DAYOFWEEK(visit_date) as day_num,
                DAYNAME(visit_date) as day_name,
                COUNT(DISTINCT visitor_id) as visitors,
                COUNT(*) as pageviews
             FROM {$pageviews_table}
             WHERE visit_date BETWEEN %s AND %s
             GROUP BY DAYOFWEEK(visit_date), DAYNAME(visit_date)
             ORDER BY day_num",
            $start_date,
            $end_date
        ), ARRAY_A);
        
        return [
            'by_hour' => $by_hour,
            'by_day' => $by_day,
        ];
    }
    
    /**
     * Compare stickiness between new and returning visitors
     */
    public function get_new_vs_returning_engagement($days = 30) {
        global $wpdb;
        
        $visitors_table = $this->database->get_visitors_table();
        $pageviews_table = $this->database->get_pageviews_table();
        
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = current_time('Y-m-d');
        
        $query = $wpdb->prepare(
            "SELECT 
                CASE WHEN v.total_visits = 1 THEN 'new' ELSE 'returning' END as visitor_type,
                COUNT(DISTINCT pv.visitor_id) as visitors,
                COUNT(*) as pageviews,
                AVG(pv.time_on_page) as avg_time_on_page,
                AVG(pv.scroll_depth) as avg_scroll_depth,
                SUM(CASE WHEN pv.is_bounce = 1 THEN 1 ELSE 0 END) / COUNT(*) * 100 as bounce_rate
             FROM {$pageviews_table} pv
             JOIN {$visitors_table} v ON pv.visitor_id = v.id
             WHERE pv.visit_date BETWEEN %s AND %s
             GROUP BY CASE WHEN v.total_visits = 1 THEN 'new' ELSE 'returning' END",
            $start_date,
            $end_date
        );
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        $formatted = [];
        foreach ($results as $row) {
            $formatted[$row['visitor_type']] = [
                'visitors' => intval($row['visitors']),
                'pageviews' => intval($row['pageviews']),
                'pages_per_visitor' => round($row['pageviews'] / max(1, $row['visitors']), 2),
                'avg_time_on_page' => round(floatval($row['avg_time_on_page']), 0),
                'avg_scroll_depth' => round(floatval($row['avg_scroll_depth']), 0),
                'bounce_rate' => round(floatval($row['bounce_rate']), 1),
            ];
        }
        
        return $formatted;
    }
    
    /**
     * Get geographic heatmap data
     */
    public function get_geographic_distribution($days = 30) {
        global $wpdb;
        
        $visitors_table = $this->database->get_visitors_table();
        $pageviews_table = $this->database->get_pageviews_table();
        
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = current_time('Y-m-d');
        
        // By country with stickiness
        $by_country = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                v.country,
                COUNT(DISTINCT pv.visitor_id) as visitors,
                COUNT(DISTINCT CASE WHEN v.total_visits > 1 THEN pv.visitor_id END) as returning_visitors,
                COUNT(*) as pageviews,
                (COUNT(DISTINCT CASE WHEN v.total_visits > 1 THEN pv.visitor_id END) / COUNT(DISTINCT pv.visitor_id)) * 100 as stickiness
             FROM {$pageviews_table} pv
             JOIN {$visitors_table} v ON pv.visitor_id = v.id
             WHERE pv.visit_date BETWEEN %s AND %s
             AND v.country IS NOT NULL
             GROUP BY v.country
             ORDER BY visitors DESC",
            $start_date,
            $end_date
        ), ARRAY_A);
        
        // By city (top 20)
        $by_city = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                v.city,
                v.country,
                v.region,
                COUNT(DISTINCT pv.visitor_id) as visitors,
                COUNT(*) as pageviews
             FROM {$pageviews_table} pv
             JOIN {$visitors_table} v ON pv.visitor_id = v.id
             WHERE pv.visit_date BETWEEN %s AND %s
             AND v.city IS NOT NULL
             GROUP BY v.city, v.country, v.region
             ORDER BY visitors DESC
             LIMIT 20",
            $start_date,
            $end_date
        ), ARRAY_A);
        
        return [
            'by_country' => $by_country,
            'by_city' => $by_city,
        ];
    }
}
