<?php
/**
 * Message Builder Class
 *
 * Fluent interface for building WhatsApp messages with validation.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WCH_Message_Builder
 *
 * Provides a fluent interface for building WhatsApp messages with proper validation.
 */
class WCH_Message_Builder {

	/**
	 * Message type
	 *
	 * @var string
	 */
	private $type = 'text';

	/**
	 * Text content for simple text messages
	 *
	 * @var string
	 */
	private $text_content = '';

	/**
	 * Header configuration
	 *
	 * @var array|null
	 */
	private $header = null;

	/**
	 * Body text
	 *
	 * @var string
	 */
	private $body = '';

	/**
	 * Footer text
	 *
	 * @var string
	 */
	private $footer = '';

	/**
	 * Buttons array
	 *
	 * @var array
	 */
	private $buttons = array();

	/**
	 * Sections for list messages
	 *
	 * @var array
	 */
	private $sections = array();

	/**
	 * Product IDs for product messages
	 *
	 * @var array
	 */
	private $products = array();

	/**
	 * Validation constraints
	 */
	const MAX_TEXT_LENGTH          = 4096;
	const MAX_HEADER_TEXT_LENGTH   = 60;
	const MAX_BODY_LENGTH          = 1024;
	const MAX_FOOTER_LENGTH        = 60;
	const MAX_BUTTONS              = 3;
	const MAX_SECTIONS             = 10;
	const MAX_ROWS_PER_SECTION     = 10;

	/**
	 * Set text content for simple text message
	 *
	 * @param string $content Text content.
	 * @return self
	 */
	public function text( $content ) {
		$this->text_content = $content;
		$this->type         = 'text';
		return $this;
	}

	/**
	 * Set header for interactive message
	 *
	 * @param string $type    Header type (text|image|document|video).
	 * @param mixed  $content Header content (text string or media URL/ID).
	 * @return self
	 */
	public function header( $type, $content ) {
		$this->header = array(
			'type' => $type,
			'content' => $content,
		);
		return $this;
	}

	/**
	 * Set body text for interactive message
	 *
	 * @param string $text Body text.
	 * @return self
	 */
	public function body( $text ) {
		$this->body = $text;
		$this->type = 'interactive';
		return $this;
	}

	/**
	 * Set footer text for interactive message
	 *
	 * @param string $text Footer text.
	 * @return self
	 */
	public function footer( $text ) {
		$this->footer = $text;
		return $this;
	}

	/**
	 * Add a button to the message
	 *
	 * @param string $type Button type (reply|url|phone|flow).
	 * @param array  $data Button data depending on type.
	 * @return self
	 */
	public function button( $type, $data ) {
		$this->buttons[] = array(
			'type' => $type,
			'data' => $data,
		);
		$this->type = 'interactive';
		return $this;
	}

	/**
	 * Add a section to list message
	 *
	 * @param string $title Section title.
	 * @param array  $rows  Section rows.
	 * @return self
	 */
	public function section( $title, $rows ) {
		$this->sections[] = array(
			'title' => $title,
			'rows'  => $rows,
		);
		$this->type = 'interactive';
		return $this;
	}

	/**
	 * Add a single product
	 *
	 * @param string $product_id Product ID.
	 * @return self
	 */
	public function product( $product_id ) {
		$this->products   = array( $product_id );
		$this->type       = 'interactive';
		return $this;
	}

	/**
	 * Add multiple products
	 *
	 * @param array $product_ids Array of product IDs.
	 * @return self
	 */
	public function product_list( $product_ids ) {
		$this->products = $product_ids;
		$this->type     = 'interactive';
		return $this;
	}

	/**
	 * Build and validate the message
	 *
	 * @throws WCH_Exception If validation fails.
	 * @return array Formatted message array for WhatsApp API.
	 */
	public function build() {
		if ( 'text' === $this->type && ! empty( $this->text_content ) ) {
			return $this->build_text_message();
		}

		if ( 'interactive' === $this->type ) {
			return $this->build_interactive_message();
		}

		throw new WCH_Exception(
			'Invalid message configuration',
			'MESSAGE_BUILD_ERROR',
			array( 'type' => $this->type )
		);
	}

	/**
	 * Build text message
	 *
	 * @throws WCH_Exception If validation fails.
	 * @return array
	 */
	private function build_text_message() {
		$this->validate_text_length( $this->text_content, self::MAX_TEXT_LENGTH, 'Text' );

		return array(
			'type' => 'text',
			'text' => array(
				'body' => $this->text_content,
			),
		);
	}

