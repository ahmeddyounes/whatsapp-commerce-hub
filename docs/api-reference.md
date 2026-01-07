# API Reference

Complete REST API documentation for WhatsApp Commerce Hub.

## Base URL

```
https://yoursite.com/wp-json/wch/v1
```

## Authentication

### WordPress Authentication

For admin endpoints, use standard WordPress authentication:

**Cookie Authentication** (for logged-in users):
- No additional headers needed when making requests from WordPress admin

**Application Password** (recommended for external integrations):
```bash
curl -X GET https://yoursite.com/wp-json/wch/v1/conversations \
  --user "username:application-password"
```

### API Key Authentication

For programmatic access, use API key in header:

```bash
curl -X GET https://yoursite.com/wp-json/wch/v1/conversations \
  -H "X-WCH-API-Key: your-api-key-here"
```

**Generate API Key**:
1. Navigate to: WhatsApp Commerce → Settings → API
2. Click "Generate New API Key"
3. Copy and securely store the key

### Webhook Signature Verification

WhatsApp webhooks use signature validation:

```php
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$payload = file_get_contents('php://input');
$expected = 'sha256=' . hash_hmac('sha256', $payload, WEBHOOK_SECRET);

if (hash_equals($expected, $signature)) {
    // Valid webhook
}
```

## Rate Limits

- **Admin Endpoints**: 1000 requests per hour per user
- **Webhook Endpoint**: 10000 requests per hour
- **Analytics Endpoints**: 100 requests per hour per user

Rate limit headers included in responses:
```
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 995
X-RateLimit-Reset: 1609459200
```

## Error Responses

All errors follow consistent format:

```json
{
  "code": "wch_error_code",
  "message": "Human-readable error message",
  "data": {
    "status": 400,
    "details": {}
  }
}
```

### Common Error Codes

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `wch_invalid_param` | 400 | Invalid parameter value |
| `wch_unauthorized` | 401 | Authentication required |
| `wch_forbidden` | 403 | Insufficient permissions |
| `wch_not_found` | 404 | Resource not found |
| `wch_rate_limit` | 429 | Rate limit exceeded |
| `wch_server_error` | 500 | Internal server error |
| `wch_api_error` | 502 | WhatsApp API error |

---

## Endpoints

### API Information

#### GET /

Get API information and available endpoints.

**Request**:
```bash
curl -X GET https://yoursite.com/wp-json/wch/v1/
```

**Response**: `200 OK`
```json
{
  "name": "WhatsApp Commerce Hub API",
  "version": "v1",
  "namespace": "wch/v1",
  "description": "REST API for WhatsApp Commerce Hub",
  "endpoints": {
    "/webhook": "WhatsApp webhook endpoint",
    "/conversations": "Conversation management",
    "/analytics": "Analytics data"
  },
  "authentication": {
    "admin": "WordPress authentication or X-WCH-API-Key header",
    "webhook": "X-Hub-Signature-256 header"
  }
}
```

---

## Webhook Endpoints

### POST /webhook

Receive WhatsApp webhook events.

**Authentication**: Signature validation

**Headers**:
```
Content-Type: application/json
X-Hub-Signature-256: sha256=<signature>
```

**Request Body**:
```json
{
  "object": "whatsapp_business_account",
  "entry": [
    {
      "id": "BUSINESS_ACCOUNT_ID",
      "changes": [
        {
          "value": {
            "messaging_product": "whatsapp",
            "metadata": {
              "display_phone_number": "15551234567",
              "phone_number_id": "PHONE_NUMBER_ID"
            },
            "messages": [
              {
                "from": "15559876543",
                "id": "wamid.XXX",
                "timestamp": "1609459200",
                "text": {
                  "body": "Hello"
                },
                "type": "text"
              }
            ]
          },
          "field": "messages"
        }
      ]
    }
  ]
}
```

**Response**: `200 OK`
```json
{
  "success": true,
  "message": "Webhook received and queued for processing"
}
```

### GET /webhook

Verify webhook during Meta setup.

**Query Parameters**:
- `hub.mode` - Should be "subscribe"
- `hub.verify_token` - Your verify token
- `hub.challenge` - Challenge string from Meta

**Request**:
```bash
curl -X GET "https://yoursite.com/wp-json/wch/v1/webhook?hub.mode=subscribe&hub.verify_token=YOUR_TOKEN&hub.challenge=CHALLENGE_STRING"
```

**Response**: `200 OK`
```
CHALLENGE_STRING
```

---

## Conversation Endpoints

### GET /conversations

List all conversations.

**Authentication**: Required

