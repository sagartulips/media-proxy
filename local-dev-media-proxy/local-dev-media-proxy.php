<?php
/*
Plugin Name: Local Development Media Proxy
Description: Loads media from live site while developing locally with configurable URLs, multi-domain support, and CORS.
Version: 1.5
Author: Sagar GC
Author URI: https://sagargc.com.np
License: GPLv2 or later
Text Domain: local-dev-media-proxy
*/

defined('ABSPATH') || exit;

class LocalDevMediaProxy {
    private $config;
    private $options_key = 'local_dev_media_proxy_settings';
    private $using_constants = false;
    private $cache_types = ['media', 'object', 'browser'];
    private $transient_key = 'ldmp_cache_flush_flag';

    public function __construct() {
        $this->load_config();
        
        // Frontend modifications
        if (!is_admin() || (defined('DOING_AJAX') && DOING_AJAX && !$this->is_backend_ajax())) {
            add_filter('upload_dir', [$this, 'modify_media_urls']);
            add_filter('wp_get_attachment_url', [$this, 'fix_attachment_url']);
            add_filter('wp_calculate_image_srcset', [$this, 'fix_srcset_urls']);
            add_filter('the_content', [$this, 'fix_content_urls'], 999);
            add_filter('wp_prepare_attachment_for_js', [$this, 'fix_media_library_urls']);
            add_action('init', [$this, 'handle_cors'], 1);
            add_action('wp_enqueue_scripts', [$this, 'enqueue_dynamic_media_fix']);
            
            // Add cache busting parameter if needed
            if ($this->should_bust_cache()) {
                add_filter('style_loader_src', [$this, 'add_cache_buster'], 15, 2);
                add_filter('script_loader_src', [$this, 'add_cache_buster'], 15, 2);
            }
        }
        
        // Admin functionality
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_bar_menu', [$this, 'add_admin_bar_item'], 100);
        add_action('admin_notices', [$this, 'show_config_source_notice']);
        add_action('init', [$this, 'maybe_clear_cache']);
    }
    
    private function load_config() {
        $defaults = [
            'local_url' => site_url(),
            'live_url' => '',
            'additional_domains' => '',
            'enable_cors' => true,
            'cache_clear_key' => 'clear_media_cache',
            'default_cache_behavior' => 'media',
            'cache_clear_keys' => 'media',
            'auto_cache_busting' => true,
            'notes' => 'Replace all media URLs from live site while developing locally'
        ];
        
        $this->config = wp_parse_args(get_option($this->options_key, []), $defaults);
        
        // Check for constants first
        if (defined('LOCAL_DEV_MEDIA_LOCAL_URL') || defined('LOCAL_DEV_MEDIA_LIVE_URL')) {
            $this->using_constants = true;
            if (defined('LOCAL_DEV_MEDIA_LOCAL_URL')) $this->config['local_url'] = LOCAL_DEV_MEDIA_LOCAL_URL;
            if (defined('LOCAL_DEV_MEDIA_LIVE_URL')) $this->config['live_url'] = LOCAL_DEV_MEDIA_LIVE_URL;
            if (defined('LOCAL_DEV_MEDIA_ADDITIONAL_DOMAINS')) $this->config['additional_domains'] = LOCAL_DEV_MEDIA_ADDITIONAL_DOMAINS;
            if (defined('LOCAL_DEV_MEDIA_ENABLE_CORS')) $this->config['enable_cors'] = LOCAL_DEV_MEDIA_ENABLE_CORS;
            if (defined('LOCAL_DEV_MEDIA_AUTO_CACHE_BUST')) $this->config['auto_cache_busting'] = LOCAL_DEV_MEDIA_AUTO_CACHE_BUST;
        }
    }
    
    private function get_domains_to_replace() {
        $domains = [$this->config['local_url']];
        
        if (!empty($this->config['additional_domains'])) {
            $additional = array_map('trim', explode(',', $this->config['additional_domains']));
            $domains = array_merge($domains, $additional);
        }
        
        return array_unique(array_filter($domains));
    }
    
