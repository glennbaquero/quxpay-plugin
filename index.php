<?php
/*
    Plugin Name: QUX® Payment Gateway For WooCommerce
    Description: Pay using QUX®
    Version: 2.1.8
    Author: Qux
    Author URI: https://qux.tv
    Requires PHP: 7.4
    Requires at least: 5.0
    Tested up to: 6.8.2
    WC requires at least: 3.0
    WC tested up to: 10.3.5
    Update URI: https://api.qux.tv/web/quxpay
    Requires Plugins: woocommerce
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('QUXPAY_PLUGIN_FILE', __FILE__);
define('QUXPAY_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('QUXPAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('QUXPAY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('QUXPAY_VERSION', '2.1.8');
define('QUXPAY_UPDATE_SERVER', 'https://api.qux.tv/web/quxpay');

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Include the auto-updater class
require_once QUXPAY_PLUGIN_DIR . 'includes/class-quxpay-updater.php';

// Initialize the auto-updater
add_action('init', 'quxpay_init_updater');
function quxpay_init_updater() {
    if (is_admin()) {
        $updater = new QuxPay_Plugin_Updater(QUXPAY_UPDATE_SERVER, QUXPAY_PLUGIN_FILE, array(
            'version' => QUXPAY_VERSION,
            'item_name' => 'QUX® Payment Gateway For WooCommerce',
            'author' => 'Qux',
            'url' => home_url(),
            'beta' => false
        ));
    }
}

// Hook to run when plugin is activated
register_activation_hook(__FILE__, 'quxpay_create_success_page');

// Hook to run when plugin is deactivated
register_deactivation_hook(__FILE__, 'quxpay_remove_success_page');

// Create success page on plugin activation
function quxpay_create_success_page() {
    // Check if page already exists
    $existing_page = get_page_by_path('qux-payment-success');
    
    if (!$existing_page) {
        // Create the success page
        $page_data = array(
            'post_title'     => 'QUX® Payment Success',
            'post_content'   => '[quxpay_success]',
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'post_name'      => 'qux-payment-success',
            'post_author'    => get_current_user_id() ?: 1,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
            'post_excerpt'   => 'QUX® Payment Gateway success page for processing payment confirmations'
        );
        
        $page_id = wp_insert_post($page_data);
        
        if ($page_id && !is_wp_error($page_id)) {
            update_option('quxpay_success_page_id', $page_id);
            set_transient('quxpay_success_page_created', true, 300);
            error_log('QUX® Payment Gateway: Success page created with ID: ' . $page_id);
        } else {
            error_log('QUX® Payment Gateway: Failed to create success page - ' . (is_wp_error($page_id) ? $page_id->get_error_message() : 'Unknown error'));
        }
    } else {
        update_option('quxpay_success_page_id', $existing_page->ID);
        
        if (strpos($existing_page->post_content, '[quxpay_success]') === false) {
            wp_update_post(array(
                'ID' => $existing_page->ID,
                'post_content' => '[quxpay_success]'
            ));
        }
        
        error_log('QUX® Payment Gateway: Using existing success page with ID: ' . $existing_page->ID);
    }
}

// Remove success page on plugin deactivation (optional)
function quxpay_remove_success_page() {
    $page_id = get_option('quxpay_success_page_id');
    
    if ($page_id) {
        // Optional: Only remove if you want to clean up on deactivation
        // wp_delete_post($page_id, true);
        // delete_option('quxpay_success_page_id');
    }
}

// Get success page URL
function quxpay_get_success_page_url() {
    $page_id = get_option('quxpay_success_page_id');
    
    if ($page_id && get_post_status($page_id) === 'publish') {
        return get_permalink($page_id);
    }
    
    $page = get_page_by_path('qux-payment-success');
    if ($page) {
        update_option('quxpay_success_page_id', $page->ID);
        return get_permalink($page->ID);
    }
    
    return site_url();
}

// Check WooCommerce dependency early
add_action('plugins_loaded', 'quxpay_check_woocommerce_dependency', 11);
function quxpay_check_woocommerce_dependency() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>QUX® Payment Gateway:</strong> WooCommerce is required for this plugin to work.</p></div>';
        });
        return;
    }
    
    quxpay_init_gateway_class();
}

// Initialize gateway class early
add_action('init', 'quxpay_init_gateway_class', 0);
function quxpay_init_gateway_class() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    if (class_exists('WC_Qux_Pay_Gateway')) {
        return;
    }

    class WC_Qux_Pay_Gateway extends WC_Payment_Gateway {

        public function __construct() {
            $this->id = 'quxpay'; 
            $this->icon = "";
            $this->has_fields = false; 
            $this->method_title = 'QUX® Payment';
            $this->method_description = 'Use qux as main payment method.';
            $this->order_button_text = __('Proceed to QUX® Payment', 'quxpay');
            $this->view_transaction_url = '';
            
            $this->supports = array(
                'products',
                'subscriptions',
                'subscription_cancellation',
                'subscription_reactivation',
                'subscription_suspension',
                'subscription_amount_changes',
                'subscription_date_changes',
                'subscription_payment_method_change',
                'subscription_payment_method_change_customer',
                'subscription_payment_method_change_admin',
                'multiple_subscriptions',
            );

            $this->init_form_fields();
            $this->init_settings();
            
            // Load settings
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->testmode = 'yes' === $this->get_option('testmode');
            $this->app_key = $this->get_option('app_key');
            $this->secret_key = $this->get_option('secret_key');
          
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_subscription_end_of_prepaid_term', array($this, 'custom_auto_renew_subscription'), 10, 2);
            add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array($this, 'process_subscription_payment'), 9, 3);
        }

        // Check if gateway is available
        public function is_available() {
            $is_available = ('yes' === $this->enabled);
            
            if ($is_available) {
                if (empty($this->app_key) || empty($this->secret_key)) {
                    $is_available = false;
                }
                
                if (!function_exists('WC') || !WC()->cart) {
                    if (!is_admin() && !wp_doing_ajax()) {
                        $is_available = false;
                    }
                }
                
                if ($is_available && function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
                    $has_subscription = false;
                    
                    foreach (WC()->cart->get_cart() as $cart_item) {
                        $product = $cart_item['data'];
                        
                        if ($this->is_wps_subscription_product($product)) {
                            $has_subscription = true;
                            break;
                        }
                        
                        if (class_exists('WC_Subscriptions_Product') && WC_Subscriptions_Product::is_subscription($product)) {
                            $has_subscription = true;
                            break;
                        }
                    }
                }
            }
            
            return apply_filters('woocommerce_' . $this->id . '_is_available', $is_available, $this);
        }
        
        // Check if product is WPS Subscription product
        private function is_wps_subscription_product($product) {
            if (!$product) {
                return false;
            }
            
            $product_id = $product->get_id();
            
            $wps_meta_keys = array(
                '_wps_sfw_product',
                '_subscription_price',
                '_wps_sfw_subscription_price',
                '_wps_sfw_subscription_period',
                '_wps_sfw_subscription_period_interval',
                'wps_sfw_product',
                '_wps_subscription_product'
            );
            
            foreach ($wps_meta_keys as $meta_key) {
                $meta_value = get_post_meta($product_id, $meta_key, true);
                if (!empty($meta_value) && $meta_value !== 'no') {
                    return true;
                }
            }
            
            if (method_exists($product, 'get_type') && in_array($product->get_type(), array('wps_subscription', 'subscription'))) {
                return true;
            }
            
            return false;
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable QUX® Payment Gateway',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default'     => 'QUX® Payment',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default'     => 'Pay with your bank via our QUX® Payment gateway.',
                ),
                'testmode' => array(
                    'title'       => 'Test mode',
                    'label'       => 'Enable Test Mode',
                    'type'        => 'checkbox',
                    'description' => 'Place the payment gateway in test mode using test API keys.',
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
                'success_url' => array(
                    'title'       => 'Success Payment URL',
                    'type'        => 'text',
                    'description' => 'Success URL upon successful payment in QuxPay. Leave empty to use auto-generated success page.',
                    'default'     => get_site_url().'/qux-payment-success/',
                    'desc_tip'    => true,
                    'disabled'    => true
                ),
                'app_key' => array(
                    'title'       => 'APP Key',
                    'label'       => 'APP Key',
                    'type'        => 'text',
                    'description' => 'APP Key can get from https://qux.tv/wallet/balance and click Qux API Key to generate.',
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'secret_key' => array(
                    'title'       => 'Secret Key',
                    'label'       => 'Secret Key',
                    'type'        => 'password',
                    'description' => 'Secret Key can get from https://qux.tv/wallet/balance and click Qux API Key to generate.',
                    'default'     => '',
                    'desc_tip'    => true,
                )
            );
        }

        // Get the success URL (either custom or auto-generated)
        public function get_success_url() {
            $custom_url = $this->get_option('success_url');
            
            if (!empty($custom_url)) {
                return $custom_url;
            }
            
            return quxpay_get_success_page_url();
        }

        public function process_payment($order_id) {
            global $wp;
            $logger = wc_get_logger();
            
            // HPOS-compatible order retrieval
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception('Order not found');
            }
            
            $order_total = $order->get_total();
            $timeStamp = time();

            $products = [];
            $productHasSubscription = [];

            // Check if cart exists before processing
            if (!WC()->cart || WC()->cart->is_empty()) {
                // Get products from order instead (HPOS-compatible)
                foreach ($order->get_items() as $item_id => $item) {
                    $product = $item->get_product();
                    if (!$product) continue;
                    
                    $product_data = [
                        'id' => $product->get_id(),
                        'quantity' => $item->get_quantity(),
                        'product_name' => $item->get_name(),
                        'product_price' => $item->get_total(),
                        'subscription' => false,
                    ];
                    
                    if ($this->is_wps_subscription_product($product)) {
                        $product_data['subscription'] = true;
                        $productHasSubscription[] = $product_data;
                    } else {
                        $products[] = $product_data;
                    }
                }
            } else {
                foreach(WC()->cart->get_cart() as $item) {
                    $product = $item['data'];
                    $product_data = [
                        'id' => $product->get_id(),
                        'quantity' => $item['quantity'],
                        'product_name' => $product->get_name(),
                        'product_price' => get_post_meta($item['product_id'], '_price', true),
                        'subscription' => false,
                    ];
                    
                    if ($this->is_wps_subscription_product($product)) {
                        $product_data['subscription'] = true;
                        $productHasSubscription[] = $product_data;
                    }
                    elseif (isset($item['data']->subscription_price) || 
                           (class_exists('WC_Subscriptions_Product') && WC_Subscriptions_Product::is_subscription($product))) {
                        $product_data['subscription'] = true;
                        $productHasSubscription[] = $product_data;
                    } else {
                        $products[] = $product_data;
                    }
                }
            }

            // ============================================
            // DISCOUNT/COUPON INFORMATION COLLECTION
            // ============================================
            $coupons_data = [];
            $total_discount = 0;

            // Get applied coupons from order
            $used_coupons = $order->get_coupon_codes();

            // ============================================
            // DISCOUNT/COUPON INFORMATION COLLECTION
            // ============================================
            $coupons_data = [];
            $total_discount = 0;
            
            // Get applied coupons from order
            $used_coupons = $order->get_coupon_codes();
            
            if (!empty($used_coupons)) {
                foreach ($used_coupons as $coupon_code) {
                    // Get coupon object
                    $coupon = new WC_Coupon($coupon_code);
                    
                    // Get discount amount for this specific order
                    $discount_amount = 0;
                    foreach ($order->get_items('coupon') as $item_id => $item) {
                        if ($item->get_code() === $coupon_code) {
                            $discount_amount = $item->get_discount();
                            break;
                        }
                    }
                    
                    $total_discount += number_format($discount_amount, 2);
                    
                    // Collect comprehensive coupon information
                    $coupon_info = [
                        'code' => $coupon_code,
                        'discount_amount' => number_format($discount_amount, 2),
                        'discount_type' => $coupon->get_discount_type(), // 'percent', 'fixed_cart', 'fixed_product'
                        'coupon_amount' => floatval($coupon->get_amount()), // Original coupon value
                        'description' => $coupon->get_description(),
                        'usage_count' => $coupon->get_usage_count(),
                        'individual_use' => $coupon->get_individual_use(),
                        'free_shipping' => $coupon->get_free_shipping(),
                        'minimum_amount' => floatval($coupon->get_minimum_amount()),
                        'maximum_amount' => floatval($coupon->get_maximum_amount())
                    ];
                    
                    $coupons_data[] = $coupon_info;
                }
            }
            
            // Get additional discount information from order
            $order_discount_total = $order->get_discount_total();
            $order_discount_tax = $order->get_discount_tax();
            
            // Calculate order subtotals
            $subtotal = $order->get_subtotal();
            $subtotal_after_discount = $subtotal - $order_discount_total;

            $args = array(
                // Order totals
                'x_amount'                 => number_format($subtotal_after_discount, 2),
                'x_subtotal'               => number_format($subtotal, 2),
                'x_discount_total'         => number_format($order_discount_total, 2),
                'x_discount_tax'           => number_format($order_discount_tax, 2),
                'x_subtotal_after_discount'=> number_format($subtotal_after_discount, 2),
                
                // Coupon information
                'coupons'                  => $coupons_data,
                'coupon_codes'             => $used_coupons,
                'total_coupon_discount'    => $total_discount,
                'has_discount'             => !empty($used_coupons),
                
                // Order identification
                'x_invoice_num'            => $order_id,
                'x_relay_response'         => "TRUE",
                'x_fp_sequence'            => $order_id,
                'x_fp_timestamp'           => $timeStamp,
                
                // Billing information
                'x_first_name'             => $order->get_billing_first_name(),
                'x_last_name'              => $order->get_billing_last_name(),
                'x_company'                => $order->get_billing_company(),
                'x_address'                => trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2()),
                'x_country'                => $order->get_billing_country(),
                'x_state'                  => $order->get_billing_state(),
                'x_city'                   => $order->get_billing_city(),
                'x_zip'                    => $order->get_billing_postcode(),
                'x_phone'                  => $order->get_billing_phone(),
                'x_email'                  => $order->get_billing_email(),
                
                // Shipping information
                'x_ship_to_first_name'     => $order->get_shipping_first_name(),
                'x_ship_to_last_name'      => $order->get_shipping_last_name(),
                'x_ship_to_company'        => $order->get_shipping_company(),
                'x_ship_to_address'        => trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2()),
                'x_ship_to_country'        => $order->get_shipping_country(),
                'x_ship_to_state'          => $order->get_shipping_state(),
                'x_ship_to_city'           => $order->get_shipping_city(),
                'x_ship_to_zip'            => $order->get_shipping_postcode(),
                
                // URLs and authentication
                'x_cancel_url'             => wc_get_checkout_url(),
                'success_url'              => $this->get_success_url(),
                'x_freight'                => $order->get_total_shipping(),
                'x_cancel_url_text'        => 'Cancel Payment',
                'secret_key'               => $this->secret_key,
                'app_key'                  => $this->app_key,
                
                // Products
                'products'                 => $products,
                'product_subscription'     => $productHasSubscription,
                
                // Tax information
                'x_tax'                    => floatval($order->get_total_tax()),
                'x_tax_exempt'             => $order->get_meta('_wc_tax_exempt') === 'yes',
                
                // Plugin information
                'sandbox'                  => $this->testmode,
                'plugin'                   => 'wordpress_plugin v2.0.8',
                'subscription_plugin'      => $this->detect_subscription_plugin(),
                
                // Additional order metadata
                'currency'                 => $order->get_currency(),
                'payment_method'           => $order->get_payment_method(),
                'payment_method_title'     => $order->get_payment_method_title(),
                'customer_note'            => $order->get_customer_note()
            );

            // Log coupon information for debugging
                if (!empty($coupons_data)) {
                    $logger->info('QuxPay: Processing order with coupons', array(
                        'source' => 'quxpay-payment',
                        'order_id' => $order_id,
                        'coupons' => $coupons_data,
                        'total_discount' => $total_discount,
                        'original_total' => $subtotal,
                        'final_total' => $order_total
                    ));
                }

            $data = base64_encode(json_encode($args));
            
            $redirect = 'https://quxpay.com/login-or-register?t=' . $data;
            
            return array(
                'result' => 'success',
                'redirect' => $redirect
            );
        }
        
        // Detect which subscription plugin is active
        private function detect_subscription_plugin() {
            if (class_exists('WC_Subscriptions')) {
                return 'woocommerce_subscriptions';
            }
            
            if (function_exists('wps_sfw_check_plugin_enable') || 
                is_plugin_active('subscriptions-for-woocommerce/subscriptions-for-woocommerce.php') ||
                class_exists('Subscriptions_For_Woocommerce_Admin')) {
                return 'wps_subscriptions';
            }
            
            return 'none';
        }
        
        public function custom_auto_renew_subscription($subscription, $renewal_order) {
            if ($this->should_renew_subscription($subscription)) {
                $renewal_order_id = $this->create_renewal_order($subscription);
        
                if ($renewal_order_id) {
                    $this->process_renewal_payment($renewal_order_id);
                }
            }
        }

        private function should_renew_subscription($subscription) {
            return ($subscription->get_status() === 'active' && $subscription->get_date('end') < strtotime('+1 week'));
        }

        public function create_renewal_order($subscription) {
            // HPOS-compatible order creation
            $renewal_order = wc_create_order(array(
                'customer_id' => $subscription->get_customer_id(),
                'billing_email' => $subscription->get_billing_email(),
                'currency' => $subscription->get_currency(),
            ));
        
            if (is_wp_error($renewal_order)) {
                return false;
            }
        
            $subscription_product_id = $subscription->get_parent_id();
            $product = wc_get_product($subscription_product_id);
            if ($product) {
                $renewal_order->add_product($product, 1);
            }
        
            $renewal_order->set_status('pending');
            $renewal_order->calculate_totals();
            $renewal_order->save();
        
            return $renewal_order->get_id();
        }

        public function process_renewal_payment($renewal_order_id) {
            // HPOS-compatible order retrieval
            $renewal_order = wc_get_order($renewal_order_id);
        
            if ($renewal_order) {
                $renewal_order->payment_complete();
                $renewal_order->add_order_note(__('Renewal payment processed successfully.', 'quxpay'));
                $renewal_order->save();
            }
        }
        
        public function process_subscription_payment($amount_to_charge, $order) {
            $logger = wc_get_logger();
         
            // API endpoint
            $api = $this->testmode ? 'https://p2.api.quxtech.tv/v/' : 'https://api.qux.tv/v/';
            
            // HPOS-compatible order data access
            $order_id = $order->get_id();
            $order_total = $order->get_total();
            $billing_email = $order->get_billing_email();
            $parent_order_id = $order->get_parent_id();

            if ($parent_order_id === 0) {
                // Try to get subscription renewal meta (HPOS-compatible)
                $parent_subscription_id = $order->get_meta('_subscription_renewal');
                if ($parent_subscription_id && function_exists('wcs_get_subscription')) {
                    $parent_subscription = wcs_get_subscription($parent_subscription_id);
                    if ($parent_subscription) {
                        $parent_order_id = $parent_subscription->get_parent_id();
                    }
                }
            }

            $line_items = $order->get_items();
            $products = [];
            foreach ($line_items as $item_id => $item) {
                $products[] = [
                    'id' => $item->get_product_id(),
                    'quantity' => $item->get_quantity(),
                    'product_name' => $item->get_name(),
                    'product_price' => $item->get_total(),
                    'subscription' => true,
                ];
            }

            $body = [
                'order_id' => $order_id,
                'order_total' => $order_total,
                'billing_email' => $billing_email,
                'parent_id' => $parent_order_id,
                'products' => $products,
                'amount_to_charge' => $amount_to_charge,
                'line_items' => $line_items,
                'secret_key' => $this->secret_key,
                'app_key' => $this->app_key,
            ];

            try {
                $response = wp_remote_post($api . 'woocommerce/webhook/auto-payment', array(
                    'method' => 'POST',
                    'headers' => array(
                        'Content-Type' => 'application/json',
                    ),
                    'body' => json_encode($body),
                ));
         
                if (is_wp_error($response)) {
                    $logger->info('RESPONSE FROM ERROR: ' . $response->get_error_message());
                    return array('success' => false, 'message' => $response->get_error_message());
                } else {
                    $response_body = wp_remote_retrieve_body($response);
                    $data = json_decode($response_body, true);

                    if ($data !== null && isset($data['data']) && is_array($data['data']) && isset($data['data']['status'])) {
                        $status = $data['data']['status'];
                        
                        // HPOS-compatible status update
                        $order->update_status($data['data']['status']);
                        $order->save();
                        
                        return $status;
                    }
                    
                    return $data;
                }
            } catch (Exception $e) {
                $logger->info('TRY CATCH ERROR: ' . $e->getMessage());
            }
        }
    }
}

// Register the gateway with WooCommerce
add_filter('woocommerce_payment_gateways', 'qux_add_gateway_class', 10, 1);
function qux_add_gateway_class($gateways) {
    if (class_exists('WC_Qux_Pay_Gateway')) {
        $gateways[] = 'WC_Qux_Pay_Gateway';
    }
    return $gateways;
}

// Force gateway availability check on checkout page load
add_action('woocommerce_checkout_init', 'quxpay_ensure_gateway_loaded');
function quxpay_ensure_gateway_loaded() {
    if (class_exists('WC_Qux_Pay_Gateway')) {
        $gateways = WC()->payment_gateways()->payment_gateways();
        if (!isset($gateways['quxpay'])) {
            WC()->payment_gateways()->init();
        }
    }
}

// Force gateway availability specifically for WPS Subscriptions
add_filter('woocommerce_available_payment_gateways', 'quxpay_force_availability_for_wps', 20, 1);
function quxpay_force_availability_for_wps($available_gateways) {
    if (!is_checkout() && !wp_doing_ajax()) {
        return $available_gateways;
    }
    
    if (!class_exists('WC_Qux_Pay_Gateway')) {
        return $available_gateways;
    }
    
    $is_wps_active = function_exists('wps_sfw_check_plugin_enable') || 
                     is_plugin_active('subscriptions-for-woocommerce/subscriptions-for-woocommerce.php') ||
                     class_exists('Subscriptions_For_Woocommerce_Admin');
    
    if (!$is_wps_active) {
        return $available_gateways;
    }
    
    $has_wps_subscription = false;
    if (WC()->cart && !WC()->cart->is_empty()) {
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $product_id = $product->get_id();
            
            $wps_meta_keys = array(
                '_wps_sfw_product',
                '_subscription_price',
                '_wps_sfw_subscription_price',
                '_wps_sfw_subscription_period',
                'wps_sfw_product',
                '_wps_subscription_product'
            );
            
            foreach ($wps_meta_keys as $meta_key) {
                $meta_value = get_post_meta($product_id, $meta_key, true);
                if (!empty($meta_value) && $meta_value !== 'no') {
                    $has_wps_subscription = true;
                    break 2;
                }
            }
            
            if (method_exists($product, 'get_type') && in_array($product->get_type(), array('wps_subscription', 'subscription'))) {
                $has_wps_subscription = true;
                break;
            }
        }
    }
    
    if ($has_wps_subscription && !isset($available_gateways['quxpay'])) {
        $gateway = new WC_Qux_Pay_Gateway();
        
        if ($gateway->get_option('enabled') === 'yes' && 
            !empty($gateway->get_option('app_key')) && 
            !empty($gateway->get_option('secret_key'))) {
            $available_gateways['quxpay'] = $gateway;
        }
    }
    
    return $available_gateways;
}

// Add WPS Subscription support hooks
add_action('init', 'quxpay_add_wps_subscription_hooks', 20);
function quxpay_add_wps_subscription_hooks() {
    if (function_exists('wps_sfw_check_plugin_enable') || 
        is_plugin_active('subscriptions-for-woocommerce/subscriptions-for-woocommerce.php')) {
        
        if (has_action('wps_sfw_subscription_renewal')) {
            add_action('wps_sfw_subscription_renewal', 'quxpay_handle_wps_renewal', 10, 2);
        }
        
        if (has_action('wps_sfw_subscription_payment')) {
            add_action('wps_sfw_subscription_payment', 'quxpay_handle_wps_payment', 10, 2);
        }
    }
}

// Handle WPS subscription renewals (HPOS-compatible)
function quxpay_handle_wps_renewal($subscription_id, $order_id) {
    $order = wc_get_order($order_id);
    if ($order && $order->get_payment_method() === 'quxpay') {
        error_log('QuxPay: WPS Subscription renewal for order ' . $order_id);
    }
}

// Handle WPS subscription payments (HPOS-compatible)
function quxpay_handle_wps_payment($subscription_id, $order_id) {
    $order = wc_get_order($order_id);
    if ($order && $order->get_payment_method() === 'quxpay') {
        error_log('QuxPay: WPS Subscription payment for order ' . $order_id);
    }
}

// Clear any caches that might affect gateway visibility
add_action('woocommerce_checkout_process', 'quxpay_refresh_payment_gateways');
function quxpay_refresh_payment_gateways() {
    if (function_exists('WC') && WC()->payment_gateways()) {
        WC()->payment_gateways()->init();
    }
}

// Debug function specifically for WPS Subscriptions (remove in production)
add_action('wp_footer', 'quxpay_debug_wps_status');
function quxpay_debug_wps_status() {
    if (is_checkout() && current_user_can('administrator') && isset($_GET['qux_wps_debug'])) {
        $is_wps_active = function_exists('wps_sfw_check_plugin_enable') || 
                         is_plugin_active('subscriptions-for-woocommerce/subscriptions-for-woocommerce.php') ||
                         class_exists('Subscriptions_For_Woocommerce_Admin');
        
        echo '<script>console.log("WPS Subscriptions Active: ' . ($is_wps_active ? 'Yes' : 'No') . '");</script>';
        
        if (WC()->cart && !WC()->cart->is_empty()) {
            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];
                $product_id = $product->get_id();
                
                echo '<script>console.log("Product ID: ' . $product_id . '");</script>';
                echo '<script>console.log("Product Type: ' . $product->get_type() . '");</script>';
                
                $wps_meta = get_post_meta($product_id, '_wps_sfw_product', true);
                echo '<script>console.log("WPS Meta (_wps_sfw_product): ' . $wps_meta . '");</script>';
                
                $sub_price = get_post_meta($product_id, '_subscription_price', true);
                echo '<script>console.log("Subscription Price: ' . $sub_price . '");</script>';
            }
        }
        
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        echo '<script>console.log("Available Gateways: ' . implode(', ', array_keys($gateways)) . '");</script>';
    }
}

/**
 * ============================================================================
 * QUX® Pay API INTEGRATION - ORDER COMPLETION HANDLER
 * ============================================================================
 * Register custom REST API endpoint for QUX® Pay to communicate order completion
 * Endpoint: POST https://yoursite.com/wp-json/quxpay/v1/order-complete
 */
