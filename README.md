# WooCommerce PayPal Proxy System

This system consists of two WordPress plugins that work together to allow a WooCommerce store (Store A) to process payments via PayPal without directly communicating with PayPal. Instead, all PayPal communication is handled by a separate WordPress site (Store B).

## System Overview

1. **Store A** (Main Store)
   - Installs the "WC PayPal Proxy Client" plugin
   - Provides a PayPal payment gateway option to customers
   - Loads an iframe from Store B during checkout
   - Never directly communicates with PayPal
   - Receives payment status updates from Store B

2. **Store B** (Proxy Store)
   - Installs the "WC PayPal Proxy Handler" plugin
   - Handles all communication with PayPal
   - Renders the PayPal buttons inside the iframe
   - Processes payments and sends results back to Store A

## Installation & Setup

### On Store B (Proxy Server)

1. Install the "WC PayPal Proxy Handler" plugin
2. Go to Settings > PayPal Proxy
3. Enter your PayPal API credentials:
   - Client ID
   - Client Secret
   - Choose Sandbox/Production mode
4. Add Store A's domain to the Allowed Domains list
5. Create an API key for Store A's domain
6. Install the PayPal PHP SDK using Composer:
   ```
   cd wp-content/plugins/wc-paypal-proxy-handler
   composer require paypal/paypal-checkout-sdk
   ```

### On Store A (Main Store)

1. Install the "WC PayPal Proxy Client" plugin
2. Go to WooCommerce > Settings > Payments
3. Enable and configure "PayPal via Proxy" payment gateway:
   - Enter Store B's URL
   - Enter the API key provided by Store B
   - Configure other settings as needed

## How It Works

1. When a customer checks out on Store A and selects PayPal:
   - Store A creates a pending order
   - Customer is redirected to a payment page with an iframe
   - The iframe loads content from Store B with order details

2. Inside the iframe from Store B:
   - PayPal buttons are rendered
   - Customer interacts with PayPal directly
   - Store B handles all communication with PayPal

3. After payment processing:
   - Store B sends the payment result to Store A
   - Store A updates the order status accordingly
   - Customer is redirected to the appropriate page (success/failure)

## Security Features

- All communication between Store A and B uses HMAC authentication
- Order data is encrypted when passed between stores
- Nonces are used to prevent replay attacks
- Only allowed domains can use the proxy
- Each client store has its own API key

## Requirements

- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.2+
- PayPal Developer Account
- PayPal Checkout SDK installed on Store B
- HTTPS on both stores

## Troubleshooting

- Check debug logs in wp-content/paypal-proxy-logs/ on Store B
- Verify API keys and domain settings
- Ensure both sites have HTTPS
- Check that the PayPal SDK is properly installed

## Customization

Both plugins can be customized to meet specific needs:
- Modify the appearance of the payment page
- Add additional security measures
- Support additional payment features
- Change the communication protocol

## Developer Notes

- Plugin A never connects to PayPal directly
- All PayPal API calls are made from Plugin B
- Communication between A and B is secured with API keys
- The system is designed to be secure and maintainable
