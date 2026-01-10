<?php
/**
 * Message Builder
 *
 * Fluent interface for building WhatsApp messages with validation.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Support\Messaging;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Message Builder Class
 *
 * Provides a fluent interface for building WhatsApp messages with proper validation.
 */
class MessageBuilder
{
    /**
     * Validation constraints
     */
    private const MAX_TEXT_LENGTH = 4096;
    private const MAX_HEADER_TEXT_LENGTH = 60;
    private const MAX_BODY_LENGTH = 1024;
    private const MAX_FOOTER_LENGTH = 60;
    private const MAX_BUTTONS = 3;
    private const MAX_LIST_SECTIONS = 10;

    /**
     * Message type
     */
    private string $type = 'text';

    /**
     * Text content for simple text messages
     */
    private string $textContent = '';

    /**
     * Header configuration
     *
     * @var array<string, mixed>|null
     */
    private ?array $header = null;

    /**
     * Body text
     */
    private string $body = '';

    /**
     * Footer text
     */
    private string $footer = '';

    /**
     * Buttons array
     *
     * @var array<int, array<string, mixed>>
     */
    private array $buttons = [];

    /**
     * Sections for list messages
     *
     * @var array<int, array<string, mixed>>
     */
    private array $sections = [];

    /**
     * Product IDs for product messages
     *
     * @var array<int, string>
     */
    private array $products = [];

    /**
     * Set text content for simple text message
     *
     * @param string $content Text content
     * @return self For method chaining
     */
    public function text(string $content): self
    {
        $this->type = 'text';
        $this->textContent = $content;
        return $this;
    }

    /**
     * Set header for interactive message
     *
     * @param string $type Header type ('text', 'image', 'video', 'document')
     * @param string $content Header content
     * @return self For method chaining
     */
    public function header(string $type, string $content): self
    {
        $this->header = [
            'type' => $type,
            'content' => $content,
        ];
        return $this;
    }

    /**
     * Set body text
     *
     * @param string $text Body text
     * @return self For method chaining
     */
    public function body(string $text): self
    {
        $this->body = $text;
        return $this;
    }

    /**
     * Set footer text
     *
     * @param string $text Footer text
     * @return self For method chaining
     */
    public function footer(string $text): self
    {
        $this->footer = $text;
        return $this;
    }

    /**
     * Add button to interactive message
     *
     * @param string $type Button type ('reply' or 'url')
     * @param array<string, string> $data Button data
     * @return self For method chaining
     */
    public function button(string $type, array $data): self
    {
        $this->buttons[] = [
            'type' => $type,
            'data' => $data,
        ];
        return $this;
    }

    /**
     * Add section to list message
     *
     * @param string $title Section title
     * @param array<int, array<string, string>> $rows Section rows
     * @return self For method chaining
     */
    public function section(string $title, array $rows): self
    {
        $this->sections[] = [
            'title' => $title,
            'rows' => $rows,
        ];
        return $this;
    }

    /**
     * Add product to product message
     *
     * @param string $productId Product catalog ID
     * @return self For method chaining
     */
    public function product(string $productId): self
    {
        $this->products[] = $productId;
        $this->type = 'product';
        return $this;
    }

    /**
     * Set multiple products
     *
     * @param array<int, string> $productIds Product catalog IDs
     * @return self For method chaining
     */
    public function productList(array $productIds): self
    {
        $this->products = $productIds;
        $this->type = 'product_list';
        return $this;
    }

    /**
     * Build message payload
     *
     * @return array<string, mixed> WhatsApp API message payload
     * @throws \InvalidArgumentException If validation fails
     */
    public function build(): array
    {
        $this->validate();

        return match ($this->type) {
            'text' => $this->buildTextMessage(),
            'interactive' => $this->buildInteractiveMessage(),
            'list' => $this->buildListMessage(),
            'product' => $this->buildProductMessage(),
            'product_list' => $this->buildProductListMessage(),
            default => throw new \InvalidArgumentException("Unsupported message type: {$this->type}"),
        };
    }

    /**
     * Build simple text message
     *
     * @return array<string, mixed> Message payload
     */
    private function buildTextMessage(): array
    {
        return [
            'type' => 'text',
            'text' => [
                'body' => $this->textContent,
            ],
        ];
    }

    /**
     * Build interactive message (buttons)
     *
     * @return array<string, mixed> Message payload
     */
    private function buildInteractiveMessage(): array
    {
        $interactive = [
            'type' => 'button',
            'body' => [
                'text' => $this->body,
            ],
        ];

        if ($this->header) {
            $interactive['header'] = $this->formatHeader();
        }

        if ($this->footer) {
            $interactive['footer'] = [
                'text' => $this->footer,
            ];
        }

        if (!empty($this->buttons)) {
            $interactive['action'] = [
                'buttons' => $this->formatButtons(),
            ];
        }

        return [
            'type' => 'interactive',
            'interactive' => $interactive,
        ];
    }

