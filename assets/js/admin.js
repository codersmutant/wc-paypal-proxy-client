/**
 * PayPal Proxy Admin JavaScript
 * 
 * Handles the AJAX functionality for proxy selection and reset
 */
(function($) {
    'use strict';

    // Only run this script on the PayPal Proxy settings page
    if (typeof paypalProxyData === 'undefined' || paypalProxyData.current_section !== 'paypal_proxy') {
        return;
    }

    // Initialize the PayPal Proxy Admin functions
    const PayPalProxyAdmin = {
        
        /**
         * Initialize
         */
        init: function() {
            console.log('PayPal Proxy Admin JS initialized');
            
            // Log debug info
            if (paypalProxyData.debug_info) {
                console.log('Debug info:', paypalProxyData.debug_info);
            }
            
            // Add click handlers after DOM is fully loaded
            $(document).ready(this.addEventListeners.bind(this));
        },
        
        /**
         * Add event listeners
         */
        addEventListeners: function() {
            // Add click handler for proxy selection buttons
            $('.select-proxy-button').on('click', this.handleSelectProxy.bind(this));
            
            // Add click handler for proxy reset buttons
            $('.reset-proxy-button').on('click', this.handleResetProxy.bind(this));
            
            // Log that event listeners are set up
            console.log('PayPal Proxy event listeners added');
        },
        
        /**
         * Handle proxy selection
         */
        handleSelectProxy: function(e) {
            // Prevent default form submission
            e.preventDefault();
            
            // Get the proxy index from data attribute
            const proxyIndex = $(e.target).data('proxy-index');
            
            // Show loading state
            $(e.target).addClass('button-busy').prop('disabled', true).text('Updating...');
            
            // Send AJAX request
            $.ajax({
                url: paypalProxyData.ajax_url,
                type: 'POST',
                data: {
                    action: paypalProxyData.action,
                    nonce: paypalProxyData.nonce,
                    type: 'select_proxy',
                    proxy_index: proxyIndex
                },
                success: function(response) {
                    console.log('Proxy selection response:', response);
                    
                    if (response.success) {
                        // Show success message
                        const message = $('<div class="notice notice-success is-dismissible"><p>Active proxy has been changed successfully.</p></div>');
                        $('.woocommerce-notices-wrapper').prepend(message);
                        
                        // Reload the page after a short delay to show updated state
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        // Show error message
                        alert('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                        $(e.target).removeClass('button-busy').prop('disabled', false).text('Use This Proxy');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', error);
                    alert('Error: ' + error);
                    $(e.target).removeClass('button-busy').prop('disabled', false).text('Use This Proxy');
                }
            });
        },
        
        /**
         * Handle proxy reset
         */
        handleResetProxy: function(e) {
            // Prevent default form submission
            e.preventDefault();
            
            // Get the proxy ID from data attribute
            const proxyId = $(e.target).data('proxy-id');
            
            // Show loading state
            $(e.target).addClass('button-busy').prop('disabled', true).text('Resetting...');
            
            // Send AJAX request
            $.ajax({
                url: paypalProxyData.ajax_url,
                type: 'POST',
                data: {
                    action: paypalProxyData.action,
                    nonce: paypalProxyData.nonce,
                    type: 'reset_proxy',
                    proxy_id: proxyId
                },
                success: function(response) {
                    console.log('Proxy reset response:', response);
                    
                    if (response.success) {
                        // Show success message
                        const message = $('<div class="notice notice-success is-dismissible"><p>Proxy payment counter has been reset successfully.</p></div>');
                        $('.woocommerce-notices-wrapper').prepend(message);
                        
                        // Reload the page after a short delay to show updated state
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        // Show error message
                        alert('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                        $(e.target).removeClass('button-busy').prop('disabled', false).text('Reset');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', error);
                    alert('Error: ' + error);
                    $(e.target).removeClass('button-busy').prop('disabled', false).text('Reset');
                }
            });
        }
    };
    
    // Initialize
    PayPalProxyAdmin.init();
    
})(jQuery);