add_action('rest_api_init', function() {
    register_rest_route('quxpay/v1', '/order-complete', array(
        'methods' => 'POST',
        'callback' => 'quxpay_handle_order_completion_from_qux',
        'permission_callback' => 'quxpay_verify_qux_request',
    ));
    
    // Test endpoint
    register_rest_route('quxpay/v1', '/test', array(
        'methods' => 'GET',
        'callback' => function() {
            return new WP_REST_Response(array(
                'status' => 'ok',
                'message' => 'QuxPay API endpoint is working',
                'endpoint' => rest_url('quxpay/v1/order-complete'),
                'wordpress_version' => get_bloginfo('version'),
                'woocommerce_version' => WC()->version,
                'quxpay_version' => QUXPAY_VERSION
            ), 200);
        },
        'permission_callback' => '__return_true'
    ));

    register_rest_route('quxpay/v1', '/order-note', array(
        'methods' => 'POST',
        'callback' => 'quxpay_add_order_note_from_qux',
        'permission_callback' => 'quxpay_verify_qux_request',
    ));
});

/**
 * Verify that the request is coming from your QUX® Pay application
 * This uses API key authentication
 */
function quxpay_verify_qux_request($request) {
    // Get the gateway settings
    $gateways = WC()->payment_gateways->get_available_payment_gateways();
    
    if (!isset($gateways['quxpay'])) {
        // Try to instantiate the gateway directly
        if (class_exists('WC_Qux_Pay_Gateway')) {
            $gateway = new WC_Qux_Pay_Gateway();
            $stored_app_key = $gateway->get_option('app_key');
            $stored_secret_key = $gateway->get_option('secret_key');
        } else {
            return new WP_Error('gateway_not_found', 'QUX Payment gateway not configured', array('status' => 500));
        }
    } else {
        $gateway = $gateways['quxpay'];
        $stored_app_key = $gateway->get_option('app_key');
        $stored_secret_key = $gateway->get_option('secret_key');
    }
    
    // Get authorization from header
    $auth_header = $request->get_header('Authorization');
    $app_key = $request->get_header('X-QuxPay-App-Key');
    
    // Method 1: Bearer token (using secret_key)
    if ($auth_header && strpos($auth_header, 'Bearer ') === 0) {
        $token = substr($auth_header, 7);
        if ($token === $stored_secret_key) {
            return true;
        }
    }
    
    // Method 2: App Key header
    if ($app_key && $app_key === $stored_app_key) {
        // Verify secret key in request body
        $body = $request->get_json_params();
        if (isset($body['secret_key']) && $body['secret_key'] === $stored_secret_key) {
            return true;
        }
    }
    
    // Method 3: Signature verification (recommended for production)
    $signature = $request->get_header('X-QuxPay-Signature');
    if ($signature) {
        $body = $request->get_body();
        $expected_signature = hash_hmac('sha256', $body, $stored_secret_key);
        
        if (hash_equals($expected_signature, $signature)) {
            return true;
        }
    }
    
    return new WP_Error('unauthorized', 'Invalid authentication credentials', array('status' => 401));
}

