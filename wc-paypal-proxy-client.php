<?php
/**
 * Plugin Name: WooCommerce PayPal Proxy Client
 * Plugin URI: https://www.upwork.com/freelancers/eneshrahman2
 * Description: WooCommerce payment gateway that uses a proxy site for PayPal payments.
 * Version: 1.0.0
 * Author: Masum Billah
 * Author URI: https://www.upwork.com/freelancers/eneshrahman2
 * Text Domain: wc-paypal-proxy-client
 * Domain Path: /languages
 * WC requires at least: 3.0.0
 * WC tested up to: 8.0.0
 *
 * @package WC_PayPal_Proxy_Client
 */

defined('ABSPATH') || exit;

// Define plugin constants
define('WC_PAYPAL_PROXY_CLIENT_VERSION', '1.0.0');
define('WC_PAYPAL_PROXY_CLIENT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_PAYPAL_PROXY_CLIENT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_PAYPAL_PROXY_CLIENT_HANDLER_URL', 'https://store-b-domain.com'); // Change to your Store B URL

/**
 * Check if WooCommerce is active
 */
function wc_paypal_proxy_client_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'wc_paypal_proxy_client_woocommerce_missing_notice');
        return false;
    }
    return true;
}

/**
 * WooCommerce missing notice
 */
function wc_paypal_proxy_client_woocommerce_missing_notice() {
    echo '<div class="error"><p><strong>' . 
         sprintf(esc_html__('PayPal Proxy Client requires WooCommerce to be installed and active. You can download %s here.', 'wc-paypal-proxy-client'), 
         '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>') . 
         '</strong></p></div>';
}

/**
 * Initialize the plugin
 */
function wc_paypal_proxy_client_init() {
    // Check if WooCommerce is active
    if (!wc_paypal_proxy_client_check_woocommerce()) {
        return;
    }
    
    // Include files in a specific order to avoid circular dependencies
    // First include the payment tracker
    require_once WC_PAYPAL_PROXY_CLIENT_PLUGIN_DIR . 'includes/class-wc-paypal-proxy-payment-tracker.php';
    
    // Then include gateway and webhook handler
    require_once WC_PAYPAL_PROXY_CLIENT_PLUGIN_DIR . 'includes/class-wc-gateway-paypal-proxy.php';
    require_once WC_PAYPAL_PROXY_CLIENT_PLUGIN_DIR . 'includes/class-wc-paypal-proxy-webhook-handler.php';
    
    // Add the gateway to WooCommerce
    add_filter('woocommerce_payment_gateways', 'wc_paypal_proxy_client_add_gateway');
    
    // Initialize webhook handler
    new WC_PayPal_Proxy_Webhook_Handler();
    
    // Add CSS for admin
    add_action('admin_enqueue_scripts', 'wc_paypal_proxy_client_admin_styles');
    
    // Load plugin text domain
    add_action('init', 'wc_paypal_proxy_client_load_textdomain');
}
add_action('plugins_loaded', 'wc_paypal_proxy_client_init');

/**
 * Add admin styles
 */
function wc_paypal_proxy_client_admin_styles() {
    wp_enqueue_style(
        'wc-paypal-proxy-admin',
        WC_PAYPAL_PROXY_CLIENT_PLUGIN_URL . 'assets/css/admin-style.css',
        array(),
        WC_PAYPAL_PROXY_CLIENT_VERSION
    );
}

/**
 * Add the gateway to WooCommerce
 */
function wc_paypal_proxy_client_add_gateway($gateways) {
    $gateways[] = 'WC_Gateway_PayPal_Proxy';
    return $gateways;
}

/**
 * Load plugin text domain
 */
