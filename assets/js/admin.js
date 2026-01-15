/**
 * Stickiness Analytics Admin JavaScript
 * Handles dashboard interactions and API calls
 */

(function($) {
    'use strict';
    
    const config = window.saAdmin || {};
    
    /**
     * Make API request
     */
    function apiRequest(endpoint, method, data) {
        return $.ajax({
            url: config.restUrl + endpoint,
            method: method || 'GET',
            data: data,
            headers: {
                'X-WP-Nonce': config.nonce,
            },
        });
    }
    
    /**
     * Refresh dashboard data
     */
    function refreshDashboard(days) {
        apiRequest('overview', 'GET', { days: days })
            .done(function(data) {
                updateOverviewCards(data);
            })
            .fail(function(xhr) {
                console.error('Failed to load overview:', xhr.responseText);
            });
    }
    
    /**
     * Update overview cards with new data
     */
    function updateOverviewCards(data) {
        // This would update the cards dynamically
        // For now, cards are rendered server-side
    }
    
    /**
     * Generate AI insights
     */
    function generateInsights(question) {
        const $loading = $('#sa-insights-loading');
        const $result = $('#sa-insights-result');
        
        $loading.show();
        $result.hide();
        
        apiRequest('insights', 'POST', { question: question || null })
            .done(function(data) {
                if (data.insights) {
                    // Convert markdown to HTML (basic)
                    let html = data.insights
                        .replace(/^### (.*$)/gim, '<h4>$1</h4>')
                        .replace(/^## (.*$)/gim, '<h3>$1</h3>')
                        .replace(/^# (.*$)/gim, '<h2>$1</h2>')
                        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                        .replace(/\*(.*?)\*/g, '<em>$1</em>')
                        .replace(/^\- (.*$)/gim, '<li>$1</li>')
                        .replace(/^\d+\. (.*$)/gim, '<li>$1</li>')
                        .replace(/\n\n/g, '</p><p>')
                        .replace(/\n/g, '<br>');
                    
                    // Wrap lists
                    html = html.replace(/(<li>.*<\/li>)+/g, function(match) {
                        return '<ul>' + match + '</ul>';
                    });
                    
                    $result.html('<div class="sa-insight-content"><p>' + html + '</p></div>');
                    $result.append('<p class="sa-insight-timestamp">Generated: ' + data.generated_at + '</p>');
                } else if (data.error) {
                    $result.html('<div class="notice notice-error"><p>' + data.error + '</p></div>');
                }
            })
            .fail(function(xhr) {
                const error = xhr.responseJSON?.error || 'Failed to generate insights';
                $result.html('<div class="notice notice-error"><p>' + error + '</p></div>');
            })
            .always(function() {
                $loading.hide();
                $result.show();
            });
    }
    
    /**
     * Export data as CSV
     */
    function exportToCsv(data, filename) {
        if (!data || !data.length) {
            alert('No data to export');
            return;
        }
        
        const headers = Object.keys(data[0]);
        const csvRows = [headers.join(',')];
        
        data.forEach(function(row) {
            const values = headers.map(function(header) {
                const val = row[header] || '';
                // Escape quotes and wrap in quotes if contains comma
                if (typeof val === 'string' && (val.includes(',') || val.includes('"'))) {
                    return '"' + val.replace(/"/g, '""') + '"';
                }
                return val;
            });
            csvRows.push(values.join(','));
        });
        
        const csvContent = csvRows.join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        
        if (navigator.msSaveBlob) {
            navigator.msSaveBlob(blob, filename);
        } else {
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            link.click();
        }
    }
    
    /**
     * Initialize dashboard events
     */
    function initDashboard() {
        // Days filter change
        $('#sa-days-filter').on('change', function() {
            const days = $(this).val();
            window.location.href = updateQueryParam('days', days);
        });
        
        // Export CSV button
        $('#sa-export-csv').on('click', function() {
            const days = $('#sa-days-filter').val() || 30;
            
            apiRequest('pages', 'GET', { days: days, limit: 1000 })
                .done(function(data) {
                    const filename = 'stickiness-pages-' + new Date().toISOString().split('T')[0] + '.csv';
                    exportToCsv(data, filename);
                })
                .fail(function() {
                    alert('Failed to export data');
                });
        });
    }
    
    /**
     * Initialize insights page
     */
    function initInsights() {
        // Generate full insights
        $('#sa-generate-insights').on('click', function() {
            generateInsights();
        });
        
        // Quick question buttons
        $('.sa-question-btn').on('click', function() {
            const question = $(this).data('question');
            generateInsights(question);
        });
    }
    
    /**
     * Initialize settings page
     */
    function initSettings() {
        // Recalculate stats
        $('#sa-recalculate-stats').on('click', function() {
            const $btn = $(this);
            $btn.prop('disabled', true).text('Processing...');
            
            // This would trigger a background job
            alert('Stats recalculation started. This may take a few minutes.');
            $btn.prop('disabled', false).text('Recalculate Historical Stats');
        });
        
        // Export all data
        $('#sa-export-all').on('click', function() {
            apiRequest('export', 'GET', { type: 'pages', days: 90 })
                .done(function(data) {
                    const filename = 'stickiness-export-' + new Date().toISOString().split('T')[0] + '.json';
                    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
                    const link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = filename;
                    link.click();
                });
        });
        
        // Clear all data
        $('#sa-clear-data').on('click', function() {
            if (confirm('Are you sure you want to delete ALL analytics data? This cannot be undone!')) {
                if (confirm('This will permanently delete all visitor and pageview data. Type "DELETE" to confirm.')) {
                    alert('Data clearing not yet implemented. Please do this via the database if needed.');
                }
            }
        });
    }
    
    /**
     * Update query parameter in URL
     */
    function updateQueryParam(key, value) {
        const url = new URL(window.location.href);
        url.searchParams.set(key, value);
        return url.toString();
    }
    
    /**
     * Format numbers with K/M suffix
     */
    function formatNumber(num) {
        if (num >= 1000000) {
            return (num / 1000000).toFixed(1) + 'M';
        }
        if (num >= 1000) {
            return (num / 1000).toFixed(1) + 'K';
        }
        return num;
    }
    
    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Detect which page we're on
        if ($('.sa-dashboard').length) {
            initDashboard();
        }
        
        if ($('.sa-page-stickiness').length) {
            initDashboard();
        }
        
        if ($('.sa-insights').length) {
            initInsights();
        }
        
        if ($('.sa-settings').length) {
            initSettings();
        }
    });
    
})(jQuery);
