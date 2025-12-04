<?php
/**
 * QuxPay Plugin Updater Class
 * 
 * Handles automatic updates for the Qux Payment Gateway plugin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class QuxPay_Plugin_Updater {
    
    private $api_url = '';
    private $api_data = array();
    private $plugin_file = '';
    private $name = '';
    private $slug = '';
    private $version = '';
    private $wp_override = false;
    private $beta = false;
    private $cache_key = '';

    /**
     * Constructor
     *
     * @param string $api_url The URL pointing to the custom API endpoint
     * @param string $plugin_file Path to the plugin file
     * @param array $api_data Optional data to send with API calls
     */
    public function __construct($api_url, $plugin_file, $api_data = null) {
        global $edd_plugin_data;

        $this->api_url = trailingslashit($api_url);
        $this->api_data = $api_data;
        $this->plugin_file = $plugin_file;
        $this->name = plugin_basename($plugin_file);
        $this->slug = basename(dirname($plugin_file));
        $this->version = $api_data['version'];
        $this->wp_override = isset($api_data['wp_override']) ? (bool) $api_data['wp_override'] : false;
        $this->beta = !empty($this->api_data['beta']);
        $this->cache_key = 'quxpay_update_' . md5(serialize($this->slug . $this->beta));

        $edd_plugin_data[$this->slug] = $this->api_data;

       // Set up hooks
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugins_api_filter'), 10, 3);
        add_action('after_plugin_row', array($this, 'show_update_notification'), 10, 2);
        add_action('admin_init', array($this, 'show_changelog'));
        add_filter('plugin_row_meta', array($this, 'plugin_row_meta'), 10, 2);
        
        // Hook for when plugin is updated
        add_action('upgrader_process_complete', array($this, 'after_update'), 10, 2);
        
        // Auto-update support hooks
        add_filter('auto_update_plugin', array($this, 'enable_auto_updates'), 10, 2);
        add_filter('plugin_auto_update_setting_html', array($this, 'auto_update_setting_html'), 10, 3);
        
        // Enable auto-updates by default if setting is enabled
        add_filter('auto_update_plugin', array($this, 'maybe_enable_auto_updates'), 10, 2);
        
        // Clear cache on admin requests for testing
        if (is_admin() && current_user_can('update_plugins') && isset($_GET['force-check'])) {
            delete_transient($this->cache_key);
        }
    }

    /**
     * Enable auto-updates for this plugin
     *
     * @param bool $update Whether to update
     * @param object $item The plugin object
     * @return bool
     */
    public function enable_auto_updates($update, $item) {
        // Check if this is our plugin
        if (isset($item->plugin) && $item->plugin === $this->name) {
            // Check if auto-updates are enabled for this plugin
            $auto_updates = get_option('auto_update_plugins', array());
            return in_array($this->name, $auto_updates);
        }
        
        return $update;
    }

    /**
     * Maybe enable auto-updates based on plugin setting
     *
     * @param bool $update
     * @param object $item
     * @return bool
     */
    public function maybe_enable_auto_updates($update, $item) {
        if (isset($item->plugin) && $item->plugin === $this->name) {
            // Get auto-update preference from plugin options
            $auto_update_enabled = get_option('quxpay_auto_update_enabled', 'no');
            
            if ($auto_update_enabled === 'yes') {
                // Add to WordPress auto-update list
                $auto_updates = get_option('auto_update_plugins', array());
                if (!in_array($this->name, $auto_updates)) {
                    $auto_updates[] = $this->name;
                    update_option('auto_update_plugins', $auto_updates);
                }
                return true;
            }
        }
        
        return $update;
    }

    /**
     * Add auto-update toggle to plugin row
     *
     * @param string $html
     * @param string $plugin_file
     * @param array $plugin_data
     * @return string
     */
    public function auto_update_setting_html($html, $plugin_file, $plugin_data) {
        if ($plugin_file !== $this->name) {
            return $html;
        }

        // Check if auto-updates are supported
        if (!wp_is_auto_update_enabled_for_type('plugin')) {
            return $html;
        }

        $auto_updates = get_option('auto_update_plugins', array());
        $is_auto_update_enabled = in_array($this->name, $auto_updates);

        $toggle_url = wp_nonce_url(
            add_query_arg(array(
                'action' => 'toggle-auto-updates',
                'plugin' => $plugin_file,
                'paged' => get_query_var('paged'),
            ), admin_url('plugins.php')),
            'updates'
        );

        if ($is_auto_update_enabled) {
            $action = 'disable';
            $text = __('Disable auto-updates');
            $action_text = __('Auto-updates enabled');
        } else {
            $action = 'enable';
            $text = __('Enable auto-updates');
            $action_text = __('Auto-updates disabled');
        }

        $html = sprintf(
            '<a href="%s" class="toggle-auto-update" data-wp-action="%s">
                <span class="dashicons dashicons-update" aria-hidden="true"></span>
                <span class="label">%s</span>
            </a>',
            esc_url($toggle_url),
            $action,
            $text
        );

        return $html;
    }

    /**
     * Handle AJAX toggle for auto-updates
     */
    public function wp_ajax_toggle_auto_updates() {
        if (empty($_POST['plugin'])) {
            wp_die(__('No plugin specified.'));
        }

        $plugin = plugin_basename(sanitize_text_field(wp_unslash($_POST['plugin'])));

        if ($plugin !== $this->name) {
            return;
        }

        if (!current_user_can('update_plugins')) {
            wp_die(__('Sorry, you are not allowed to modify plugins for this site.'));
        }

        check_admin_referer('updates');

        $auto_updates = get_option('auto_update_plugins', array());

        if (in_array($plugin, $auto_updates)) {
            $auto_updates = array_diff($auto_updates, array($plugin));
            $action = 'disable';
        } else {
            $auto_updates[] = $plugin;
            $action = 'enable';
        }

        update_option('auto_update_plugins', array_values(array_unique($auto_updates)));

        wp_redirect(self_admin_url('plugins.php?plugin_status=all&paged=' . (int) $_POST['paged']));
        exit;
    }

    /**
     * Add auto-update setting to plugin admin options
     */
    public function add_auto_update_admin_setting() {
        // Add this to your gateway's form fields
        $auto_update_field = array(
            'auto_updates' => array(
                'title'       => 'Auto Updates',
                'label'       => 'Enable automatic updates for this plugin',
                'type'        => 'checkbox',
                'description' => 'Automatically update the plugin when new versions are available.',
                'default'     => 'no',
                'desc_tip'    => true,
            )
        );
        
        return $auto_update_field;
    }

    /**
     * Check for Updates at the defined API endpoint and modify the update array.
     *
     * @param array $_transient_data Update array build by WordPress.
     * @return array Modified update array with custom plugin data.
     */
    public function check_for_update($_transient_data) {
        global $pagenow;

        if (!is_object($_transient_data)) {
            $_transient_data = new stdClass;
        }

        if (!empty($_transient_data->response) && !empty($_transient_data->response[$this->name]) && false === $this->wp_override) {
            return $_transient_data;
        }

        $current = $this->get_version_info();

        if (false !== $current && is_object($current) && isset($current->new_version)) {
            if (version_compare($this->version, $current->new_version, '<')) {
                $_transient_data->response[$this->name] = $current;
                
                // Set transient for admin notice
                set_transient('quxpay_update_available', array(
                    'new_version' => $current->new_version,
                    'current_version' => $this->version
                ), DAY_IN_SECONDS);
            } else {
                // No update needed, clear the notice transient
                delete_transient('quxpay_update_available');
            }

            $_transient_data->last_checked = current_time('timestamp');
            $_transient_data->checked[$this->name] = $this->version;
        }

        return $_transient_data;
    }

    /**
     * Get version information from the remote API.
     *
     * @return object|false
     */
    public function get_version_info() {
        $cached = get_transient($this->cache_key);

        if (false === $cached || (defined('WP_DEBUG') && WP_DEBUG)) {
            $api_request_args = array(
                'slug' => $this->slug,
                'plugin_name' => $this->api_data['item_name'],
                'version' => $this->version,
                'author' => $this->api_data['author'],
                'url' => home_url(),
                'beta' => $this->beta
            );

            // Use correct endpoint URL based on your controller
            $api_endpoint = rtrim($this->api_url, '/') . '/check-version';
            $request = wp_remote_post($api_endpoint, array(
                'timeout' => 15,
                'sslverify' => true, // Changed to true for better security
                'body' => $api_request_args,
                'headers' => array(
                    'Accept' => 'application/json',
                    'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
                )
            ));

            if (!is_wp_error($request) && wp_remote_retrieve_response_code($request) === 200) {
                $body = wp_remote_retrieve_body($request);
                $body = json_decode($body, true);
                
                if (is_array($body) && isset($body['new_version'])) {
                    $version_info = new stdClass();
                    $version_info->new_version = $body['new_version'];
                    $version_info->stable_version = $body['stable_version'] ?? $body['new_version'];
                    $version_info->slug = $this->slug;
                    $version_info->plugin = $this->name;
                    $version_info->url = isset($body['homepage']) ? $body['homepage'] : '';
                    $version_info->package = isset($body['package']) ? $body['package'] : '';
                    $version_info->tested = isset($body['tested']) ? $body['tested'] : '';
                    $version_info->requires_php = isset($body['requires_php']) ? $body['requires_php'] : '';
                    $version_info->compatibility = isset($body['compatibility']) ? $body['compatibility'] : array();

                    // Add changelog and upgrade notice
                    if (isset($body['sections'])) {
                        $version_info->sections = $body['sections'];
                    }
                    
                    if (isset($body['upgrade_notice'])) {
                        $version_info->upgrade_notice = $body['upgrade_notice'];
                    }

                    if (isset($body['banners'])) {
                        $version_info->banners = $body['banners'];
                    }

                    if (isset($body['icons'])) {
                        $version_info->icons = $body['icons'];
                    }

                    // Cache for 12 hours
                    set_transient($this->cache_key, $version_info, 12 * HOUR_IN_SECONDS);
                    
                    return $version_info;
                } else {
                    // Invalid response format
                    error_log('QuxPay Updater: Invalid response format from update server - ' . wp_remote_retrieve_body($request));
                    return false;
                }
            } else {
                // API request failed
                if (is_wp_error($request)) {
                    error_log('QuxPay Updater: ' . $request->get_error_message());
                } else {
                    error_log('QuxPay Updater: HTTP ' . wp_remote_retrieve_response_code($request) . ' - ' . wp_remote_retrieve_body($request));
                }
                return false;
            }
        }

        return $cached;
    }

    /**
     * Updates information on the "View version x.x details" page with custom data.
     *
     * @param mixed $_data
     * @param string $_action
     * @param object $_args
     * @return object $_data
     */
    public function plugins_api_filter($_data, $_action = '', $_args = null) {
        if ($_action != 'plugin_information') {
            return $_data;
        }

        if (!isset($_args->slug) || ($_args->slug != $this->slug)) {
            return $_data;
        }

        $to_send = array(
            'slug' => $this->slug,
            'plugin_name' => $this->api_data['item_name'],
            'version' => $this->version,
            'author' => $this->api_data['author'],
            'url' => home_url(),
            'beta' => $this->beta
        );

        // Use correct endpoint URL
        $api_endpoint = rtrim($this->api_url, '/') . '/plugin-information';

        $api_response = wp_remote_post($api_endpoint, array(
            'timeout' => 15,
            'sslverify' => true,
            'body' => $to_send,
            'headers' => array(
                'Accept' => 'application/json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
            )
        ));

        if (!is_wp_error($api_response) && wp_remote_retrieve_response_code($api_response) === 200) {
            $body = wp_remote_retrieve_body($api_response);
            $body = json_decode($body, true);
            
            if (is_array($body)) {
                $_data = new stdClass();
                $_data->name = isset($body['name']) ? $body['name'] : $this->api_data['item_name'];
                $_data->slug = $this->slug;
                $_data->plugin = $this->name;
                $_data->version = isset($body['version']) ? $body['version'] : $this->version;
                $_data->author = isset($body['author']) ? $body['author'] : $this->api_data['author'];
                $_data->author_profile = isset($body['author_profile']) ? $body['author_profile'] : '';
                $_data->requires = isset($body['requires']) ? $body['requires'] : '';
                $_data->tested = isset($body['tested']) ? $body['tested'] : '';
                $_data->requires_php = isset($body['requires_php']) ? $body['requires_php'] : '';
                $_data->rating = isset($body['rating']) ? $body['rating'] : 0;
                $_data->num_ratings = isset($body['num_ratings']) ? $body['num_ratings'] : 0;
                $_data->support_threads = isset($body['support_threads']) ? $body['support_threads'] : 0;
                $_data->support_threads_resolved = isset($body['support_threads_resolved']) ? $body['support_threads_resolved'] : 0;
                $_data->downloaded = isset($body['downloaded']) ? $body['downloaded'] : 0;
                $_data->active_installs = isset($body['active_installs']) ? $body['active_installs'] : 0;
                $_data->last_updated = isset($body['last_updated']) ? $body['last_updated'] : '';
                $_data->added = isset($body['added']) ? $body['added'] : '';
                $_data->homepage = isset($body['homepage']) ? $body['homepage'] : $this->api_data['url'];
                $_data->download_link = isset($body['download_link']) ? $body['download_link'] : '';

                if (isset($body['sections'])) {
                    $_data->sections = $body['sections'];
                }

                if (isset($body['banners'])) {
                    $_data->banners = $body['banners'];
                }

                if (isset($body['icons'])) {
                    $_data->icons = $body['icons'];
                }

                if (isset($body['contributors'])) {
                    $_data->contributors = $body['contributors'];
                }
            }
        }

        return $_data;
    }

    /**
     * Show the update notification on the plugin row.
     *
     * @param string $file
     * @param array $plugin
     */
    public function show_update_notification($file, $plugin) {
        if (is_network_admin()) {
            return;
        }

        if (!current_user_can('update_plugins')) {
            return;
        }

        if ($this->name != $file) {
            return;
        }

        // Remove our filter on the site transient
        remove_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));

        $update_cache = get_site_transient('update_plugins');
        $update_cache = is_object($update_cache) ? $update_cache : new stdClass();

        if (empty($update_cache->response) || empty($update_cache->response[$this->name])) {
            $version_info = $this->get_version_info();
            if (false !== $version_info && is_object($version_info) && isset($version_info->new_version)) {
                if (version_compare($this->version, $version_info->new_version, '<')) {
                    $update_cache->response[$this->name] = $version_info;
                }
            }
        }

        // Restore our filter
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));

        if (!empty($update_cache->response[$this->name])) {
            $update_obj = $update_cache->response[$this->name];
            echo '<tr class="plugin-update-tr" id="' . $this->slug . '-update" data-slug="' . $this->slug . '" data-plugin="' . $this->slug . '/' . $file . '">';
            echo '<td colspan="3" class="plugin-update colspanchange">';
            echo '<div class="update-message notice inline notice-warning notice-alt">';
            echo '<p>';
            printf(
                __('There is a new version of %1$s available. %2$sView version %3$s details%4$s or %5$supdate now%6$s.'),
                esc_html($plugin['Name']),
                '<a target="_blank" class="thickbox open-plugin-details-modal" href="' . esc_url(wp_nonce_url(self_admin_url('plugin-install.php?tab=plugin-information&plugin=' . urlencode($this->slug) . '&section=changelog'), 'install-plugin_' . $this->slug)) . '&TB_iframe=true&width=772&height=884">',
                esc_html($update_obj->new_version),
                '</a>',
                '<a href="' . esc_url(wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=') . $this->name, 'upgrade-plugin_' . $this->name)) . '">',
                '</a>'
            );
            echo '</p></div></td></tr>';
        }
    }

    /**
     * Show the changelog popup.
     */
    public function show_changelog() {
        global $tab, $pagenow;

        if (!empty($_REQUEST['quxpay_tab'])) {
            if ($_REQUEST['quxpay_tab'] == 'changelog') {
                $this->display_changelog();
                exit;
            }
        }
    }

    /**
     * Display the changelog.
     */
    private function display_changelog() {
        $version_info = $this->get_version_info();
        
        if (false === $version_info || !isset($version_info->sections['changelog'])) {
            wp_die(__('Could not retrieve changelog information.', 'quxpay'));
        }

        echo '<div style="background: #fff; padding: 1em; margin: 0;">';
        echo '<h2>' . esc_html($this->api_data['item_name']) . ' Changelog</h2>';
        echo '<div>' . wp_kses_post($version_info->sections['changelog']) . '</div>';
        echo '</div>';
    }

    /**
     * Add links to the plugin row meta.
     *
     * @param array $links
     * @param string $file
     * @return array
     */
    public function plugin_row_meta($links, $file) {
        if ($file != $this->name) {
            return $links;
        }

        // Add View Details link (always visible)
        $details_link = add_query_arg(array(
            'tab' => 'plugin-information',
            'plugin' => urlencode($this->slug),
            'TB_iframe' => 'true',
            'width' => '772',
            'height' => '884'
        ), admin_url('plugin-install.php'));

        $links[] = '<a href="' . esc_url($details_link) . '" class="thickbox open-plugin-details-modal" aria-label="' . esc_attr(sprintf(__('More information about %s'), $this->api_data['item_name'])) . '">' . __('View details', 'quxpay') . '</a>';

        // Add changelog link
        // $changelog_link = add_query_arg(array(
        //     'quxpay_tab' => 'changelog',
        //     'plugin' => urlencode($this->name),
        //     'TB_iframe' => 'true',
        //     'width' => '772',
        //     'height' => '884'
        // ), admin_url('plugin-install.php'));

        // $links[] = '<a href="' . esc_url($changelog_link) . '" class="thickbox">' . __('View Changelog', 'quxpay') . '</a>';

        return $links;
    }

    /**
     * Handle post-update actions.
     *
     * @param WP_Upgrader $upgrader
     * @param array $options
     */
    public function after_update($upgrader, $options) {
        if ($options['action'] == 'update' && $options['type'] == 'plugin') {
            // Clear the version info cache
            delete_transient($this->cache_key);
            
            // Check if our plugin was updated
            if (isset($options['plugins']) && in_array($this->name, $options['plugins'])) {
                // Set a transient to show success message
                set_transient('quxpay_updated_successfully', $this->version, 300);
            }
        }
    }

    /**
     * Get cache key for debugging
     */
    public function get_cache_key() {
        return $this->cache_key;
    }
}

/**
 * Helper function to get plugin updater instance
 */
function quxpay_get_updater_instance() {
    static $instance = null;
    
    if ($instance === null) {
        $instance = new QuxPay_Plugin_Updater(
            QUXPAY_UPDATE_SERVER,
            QUXPAY_PLUGIN_FILE,
            array(
                'version' => QUXPAY_VERSION,
                'item_name' => 'Qux Payment Gateway For WooCommerce',
                'author' => 'Qux',
                'url' => home_url(),
                'beta' => false
            )
        );
    }
    
    return $instance;
}

/**
 * Manual update check function (for AJAX calls)
 */
function quxpay_manual_update_check() {
    $updater = quxpay_get_updater_instance();
    
    // Clear cache to force fresh check
    delete_transient($updater->get_cache_key());
    
    // Get fresh version info
    $version_info = $updater->get_version_info();
    
    if (false === $version_info) {
        return array(
            'error' => true,
            'message' => 'Could not connect to update server'
        );
    }
    
    $update_available = version_compare(QUXPAY_VERSION, $version_info->new_version, '<');
    
    return array(
        'update_available' => $update_available,
        'current_version' => QUXPAY_VERSION,
        'new_version' => $version_info->new_version,
        'error' => false
    );
}
