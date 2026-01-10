<?php
/**
 * Message Builder Factory
 *
 * Factory for creating WCH_Message_Builder instances.
 * Enables dependency injection and testability.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MessageBuilderFactory
 *
 * Creates message builder instances with optional configuration.
 */
class MessageBuilderFactory {

	/**
	 * Default configuration for message builders.
	 *
	 * @var array
	 */
	private array $defaults;

	/**
	 * Constructor.
	 *
	 * @param array $defaults Default configuration to apply to new builders.
	 */
	public function __construct( array $defaults = array() ) {
		$this->defaults = array_merge(
			array(
				'footer' => '',
			),
			$defaults
		);
	}

	/**
	 * Create a new message builder instance.
	 *
	 * @return \WCH_Message_Builder
	 */
	public function create(): \WCH_Message_Builder {
		$builder = new \WCH_Message_Builder();

		// Apply default footer if configured.
		if ( ! empty( $this->defaults['footer'] ) ) {
			$builder->footer( $this->defaults['footer'] );
		}

		return $builder;
	}

	/**
	 * Create a text message builder.
	 *
	 * @param string $text The text content.
	 * @return \WCH_Message_Builder
	 */
	public function text( string $text ): \WCH_Message_Builder {
		return $this->create()->text( $text );
	}

	/**
	 * Create an interactive message builder with body.
	 *
	 * @param string $body The body text.
	 * @return \WCH_Message_Builder
	 */
	public function interactive( string $body ): \WCH_Message_Builder {
		return $this->create()->body( $body );
	}

	/**
	 * Create a message builder with header and body.
	 *
	 * @param string $header_type    Header type (text|image|document|video).
	 * @param mixed  $header_content Header content.
	 * @param string $body           Body text.
	 * @return \WCH_Message_Builder
	 */
	public function withHeader( string $header_type, $header_content, string $body ): \WCH_Message_Builder {
		return $this->create()
			->header( $header_type, $header_content )
			->body( $body );
	}

	/**
	 * Create a list message builder.
	 *
	 * @param string $body        Body text.
	 * @param string $button_text Button text for the list.
	 * @return \WCH_Message_Builder
	 */
	public function list( string $body, string $button_text ): \WCH_Message_Builder {
		return $this->create()
			->body( $body )
			->button( 'list', array( 'text' => $button_text ) );
	}

	/**
	 * Create a button message builder.
	 *
	 * @param string $body Body text.
	 * @return \WCH_Message_Builder
	 */
	public function buttons( string $body ): \WCH_Message_Builder {
		return $this->create()->body( $body );
	}

	/**
	 * Create a product catalog message builder.
	 *
	 * @param string $body        Body text.
	 * @param array  $product_ids Product IDs to display.
	 * @return \WCH_Message_Builder
	 */
	public function products( string $body, array $product_ids ): \WCH_Message_Builder {
		$builder = $this->create()->body( $body );

		foreach ( $product_ids as $product_id ) {
			$builder->product( $product_id );
		}

		return $builder;
	}

	/**
	 * Update default configuration.
	 *
	 * @param array $defaults New defaults to merge.
	 * @return self
	 */
	public function withDefaults( array $defaults ): self {
		return new self( array_merge( $this->defaults, $defaults ) );
	}

	/**
	 * Get current default configuration.
	 *
	 * @return array
	 */
	public function getDefaults(): array {
		return $this->defaults;
	}
}
