<?php
/**
 * Analytics class for Stickiness Analytics
 * Handles DAU/MAU calculations and stickiness metrics
 */

if (!defined('ABSPATH')) {
    exit;
}

class SA_Analytics {
    
    private $database;
    
    public function __construct() {
        $this->database = new SA_Database();
    }
    
    /**
     * Get Daily Active Users for a specific date
     */
    public function get_dau($date = null) {
        global $wpdb;
        
        $date = $date ?: current_time('Y-m-d');
        $visitors_table = $this->database->get_visitors_table();
        $pageviews_table = $this->database->get_pageviews_table();
        
        $dau = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT visitor_id) 
             FROM {$pageviews_table} 
             WHERE visit_date = %s",
            $date
        ));
        
        return intval($dau);
    }
    
    /**
     * Get Monthly Active Users for a specific month
     */
    public function get_mau($year = null, $month = null) {
        global $wpdb;
        
        $year = $year ?: current_time('Y');
        $month = $month ?: current_time('m');
        
        $pageviews_table = $this->database->get_pageviews_table();
        
        $start_date = "{$year}-{$month}-01";
        $end_date = date('Y-m-t', strtotime($start_date));
        
        $mau = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT visitor_id) 
             FROM {$pageviews_table} 
             WHERE visit_date BETWEEN %s AND %s",
            $start_date,
            $end_date
        ));
        
        return intval($mau);
    }
    
    /**
     * Get rolling MAU (last 30 days)
     */
    public function get_rolling_mau($end_date = null) {
        global $wpdb;
        
        $end_date = $end_date ?: current_time('Y-m-d');
        $start_date = date('Y-m-d', strtotime('-29 days', strtotime($end_date)));
        
        $pageviews_table = $this->database->get_pageviews_table();
        
        $mau = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT visitor_id) 
             FROM {$pageviews_table} 
             WHERE visit_date BETWEEN %s AND %s",
            $start_date,
            $end_date
        ));
        
        return intval($mau);
    }
    
    /**
     * Calculate DAU/MAU stickiness ratio
     */
    public function get_stickiness_ratio($date = null) {
        $date = $date ?: current_time('Y-m-d');
        
        $dau = $this->get_dau($date);
        $mau = $this->get_rolling_mau($date);
        
        if ($mau === 0) {
            return 0;
        }
        
        return round(($dau / $mau) * 100, 2);
    }
    
    /**
     * Get DAU/MAU trend over time
     */
    public function get_stickiness_trend($days = 30) {
        global $wpdb;
        
        $pageviews_table = $this->database->get_pageviews_table();
        $results = [];
        
        $end_date = current_time('Y-m-d');
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days", strtotime($end_date)));
            
            $dau = $this->get_dau($date);
            $mau = $this->get_rolling_mau($date);
            $ratio = $mau > 0 ? round(($dau / $mau) * 100, 2) : 0;
            
            $results[] = [
                'date' => $date,
                'dau' => $dau,
                'mau' => $mau,
                'stickiness' => $ratio,
            ];
        }
        
        return $results;
    }
    
    /**
     * Get page-level stickiness metrics
     * Returns pages ranked by stickiness (return visitor rate)
     */
    public function get_page_stickiness($days = 30, $limit = 20) {
        global $wpdb;
        
        $pageviews_table = $this->database->get_pageviews_table();
        $visitors_table = $this->database->get_visitors_table();
        
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = current_time('Y-m-d');
        
        // Get pages with the highest proportion of returning visitors
        $query = $wpdb->prepare(
            "SELECT 
                pv.page_id,
                pv.page_url,
                pv.page_title,
                pv.post_type,
                COUNT(DISTINCT pv.visitor_id) as unique_visitors,
                COUNT(*) as total_views,
                COUNT(DISTINCT CASE WHEN v.total_visits > 1 THEN pv.visitor_id END) as return_visitors,
                AVG(pv.time_on_page) as avg_time_on_page,
                AVG(pv.scroll_depth) as avg_scroll_depth,
                SUM(CASE WHEN pv.is_bounce = 1 THEN 1 ELSE 0 END) / COUNT(*) * 100 as bounce_rate,
                COUNT(DISTINCT CASE WHEN v.total_visits > 1 THEN pv.visitor_id END) / COUNT(DISTINCT pv.visitor_id) * 100 as stickiness_score,
                COUNT(DISTINCT CASE WHEN v.utm_medium IN ('cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'cpv', 'cpm', 'banner', 'display', 'retargeting', 'paid_social', 'paidsocial') THEN pv.visitor_id END) as paid_visitors,
                (COUNT(DISTINCT CASE WHEN v.utm_medium IN ('cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'cpv', 'cpm', 'banner', 'display', 'retargeting', 'paid_social', 'paidsocial') THEN pv.visitor_id END) / COUNT(DISTINCT pv.visitor_id)) * 100 as paid_traffic_pct
             FROM {$pageviews_table} pv
             JOIN {$visitors_table} v ON pv.visitor_id = v.id
             WHERE pv.visit_date BETWEEN %s AND %s
             AND pv.page_id IS NOT NULL
             GROUP BY pv.page_id, pv.page_url, pv.page_title, pv.post_type
             HAVING unique_visitors >= 5
             ORDER BY stickiness_score DESC, unique_visitors DESC
             LIMIT %d",
            $start_date,
            $end_date,
            $limit
        );
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        // Calculate additional metrics and insights
        foreach ($results as &$page) {
            $page['stickiness_score'] = round(floatval($page['stickiness_score']), 2);
            $page['avg_time_on_page'] = round(floatval($page['avg_time_on_page']), 0);
            $page['avg_scroll_depth'] = round(floatval($page['avg_scroll_depth']), 0);
            $page['bounce_rate'] = round(floatval($page['bounce_rate']), 1);
            $page['views_per_visitor'] = round($page['total_views'] / max(1, $page['unique_visitors']), 2);
            $page['paid_visitors'] = intval($page['paid_visitors']);
            $page['paid_traffic_pct'] = round(floatval($page['paid_traffic_pct']), 1);
            
            // Generate stickiness grade
            $score = $page['stickiness_score'];
            if ($score >= 70) {
                $page['grade'] = 'A';
                $page['grade_label'] = 'Excellent';
            } elseif ($score >= 50) {
                $page['grade'] = 'B';
                $page['grade_label'] = 'Good';
            } elseif ($score >= 30) {
                $page['grade'] = 'C';
                $page['grade_label'] = 'Average';
            } elseif ($score >= 15) {
                $page['grade'] = 'D';
                $page['grade_label'] = 'Below Average';
            } else {
                $page['grade'] = 'F';
                $page['grade_label'] = 'Needs Improvement';
            }
        }
        
        return $results;
    }
    
    /**
     * Get visitor retention cohorts
     */
    public function get_retention_cohorts($weeks = 8) {
        global $wpdb;
        
        $visitors_table = $this->database->get_visitors_table();
        $pageviews_table = $this->database->get_pageviews_table();
        
        $cohorts = [];
        
        for ($w = $weeks - 1; $w >= 0; $w--) {
            $cohort_start = date('Y-m-d', strtotime("-{$w} weeks monday"));
            $cohort_end = date('Y-m-d', strtotime($cohort_start . ' +6 days'));
            
            // Get visitors who first visited during this week
            $cohort_visitors = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$visitors_table}
                 WHERE DATE(first_visit) BETWEEN %s AND %s",
                $cohort_start,
                $cohort_end
            ));
            
            $cohort = [
                'week' => $cohort_start,
                'new_visitors' => intval($cohort_visitors),
                'retention' => [],
            ];
            
            // Calculate retention for each subsequent week
            for ($r = 0; $r <= min($w, 7); $r++) {
                $retention_start = date('Y-m-d', strtotime($cohort_start . " +{$r} weeks"));
                $retention_end = date('Y-m-d', strtotime($retention_start . ' +6 days'));
                
                $retained = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT v.id)
                     FROM {$visitors_table} v
                     JOIN {$pageviews_table} pv ON v.id = pv.visitor_id
                     WHERE DATE(v.first_visit) BETWEEN %s AND %s
                     AND pv.visit_date BETWEEN %s AND %s",
                    $cohort_start,
                    $cohort_end,
                    $retention_start,
                    $retention_end
                ));
                
                $retention_rate = $cohort_visitors > 0 
                    ? round(($retained / $cohort_visitors) * 100, 1)
                    : 0;
                
                $cohort['retention']['week_' . $r] = [
                    'visitors' => intval($retained),
                    'rate' => $retention_rate,
                ];
            }
            
            $cohorts[] = $cohort;
        }
        
        return $cohorts;
    }
    
    /**
     * Aggregate daily statistics
     */
    public function aggregate_daily_stats($date) {
        global $wpdb;
        
        $pageviews_table = $this->database->get_pageviews_table();
        $visitors_table = $this->database->get_visitors_table();
        $daily_stats_table = $this->database->get_daily_stats_table();
        
        // Get DAU
        $dau = $this->get_dau($date);
        
        // Get new vs returning visitors
        $visitor_breakdown = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(DISTINCT CASE WHEN DATE(v.first_visit) = %s THEN pv.visitor_id END) as new_visitors,
                COUNT(DISTINCT CASE WHEN DATE(v.first_visit) < %s THEN pv.visitor_id END) as returning_visitors
             FROM {$pageviews_table} pv
             JOIN {$visitors_table} v ON pv.visitor_id = v.id
             WHERE pv.visit_date = %s",
            $date,
            $date,
            $date
        ));
        
        // Get total pageviews
        $total_pageviews = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$pageviews_table} WHERE visit_date = %s",
            $date
        ));
        
        // Get average session duration and pages per session
        $session_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                AVG(session_duration) as avg_session_duration,
                AVG(pages_in_session) as avg_pages_per_session
             FROM (
                SELECT 
                    session_id,
                    SUM(COALESCE(time_on_page, 0)) as session_duration,
                    COUNT(*) as pages_in_session
                FROM {$pageviews_table}
                WHERE visit_date = %s AND session_id IS NOT NULL AND session_id != ''
                GROUP BY session_id
             ) sessions",
            $date
        ));
        
        // Get bounce rate
        $bounce_rate = $wpdb->get_var($wpdb->prepare(
            "SELECT 
                (SUM(CASE WHEN is_bounce = 1 THEN 1 ELSE 0 END) / COUNT(*)) * 100
             FROM {$pageviews_table}
             WHERE visit_date = %s",
            $date
        ));
        
        // Get device breakdown
        $device_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(DISTINCT CASE WHEN v.device_type = 'desktop' THEN pv.visitor_id END) as desktop_users,
                COUNT(DISTINCT CASE WHEN v.device_type = 'mobile' THEN pv.visitor_id END) as mobile_users,
                COUNT(DISTINCT CASE WHEN v.device_type = 'tablet' THEN pv.visitor_id END) as tablet_users
             FROM {$pageviews_table} pv
             JOIN {$visitors_table} v ON pv.visitor_id = v.id
             WHERE pv.visit_date = %s",
            $date
        ));
        
        // Insert or update stats
        $wpdb->replace(
            $daily_stats_table,
            [
                'stat_date' => $date,
                'dau' => $dau,
                'new_visitors' => $visitor_breakdown ? intval($visitor_breakdown->new_visitors) : 0,
                'returning_visitors' => $visitor_breakdown ? intval($visitor_breakdown->returning_visitors) : 0,
                'total_pageviews' => intval($total_pageviews),
                'avg_session_duration' => $session_stats ? floatval($session_stats->avg_session_duration) : null,
                'avg_pages_per_session' => $session_stats ? floatval($session_stats->avg_pages_per_session) : null,
                'bounce_rate' => floatval($bounce_rate),
                'desktop_users' => $device_stats ? intval($device_stats->desktop_users) : 0,
                'mobile_users' => $device_stats ? intval($device_stats->mobile_users) : 0,
                'tablet_users' => $device_stats ? intval($device_stats->tablet_users) : 0,
                'updated_at' => current_time('mysql'),
            ]
        );
    }
    
    /**
     * Get overview statistics
     */
    public function get_overview_stats($days = 30) {
        global $wpdb;
        
        $pageviews_table = $this->database->get_pageviews_table();
        $visitors_table = $this->database->get_visitors_table();
        
        $end_date = current_time('Y-m-d');
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        
        // Previous period for comparison
        $prev_end_date = date('Y-m-d', strtotime('-1 day', strtotime($start_date)));
        $prev_start_date = date('Y-m-d', strtotime("-{$days} days", strtotime($prev_end_date)));
        
        // Current period stats
        $current = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(DISTINCT visitor_id) as unique_visitors,
                COUNT(*) as total_pageviews,
                AVG(time_on_page) as avg_time_on_page,
                SUM(CASE WHEN is_bounce = 1 THEN 1 ELSE 0 END) / COUNT(*) * 100 as bounce_rate
             FROM {$pageviews_table}
             WHERE visit_date BETWEEN %s AND %s",
            $start_date,
            $end_date
        ));
        
        // Previous period stats
        $previous = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(DISTINCT visitor_id) as unique_visitors,
                COUNT(*) as total_pageviews
             FROM {$pageviews_table}
             WHERE visit_date BETWEEN %s AND %s",
            $prev_start_date,
            $prev_end_date
        ));
        
        // Today's DAU
        $today_dau = $this->get_dau();
        
        // Rolling MAU
        $mau = $this->get_rolling_mau();
        
        // Stickiness
        $stickiness = $this->get_stickiness_ratio();
        
        // New visitors in period
        $new_visitors = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$visitors_table}
             WHERE DATE(first_visit) BETWEEN %s AND %s",
            $start_date,
            $end_date
        ));
        
        return [
            'period' => [
                'start' => $start_date,
                'end' => $end_date,
                'days' => $days,
            ],
            'dau' => [
                'today' => $today_dau,
                'label' => 'Daily Active Users',
            ],
            'mau' => [
                'value' => $mau,
                'label' => 'Monthly Active Users (30-day rolling)',
            ],
            'stickiness' => [
                'value' => $stickiness,
                'label' => 'Stickiness Ratio (DAU/MAU)',
                'interpretation' => $this->interpret_stickiness($stickiness),
            ],
            'unique_visitors' => [
                'current' => intval($current->unique_visitors),
                'previous' => intval($previous->unique_visitors),
                'change_pct' => $previous->unique_visitors > 0 
                    ? round((($current->unique_visitors - $previous->unique_visitors) / $previous->unique_visitors) * 100, 1)
                    : 0,
            ],
            'pageviews' => [
                'current' => intval($current->total_pageviews),
                'previous' => intval($previous->total_pageviews),
                'change_pct' => $previous->total_pageviews > 0
                    ? round((($current->total_pageviews - $previous->total_pageviews) / $previous->total_pageviews) * 100, 1)
                    : 0,
            ],
            'avg_time_on_page' => round(floatval($current->avg_time_on_page), 0),
            'bounce_rate' => round(floatval($current->bounce_rate), 1),
            'new_visitors' => intval($new_visitors),
            'returning_visitors' => intval($current->unique_visitors) - intval($new_visitors),
        ];
    }
    
    /**
     * Interpret stickiness ratio
     */
    private function interpret_stickiness($ratio) {
        if ($ratio >= 50) {
            return 'Excellent - Your users are highly engaged and returning frequently. This is exceptional stickiness, comparable to top social media apps.';
        } elseif ($ratio >= 25) {
            return 'Good - You have solid user engagement. Users are finding value and returning regularly.';
        } elseif ($ratio >= 15) {
            return 'Average - Typical engagement for most websites. There\'s room for improvement in getting users to return more frequently.';
        } elseif ($ratio >= 10) {
            return 'Below Average - Users aren\'t returning as often as they could. Consider what would make them want to come back daily.';
        } else {
            return 'Needs Work - Low engagement suggests users aren\'t finding enough value to return. Focus on identifying and improving sticky content.';
        }
    }
    
    /**
     * Get demographics breakdown
     */
    public function get_demographics($days = 30) {
        global $wpdb;
        
        $visitors_table = $this->database->get_visitors_table();
        $pageviews_table = $this->database->get_pageviews_table();
        
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = current_time('Y-m-d');
        
        // Device breakdown
        $devices = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                v.device_type,
                COUNT(DISTINCT pv.visitor_id) as visitors,
                COUNT(*) as pageviews
             FROM {$pageviews_table} pv
             JOIN {$visitors_table} v ON pv.visitor_id = v.id
             WHERE pv.visit_date BETWEEN %s AND %s
             GROUP BY v.device_type
             ORDER BY visitors DESC",
            $start_date,
            $end_date
        ), ARRAY_A);
        
        // Browser breakdown
        $browsers = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                v.browser,
                COUNT(DISTINCT pv.visitor_id) as visitors
             FROM {$pageviews_table} pv
             JOIN {$visitors_table} v ON pv.visitor_id = v.id
             WHERE pv.visit_date BETWEEN %s AND %s
             GROUP BY v.browser
             ORDER BY visitors DESC
             LIMIT 10",
            $start_date,
            $end_date
        ), ARRAY_A);
        
        // Country breakdown
        $countries = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                v.country,
                COUNT(DISTINCT pv.visitor_id) as visitors
             FROM {$pageviews_table} pv
             JOIN {$visitors_table} v ON pv.visitor_id = v.id
             WHERE pv.visit_date BETWEEN %s AND %s AND v.country IS NOT NULL
             GROUP BY v.country
             ORDER BY visitors DESC
             LIMIT 15",
            $start_date,
            $end_date
        ), ARRAY_A);
        
        // Referrer breakdown
        $referrers = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                v.referrer_type,
                COUNT(DISTINCT pv.visitor_id) as visitors
             FROM {$pageviews_table} pv
             JOIN {$visitors_table} v ON pv.visitor_id = v.id
             WHERE pv.visit_date BETWEEN %s AND %s
             GROUP BY v.referrer_type
             ORDER BY visitors DESC",
            $start_date,
            $end_date
        ), ARRAY_A);
        
        // OS breakdown
        $operating_systems = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                v.os,
                COUNT(DISTINCT pv.visitor_id) as visitors
             FROM {$pageviews_table} pv
             JOIN {$visitors_table} v ON pv.visitor_id = v.id
             WHERE pv.visit_date BETWEEN %s AND %s
             GROUP BY v.os
             ORDER BY visitors DESC
             LIMIT 10",
            $start_date,
            $end_date
        ), ARRAY_A);
        
        // Paid vs Organic breakdown (now using ad_platform field and click IDs)
        $paid_organic = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                CASE 
                    WHEN v.ad_platform IS NOT NULL AND v.ad_platform != '' THEN 'paid'
                    WHEN v.gclid IS NOT NULL AND v.gclid != '' THEN 'paid'
                    WHEN v.fbclid IS NOT NULL AND v.fbclid != '' THEN 'paid'
                    WHEN v.msclkid IS NOT NULL AND v.msclkid != '' THEN 'paid'
                    WHEN v.ttclid IS NOT NULL AND v.ttclid != '' THEN 'paid'
                    WHEN v.utm_medium IN ('cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'cpv', 'cpm', 'banner', 'display', 'retargeting', 'paid_social', 'paidsocial') THEN 'paid'
                    WHEN v.utm_medium IN ('email', 'newsletter') THEN 'email'
                    WHEN v.referrer_type = 'organic_search' THEN 'organic_search'
                    WHEN v.referrer_type = 'social' AND (v.utm_medium IS NULL OR v.utm_medium = '' OR v.utm_medium NOT IN ('cpc', 'ppc', 'paid', 'paid_social', 'paidsocial')) THEN 'organic_social'
                    WHEN v.referrer_type = 'direct' THEN 'direct'
                    WHEN v.referrer_type = 'referral' THEN 'referral'
                    ELSE 'other'
                END as traffic_type,
                COUNT(DISTINCT pv.visitor_id) as visitors,
                COUNT(*) as pageviews,
                COUNT(DISTINCT CASE WHEN v.total_visits > 1 THEN pv.visitor_id END) as returning_visitors
             FROM {$pageviews_table} pv
             JOIN {$visitors_table} v ON pv.visitor_id = v.id
             WHERE pv.visit_date BETWEEN %s AND %s
             GROUP BY traffic_type
             ORDER BY visitors DESC",
            $start_date,
            $end_date
        ), ARRAY_A);
        
        // Paid traffic by ad platform (using ad_platform field, falls back to source/medium)
        $paid_platforms = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                COALESCE(v.ad_platform, 
                    CASE 
                        WHEN v.gclid IS NOT NULL AND v.gclid != '' THEN 'google_ads'
                        WHEN v.fbclid IS NOT NULL AND v.fbclid != '' THEN 'facebook_ads'
                        WHEN v.msclkid IS NOT NULL AND v.msclkid != '' THEN 'microsoft_ads'
                        WHEN v.ttclid IS NOT NULL AND v.ttclid != '' THEN 'tiktok_ads'
                        ELSE CONCAT(COALESCE(v.utm_source, 'unknown'), '_ads')
                    END
                ) as platform,
                v.utm_source,
                v.utm_medium,
                COUNT(DISTINCT v.utm_campaign) as unique_campaigns,
                COUNT(DISTINCT pv.visitor_id) as visitors,
                COUNT(*) as pageviews,
                COUNT(DISTINCT CASE WHEN v.total_visits > 1 THEN pv.visitor_id END) as returning_visitors,
                (COUNT(DISTINCT CASE WHEN v.total_visits > 1 THEN pv.visitor_id END) / COUNT(DISTINCT pv.visitor_id)) * 100 as stickiness
             FROM {$pageviews_table} pv
             JOIN {$visitors_table} v ON pv.visitor_id = v.id
             WHERE pv.visit_date BETWEEN %s AND %s
             AND (
                v.ad_platform IS NOT NULL AND v.ad_platform != ''
                OR v.gclid IS NOT NULL AND v.gclid != ''
                OR v.fbclid IS NOT NULL AND v.fbclid != ''
                OR v.msclkid IS NOT NULL AND v.msclkid != ''
                OR v.ttclid IS NOT NULL AND v.ttclid != ''
                OR v.utm_medium IN ('cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'cpv', 'cpm', 'banner', 'display', 'retargeting', 'paid_social', 'paidsocial')
             )
             GROUP BY platform, v.utm_source, v.utm_medium
             HAVING visitors >= 1
             ORDER BY visitors DESC",
            $start_date,
            $end_date
        ), ARRAY_A);
        
        // Top individual campaigns (sample - limited to top 10 by visitors)
        $paid_campaigns = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                v.utm_source,
                v.utm_medium,
                v.utm_campaign,
                COUNT(DISTINCT pv.visitor_id) as visitors,
                COUNT(*) as pageviews,
                COUNT(DISTINCT CASE WHEN v.total_visits > 1 THEN pv.visitor_id END) as returning_visitors,
                (COUNT(DISTINCT CASE WHEN v.total_visits > 1 THEN pv.visitor_id END) / COUNT(DISTINCT pv.visitor_id)) * 100 as stickiness
             FROM {$pageviews_table} pv
             JOIN {$visitors_table} v ON pv.visitor_id = v.id
             WHERE pv.visit_date BETWEEN %s AND %s
             AND (
                v.ad_platform IS NOT NULL AND v.ad_platform != ''
                OR v.gclid IS NOT NULL AND v.gclid != ''
                OR v.fbclid IS NOT NULL AND v.fbclid != ''
                OR v.msclkid IS NOT NULL AND v.msclkid != ''
                OR v.ttclid IS NOT NULL AND v.ttclid != ''
                OR v.utm_medium IN ('cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'cpv', 'cpm', 'banner', 'display', 'retargeting', 'paid_social', 'paidsocial')
             )
             AND v.utm_campaign IS NOT NULL AND v.utm_campaign != ''
             GROUP BY v.utm_source, v.utm_medium, v.utm_campaign
             HAVING visitors >= 1
             ORDER BY visitors DESC
             LIMIT 10",
            $start_date,
            $end_date
        ), ARRAY_A);
        
        return [
            'devices' => $devices,
            'browsers' => $browsers,
            'countries' => $countries,
            'referrers' => $referrers,
            'operating_systems' => $operating_systems,
            'paid_organic' => $paid_organic,
            'paid_platforms' => $paid_platforms,
            'paid_campaigns' => $paid_campaigns,
        ];
    }
}