    private function replace_domains($url) {
        if (empty($this->config['live_url'])) return $url;
        
        $domains = $this->get_domains_to_replace();
        $live_url = $this->config['live_url'];
        
        foreach ($domains as $domain) {
            $url = str_replace(
                [$domain . '/wp-content/uploads', $domain],
                [$live_url . '/wp-content/uploads', $live_url],
                $url
            );
        }
        
        return $url;
    }
    
    private function is_backend_ajax() {
        return strpos($_SERVER['HTTP_REFERER'] ?? '', admin_url()) === 0;
    }
    
    public function modify_media_urls($uploads) {
        if (empty($this->config['live_url'])) return $uploads;
        
        $uploads['url'] = $this->replace_domains($uploads['url']);
        $uploads['baseurl'] = $this->replace_domains($uploads['baseurl']);
        return $uploads;
    }
    
    public function fix_attachment_url($url) {
        return $this->replace_domains($url);
    }
    
    public function fix_srcset_urls($sources) {
        if (empty($this->config['live_url'])) return $sources;
        
        foreach ($sources as &$source) {
            $source['url'] = $this->replace_domains($source['url']);
        }
        return $sources;
    }
    
    public function fix_content_urls($content) {
        if (empty($this->config['live_url'])) return $content;
        return $this->replace_domains($content);
    }
    
    public function fix_media_library_urls($response) {
        if (empty($this->config['live_url'])) return $response;
        
        foreach ($response as $key => $value) {
            if (is_string($value)) {
                $response[$key] = $this->replace_domains($value);
            }
        }
        return $response;
    }
    
