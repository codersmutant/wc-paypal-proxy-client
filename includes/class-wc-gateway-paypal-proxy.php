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

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
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
                'title'       => __('Proxy Site URL', 'wc-paypal-proxy-client'),
                'type'        => 'text',
                'description' => __('Enter the URL of the proxy site (Store B) that will handle PayPal payments.', 'wc-paypal-proxy-client'),
                'default'     => WC_PAYPAL_PROXY_CLIENT_HANDLER_URL,
                'desc_tip'    => true,
            ),
            'api_key' => array(
                'title'       => __('API Key', 'wc-paypal-proxy-client'),
                'type'        => 'password',
                'description' => __('Enter the API key shared with the proxy site for secure communication.', 'wc-paypal-proxy-client'),
                'default'     => '',
                'desc_tip'    => true,
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
    
    // CHANGE: Added sandbox attribute and important styling to ensure iframe renders correctly
    echo '<iframe id="paypal-proxy-iframe" 
        referrerpolicy="no-referrer" 
        sandbox="allow-forms allow-scripts allow-same-origin allow-top-navigation allow-popups" 
        height="' . esc_attr($this->iframe_height) . '" 
        frameborder="0" 
        style="width: 100%; border: none !important; display: block !important; min-height: 450px;"
        src="' . esc_url($iframe_url) . '"></iframe>';
    
    echo '</div>';
    
    // CHANGE: Added JavaScript to ensure iframe content is displayed properly
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