function wc_paypal_proxy_client_load_textdomain() {
    load_plugin_textdomain('wc-paypal-proxy-client', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

/**
 * Add settings link to plugin page
 */
function wc_paypal_proxy_client_plugin_links($links) {
    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=paypal_proxy') . '">' . __('Settings', 'wc-paypal-proxy-client') . '</a>',
    );
    return array_merge($plugin_links, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wc_paypal_proxy_client_plugin_links');



/**
 * Add PayPal button to the checkout page
 */
function wc_paypal_proxy_client_add_checkout_button() {
    // Check if our gateway is enabled
    $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
    if (!isset($available_gateways['paypal_proxy'])) {
        return;
    }

    // Get our gateway instance
    $gateway = $available_gateways['paypal_proxy'];

    // Only modify the checkout form when our gateway is selected
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Create a container for the PayPal button
        if ($('#paypal-button-container').length === 0) {
            $('#place_order').after('<div id="paypal-button-container" style="display:none;margin-top:20px;"></div>');
        }

        // Function to show/hide PayPal buttons based on payment method selection
        function togglePayPalButtons() {
            if ($('#payment_method_paypal_proxy').is(':checked')) {
                $('#place_order').hide();
                $('#paypal-button-container').show();
                loadPayPalProxy();
            } else {
                $('#place_order').show();
                $('#paypal-button-container').hide();
            }
        }

        // Handle payment method change
        $('form.checkout').on('change', 'input[name="payment_method"]', function() {
            togglePayPalButtons();
        });

        // Initial check
        togglePayPalButtons();

        // WooCommerce updates checkout fragments, reapply our changes
        $(document.body).on('updated_checkout', function() {
            togglePayPalButtons();
        });

        // Load PayPal proxy
        var paypalProxyLoaded = false;
        function loadPayPalProxy() {
            // Only load once
            if (paypalProxyLoaded) {
                return;
            }

            $('#paypal-button-container').html('<p><?php _e('Loading PayPal...', 'wc-paypal-proxy-client'); ?></p>');

            // Create the order in WooCommerce first
            $.ajax({
                type: 'POST',
                url: wc_checkout_params.ajax_url,
                data: {
                    action: 'wc_paypal_proxy_create_order',
                    security: '<?php echo wp_create_nonce('wc-paypal-proxy-checkout'); ?>',
                    form_data: $('form.checkout').serialize()
                },
                success: function(response) {
                    if (response.success) {
                        var order = response.data;
                        // Load iframe with PayPal buttons
                        var iframe = document.createElement('iframe');
                        iframe.id = 'paypal-proxy-iframe';
                        iframe.setAttribute('referrerpolicy', 'no-referrer');
                        iframe.setAttribute('sandbox', 'allow-forms allow-scripts allow-same-origin allow-top-navigation allow-popups');
                        iframe.style.width = '100%';
                        iframe.style.border = 'none';
                        iframe.style.minHeight = '150px';
                        iframe.style.overflow = 'hidden';
                        iframe.src = order.iframe_url;

                        $('#paypal-button-container').html('').append(iframe);
                        paypalProxyLoaded = true;

                        // Add message listener to handle iframe communication
                        window.addEventListener('message', function(event) {
                            // Verify origin (can be made more secure in production)
                            var proxyUrl = new URL(order.iframe_url);
                            if (event.origin !== proxyUrl.origin) {
                                return;
                            }

                            var message = event.data;
                            if (!message || typeof message !== 'object') {
                                return;
                            }

                            // Handle different message types
                            switch (message.type) {
                                case 'payment_completed':
                                    window.location.href = message.redirect_url;
                                    break;
                                case 'payment_failed':
                                    // Show error message
                                    if ($('.woocommerce-error').length === 0) {
                                        $('.woocommerce-notices-wrapper').append('<div class="woocommerce-error">' + (message.error || 'Payment failed') + '</div>');
                                    }
                                    break;
                                case 'payment_cancelled':
                                    // Show cancelled message
                                    if ($('.woocommerce-info').length === 0) {
                                        $('.woocommerce-notices-wrapper').append('<div class="woocommerce-info">Payment cancelled</div>');
                                    }
                                    break;
                                case 'iframe_height':
                                    // Adjust iframe height
                                    if (message.height) {
                                        $('#paypal-proxy-iframe').height(message.height + 'px');
                                    }
                                    break;
                            }
                        });
                    } else {
                        $('#paypal-button-container').html('<p class="woocommerce-error">' + (response.data || 'Error creating order') + '</p>');
                    }
                },
                error: function() {
                    $('#paypal-button-container').html('<p class="woocommerce-error">Error creating order</p>');
                }
            });
        }
    });
    </script>
    <?php
}
add_action('woocommerce_review_order_before_submit', 'wc_paypal_proxy_client_add_checkout_button');

/**
 * Add this AJAX handler to create the order
 */
function wc_paypal_proxy_create_order() {
    // Verify nonce
    if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'wc-paypal-proxy-checkout')) {
        wp_send_json_error('Invalid security token.');
        return;
    }

    // Save form data into session to use later in the order creation
    if (isset($_POST['form_data'])) {
        parse_str($_POST['form_data'], $checkout_data);
        WC()->session->set('checkout_data', $checkout_data);
    }

    // Create a pending order
    try {
        // Get cart contents and calculate totals
        WC()->cart->calculate_totals();
        
        // Set the payment method to our gateway
        WC()->session->set('chosen_payment_method', 'paypal_proxy');
        
        // Create a new order
        $order_id = WC()->checkout()->create_order([
            'payment_method' => 'paypal_proxy'
        ]);
        
        if (is_wp_error($order_id)) {
            throw new Exception($order_id->get_error_message());
        }
        
        $order = wc_get_order($order_id);
        $order->update_status('pending', __('Awaiting PayPal payment', 'wc-paypal-proxy-client'));
        
        // Get gateway instance
        $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
        $gateway = $available_gateways['paypal_proxy'];
        
        // Make sure tracker data is loaded
        $gateway->maybe_load_tracker_data();
        
        // Get current proxy URL and API key
        $proxy_url = $gateway->proxy_url;
        $api_key = $gateway->api_key;
        
        // Generate secure hash and nonce
        $nonce = wp_create_nonce('wc-paypal-proxy-' . $order_id);
        $hash  = hash_hmac('sha256', $order_id . $nonce, $api_key);
        
        // Prepare order data
        $order_data = [
            'order_id'    => $order_id,
            'currency'    => $order->get_currency(),
            'amount'      => $order->get_total(),
            'return_url'  => $gateway->get_return_url($order),
            'cancel_url'  => $order->get_cancel_order_url(),
            'nonce'       => $nonce,
            'hash'        => $hash,
            'store_name'  => get_bloginfo('name'),
        ];
        
        // Encode order data
        $order_data_json = json_encode($order_data);
        $encrypted_data  = base64_encode($order_data_json);
        
        // Build iframe URL
        $iframe_url = rtrim($proxy_url, '/') . '/';
        $iframe_url .= '?rest_route=/wc-paypal-proxy/v1/checkout&data=' . urlencode($encrypted_data);
        
        // Return success with order details and iframe URL
        wp_send_json_success([
            'order_id' => $order_id,
            'iframe_url' => $iframe_url
        ]);
        
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
    
    wp_die();
}
add_action('wp_ajax_wc_paypal_proxy_create_order', 'wc_paypal_proxy_create_order');
add_action('wp_ajax_nopriv_wc_paypal_proxy_create_order', 'wc_paypal_proxy_create_order');