	/**
	 * Build interactive message
	 *
	 * @throws WCH_Exception If validation fails.
	 * @return array
	 */
	private function build_interactive_message() {
		// Determine interactive type
		$interactive_type = $this->determine_interactive_type();

		// Validate based on type
		$this->validate_interactive_message( $interactive_type );

		// Build message structure
		$message = array(
			'type'        => 'interactive',
			'interactive' => array(
				'type' => $interactive_type,
			),
		);

		// Add header if present
		if ( ! empty( $this->header ) ) {
			$message['interactive']['header'] = $this->build_header();
		}

		// Add body
		if ( ! empty( $this->body ) ) {
			$message['interactive']['body'] = array(
				'text' => $this->body,
			);
		}

		// Add footer if present
		if ( ! empty( $this->footer ) ) {
			$message['interactive']['footer'] = array(
				'text' => $this->footer,
			);
		}

		// Add action based on type
		if ( 'button' === $interactive_type ) {
			$message['interactive']['action'] = array(
				'buttons' => $this->build_buttons(),
			);
		} elseif ( 'list' === $interactive_type ) {
			$message['interactive']['action'] = array(
				'button'   => $this->header['content'] ?? 'Select',
				'sections' => $this->build_sections(),
			);
		} elseif ( 'product' === $interactive_type ) {
			$message['interactive']['action'] = array(
				'catalog_id'       => $this->get_catalog_id(),
				'product_retailer_id' => $this->products[0],
			);
		} elseif ( 'product_list' === $interactive_type ) {
			$message['interactive']['action'] = array(
				'catalog_id' => $this->get_catalog_id(),
				'sections'   => $this->build_product_sections(),
			);
		}

		return $message;
	}

	/**
	 * Determine interactive message type
	 *
	 * @return string
	 */
	private function determine_interactive_type() {
		if ( ! empty( $this->buttons ) ) {
			return 'button';
		}

		if ( ! empty( $this->sections ) ) {
			return 'list';
		}

		if ( count( $this->products ) === 1 ) {
			return 'product';
		}

		if ( count( $this->products ) > 1 ) {
			return 'product_list';
		}

		return 'button'; // Default to button type
	}

	/**
	 * Validate interactive message
	 *
	 * @param string $interactive_type Interactive message type.
	 * @throws WCH_Exception If validation fails.
	 */
	private function validate_interactive_message( $interactive_type ) {
		// Validate header text length
		if ( ! empty( $this->header ) && 'text' === $this->header['type'] ) {
			$this->validate_text_length(
				$this->header['content'],
				self::MAX_HEADER_TEXT_LENGTH,
				'Header text'
			);
		}

		// Validate body text length
		if ( ! empty( $this->body ) ) {
			$this->validate_text_length(
				$this->body,
				self::MAX_BODY_LENGTH,
				'Body text'
			);
		}

		// Validate footer text length
		if ( ! empty( $this->footer ) ) {
			$this->validate_text_length(
				$this->footer,
				self::MAX_FOOTER_LENGTH,
				'Footer text'
			);
		}

		// Validate buttons
		if ( 'button' === $interactive_type ) {
			if ( count( $this->buttons ) > self::MAX_BUTTONS ) {
				throw new WCH_Exception(
					sprintf(
						'Maximum %d buttons allowed, %d provided',
						self::MAX_BUTTONS,
						count( $this->buttons )
					),
					'MESSAGE_VALIDATION_ERROR',
					array( 'max_buttons' => self::MAX_BUTTONS, 'provided' => count( $this->buttons ) )
				);
			}

			if ( empty( $this->buttons ) ) {
				throw new WCH_Exception(
					'At least one button is required for button messages',
					'MESSAGE_VALIDATION_ERROR'
				);
			}
		}

		// Validate sections
		if ( 'list' === $interactive_type ) {
			if ( count( $this->sections ) > self::MAX_SECTIONS ) {
				throw new WCH_Exception(
					sprintf(
						'Maximum %d sections allowed, %d provided',
						self::MAX_SECTIONS,
						count( $this->sections )
					),
					'MESSAGE_VALIDATION_ERROR',
					array( 'max_sections' => self::MAX_SECTIONS, 'provided' => count( $this->sections ) )
				);
			}

			foreach ( $this->sections as $index => $section ) {
				if ( count( $section['rows'] ) > self::MAX_ROWS_PER_SECTION ) {
					throw new WCH_Exception(
						sprintf(
							'Section %d has %d rows, maximum %d allowed',
							$index + 1,
							count( $section['rows'] ),
							self::MAX_ROWS_PER_SECTION
						),
						'MESSAGE_VALIDATION_ERROR',
						array(
							'section_index' => $index,
							'max_rows'      => self::MAX_ROWS_PER_SECTION,
							'provided'      => count( $section['rows'] ),
						)
					);
				}
			}
		}
	}

