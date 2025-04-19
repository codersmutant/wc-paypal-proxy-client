<?php
/**
 * WooCommerce PayPal Proxy Gateway
 *
 * @package WC_PayPal_Proxy_Client
 */

defined('ABSPATH') || exit;

/**
 * WC_Gateway_PayPal_Proxy Class
 */
class WC_Gateway_PayPal_Proxy extends WC_Payment_Gateway {

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id                 = 'paypal_proxy';
        $this->icon               = apply_filters('woocommerce_paypal_proxy_icon', WC_PAYPAL_PROXY_CLIENT_PLUGIN_URL . 'assets/images/paypal.svg');
        $this->has_fields         = false;
        $this->method_title       = __('PayPal via Proxy', 'wc-paypal-proxy-client');
        $this->method_description = __('Accept PayPal payments via a proxy site.', 'wc-paypal-proxy-client');
        $this->supports           = array(
            'products',
            'refunds',
        );

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title        = $this->get_option('title');
        $this->description  = $this->get_option('description');
        $this->enabled      = $this->get_option('enabled');
        $this->debug        = 'yes' === $this->get_option('debug', 'no');
        $this->proxy_url    = $this->get_option('proxy_url', WC_PAYPAL_PROXY_CLIENT_HANDLER_URL);
        $this->api_key      = $this->get_option('api_key');
        $this->iframe_height = $this->get_option('iframe_height', '450');
        $this->payment_cap  = $this->get_option('payment_cap', 0);
        
        // Get current proxy URL and API key from tracker if available
        // CHANGED: Only load tracker data after full initialization
        add_action('init', array($this, 'maybe_load_tracker_data'), 20);

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
    }

    /**
     * Load tracker data after initialization
     */
    public function maybe_load_tracker_data() {
        // Only run once and only if class exists
        static $done = false;
        if ($done || !class_exists('WC_PayPal_Proxy_Payment_Tracker')) {
            return;
        }

        $tracker = WC_PayPal_Proxy_Payment_Tracker::get_instance();
        
        // Update URL and API key
        $this->proxy_url = $tracker->get_current_proxy_url();
        $this->api_key = $tracker->get_current_api_key();
        
        // Check if payment cap reached - disable if necessary
        if ($tracker->is_cap_reached() && count($tracker->get_proxy_urls()) <= 1) {
            $this->enabled = 'no';
        }
        
        $done = true;
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Enable/Disable', 'wc-paypal-proxy-client'),
                'type'    => 'checkbox',
                'label'   => __('Enable PayPal via Proxy', 'wc-paypal-proxy-client'),
                'default' => 'yes'
            ),
            'title' => array(
                'title'       => __('Title', 'wc-paypal-proxy-client'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'wc-paypal-proxy-client'),
                'default'     => __('PayPal', 'wc-paypal-proxy-client'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'wc-paypal-proxy-client'),
                'type'        => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'wc-paypal-proxy-client'),
                'default'     => __('Pay via PayPal; you can pay with your credit card if you don\'t have a PayPal account.', 'wc-paypal-proxy-client'),
                'desc_tip'    => true,
            ),
            'proxy_url' => array(
                'title'       => __('Primary Proxy Site URL', 'wc-paypal-proxy-client'),
                'type'        => 'text',
                'description' => __('Enter the URL of the primary proxy site (Store B) that will handle PayPal payments.', 'wc-paypal-proxy-client'),
                'default'     => WC_PAYPAL_PROXY_CLIENT_HANDLER_URL,
                'desc_tip'    => true,
            ),
            'api_key' => array(
                'title'       => __('Primary API Key', 'wc-paypal-proxy-client'),
                'type'        => 'password',
                'description' => __('Enter the API key shared with the primary proxy site for secure communication.', 'wc-paypal-proxy-client'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'proxy_urls' => array(
                'title'       => __('Additional Proxy Sites', 'wc-paypal-proxy-client'),
                'type'        => 'textarea',
                'description' => __('Enter additional proxy sites for rotation, one per line in format: URL|API_KEY. Example: https://proxy2.example.com|api_key_here', 'wc-paypal-proxy-client'),
                'default'     => '',
                'desc_tip'    => false,
                'css'         => 'height: 100px;',
            ),
            'payment_cap' => array(
                'title'       => __('Payment Cap', 'wc-paypal-proxy-client'),
                'type'        => 'number',
                'description' => __('Maximum amount of payments to collect before rotating to next proxy. Set to 0 for no limit.', 'wc-paypal-proxy-client'),
                'default'     => '0',
                'desc_tip'    => true,
                'custom_attributes' => array(
                    'min'  => '0',
                    'step' => '0.01',
                ),
            ),
            'payment_stats' => array(
                'title'       => __('Payment Stats', 'wc-paypal-proxy-client'),
                'type'        => 'title',
                'description' => $this->get_payment_stats_html(),
            ),
            'iframe_height' => array(
                'title'       => __('iFrame Height', 'wc-paypal-proxy-client'),
                'type'        => 'text',
                'description' => __('Height of the PayPal button iframe in pixels.', 'wc-paypal-proxy-client'),
                'default'     => '450',
                'desc_tip'    => true,
            ),
            'debug' => array(
                'title'       => __('Debug Log', 'wc-paypal-proxy-client'),
                'type'        => 'checkbox',
                'label'       => __('Enable logging', 'wc-paypal-proxy-client'),
                'default'     => 'no',
                'description' => __('Log PayPal proxy events inside WC_PAYPAL_PROXY_CLIENT_PLUGIN_DIR/logs/', 'wc-paypal-proxy-client'),
            ),
        );
    }

   
