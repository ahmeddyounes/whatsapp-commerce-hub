<?php
/**
 * Conversation Entity - Alias
 *
 * This file maintains backward compatibility by aliasing to the canonical Domain model.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 * @deprecated Use WhatsAppCommerceHub\Domain\Conversation\Conversation instead
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Entities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @deprecated Use WhatsAppCommerceHub\Domain\Conversation\Conversation instead
 */
class_alias(
	\WhatsAppCommerceHub\Domain\Conversation\Conversation::class,
	__NAMESPACE__ . '\Conversation'
);