    /**
     * Build list message
     *
     * @return array<string, mixed> Message payload
     */
    private function buildListMessage(): array
    {
        $interactive = [
            'type' => 'list',
            'body' => [
                'text' => $this->body,
            ],
            'action' => [
                'button' => 'View Options',
                'sections' => $this->sections,
            ],
        ];

        if ($this->header) {
            $interactive['header'] = $this->formatHeader();
        }

        if ($this->footer) {
            $interactive['footer'] = [
                'text' => $this->footer,
            ];
        }

        return [
            'type' => 'interactive',
            'interactive' => $interactive,
        ];
    }

    /**
     * Build single product message
     *
     * @return array<string, mixed> Message payload
     */
    private function buildProductMessage(): array
    {
        return [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'product',
                'body' => [
                    'text' => $this->body ?: 'Check out this product',
                ],
                'action' => [
                    'catalog_id' => get_option('wch_catalog_id'),
                    'product_retailer_id' => $this->products[0],
                ],
            ],
        ];
    }

    /**
     * Build product list message
     *
     * @return array<string, mixed> Message payload
     */
    private function buildProductListMessage(): array
    {
        return [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'product_list',
                'header' => [
                    'type' => 'text',
                    'text' => $this->header['content'] ?? 'Our Products',
                ],
                'body' => [
                    'text' => $this->body ?: 'Browse our catalog',
                ],
                'action' => [
                    'catalog_id' => get_option('wch_catalog_id'),
                    'sections' => [
                        [
                            'title' => 'Products',
                            'product_items' => array_map(fn($id) => [
                                'product_retailer_id' => $id,
                            ], $this->products),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Format header for API
     *
     * @return array<string, string> Formatted header
     */
    private function formatHeader(): array
    {
        if (!$this->header) {
            return [];
        }

        return match ($this->header['type']) {
            'text' => [
                'type' => 'text',
                'text' => $this->header['content'],
            ],
            'image' => [
                'type' => 'image',
                'image' => [
                    'link' => $this->header['content'],
                ],
            ],
            'video' => [
                'type' => 'video',
                'video' => [
                    'link' => $this->header['content'],
                ],
            ],
            'document' => [
                'type' => 'document',
                'document' => [
                    'link' => $this->header['content'],
                ],
            ],
            default => [],
        };
    }

    /**
     * Format buttons for API
     *
     * @return array<int, array<string, mixed>> Formatted buttons
     */
    private function formatButtons(): array
    {
        $formatted = [];

        foreach ($this->buttons as $button) {
            $formatted[] = match ($button['type']) {
                'reply' => [
                    'type' => 'reply',
                    'reply' => [
                        'id' => $button['data']['id'] ?? uniqid('btn_'),
                        'title' => $button['data']['title'],
                    ],
                ],
                'url' => [
                    'type' => 'url',
                    'url' => [
                        'text' => $button['data']['text'],
                        'url' => $button['data']['url'],
                    ],
                ],
                default => [],
            };
        }

        return $formatted;
    }

    /**
     * Validate message configuration
     *
     * @throws \InvalidArgumentException If validation fails
     */
    private function validate(): void
    {
        if ($this->type === 'text') {
            if (empty($this->textContent)) {
                throw new \InvalidArgumentException('Text content is required for text messages');
            }

            if (strlen($this->textContent) > self::MAX_TEXT_LENGTH) {
                throw new \InvalidArgumentException('Text content exceeds maximum length of ' . self::MAX_TEXT_LENGTH);
            }
        }

        if ($this->type === 'interactive' && empty($this->body)) {
            throw new \InvalidArgumentException('Body text is required for interactive messages');
        }

        if ($this->body && strlen($this->body) > self::MAX_BODY_LENGTH) {
            throw new \InvalidArgumentException('Body text exceeds maximum length of ' . self::MAX_BODY_LENGTH);
        }

        if ($this->footer && strlen($this->footer) > self::MAX_FOOTER_LENGTH) {
            throw new \InvalidArgumentException('Footer text exceeds maximum length of ' . self::MAX_FOOTER_LENGTH);
        }

        if (count($this->buttons) > self::MAX_BUTTONS) {
            throw new \InvalidArgumentException('Maximum of ' . self::MAX_BUTTONS . ' buttons allowed');
        }

        if ($this->type === 'list' && empty($this->sections)) {
            throw new \InvalidArgumentException('At least one section is required for list messages');
        }

        if (count($this->sections) > self::MAX_LIST_SECTIONS) {
            throw new \InvalidArgumentException('Maximum of ' . self::MAX_LIST_SECTIONS . ' sections allowed');
        }

        if (($this->type === 'product' || $this->type === 'product_list') && empty($this->products)) {
            throw new \InvalidArgumentException('At least one product is required for product messages');
        }
    }

    /**
     * Reset builder to initial state
     *
     * @return self For method chaining
     */
    public function reset(): self
    {
        $this->type = 'text';
        $this->textContent = '';
        $this->header = null;
        $this->body = '';
        $this->footer = '';
        $this->buttons = [];
        $this->sections = [];
        $this->products = [];

        return $this;
    }

    /**
     * Create new instance (factory method)
     *
     * @return self New message builder instance
     */
    public static function create(): self
    {
        return new self();
    }
}