/**
 * Generate HTML for payment stats
 * 
 * @return string
 */
private function get_payment_stats_html() {
    $html = '';
    
    if (class_exists('WC_PayPal_Proxy_Payment_Tracker')) {
        $tracker = WC_PayPal_Proxy_Payment_Tracker::get_instance();
        $total_collected = $tracker->get_total_collected();
        $payment_cap = $tracker->get_payment_cap();
        $current_proxy = $tracker->get_current_proxy_url();
        $proxy_index = isset($tracker->payment_data['current_proxy_index']) ? 
                      ($tracker->payment_data['current_proxy_index'] + 1) : 1;
        $proxy_urls = $tracker->get_proxy_urls();
        
        $html .= '<div class="payment-stats-container">';
        
        // Global payment stats
        $html .= '<div class="payment-stats-global">';
        $html .= '<h3>' . __('Global Payment Stats', 'wc-paypal-proxy-client') . '</h3>';
        $html .= '<p><strong>' . __('Current Active Proxy:', 'wc-paypal-proxy-client') . '</strong> #' . $proxy_index . ' - ' . esc_html($current_proxy) . '</p>';
        $html .= '<p><strong>' . __('Total Collected (All Proxies):', 'wc-paypal-proxy-client') . '</strong> ' . wc_price($total_collected) . '</p>';
        
        if ($payment_cap > 0) {
            $html .= '<p><strong>' . __('Payment Cap Per Proxy:', 'wc-paypal-proxy-client') . '</strong> ' . wc_price($payment_cap) . '</p>';
        }
        
        // Reset all button
        $html .= '<form method="post" action="' . admin_url('admin-post.php') . '">';
        $html .= '<input type="hidden" name="action" value="reset_paypal_proxy_payments">';
        $html .= wp_nonce_field('reset_paypal_proxy_payments', '_wpnonce', true, false);
        $html .= '<button type="submit" class="button button-secondary reset-payment-button">' . __('Reset All Payment Counters', 'wc-paypal-proxy-client') . '</button>';
        $html .= '</form>';
        
        // Debug info link (only visible to admins)
        if (current_user_can('manage_options')) {
            $html .= '<p class="debug-info-link"><a href="' . add_query_arg('show_debug', 'yes') . '">' . __('Show Debug Info', 'wc-paypal-proxy-client') . '</a></p>';
        }
        
        $html .= '</div>'; // End global stats
        
        // Individual proxy stats
        $html .= '<div class="payment-stats-proxies">';
        $html .= '<h3>' . __('Individual Proxy Stats', 'wc-paypal-proxy-client') . '</h3>';
        $html .= '<table class="widefat">';
        $html .= '<thead><tr>';
        $html .= '<th>' . __('Proxy', 'wc-paypal-proxy-client') . '</th>';
        $html .= '<th>' . __('URL', 'wc-paypal-proxy-client') . '</th>';
        $html .= '<th>' . __('Collected', 'wc-paypal-proxy-client') . '</th>';
        if ($payment_cap > 0) {
            $html .= '<th>' . __('Usage', 'wc-paypal-proxy-client') . '</th>';
        }
        $html .= '<th>' . __('Status', 'wc-paypal-proxy-client') . '</th>';
        $html .= '<th>' . __('Actions', 'wc-paypal-proxy-client') . '</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';
        
        // Add a row for each proxy
        foreach ($proxy_urls as $index => $proxy) {
            $proxy_url = $proxy['url'];
            $proxy_id = 'proxy_' . md5($proxy_url); // Same hash function as the tracker
            $is_current = ($index === $tracker->payment_data['current_proxy_index']);
            $proxy_collected = $tracker->get_proxy_collected($proxy_url);
            $is_at_cap = $tracker->is_proxy_at_cap($proxy_url);
            
            // Calculate percentage for progress bar
            $percentage = 0;
            if ($payment_cap > 0) {
                $percentage = min(100, round(($proxy_collected / $payment_cap) * 100));
            }
            
            // Row CSS class
            $row_class = $is_current ? 'current-proxy' : '';
            $row_class .= $is_at_cap ? ' at-cap' : '';
            
            $html .= '<tr class="' . esc_attr($row_class) . '">';
            $html .= '<td>#' . ($index + 1) . ($is_current ? ' <span class="current-badge">' . __('Active', 'wc-paypal-proxy-client') . '</span>' : '') . '</td>';
            $html .= '<td>' . esc_html($proxy_url) . '</td>';
            $html .= '<td>' . wc_price($proxy_collected) . '</td>';
            
            if ($payment_cap > 0) {
                // Progress bar
                $bar_class = $percentage >= 90 ? 'danger' : ($percentage >= 70 ? 'warning' : 'good');
                $html .= '<td>';
                $html .= '<div class="payment-cap-progress">';
                $html .= '<div class="payment-cap-bar ' . $bar_class . '" style="width: ' . $percentage . '%;"></div>';
                $html .= '</div>';
                $html .= $percentage . '%';
                $html .= '</td>';
            }
            
            $html .= '<td>';
            if ($is_at_cap) {
                $html .= '<span class="cap-status cap-reached">' . __('Cap Reached', 'wc-paypal-proxy-client') . '</span>';
            } else {
                $html .= '<span class="cap-status cap-available">' . __('Available', 'wc-paypal-proxy-client') . '</span>';
            }
            $html .= '</td>';
            
            // Actions for this proxy
            $html .= '<td class="proxy-actions">';
            
            // Regular form submission as fallback
            $html .= '<form method="post" action="' . admin_url('admin-post.php') . '" class="proxy-form">';
            $html .= '<input type="hidden" name="action" value="reset_proxy_site_payments">';
            $html .= '<input type="hidden" name="proxy_id" value="' . esc_attr($proxy_id) . '">';
            $html .= wp_nonce_field('reset_proxy_site_payments', '_wpnonce', true, false);
            $html .= '<button type="submit" class="button button-small reset-proxy-button" data-proxy-id="' . esc_attr($proxy_id) . '">' . __('Reset', 'wc-paypal-proxy-client') . '</button>';
            $html .= '</form>';
            
            // Select button - Only show for non-current proxies
            if (!$is_current) {
                $html .= '<form method="post" action="' . admin_url('admin-post.php') . '" class="proxy-form">';
                $html .= '<input type="hidden" name="action" value="select_proxy_site">';
                $html .= '<input type="hidden" name="proxy_index" value="' . esc_attr($index) . '">';
                $html .= wp_nonce_field('select_proxy_site', '_wpnonce', true, false);
                $html .= '<button type="submit" class="button button-small button-primary select-proxy-button" data-proxy-index="' . esc_attr($index) . '">' . __('Use This Proxy', 'wc-paypal-proxy-client') . '</button>';
                $html .= '</form>';
            }
            
            $html .= '</td>';
            
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        $html .= '</div>'; // End proxies stats
        
        // Let's add a small payment history section if there's history available
        if (!empty($tracker->payment_data['history'])) {
            $html .= '<div class="payment-history">';
            $html .= '<h3>' . __('Recent Activity', 'wc-paypal-proxy-client') . '</h3>';
            $html .= '<ul class="activity-list">';
            
            // Show the last 5 history items in reverse (newest first)
            $history = array_slice($tracker->payment_data['history'], -5);
            $history = array_reverse($history);
            
            foreach ($history as $entry) {
                $html .= '<li class="activity-item">';
                
                // Format the entry based on type
                if (isset($entry['type'])) {
                    switch ($entry['type']) {
                        case 'reset_all':
                            $html .= '<span class="activity-icon reset-icon">↺</span> <span class="activity-text">' . 
                                    __('All payment counters reset', 'wc-paypal-proxy-client') . 
                                    ' <span class="activity-date">(' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry['date'])) . ')</span>';
                            break;
                            
                        case 'reset_proxy':
                            $html .= '<span class="activity-icon reset-icon">↺</span> <span class="activity-text">' . 
                                    sprintf(__('Reset proxy %s', 'wc-paypal-proxy-client'), esc_html($entry['proxy_url'])) . 
                                    ' <span class="activity-date">(' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry['date'])) . ')</span>';
                            break;
                            
                        case 'rotation':
                            $html .= '<span class="activity-icon rotate-icon">↻</span> <span class="activity-text">' . 
                                    sprintf(__('Rotated from proxy #%d to proxy #%d', 'wc-paypal-proxy-client'), $entry['from'] + 1, $entry['to'] + 1) . 
                                    ' <span class="activity-date">(' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry['date'])) . ')</span>';
                            break;
                            
                        case 'manual_selection':
                            $html .= '<span class="activity-icon select-icon">👆</span> <span class="activity-text">' . 
                                    sprintf(__('Manually selected proxy #%d', 'wc-paypal-proxy-client'), $entry['proxy_index'] + 1) . 
                                    ' <span class="activity-date">(' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry['date'])) . ')</span>';
                            break;
                            
                        case 'rotation_failed':
                            $html .= '<span class="activity-icon error-icon">⚠️</span> <span class="activity-text">' . 
                                    __('Rotation failed: No available proxies', 'wc-paypal-proxy-client') . 
                                    ' <span class="activity-date">(' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry['date'])) . ')</span>';
                            break;
                    }
                } else {
                    // Payment entry
                    if (isset($entry['order_id']) && isset($entry['amount'])) {
                        $html .= '<span class="activity-icon payment-icon">💰</span> <span class="activity-text">' . 
                                sprintf(__('Payment of %s for order #%s', 'wc-paypal-proxy-client'), wc_price($entry['amount']), $entry['order_id']) . 
                                ' <span class="activity-date">(' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry['date'])) . ')</span>';
                    }
                }
                
                $html .= '</li>';
            }
            
            $html .= '</ul>';
            $html .= '</div>'; // End history section
        }
        
        $html .= '</div>'; // End stats container
    } else {
        $html .= '<p>' . __('Payment tracking not available.', 'wc-paypal-proxy-client') . '</p>';
    }
    
    return $html;
}

    /**
     * Process Payment
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        // Mark as pending (we're awaiting the payment)
        $order->update_status('pending', __('Awaiting PayPal payment', 'wc-paypal-proxy-client'));
        
        // Log the pending status
        $this->log('Payment pending for order #' . $order_id);
        
        // Redirect to the payment page
        return array(
            'result'   => 'success',
            'redirect' => $order->get_checkout_payment_url(true),
        );
    }

    /**
     * Output for the order received page.
     *
     * @param int $order_id Order ID.
     */
    public function receipt_page($order_id) {
        $order = wc_get_order($order_id);
        
        // Make sure tracker data is loaded
        $this->maybe_load_tracker_data();
        
        // Check payment cap if enabled
        if (class_exists('WC_PayPal_Proxy_Payment_Tracker')) {
            $tracker = WC_PayPal_Proxy_Payment_Tracker::get_instance();
            if ($tracker->is_cap_reached() && count($tracker->get_proxy_urls()) <= 1) {
                echo '<div class="woocommerce-error">' . 
                     __('PayPal payments are currently unavailable. Please choose another payment method.', 'wc-paypal-proxy-client') . 
                     '</div>';
                return;
            }
        }
        
        // Generate secure hash and nonce
        $nonce = wp_create_nonce('wc-paypal-proxy-' . $order_id);
        $hash  = hash_hmac('sha256', $order_id . $nonce, $this->api_key);
        
        // Prepare order data
        $order_data = array(
            'order_id'    => $order_id,
            'currency'    => $order->get_currency(),
            'amount'      => $order->get_total(),
            'return_url'  => $this->get_return_url($order),
            'cancel_url'  => $order->get_cancel_order_url(),
            'nonce'       => $nonce,
            'hash'        => $hash,
            'store_name'  => get_bloginfo('name'),
        );
        
        // Encode order data
        $order_data_json = json_encode($order_data);
        $encrypted_data  = base64_encode($order_data_json);
        
        // Build iframe URL
        $iframe_url = rtrim($this->proxy_url, '/') . '/';
        $iframe_url .= '?rest_route=/wc-paypal-proxy/v1/checkout&data=' . urlencode($encrypted_data);
        
        // Show iframe with loading message
        echo '<div id="paypal-proxy-container">';
        echo '<h2>' . __('Please complete your payment', 'wc-paypal-proxy-client') . '</h2>';
        echo '<p>' . __('Please wait while we redirect you to PayPal to complete your payment.', 'wc-paypal-proxy-client') . '</p>';
        
        // Add sandbox attribute and important styling to ensure iframe renders correctly
        echo '<iframe id="paypal-proxy-iframe" 
            referrerpolicy="no-referrer" 
            sandbox="allow-forms allow-scripts allow-same-origin allow-top-navigation allow-popups" 
            height="' . esc_attr($this->iframe_height) . '" 
            frameborder="0" 
            style="width: 100%; border: none !important; display: block !important; min-height: 450px;"
            src="' . esc_url($iframe_url) . '"></iframe>';
        
        echo '</div>';
        
        // Added JavaScript to ensure iframe content is displayed properly
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Make sure the iframe is visible
            $('#paypal-proxy-iframe').css({
                'display': 'block',
                'width': '100%',
                'min-height': '450px',
                'border': 'none'
            });
            
            // Log when iframe loads
            $('#paypal-proxy-iframe').on('load', function() {
                console.log('PayPal iframe loaded');
                // Hide loading message after iframe loads
                $('#paypal-proxy-container > p').hide();
            });
        });
        </script>
        <?php
    }

    /**
     * Load payment scripts
     */
    public function payment_scripts() {
        if (!is_checkout_pay_page()) {
            return;
        }

        // Check if our gateway is selected
        if (!isset($_GET['key'])) {
            return;
        }
        
        $order_id = wc_get_order_id_by_order_key(wc_clean($_GET['key']));
        $order = wc_get_order($order_id);
        
        if ($order->get_payment_method() !== $this->id) {
            return;
        }

        // Enqueue script
        wp_enqueue_script(
            'wc-paypal-proxy-client',
            WC_PAYPAL_PROXY_CLIENT_PLUGIN_URL . 'assets/js/paypal-proxy-client.js',
            array('jquery'),
            WC_PAYPAL_PROXY_CLIENT_VERSION,
            true
        );

        // Add script data
        wp_localize_script(
            'wc-paypal-proxy-client',
            'wc_paypal_proxy_params',
            array(
                'order_id'  => $order_id,
                'ajax_url'  => admin_url('admin-ajax.php'),
                'nonce'     => wp_create_nonce('wc-paypal-proxy-' . $order_id),
            )
        );
    }

    /**
     * Process refund
     *
     * @param int    $order_id Order ID.
     * @param float  $amount Refund amount.
     * @param string $reason Refund reason.
     * @return bool|WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);

        if (!$order) {
            return new WP_Error('invalid_order', __('Invalid order', 'wc-paypal-proxy-client'));
        }

        $transaction_id = $order->get_transaction_id();

        if (!$transaction_id) {
            return new WP_Error('no_transaction_id', __('No transaction ID found', 'wc-paypal-proxy-client'));
        }

        // Prepare refund data
        $refund_data = array(
            'order_id'      => $order_id,
            'amount'        => $amount,
            'reason'        => $reason,
            'transaction_id' => $transaction_id,
            'currency'      => $order->get_currency(),
        );

        // Generate secure hash
        $nonce = wp_create_nonce('wc-paypal-proxy-refund-' . $order_id);
        $hash  = hash_hmac('sha256', $order_id . $nonce . $amount, $this->api_key);

        $refund_data['nonce'] = $nonce;
        $refund_data['hash']  = $hash;

        // Make sure tracker data is loaded
        $this->maybe_load_tracker_data();

        // Send refund request to proxy
        $response = wp_remote_post(
            trailingslashit($this->proxy_url) . '?rest_route=/wc-paypal-proxy/v1/refund',
            array(
                'body'    => $refund_data,
                'timeout' => 60,
            )
        );

        if (is_wp_error($response)) {
            $this->log('Refund Error: ' . $response->get_error_message());
            return new WP_Error('refund_error', $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body) || !isset($body['success'])) {
            $this->log('Refund Error: Invalid response from proxy');
            return new WP_Error('refund_error', __('Invalid response from proxy', 'wc-paypal-proxy-client'));
        }

        if (!$body['success']) {
            $this->log('Refund Error: ' . $body['message']);
            return new WP_Error('refund_error', $body['message']);
        }

        $this->log('Refund successful for order #' . $order_id);
        return true;
    }

    /**
     * Log messages
     *
     * @param string $message Log message.
     * @param string $level   Log level.
     */
    public function log($message, $level = 'info') {
        if ($this->debug) {
            if (empty($this->logger)) {
                $this->logger = wc_get_logger();
            }
            $this->logger->log($level, $message, array('source' => 'paypal-proxy'));
        }
    }
}