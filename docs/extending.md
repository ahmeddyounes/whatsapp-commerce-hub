# Extending WhatsApp Commerce Hub

Complete guide for developers who want to extend and customize WhatsApp Commerce Hub.

## Table of Contents

- [Adding Custom Intents](#adding-custom-intents)
- [Creating Custom Payment Gateways](#creating-custom-payment-gateways)
- [Adding Custom Flow States](#adding-custom-flow-states)
- [Building Add-on Plugins](#building-add-on-plugins)
- [Customizing Templates](#customizing-templates)
- [Custom REST API Endpoints](#custom-rest-api-endpoints)
- [Custom Admin Pages](#custom-admin-pages)

---

## Adding Custom Intents

Custom intents allow you to handle specialized customer requests beyond the built-in intents.

### Basic Custom Intent

```php
/**
 * Register custom intent for store hours inquiry
 */
add_filter('wch_custom_intents', function($intents) {
    $intents[] = [
        'name' => 'ask_store_hours',
        'patterns' => [
            'what are your hours',
            'when are you open',
            'opening hours',
            'store hours',
            'when do you close'
        ],
        'confidence' => 0.85,
        'handler' => 'handle_store_hours_request'
    ];
    return $intents;
});

/**
 * Handle store hours request
 */
function handle_store_hours_request($conversation, $message, $context) {
    $hours = get_option('wch_business_hours', [
        'monday-friday' => '9:00 AM - 6:00 PM',
        'saturday' => '10:00 AM - 4:00 PM',
        'sunday' => 'Closed'
    ]);

    $response = "📅 Our Store Hours:\n\n";
    $response .= "Monday-Friday: " . $hours['monday-friday'] . "\n";
    $response .= "Saturday: " . $hours['saturday'] . "\n";
    $response .= "Sunday: " . $hours['sunday'];

    return [
        'type' => 'text',
        'content' => $response,
        'success' => true
    ];
}
```

### Advanced Intent with AI Integration

```php
/**
 * Custom intent with OpenAI function calling
 */
add_filter('wch_ai_functions', function($functions) {
    $functions[] = [
        'name' => 'check_warranty_status',
        'description' => 'Check warranty status for a product',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'order_id' => [
                    'type' => 'string',
                    'description' => 'Order ID or order number'
                ],
                'product_name' => [
                    'type' => 'string',
                    'description' => 'Product name'
                ]
            ],
            'required' => ['order_id']
        ]
    ];
    return $functions;
});

/**
 * Execute warranty check function
 */
add_filter('wch_ai_function_result', function($result, $function_name, $arguments, $context) {
    if ($function_name === 'check_warranty_status') {
        $order_id = $arguments['order_id'];
        $order = wc_get_order($order_id);

        if (!$order) {
            return [
                'status' => 'not_found',
                'message' => 'Order not found'
            ];
        }

        $purchase_date = $order->get_date_created();
        $warranty_months = 12;
        $expiry_date = $purchase_date->modify("+{$warranty_months} months");
        $now = new DateTime();

        $is_valid = $now < $expiry_date;
        $days_remaining = $is_valid ? $now->diff($expiry_date)->days : 0;

        return [
            'status' => $is_valid ? 'active' : 'expired',
            'purchase_date' => $purchase_date->format('Y-m-d'),
            'expiry_date' => $expiry_date->format('Y-m-d'),
            'days_remaining' => $days_remaining,
            'warranty_period' => "{$warranty_months} months"
        ];
    }
    return $result;
}, 10, 4);
```

### Intent with Multi-turn Conversation

```php
class Custom_Size_Guide_Intent {
    public function __construct() {
        add_filter('wch_custom_intents', [$this, 'register_intent']);
        add_filter('wch_fsm_action_execute', [$this, 'execute_action'], 10, 4);
    }

    public function register_intent($intents) {
        $intents[] = [
            'name' => 'request_size_guide',
            'patterns' => ['size guide', 'what size', 'sizing', 'measurements'],
            'confidence' => 0.8,
            'handler' => 'start_size_guide_flow'
        ];
        return $intents;
    }

    public function execute_action($result, $action_name, $conversation, $payload) {
        if ($action_name === 'start_size_guide_flow') {
            // Ask for product category
            return [
                'type' => 'interactive',
                'content' => [
                    'text' => 'Which category do you need size info for?',
                    'buttons' => [
                        ['id' => 'tops', 'title' => 'Tops & Shirts'],
                        ['id' => 'bottoms', 'title' => 'Pants & Jeans'],
                        ['id' => 'shoes', 'title' => 'Footwear']
                    ]
                ],
                'success' => true,
                'next_state' => 'SIZE_GUIDE_CATEGORY'
            ];
        }

        if ($action_name === 'show_size_guide') {
            $category = $payload['category'] ?? 'tops';
            $guide_image = $this->get_size_guide_image($category);

            return [
                'type' => 'image',
                'content' => [
                    'url' => $guide_image,
                    'caption' => 'Here\'s our size guide for ' . $category
                ],
                'success' => true
            ];
        }

        return $result;
    }

    private function get_size_guide_image($category) {
        $guides = [
            'tops' => 'https://yoursite.com/size-guides/tops.jpg',
            'bottoms' => 'https://yoursite.com/size-guides/bottoms.jpg',
            'shoes' => 'https://yoursite.com/size-guides/shoes.jpg'
        ];
        return $guides[$category] ?? $guides['tops'];
    }
}

new Custom_Size_Guide_Intent();
```

---

## Creating Custom Payment Gateways

Implement the `WCH_Payment_Gateway` interface to add custom payment methods.

### Basic Payment Gateway

```php
/**
 * Custom Bitcoin payment gateway
 */
class WCH_Payment_Bitcoin implements WCH_Payment_Gateway {

    /**
     * Get gateway ID
     */
    public function get_id() {
        return 'bitcoin';
    }

    /**
     * Get gateway name
     */
    public function get_name() {
        return __('Bitcoin (BTC)', 'my-plugin');
    }

    /**
     * Get gateway description
     */
    public function get_description() {
        return __('Pay with Bitcoin cryptocurrency', 'my-plugin');
    }

    /**
     * Check if gateway is available
     */
    public function is_available() {
        return get_option('wch_bitcoin_enabled', false);
    }

    /**
     * Process payment
     *
     * @param int   $order_id Order ID
     * @param array $data     Payment data
     * @return array Payment result
     */
    public function process_payment($order_id, $data) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return [
                'success' => false,
                'message' => 'Order not found'
            ];
        }

        // Generate Bitcoin payment address
        $btc_address = $this->generate_btc_address($order_id);
        $amount_btc = $this->convert_to_btc($order->get_total());

        // Save payment info
        update_post_meta($order_id, '_btc_address', $btc_address);
        update_post_meta($order_id, '_btc_amount', $amount_btc);

        // Set order as pending payment
        $order->update_status('pending', 'Awaiting Bitcoin payment');

        return [
            'success' => true,
            'message' => $this->get_payment_instructions($btc_address, $amount_btc),
            'requires_action' => true,
            'payment_url' => $this->get_payment_qr_url($btc_address, $amount_btc)
        ];
    }

    /**
     * Verify payment
     *
     * @param int    $order_id Order ID
     * @param string $transaction_id Transaction ID
     * @return bool Payment verified
     */
    public function verify_payment($order_id, $transaction_id) {
        $btc_address = get_post_meta($order_id, '_btc_address', true);

        // Check blockchain for transaction
        $confirmed = $this->check_blockchain_transaction($btc_address, $transaction_id);

        if ($confirmed) {
            $order = wc_get_order($order_id);
            $order->payment_complete($transaction_id);
            $order->add_order_note('Bitcoin payment received. Transaction: ' . $transaction_id);
            return true;
        }

        return false;
    }

    /**
     * Handle webhook from payment provider
     *
     * @param array $data Webhook data
     * @return bool Webhook handled
     */
    public function handle_webhook($data) {
        if (isset($data['transaction_id']) && isset($data['order_id'])) {
            return $this->verify_payment($data['order_id'], $data['transaction_id']);
        }
        return false;
    }

    /**
     * Get supported currencies
     *
     * @return array Currency codes
     */
    public function get_supported_currencies() {
        return ['USD', 'EUR', 'GBP']; // Will be converted to BTC
    }

    /**
     * Generate Bitcoin address for order
     */
    private function generate_btc_address($order_id) {
        // Integrate with your Bitcoin payment processor API
        // Example: BTCPay Server, Coinbase Commerce, etc.
        return 'bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh'; // Example address
    }

    /**
     * Convert fiat amount to BTC
     */
    private function convert_to_btc($amount) {
        $btc_rate = $this->get_btc_rate();
        return number_format($amount / $btc_rate, 8, '.', '');
    }

    /**
     * Get current BTC exchange rate
     */
    private function get_btc_rate() {
        // Get from cache or API
        $rate = wp_cache_get('btc_rate');
        if (!$rate) {
            // Fetch from API (e.g., CoinGecko, Coinbase)
            $response = wp_remote_get('https://api.coinbase.com/v2/exchange-rates?currency=BTC');
            $data = json_decode(wp_remote_retrieve_body($response), true);
            $rate = $data['data']['rates']['USD'] ?? 50000;
            wp_cache_set('btc_rate', $rate, '', 300); // Cache for 5 minutes
        }
        return $rate;
    }

    /**
     * Get payment instructions
     */
    private function get_payment_instructions($address, $amount) {
        return sprintf(
            "Please send exactly %s BTC to:\n\n%s\n\nWe'll confirm your payment automatically once received.",
            $amount,
            $address
        );
    }

    /**
     * Get QR code URL for payment
     */
    private function get_payment_qr_url($address, $amount) {
        return sprintf(
            'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=bitcoin:%s?amount=%s',
            $address,
            $amount
        );
    }

    /**
     * Check blockchain for transaction
     */
    private function check_blockchain_transaction($address, $txid) {
        // Integrate with blockchain API
        // Example: Blockchain.com API, BlockCypher, etc.
        return false; // Implement actual verification
    }
}

/**
 * Register the gateway
 */
add_action('wch_register_payment_gateways', function($payment_manager) {
    $payment_manager->register_gateway(new WCH_Payment_Bitcoin());
});
```

---

## Adding Custom Flow States

Extend the conversation FSM with custom states for specialized workflows.

### Custom State Example: Wishlist

```php
/**
 * Add Wishlist state to conversation flow
 */
class WCH_Wishlist_Extension {

    public function __construct() {
        add_filter('wch_fsm_transitions', [$this, 'add_wishlist_transitions']);
        add_filter('wch_fsm_action_execute', [$this, 'execute_wishlist_actions'], 10, 4);
        add_filter('wch_custom_intents', [$this, 'add_wishlist_intents']);
    }

    /**
     * Add transitions for wishlist state
     */
    public function add_wishlist_transitions($transitions) {
        // From browsing to wishlist
        $transitions[] = [
            'from' => 'BROWSING',
            'to' => 'WISHLIST',
            'event' => 'save_for_later',
            'guards' => ['product_selected'],
            'actions' => ['add_to_wishlist', 'show_wishlist']
        ];

        // From wishlist to cart
        $transitions[] = [
            'from' => 'WISHLIST',
            'to' => 'CART',
            'event' => 'move_to_cart',
            'guards' => ['wishlist_item_selected'],
            'actions' => ['move_wishlist_to_cart']
        ];

        // From wishlist back to browsing
        $transitions[] = [
            'from' => 'WISHLIST',
            'to' => 'BROWSING',
            'event' => 'continue_browsing',
            'guards' => [],
            'actions' => ['show_categories']
        ];

        return $transitions;
    }

    /**
     * Execute wishlist actions
     */
    public function execute_wishlist_actions($result, $action_name, $conversation, $payload) {
        global $wpdb;

        switch ($action_name) {
            case 'add_to_wishlist':
                $product_id = $payload['product_id'] ?? null;
                if (!$product_id) {
                    return $result;
                }

                // Add to wishlist table
                $wpdb->insert(
                    $wpdb->prefix . 'wch_wishlist',
                    [
                        'customer_phone' => $conversation->customer_phone,
                        'product_id' => $product_id,
                        'added_at' => current_time('mysql')
                    ],
                    ['%s', '%d', '%s']
                );

                return [
                    'type' => 'text',
                    'content' => '❤️ Added to your wishlist!',
                    'success' => true
                ];

            case 'show_wishlist':
                $items = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}wch_wishlist WHERE customer_phone = %s",
                    $conversation->customer_phone
                ));

                if (empty($items)) {
                    return [
                        'type' => 'text',
                        'content' => 'Your wishlist is empty.',
                        'success' => true
                    ];
                }

                $message = "💝 Your Wishlist:\n\n";
                $buttons = [];

                foreach ($items as $item) {
                    $product = wc_get_product($item->product_id);
                    $message .= "• " . $product->get_name() . " - " . wc_price($product->get_price()) . "\n";
                    $buttons[] = [
                        'id' => 'wishlist_' . $item->id,
                        'title' => '🛒 Add to cart'
                    ];
                }

                return [
                    'type' => 'interactive',
                    'content' => [
                        'text' => $message,
                        'buttons' => array_slice($buttons, 0, 3) // Max 3 buttons
                    ],
                    'success' => true
                ];

            case 'move_wishlist_to_cart':
                $wishlist_id = $payload['wishlist_id'] ?? null;
                if (!$wishlist_id) {
                    return $result;
                }

                $item = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}wch_wishlist WHERE id = %d",
                    $wishlist_id
                ));

                if ($item) {
                    // Add to cart
                    $cart_manager = WCH_Cart_Manager::getInstance();
                    $cart_manager->add_item($conversation->cart_id, $item->product_id, 1);

                    // Remove from wishlist
                    $wpdb->delete(
                        $wpdb->prefix . 'wch_wishlist',
                        ['id' => $wishlist_id],
                        ['%d']
                    );

                    return [
                        'type' => 'text',
                        'content' => '✅ Moved to cart!',
                        'success' => true
                    ];
                }
                break;
        }

        return $result;
    }

    /**
     * Add wishlist intents
     */
    public function add_wishlist_intents($intents) {
        $intents[] = [
            'name' => 'save_for_later',
            'patterns' => [
                'save for later',
                'add to wishlist',
                'wishlist',
                'save this',
                'bookmark'
            ],
            'confidence' => 0.85
        ];

        $intents[] = [
            'name' => 'view_wishlist',
            'patterns' => [
                'show wishlist',
                'my wishlist',
                'saved items',
                'show saved'
            ],
            'confidence' => 0.9
        ];

        return $intents;
    }
}

new WCH_Wishlist_Extension();

/**
 * Create wishlist table on plugin activation
 */
register_activation_hook(__FILE__, function() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wch_wishlist (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_phone varchar(20) NOT NULL,
        product_id bigint(20) UNSIGNED NOT NULL,
        added_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY customer_phone (customer_phone),
        KEY product_id (product_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
});
```

---

## Building Add-on Plugins

Create standalone plugins that extend WhatsApp Commerce Hub.

### Add-on Plugin Structure

```
wch-loyalty-addon/
├── wch-loyalty-addon.php
├── includes/
│   ├── class-loyalty-manager.php
│   ├── class-loyalty-hooks.php
│   └── class-loyalty-admin.php
├── assets/
│   ├── css/
│   └── js/
└── readme.txt
```

### Main Plugin File

```php
<?php
/**
 * Plugin Name: WCH Loyalty Add-on
 * Plugin URI: https://example.com/wch-loyalty
 * Description: Loyalty points program for WhatsApp Commerce Hub
 * Version: 1.0.0
 * Author: Your Name
 * Requires Plugins: whatsapp-commerce-hub
 * Text Domain: wch-loyalty
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check if WCH is active
if (!class_exists('WCH_Plugin')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>';
        echo __('WCH Loyalty Add-on requires WhatsApp Commerce Hub to be installed and activated.', 'wch-loyalty');
        echo '</p></div>';
    });
    return;
}

// Define constants
define('WCH_LOYALTY_VERSION', '1.0.0');
define('WCH_LOYALTY_PATH', plugin_dir_path(__FILE__));
define('WCH_LOYALTY_URL', plugin_dir_url(__FILE__));

// Autoloader
spl_autoload_register(function($class) {
    if (strpos($class, 'WCH_Loyalty_') === 0) {
        $file = WCH_LOYALTY_PATH . 'includes/class-' . str_replace('_', '-', strtolower($class)) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// Initialize
add_action('plugins_loaded', function() {
    WCH_Loyalty_Manager::getInstance();
    WCH_Loyalty_Hooks::getInstance();

    if (is_admin()) {
        WCH_Loyalty_Admin::getInstance();
    }
});
```

### Loyalty Manager Class

```php
<?php
/**
 * Loyalty Manager
 */
class WCH_Loyalty_Manager {
    private static $instance = null;

    public static function getInstance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init();
    }

    private function init() {
        // Register custom intent for points inquiry
        add_filter('wch_custom_intents', [$this, 'register_intents']);

        // Register AI function for points
        add_filter('wch_ai_functions', [$this, 'register_ai_functions']);
        add_filter('wch_ai_function_result', [$this, 'execute_ai_function'], 10, 4);
    }

    /**
     * Award points for order
     */
    public function award_points($customer_id, $order_total) {
        $points_rate = get_option('wch_loyalty_points_rate', 1); // 1 point per dollar
        $points = floor($order_total * $points_rate);

        $current_points = $this->get_points($customer_id);
        $new_points = $current_points + $points;

        update_user_meta($customer_id, 'wch_loyalty_points', $new_points);

        // Log transaction
        $this->log_transaction($customer_id, $points, 'earned', 'Order purchase');

        return $points;
    }

    /**
     * Redeem points
     */
    public function redeem_points($customer_id, $points) {
        $current_points = $this->get_points($customer_id);

        if ($points > $current_points) {
            return new WP_Error('insufficient_points', 'Not enough points');
        }

        $new_points = $current_points - $points;
        update_user_meta($customer_id, 'wch_loyalty_points', $new_points);

        $this->log_transaction($customer_id, $points, 'redeemed', 'Points redemption');

        return $new_points;
    }

    /**
     * Get customer points
     */
    public function get_points($customer_id) {
        return (int) get_user_meta($customer_id, 'wch_loyalty_points', true);
    }

    /**
     * Log points transaction
     */
    private function log_transaction($customer_id, $points, $type, $description) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'wch_loyalty_transactions',
            [
                'customer_id' => $customer_id,
                'points' => $points,
                'type' => $type,
                'description' => $description,
                'created_at' => current_time('mysql')
            ],
            ['%d', '%d', '%s', '%s', '%s']
        );
    }

    /**
     * Register custom intents
     */
    public function register_intents($intents) {
        $intents[] = [
            'name' => 'check_loyalty_points',
            'patterns' => [
                'how many points',
                'my points',
                'loyalty points',
                'rewards balance',
                'check points'
            ],
            'confidence' => 0.9
        ];

        return $intents;
    }

    /**
     * Register AI functions
     */
    public function register_ai_functions($functions) {
        $functions[] = [
            'name' => 'get_loyalty_points',
            'description' => 'Get customer loyalty points balance',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'customer_phone' => [
                        'type' => 'string',
                        'description' => 'Customer phone number'
                    ]
                ],
                'required' => ['customer_phone']
            ]
        ];

        return $functions;
    }

    /**
     * Execute AI function
     */
    public function execute_ai_function($result, $function_name, $arguments, $context) {
        if ($function_name === 'get_loyalty_points') {
            $phone = $arguments['customer_phone'];
            $customer_service = WCH_Customer_Service::getInstance();
            $customer = $customer_service->get_by_phone($phone);

            if ($customer && $customer->user_id) {
                $points = $this->get_points($customer->user_id);
                $value = $points * 0.01; // 1 point = $0.01

                return [
                    'points' => $points,
                    'value' => number_format($value, 2),
                    'currency' => get_woocommerce_currency()
                ];
            }

            return [
                'points' => 0,
                'message' => 'No loyalty account found'
            ];
        }

        return $result;
    }
}
```

---

## Customizing Templates

Override default message templates and conversation flows.

### Custom Message Templates

```php
/**
 * Customize welcome message template
 */
add_filter('wch_message_content', function($content, $message_type, $conversation) {
    if ($message_type === 'welcome') {
        $customer_name = $conversation->customer_name ?? 'there';
        $hour = (int) date('H');

        if ($hour < 12) {
            $greeting = '🌅 Good morning';
        } elseif ($hour < 18) {
            $greeting = '☀️ Good afternoon';
        } else {
            $greeting = '🌙 Good evening';
        }

        $content = "{$greeting}, {$customer_name}!\n\n";
        $content .= "Welcome to " . get_bloginfo('name') . "! 🛍️\n\n";
        $content .= "I'm your personal shopping assistant. How can I help you today?";
    }

    return $content;
}, 10, 3);
```

### Custom Product Display

```php
/**
 * Customize product message format
 */
add_filter('wch_product_message_format', function($format, $product) {
    $format = '';
    $format .= "✨ *" . $product->get_name() . "*\n\n";

    // Add brand if available
    $brand = $product->get_attribute('brand');
    if ($brand) {
        $format .= "🏷️ Brand: {$brand}\n";
    }

    // Price with discount badge
    if ($product->is_on_sale()) {
        $regular_price = $product->get_regular_price();
        $sale_price = $product->get_sale_price();
        $discount = round((($regular_price - $sale_price) / $regular_price) * 100);
        $format .= "💰 ~~" . wc_price($regular_price) . "~~ " . wc_price($sale_price);
        $format .= " 🔥 *{$discount}% OFF*\n";
    } else {
        $format .= "💰 " . wc_price($product->get_price()) . "\n";
    }

    // Stock status with emoji
    if ($product->is_in_stock()) {
        $stock = $product->get_stock_quantity();
        if ($stock && $stock < 10) {
            $format .= "⚠️ Only {$stock} left in stock!\n";
        } else {
            $format .= "✅ In Stock\n";
        }
    } else {
        $format .= "❌ Out of Stock\n";
    }

    // Rating
    $rating = $product->get_average_rating();
    if ($rating > 0) {
        $stars = str_repeat('⭐', round($rating));
        $format .= "{$stars} ({$rating}/5)\n";
    }

    $format .= "\n" . wp_strip_all_tags($product->get_short_description());

    return $format;
}, 10, 2);
```

---

## Custom REST API Endpoints

Add custom REST API endpoints for integrations.

```php
/**
 * Custom REST API Controller
 */
class My_Custom_REST_Controller extends WCH_REST_Controller {

    protected $namespace = 'wch/v1';
    protected $rest_base = 'custom';

    public function register_routes() {
        // GET /wch/v1/custom/stats
        register_rest_route($this->namespace, '/' . $this->rest_base . '/stats', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_custom_stats'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // POST /wch/v1/custom/trigger-action
        register_rest_route($this->namespace, '/' . $this->rest_base . '/trigger-action', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'trigger_custom_action'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'action_type' => [
                    'required' => true,
                    'type' => 'string',
                    'enum' => ['welcome_campaign', 'product_recommendation', 'survey']
                ],
                'target_phone' => [
                    'required' => true,
                    'type' => 'string',
                    'validate_callback' => [$this, 'validate_phone']
                ]
            ]
        ]);
    }

    public function get_custom_stats($request) {
        // Your custom logic
        return rest_ensure_response([
            'total_interactions' => 1234,
            'conversion_rate' => 15.5,
            'top_products' => []
        ]);
    }

    public function trigger_custom_action($request) {
        $action_type = $request->get_param('action_type');
        $phone = $request->get_param('target_phone');

        // Execute action
        $result = $this->execute_action($action_type, $phone);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Action triggered successfully'
        ]);
    }

    private function execute_action($type, $phone) {
        // Implementation
        return true;
    }
}

/**
 * Register custom controller
 */
add_filter('wch_rest_api_controllers', function($controllers) {
    $controllers[] = 'My_Custom_REST_Controller';
    return $controllers;
});
```

---

## Custom Admin Pages

Add custom admin pages for your extensions.

```php
/**
 * Add custom admin page
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'whatsapp-commerce-hub',
        'Loyalty Program',
        'Loyalty',
        'manage_woocommerce',
        'wch-loyalty',
        'render_loyalty_admin_page'
    );
});

function render_loyalty_admin_page() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

        <div class="wch-admin-content">
            <h2>Loyalty Program Settings</h2>
            <form method="post" action="options.php">
                <?php
                settings_fields('wch_loyalty_options');
                do_settings_sections('wch_loyalty_options');
                submit_button();
                ?>
            </form>
        </div>
    </div>
    <?php
}

/**
 * Register settings
 */
add_action('admin_init', function() {
    register_setting('wch_loyalty_options', 'wch_loyalty_points_rate');
    register_setting('wch_loyalty_options', 'wch_loyalty_redemption_rate');

    add_settings_section(
        'wch_loyalty_general',
        'General Settings',
        null,
        'wch_loyalty_options'
    );

    add_settings_field(
        'points_rate',
        'Points per Dollar',
        function() {
            $value = get_option('wch_loyalty_points_rate', 1);
            echo '<input type="number" name="wch_loyalty_points_rate" value="' . esc_attr($value) . '" min="0" step="0.1" />';
        },
        'wch_loyalty_options',
        'wch_loyalty_general'
    );
});
```

---

## Best Practices

### 1. Namespace Your Code

```php
// Good
class MyCompany_WCH_Extension {}
function mycompany_wch_custom_function() {}

// Bad
class Custom_Extension {} // Too generic
function custom_function() {} // May conflict
```

### 2. Check Dependencies

```php
// Always check if WCH is active
if (!class_exists('WCH_Plugin')) {
    return;
}

// Check for required WCH version
if (version_compare(WCH_VERSION, '1.0.0', '<')) {
    // Show notice
    return;
}
```

### 3. Use Proper Error Handling

```php
try {
    // Your code
} catch (Exception $e) {
    WCH_Logger::log('error', $e->getMessage(), 'my-extension');
    return new WP_Error('extension_error', $e->getMessage());
}
```

### 4. Leverage WCH APIs

```php
// Use WCH's built-in services
$customer_service = WCH_Customer_Service::getInstance();
$cart_manager = WCH_Cart_Manager::getInstance();
$message_builder = WCH_Message_Builder::getInstance();
```

### 5. Add Proper Documentation

```php
/**
 * Custom function description.
 *
 * @param string $phone Customer phone number.
 * @param array  $data  Additional data.
 * @return array|WP_Error Result or error.
 */
function my_custom_function($phone, $data) {
    // Implementation
}
```

---

## Testing Your Extensions

### Unit Testing

```php
class Test_My_Extension extends WP_UnitTestCase {
    public function test_custom_intent() {
        $intents = apply_filters('wch_custom_intents', []);
        $this->assertContains('my_custom_intent', wp_list_pluck($intents, 'name'));
    }

    public function test_payment_gateway() {
        $payment_manager = WCH_Payment_Manager::getInstance();
        $this->assertTrue($payment_manager->has_gateway('my_gateway'));
    }
}
```

### Integration Testing

Test your extension with actual WhatsApp conversations in a development environment.

---

## Example: Complete Extension

See the `/examples` directory for complete working examples of extensions:

- `examples/loyalty-addon/` - Full loyalty program implementation
- `examples/custom-payment/` - Custom payment gateway
- `examples/multi-language/` - Multi-language support extension

---

**Next Steps**: [Explore the Hooks Reference](hooks-reference.md) | [Check Troubleshooting Guide](troubleshooting.md)