	/**
	 * Validate text length
	 *
	 * @param string $text      Text to validate.
	 * @param int    $max_length Maximum allowed length.
	 * @param string $field_name Field name for error message.
	 * @throws WCH_Exception If text exceeds max length.
	 */
	private function validate_text_length( $text, $max_length, $field_name ) {
		$length = mb_strlen( $text );
		if ( $length > $max_length ) {
			throw new WCH_Exception(
				sprintf(
					'%s exceeds maximum length of %d characters (%d provided)',
					$field_name,
					$max_length,
					$length
				),
				'MESSAGE_VALIDATION_ERROR',
				array(
					'field'      => $field_name,
					'max_length' => $max_length,
					'provided'   => $length,
				)
			);
		}
	}

	/**
	 * Build header array
	 *
	 * @return array
	 */
	private function build_header() {
		$type = $this->header['type'];
		$content = $this->header['content'];

		if ( 'text' === $type ) {
			return array(
				'type' => 'text',
				'text' => $content,
			);
		}

		// For media types (image, document, video)
		return array(
			'type' => $type,
			$type => array(
				'link' => $content, // Assuming content is a URL
			),
		);
	}

	/**
	 * Build buttons array
	 *
	 * @return array
	 */
	private function build_buttons() {
		$formatted_buttons = array();

		foreach ( $this->buttons as $button ) {
			$type = $button['type'];
			$data = $button['data'];

			if ( 'reply' === $type ) {
				$formatted_buttons[] = array(
					'type'  => 'reply',
					'reply' => array(
						'id'    => $data['id'] ?? uniqid( 'btn_' ),
						'title' => $data['title'],
					),
				);
			} elseif ( 'url' === $type ) {
				$formatted_buttons[] = array(
					'type' => 'url',
					'url'  => array(
						'display_text' => $data['title'],
						'url'          => $data['url'],
					),
				);
			} elseif ( 'phone' === $type ) {
				$formatted_buttons[] = array(
					'type'  => 'phone_number',
					'phone_number' => array(
						'display_text' => $data['title'],
						'phone_number' => $data['phone'],
					),
				);
			} elseif ( 'flow' === $type ) {
				$formatted_buttons[] = array(
					'type' => 'flow',
					'flow' => array(
						'id'                => $data['flow_id'],
						'cta'               => $data['title'],
						'flow_action'       => $data['action'] ?? 'navigate',
						'flow_action_payload' => $data['payload'] ?? array(),
					),
				);
			}
		}

		return $formatted_buttons;
	}

	/**
	 * Build sections array for list messages
	 *
	 * @return array
	 */
	private function build_sections() {
		$formatted_sections = array();

		foreach ( $this->sections as $section ) {
			$formatted_rows = array();

			foreach ( $section['rows'] as $row ) {
				$formatted_rows[] = array(
					'id'          => $row['id'] ?? uniqid( 'row_' ),
					'title'       => $row['title'],
					'description' => $row['description'] ?? '',
				);
			}

			$formatted_sections[] = array(
				'title' => $section['title'],
				'rows'  => $formatted_rows,
			);
		}

		return $formatted_sections;
	}

	/**
	 * Build product sections
	 *
	 * @return array
	 */
	private function build_product_sections() {
		return array(
			array(
				'title'              => 'Products',
				'product_items' => array_map(
					function ( $product_id ) {
						return array( 'product_retailer_id' => $product_id );
					},
					$this->products
				),
			),
		);
	}

	/**
	 * Get catalog ID from settings
	 *
	 * @return string
	 */
	private function get_catalog_id() {
		$settings = WCH_Settings::getInstance();
		return $settings->get( 'api.catalog_id', '' );
	}

	/**
	 * Reset builder state
	 *
	 * @return self
	 */
	public function reset() {
		$this->type         = 'text';
		$this->text_content = '';
		$this->header       = null;
		$this->body         = '';
		$this->footer       = '';
		$this->buttons      = array();
		$this->sections     = array();
		$this->products     = array();
		return $this;
	}
}
