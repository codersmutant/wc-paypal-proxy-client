<?php
/**
 * WooCommerce PayPal Proxy Webhook Handler
 *
 * @package WC_PayPal_Proxy_Client
 */

defined('ABSPATH') || exit;

/**
 * WC_PayPal_Proxy_Webhook_Handler Class
 */
class WC_PayPal_Proxy_Webhook_Handler {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_webhook_endpoints'));
        add_action('init', array($this, 'legacy_webhook_handler'));
    }

    /**
     * Register REST API endpoints
     */
    public function register_webhook_endpoints() {
        register_rest_route('wc-paypal-proxy/v1', '/webhook', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'process_webhook'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Process webhook
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function process_webhook($request) {
        $params = $request->get_params();
        
        // Get the gateway settings
        $gateway = new WC_Gateway_PayPal_Proxy();
        
        // Verify webhook
        if (!$this->verify_webhook($params, $gateway)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid webhook signature',
            ), 403);
        }
        
        // Process the payment status
        $result = $this->process_payment_status($params, $gateway);
        
        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => $result->get_error_message(),
            ), 400);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Webhook processed successfully',
        ), 200);
    }

    /**
     * Legacy webhook handler (for non-REST API requests)
     */
    public function legacy_webhook_handler() {
        if (isset($_GET['wc-paypal-proxy-webhook']) && $_GET['wc-paypal-proxy-webhook'] === 'yes' && isset($_POST['payload'])) {
            // Get the gateway settings
            $gateway = new WC_Gateway_PayPal_Proxy();
            
            // Decode the payload
            $params = json_decode(base64_decode(wp_unslash($_POST['payload'])), true);
            
            // Verify webhook
            if (!$this->verify_webhook($params, $gateway)) {
                wp_die('Invalid webhook signature', 'PayPal Proxy Webhook', array('response' => 403));
            }
            
            // Process the payment status
            $result = $this->process_payment_status($params, $gateway);
            
            if (is_wp_error($result)) {
                wp_die($result->get_error_message(), 'PayPal Proxy Webhook', array('response' => 400));
            }
            
            echo 'Webhook processed successfully';
            exit;
        }
    }

    /**
     * Verify webhook
     *
     * @param array                $params Webhook parameters.
     * @param WC_Gateway_PayPal_Proxy $gateway Gateway instance.
     * @return bool
     */
    private function verify_webhook($params, $gateway) {
        // Check required parameters
        if (empty($params['order_id']) || empty($params['status']) || empty($params['nonce']) || empty($params['hash'])) {
            $gateway->log('Webhook Error: Missing required parameters');
            return false;
        }
        
        // Verify hash
        $expected_hash = hash_hmac('sha256', $params['order_id'] . $params['status'] . $params['nonce'], $gateway->api_key);
        
        if (!hash_equals($expected_hash, $params['hash'])) {
            $gateway->log('Webhook Error: Invalid hash for order #' . $params['order_id']);
            return false;
        }
        
        // Verify nonce hasn't been used before
        $used_nonces = get_option('wc_paypal_proxy_used_nonces', array());
        
        if (in_array($params['nonce'], $used_nonces)) {
            $gateway->log('Webhook Error: Nonce already used for order #' . $params['order_id']);
            return false;
        }
        
        // Store nonce to prevent replay attacks
        $used_nonces[] = $params['nonce'];
        update_option('wc_paypal_proxy_used_nonces', $used_nonces);
        
        return true;
    }

    /**
     * Process payment status
     *
     * @param array                $params Webhook parameters.
     * @param WC_Gateway_PayPal_Proxy $gateway Gateway instance.
     * @return true|WP_Error
     */
    private function process_payment_status($params, $gateway) {
        $order_id = $params['order_id'];
        $status = $params['status'];
        $transaction_id = isset($params['transaction_id']) ? $params['transaction_id'] : '';
        
        // Get the order
        $order = wc_get_order($order_id);
        
        if (!$order) {
            $gateway->log('Webhook Error: Order #' . $order_id . ' not found');
            return new WP_Error('invalid_order', 'Order not found');
        }
        
        // Check if order payment method matches our gateway
        if ($order->get_payment_method() !== 'paypal_proxy') {
            $gateway->log('Webhook Error: Order #' . $order_id . ' payment method mismatch');
            return new WP_Error('payment_method_mismatch', 'Payment method mismatch');
        }
        
        // Process based on status
        switch ($status) {
            case 'completed':
                // Payment completed
                if ($order->has_status('completed')) {
                    // Already completed, just log it
                    $gateway->log('Webhook: Order #' . $order_id . ' already completed');
                    return true;
                }
                
                // Save transaction ID
                if (!empty($transaction_id)) {
                    $order->set_transaction_id($transaction_id);
                }
                
                // Add order note
                $order->add_order_note(
                    sprintf(__('PayPal payment completed via proxy. Transaction ID: %s', 'wc-paypal-proxy-client'), $transaction_id)
                );
                
                // Complete the order
                $order->payment_complete($transaction_id);
                $gateway->log('Webhook: Payment completed for order #' . $order_id);
                
                // Add the payment to the tracker
                if (class_exists('WC_PayPal_Proxy_Payment_Tracker')) {
                    do_action('woocommerce_paypal_proxy_payment_complete', $order->get_total(), $order_id);
                    $gateway->log('Webhook: Added payment amount to tracker for order #' . $order_id);
                }
                
                break;
                
            case 'failed':
                // Payment failed
                $order->update_status(
                    'failed',
                    __('PayPal payment failed.', 'wc-paypal-proxy-client')
                );
                $gateway->log('Webhook: Payment failed for order #' . $order_id);
                break;
                
            case 'cancelled':
                // Payment cancelled
                $order->update_status(
                    'cancelled',
                    __('PayPal payment cancelled.', 'wc-paypal-proxy-client')
                );
                $gateway->log('Webhook: Payment cancelled for order #' . $order_id);
                break;
                
            case 'refunded':
                // Payment refunded
                if ($order->has_status('refunded')) {
                    // Already refunded, just log it
                    $gateway->log('Webhook: Order #' . $order_id . ' already refunded');
                    return true;
                }
                
                // Get refund amount
                $refund_amount = isset($params['amount']) ? floatval($params['amount']) : $order->get_total();
                
                // Process the refund
                $refund_reason = isset($params['reason']) ? $params['reason'] : __('Refunded via PayPal', 'wc-paypal-proxy-client');
                
                // Create the refund
                $refund = wc_create_refund(array(
                    'order_id'   => $order_id,
                    'amount'     => $refund_amount,
                    'reason'     => $refund_reason,
                ));
                
                if (is_wp_error($refund)) {
                    $gateway->log('Webhook Error: ' . $refund->get_error_message());
                    return $refund;
                }
                
                $order->add_order_note(
                    sprintf(__('Refunded %s via PayPal proxy. Refund ID: %s', 'wc-paypal-proxy-client'),
                        wc_price($refund_amount, array('currency' => $order->get_currency())),
                        $transaction_id
                    )
                );
                
                $gateway->log('Webhook: Payment refunded for order #' . $order_id);
                break;
                
            default:
                // Unknown status
                $gateway->log('Webhook Error: Unknown status "' . $status . '" for order #' . $order_id);
                return new WP_Error('invalid_status', 'Unknown payment status');
        }
        
        // Save order changes
        $order->save();
        
        return true;
    }
}