**Query Parameters**:
- `page` (integer, default: 1) - Page number
- `per_page` (integer, default: 20, max: 100) - Results per page
- `status` (string) - Filter by status: `active`, `completed`, `abandoned`
- `search` (string) - Search by customer phone or name
- `orderby` (string) - Sort field: `date`, `customer`, `status`
- `order` (string) - Sort order: `asc`, `desc`

**Request**:
```bash
curl -X GET "https://yoursite.com/wp-json/wch/v1/conversations?status=active&per_page=10" \
  -H "X-WCH-API-Key: your-api-key"
```

**Response**: `200 OK`
```json
{
  "data": [
    {
      "id": 123,
      "customer_phone": "+15559876543",
      "customer_name": "John Doe",
      "status": "active",
      "state": "CART",
      "last_message": "Add to cart",
      "last_message_at": "2024-01-15T10:30:00Z",
      "created_at": "2024-01-15T10:25:00Z",
      "message_count": 8,
      "order_id": null
    }
  ],
  "pagination": {
    "total": 45,
    "pages": 5,
    "current_page": 1,
    "per_page": 10
  }
}
```

### GET /conversations/{id}

Get single conversation details.

**Authentication**: Required

**Path Parameters**:
- `id` (integer) - Conversation ID

**Request**:
```bash
curl -X GET https://yoursite.com/wp-json/wch/v1/conversations/123 \
  -H "X-WCH-API-Key: your-api-key"
```

**Response**: `200 OK`
```json
{
  "id": 123,
  "customer_phone": "+15559876543",
  "customer_name": "John Doe",
  "status": "active",
  "state": "CART",
  "context": {
    "cart_id": 456,
    "viewing_product": null,
    "selected_category": null
  },
  "last_message_at": "2024-01-15T10:30:00Z",
  "created_at": "2024-01-15T10:25:00Z",
  "updated_at": "2024-01-15T10:30:00Z",
  "message_count": 8,
  "order_id": null,
  "customer": {
    "phone": "+15559876543",
    "name": "John Doe",
    "email": "john@example.com",
    "total_orders": 2,
    "total_spent": 150.00
  }
}
```

### GET /conversations/{id}/messages

Get conversation messages.

**Authentication**: Required

**Path Parameters**:
- `id` (integer) - Conversation ID

**Query Parameters**:
- `page` (integer, default: 1)
- `per_page` (integer, default: 50, max: 100)

**Request**:
```bash
curl -X GET https://yoursite.com/wp-json/wch/v1/conversations/123/messages \
  -H "X-WCH-API-Key: your-api-key"
```

**Response**: `200 OK`
```json
{
  "data": [
    {
      "id": 1001,
      "conversation_id": 123,
      "direction": "inbound",
      "message_type": "text",
      "content": {
        "text": "Show me smartphones"
      },
      "status": "delivered",
      "timestamp": "2024-01-15T10:25:00Z",
      "whatsapp_message_id": "wamid.XXX"
    },
    {
      "id": 1002,
      "conversation_id": 123,
      "direction": "outbound",
      "message_type": "interactive",
      "content": {
        "text": "Here are our smartphones:",
        "buttons": [
          {"id": "1", "title": "iPhone 14"},
          {"id": "2", "title": "Samsung S23"}
        ]
      },
      "status": "read",
      "timestamp": "2024-01-15T10:25:10Z",
      "whatsapp_message_id": "wamid.YYY"
    }
  ],
  "pagination": {
    "total": 8,
    "pages": 1,
    "current_page": 1,
    "per_page": 50
  }
}
```

### POST /conversations/{id}/messages

Send a message in conversation.

**Authentication**: Required

**Path Parameters**:
- `id` (integer) - Conversation ID

**Request Body**:
```json
{
  "message_type": "text",
  "content": {
    "text": "Thank you for contacting us. A human agent will be with you shortly."
  }
}
```

**Response**: `201 Created`
```json
{
  "id": 1003,
  "conversation_id": 123,
  "direction": "outbound",
  "message_type": "text",
  "content": {
    "text": "Thank you for contacting us..."
  },
  "status": "queued",
  "timestamp": "2024-01-15T10:35:00Z",
  "whatsapp_message_id": null
}
```

### PATCH /conversations/{id}

Update conversation status or state.

**Authentication**: Required

**Request Body**:
```json
{
  "status": "completed",
  "notes": "Issue resolved by human agent"
}
```

**Response**: `200 OK`
```json
{
  "id": 123,
  "status": "completed",
  "updated_at": "2024-01-15T10:40:00Z"
}
```

---

## Customer Endpoints

### GET /customers

List customers.

**Authentication**: Required

**Query Parameters**:
- `page` (integer)
- `per_page` (integer)
- `search` (string)

