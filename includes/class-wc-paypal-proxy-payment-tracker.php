<?php
/**
 * WooCommerce PayPal Proxy Payment Tracker - COMPLETELY REVISED
 *
 * @package WC_PayPal_Proxy_Client
 */

defined('ABSPATH') || exit;

/**
 * WC_PayPal_Proxy_Payment_Tracker Class
 * Tracks payment amounts and handles proxy rotation based on payment caps.
 */
class WC_PayPal_Proxy_Payment_Tracker {

    /**
     * Payment data option name in the database
     */
    const OPTION_NAME = 'wc_paypal_proxy_payment_data';

    /**
     * Direct action URL for AJAX operations
     */
    const AJAX_ACTION = 'paypal_proxy_ajax_action';

    /**
     * Singleton instance
     *
     * @var WC_PayPal_Proxy_Payment_Tracker
     */
    private static $instance = null;

    /**
     * Current payment data
     *
     * @var array
     */
    public $payment_data;

    /**
     * Gateway settings cache
     * 
     * @var array
     */
    private $gateway_settings = null;

    /**
     * Get singleton instance
     *
     * @return WC_PayPal_Proxy_Payment_Tracker
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_payment_data();
        
        // Add hooks
        add_action('woocommerce_paypal_proxy_payment_complete', array($this, 'add_payment'), 10, 2);
        add_action('admin_post_reset_paypal_proxy_payments', array($this, 'reset_payments'));
        add_action('admin_post_reset_proxy_site_payments', array($this, 'reset_proxy_site_payments'));
        add_action('admin_post_select_proxy_site', array($this, 'select_proxy_site'));
        add_action('admin_notices', array($this, 'display_cap_notices'));
        
        // Add AJAX handler for direct selection
        add_action('wp_ajax_' . self::AJAX_ACTION, array($this, 'handle_ajax'));
        
        // Add admin scripts for direct AJAX selection
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    /**
     * Enqueue admin scripts for AJAX operations
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our settings page
        if ('woocommerce_page_wc-settings' !== $hook) {
            return;
        }
        
        // Ensure jQuery is loaded
        wp_enqueue_script('jquery');
        
        // Add our custom script
        wp_enqueue_script(
            'paypal-proxy-admin',
            WC_PAYPAL_PROXY_CLIENT_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            WC_PAYPAL_PROXY_CLIENT_VERSION,
            true
        );
        
        // Add data for the script
        wp_localize_script(
            'paypal-proxy-admin',
            'paypalProxyData',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'action' => self::AJAX_ACTION,
                'nonce' => wp_create_nonce(self::AJAX_ACTION),
                'current_section' => isset($_GET['section']) ? sanitize_text_field($_GET['section']) : '',
                'debug_info' => $this->get_debug_info(),
            )
        );
    }
    
    /**
     * Get debug information
     */
    private function get_debug_info() {
        $debug = array(
            'current_index' => $this->payment_data['current_proxy_index'],
            'proxy_count' => count($this->get_proxy_urls()),
            'proxies' => array(),
        );
        
        $proxy_urls = $this->get_proxy_urls();
        foreach ($proxy_urls as $index => $proxy) {
            $debug['proxies'][] = array(
                'index' => $index,
                'url' => $proxy['url'],
            );
        }
        
        return $debug;
    }
    
