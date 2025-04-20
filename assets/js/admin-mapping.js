/**
 * PayPal Proxy Product Mapping Admin JS
 */
(function($) {
    'use strict';

    const PayPalProxyMapping = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Single product mapping save
            $('.save-mapping').on('click', this.saveSingleMapping.bind(this));
            
            // Bulk save mappings
            $('#bulk-save-mapping').on('click', this.saveBulkMappings.bind(this));
            
            // Search functionality
            $('#mapping-search').on('keyup', this.searchProducts.bind(this));
        },

        saveSingleMapping: function(e) {
            const button = $(e.currentTarget);
            const row = button.closest('tr');
            const productId = row.data('product-id');
            const mappedId = row.find('.mapped-id-input').val();
            const statusSpan = row.find('.status');
            
            button.prop('disabled', true);
            statusSpan.html('<span class="spinner is-active"></span>');
            
            $.ajax({
                url: wc_paypal_proxy_mapping_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'wc_paypal_proxy_save_mapping',
                    nonce: wc_paypal_proxy_mapping_params.nonce,
                    product_id: productId,
                    mapped_id: mappedId
                },
                success: function(response) {
                    button.prop('disabled', false);
                    
                    if (response.success) {
                        statusSpan.html('<span class="dashicons dashicons-yes" style="color:green;"></span>');
                        setTimeout(function() {
                            statusSpan.html('');
                        }, 2000);
                    } else {
                        statusSpan.html('<span class="dashicons dashicons-no" style="color:red;"></span>');
                    }
                },
                error: function() {
                    button.prop('disabled', false);
                    statusSpan.html('<span class="dashicons dashicons-no" style="color:red;"></span>');
                }
            });
        },

        saveBulkMappings: function() {
            const button = $('#bulk-save-mapping');
            const statusDiv = $('.mapping-status');
            const mappings = [];
            
            // Collect all mappings
            $('.mapping-table tbody tr').each(function() {
                const productId = $(this).data('product-id');
                const mappedId = $(this).find('.mapped-id-input').val();
                
                if (productId) {
                    mappings.push({
                        product_id: productId,
                        mapped_id: mappedId
                    });
                }
            });
            
            button.prop('disabled', true);
            statusDiv.html('<div class="notice notice-info"><p><span class="spinner is-active"></span> ' + 
                          'Saving mappings...</p></div>');
            
            $.ajax({
                url: wc_paypal_proxy_mapping_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'wc_paypal_proxy_bulk_save_mapping',
                    nonce: wc_paypal_proxy_mapping_params.nonce,
                    mappings: mappings
                },
                success: function(response) {
                    button.prop('disabled', false);
                    
                    if (response.success) {
                        statusDiv.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                    } else {
                        statusDiv.html('<div class="notice notice-error"><p>' + 
                                      (response.data.message || wc_paypal_proxy_mapping_params.save_error) + 
                                      '</p></div>');
                    }
                    
                    // Clear status after 5 seconds
                    setTimeout(function() {
                        statusDiv.html('');
                    }, 5000);
                },
                error: function() {
                    button.prop('disabled', false);
                    statusDiv.html('<div class="notice notice-error"><p>' + 
                                  wc_paypal_proxy_mapping_params.save_error + 
                                  '</p></div>');
                }
            });
        },

        searchProducts: function(e) {
            const searchTerm = $(e.currentTarget).val().toLowerCase();
            
            $('.mapping-table tbody tr').each(function() {
                const productName = $(this).find('.column-product').text().toLowerCase();
                const sku = $(this).find('.column-sku').text().toLowerCase();
                
                if (productName.includes(searchTerm) || sku.includes(searchTerm)) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        }
    };

    $(document).ready(function() {
        PayPalProxyMapping.init();
    });
    
})(jQuery);