**Request**:
```bash
curl -X GET https://yoursite.com/wp-json/wch/v1/customers \
  -H "X-WCH-API-Key: your-api-key"
```

**Response**: `200 OK`
```json
{
  "data": [
    {
      "phone": "+15559876543",
      "name": "John Doe",
      "email": "john@example.com",
      "first_contact": "2024-01-10T14:20:00Z",
      "last_contact": "2024-01-15T10:30:00Z",
      "conversation_count": 3,
      "total_orders": 2,
      "total_spent": 150.00,
      "status": "active"
    }
  ],
  "pagination": {
    "total": 150,
    "pages": 15,
    "current_page": 1,
    "per_page": 10
  }
}
```

### GET /customers/{phone}

Get customer details.

**Authentication**: Required

**Path Parameters**:
- `phone` (string) - Customer phone number (URL encoded)

**Request**:
```bash
curl -X GET "https://yoursite.com/wp-json/wch/v1/customers/%2B15559876543" \
  -H "X-WCH-API-Key: your-api-key"
```

**Response**: `200 OK`
```json
{
  "phone": "+15559876543",
  "name": "John Doe",
  "email": "john@example.com",
  "first_contact": "2024-01-10T14:20:00Z",
  "last_contact": "2024-01-15T10:30:00Z",
  "conversation_count": 3,
  "total_orders": 2,
  "total_spent": 150.00,
  "average_order_value": 75.00,
  "status": "active",
  "recent_conversations": [
    {
      "id": 123,
      "state": "CART",
      "created_at": "2024-01-15T10:25:00Z"
    }
  ],
  "recent_orders": [
    {
      "id": 456,
      "total": 75.00,
      "status": "completed",
      "date": "2024-01-14T15:30:00Z"
    }
  ]
}
```

---

## Analytics Endpoints

### GET /analytics/summary

Get analytics summary for dashboard.

**Authentication**: Required

**Query Parameters**:
- `period` (string, default: "today") - Options: `today`, `yesterday`, `last_7_days`, `last_30_days`, `this_month`, `last_month`

**Request**:
```bash
curl -X GET "https://yoursite.com/wp-json/wch/v1/analytics/summary?period=last_7_days" \
  -H "X-WCH-API-Key: your-api-key"
```

**Response**: `200 OK`
```json
{
  "period": "last_7_days",
  "date_range": {
    "start": "2024-01-08",
    "end": "2024-01-15"
  },
  "metrics": {
    "total_conversations": 125,
    "active_conversations": 45,
    "completed_conversations": 72,
    "abandoned_conversations": 8,
    "total_messages": 1850,
    "total_orders": 58,
    "total_revenue": 4350.00,
    "conversion_rate": 46.4,
    "average_order_value": 75.00,
    "average_response_time": 45.2,
    "customer_satisfaction": 4.5
  },
  "comparisons": {
    "conversations_change": 12.5,
    "revenue_change": 18.2,
    "conversion_rate_change": -2.1
  }
}
```

### GET /analytics/revenue

Get revenue data over time.

**Authentication**: Required

**Query Parameters**:
- `days` (integer, default: 30) - Number of days to analyze

**Request**:
```bash
curl -X GET "https://yoursite.com/wp-json/wch/v1/analytics/revenue?days=7" \
  -H "X-WCH-API-Key: your-api-key"
```

**Response**: `200 OK`
```json
{
  "period": 7,
  "total_revenue": 4350.00,
  "total_orders": 58,
  "average_order_value": 75.00,
  "data": [
    {
      "date": "2024-01-15",
      "revenue": 875.00,
      "orders": 12,
      "average_order_value": 72.92
    },
    {
      "date": "2024-01-14",
      "revenue": 650.00,
      "orders": 8,
      "average_order_value": 81.25
    }
  ]
}
```

### GET /analytics/products

Get top-selling products.

**Authentication**: Required

**Query Parameters**:
- `limit` (integer, default: 10) - Number of products
- `days` (integer, default: 30) - Time period

**Response**: `200 OK`
```json
{
  "data": [
    {
      "product_id": 123,
      "name": "iPhone 14 Pro",
      "sku": "IPHONE14PRO",
      "quantity_sold": 45,
      "revenue": 44955.00,
      "average_price": 999.00,
      "views": 350,
      "conversion_rate": 12.86
    }
  ]
}
```

### GET /analytics/conversations

Get conversation metrics over time.

**Response**: `200 OK`
```json
{
  "period": 7,
  "total_conversations": 125,
  "data": [
    {
      "date": "2024-01-15",
      "total": 22,
      "active": 8,
      "completed": 12,
      "abandoned": 2,
      "average_duration": 320
    }
  ]
}
```

