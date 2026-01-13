/**
 * WhatsApp Commerce Hub - Admin Inbox
 */

(function($) {
    'use strict';

    const WCHInbox = {
        currentConversation: null,
        conversations: [],
        selectedConversations: new Set(),
        pollingInterval: null,
        filters: {
            search: '',
            status: '',
            agent_id: '',
        },

        init: function() {
            this.bindEvents();
            this.loadConversations();
            this.startPolling();
        },

        bindEvents: function() {
            // Search and filters
            $('#wch-conversation-search').on('input', $.proxy(this.handleSearch, this));
            $('#wch-filter-status').on('change', $.proxy(this.handleFilterChange, this));
            $('#wch-filter-agent').on('change', $.proxy(this.handleFilterChange, this));

            // Bulk actions
            $('#wch-select-all').on('change', $.proxy(this.handleSelectAll, this));
            $('#wch-bulk-assign').on('click', $.proxy(this.handleBulkAssign, this));
            $('#wch-bulk-close').on('click', $.proxy(this.handleBulkClose, this));
            $('#wch-bulk-export').on('click', $.proxy(this.handleBulkExport, this));

            // Conversation actions
            $('#wch-assign-agent').on('change', $.proxy(this.handleAssignAgent, this));
            $('#wch-mark-closed').on('click', $.proxy(this.handleMarkClosed, this));
            $('#wch-view-customer').on('click', $.proxy(this.handleViewCustomer, this));

            // Message actions
            $('#wch-send-message').on('click', $.proxy(this.handleSendMessage, this));
            $('#wch-message-input').on('keydown', $.proxy(this.handleMessageKeydown, this));
            $('#wch-ai-suggest').on('click', $.proxy(this.handleAISuggest, this));
            $('#wch-copy-phone').on('click', $.proxy(this.handleCopyPhone, this));

            // Cleanup on page unload
            $(window).on('beforeunload', $.proxy(this.cleanup, this));
        },

        handleSearch: function(e) {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout($.proxy(function() {
                this.filters.search = $(e.target).val();
                this.loadConversations();
            }, this), 300);
        },

        handleFilterChange: function() {
            this.filters.status = $('#wch-filter-status').val();
            this.filters.agent_id = $('#wch-filter-agent').val();
            this.loadConversations();
        },

        loadConversations: function() {
            const params = new URLSearchParams({
                per_page: 50,
                ...this.filters
            });

            $.ajax({
                url: wchInbox.rest_url + '?' + params.toString(),
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wchInbox.nonce);
                },
                success: $.proxy(this.renderConversations, this),
                error: function(xhr) {
                    console.error('Failed to load conversations:', xhr);
                    $('#wch-conversation-list').html('<div class="wch-loading">' + wchInbox.strings.error + '</div>');
                }
            });
        },

        renderConversations: function(conversations) {
            this.conversations = conversations;

            if (conversations.length === 0) {
                $('#wch-conversation-list').html('<div class="wch-loading">' + wchInbox.strings.no_conversations + '</div>');
                return;
            }

            let html = '';
            conversations.forEach(function(conv) {
                const isSelected = this.selectedConversations.has(conv.id);
                const isActive = this.currentConversation && this.currentConversation.id === conv.id;

                html += '<div class="wch-conversation-item' + (isActive ? ' active' : '') + ' has-checkbox" data-id="' + conv.id + '">';
                html += '<input type="checkbox" class="wch-conversation-item-checkbox" data-id="' + conv.id + '"' + (isSelected ? ' checked' : '') + '>';
                html += '<div class="wch-conversation-header-row">';
                html += '<div class="wch-conversation-name">' + this.escapeHtml(conv.customer_name || conv.customer_phone) + '</div>';
                html += '<div class="wch-conversation-meta">';
                if (conv.unread_count > 0) {
                    html += '<span class="wch-unread-badge">' + conv.unread_count + '</span>';
                }
                html += '<span class="wch-conversation-time">' + this.formatTime(conv.last_message_at) + '</span>';
                html += '</div>';
                html += '</div>';
                html += '<div class="wch-conversation-preview">' + this.escapeHtml(conv.last_message_preview || '') + '</div>';
                html += '<div class="wch-conversation-footer">';
                html += '<span class="wch-status-badge status-' + conv.status + '">' + conv.status + '</span>';
                if (conv.agent_name) {
                    html += '<div class="wch-agent-avatar" title="' + this.escapeHtml(conv.agent_name) + '">' + conv.agent_name.charAt(0).toUpperCase() + '</div>';
                }
                html += '</div>';
                html += '</div>';
            }, this);

            $('#wch-conversation-list').html(html);

            // Bind click events
            $('.wch-conversation-item').on('click', $.proxy(function(e) {
                if (!$(e.target).hasClass('wch-conversation-item-checkbox')) {
                    const id = $(e.currentTarget).data('id');
                    this.selectConversation(id);
                }
            }, this));

            $('.wch-conversation-item-checkbox').on('change', $.proxy(function(e) {
                e.stopPropagation();
                const id = $(e.target).data('id');
                if ($(e.target).is(':checked')) {
                    this.selectedConversations.add(id);
                } else {
                    this.selectedConversations.delete(id);
                }
                this.updateBulkActions();
            }, this));
        },

        selectConversation: function(id) {
            const conversation = this.conversations.find(c => c.id === id);
            if (!conversation) return;

            this.currentConversation = conversation;
            $('.wch-conversation-item').removeClass('active');
            $('.wch-conversation-item[data-id="' + id + '"]').addClass('active');

            this.loadConversationDetails(id);
            this.loadMessages(id);
            this.loadCustomerDetails(conversation);
        },

        loadConversationDetails: function(id) {
            $.ajax({
                url: wchInbox.rest_url + '/' + id,
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wchInbox.nonce);
                },
                success: $.proxy(function(conversation) {
                    $('#wch-customer-name').text(conversation.customer_name || conversation.customer_phone);
                    $('#wch-customer-phone').text(conversation.customer_phone);
                    $('#wch-assign-agent').val(conversation.assigned_agent_id || '');

                    $('#wch-no-conversation').hide();
                    $('#wch-conversation-view').show();
                }, this),
                error: function(xhr) {
                    console.error('Failed to load conversation details:', xhr);
                }
            });
        },

        loadMessages: function(id) {
            $('#wch-messages-container').html('<div class="wch-loading">' + wchInbox.strings.loading + '</div>');

            $.ajax({
                url: wchInbox.rest_url + '/' + id + '/messages',
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wchInbox.nonce);
                },
                success: $.proxy(this.renderMessages, this),
                error: function(xhr) {
                    console.error('Failed to load messages:', xhr);
                    $('#wch-messages-container').html('<div class="wch-loading">' + wchInbox.strings.error + '</div>');
                }
            });
        },

        renderMessages: function(messages) {
            if (messages.length === 0) {
                $('#wch-messages-container').html('<div class="wch-loading">No messages yet</div>');
                return;
            }

            let html = '';
            messages.forEach(function(msg) {
                const isSystem = msg.type === 'system';
                const direction = isSystem ? 'system' : msg.direction;

                html += '<div class="wch-message ' + direction + '">';
                html += '<div class="wch-message-bubble">';
                html += '<div class="wch-message-content">' + this.formatMessageContent(msg) + '</div>';
                if (!isSystem) {
                    html += '<div class="wch-message-meta">';
                    html += '<span class="wch-message-time">' + this.formatTime(msg.created_at) + '</span>';
                    if (msg.direction === 'outbound') {
                        html += '<span class="wch-message-status">' + this.getStatusIcon(msg.status) + '</span>';
                    }
                    html += '</div>';
                }
                html += '</div>';
                html += '</div>';
            }, this);

            $('#wch-messages-container').html(html);
            this.scrollToBottom();
        },

        formatMessageContent: function(msg) {
            if (!msg.content) return '';

            switch (msg.type) {
                case 'text':
                    return this.escapeHtml(msg.content.text || '').replace(/\n/g, '<br>');
                case 'image':
                    return '<div class="wch-message-media"><img src="' + this.escapeHtml(msg.content.image?.url || '') + '" alt="Image"></div>';
                case 'document':
                    return '<div class="wch-message-media"><a href="' + this.escapeHtml(msg.content.document?.url || '') + '" target="_blank">' + this.escapeHtml(msg.content.document?.filename || 'Document') + '</a></div>';
                case 'interactive':
                    return this.escapeHtml(msg.content.interactive?.body?.text || '[Interactive message]');
                default:
                    return '[Unknown message type]';
            }
        },

        getStatusIcon: function(status) {
            switch (status) {
                case 'sent':
                    return '<span class="dashicons dashicons-yes"></span>';
                case 'delivered':
                    return '<span class="dashicons dashicons-yes"></span><span class="dashicons dashicons-yes"></span>';
                case 'read':
                    return '<span class="dashicons dashicons-yes wch-text-success"></span><span class="dashicons dashicons-yes wch-text-success"></span>';
                case 'failed':
                    return '<span class="dashicons dashicons-warning wch-text-error"></span>';
                default:
                    return '';
            }
        },

        loadCustomerDetails: function(conversation) {
            $('#wch-sidebar-customer-name').text(conversation.customer_name || conversation.customer_phone);
            $('#wch-sidebar-customer-phone').text(conversation.customer_phone);
            $('#wch-sidebar-status').text(conversation.status).attr('class', 'wch-status-badge status-' + conversation.status);
            $('#wch-sidebar-agent').text(conversation.agent_name || 'Unassigned');

            if (conversation.wc_customer_id) {
                $('#wch-wc-customer-link').show();
                $('#wch-wc-customer-url')
                    .attr('href', '/wp-admin/user-edit.php?user_id=' + conversation.wc_customer_id)
                    .text('View Profile');
                this.loadCustomerOrders(conversation.wc_customer_id);
            } else {
                $('#wch-wc-customer-link').hide();
                $('#wch-order-list').html('<p class="wch-no-orders">' + wchInbox.strings.no_orders || 'No orders found' + '</p>');
                $('#wch-total-spent').text('$0.00');
            }

            $('#wch-no-customer').hide();
            $('#wch-customer-details').show();
        },

        loadCustomerOrders: function(customerId) {
            // In a real implementation, this would fetch orders via WooCommerce REST API
            // For now, we'll show a placeholder
            $('#wch-order-list').html('<p class="wch-no-orders">Loading orders...</p>');
            $('#wch-total-spent').text('$0.00');
        },

        handleSendMessage: function() {
            if (!this.currentConversation) return;

            const message = $('#wch-message-input').val().trim();
            if (!message) return;

            const $button = $('#wch-send-message');
            $button.prop('disabled', true).text('Sending...');

            $.ajax({
                url: wchInbox.rest_url + '/' + this.currentConversation.id + '/messages',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wchInbox.nonce);
                },
                data: JSON.stringify({ message: message }),
                contentType: 'application/json',
                success: $.proxy(function(response) {
                    $('#wch-message-input').val('');
                    this.loadMessages(this.currentConversation.id);
                    this.showNotice(wchInbox.strings.send_success, 'success');
                }, this),
                error: function(xhr) {
                    console.error('Failed to send message:', xhr);
                    this.showNotice(wchInbox.strings.error, 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).text(wchInbox.strings.send_message);
                }
            });
        },

        handleMessageKeydown: function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.handleSendMessage();
            }
        },

        handleAssignAgent: function(e) {
            if (!this.currentConversation) return;

            const agentId = $(e.target).val();

            $.ajax({
                url: wchInbox.rest_url + '/' + this.currentConversation.id,
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wchInbox.nonce);
                },
                data: JSON.stringify({ assigned_agent_id: agentId }),
                contentType: 'application/json',
                success: $.proxy(function(response) {
                    this.showNotice(wchInbox.strings.assign_success, 'success');
                    this.loadConversations();
                }, this),
                error: function(xhr) {
                    console.error('Failed to assign agent:', xhr);
                    this.showNotice(wchInbox.strings.error, 'error');
                }
            });
        },

        handleMarkClosed: function() {
            if (!this.currentConversation) return;

            $.ajax({
                url: wchInbox.rest_url + '/' + this.currentConversation.id,
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wchInbox.nonce);
                },
                data: JSON.stringify({ status: 'closed' }),
                contentType: 'application/json',
                success: $.proxy(function(response) {
                    this.showNotice(wchInbox.strings.close_success, 'success');
                    this.loadConversations();
                    this.loadConversationDetails(this.currentConversation.id);
                }, this),
                error: function(xhr) {
                    console.error('Failed to close conversation:', xhr);
                    this.showNotice(wchInbox.strings.error, 'error');
                }
            });
        },

        handleViewCustomer: function() {
            if (!this.currentConversation || !this.currentConversation.wc_customer_id) return;
            window.open('/wp-admin/user-edit.php?user_id=' + this.currentConversation.wc_customer_id, '_blank');
        },

        handleAISuggest: function() {
            if (!this.currentConversation) return;

            const $button = $('#wch-ai-suggest');
            $button.prop('disabled', true).text(wchInbox.strings.ai_generating);

            $.ajax({
                url: wchInbox.rest_url + '/suggest-reply',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wchInbox.nonce);
                },
                data: JSON.stringify({ conversation_id: this.currentConversation.id }),
                contentType: 'application/json',
                success: function(response) {
                    $('#wch-message-input').val(response.suggestion);
                },
                error: function(xhr) {
                    console.error('Failed to generate AI suggestion:', xhr);
                    this.showNotice(wchInbox.strings.ai_error, 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).text('AI Suggest');
                }
            });
        },

        handleSelectAll: function(e) {
            const isChecked = $(e.target).is(':checked');
            $('.wch-conversation-item-checkbox').prop('checked', isChecked);

            if (isChecked) {
                this.conversations.forEach(conv => this.selectedConversations.add(conv.id));
            } else {
                this.selectedConversations.clear();
            }

            this.updateBulkActions();
        },

        updateBulkActions: function() {
            const hasSelection = this.selectedConversations.size > 0;
            $('#wch-bulk-assign, #wch-bulk-close, #wch-bulk-export').prop('disabled', !hasSelection);
        },

        handleBulkAssign: function() {
            if (this.selectedConversations.size === 0) {
                alert(wchInbox.strings.select_conversations);
                return;
            }

            const agentId = prompt('Enter agent ID to assign:');
            if (!agentId) return;

            this.bulkUpdate('assign', { agent_id: agentId });
        },

        handleBulkClose: function() {
            if (this.selectedConversations.size === 0) {
                alert(wchInbox.strings.select_conversations);
                return;
            }

            if (!confirm(wchInbox.strings.confirm_bulk_close)) return;

            this.bulkUpdate('close');
        },

        handleBulkExport: function() {
            if (this.selectedConversations.size === 0) {
                alert(wchInbox.strings.select_conversations);
                return;
            }

            this.bulkUpdate('export');
        },

        bulkUpdate: function(action, extraData = {}) {
            const data = {
                action: action,
                ids: Array.from(this.selectedConversations),
                ...extraData
            };

            $.ajax({
                url: wchInbox.rest_url + '/bulk',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wchInbox.nonce);
                },
                data: JSON.stringify(data),
                contentType: 'application/json',
                success: $.proxy(function(response) {
                    if (action === 'export' && response.csv) {
                        this.downloadCSV(response.csv, response.filename);
                    } else {
                        const message = action === 'assign' ? wchInbox.strings.bulk_assign_success : wchInbox.strings.bulk_close_success;
                        this.showNotice(message, 'success');
                        this.selectedConversations.clear();
                        $('#wch-select-all').prop('checked', false);
                        this.loadConversations();
                    }
                }, this),
                error: function(xhr) {
                    console.error('Bulk update failed:', xhr);
                    this.showNotice(wchInbox.strings.error, 'error');
                }
            });
        },

        handleCopyPhone: function() {
            const phone = $('#wch-sidebar-customer-phone').text();
            navigator.clipboard.writeText(phone).then(function() {
                this.showNotice('Phone number copied!', 'success');
            }.bind(this));
        },

        downloadCSV: function(csv, filename) {
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
        },

        startPolling: function() {
            this.pollingInterval = setInterval($.proxy(function() {
                if (this.currentConversation) {
                    this.loadMessages(this.currentConversation.id);
                }
                this.loadConversations();
            }, this), 10000); // Poll every 10 seconds
        },

        cleanup: function() {
            if (this.pollingInterval) {
                clearInterval(this.pollingInterval);
            }
        },

        scrollToBottom: function() {
            const container = $('#wch-messages-container');
            container.scrollTop(container[0].scrollHeight);
        },

        formatTime: function(datetime) {
            const date = new Date(datetime);
            const now = new Date();
            const diff = now - date;

            if (diff < 60000) return 'Just now';
            if (diff < 3600000) return Math.floor(diff / 60000) + 'm ago';
            if (diff < 86400000) return Math.floor(diff / 3600000) + 'h ago';
            if (diff < 604800000) return Math.floor(diff / 86400000) + 'd ago';

            return date.toLocaleDateString();
        },

        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        showNotice: function(message, type) {
            const $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            $('.wch-inbox-wrap h1').after($notice);

            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 3000);
        }
    };

    $(document).ready(function() {
        WCHInbox.init();
    });

})(jQuery);
