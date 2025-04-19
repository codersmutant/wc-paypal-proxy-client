/**
 * PayPal Proxy Client JavaScript
 * 
 * Handles messaging between the parent window (Store A) and the iframe (Store B)
 */
(function($) {
    'use strict';

    // Initialize the PayPal Proxy Client
    const PayPalProxyClient = {
        
        /**
         * Initialize
         */
        init: function() {
            // Set up the message listener for iframe communication
            window.addEventListener('message', this.handleMessage.bind(this));
            
            // Add a listener for the iframe load event
            $('#paypal-proxy-iframe').on('load', this.iframeLoaded.bind(this));
            
            // Log initialization
            this.log('PayPal Proxy Client initialized');
        },
        
        /**
         * Handle messages from the iframe
         */
        handleMessage: function(event) {
            // Verify message origin (must be from the proxy site)
            const proxyUrl = new URL($('#paypal-proxy-iframe').attr('src'));
            if (event.origin !== proxyUrl.origin) {
                this.log('Received message from unauthorized origin: ' + event.origin);
                return;
            }
            
            // Process the message
            const message = event.data;
            
            if (!message || typeof message !== 'object') {
                return;
            }
            
            this.log('Received message from iframe: ' + JSON.stringify(message));
            
            // Handle different message types
            switch (message.type) {
                case 'payment_completed':
                    this.handlePaymentCompleted(message);
                    break;
                    
                case 'payment_failed':
                    this.handlePaymentFailed(message);
                    break;
                    
                case 'payment_cancelled':
                    this.handlePaymentCancelled(message);
                    break;
                    
                case 'iframe_height':
                    this.adjustIframeHeight(message.height);
                    break;
                    
                case 'iframe_ready':
                    this.iframeReady();
                    break;
            }
        },
        
        /**
         * Handle payment completed message
         */
        handlePaymentCompleted: function(message) {
            const orderId = wc_paypal_proxy_params.order_id;
            const redirectUrl = message.redirect_url || window.location.href;
            
            // Show success message
            this.showMessage('Payment completed successfully! Redirecting...', 'success');
            
            // Redirect after a short delay
            setTimeout(function() {
                window.location.href = redirectUrl;
            }, 2000);
        },
        
        /**
         * Handle payment failed message
         */
        handlePaymentFailed: function(message) {
            // Show error message
            this.showMessage('Payment failed: ' + (message.error || 'Unknown error'), 'error');
            
            // Reload the page after a delay to allow retry
            setTimeout(function() {
                window.location.reload();
            }, 5000);
        },
        
        /**
         * Handle payment cancelled message
         */
        handlePaymentCancelled: function(message) {
            const cancelUrl = message.cancel_url || window.location.href;
            
            // Show cancelled message
            this.showMessage('Payment cancelled. Redirecting...', 'info');
            
            // Redirect after a short delay
            setTimeout(function() {
                window.location.href = cancelUrl;
            }, 2000);
        },
        
        /**
         * Adjust iframe height
         */
        adjustIframeHeight: function(height) {
            if (height && !isNaN(height)) {
                $('#paypal-proxy-iframe').height(height + 'px');
                this.log('Adjusted iframe height to ' + height + 'px');
            }
        },
        
        /**
         * Handle iframe loaded event
         */
        iframeLoaded: function() {
            this.log('Iframe loaded');
            
            // Hide loading message
            $('#paypal-proxy-container > p').hide();
        },
        
        /**
         * Handle iframe ready message
         */
        iframeReady: function() {
            this.log('Iframe is ready');
            
            // Send order information to the iframe
            const iframe = document.getElementById('paypal-proxy-iframe');
            if (iframe) {
                const message = {
                    type: 'order_info',
                    order_id: wc_paypal_proxy_params.order_id
                };
                
                iframe.contentWindow.postMessage(message, '*');
            }
        },
        
        /**
         * Show message
         */
        showMessage: function(message, type) {
            const container = $('#paypal-proxy-container');
            
            // Remove any existing messages
            container.find('.paypal-proxy-message').remove();
            
            // Add the new message
            const messageHtml = '<div class="paypal-proxy-message paypal-proxy-' + type + '">' + message + '</div>';
            container.prepend(messageHtml);
        },
        
        /**
         * Log message to console
         */
        log: function(message) {
            if (typeof console !== 'undefined' && console.log) {
                console.log('PayPal Proxy: ' + message);
            }
        }
    };
    
    // Initialize when the document is ready
    $(document).ready(function() {
        PayPalProxyClient.init();
    });
    
})(jQuery);