/**
 * Handle order completion notification from QUX® Pay
 * 
 * Expected JSON payload from QUX® Pay:
 * {
 *   "order_id": 12345,
 *   "reference_number": "TXN123456789",
 *   "status": "completed",
 *   "transaction_id": "qux_txn_abc123",
 *   "amount": 99.99,
 *   "currency": "USD",
 *   "payment_method": "bank_transfer",
 *   "customer_email": "customer@example.com",
 *   "secret_key": "your_secret_key",
 *   "timestamp": 1234567890,
 *   "metadata": {
 *     "payment_date": "2024-01-01 12:00:00",
 *     "bank_name": "Example Bank"
 *   }
 * }
 */
function quxpay_handle_order_completion_from_qux($request) {
    $logger = wc_get_logger();
    $context = array('source' => 'quxpay-qux-api');
    
    // Get JSON parameters
    $params = $request->get_json_params();
    
    // Validate required fields
    $required_fields = array('order_id', 'status');
    foreach ($required_fields as $field) {
        if (!isset($params[$field]) || empty($params[$field])) {
            $logger->error("Missing required field: $field", $context);
            return new WP_Error(
                'missing_field',
                "Missing required field: $field",
                array('status' => 400)
            );
        }
    }
    
    $order_id = intval($params['order_id']);
    $status = strtolower(sanitize_text_field($params['status']));
    $transaction_id = isset($params['transaction_id']) ? sanitize_text_field($params['transaction_id']) : 'qux_' . $order_id . '_' . time();
    $amount = isset($params['amount']) ? floatval($params['amount']) : null;
    
    // Get the order (HPOS compatible)
    $order = wc_get_order($order_id);
    
    if (!$order) {
        $logger->error("Order not found: $order_id", $context);
        return new WP_Error(
            'order_not_found',
            "Order #$order_id not found",
            array('status' => 404)
        );
    }
    
    // Verify order is using QuxPay gateway
    if ($order->get_payment_method() !== 'quxpay') {
        $logger->error("Order $order_id is not using QuxPay gateway", $context);
        return new WP_Error(
            'invalid_payment_method',
            "Order is not using QuxPay payment method",
            array('status' => 400)
        );
    }
    
    // Check if order is already completed to avoid duplicate processing
    $current_status = $order->get_status();
    if (in_array($current_status, array('completed', 'refunded', 'cancelled'))) {
        $logger->info("Order $order_id already in final state: $current_status", $context);
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Order already in final state',
            'order_id' => $order_id,
            'current_status' => $current_status,
            'already_processed' => true
        ), 200);
    }
    
    // Verify amount if provided
    if ($amount !== null) {
        $order_total = floatval($order->get_total());
        if (abs($order_total - $amount) > 0.01) { // Allow 1 cent difference for rounding
            $logger->warning("Amount mismatch for order $order_id. Expected: $order_total, Received: $amount", $context);
            return new WP_Error(
                'amount_mismatch',
                "Payment amount does not match order total",
                array('status' => 400)
            );
        }
    }
    
    // Log the completion request
    $logger->info("Processing order completion from QUX® Pay for order $order_id", array_merge($context, array(
        'status' => $status
    )));
    
    // Add order note
    $order->add_order_note(sprintf(
        __('Payment confirmed by QUX® API. Transaction ID: %s', 'quxpay'),
        $transaction_id
    ));
    
    // Store payment metadata
    $order->update_meta_data('_quxpay_transaction_id', $transaction_id);
    $order->update_meta_data('_quxpay_payment_completed_at', current_time('mysql'));
    $order->update_meta_data('_quxpay_qux_status', $status);
    
    // Store additional metadata if provided
    if (isset($params['metadata']) && is_array($params['metadata'])) {
        foreach ($params['metadata'] as $key => $value) {
            $meta_key = '_quxpay_' . sanitize_key($key);
            $order->update_meta_data($meta_key, sanitize_text_field($value));
        }
    }
    
    // Process based on status from QUX® Pay
    $wc_status = 'processing'; // Default WooCommerce status
    $order_note = '';
    
    switch ($status) {
        case 'completed':
        case 'complete':
        case 'success':
        case 'paid':
            // Mark payment as complete
            $order->payment_complete($transaction_id);
            
            // Update to completed status
            $order->update_status('completed', __('Order automatically completed by QUX® Payment API.', 'quxpay'));
            
            // Reduce stock levels
            wc_reduce_stock_levels($order_id);
            
            $wc_status = 'completed';
            $order_note = 'Payment completed and order fulfilled.';
            
            $logger->info("Order $order_id marked as completed", $context);
            break;
            
        case 'processing':
        case 'pending_fulfillment':
            // Mark payment as complete but keep in processing
            $order->payment_complete($transaction_id);
            $order->update_status('processing', __('Payment received, processing order.', 'quxpay'));
            
            // Reduce stock for processing orders
            wc_reduce_stock_levels($order_id);
            
            $wc_status = 'processing';
            $order_note = 'Payment received, order is being processed.';
            
            $logger->info("Order $order_id marked as processing", $context);
            break;
            
        case 'pending':
        case 'on-hold':
            $order->update_status('on-hold', __('Payment is pending verification.', 'quxpay'));
            
            $wc_status = 'on-hold';
            $order_note = 'Payment pending verification.';
            
            $logger->info("Order $order_id placed on-hold", $context);
            break;
            
        case 'failed':
        case 'cancelled':
            $order->update_status('failed', __('Payment failed or was cancelled.', 'quxpay'));
            
            $wc_status = 'failed';
            $order_note = 'Payment failed or cancelled.';
            
            $logger->warning("Order $order_id marked as failed", $context);
            break;
            
        case 'refunded':
            $order->update_status('refunded', __('Payment was refunded.', 'quxpay'));
            
            $wc_status = 'refunded';
            $order_note = 'Payment refunded.';
            
            $logger->info("Order $order_id marked as refunded", $context);
            break;
            
        default:
            $logger->warning("Unknown status '$status' for order $order_id, defaulting to processing", $context);
            $order->payment_complete($transaction_id);
            $order->update_status('processing', sprintf(
                __('Payment received with status: %s', 'quxpay'),
                $status
            ));
            $wc_status = 'processing';
            $order_note = "Payment received with status: $status";
    }
    
    // Save all changes
    $order->save();
    
    // Trigger WooCommerce actions for email notifications etc.
    if ($wc_status === 'completed') {
        do_action('woocommerce_order_status_completed', $order_id);
    } elseif ($wc_status === 'processing') {
        do_action('woocommerce_order_status_processing', $order_id);
    }
    
    // Prepare success response
    $response_data = array(
        'success' => true,
        'message' => 'Order status updated successfully',
        'order_id' => $order_id,
        'previous_status' => $current_status,
        'new_status' => $wc_status,
        'transaction_id' => $transaction_id,
        'order_total' => $order->get_total(),
        'currency' => $order->get_currency(),
        'customer_email' => $order->get_billing_email(),
        'order_note' => $order_note,
        'timestamp' => current_time('mysql')
    );
    
    $logger->info("Order $order_id completed successfully", array_merge($context, array(
        'new_status' => $wc_status
    )));
    
    return new WP_REST_Response($response_data, 200);
}

