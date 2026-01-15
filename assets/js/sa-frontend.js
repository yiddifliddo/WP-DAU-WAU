/**
 * Stickiness Analytics Frontend Tracker
 * Tracks pageviews, time on page, scroll depth, and user engagement
 */

(function() {
    'use strict';
    
    // Configuration
    const config = window.saConfig || {};
    const COOKIE_NAME = 'sa_uid';
    const SESSION_COOKIE = 'sa_sid';
    
    // State
    let pageviewId = null;
    let sessionId = null;
    let startTime = Date.now();
    let maxScrollDepth = 0;
    let isEngaged = false; // Has user interacted beyond initial load
    let lastActivityTime = Date.now();
    let timeOnPage = 0;
    let isVisible = true;
    
    /**
     * Generate a unique ID
     */
    function generateId() {
        return 'sa_' + Date.now().toString(36) + Math.random().toString(36).substr(2, 9);
    }
    
    /**
     * Get or create visitor fingerprint
     */
    function getFingerprint() {
        const components = [
            navigator.userAgent,
            navigator.language,
            screen.width + 'x' + screen.height,
            screen.colorDepth,
            new Date().getTimezoneOffset(),
            navigator.hardwareConcurrency || 'unknown',
            navigator.deviceMemory || 'unknown',
        ];
        
        // Simple hash function
        let hash = 0;
        const str = components.join('|');
        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash;
        }
        
        return 'fp_' + Math.abs(hash).toString(36);
    }
    
    /**
     * Get or set cookie
     */
    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? match[2] : null;
    }
    
    function setCookie(name, value, days) {
        const date = new Date();
        date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
        document.cookie = name + '=' + value + ';expires=' + date.toUTCString() + ';path=/;SameSite=Lax';
    }
    
    /**
     * Get or create session ID
     */
    function getSessionId() {
        let sid = getCookie(SESSION_COOKIE);
        if (!sid) {
            sid = generateId();
            // Session cookie (expires when browser closes, or after 30 minutes of inactivity)
            document.cookie = SESSION_COOKIE + '=' + sid + ';path=/;SameSite=Lax';
        }
        return sid;
    }
    
    /**
     * Parse UTM parameters from URL
     */
    function getUtmParams() {
        const params = new URLSearchParams(window.location.search);
        return {
            utm_source: params.get('utm_source') || '',
            utm_medium: params.get('utm_medium') || '',
            utm_campaign: params.get('utm_campaign') || '',
        };
    }
    
    /**
     * Parse ad platform click IDs from URL
     */
    function getClickIds() {
        const params = new URLSearchParams(window.location.search);
        return {
            gclid: params.get('gclid') || '',      // Google Ads
            fbclid: params.get('fbclid') || '',    // Facebook/Meta Ads
            msclkid: params.get('msclkid') || '',  // Microsoft/Bing Ads
            ttclid: params.get('ttclid') || '',    // TikTok Ads
        };
    }
    
    /**
     * Auto-detect ad platform from click IDs
     */
    function detectAdPlatform(clickIds, utmParams) {
        if (clickIds.gclid) return 'google_ads';
        if (clickIds.fbclid) return 'facebook_ads';
        if (clickIds.msclkid) return 'microsoft_ads';
        if (clickIds.ttclid) return 'tiktok_ads';
        
        // Check UTM medium for paid indicators
        const paidMediums = ['cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'cpv', 'cpm', 'banner', 'display', 'retargeting', 'paid_social', 'paidsocial'];
        if (paidMediums.includes((utmParams.utm_medium || '').toLowerCase())) {
            // Try to identify platform from source
            const source = (utmParams.utm_source || '').toLowerCase();
            if (source.includes('google')) return 'google_ads';
            if (source.includes('facebook') || source.includes('fb') || source.includes('meta') || source.includes('instagram')) return 'facebook_ads';
            if (source.includes('bing') || source.includes('microsoft')) return 'microsoft_ads';
            if (source.includes('tiktok')) return 'tiktok_ads';
            if (source.includes('linkedin')) return 'linkedin_ads';
            if (source.includes('twitter') || source === 'x') return 'twitter_ads';
            return 'paid_other';
        }
        
        return '';
    }
    
    /**
     * Check if this is an entry page (first page in session)
     */
    function isEntryPage() {
        const sessionPages = sessionStorage.getItem('sa_session_pages');
        if (!sessionPages) {
            sessionStorage.setItem('sa_session_pages', '1');
            return true;
        }
        sessionStorage.setItem('sa_session_pages', (parseInt(sessionPages) + 1).toString());
        return false;
    }
    
    /**
     * Track scroll depth
     */
    function trackScrollDepth() {
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const docHeight = Math.max(
            document.body.scrollHeight,
            document.documentElement.scrollHeight,
            document.body.offsetHeight,
            document.documentElement.offsetHeight
        );
        const winHeight = window.innerHeight;
        
        const scrollPercent = Math.round((scrollTop / (docHeight - winHeight)) * 100);
        
        if (scrollPercent > maxScrollDepth) {
            maxScrollDepth = Math.min(100, scrollPercent);
        }
    }
    
    /**
     * Track time on page (accounting for visibility)
     */
    function updateTimeOnPage() {
        if (isVisible) {
            timeOnPage = Math.round((Date.now() - startTime) / 1000);
        }
    }
    
    /**
     * Send tracking data via AJAX
     */
    function sendTrackingData(action, data, callback) {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', config.nonce);
        
        for (const key in data) {
            if (data.hasOwnProperty(key)) {
                formData.append(key, data[key]);
            }
        }
        
        fetch(config.ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        })
        .then(response => response.json())
        .then(result => {
            if (callback && typeof callback === 'function') {
                callback(result);
            }
        })
        .catch(error => {
            console.error('Stickiness Analytics tracking error:', error);
        });
    }
    
    /**
     * Send pageview
     */
    function trackPageview() {
        sessionId = getSessionId();
        const fingerprint = getFingerprint();
        const utmParams = getUtmParams();
        const clickIds = getClickIds();
        const adPlatform = detectAdPlatform(clickIds, utmParams);
        const entry = isEntryPage();
        
        // Get or create visitor cookie
        let visitorId = getCookie(COOKIE_NAME);
        if (!visitorId) {
            visitorId = generateId();
            setCookie(COOKIE_NAME, visitorId, config.cookieDuration || 365);
        }
        
        const data = {
            page_id: config.pageId || '',
            page_url: config.pageUrl || window.location.href,
            page_title: config.pageTitle || document.title,
            post_type: config.postType || '',
            referrer: document.referrer,
            fingerprint: fingerprint,
            session_id: sessionId,
            entry_page: entry ? 1 : 0,
            screen_resolution: screen.width + 'x' + screen.height,
            language: navigator.language,
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            ...utmParams,
            ...clickIds,
            ad_platform: adPlatform,
        };
        
        sendTrackingData('sa_pv', data, function(result) {
            if (result.success && result.data) {
                pageviewId = result.data.pageview_id;
            }
        });
    }
    
    /**
     * Send engagement update
     */
    function trackEngagement(isExit = false) {
        if (!pageviewId) return;
        
        updateTimeOnPage();
        
        const data = {
            pageview_id: pageviewId,
            time_on_page: timeOnPage,
            scroll_depth: maxScrollDepth,
            is_bounce: isEngaged ? 0 : 1,
        };
        
        if (isExit) {
            data.exit_page = 1;
        }
        
        // Use sendBeacon for exit tracking (more reliable)
        if (isExit && navigator.sendBeacon) {
            const formData = new FormData();
            formData.append('action', 'sa_eng');
            formData.append('nonce', config.nonce);
            for (const key in data) {
                formData.append(key, data[key]);
            }
            navigator.sendBeacon(config.ajaxUrl, formData);
        } else {
            sendTrackingData('sa_eng', data);
        }
    }
    
    /**
     * Detect user engagement
     */
    function detectEngagement() {
        isEngaged = true;
        lastActivityTime = Date.now();
    }
    
    /**
     * Initialize tracking
     */
    function init() {
        // Track pageview immediately
        trackPageview();
        
        // Track scroll depth
        let scrollTimeout;
        window.addEventListener('scroll', function() {
            detectEngagement();
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(trackScrollDepth, 100);
        }, { passive: true });
        
        // Track engagement signals
        document.addEventListener('click', detectEngagement);
        document.addEventListener('keydown', detectEngagement);
        document.addEventListener('mousemove', function() {
            lastActivityTime = Date.now();
        }, { passive: true });
        
        // Track visibility changes
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') {
                isVisible = false;
                trackEngagement(false);
            } else {
                isVisible = true;
                startTime = Date.now() - (timeOnPage * 1000);
            }
        });
        
        // Periodic engagement updates (every 15 seconds while active)
        setInterval(function() {
            if (isVisible && (Date.now() - lastActivityTime) < 60000) {
                trackEngagement(false);
            }
        }, 15000);
        
        // Track exit
        window.addEventListener('beforeunload', function() {
            trackEngagement(true);
        });
        
        // Also track on pagehide for mobile
        window.addEventListener('pagehide', function() {
            trackEngagement(true);
        });
    }
    
    // Start tracking when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
})();
