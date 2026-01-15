<?php
/**
 * Admin class for Stickiness Analytics
 * Handles admin dashboard and settings
 */

if (!defined('ABSPATH')) {
    exit;
}

class SA_Admin {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('admin_init', [$this, 'register_settings']);
    }
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        add_menu_page(
            'Stickiness Analytics',
            'Stickiness',
            'manage_options',
            'stickiness-analytics',
            [$this, 'render_dashboard'],
            'dashicons-chart-line',
            30
        );
        
        add_submenu_page(
            'stickiness-analytics',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'stickiness-analytics',
            [$this, 'render_dashboard']
        );
        
        add_submenu_page(
            'stickiness-analytics',
            'Page Stickiness',
            'Page Stickiness',
            'manage_options',
            'sa-page-stickiness',
            [$this, 'render_page_stickiness']
        );
        
        add_submenu_page(
            'stickiness-analytics',
            'Demographics',
            'Demographics',
            'manage_options',
            'sa-demographics',
            [$this, 'render_demographics']
        );
        
        add_submenu_page(
            'stickiness-analytics',
            'AI Insights',
            'AI Insights',
            'manage_options',
            'sa-insights',
            [$this, 'render_insights']
        );
        
        add_submenu_page(
            'stickiness-analytics',
            'Settings',
            'Settings',
            'manage_options',
            'sa-settings',
            [$this, 'render_settings']
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'stickiness') === false && strpos($hook, 'sa-') === false) {
            return;
        }
        
        // Chart.js
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            [],
            '4.4.1',
            true
        );
        
        // Admin CSS
        wp_enqueue_style(
            'sa-admin-css',
            SA_PLUGIN_URL . 'assets/css/admin.css',
            [],
            SA_VERSION
        );
        
        // Admin JS
        wp_enqueue_script(
            'sa-admin-js',
            SA_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'chartjs'],
            SA_VERSION,
            true
        );
        
        wp_localize_script('sa-admin-js', 'saAdmin', [
            'restUrl' => rest_url('stickiness-analytics/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('sa_settings', 'sa_tracking_enabled');
        register_setting('sa_settings', 'sa_exclude_admins');
        register_setting('sa_settings', 'sa_cookie_duration');
        register_setting('sa_settings', 'sa_data_retention_days');
        register_setting('sa_settings', 'sa_ai_provider');
        register_setting('sa_settings', 'sa_openai_api_key');
        register_setting('sa_settings', 'sa_mistral_api_key');
        register_setting('sa_settings', 'sa_mistral_agent_id');
    }
    
    /**
     * Render main dashboard
     */
    public function render_dashboard() {
        $analytics = new SA_Analytics();
        $overview = $analytics->get_overview_stats(30);
        $trend = $analytics->get_stickiness_trend(30);
        
        ?>
        <div class="wrap sa-dashboard">
            <h1>Stickiness Analytics Dashboard</h1>
            
            <div class="sa-overview-cards">
                <div class="sa-card sa-card-primary">
                    <div class="sa-card-icon">📊</div>
                    <div class="sa-card-content">
                        <h3>DAU/MAU Stickiness</h3>
                        <div class="sa-card-value"><?php echo esc_html($overview['stickiness']['value']); ?>%</div>
                        <p class="sa-card-description"><?php echo esc_html($overview['stickiness']['interpretation']); ?></p>
                    </div>
                </div>
                
                <div class="sa-card">
                    <div class="sa-card-icon">👥</div>
                    <div class="sa-card-content">
                        <h3>Daily Active Users</h3>
                        <div class="sa-card-value"><?php echo number_format($overview['dau']['today']); ?></div>
                        <p class="sa-card-label">Today</p>
                    </div>
                </div>
                
                <div class="sa-card">
                    <div class="sa-card-icon">📅</div>
                    <div class="sa-card-content">
                        <h3>Monthly Active Users</h3>
                        <div class="sa-card-value"><?php echo number_format($overview['mau']['value']); ?></div>
                        <p class="sa-card-label">30-day rolling</p>
                    </div>
                </div>
                
                <div class="sa-card">
                    <div class="sa-card-icon">👁️</div>
                    <div class="sa-card-content">
                        <h3>Unique Visitors</h3>
                        <div class="sa-card-value"><?php echo number_format($overview['unique_visitors']['current']); ?></div>
                        <p class="sa-card-change <?php echo $overview['unique_visitors']['change_pct'] >= 0 ? 'positive' : 'negative'; ?>">
                            <?php echo ($overview['unique_visitors']['change_pct'] >= 0 ? '+' : '') . $overview['unique_visitors']['change_pct']; ?>% vs prev period
                        </p>
                    </div>
                </div>
                
                <div class="sa-card">
                    <div class="sa-card-icon">📄</div>
                    <div class="sa-card-content">
                        <h3>Pageviews</h3>
                        <div class="sa-card-value"><?php echo number_format($overview['pageviews']['current']); ?></div>
                        <p class="sa-card-change <?php echo $overview['pageviews']['change_pct'] >= 0 ? 'positive' : 'negative'; ?>">
                            <?php echo ($overview['pageviews']['change_pct'] >= 0 ? '+' : '') . $overview['pageviews']['change_pct']; ?>% vs prev period
                        </p>
                    </div>
                </div>
                
                <div class="sa-card">
                    <div class="sa-card-icon">⏱️</div>
                    <div class="sa-card-content">
                        <h3>Avg Time on Page</h3>
                        <div class="sa-card-value"><?php echo $this->format_seconds($overview['avg_time_on_page']); ?></div>
                    </div>
                </div>
                
                <div class="sa-card">
                    <div class="sa-card-icon">↩️</div>
                    <div class="sa-card-content">
                        <h3>Bounce Rate</h3>
                        <div class="sa-card-value"><?php echo $overview['bounce_rate']; ?>%</div>
                    </div>
                </div>
                
                <div class="sa-card">
                    <div class="sa-card-icon">🔄</div>
                    <div class="sa-card-content">
                        <h3>Returning Visitors</h3>
                        <div class="sa-card-value"><?php echo number_format($overview['returning_visitors']); ?></div>
                        <p class="sa-card-label"><?php echo round($overview['returning_visitors'] / max(1, $overview['unique_visitors']['current']) * 100, 1); ?>% of total</p>
                    </div>
                </div>
            </div>
            
            <div class="sa-charts-row">
                <div class="sa-chart-container">
                    <h3>Stickiness Trend (30 Days)</h3>
                    <div style="position: relative; height: 250px; width: 100%;">
                        <canvas id="sa-stickiness-chart"></canvas>
                    </div>
                </div>
                
                <div class="sa-chart-container">
                    <h3>DAU / MAU Trend</h3>
                    <div style="position: relative; height: 250px; width: 100%;">
                        <canvas id="sa-dau-mau-chart"></canvas>
                    </div>
                </div>
            </div>
            
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const trendData = <?php echo json_encode($trend); ?>;
                
                // Stickiness Chart
                new Chart(document.getElementById('sa-stickiness-chart'), {
                    type: 'line',
                    data: {
                        labels: trendData.map(d => d.date),
                        datasets: [{
                            label: 'Stickiness %',
                            data: trendData.map(d => d.stickiness),
                            borderColor: '#2271b1',
                            backgroundColor: 'rgba(34, 113, 177, 0.1)',
                            fill: true,
                            tension: 0.3,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100,
                                ticks: { callback: v => v + '%' }
                            }
                        }
                    }
                });
                
                // DAU/MAU Chart
                new Chart(document.getElementById('sa-dau-mau-chart'), {
                    type: 'line',
                    data: {
                        labels: trendData.map(d => d.date),
                        datasets: [{
                            label: 'DAU',
                            data: trendData.map(d => d.dau),
                            borderColor: '#00a32a',
                            backgroundColor: 'transparent',
                            tension: 0.3,
                        }, {
                            label: 'MAU (30-day)',
                            data: trendData.map(d => d.mau),
                            borderColor: '#d63638',
                            backgroundColor: 'transparent',
                            tension: 0.3,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                    }
                });
            });
            </script>
        </div>
        <?php
    }
    
    /**
     * Render page stickiness report
     */
    public function render_page_stickiness() {
        $analytics = new SA_Analytics();
        $pages = $analytics->get_page_stickiness(30, 50);
        
        ?>
        <div class="wrap sa-page-stickiness">
            <h1>Page Stickiness Report</h1>
            <p class="description">Pages ranked by their ability to bring visitors back. Higher stickiness scores indicate pages that drive repeat visits.</p>
            
            <div class="sa-filters">
                <select id="sa-days-filter">
                    <option value="7">Last 7 days</option>
                    <option value="30" selected>Last 30 days</option>
                    <option value="90">Last 90 days</option>
                </select>
                <button class="button" id="sa-export-csv">Export CSV</button>
            </div>
            
            <table class="wp-list-table widefat fixed striped sa-pages-table">
                <thead>
                    <tr>
                        <th class="column-rank">#</th>
                        <th class="column-title">Page</th>
                        <th class="column-stickiness">Stickiness Score</th>
                        <th class="column-grade">Grade</th>
                        <th class="column-visitors">Unique Visitors</th>
                        <th class="column-return">Return Visitors</th>
                        <th class="column-paid">Paid Traffic</th>
                        <th class="column-views">Total Views</th>
                        <th class="column-time">Avg. Time</th>
                        <th class="column-scroll">Avg. Scroll</th>
                        <th class="column-bounce">Bounce Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pages as $i => $page) : ?>
                    <tr>
                        <td class="column-rank"><?php echo $i + 1; ?></td>
                        <td class="column-title">
                            <strong>
                                <a href="<?php echo esc_url($page['page_url']); ?>" target="_blank">
                                    <?php echo esc_html($page['page_title'] ?: 'Untitled'); ?>
                                </a>
                            </strong>
                            <br>
                            <span class="sa-post-type"><?php echo esc_html($page['post_type']); ?></span>
                        </td>
                        <td class="column-stickiness">
                            <div class="sa-stickiness-bar">
                                <div class="sa-stickiness-fill" style="width: <?php echo min(100, $page['stickiness_score']); ?>%"></div>
                                <span class="sa-stickiness-value"><?php echo $page['stickiness_score']; ?>%</span>
                            </div>
                        </td>
                        <td class="column-grade">
                            <span class="sa-grade sa-grade-<?php echo strtolower($page['grade']); ?>">
                                <?php echo $page['grade']; ?>
                            </span>
                            <span class="sa-grade-label"><?php echo $page['grade_label']; ?></span>
                        </td>
                        <td class="column-visitors"><?php echo number_format($page['unique_visitors']); ?></td>
                        <td class="column-return"><?php echo number_format($page['return_visitors']); ?></td>
                        <td class="column-paid">
                            <?php if ($page['paid_visitors'] > 0) : ?>
                                <span class="sa-paid-badge"><?php echo $page['paid_visitors']; ?></span>
                                <span class="sa-paid-pct">(<?php echo $page['paid_traffic_pct']; ?>%)</span>
                            <?php else : ?>
                                <span class="sa-no-paid">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="column-views"><?php echo number_format($page['total_views']); ?></td>
                        <td class="column-time"><?php echo $this->format_seconds($page['avg_time_on_page']); ?></td>
                        <td class="column-scroll"><?php echo $page['avg_scroll_depth']; ?>%</td>
                        <td class="column-bounce"><?php echo $page['bounce_rate']; ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="sa-insights-panel">
                <h3>📊 Understanding Page Stickiness</h3>
                <div class="sa-insight-grid">
                    <div class="sa-insight-item">
                        <h4>What is Stickiness Score?</h4>
                        <p>The percentage of unique visitors who have visited your site more than once. High stickiness = people come back for this content.</p>
                    </div>
                    <div class="sa-insight-item">
                        <h4>Grade Scale</h4>
                        <ul>
                            <li><span class="sa-grade sa-grade-a">A</span> 70%+ Exceptional</li>
                            <li><span class="sa-grade sa-grade-b">B</span> 50-69% Good</li>
                            <li><span class="sa-grade sa-grade-c">C</span> 30-49% Average</li>
                            <li><span class="sa-grade sa-grade-d">D</span> 15-29% Below Avg</li>
                            <li><span class="sa-grade sa-grade-f">F</span> <15% Needs Work</li>
                        </ul>
                    </div>
                    <div class="sa-insight-item">
                        <h4>How to Improve</h4>
                        <ul>
                            <li>Study your A-grade pages - what makes them sticky?</li>
                            <li>Add related content links to keep visitors exploring</li>
                            <li>Create series or follow-up content</li>
                            <li>Add email capture to bring visitors back</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render demographics page
     */
    public function render_demographics() {
        $analytics = new SA_Analytics();
        $demographics = new SA_Demographics();
        
        $demo_data = $analytics->get_demographics(30);
        $device_stickiness = $demographics->get_stickiness_by_segment('device', 30);
        $country_stickiness = $demographics->get_stickiness_by_segment('country', 30);
        $time_patterns = $demographics->get_engagement_by_time(30);
        $new_vs_returning = $demographics->get_new_vs_returning_engagement(30);
        
        ?>
        <div class="wrap sa-demographics">
            <h1>Demographics & Segmentation</h1>
            
            <div class="sa-demo-grid">
                <div class="sa-demo-section">
                    <h3>Device Stickiness</h3>
                    <p class="description">Which devices drive the most repeat visits?</p>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Device</th>
                                <th>Visitors</th>
                                <th>Stickiness</th>
                                <th>Pages/Visit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($device_stickiness as $row) : ?>
                            <tr>
                                <td><strong><?php echo ucfirst(esc_html($row['segment'])); ?></strong></td>
                                <td><?php echo number_format($row['unique_visitors']); ?></td>
                                <td>
                                    <div class="sa-mini-bar" style="--width: <?php echo min(100, $row['stickiness']); ?>%">
                                        <?php echo $row['stickiness']; ?>%
                                    </div>
                                </td>
                                <td><?php echo $row['pages_per_visitor']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="sa-demo-section">
                    <h3>New vs Returning Visitors</h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Visitors</th>
                                <th>Pageviews</th>
                                <th>Bounce Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($new_vs_returning as $type => $data) : ?>
                            <tr>
                                <td><strong><?php echo ucfirst($type); ?></strong></td>
                                <td><?php echo number_format($data['visitors']); ?></td>
                                <td><?php echo number_format($data['pageviews']); ?></td>
                                <td><?php echo $data['bounce_rate']; ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="sa-demo-section">
                    <h3>Top Countries by Stickiness</h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Country</th>
                                <th>Visitors</th>
                                <th>Stickiness</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($country_stickiness, 0, 10) as $row) : ?>
                            <tr>
                                <td><strong><?php echo esc_html($row['segment']); ?></strong></td>
                                <td><?php echo number_format($row['unique_visitors']); ?></td>
                                <td><?php echo $row['stickiness']; ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="sa-demo-section">
                    <h3>📊 Traffic Sources</h3>
                    <p class="description">Full breakdown by acquisition channel</p>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Traffic Type</th>
                                <th>Visitors</th>
                                <th>Pageviews</th>
                                <th>Returning</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $traffic_labels = [
                                'paid' => '💰 Paid Ads',
                                'organic_search' => '🔍 Organic Search',
                                'organic_social' => '📱 Organic Social',
                                'email' => '📧 Email',
                                'direct' => '🔗 Direct',
                                'referral' => '↗️ Referral',
                                'other' => '🌐 Other',
                            ];
                            foreach ($demo_data['paid_organic'] as $row) : 
                                $label = isset($traffic_labels[$row['traffic_type']]) ? $traffic_labels[$row['traffic_type']] : ucfirst($row['traffic_type']);
                            ?>
                            <tr class="<?php echo $row['traffic_type'] === 'paid' ? 'sa-paid-row' : ''; ?>">
                                <td><strong><?php echo $label; ?></strong></td>
                                <td><?php echo number_format($row['visitors']); ?></td>
                                <td><?php echo number_format($row['pageviews']); ?></td>
                                <td><?php echo number_format($row['returning_visitors']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (!empty($demo_data['paid_platforms'])) : ?>
                <div class="sa-demo-section sa-full-width">
                    <h3>💰 Ad Platform Performance</h3>
                    <p class="description">Paid traffic grouped by advertising platform — works with Google Ads auto-tagging (gclid), Facebook (fbclid), Microsoft (msclkid), and UTM parameters</p>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Platform</th>
                                <th>Source</th>
                                <th>Medium</th>
                                <th>Campaigns</th>
                                <th>Visitors</th>
                                <th>Pageviews</th>
                                <th>Returning</th>
                                <th>Stickiness</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($demo_data['paid_platforms'] as $row) : 
                                $platform = $row['platform'] ?? '';
                                $platform_labels = [
                                    'google_ads' => '🔵 Google Ads',
                                    'facebook_ads' => '🔷 Facebook/Meta',
                                    'microsoft_ads' => '🟦 Microsoft Ads',
                                    'tiktok_ads' => '🎵 TikTok Ads',
                                    'linkedin_ads' => '🔹 LinkedIn Ads',
                                    'twitter_ads' => '✖️ Twitter/X Ads',
                                    'paid_other' => '💰 Other Paid',
                                ];
                                $platform_label = isset($platform_labels[$platform]) ? $platform_labels[$platform] : '💰 ' . ucfirst(str_replace('_', ' ', $platform));
                            ?>
                            <tr class="sa-paid-row">
                                <td><strong><?php echo $platform_label; ?></strong></td>
                                <td><?php echo esc_html($row['utm_source'] ?: '(auto-tagged)'); ?></td>
                                <td><?php echo esc_html($row['utm_medium'] ?: '-'); ?></td>
                                <td><span class="sa-campaign-count"><?php echo number_format($row['unique_campaigns']); ?></span></td>
                                <td><?php echo number_format($row['visitors']); ?></td>
                                <td><?php echo number_format($row['pageviews']); ?></td>
                                <td><?php echo number_format($row['returning_visitors']); ?></td>
                                <td>
                                    <div class="sa-mini-bar" style="--width: <?php echo min(100, round($row['stickiness'], 1)); ?>%">
                                        <?php echo round($row['stickiness'], 1); ?>%
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($demo_data['paid_campaigns'])) : ?>
                <div class="sa-demo-section sa-full-width">
                    <h3>📋 Top 10 Individual Campaigns</h3>
                    <p class="description">Sample of top performing campaign names by visitor count</p>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Source</th>
                                <th>Medium</th>
                                <th>Campaign</th>
                                <th>Visitors</th>
                                <th>Pageviews</th>
                                <th>Returning</th>
                                <th>Stickiness</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($demo_data['paid_campaigns'] as $row) : ?>
                            <tr>
                                <td><strong><?php echo esc_html($row['utm_source'] ?: '-'); ?></strong></td>
                                <td><?php echo esc_html($row['utm_medium'] ?: '-'); ?></td>
                                <td class="sa-campaign-name" title="<?php echo esc_attr($row['utm_campaign']); ?>">
                                    <?php 
                                    $campaign = $row['utm_campaign'] ?: '-';
                                    echo esc_html(strlen($campaign) > 40 ? substr($campaign, 0, 37) . '...' : $campaign); 
                                    ?>
                                </td>
                                <td><?php echo number_format($row['visitors']); ?></td>
                                <td><?php echo number_format($row['pageviews']); ?></td>
                                <td><?php echo number_format($row['returning_visitors']); ?></td>
                                <td>
                                    <div class="sa-mini-bar" style="--width: <?php echo min(100, round($row['stickiness'], 1)); ?>%">
                                        <?php echo round($row['stickiness'], 1); ?>%
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                
                <div class="sa-demo-section sa-full-width">
                    <h3>Engagement by Hour of Day</h3>
                    <div style="position: relative; height: 200px; width: 100%;">
                        <canvas id="sa-hourly-chart"></canvas>
                    </div>
                </div>
                
                <div class="sa-demo-section">
                    <h3>Browser Distribution</h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Browser</th>
                                <th>Visitors</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($demo_data['browsers'] as $row) : ?>
                            <tr>
                                <td><?php echo esc_html($row['browser']); ?></td>
                                <td><?php echo number_format($row['visitors']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Hourly Chart
                const hourlyData = <?php echo json_encode($time_patterns['by_hour']); ?>;
                new Chart(document.getElementById('sa-hourly-chart'), {
                    type: 'bar',
                    data: {
                        labels: hourlyData.map(h => h.hour + ':00'),
                        datasets: [{
                            label: 'Visitors',
                            data: hourlyData.map(h => h.visitors),
                            backgroundColor: '#2271b1',
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } }
                    }
                });
            });
            </script>
        </div>
        <?php
    }
    
    /**
     * Render AI insights page
     */
    public function render_insights() {
        ?>
        <div class="wrap sa-insights">
            <h1>AI-Powered Insights</h1>
            
            <div class="sa-insight-intro">
                <p>Get AI-powered analysis of your stickiness data. AI (OpenAi) will analyze your metrics and provide actionable recommendations.</p>
            </div>
            
            <div class="sa-insight-actions">
                <button class="button button-primary button-hero" id="sa-generate-insights">
                    <span class="dashicons dashicons-lightbulb"></span>
                    Generate Insights Report
                </button>
            </div>
            
            <div id="sa-insights-loading" style="display: none;">
                <div class="sa-loading-spinner"></div>
                <p>Analyzing your data...</p>
            </div>
            
            <div id="sa-insights-result" class="sa-insights-content" style="display: none;">
                <!-- AI insights will be inserted here -->
            </div>
            
            <div class="sa-manual-analysis">
                <h3>Quick Analysis Questions</h3>
                <div class="sa-question-buttons">
                    <button class="button sa-question-btn" data-question="why_sticky">
                        Why are my top pages sticky?
                    </button>
                    <button class="button sa-question-btn" data-question="improve_retention">
                        How can I improve visitor retention?
                    </button>
                    <button class="button sa-question-btn" data-question="device_differences">
                        Why do different devices have different stickiness?
                    </button>
                    <button class="button sa-question-btn" data-question="best_times">
                        What are my best times for engagement?
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render settings page
     */
    public function render_settings() {
        ?>
        <div class="wrap sa-settings">
            <h1>Stickiness Analytics Settings</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('sa_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Tracking</th>
                        <td>
                            <label>
                                <input type="checkbox" name="sa_tracking_enabled" value="1" 
                                    <?php checked(get_option('sa_tracking_enabled', 1), 1); ?>>
                                Track visitor activity on this site
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Exclude Administrators</th>
                        <td>
                            <label>
                                <input type="checkbox" name="sa_exclude_admins" value="1"
                                    <?php checked(get_option('sa_exclude_admins', 1), 1); ?>>
                                Don't track logged-in administrators
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Cookie Duration (days)</th>
                        <td>
                            <input type="number" name="sa_cookie_duration" 
                                value="<?php echo esc_attr(get_option('sa_cookie_duration', 365)); ?>"
                                min="1" max="730" class="small-text">
                            <p class="description">How long to remember returning visitors</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Data Retention (days)</th>
                        <td>
                            <input type="number" name="sa_data_retention_days"
                                value="<?php echo esc_attr(get_option('sa_data_retention_days', 90)); ?>"
                                min="7" max="365" class="small-text">
                            <p class="description">How long to keep detailed pageview data. Older data will be deleted.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">AI Provider</th>
                        <td>
                            <select name="sa_ai_provider">
                                <option value="openai" <?php selected(get_option('sa_ai_provider', 'openai'), 'openai'); ?>>OpenAI (GPT-4)</option>
                                <option value="mistral" <?php selected(get_option('sa_ai_provider', 'openai'), 'mistral'); ?>>Mistral AI</option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">OpenAI API Key</th>
                        <td>
                            <input type="password" name="sa_openai_api_key"
                                value="<?php echo esc_attr(get_option('sa_openai_api_key', '')); ?>"
                                class="regular-text">
                            <p class="description">Get your key from <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI</a>.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Mistral AI API Key</th>
                        <td>
                            <input type="password" name="sa_mistral_api_key"
                                value="<?php echo esc_attr(get_option('sa_mistral_api_key', '')); ?>"
                                class="regular-text">
                            <p class="description">Get your key from <a href="https://console.mistral.ai/api-keys" target="_blank">Mistral AI Console</a>.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Mistral Agent ID</th>
                        <td>
                            <input type="text" name="sa_mistral_agent_id"
                                value="<?php echo esc_attr(get_option('sa_mistral_agent_id', '')); ?>"
                                class="regular-text" placeholder="ag_xxxxxxxxxxxxxxxxxxxx">
                            <p class="description">Optional. If you created a custom agent in Mistral AI Studio, paste its ID here (e.g., ag_019bbcf30b4f76fca9b9275328ed804f).</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2>Data Management</h2>
            <p>
                <button class="button" id="sa-recalculate-stats">Recalculate Historical Stats</button>
                <button class="button" id="sa-export-all">Export All Data</button>
                <button class="button button-link-delete" id="sa-clear-data">Clear All Data</button>
            </p>
            
            <hr>
            
            <h2>Debug Information</h2>
            <pre style="background: #f0f0f0; padding: 15px; overflow: auto;">
Plugin Version: <?php echo SA_VERSION; ?>

Database Version: <?php echo get_option('sa_db_version', 'Not set'); ?>

Tables:
<?php
global $wpdb;
$tables = [
    $wpdb->prefix . 'sa_visitors',
    $wpdb->prefix . 'sa_pageviews',
    $wpdb->prefix . 'sa_daily_stats',
    $wpdb->prefix . 'sa_page_stats',
];
foreach ($tables as $table) {
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    echo "- {$table}: " . ($count !== null ? number_format($count) . " rows" : "TABLE NOT FOUND") . "\n";
}
?>
            </pre>
        </div>
        <?php
    }
    
    /**
     * Format seconds to human readable time
     */
    private function format_seconds($seconds) {
        if ($seconds < 60) {
            return round($seconds) . 's';
        } elseif ($seconds < 3600) {
            $mins = floor($seconds / 60);
            $secs = round($seconds % 60);
            return $mins . 'm ' . $secs . 's';
        } else {
            $hours = floor($seconds / 3600);
            $mins = floor(($seconds % 3600) / 60);
            return $hours . 'h ' . $mins . 'm';
        }
    }
}
