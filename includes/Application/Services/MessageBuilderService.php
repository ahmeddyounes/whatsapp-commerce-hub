<?php
/**
 * Message Builder Service
 *
 * Fluent interface for building WhatsApp messages with validation.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services;

use WhatsAppCommerceHub\Contracts\Services\MessageBuilderInterface;
use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;
use WhatsAppCommerceHub\Exceptions\ValidationException;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MessageBuilderService
 *
 * Provides a fluent interface for building WhatsApp messages with proper validation.
 */
class MessageBuilderService implements MessageBuilderInterface {

	/**
	 * Validation constraints.
	 */
	public const MAX_TEXT_LENGTH        = 4096;
	public const MAX_HEADER_TEXT_LENGTH = 60;
	public const MAX_BODY_LENGTH        = 1024;
	public const MAX_FOOTER_LENGTH      = 60;
	public const MAX_BUTTONS            = 3;
	public const MAX_SECTIONS           = 10;
	public const MAX_ROWS_PER_SECTION   = 10;

	/**
	 * Message type.
	 *
	 * @var string
	 */
	protected string $type = 'text';

	/**
	 * Text content for simple text messages.
	 *
	 * @var string
	 */
	protected string $textContent = '';

	/**
	 * Header configuration.
	 *
	 * @var array|null
	 */
	protected ?array $headerConfig = null;

	/**
	 * Body text.
	 *
	 * @var string
	 */
	protected string $bodyText = '';

	/**
	 * Footer text.
	 *
	 * @var string
	 */
	protected string $footerText = '';

	/**
	 * Buttons array.
	 *
	 * @var array
	 */
	protected array $buttons = [];

	/**
	 * Sections for list messages.
	 *
	 * @var array
	 */
	protected array $sections = [];

	/**
	 * Product IDs for product messages.
	 *
	 * @var array
	 */
	protected array $products = [];

	/**
	 * Settings service.
	 *
	 * @var SettingsInterface|null
	 */
	protected ?SettingsInterface $settings;

	/**
	 * Constructor.
	 *
	 * @param SettingsInterface|null $settings Settings service.
	 */
	public function __construct( ?SettingsInterface $settings = null ) {
		$this->settings = $settings;
	}