---

## Broadcast Endpoints

### GET /broadcasts

List broadcast campaigns.

**Authentication**: Required

**Response**: `200 OK`
```json
{
  "data": [
    {
      "id": 1,
      "name": "Holiday Sale 2024",
      "status": "sent",
      "template_id": "holiday_sale_2024",
      "target_count": 500,
      "sent_count": 498,
      "delivered_count": 485,
      "read_count": 320,
      "replied_count": 45,
      "scheduled_at": "2024-01-15T09:00:00Z",
      "sent_at": "2024-01-15T09:01:30Z",
      "created_at": "2024-01-14T15:00:00Z"
    }
  ]
}
```

### POST /broadcasts

Create new broadcast campaign.

**Authentication**: Required

**Request Body**:
```json
{
  "name": "Flash Sale Alert",
  "template_id": "flash_sale_template",
  "target_segments": ["active_customers", "high_value"],
  "target_filters": {
    "min_orders": 1,
    "last_active_days": 30
  },
  "template_params": {
    "1": "50% OFF",
    "2": "Electronics",
    "3": "24 hours"
  },
  "schedule_type": "immediate"
}
```

**Response**: `201 Created`
```json
{
  "id": 2,
  "name": "Flash Sale Alert",
  "status": "queued",
  "target_count": 350,
  "created_at": "2024-01-15T11:00:00Z"
}
```

---

## Payment Webhook Endpoints

### POST /payments/stripe/webhook

Stripe payment webhook.

**Headers**:
```
Stripe-Signature: <signature>
```

### POST /payments/razorpay/webhook

Razorpay payment webhook.

**Headers**:
```
X-Razorpay-Signature: <signature>
```

---

## Testing

### Testing with cURL

```bash
# Test authentication
curl -X GET https://yoursite.com/wp-json/wch/v1/conversations \
  -H "X-WCH-API-Key: your-api-key" \
  -v

# Test pagination
curl -X GET "https://yoursite.com/wp-json/wch/v1/conversations?page=2&per_page=5" \
  -H "X-WCH-API-Key: your-api-key"

# Test filtering
curl -X GET "https://yoursite.com/wp-json/wch/v1/conversations?status=active&search=john" \
  -H "X-WCH-API-Key: your-api-key"
```

### Testing with Postman

1. Import the OpenAPI spec (available at `/wp-json/wch/v1/schema`)
2. Set up environment with:
   - `base_url`: Your site URL
   - `api_key`: Your API key
3. Use collection runner for batch testing

### Rate Limit Testing

```bash
# Monitor rate limits
for i in {1..100}; do
  curl -X GET https://yoursite.com/wp-json/wch/v1/conversations \
    -H "X-WCH-API-Key: your-api-key" \
    -I | grep -i ratelimit
  sleep 0.1
done
```

---

## SDKs and Libraries

### PHP Client

```php
$client = new WCH_API_Client([
    'base_url' => 'https://yoursite.com',
    'api_key' => 'your-api-key'
]);

// Get conversations
$conversations = $client->conversations()->list([
    'status' => 'active',
    'per_page' => 10
]);

// Get analytics
$summary = $client->analytics()->summary('last_7_days');
```

### JavaScript Client

```javascript
const client = new WCHClient({
  baseURL: 'https://yoursite.com/wp-json/wch/v1',
  apiKey: 'your-api-key'
});

// Get conversations
const conversations = await client.conversations.list({
  status: 'active',
  perPage: 10
});

// Get analytics summary
const summary = await client.analytics.summary('last_7_days');
```

---

## Webhooks

### Setting Up Webhooks

Your application can subscribe to events via webhooks (coming soon):

**Available Events**:
- `conversation.started`
- `conversation.completed`
- `message.received`
- `message.sent`
- `order.created`
- `order.completed`
- `cart.abandoned`

**Configuration**:
Navigate to: WhatsApp Commerce → Settings → Webhooks

**Payload Example**:
```json
{
  "event": "order.created",
  "timestamp": "2024-01-15T10:30:00Z",
  "data": {
    "order_id": 456,
    "customer_phone": "+15559876543",
    "total": 75.00
  }
}
```

---

## OpenAPI Specification

Full OpenAPI 3.0 specification available at:
```
https://yoursite.com/wp-json/wch/v1/schema
```

Download and import into tools like Swagger UI, Postman, or generate client libraries.

---

## Support

- **API Status**: Check [status page](https://status.yoursite.com)
- **Report Issues**: [GitHub Issues](https://github.com/your-repo/issues)
- **Contact Support**: support@yoursite.com