    public function handle_cors() {
        if (empty($this->config['enable_cors'])) return;
        
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (empty($origin)) return;
        
        $allowed_domains = $this->get_domains_to_replace();
        $origin_host = parse_url($origin, PHP_URL_HOST);
        
        foreach ($allowed_domains as $domain) {
            $domain_host = parse_url($domain, PHP_URL_HOST);
            
            if ($origin_host && $domain_host && $origin_host === $domain_host) {
                header("Access-Control-Allow-Origin: $origin");
                header("Access-Control-Allow-Methods: GET, OPTIONS");
                header("Access-Control-Allow-Credentials: true");
                header("Access-Control-Allow-Headers: Content-Type, *");
                
                if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
                    status_header(200);
                    exit;
                }
                break;
            }
        }
    }
    
    public function enqueue_dynamic_media_fix() {
        if (empty($this->config['live_url'])) return;
        
        wp_register_script('local-dev-media-proxy', '', [], '', true);
        wp_enqueue_script('local-dev-media-proxy');
        
        wp_localize_script('local-dev-media-proxy', 'ldmp_vars', [
            'domains' => $this->get_domains_to_replace(),
            'liveUrl' => $this->config['live_url']
        ]);
        
        wp_add_inline_script('local-dev-media-proxy', '
            document.addEventListener("DOMContentLoaded", function() {
                function fixElementUrls(el) {
                    ldmp_vars.domains.forEach(function(domain) {
                        if (el.src) el.src = el.src.replace(domain, ldmp_vars.liveUrl);
                        if (el.srcset) el.srcset = el.srcset.replace(domain, ldmp_vars.liveUrl);
                        if (el.style) el.style.cssText = el.style.cssText.replace(domain, ldmp_vars.liveUrl);
                        ["data-src", "data-bg", "data-srcset"].forEach(attr => {
                            if (el.hasAttribute(attr)) {
                                el.setAttribute(attr, el.getAttribute(attr).replace(domain, ldmp_vars.liveUrl));
                            }
                        });
                    });
                }
                
                // Create selector for all domains
                var selectors = ldmp_vars.domains.map(function(domain) {
                    return \'[src*="\' + domain + \'"], [style*="\' + domain + \'"], [data-src*="\' + domain + \'"], [srcset*="\' + domain + \'"], [data-bg*="\' + domain + \'"], [data-srcset*="\' + domain + \'"]\';
                }).join(", ");
                
                document.querySelectorAll(selectors).forEach(fixElementUrls);
                
                new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        mutation.addedNodes.forEach(function(node) {
                            if (node.nodeType === 1) {
                                var matches = ldmp_vars.domains.some(function(domain) {
                                    return node.matches(\'[src*="\' + domain + \'"], [style*="\' + domain + \'"]\');
                                });
                                
                                if (matches) {
                                    fixElementUrls(node);
                                }
                                node.querySelectorAll(selectors).forEach(fixElementUrls);
                            }
                        });
                    });
                }).observe(document.body, { 
                    subtree: true, 
                    childList: true,
                    attributes: true,
                    attributeFilter: ["src", "srcset", "style", "data-src", "data-bg", "data-srcset"]
                });
            });
        ');
    }

    public function maybe_clear_cache() {
        if (!isset($_REQUEST[$this->config['cache_clear_key']])) return;
        
        $cache_value = $_REQUEST[$this->config['cache_clear_key']];
        $cleared = [];
        
        if ($cache_value === 'true' || $this->config['default_cache_behavior'] === 'all') {
            $this->flush_all_caches();
            $cleared = $this->cache_types;
            set_transient($this->transient_key, time(), 60);
        } elseif (!empty($cache_value) && $cache_value !== 'false') {
            $keys = array_map('trim', explode(',', $cache_value));
            $this->flush_specific_caches($keys);
            $cleared = $keys;
            if (in_array('media', $keys)) set_transient($this->transient_key, time(), 60);
        } else {
            $keys = array_map('trim', explode(',', $this->config['cache_clear_keys']));
            $this->flush_specific_caches($keys);
            $cleared = $keys;
            if (in_array('media', $keys)) set_transient($this->transient_key, time(), 60);
        }
        
        $this->admin_notice(
            sprintf('Cleared cache: %s', implode(', ', array_intersect($cleared, $this->cache_types)))
        );
    }
    
    private function flush_all_caches() {
        try {
            $this->flush_media_cache();
            
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
            
            $this->send_no_cache_headers();
            $this->flush_third_party_caches();
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Local Dev Media Proxy: All caches flushed successfully');
            }
        } catch (Exception $e) {
            error_log('Local Dev Media Proxy cache flush error: ' . $e->getMessage());
        }
    }

    private function flush_specific_caches($keys) {
        if (!is_array($keys)) {
            $keys = array_map('trim', explode(',', $keys));
        }
        
        $valid_keys = array_intersect($keys, $this->cache_types);
        
        foreach ($valid_keys as $key) {
            try {
                switch ($key) {
                    case 'media':
                        $this->flush_media_cache();
                        break;
                        
                    case 'object':
                        if (function_exists('wp_cache_flush')) {
                            wp_cache_flush();
                        }
                        break;
                        
                    case 'browser':
                        $this->send_no_cache_headers();
                        break;
                }
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Local Dev Media Proxy: Flushed '{$key}' cache");
                }
            } catch (Exception $e) {
                error_log("Local Dev Media Proxy error flushing '{$key}' cache: " . $e->getMessage());
            }
        }
    }

    private function flush_media_cache() {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient%attachment%' 
            OR option_name LIKE '_transient%media%'"
        );
        
        delete_option('medium_crop');
        delete_option('large_crop');
        
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('media');
        }
        
        do_action('local_dev_media_proxy_flush_media_cache');
    }

    private function send_no_cache_headers() {
        if (!headers_sent()) {
            if (function_exists('nocache_headers')) {
                nocache_headers();
            } else {
                header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
                header('Cache-Control: no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
            }
        }
    }

    private function flush_third_party_caches() {
        $plugins = [
            'w3tc' => 'w3tc_flush_all',
            'wp_rocket' => 'rocket_clean_domain',
            'wp_super_cache' => 'wp_cache_clear_cache',
            'litespeed' => ['LiteSpeed_Cache_API', 'purge_all']
        ];
        
        foreach ($plugins as $plugin => $method) {
            try {
                if (is_callable($method)) {
                    call_user_func($method);
                }
            } catch (Exception $e) {
                error_log("Local Dev Media Proxy: Error flushing {$plugin} cache - " . $e->getMessage());
            }
        }
    }
    
    private function should_bust_cache() {
        return $this->config['auto_cache_busting'] && get_transient($this->transient_key);
    }
    
    public function add_cache_buster($src, $handle) {
        $cache_flag = get_transient($this->transient_key);
        if ($cache_flag) {
            $src = add_query_arg('ldmp_cache', $cache_flag, $src);
        }
        return $src;
    }
    
    private function admin_notice($message) {
        if (!is_admin() || wp_doing_ajax()) return;
        
        add_action('admin_notices', function() use ($message) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html($message)
            );
        });
    }
    
    public function add_admin_menu() {
        add_options_page(
            'Local Dev Media Proxy',
            'Media Proxy',
            'manage_options',
            'local-dev-media-proxy',
            [$this, 'render_settings_page']
        );
    }
    
    public function register_settings() {
        register_setting('local_dev_media_proxy', $this->options_key);
        
        add_settings_section(
            'local_dev_media_proxy_section',
            'Media Proxy Configuration',
            [$this, 'render_section_header'],
            'local-dev-media-proxy'
        );
        
        add_settings_field(
            'local_url',
            'Local Site URL',
            [$this, 'render_local_url_field'],
            'local-dev-media-proxy',
            'local_dev_media_proxy_section'
        );
        
        add_settings_field(
            'live_url',
            'Live Site URL',
            [$this, 'render_live_url_field'],
            'local-dev-media-proxy',
            'local_dev_media_proxy_section'
        );
        
        add_settings_field(
            'additional_domains',
            'Additional Local Domains',
            [$this, 'render_additional_domains_field'],
            'local-dev-media-proxy',
            'local_dev_media_proxy_section'
        );
        
        add_settings_field(
            'enable_cors',
            'Enable CORS',
            [$this, 'render_enable_cors_field'],
            'local-dev-media-proxy',
            'local_dev_media_proxy_section'
        );
        
        add_settings_field(
            'cache_settings',
            'Cache Settings',
            [$this, 'render_cache_settings_field'],
            'local-dev-media-proxy',
            'local_dev_media_proxy_section'
        );
        
        add_settings_field(
            'auto_cache_busting',
            'Auto Cache Busting',
            [$this, 'render_cache_busting_field'],
            'local-dev-media-proxy',
            'local_dev_media_proxy_section'
        );
        
        add_settings_field(
            'notes',
            'Notes',
            [$this, 'render_notes_field'],
            'local-dev-media-proxy',
            'local_dev_media_proxy_section'
        );
    }
    
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Local Development Media Proxy</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('local_dev_media_proxy');
                do_settings_sections('local-dev-media-proxy');
                
                if (!$this->using_constants) {
                    submit_button();
                } else {
                    echo '<p class="description">Settings are currently controlled via wp-config.php constants</p>';
                }
                ?>
            </form>
            
            <div class="card">
                <h2>Cache Management</h2>
                <div class="cache-controls">
                    <h3>Quick Clear</h3>
                    <p>
                        <a href="<?= esc_url(add_query_arg($this->config['cache_clear_key'], 'true')) ?>" class="button button-primary">
                            Clear All Caches
                        </a>
                        <a href="<?= esc_url(add_query_arg($this->config['cache_clear_key'], 'media,object')) ?>" class="button">
                            Clear Media & Object
                        </a>
                        <a href="<?= esc_url(add_query_arg($this->config['cache_clear_key'], 'media')) ?>" class="button">
                            Clear Media Only
                        </a>
                    </p>
                    <p class="description">Trigger key: <code><?= esc_html($this->config['cache_clear_key']) ?></code></p>
                </div>
            </div>
        </div>
        <style>
            .cache-controls .button { margin-right: 5px; margin-bottom: 5px; }
            .cache-controls .button-primary { background: #2271b1; border-color: #2271b1; color: #fff; }
        </style>
        <?php
    }
    
    public function render_section_header() {
        echo '<p>Configure media URL replacements for local development</p>';
    }
    
    public function render_local_url_field() {
        $value = $this->using_constants && defined('LOCAL_DEV_MEDIA_LOCAL_URL') 
            ? LOCAL_DEV_MEDIA_LOCAL_URL 
            : $this->config['local_url'];
        printf(
            '<input type="text" name="%s[local_url]" value="%s" class="regular-text code" placeholder="https://yoursite.test" %s>',
            esc_attr($this->options_key),
            esc_attr($value),
            $this->using_constants ? 'disabled' : ''
        );
        if ($this->using_constants && defined('LOCAL_DEV_MEDIA_LOCAL_URL')) {
            echo '<p class="description">Defined in wp-config.php</p>';
        }
    }
    
    public function render_live_url_field() {
        $value = $this->using_constants && defined('LOCAL_DEV_MEDIA_LIVE_URL') 
            ? LOCAL_DEV_MEDIA_LIVE_URL 
            : $this->config['live_url'];
        printf(
            '<input type="text" name="%s[live_url]" value="%s" class="regular-text code" placeholder="https://yoursite.com" %s>',
            esc_attr($this->options_key),
            esc_attr($value),
            $this->using_constants ? 'disabled' : ''
        );
        if ($this->using_constants && defined('LOCAL_DEV_MEDIA_LIVE_URL')) {
            echo '<p class="description">Defined in wp-config.php</p>';
        }
    }
    
    public function render_additional_domains_field() {
        $value = $this->using_constants && defined('LOCAL_DEV_MEDIA_ADDITIONAL_DOMAINS') 
            ? LOCAL_DEV_MEDIA_ADDITIONAL_DOMAINS 
            : $this->config['additional_domains'];
        printf(
            '<input type="text" name="%s[additional_domains]" value="%s" class="regular-text code" placeholder="https://abcno.test,https://abcde.test" %s>',
            esc_attr($this->options_key),
            esc_attr($value),
            $this->using_constants ? 'disabled' : ''
        );
        echo '<p class="description">Comma-separated list of additional local domains that share the same media</p>';
        if ($this->using_constants && defined('LOCAL_DEV_MEDIA_ADDITIONAL_DOMAINS')) {
            echo '<p class="description">Defined in wp-config.php</p>';
        }
    }
    
    public function render_enable_cors_field() {
        $checked = $this->using_constants && defined('LOCAL_DEV_MEDIA_ENABLE_CORS') 
            ? LOCAL_DEV_MEDIA_ENABLE_CORS 
            : $this->config['enable_cors'];
        printf(
            '<label><input type="checkbox" name="%s[enable_cors]" value="1" %s %s> Enable CORS headers</label>',
            esc_attr($this->options_key),
            checked(1, $checked, false),
            $this->using_constants ? 'disabled' : ''
        );
        echo '<p class="description">Required if getting CORS errors loading media</p>';
    }
    
    public function render_cache_settings_field() {
        ?>
        <fieldset>
            <legend class="screen-reader-text">Cache Clearing Behavior</legend>
            
            <p>
                <label>
                    <input type="radio" name="<?= esc_attr($this->options_key) ?>[default_cache_behavior]" value="media" <?php checked('media', $this->config['default_cache_behavior']); ?> <?= $this->using_constants ? 'disabled' : '' ?>>
                    Clear media cache only (default)
                </label>
            </p>
            
            <p>
                <label>
                    <input type="radio" name="<?= esc_attr($this->options_key) ?>[default_cache_behavior]" value="specific" <?php checked('specific', $this->config['default_cache_behavior']); ?> <?= $this->using_constants ? 'disabled' : '' ?>>
                    Clear specific caches:
                </label>
                <input type="text" name="<?= esc_attr($this->options_key) ?>[cache_clear_keys]" value="<?= esc_attr($this->config['cache_clear_keys']) ?>" class="regular-text" placeholder="media,object" <?= $this->using_constants ? 'disabled' : '' ?>>
                <span class="description">Comma-separated: media, object, browser</span>
            </p>
            
            <p>
                <label>
                    <input type="radio" name="<?= esc_attr($this->options_key) ?>[default_cache_behavior]" value="all" <?php checked('all', $this->config['default_cache_behavior']); ?> <?= $this->using_constants ? 'disabled' : '' ?>>
                    Clear all caches when triggered
                </label>
            </p>
            
            <p>
                <label>Cache Trigger Key:
                    <input type="text" name="<?= esc_attr($this->options_key) ?>[cache_clear_key]" value="<?= esc_attr($this->config['cache_clear_key']) ?>" class="regular-text" <?= $this->using_constants ? 'disabled' : '' ?>>
                </label>
                <span class="description">URL parameter to trigger clearing</span>
            </p>
        </fieldset>
        <?php
    }
    
    public function render_cache_busting_field() {
        $checked = $this->using_constants && defined('LOCAL_DEV_MEDIA_AUTO_CACHE_BUST') 
            ? LOCAL_DEV_MEDIA_AUTO_CACHE_BUST 
            : $this->config['auto_cache_busting'];
        printf(
            '<label><input type="checkbox" name="%s[auto_cache_busting]" value="1" %s %s> Automatically add cache-busting parameters</label>',
            esc_attr($this->options_key),
            checked(1, $checked, false),
            $this->using_constants ? 'disabled' : ''
        );
        echo '<p class="description">Adds cache-busting parameter to assets after cache clearing</p>';
    }
    
    public function render_notes_field() {
        printf(
            '<textarea name="%s[notes]" rows="3" class="large-text" %s>%s</textarea>',
            esc_attr($this->options_key),
            $this->using_constants ? 'disabled' : '',
            esc_textarea($this->config['notes'])
        );
    }
    
    public function show_config_source_notice() {
        if (!current_user_can('manage_options')) return;
        
        $screen = get_current_screen();
        if ($screen->id !== 'settings_page_local-dev-media-proxy') return;
        
        if ($this->using_constants) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>Note:</strong> Configuration is being loaded from wp-config.php constants. ';
            echo 'Admin settings below are visible but not active.</p></div>';
            
            echo '<div class="notice notice-info"><p>';
            echo '<strong>Active Configuration:</strong><br>';
            echo 'Local URL: ' . esc_html($this->config['local_url']) . '<br>';
            echo 'Live URL: ' . esc_html($this->config['live_url']) . '<br>';
            if (!empty($this->config['additional_domains'])) {
                echo 'Additional Domains: ' . esc_html($this->config['additional_domains']) . '<br>';
            }
            echo 'CORS: ' . ($this->config['enable_cors'] ? 'Enabled' : 'Disabled') . '<br>';
            echo 'Cache Busting: ' . ($this->config['auto_cache_busting'] ? 'Enabled' : 'Disabled');
            echo '</p></div>';
        }
    }
    
    public function add_admin_bar_item($admin_bar) {
        if (!current_user_can('manage_options')) return;
        
        $admin_bar->add_node([
            'id'    => 'local-dev-media-proxy',
            'title' => 'Media Proxy: ' . (empty($this->config['live_url']) ? 'Inactive' : 'Active'),
            'href'  => admin_url('options-general.php?page=local-dev-media-proxy'),
            'meta'  => [
                'title' => empty($this->config['live_url']) ? 
                    'Configure media proxy' : 
                    'Live media: ' . esc_html($this->config['live_url']),
            ],
        ]);
        
        if (!empty($this->config['live_url'])) {
            $admin_bar->add_node([
                'id'     => 'local-dev-media-proxy-clear',
                'parent' => 'local-dev-media-proxy',
                'title'  => 'Clear Media Cache',
                'href'   => add_query_arg($this->config['cache_clear_key'], 'media'),
                'meta'   => [
                    'title' => 'Clear media URL cache',
                ],
            ]);
        }
    }
}

new LocalDevMediaProxy();