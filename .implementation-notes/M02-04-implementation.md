# M02-04 Implementation Notes

## Summary
Implemented customer profile management system linking WhatsApp users to WooCommerce customers with full GDPR compliance.

## Files Created

### 1. includes/class-wch-customer-profile.php
- **WCH_Customer_Profile** class representing a WhatsApp customer profile
- Properties:
  - `phone` (E.164 format)
  - `wc_customer_id` (nullable)
  - `name`
  - `saved_addresses` (array)
  - `order_history` (from WooCommerce)
  - `preferences` (language, currency)
  - `opt_in_marketing`
  - `lifetime_value` (calculated)
  - `last_order_date`
  - `total_orders`
  - `created_at`
  - `updated_at`
- Method: `to_array()` for serialization

### 2. includes/class-wch-customer-service.php
- **WCH_Customer_Service** singleton service class
- Implements all required methods:

#### Core Methods
1. **get_or_create_profile($phone)**
   - Normalizes phone to E.164 format
   - Checks wch_customer_profiles table
   - Creates profile if not exists
   - Returns WCH_Customer_Profile object

2. **link_to_wc_customer($phone, $wc_customer_id)**
   - Associates WhatsApp profile with WooCommerce customer
   - Updates profile with WC customer ID
   - Enables order history merge

3. **find_wc_customer_by_phone($phone)**
   - Searches WC customers by billing_phone meta
   - Handles phone format variations (+1xxx, 1xxx, xxx)
   - Returns customer_id or null

#### Address Management
4. **save_address($phone, $address_data, $is_default)**
   - Validates required address fields (address_1, city, postcode, country)
   - Stores in saved_addresses JSON
   - Marks as default if specified

5. **get_default_address($phone)**
   - Returns default saved address or null

#### Preferences & Data
6. **update_preferences($phone, $preferences)**
   - Merges and saves preference updates
   - Supports language, currency, etc.

7. **get_order_history($phone)**
   - If linked to WC customer, fetches their orders
   - Otherwise fetches orders with matching billing_phone
   - Handles phone variations for matching

8. **calculate_customer_stats($phone)**
   - Returns:
     - `total_orders`
     - `total_spent`
     - `average_order_value`
     - `days_since_last_order`

#### GDPR Compliance
9. **export_customer_data($phone)**
   - Exports complete customer data for GDPR
   - Includes profile, orders, conversations, stats
   - Returns structured array with timestamp

10. **delete_customer_data($phone)**
    - Anonymizes conversations (sets phone to 'ANONYMIZED')
    - Removes phone references from message content
    - Deletes customer profile
    - Full GDPR erasure compliance

#### Helper Methods
- **normalize_phone($phone)**: Converts phone to E.164 format
- **get_phone_variations($phone)**: Generates format variations for matching
- **format_order($order)**: Formats WC order for profile
- **row_to_profile($row)**: Converts DB row to profile object

### 3. test-customer-service.php
- Comprehensive test script covering:
  - Profile creation
  - Address saving/retrieval
  - Preference updates
  - Statistics calculation
  - Phone normalization
  - GDPR export/deletion

## Key Features

### Phone Normalization
- All phone numbers normalized to E.164 format (+XXXXXXXXXXX)
- Handles various input formats: (123) 456-7890, 123-456-7890, etc.

### Phone Variation Matching
- Searches for WooCommerce customers with multiple phone formats
- Handles country code variations (+1, 1, without prefix)
- Ensures cross-system compatibility

### Data Persistence
- Uses existing wch_customer_profiles table from database schema
- JSON storage for addresses and preferences
- Efficient lookups via indexed phone column

### Order History Integration
- Automatically fetches orders if linked to WC customer
- Falls back to billing_phone matching if not linked
- Deduplicates results across phone variations

### GDPR Compliance
- Complete data export with all related records
- Thorough anonymization on deletion
- Conversation and message data handled
- Profile permanently removed

## Acceptance Criteria Status

✅ Profiles persist across conversations
✅ WC linking works with phone variations
✅ Addresses save and retrieve correctly
✅ GDPR methods complete (export & delete)
✅ Customer stats accurate

## Integration Points

- Integrates with existing WCH_Database_Manager
- Uses WCH_Logger for all operations
- Compatible with WooCommerce customer and order APIs
- Works with existing conversations and messages tables

## No Breaking Changes

- Uses existing database schema (no migrations needed)
- Follows established plugin patterns (singleton, autoloader)
- Compatible with existing codebase conventions