function quxpay_add_order_note_from_qux($request) {
    $logger = wc_get_logger();
    $context = array('source' => 'quxpay-order-note-api');
    
    // Get JSON parameters
    $params = $request->get_json_params();
    
    // Validate required fields
    if (!isset($params['order_id']) || empty($params['order_id'])) {
        $logger->error("Missing order_id", $context);
        return new WP_Error(
            'missing_order_id',
            'Order ID is required',
            array('status' => 400)
        );
    }
    
    if (!isset($params['note']) || empty($params['note'])) {
        $logger->error("Missing note content", $context);
        return new WP_Error(
            'missing_note',
            'Note content is required',
            array('status' => 400)
        );
    }
    
    $order_id = intval($params['order_id']);
    $note_content = sanitize_textarea_field($params['note']);
    
    // Determine note type (customer visible or private)
    $is_customer_note = true; // Default to customer visible
    if (isset($params['note_type'])) {
        $is_customer_note = ($params['note_type'] === 'customer');
    } elseif (isset($params['is_customer_note'])) {
        $is_customer_note = (bool) $params['is_customer_note'];
    }
    
    $added_by_user = isset($params['added_by_user']) ? (bool) $params['added_by_user'] : false;
    
    // Get the order (HPOS compatible)
    $order = wc_get_order($order_id);
    
    if (!$order) {
        $logger->error("Order not found: $order_id", $context);
        return new WP_Error(
            'order_not_found',
            "Order #$order_id not found",
            array('status' => 404)
        );
    }
    
    // Optional: Verify order is using QuxPay gateway
    if ($order->get_payment_method() !== 'quxpay') {
        $logger->warning("Order $order_id is not using QuxPay gateway", $context);
        // We'll allow notes for non-QuxPay orders but log a warning
    }
    
    // Log the request
    $logger->info("Adding note to order $order_id from QUX® Pay API", array_merge($context, array(
        'note_type' => $is_customer_note ? 'customer' : 'private',
        'note_length' => strlen($note_content)
    )));
    
    // Add the note to the order
    $note_id = $order->add_order_note(
        $note_content,
        $is_customer_note ? 1 : 0, // 1 = customer note, 0 = private note
        $added_by_user
    );
    
    if (!$note_id) {
        $logger->error("Failed to add note to order $order_id", $context);
        return new WP_Error(
            'note_creation_failed',
            'Failed to add note to order',
            array('status' => 500)
        );
    }
    
    // Save the order
    $order->save();
    
    // Log success
    $logger->info("Note added successfully to order $order_id", array_merge($context, array(
        'note_id' => $note_id
    )));
    
    // Prepare success response
    $response_data = array(
        'success' => true,
        'message' => 'Order note added successfully',
        'order_id' => $order_id,
        'note_id' => $note_id,
        'note_type' => $is_customer_note ? 'customer' : 'private',
        'note_preview' => mb_substr($note_content, 0, 100) . (strlen($note_content) > 100 ? '...' : ''),
        'timestamp' => current_time('mysql'),
        'order_status' => $order->get_status()
    );
    
    return new WP_REST_Response($response_data, 200);
}
/**
 * ============================================================================
 * SUCCESS PAGE HANDLER WITH AUTO-COMPLETE
 * ============================================================================
 */

