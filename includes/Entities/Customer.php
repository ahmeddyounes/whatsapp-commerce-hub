<?php
/**
 * Customer Entity - Alias
 *
 * This file maintains backward compatibility by aliasing to the canonical Domain model.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 * @deprecated Use WhatsAppCommerceHub\Domain\Customer\Customer instead
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Entities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @deprecated Use WhatsAppCommerceHub\Domain\Customer\Customer instead
 */
class_alias(
	\WhatsAppCommerceHub\Domain\Customer\Customer::class,
	__NAMESPACE__ . '\Customer'
);
