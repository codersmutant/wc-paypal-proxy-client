<?php
/**
 * Admin template for product mapping
 *
 * @package WC_PayPal_Proxy_Client
 */

defined('ABSPATH') || exit;
?>

<div class="wrap wc-paypal-proxy-mapping">
    <h1><?php esc_html_e('PayPal Proxy Product Mapping', 'wc-paypal-proxy-client'); ?></h1>
    
    <p><?php esc_html_e('Map your products from this store (Store A) to corresponding products in the proxy store (Store B).', 'wc-paypal-proxy-client'); ?></p>
    
    <div class="mapping-tools">
        <input type="text" id="mapping-search" placeholder="<?php esc_attr_e('Search products...', 'wc-paypal-proxy-client'); ?>" class="regular-text">
        <button type="button" id="bulk-save-mapping" class="button button-primary"><?php esc_html_e('Save All Mappings', 'wc-paypal-proxy-client'); ?></button>
    </div>
    
    <div class="mapping-status"></div>
    
    <table class="wp-list-table widefat fixed striped mapping-table">
        <thead>
            <tr>
                <th scope="col" class="column-product"><?php esc_html_e('Product', 'wc-paypal-proxy-client'); ?></th>
                <th scope="col" class="column-sku"><?php esc_html_e('SKU', 'wc-paypal-proxy-client'); ?></th>
                <th scope="col" class="column-price"><?php esc_html_e('Price', 'wc-paypal-proxy-client'); ?></th>
                <th scope="col" class="column-store-a-id"><?php esc_html_e('Store A ID', 'wc-paypal-proxy-client'); ?></th>
                <th scope="col" class="column-store-b-id"><?php esc_html_e('Store B ID', 'wc-paypal-proxy-client'); ?></th>
                <th scope="col" class="column-actions"><?php esc_html_e('Actions', 'wc-paypal-proxy-client'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($products)) : ?>
                <?php foreach ($products as $product) : ?>
                    <tr data-product-id="<?php echo esc_attr($product['id']); ?>">
                        <td class="column-product"><?php echo esc_html($product['name']); ?></td>
                        <td class="column-sku"><?php echo esc_html($product['sku']); ?></td>
                        <td class="column-price"><?php echo wc_price($product['price']); ?></td>
                        <td class="column-store-a-id"><?php echo esc_html($product['id']); ?></td>
                        <td class="column-store-b-id">
                            <input type="text" class="mapped-id-input" 
                                   value="<?php echo esc_attr($product['mapped_id']); ?>"
                                   placeholder="<?php esc_attr_e('Enter Store B ID', 'wc-paypal-proxy-client'); ?>">
                        </td>
                        <td class="column-actions">
                            <button type="button" class="button save-mapping"><?php esc_html_e('Save', 'wc-paypal-proxy-client'); ?></button>
                            <span class="status"></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr>
                    <td colspan="6"><?php esc_html_e('No products found.', 'wc-paypal-proxy-client'); ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>