/**
 * Add direct reset function for debugging (add this to wc-paypal-proxy-client.php)
 */
function wc_paypal_proxy_debug_reset() {
    // Only allow admin access
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access');
    }
    
    // Check if we should run the reset
    if (isset($_GET['debug_reset_paypal_proxy']) && $_GET['debug_reset_paypal_proxy'] === 'yes') {
        // Create nonce
        $nonce = wp_create_nonce('reset_paypal_proxy_payments');
        
        // Prepare form data
        $data = [
            'action' => 'reset_paypal_proxy_payments',
            '_wpnonce' => $nonce
        ];
        
        // Log attempt
        error_log('PayPal Proxy: Debug reset triggered');
        
        // Get tracker and reset manually
        if (class_exists('WC_PayPal_Proxy_Payment_Tracker')) {
            $tracker = WC_PayPal_Proxy_Payment_Tracker::get_instance();
            if (method_exists($tracker, 'reset_payments')) {
                // Set POST data to simulate form submission
                $_POST['_wpnonce'] = $nonce;
                
                // Call reset directly
                try {
                    $tracker->reset_payments();
                } catch (Exception $e) {
                    error_log('PayPal Proxy Debug: Reset failed with error - ' . $e->getMessage());
                    wp_die('Reset failed: ' . $e->getMessage());
                }
            } else {
                wp_die('Reset method not found');
            }
        } else {
            wp_die('Tracker class not found');
        }
    }
}
add_action('admin_init', 'wc_paypal_proxy_debug_reset');



function wc_paypal_proxy_test_selection() {
    // Only allow admin access
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access');
    }
    
    // Check if we should run the test
    if (isset($_GET['test_proxy_selection']) && $_GET['test_proxy_selection'] === 'yes') {
        // Get the requested proxy index
        $proxy_index = isset($_GET['proxy_index']) ? intval($_GET['proxy_index']) : 0;
        
        // Log attempt
        error_log('PayPal Proxy: Test selection triggered for index ' . $proxy_index);
        
        // Get tracker and manually update
        if (class_exists('WC_PayPal_Proxy_Payment_Tracker')) {
            $tracker = WC_PayPal_Proxy_Payment_Tracker::get_instance();
            
            // Verify we have the proxy URLs
            $proxy_urls = $tracker->get_proxy_urls();
            if ($proxy_index < 0 || $proxy_index >= count($proxy_urls)) {
                wp_die('Invalid proxy index');
            }
            
            // Create nonce
            $nonce = wp_create_nonce('select_proxy_site');
            
            // Set up direct call parameters
            $_POST['_wpnonce'] = $nonce;
            $_POST['proxy_index'] = $proxy_index;
            
            // Call function directly for testing
            try {
                $tracker->select_proxy_site();
            } catch (Exception $e) {
                error_log('PayPal Proxy Test: Selection failed with error - ' . $e->getMessage());
                wp_die('Selection failed: ' . $e->getMessage());
            }
        } else {
            wp_die('Tracker class not found');
        }
    }
}
add_action('admin_init', 'wc_paypal_proxy_test_selection');