// Enhanced success page shortcode with auto-complete functionality
function qux_payment_success() {
    global $woocommerce;

    // Add custom CSS
    $contents = '<style>
        .qux-success-container {
            max-width: 600px;
            margin: 40px auto;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        }
        .qux-success-header {
            text-align: center;
            background: #ffffff;
            padding: 60px 40px 40px;
            border-radius: 8px 8px 0 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .qux-success-title {
            font-size: 48px;
            font-weight: 300;
            color: #333;
            margin: 0;
            letter-spacing: -1px;
        }
        .qux-success-icon {
            width: 80px;
            height: 80px;
            background: #4CAF50;
            border-radius: 50%;
            margin: 0 auto 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            color: white;
        }
        .qux-success-content {
            background: #f8f9fa;
            padding: 40px;
            border-radius: 0 0 8px 8px;
            text-align: center;
        }
        .qux-success-message {
            font-size: 18px;
            color: #666;
            margin: 0 0 30px;
            line-height: 1.5;
        }
        .qux-order-details {
            background: white;
            padding: 25px;
            border-radius: 6px;
            margin: 20px 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .qux-detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .qux-detail-row:last-child {
            border-bottom: none;
        }
        .qux-detail-label {
            font-weight: 600;
            color: #333;
        }
        .qux-detail-value {
            color: #666;
            font-family: monospace;
        }
        .qux-buttons {
            margin-top: 30px;
        }
        .qux-button {
            display: inline-block;
            padding: 12px 24px;
            margin: 0 10px;
            background: #007cba;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            transition: background-color 0.2s;
        }
        .qux-button:hover {
            background: #005a87;
            color: white;
            text-decoration: none;
        }
        .qux-button-secondary {
            background: transparent;
            color: #007cba;
            border: 2px solid #007cba;
        }
        .qux-button-secondary:hover {
            background: #007cba;
            color: white;
        }
        .qux-error-container {
            max-width: 600px;
            margin: 40px auto;
            text-align: center;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .qux-error-header {
            background: #dc3545;
            color: white;
            padding: 40px;
        }
        .qux-error-icon {
            font-size: 40px;
            margin-bottom: 20px;
        }
        .qux-error-title {
            font-size: 32px;
            margin: 0;
            font-weight: 300;
        }
        .qux-error-content {
            padding: 40px;
        }
        @media (max-width: 768px) {
            .qux-success-container, .qux-error-container {
                margin: 20px;
                max-width: none;
            }
            .qux-success-header, .qux-success-content, .qux-error-header, .qux-error-content {
                padding: 30px 20px;
            }
            .qux-success-title {
                font-size: 36px;
            }
            .qux-detail-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            .qux-buttons {
                flex-direction: column;
            }
            .qux-button {
                display: block;
                margin: 5px 0;
            }
        }
    </style>';

    if (isset($_GET['success'])) {
        $data = base64_decode($_GET['success']);
        $data = json_decode($data);

        if (isset($data->success) && $data->success) {
            
            $order_id = $data->invoice_num;
            // HPOS-compatible order retrieval
            $order = wc_get_order($order_id);

            if ($order) {
                // Process the order based on the status from API
                $api_status = isset($data->status) ? strtolower($data->status) : '';
                $current_status = $order->get_status();
                
                // Log the payment success
                $order->add_order_note(sprintf(
                    __('QUX® Payment received successfully. Reference: %s', 'quxpay'),
                    esc_html($data->reference_num)
                ));
                
                // Auto-complete the order based on API response
                if ($api_status === 'completed' || $api_status === 'complete') {
                    // Mark payment as complete
                    $order->payment_complete($data->reference_num);
                    
                    // Update order status to completed
                    $order->update_status('completed', __('Order auto-completed after successful QUX® payment.', 'quxpay'));
                    
                    // Reduce stock levels
                    wc_reduce_stock_levels($order_id);
                    
                    // Add completion note
                    $order->add_order_note(__('Order automatically completed by QUX® Payment Gateway.', 'quxpay'));
                    
                } elseif ($api_status === 'processing') {
                    // Mark as processing if that's what API returns
                    $order->payment_complete($data->reference_num);
                    $order->update_status('processing', __('Payment received via QUX®, order is being processed.', 'quxpay'));
                    
                    // Reduce stock for processing orders too
                    wc_reduce_stock_levels($order_id);
                    
                } elseif (in_array($api_status, array('pending', 'on-hold'))) {
                    // Handle pending payments
                    $order->update_status($api_status, sprintf(
                        __('QUX® Payment status: %s', 'quxpay'),
                        $api_status
                    ));
                } else {
                    // Default: mark payment complete and set to processing
                    $order->payment_complete($data->reference_num);
                }
                
                // Save order meta with payment details
                $order->update_meta_data('_quxpay_reference_number', $data->reference_num);
                $order->update_meta_data('_quxpay_transaction_id', $data->reference_num);
                $order->update_meta_data('_quxpay_payment_status', $api_status);
                $order->save();

                // Empty the cart if it exists and has items
                if (function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
                    WC()->cart->empty_cart();
                }
                
                // Trigger order completion emails
                do_action('woocommerce_order_status_completed', $order_id);
            }

            // Success page layout
            $contents .= '<div class="qux-success-container">';
            $contents .= '    <div class="qux-success-header">';
            $contents .= '        <div class="qux-success-icon">✓</div>';
            $contents .= '        <h1 class="qux-success-title">Order Successful</h1>';
            $contents .= '    </div>';
            $contents .= '    <div class="qux-success-content">';
            $contents .= '        <p class="qux-success-message">Thank you for your payment! Your order has been processed and completed successfully.</p>';
            
            $contents .= '        <div class="qux-order-details">';
            $contents .= '            <div class="qux-detail-row">';
            $contents .= '                <span class="qux-detail-label">Reference Number:</span>';
            $contents .= '                <span class="qux-detail-value">' . esc_html($data->reference_num) . '</span>';
            $contents .= '            </div>';
            $contents .= '            <div class="qux-detail-row">';
            $contents .= '                <span class="qux-detail-label">Order ID:</span>';
            $contents .= '                <span class="qux-detail-value">' . esc_html($data->invoice_num) . '</span>';
            $contents .= '            </div>';
            $contents .= '            <div class="qux-detail-row">';
            $contents .= '                <span class="qux-detail-label">Status:</span>';
            $contents .= '                <span class="qux-detail-value" style="text-transform: capitalize; color: #4CAF50; font-weight: 600;">Completed</span>';
            $contents .= '            </div>';
            $contents .= '        </div>';
            
            $contents .= '        <div class="qux-buttons">';
            
            // Add link to view order if user is logged in
            if (is_user_logged_in() && $order) {
                $view_order_url = $order->get_view_order_url();
                $contents .= '<a href="' . esc_url($view_order_url) . '" class="qux-button">View Order Details</a>';
            }
            
            $contents .= '            <a href="' . esc_url(wc_get_page_permalink('shop')) . '" class="qux-button qux-button-secondary">Continue Shopping</a>';
            $contents .= '        </div>';
            $contents .= '    </div>';
            $contents .= '</div>';
            
        } else {
            // Error message
            $contents .= '<div class="qux-error-container">';
            $contents .= '    <div class="qux-error-header">';
            $contents .= '        <div class="qux-error-icon">⚠</div>';
            $contents .= '        <h1 class="qux-error-title">Payment Error</h1>';
            $contents .= '    </div>';
            $contents .= '    <div class="qux-error-content">';
            $contents .= '        <p>There was an issue processing your payment. Please contact our support team if you need assistance.</p>';
            $contents .= '        <div class="qux-buttons">';
            $contents .= '            <a href="' . esc_url(wc_get_checkout_url()) . '" class="qux-button">Return to Checkout</a>';
            $contents .= '            <a href="' . esc_url(home_url()) . '" class="qux-button qux-button-secondary">Go to Homepage</a>';
            $contents .= '        </div>';
            $contents .= '    </div>';
            $contents .= '</div>';
        }
    } else {
        // No payment data found
        $contents .= '<div class="qux-error-container">';
        $contents .= '    <div class="qux-error-header" style="background: #ffc107;">';
        $contents .= '        <div class="qux-error-icon">ℹ</div>';
        $contents .= '        <h1 class="qux-error-title">Payment Information</h1>';
        $contents .= '    </div>';
        $contents .= '    <div class="qux-error-content">';
        $contents .= '        <p>No payment information found. If you have completed a payment, please check your email for confirmation.</p>';
        $contents .= '        <div class="qux-buttons">';
        $contents .= '            <a href="' . esc_url(home_url()) . '" class="qux-button">Go to Homepage</a>';
        $contents .= '        </div>';
        $contents .= '    </div>';
        $contents .= '</div>';
    }

    return $contents;
}

add_shortcode('quxpay_success', 'qux_payment_success');

/**
 * ============================================================================
 * ADMIN INTERFACE ENHANCEMENTS
 * ============================================================================
 */

// Add admin notice when success page is created
add_action('admin_notices', 'quxpay_success_page_notice');
function quxpay_success_page_notice() {
    // Show notice when page is created
    if (get_transient('quxpay_success_page_created')) {
        $page_url = quxpay_get_success_page_url();
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>🎉 QUX® Payment Gateway:</strong> Success page has been created automatically at <a href="' . esc_url($page_url) . '" target="_blank">' . esc_url($page_url) . '</a></p>';
        echo '</div>';
        delete_transient('quxpay_success_page_created');
    }
    
    // Show notice if there's an issue with the success page
    $page_id = get_option('quxpay_success_page_id');
    if ($page_id && get_post_status($page_id) !== 'publish') {
        echo '<div class="notice notice-warning">';
        echo '<p><strong>⚠️ QUX® Payment Gateway:</strong> Success page exists but is not published. <a href="' . esc_url(admin_url('post.php?post=' . $page_id . '&action=edit')) . '">Edit Page</a></p>';
        echo '</div>';
    }
}

// Add a menu item in the admin for easy access
add_action('admin_menu', 'quxpay_add_admin_menu');
function quxpay_add_admin_menu() {
    add_submenu_page(
        'woocommerce',
        'QUX® Payment Pages',
        'QUX® Payment',
        'manage_options',
        'quxpay-pages',
        'quxpay_admin_page_callback'
    );
}

// Admin page callback
function quxpay_admin_page_callback() {
    $page_id = get_option('quxpay_success_page_id');
    $page_url = quxpay_get_success_page_url();
    $api_endpoint = rest_url('quxpay/v1/order-complete');
    
    echo '<div class="wrap">';
    echo '<h1>QUX® Payment Gateway Configuration</h1>';
    
    // Success Page Status
    echo '<div class="card" style="max-width: none;">';
    echo '<h2>Success Page Status</h2>';
    if ($page_id && get_post_status($page_id) === 'publish') {
        echo '<div class="notice notice-success inline">';
        echo '<p><strong>✅ Success Page Status:</strong> Active and working</p>';
        echo '</div>';
        
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row">Success Page URL</th>';
        echo '<td><a href="' . esc_url($page_url) . '" target="_blank">' . esc_url($page_url) . '</a></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row">Page ID</th>';
        echo '<td>' . $page_id . ' <a href="' . esc_url(admin_url('post.php?post=' . $page_id . '&action=edit')) . '" class="button button-small">Edit Page</a></td>';
        echo '</tr>';
        echo '</table>';
    } else {
        echo '<div class="notice notice-warning inline">';
        echo '<p><strong>⚠️ Success Page Status:</strong> Not found or not published</p>';
        echo '</div>';
        
        echo '<p><button class="button button-primary" onclick="location.reload()">Recreate Success Page</button></p>';
        
        // Try to recreate the page
        if (isset($_GET['recreate']) || !$page_id) {
            quxpay_create_success_page();
            echo '<script>window.location.reload();</script>';
        }
    }
    echo '</div>';
}

/**
 * Add endpoint information to the gateway settings page
 */
add_action('woocommerce_update_options_payment_gateways_quxpay', 'quxpay_display_api_endpoint_info');
function quxpay_display_api_endpoint_info() {
    $endpoint_url = rest_url('quxpay/v1/order-complete');
    ?>
    <tr valign="top">
        <th scope="row" class="titledesc">
            <label>QUX® Payment API Endpoint</label>
        </th>
        <td class="forminp">
            <fieldset>
                <p class="description">
                    Your QUX® Payment application should send POST requests to this endpoint to complete orders:<br>
                    <strong><code><?php echo esc_url($endpoint_url); ?></code></strong>
                </p>
                <p class="description">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=quxpay-pages')); ?>" class="button button-secondary">View Integration Details</a>
                </p>
            </fieldset>
        </td>
    </tr>
    <?php
}

/**
 * Log all incoming requests to the endpoint (for debugging - remove in production)
 */
add_action('rest_pre_dispatch', function($result, $server, $request) {
    if (strpos($request->get_route(), '/quxpay/v1/order-complete') !== false) {
        $logger = wc_get_logger();
        $logger->debug('Incoming request to order-complete endpoint', array(
            'source' => 'quxpay-qux-api',
            'method' => $request->get_method(),
            'params' => $request->get_json_params(),
            'headers' => array(
                'authorization' => $request->get_header('Authorization') ? 'Bearer ***' : 'Not set',
                'signature' => $request->get_header('X-QuxPay-Signature') ? 'Present' : 'Not set',
                'app_key' => $request->get_header('X-QuxPay-App-Key') ? 'Present' : 'Not set',
            )
        ));
    }
    return $result;
}, 10, 3);

/**
 * Handle auto-update toggle from plugins page
 */
add_action('admin_init', 'quxpay_handle_auto_update_toggle');
function quxpay_handle_auto_update_toggle() {
    if (!isset($_GET['action']) || $_GET['action'] !== 'toggle-auto-updates') {
        return;
    }

    if (!isset($_GET['plugin']) || $_GET['plugin'] !== QUXPAY_PLUGIN_BASENAME) {
        return;
    }

    if (!current_user_can('update_plugins')) {
        wp_die(__('Sorry, you are not allowed to modify plugins for this site.'));
    }

    check_admin_referer('updates');

    $auto_updates = get_option('auto_update_plugins', array());
    
    if (in_array(QUXPAY_PLUGIN_BASENAME, $auto_updates)) {
        // Remove from auto-updates
        $auto_updates = array_diff($auto_updates, array(QUXPAY_PLUGIN_BASENAME));
        $message = 'Auto-updates disabled for QUX® Payment Gateway.';
    } else {
        // Add to auto-updates
        $auto_updates[] = QUXPAY_PLUGIN_BASENAME;
        $message = 'Auto-updates enabled for QUX® Payment Gateway.';
    }

    update_option('auto_update_plugins', array_values(array_unique($auto_updates)));

    // Redirect back to plugins page with success message
    $redirect_url = add_query_arg(array(
        'quxpay-auto-update' => 'updated'
    ), admin_url('plugins.php'));
    
    wp_redirect($redirect_url);
    exit;
}

/**
 * Show admin notice for auto-update toggle
 */
add_action('admin_notices', 'quxpay_auto_update_admin_notice');
function quxpay_auto_update_admin_notice() {
    if (!isset($_GET['quxpay-auto-update'])) {
        return;
    }

    $auto_updates = get_option('auto_update_plugins', array());
    $is_enabled = in_array(QUXPAY_PLUGIN_BASENAME, $auto_updates);
    
    $message = $is_enabled 
        ? 'Auto-updates have been enabled for QUX® Payment Gateway.' 
        : 'Auto-updates have been disabled for QUX® Payment Gateway.';
    
    $class = $is_enabled ? 'notice-success' : 'notice-info';

    printf(
        '<div class="notice %s is-dismissible"><p><strong>%s</strong></p></div>',
        esc_attr($class),
        esc_html($message)
    );
}

/**
 * Ensure our plugin supports WordPress auto-updates
 */
add_filter('plugins_auto_update_enabled', '__return_true');

/**
 * Add auto-update status to plugin meta
 */
add_filter('plugin_row_meta', 'quxpay_add_auto_update_status', 10, 2);
function quxpay_add_auto_update_status($plugin_meta, $plugin_file) {
    if ($plugin_file !== QUXPAY_PLUGIN_BASENAME) {
        return $plugin_meta;
    }

    if (!wp_is_auto_update_enabled_for_type('plugin')) {
        return $plugin_meta;
    }

    $auto_updates = get_option('auto_update_plugins', array());
    $is_enabled = in_array(QUXPAY_PLUGIN_BASENAME, $auto_updates);

    if ($is_enabled) {
        $plugin_meta[] = '<span style="color: #46b450;">Auto-updates enabled</span>';
    }

    return $plugin_meta;
}

?>