    /**
     * Handle AJAX requests
     */
    public function handle_ajax() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], self::AJAX_ACTION)) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Get action type
        $action_type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        
        if ($action_type === 'select_proxy') {
            // Get proxy index
            $proxy_index = isset($_POST['proxy_index']) ? intval($_POST['proxy_index']) : 0;
            
            // Validate index
            $proxy_urls = $this->get_proxy_urls();
            if ($proxy_index < 0 || $proxy_index >= count($proxy_urls)) {
                wp_send_json_error(array('message' => 'Invalid proxy index'));
                return;
            }
            
            // Update the current proxy
            $old_index = $this->payment_data['current_proxy_index'];
            $this->payment_data['current_proxy_index'] = $proxy_index;
            
            // Add to history
            $this->payment_data['history'][] = array(
                'type' => 'manual_selection',
                'date' => current_time('mysql'),
                'proxy_index' => $proxy_index,
                'from_index' => $old_index,
                'proxy_url' => $proxy_urls[$proxy_index]['url']
            );
            
            // Limit history
            if (count($this->payment_data['history']) > 20) {
                $this->payment_data['history'] = array_slice($this->payment_data['history'], -20);
            }
            
            // Save changes
            $this->save_payment_data();
            
            // Send success response
            wp_send_json_success(array(
                'message' => 'Proxy selection updated successfully',
                'new_index' => $proxy_index,
                'current_url' => $proxy_urls[$proxy_index]['url'],
                'debug' => $this->get_debug_info(),
            ));
        } elseif ($action_type === 'reset_proxy') {
            // Get proxy ID
            $proxy_id = isset($_POST['proxy_id']) ? sanitize_text_field($_POST['proxy_id']) : '';
            
            // Validate proxy ID
            if (empty($proxy_id) || !isset($this->payment_data['proxy_amounts'][$proxy_id])) {
                wp_send_json_error(array('message' => 'Invalid proxy ID'));
                return;
            }
            
            // Get proxy URL for history
            $proxy_url = $this->payment_data['proxy_amounts'][$proxy_id]['url'];
            
            // Reset this proxy's data
            $this->payment_data['proxy_amounts'][$proxy_id]['amount'] = 0.00;
            $this->payment_data['proxy_amounts'][$proxy_id]['cap_reached'] = false;
            
            // Recalculate total collected
            $total = 0.00;
            foreach ($this->payment_data['proxy_amounts'] as $p_id => $data) {
                $total += $data['amount'];
            }
            $this->payment_data['total_collected'] = $total;
            
            // Check if any proxy is still at cap
            $any_cap_reached = false;
            foreach ($this->payment_data['proxy_amounts'] as $p_id => $data) {
                if ($data['cap_reached']) {
                    $any_cap_reached = true;
                    break;
                }
            }
            $this->payment_data['cap_reached'] = $any_cap_reached;
            
            // Add to history
            $this->payment_data['history'][] = array(
                'type' => 'reset_proxy',
                'date' => current_time('mysql'),
                'proxy_url' => $proxy_url,
            );
            
            // Save changes
            $this->save_payment_data();
            
            // Send success response
            wp_send_json_success(array(
                'message' => 'Proxy reset successfully',
                'debug' => $this->get_debug_info(),
            ));
        } elseif ($action_type === 'debug_info') {
            // Return current state information for debugging
            wp_send_json_success(array(
                'payment_data' => $this->payment_data,
                'proxy_urls' => $this->get_proxy_urls(),
                'debug' => $this->get_debug_info(),
            ));
        }
        
        // Invalid action type
        wp_send_json_error(array('message' => 'Invalid action type'));
    }

    /**
     * Load payment data from database
     */
    private function load_payment_data() {
        $default_data = array(
            'current_proxy_index' => 0,
            'total_collected' => 0.00,
            'history' => array(),
            'cap_reached' => false,
            'proxy_amounts' => array(),
        );
        
        $this->payment_data = get_option(self::OPTION_NAME, $default_data);
        
        // Ensure all default fields exist
        $this->payment_data = wp_parse_args($this->payment_data, $default_data);
        
        // Limit history size
        if (isset($this->payment_data['history']) && count($this->payment_data['history']) > 20) {
            $this->payment_data['history'] = array_slice($this->payment_data['history'], -20);
        }
        
        // Make sure proxy_amounts exists and has entries for all proxies
        if (!isset($this->payment_data['proxy_amounts']) || !is_array($this->payment_data['proxy_amounts'])) {
            $this->payment_data['proxy_amounts'] = array();
        }
        
        // Initialize proxy amounts for all configured proxies if not already set
        $proxy_urls = $this->get_proxy_urls();
        foreach ($proxy_urls as $index => $proxy) {
            $proxy_id = $this->get_proxy_id($proxy['url']);
            if (!isset($this->payment_data['proxy_amounts'][$proxy_id])) {
                $this->payment_data['proxy_amounts'][$proxy_id] = array(
                    'url' => $proxy['url'],
                    'amount' => 0.00,
                    'cap_reached' => false,
                );
            }
            
            // Ensure URL is updated if it changed in settings
            $this->payment_data['proxy_amounts'][$proxy_id]['url'] = $proxy['url'];
        }
        
        // Validate current_proxy_index is within bounds
        if ($this->payment_data['current_proxy_index'] >= count($proxy_urls)) {
            $this->payment_data['current_proxy_index'] = 0;
        }
        
        update_option(self::OPTION_NAME, $this->payment_data);
    }

    /**
     * Generate a unique ID for a proxy URL
     * 
     * @param string $url Proxy URL
     * @return string
     */
    private function get_proxy_id($url) {
        // Create a simple hash based on the URL
        return 'proxy_' . md5($url);
    }

    /**
     * Save payment data to database
     */
    private function save_payment_data() {
        update_option(self::OPTION_NAME, $this->payment_data);
    }

    /**
     * Add payment to the tracker
     *
     * @param float  $amount   Payment amount.
     * @param string $order_id Order ID.
     */
    public function add_payment($amount, $order_id) {
        // Get current proxy URL
        $current_proxy_url = $this->get_current_proxy_url();
        $proxy_id = $this->get_proxy_id($current_proxy_url);
        
        // Add to total collected
        $this->payment_data['total_collected'] += floatval($amount);
        
        // Add to proxy-specific amount
        if (!isset($this->payment_data['proxy_amounts'][$proxy_id])) {
            $this->payment_data['proxy_amounts'][$proxy_id] = array(
                'url' => $current_proxy_url,
                'amount' => 0.00,
                'cap_reached' => false,
            );
        }
        $this->payment_data['proxy_amounts'][$proxy_id]['amount'] += floatval($amount);
        
        // Add to history
        $this->payment_data['history'][] = array(
            'order_id' => $order_id,
            'amount' => $amount,
            'date' => current_time('mysql'),
            'proxy_url' => $current_proxy_url,
        );
        
        // Limit history to last 20 entries
        if (count($this->payment_data['history']) > 20) {
            $this->payment_data['history'] = array_slice($this->payment_data['history'], -20);
        }
        
        // Check if proxy-specific cap is reached
        $cap_limit = $this->get_payment_cap();
        if ($cap_limit > 0 && $this->payment_data['proxy_amounts'][$proxy_id]['amount'] >= $cap_limit) {
            $this->payment_data['proxy_amounts'][$proxy_id]['cap_reached'] = true;
            
            // Also update global cap reached flag
            $this->payment_data['cap_reached'] = true;
            
            // Try to rotate to the next proxy
            $this->try_rotate_proxy();
        }
        
        $this->save_payment_data();
    }

    /**
     * Reset all payments
     */
    public function reset_payments() {
        // Verify nonce for security
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'reset_paypal_proxy_payments')) {
            wp_die('Security check failed');
        }
        
        // Reset total data
        $this->payment_data['total_collected'] = 0.00;
        $this->payment_data['cap_reached'] = false;
        
        // Reset all proxy-specific amounts
        foreach ($this->payment_data['proxy_amounts'] as $proxy_id => $data) {
            $this->payment_data['proxy_amounts'][$proxy_id]['amount'] = 0.00;
            $this->payment_data['proxy_amounts'][$proxy_id]['cap_reached'] = false;
        }
        
        // Add to history
        $this->payment_data['history'][] = array(
            'type' => 'reset_all',
            'date' => current_time('mysql'),
        );
        
        // Limit history
        if (count($this->payment_data['history']) > 20) {
            $this->payment_data['history'] = array_slice($this->payment_data['history'], -20);
        }
        
        $this->save_payment_data();
        
        // Redirect back to settings
        wp_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=paypal_proxy&reset=success'));
        exit;
    }
    
    /**
     * Reset payments for a specific proxy site
     */
    public function reset_proxy_site_payments() {
        // Verify nonce for security
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'reset_proxy_site_payments')) {
            wp_die('Security check failed');
        }
        
        // Get proxy ID from POST
        $proxy_id = isset($_POST['proxy_id']) ? sanitize_text_field($_POST['proxy_id']) : '';
        
        if (empty($proxy_id) || !isset($this->payment_data['proxy_amounts'][$proxy_id])) {
            wp_die('Invalid proxy selected');
        }
        
        // Store the proxy URL for the history
        $proxy_url = $this->payment_data['proxy_amounts'][$proxy_id]['url'];
        
        // Reset this proxy's data
        $this->payment_data['proxy_amounts'][$proxy_id]['amount'] = 0.00;
        $this->payment_data['proxy_amounts'][$proxy_id]['cap_reached'] = false;
        
        // Recalculate total collected from all proxies
        $total = 0.00;
        foreach ($this->payment_data['proxy_amounts'] as $p_id => $data) {
            $total += $data['amount'];
        }
        $this->payment_data['total_collected'] = $total;
        
        // Check if any proxy has reached its cap
        $any_cap_reached = false;
        foreach ($this->payment_data['proxy_amounts'] as $p_id => $data) {
            if ($data['cap_reached']) {
                $any_cap_reached = true;
                break;
            }
        }
        $this->payment_data['cap_reached'] = $any_cap_reached;
        
        // Add to history
        $this->payment_data['history'][] = array(
            'type' => 'reset_proxy',
            'date' => current_time('mysql'),
            'proxy_url' => $proxy_url,
        );
        
        // Limit history
        if (count($this->payment_data['history']) > 20) {
            $this->payment_data['history'] = array_slice($this->payment_data['history'], -20);
        }
        
        $this->save_payment_data();
        
        // Redirect back to settings
        wp_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=paypal_proxy&reset_proxy=success'));
        exit;
    }
    
    /**
     * Manually select a proxy site
     */
    public function select_proxy_site() {
        // Verify nonce for security
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'select_proxy_site')) {
            wp_die('Security check failed');
        }
        
        // Get proxy index from POST
        $proxy_index = isset($_POST['proxy_index']) ? intval($_POST['proxy_index']) : 0;
        
        // Validate the proxy index
        $proxy_urls = $this->get_proxy_urls();
        if ($proxy_index < 0 || $proxy_index >= count($proxy_urls)) {
            wp_die('Invalid proxy index');
        }
        
        // Set the current proxy index
        $this->payment_data['current_proxy_index'] = $proxy_index;
        
        // Add to history
        $this->payment_data['history'][] = array(
            'type' => 'manual_selection',
            'date' => current_time('mysql'),
            'proxy_index' => $proxy_index,
            'proxy_url' => $proxy_urls[$proxy_index]['url']
        );
        
        // Limit history
        if (count($this->payment_data['history']) > 20) {
            $this->payment_data['history'] = array_slice($this->payment_data['history'], -20);
        }
        
        $this->save_payment_data();
        
        // Redirect back to settings
        wp_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=paypal_proxy&select_proxy=success'));
        exit;
    }

    /**
     * Try to rotate to the next available proxy
     */
    private function try_rotate_proxy() {
        // Get proxy URLs list
        $proxy_urls = $this->get_proxy_urls();
        if (count($proxy_urls) <= 1) {
            // No alternative proxies, just return
            return;
        }
        
        // Start from the current proxy index
        $current = $this->payment_data['current_proxy_index'];
        $found_available = false;
        
        // Try to find an available proxy (not at cap)
        for ($i = 1; $i < count($proxy_urls); $i++) {
            $next_index = ($current + $i) % count($proxy_urls);
            $next_url = $proxy_urls[$next_index]['url'];
            $next_proxy_id = $this->get_proxy_id($next_url);
            
            // Check if this proxy is available (not at cap)
            if (isset($this->payment_data['proxy_amounts'][$next_proxy_id]) && 
                !$this->payment_data['proxy_amounts'][$next_proxy_id]['cap_reached']) {
                // Found an available proxy
                $this->payment_data['current_proxy_index'] = $next_index;
                $found_available = true;
                
                // Add to history
                $this->payment_data['history'][] = array(
                    'type' => 'rotation',
                    'from' => $current,
                    'to' => $next_index,
                    'date' => current_time('mysql'),
                );
                
                break;
            }
        }
        
        // If no available proxy found, just stay on the current one
        if (!$found_available) {
            // Add to history that we tried but couldn't rotate
            $this->payment_data['history'][] = array(
                'type' => 'rotation_failed',
                'date' => current_time('mysql'),
                'message' => 'No available proxies found',
            );
        }
        
        // Limit history
        if (count($this->payment_data['history']) > 20) {
            $this->payment_data['history'] = array_slice($this->payment_data['history'], -20);
        }
        
        $this->save_payment_data();
    }

    /**
     * Check if payment cap is reached for the current proxy
     *
     * @return bool
     */
    public function is_cap_reached() {
        // Get current proxy
        $current_proxy_url = $this->get_current_proxy_url();
        $proxy_id = $this->get_proxy_id($current_proxy_url);
        
        // Check if this specific proxy has reached its cap
        if (isset($this->payment_data['proxy_amounts'][$proxy_id])) {
            return $this->payment_data['proxy_amounts'][$proxy_id]['cap_reached'];
        }
        
        // Fallback to global cap reached flag
        return $this->payment_data['cap_reached'];
    }

    /**
     * Get current proxy URL
     *
     * @return string
     */
    public function get_current_proxy_url() {
        $proxy_urls = $this->get_proxy_urls();
        $index = $this->payment_data['current_proxy_index'];
        
        if (isset($proxy_urls[$index])) {
            return $proxy_urls[$index]['url'];
        }
        
        // Fallback to default
        return $this->get_gateway_setting('proxy_url', WC_PAYPAL_PROXY_CLIENT_HANDLER_URL);
    }

    /**
     * Get current proxy API key
     *
     * @return string
     */
    public function get_current_api_key() {
        $proxy_urls = $this->get_proxy_urls();
        $index = $this->payment_data['current_proxy_index'];
        
        if (isset($proxy_urls[$index])) {
            return $proxy_urls[$index]['api_key'];
        }
        
        // Fallback to gateway setting
        return $this->get_gateway_setting('api_key');
    }

    /**
     * Get total collected amount
     *
     * @return float
     */
    public function get_total_collected() {
        return floatval($this->payment_data['total_collected']);
    }
    
    /**
     * Get collected amount for a specific proxy
     *
     * @param string $proxy_url Proxy URL
     * @return float
     */
    public function get_proxy_collected($proxy_url) {
        $proxy_id = $this->get_proxy_id($proxy_url);
        
        if (isset($this->payment_data['proxy_amounts'][$proxy_id])) {
            return floatval($this->payment_data['proxy_amounts'][$proxy_id]['amount']);
        }
        
        return 0.00;
    }
    
    /**
     * Check if proxy is at cap
     * 
     * @param string $proxy_url Proxy URL
     * @return bool
     */
    public function is_proxy_at_cap($proxy_url) {
        $proxy_id = $this->get_proxy_id($proxy_url);
        
        if (isset($this->payment_data['proxy_amounts'][$proxy_id])) {
            return $this->payment_data['proxy_amounts'][$proxy_id]['cap_reached'];
        }
        
        return false;
    }

    /**
     * Get payment cap amount
     *
     * @return float
     */
    public function get_payment_cap() {
        return floatval($this->get_gateway_setting('payment_cap', 0));
    }
    
    /**
     * Get gateway setting without creating a new gateway instance
     * 
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed
     */
    private function get_gateway_setting($key, $default = '') {
        // Cache gateway settings to avoid repeated DB queries
        if ($this->gateway_settings === null) {
            $this->gateway_settings = get_option('woocommerce_paypal_proxy_settings', array());
        }
        
        return isset($this->gateway_settings[$key]) ? $this->gateway_settings[$key] : $default;
    }

    /**
     * Get configured proxy URLs
     *
     * @return array
     */
    public function get_proxy_urls() {
        $urls = array();
        
        // Main proxy is always included
        $urls[] = array(
            'url' => $this->get_gateway_setting('proxy_url', WC_PAYPAL_PROXY_CLIENT_HANDLER_URL),
            'api_key' => $this->get_gateway_setting('api_key'),
        );
        
        // Parse additional proxies
        $proxy_list = $this->get_gateway_setting('proxy_urls', '');
        if (!empty($proxy_list)) {
            $lines = explode("\n", $proxy_list);
            foreach ($lines as $line) {
                $parts = explode('|', $line);
                if (count($parts) >= 2) {
                    $url = trim($parts[0]);
                    $key = trim($parts[1]);
                    if (!empty($url) && !empty($key)) {
                        $urls[] = array(
                            'url' => $url,
                            'api_key' => $key,
                        );
                    }
                }
            }
        }
        
        return $urls;
    }

    /**
     * Display admin notices for cap status
     */
    public function display_cap_notices() {
        global $current_section, $current_tab;
        
        // Only show on our settings page
        if (!is_admin() || $current_tab !== 'checkout' || $current_section !== 'paypal_proxy') {
            return;
        }
        
        // Show reset success message
        if (isset($_GET['reset']) && $_GET['reset'] === 'success') {
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 esc_html__('PayPal Proxy payment counter has been reset successfully.', 'wc-paypal-proxy-client') . 
                 '</p></div>';
        }
        
        // Show proxy reset success message
        if (isset($_GET['reset_proxy']) && $_GET['reset_proxy'] === 'success') {
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 esc_html__('Individual proxy payment counter has been reset successfully.', 'wc-paypal-proxy-client') . 
                 '</p></div>';
        }
        
        // Show proxy selection success message
        if (isset($_GET['select_proxy']) && $_GET['select_proxy'] === 'success') {
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 esc_html__('Active proxy has been changed successfully.', 'wc-paypal-proxy-client') . 
                 '</p></div>';
        }
        
        // Debug information - only show if explicitly requested
        if (isset($_GET['show_debug']) && $_GET['show_debug'] === 'yes' && current_user_can('manage_options')) {
            echo '<div class="notice notice-info"><p><strong>' . esc_html__('Debug Info:', 'wc-paypal-proxy-client') . '</strong></p>';
            echo '<pre>' . esc_html(print_r($this->get_debug_info(), true)) . '</pre>';
            echo '</div>';
        }
        
        // Show warning if cap is reached for the current proxy
        $cap_limit = $this->get_payment_cap();
        if ($cap_limit > 0 && $this->is_cap_reached()) {
            echo '<div class="notice notice-warning"><p>' . 
                 sprintf(
                     esc_html__('PayPal Proxy payment cap of %s has been reached for the current proxy. Using proxy #%d.', 'wc-paypal-proxy-client'),
                     wc_price($cap_limit),
                     $this->payment_data['current_proxy_index'] + 1
                 ) . 
                 '</p></div>';
        }
    }
}

// Initialize the tracker
WC_PayPal_Proxy_Payment_Tracker::get_instance();