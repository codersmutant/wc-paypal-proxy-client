<?php
/**
 * WooCommerce PayPal Proxy Product Mapping
 *
 * @package WC_PayPal_Proxy_Client
 */

defined('ABSPATH') || exit;

/**
 * WC_PayPal_Proxy_Product_Mapping Class
 */
class WC_PayPal_Proxy_Product_Mapping {

    /**
     * Constructor
     */
    public function __construct() {
        // Register admin menu
        add_action('admin_menu', array($this, 'register_admin_menu'), 99);
        
        // Register admin scripts
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        
        // Ajax handlers for saving product mappings
        add_action('wp_ajax_wc_paypal_proxy_save_mapping', array($this, 'ajax_save_mapping'));
        add_action('wp_ajax_wc_paypal_proxy_bulk_save_mapping', array($this, 'ajax_bulk_save_mapping'));
    }

    /**
     * Register admin menu
     */
    public function register_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('PayPal Proxy Product Mapping', 'wc-paypal-proxy-client'),
            __('PayPal Proxy Mapping', 'wc-paypal-proxy-client'),
            'manage_woocommerce',
            'wc-paypal-proxy-mapping',
            array($this, 'render_mapping_page')
        );
    }

    /**
     * Enqueue admin scripts
     */
    public function admin_scripts($hook) {
        if ('woocommerce_page_wc-paypal-proxy-mapping' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'wc-paypal-proxy-mapping',
            WC_PAYPAL_PROXY_CLIENT_PLUGIN_URL . 'assets/css/admin-mapping.css',
            array(),
            WC_PAYPAL_PROXY_CLIENT_VERSION
        );

        wp_enqueue_script(
            'wc-paypal-proxy-mapping',
            WC_PAYPAL_PROXY_CLIENT_PLUGIN_URL . 'assets/js/admin-mapping.js',
            array('jquery'),
            WC_PAYPAL_PROXY_CLIENT_VERSION,
            true
        );

        wp_localize_script(
            'wc-paypal-proxy-mapping',
            'wc_paypal_proxy_mapping_params',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc-paypal-proxy-mapping'),
                'save_success' => __('Mapping saved successfully.', 'wc-paypal-proxy-client'),
                'save_error' => __('Error saving mapping.', 'wc-paypal-proxy-client'),
            )
        );
    }

    /**
     * Render mapping page
     */
    public function render_mapping_page() {
        // Get all products
        $products = $this->get_all_products();
        
        // Render the page
        include WC_PAYPAL_PROXY_CLIENT_PLUGIN_DIR . 'templates/admin-product-mapping.php';
    }

    /**
     * Get all products
     *
     * @return array
     */
    private function get_all_products() {
        $args = array(
            'status' => 'publish',
            'limit' => -1,
            'return' => 'objects',
        );

        $products = wc_get_products($args);
        $product_data = array();

        foreach ($products as $product) {
            $product_data[] = array(
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'price' => $product->get_price(),
                'mapped_id' => $this->get_mapped_product_id($product->get_id()),
            );
        }

        return $product_data;
    }

    /**
     * Get mapped product ID for a Store A product
     *
     * @param int $product_id Product ID in Store A
     * @return string|int Mapped product ID in Store B or empty string
     */
    public function get_mapped_product_id($product_id) {
        return get_post_meta($product_id, '_paypal_proxy_mapped_id', true);
    }

    /**
     * Save mapped product ID
     *
     * @param int $product_id Product ID in Store A
     * @param string|int $mapped_id Mapped product ID in Store B
     * @return bool Success status
     */
    public function save_mapped_product_id($product_id, $mapped_id) {
        if (empty($product_id)) {
            return false;
        }

        if (empty($mapped_id)) {
            delete_post_meta($product_id, '_paypal_proxy_mapped_id');
            return true;
        }

        return update_post_meta($product_id, '_paypal_proxy_mapped_id', sanitize_text_field($mapped_id));
    }

    /**
     * Ajax handler for saving a single product mapping
     */
    public function ajax_save_mapping() {
        check_ajax_referer('wc-paypal-proxy-mapping', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You do not have permission.', 'wc-paypal-proxy-client')));
            return;
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $mapped_id = isset($_POST['mapped_id']) ? sanitize_text_field($_POST['mapped_id']) : '';

        if (!$product_id) {
            wp_send_json_error(array('message' => __('Invalid product ID.', 'wc-paypal-proxy-client')));
            return;
        }

        $result = $this->save_mapped_product_id($product_id, $mapped_id);

        if ($result) {
            wp_send_json_success(array('message' => __('Mapping saved.', 'wc-paypal-proxy-client')));
        } else {
            wp_send_json_error(array('message' => __('Error saving mapping.', 'wc-paypal-proxy-client')));
        }
    }

    /**
     * Ajax handler for bulk saving product mappings
     */
    public function ajax_bulk_save_mapping() {
        check_ajax_referer('wc-paypal-proxy-mapping', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You do not have permission.', 'wc-paypal-proxy-client')));
            return;
        }

        $mappings = isset($_POST['mappings']) ? $_POST['mappings'] : array();
        
        if (!is_array($mappings) || empty($mappings)) {
            wp_send_json_error(array('message' => __('No mappings to save.', 'wc-paypal-proxy-client')));
            return;
        }

        $success_count = 0;
        $error_count = 0;

        foreach ($mappings as $mapping) {
            $product_id = isset($mapping['product_id']) ? absint($mapping['product_id']) : 0;
            $mapped_id = isset($mapping['mapped_id']) ? sanitize_text_field($mapping['mapped_id']) : '';

            if (!$product_id) {
                $error_count++;
                continue;
            }

            $result = $this->save_mapped_product_id($product_id, $mapped_id);

            if ($result) {
                $success_count++;
            } else {
                $error_count++;
            }
        }

        wp_send_json_success(array(
            'message' => sprintf(
                __('Mapping saved. %d successful, %d failed.', 'wc-paypal-proxy-client'),
                $success_count,
                $error_count
            ),
            'success_count' => $success_count,
            'error_count' => $error_count,
        ));
    }

    /**
 * Get product data for order with mapped IDs
 *
 * @param WC_Order $order Order object
 * @return array
 */
public function get_order_items_with_mapping($order) {
    $items = array();

    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        $mapped_id = $this->get_mapped_product_id($product_id);
        
        // Include all items, even those without mapping
        $items[] = array(
            'store_a_id' => $product_id,
            'store_b_id' => $mapped_id, // Will be empty if not mapped
            'name' => $item->get_name(),
            'quantity' => $item->get_quantity(),
            'price' => $item->get_total() / $item->get_quantity(),
            'total' => $item->get_total(),
            'is_mapped' => !empty($mapped_id), // Flag to indicate if it's mapped
        );
    }

    return $items;
}
}