	/**
	 * {@inheritdoc}
	 */
	public function text( string $content ): static {
		$this->textContent = $content;
		$this->type        = 'text';
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function header( string $type, string $content ): static {
		$this->headerConfig = [
			'type'    => $type,
			'content' => $content,
		];
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function body( string $text ): static {
		$this->bodyText = $text;
		$this->type     = 'interactive';
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function footer( string $text ): static {
		$this->footerText = $text;
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function button( string $type, array $data ): static {
		$this->buttons[] = [
			'type' => $type,
			'data' => $data,
		];
		$this->type      = 'interactive';
		return $this;
	}

	/**
	 * Add a reply button (convenience method).
	 *
	 * @param string $id    Button ID.
	 * @param string $title Button title.
	 * @return static
	 */
	public function replyButton( string $id, string $title ): static {
		return $this->button(
			'reply',
			[
				'id'    => $id,
				'title' => $title,
			]
		);
	}

	/**
	 * Add a URL button (convenience method).
	 *
	 * @param string $title Button title.
	 * @param string $url   URL to open.
	 * @return static
	 */
	public function urlButton( string $title, string $url ): static {
		return $this->button(
			'url',
			[
				'title' => $title,
				'url'   => $url,
			]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function section( string $title, array $rows ): static {
		$this->sections[] = [
			'title' => $title,
			'rows'  => $rows,
		];
		$this->type       = 'interactive';
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function product( string $productId ): static {
		$this->products = [ $productId ];
		$this->type     = 'interactive';
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function productList( array $productIds ): static {
		$this->products = $productIds;
		$this->type     = 'interactive';
		return $this;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws ValidationException If validation fails.
	 */
	public function build(): array {
		if ( 'text' === $this->type && '' !== $this->textContent ) {
			return $this->buildTextMessage();
		}

		if ( 'interactive' === $this->type ) {
			return $this->buildInteractiveMessage();
		}

		throw ValidationException::forField( 'type', 'Invalid message configuration' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function reset(): static {
		$this->type         = 'text';
		$this->textContent  = '';
		$this->headerConfig = null;
		$this->bodyText     = '';
		$this->footerText   = '';
		$this->buttons      = [];
		$this->sections     = [];
		$this->products     = [];
		return $this;
	}

	/**
	 * Build text message.
	 *
	 * @return array
	 * @throws ValidationException If validation fails.
	 */
	protected function buildTextMessage(): array {
		$this->validateTextLength( $this->textContent, self::MAX_TEXT_LENGTH, 'text' );

		return [
			'type' => 'text',
			'text' => [
				'body' => $this->textContent,
			],
		];
	}

	/**
	 * Build interactive message.
	 *
	 * @return array
	 * @throws ValidationException If validation fails.
	 */
	protected function buildInteractiveMessage(): array {
		$interactiveType = $this->determineInteractiveType();

		$this->validateInteractiveMessage( $interactiveType );

		$message = [
			'type'        => 'interactive',
			'interactive' => [
				'type' => $interactiveType,
			],
		];

		// Add header if present.
		if ( null !== $this->headerConfig ) {
			$message['interactive']['header'] = $this->buildHeader();
		}

		// Add body.
		if ( '' !== $this->bodyText ) {
			$message['interactive']['body'] = [
				'text' => $this->bodyText,
			];
		}

		// Add footer if present.
		if ( '' !== $this->footerText ) {
			$message['interactive']['footer'] = [
				'text' => $this->footerText,
			];
		}

		// Add action based on type.
		$message['interactive']['action'] = $this->buildAction( $interactiveType );

		return $message;
	}

	/**
	 * Determine interactive message type.
	 *
	 * @return string
	 */
	protected function determineInteractiveType(): string {
		if ( ! empty( $this->buttons ) ) {
			return 'button';
		}

		if ( ! empty( $this->sections ) ) {
			return 'list';
		}

		if ( 1 === count( $this->products ) ) {
			return 'product';
		}

		if ( count( $this->products ) > 1 ) {
			return 'product_list';
		}

		return 'button';
	}

	/**
	 * Build action based on interactive type.
	 *
	 * @param string $interactiveType Interactive message type.
	 * @return array
	 */
	protected function buildAction( string $interactiveType ): array {
		return match ( $interactiveType ) {
			'button'       => [ 'buttons' => $this->buildButtons() ],
			'list'         => [
				'button'   => $this->headerConfig['content'] ?? 'Select',
				'sections' => $this->buildSections(),
			],
			'product'      => [
				'catalog_id'          => $this->getCatalogId(),
				'product_retailer_id' => $this->products[0],
			],
			'product_list' => [
				'catalog_id' => $this->getCatalogId(),
				'sections'   => $this->buildProductSections(),
			],
			default        => [],
		};
	}

	/**
	 * Validate interactive message.
	 *
	 * @param string $interactiveType Interactive message type.
	 * @throws ValidationException If validation fails.
	 */
	protected function validateInteractiveMessage( string $interactiveType ): void {
		// Validate header text length.
		if ( null !== $this->headerConfig && 'text' === $this->headerConfig['type'] ) {
			$this->validateTextLength(
				$this->headerConfig['content'],
				self::MAX_HEADER_TEXT_LENGTH,
				'header'
			);
		}

		// Validate body text length.
		if ( '' !== $this->bodyText ) {
			$this->validateTextLength( $this->bodyText, self::MAX_BODY_LENGTH, 'body' );
		}

		// Validate footer text length.
		if ( '' !== $this->footerText ) {
			$this->validateTextLength( $this->footerText, self::MAX_FOOTER_LENGTH, 'footer' );
		}

		// Validate buttons.
		if ( 'button' === $interactiveType ) {
			$this->validateButtons();
		}

		// Validate sections.
		if ( 'list' === $interactiveType ) {
			$this->validateSections();
		}
	}

	/**
	 * Validate buttons.
	 *
	 * @throws ValidationException If validation fails.
	 */
	protected function validateButtons(): void {
		if ( count( $this->buttons ) > self::MAX_BUTTONS ) {
			throw ValidationException::forField(
				'buttons',
				sprintf( 'Maximum %d buttons allowed, %d provided', self::MAX_BUTTONS, count( $this->buttons ) )
			);
		}

		if ( empty( $this->buttons ) ) {
			throw ValidationException::forField( 'buttons', 'At least one button is required for button messages' );
		}
	}

	/**
	 * Validate sections.
	 *
	 * @throws ValidationException If validation fails.
	 */
	protected function validateSections(): void {
		if ( count( $this->sections ) > self::MAX_SECTIONS ) {
			throw ValidationException::forField(
				'sections',
				sprintf( 'Maximum %d sections allowed, %d provided', self::MAX_SECTIONS, count( $this->sections ) )
			);
		}

		foreach ( $this->sections as $index => $section ) {
			if ( count( $section['rows'] ) > self::MAX_ROWS_PER_SECTION ) {
				throw ValidationException::forField(
					"sections[{$index}].rows",
					sprintf(
						'Section %d has %d rows, maximum %d allowed',
						$index + 1,
						count( $section['rows'] ),
						self::MAX_ROWS_PER_SECTION
					)
				);
			}
		}
	}

	/**
	 * Validate text length.
	 *
	 * @param string $text      Text to validate.
	 * @param int    $maxLength Maximum length.
	 * @param string $fieldName Field name.
	 * @throws ValidationException If text exceeds max length.
	 */
	protected function validateTextLength( string $text, int $maxLength, string $fieldName ): void {
		$length = mb_strlen( $text );
		if ( $length > $maxLength ) {
			throw ValidationException::forField(
				$fieldName,
				sprintf( '%s exceeds maximum length of %d characters (%d provided)', ucfirst( $fieldName ), $maxLength, $length )
			);
		}
	}

	/**
	 * Build header array.
	 *
	 * @return array
	 */
	protected function buildHeader(): array {
		$type    = $this->headerConfig['type'];
		$content = $this->headerConfig['content'];

		if ( 'text' === $type ) {
			return [
				'type' => 'text',
				'text' => $content,
			];
		}

		return [
			'type' => $type,
			$type  => [
				'link' => $content,
			],
		];
	}

	/**
	 * Build buttons array.
	 *
	 * @return array
	 */
	protected function buildButtons(): array {
		$formatted = [];

		foreach ( $this->buttons as $button ) {
			$type = $button['type'];
			$data = $button['data'];

			$formatted[] = match ( $type ) {
				'reply' => [
					'type'  => 'reply',
					'reply' => [
						'id'    => $data['id'] ?? uniqid( 'btn_' ),
						'title' => $data['title'],
					],
				],
				'url'   => [
					'type' => 'url',
					'url'  => [
						'display_text' => $data['title'],
						'url'          => $data['url'],
					],
				],
				'phone' => [
					'type'         => 'phone_number',
					'phone_number' => [
						'display_text' => $data['title'],
						'phone_number' => $data['phone'],
					],
				],
				'flow'  => [
					'type' => 'flow',
					'flow' => [
						'id'                  => $data['flow_id'],
						'cta'                 => $data['title'],
						'flow_action'         => $data['action'] ?? 'navigate',
						'flow_action_payload' => $data['payload'] ?? [],
					],
				],
				default => [],
			};
		}

		return array_filter( $formatted );
	}

	/**
	 * Build sections array.
	 *
	 * @return array
	 */
	protected function buildSections(): array {
		$formatted = [];

		foreach ( $this->sections as $section ) {
			$rows = [];

			foreach ( $section['rows'] as $row ) {
				$rows[] = [
					'id'          => $row['id'] ?? uniqid( 'row_' ),
					'title'       => $row['title'],
					'description' => $row['description'] ?? '',
				];
			}

			$formatted[] = [
				'title' => $section['title'],
				'rows'  => $rows,
			];
		}

		return $formatted;
	}

	/**
	 * Build product sections.
	 *
	 * @return array
	 */
	protected function buildProductSections(): array {
		return [
			[
				'title'         => 'Products',
				'product_items' => array_map(
					fn( string $productId ): array => [ 'product_retailer_id' => $productId ],
					$this->products
				),
			],
		];
	}

	/**
	 * Get catalog ID from settings.
	 *
	 * @return string
	 */
	protected function getCatalogId(): string {
		if ( null !== $this->settings ) {
			return $this->settings->get( 'api.catalog_id', '' );
		}

		// Fallback to legacy settings.
		if ( class_exists( 'WCH_Settings' ) ) {
			return \WCH_Settings::getInstance()->get( 'api.catalog_id', '' );
		}

		return '';
	}

	/**
	 * Create a new instance (factory method).
	 *
	 * @return static
	 */
	public static function create(): static {
		return new static();
	}
}
