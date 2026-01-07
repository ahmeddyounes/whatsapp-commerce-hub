<?php
/**
 * Admin Inbox Page
 *
 * @package WhatsApp_Commerce_Hub
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCH_Admin_Inbox {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_item'), 49);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
    }

    public static function add_menu_item() {
        $hook = add_submenu_page(
            'woocommerce',
            __('Inbox - WhatsApp Commerce Hub', 'whatsapp-commerce-hub'),
            __('WhatsApp Inbox', 'whatsapp-commerce-hub'),
            'manage_woocommerce',
            'wch-inbox',
            array(__CLASS__, 'render_page')
        );

        add_action('load-' . $hook, array(__CLASS__, 'add_help_tab'));
    }

    public static function add_help_tab() {
        $screen = get_current_screen();

        $screen->add_help_tab(array(
            'id'      => 'wch_inbox_overview',
            'title'   => __('Overview', 'whatsapp-commerce-hub'),
            'content' => '<p>' . __('The Inbox allows you to view and manage customer conversations from WhatsApp.', 'whatsapp-commerce-hub') . '</p>',
        ));

        $screen->add_help_tab(array(
            'id'      => 'wch_inbox_conversations',
            'title'   => __('Conversations', 'whatsapp-commerce-hub'),
            'content' => '<p>' . __('Filter and search conversations by status, assigned agent, or customer information. Click on a conversation to view the full message history.', 'whatsapp-commerce-hub') . '</p>',
        ));

        $screen->add_help_tab(array(
            'id'      => 'wch_inbox_actions',
            'title'   => __('Actions', 'whatsapp-commerce-hub'),
            'content' => '<p>' . __('You can assign conversations to agents, mark them as closed, send replies, and use AI to suggest contextual responses.', 'whatsapp-commerce-hub') . '</p>',
        ));
    }

    public static function enqueue_scripts($hook) {
        if ('woocommerce_page_wch-inbox' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'wch-admin-inbox',
            plugins_url('assets/css/admin-inbox.css', dirname(__FILE__)),
            array(),
            WCH_VERSION
        );

        wp_enqueue_script(
            'wch-admin-inbox',
            plugins_url('assets/js/admin-inbox.js', dirname(__FILE__)),
            array('jquery', 'wp-util'),
            WCH_VERSION,
            true
        );

        $agents = get_users(array(
            'role__in' => array('administrator', 'shop_manager'),
            'fields' => array('ID', 'display_name'),
        ));

        wp_localize_script('wch-admin-inbox', 'wchInbox', array(
            'ajax_url'   => admin_url('admin-ajax.php'),
            'rest_url'   => rest_url('wch/v1/conversations'),
            'nonce'      => wp_create_nonce('wp_rest'),
            'agents'     => $agents,
            'current_user_id' => get_current_user_id(),
            'strings'    => array(
                'loading'              => __('Loading...', 'whatsapp-commerce-hub'),
                'error'                => __('An error occurred', 'whatsapp-commerce-hub'),
                'no_conversations'     => __('No conversations found', 'whatsapp-commerce-hub'),
                'search_placeholder'   => __('Search by phone or name...', 'whatsapp-commerce-hub'),
                'send_message'         => __('Send', 'whatsapp-commerce-hub'),
                'type_message'         => __('Type a message...', 'whatsapp-commerce-hub'),
                'assign_success'       => __('Conversation assigned successfully', 'whatsapp-commerce-hub'),
                'close_success'        => __('Conversation closed successfully', 'whatsapp-commerce-hub'),
                'send_success'         => __('Message sent successfully', 'whatsapp-commerce-hub'),
                'bulk_assign_success'  => __('Conversations assigned successfully', 'whatsapp-commerce-hub'),
                'bulk_close_success'   => __('Conversations closed successfully', 'whatsapp-commerce-hub'),
                'confirm_bulk_close'   => __('Are you sure you want to close the selected conversations?', 'whatsapp-commerce-hub'),
                'select_conversations' => __('Please select at least one conversation', 'whatsapp-commerce-hub'),
                'select_agent'         => __('Please select an agent', 'whatsapp-commerce-hub'),
                'ai_generating'        => __('Generating AI suggestion...', 'whatsapp-commerce-hub'),
                'ai_error'             => __('Failed to generate AI suggestion', 'whatsapp-commerce-hub'),
            ),
        ));
    }

    public static function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'whatsapp-commerce-hub'));
        }

        $agents = get_users(array(
            'role__in' => array('administrator', 'shop_manager'),
            'fields' => array('ID', 'display_name'),
        ));
        ?>
        <div class="wrap wch-inbox-wrap">
            <h1><?php echo esc_html__('WhatsApp Inbox', 'whatsapp-commerce-hub'); ?></h1>

            <div class="wch-inbox-container">
                <!-- Left Sidebar: Conversation List -->
                <div class="wch-inbox-sidebar">
                    <div class="wch-inbox-filters">
                        <input
                            type="text"
                            id="wch-conversation-search"
                            class="wch-search-input"
                            placeholder="<?php echo esc_attr__('Search by phone or name...', 'whatsapp-commerce-hub'); ?>"
                        />

                        <div class="wch-filter-group">
                            <label for="wch-filter-status"><?php esc_html_e('Status:', 'whatsapp-commerce-hub'); ?></label>
                            <select id="wch-filter-status" class="wch-filter-select">
                                <option value=""><?php esc_html_e('All', 'whatsapp-commerce-hub'); ?></option>
                                <option value="active"><?php esc_html_e('Active', 'whatsapp-commerce-hub'); ?></option>
                                <option value="pending"><?php esc_html_e('Pending', 'whatsapp-commerce-hub'); ?></option>
                                <option value="closed"><?php esc_html_e('Closed', 'whatsapp-commerce-hub'); ?></option>
                            </select>
                        </div>

                        <div class="wch-filter-group">
                            <label for="wch-filter-agent"><?php esc_html_e('Agent:', 'whatsapp-commerce-hub'); ?></label>
                            <select id="wch-filter-agent" class="wch-filter-select">
                                <option value=""><?php esc_html_e('All Agents', 'whatsapp-commerce-hub'); ?></option>
                                <?php foreach ($agents as $agent) : ?>
                                    <option value="<?php echo esc_attr($agent->ID); ?>">
                                        <?php echo esc_html($agent->display_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="wch-bulk-actions">
                        <input type="checkbox" id="wch-select-all" />
                        <label for="wch-select-all"><?php esc_html_e('Select All', 'whatsapp-commerce-hub'); ?></label>
                        <div class="wch-bulk-action-buttons">
                            <button id="wch-bulk-assign" class="button" disabled>
                                <?php esc_html_e('Assign Selected', 'whatsapp-commerce-hub'); ?>
                            </button>
                            <button id="wch-bulk-close" class="button" disabled>
                                <?php esc_html_e('Close Selected', 'whatsapp-commerce-hub'); ?>
                            </button>
                            <button id="wch-bulk-export" class="button" disabled>
                                <?php esc_html_e('Export Selected', 'whatsapp-commerce-hub'); ?>
                            </button>
                        </div>
                    </div>

                    <div id="wch-conversation-list" class="wch-conversation-list">
                        <div class="wch-loading"><?php esc_html_e('Loading conversations...', 'whatsapp-commerce-hub'); ?></div>
                    </div>
                </div>

                <!-- Main Area: Conversation View -->
                <div class="wch-inbox-main">
                    <div id="wch-no-conversation" class="wch-no-conversation">
                        <p><?php esc_html_e('Select a conversation to view messages', 'whatsapp-commerce-hub'); ?></p>
                    </div>

                    <div id="wch-conversation-view" class="wch-conversation-view" style="display: none;">
                        <div class="wch-conversation-header">
                            <div class="wch-conversation-title">
                                <h2 id="wch-customer-name"></h2>
                                <span id="wch-customer-phone" class="wch-customer-phone"></span>
                            </div>
                            <div class="wch-conversation-actions">
                                <select id="wch-assign-agent" class="wch-action-select">
                                    <option value=""><?php esc_html_e('Assign to...', 'whatsapp-commerce-hub'); ?></option>
                                    <?php foreach ($agents as $agent) : ?>
                                        <option value="<?php echo esc_attr($agent->ID); ?>">
                                            <?php echo esc_html($agent->display_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button id="wch-mark-closed" class="button">
                                    <?php esc_html_e('Mark as Closed', 'whatsapp-commerce-hub'); ?>
                                </button>
                                <button id="wch-view-customer" class="button">
                                    <?php esc_html_e('View Customer', 'whatsapp-commerce-hub'); ?>
                                </button>
                            </div>
                        </div>

                        <div id="wch-messages-container" class="wch-messages-container">
                            <div class="wch-loading"><?php esc_html_e('Loading messages...', 'whatsapp-commerce-hub'); ?></div>
                        </div>

                        <div class="wch-reply-composer">
                            <div class="wch-composer-toolbar">
                                <button id="wch-ai-suggest" class="button button-secondary">
                                    <?php esc_html_e('AI Suggest', 'whatsapp-commerce-hub'); ?>
                                </button>
                                <select id="wch-template-selector" class="wch-template-select">
                                    <option value=""><?php esc_html_e('Quick Templates', 'whatsapp-commerce-hub'); ?></option>
                                </select>
                            </div>
                            <div class="wch-composer-input">
                                <textarea
                                    id="wch-message-input"
                                    placeholder="<?php echo esc_attr__('Type a message...', 'whatsapp-commerce-hub'); ?>"
                                    rows="3"
                                ></textarea>
                                <button id="wch-send-message" class="button button-primary">
                                    <?php esc_html_e('Send', 'whatsapp-commerce-hub'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Sidebar: Customer Details -->
                <div class="wch-inbox-customer-sidebar">
                    <div id="wch-no-customer" class="wch-no-customer">
                        <p><?php esc_html_e('Customer details will appear here', 'whatsapp-commerce-hub'); ?></p>
                    </div>

                    <div id="wch-customer-details" class="wch-customer-details" style="display: none;">
                        <div class="wch-customer-profile">
                            <div class="wch-customer-avatar">
                                <span class="dashicons dashicons-admin-users"></span>
                            </div>
                            <h3 id="wch-sidebar-customer-name"></h3>
                            <div class="wch-customer-phone-wrapper">
                                <span id="wch-sidebar-customer-phone"></span>
                                <button id="wch-copy-phone" class="button-link" title="<?php echo esc_attr__('Copy phone number', 'whatsapp-commerce-hub'); ?>">
                                    <span class="dashicons dashicons-clipboard"></span>
                                </button>
                            </div>
                        </div>

                        <div class="wch-customer-info">
                            <h4><?php esc_html_e('Customer Information', 'whatsapp-commerce-hub'); ?></h4>
                            <div id="wch-wc-customer-link" class="wch-info-item" style="display: none;">
                                <label><?php esc_html_e('WooCommerce Customer:', 'whatsapp-commerce-hub'); ?></label>
                                <a id="wch-wc-customer-url" href="#" target="_blank"></a>
                            </div>
                            <div class="wch-info-item">
                                <label><?php esc_html_e('Status:', 'whatsapp-commerce-hub'); ?></label>
                                <span id="wch-sidebar-status" class="wch-status-badge"></span>
                            </div>
                            <div class="wch-info-item">
                                <label><?php esc_html_e('Assigned Agent:', 'whatsapp-commerce-hub'); ?></label>
                                <span id="wch-sidebar-agent"></span>
                            </div>
                        </div>

                        <div class="wch-customer-orders">
                            <h4><?php esc_html_e('Order History', 'whatsapp-commerce-hub'); ?></h4>
                            <div id="wch-order-list">
                                <p class="wch-no-orders"><?php esc_html_e('No orders found', 'whatsapp-commerce-hub'); ?></p>
                            </div>
                            <div class="wch-info-item">
                                <label><?php esc_html_e('Total Spent:', 'whatsapp-commerce-hub'); ?></label>
                                <span id="wch-total-spent">$0.00</span>
                            </div>
                        </div>

                        <div class="wch-customer-notes">
                            <h4><?php esc_html_e('Agent Notes', 'whatsapp-commerce-hub'); ?></h4>
                            <textarea id="wch-agent-notes" rows="4" placeholder="<?php echo esc_attr__('Add notes about this customer...', 'whatsapp-commerce-hub'); ?>"></textarea>
                            <button id="wch-save-notes" class="button button-secondary">
                                <?php esc_html_e('Save Notes', 'whatsapp-commerce-hub'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
