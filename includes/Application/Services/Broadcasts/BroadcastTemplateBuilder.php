<?php
/**
 * Broadcast Template Builder
 *
 * Builds WhatsApp template components for broadcast messages.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services\Broadcasts;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BroadcastTemplateBuilder
 *
 * Transforms campaign data into WhatsApp template parameters.
 */
class BroadcastTemplateBuilder {

	/**
	 * Build template components for WhatsApp sendTemplate API.
	 *
	 * @param array $templateData     Template metadata from WhatsApp.
	 * @param array $personalization  Variable mapping data.
	 * @param array $recipient        Recipient details (e.g., name).
	 * @return array<int, array<string, mixed>>
	 */
	public function buildComponents( array $templateData, array $personalization, array $recipient = [] ): array {
		$components = [];
		$templateComponents = $templateData['components'] ?? [];

		if ( ! is_array( $templateComponents ) ) {
			return $components;
		}

		foreach ( $templateComponents as $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}

			$type = strtolower( (string) ( $component['type'] ?? '' ) );
			if ( '' === $type ) {
				continue;
			}

			$text = $component['text'] ?? '';
			if ( ! is_string( $text ) ) {
				continue;
			}

			preg_match_all( '/\{\{(\d+)\}\}/', $text, $matches );
			if ( empty( $matches[1] ) ) {
				continue;
			}

			$parameters = [];
			foreach ( $matches[1] as $varNum ) {
				$mapping = $personalization[ (string) $varNum ] ?? null;
				$parameters[] = [
					'type' => 'text',
					'text' => $this->resolveVariableValue( $mapping, $recipient ),
				];
			}

			$components[] = [
				'type'       => $type,
				'parameters' => $parameters,
			];
		}

		return $components;
	}

	/**
	 * Resolve WhatsApp template language code.
	 *
	 * @param array $templateData Template metadata.
	 * @return string
	 */
	public function getLanguageCode( array $templateData ): string {
		$language = $templateData['language'] ?? null;

		if ( is_array( $language ) && ! empty( $language['code'] ) ) {
			return (string) $language['code'];
		}

		if ( is_string( $language ) && '' !== $language ) {
			return $language;
		}

		return 'en';
	}

	/**
	 * Resolve variable value for personalization.
	 *
	 * @param mixed $mapping   Variable mapping info.
	 * @param array $recipient Recipient data.
	 * @return string
	 */
	private function resolveVariableValue( mixed $mapping, array $recipient ): string {
		$type  = '';
		$value = '';

		if ( is_array( $mapping ) ) {
			$type  = (string) ( $mapping['type'] ?? '' );
			$value = (string) ( $mapping['value'] ?? '' );
		} elseif ( is_scalar( $mapping ) ) {
			$value = (string) $mapping;
		}

		$fallbackName = (string) ( $recipient['name'] ?? 'there' );

		return match ( $type ) {
			'customer_name' => $fallbackName,
			'product_name', 'coupon_code', 'static' => $value,
			default => '' !== $value ? $value : $fallbackName,
		};
	}
}
