<?php
/**
 * AI Assistant Class
 *
 * OpenAI integration for enhanced conversation handling with function calling.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_AI_Assistant
 *
 * Provides AI-powered conversation assistance using OpenAI with structured function calling,
 * rate limiting, cost tracking, and error handling.
 */
class WCH_AI_Assistant {
	/**
	 * API endpoint for OpenAI.
	 */
	const API_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

	/**
	 * Rate limit: max AI calls per conversation per hour.
	 */
	const RATE_LIMIT_CALLS_PER_HOUR = 10;

	/**
	 * Request timeout in seconds.
	 */
	const REQUEST_TIMEOUT = 15;

	/**
	 * OpenAI API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Model to use.
	 *
	 * @var string
	 */
	private $model;

	/**
	 * Temperature for response generation.
	 *
	 * @var float
	 */
	private $temperature;

	/**
	 * Maximum tokens for response.
	 *
	 * @var int
	 */
	private $max_tokens;

	/**
	 * System prompt for the assistant.
	 *
	 * @var string
	 */
	private $system_prompt;

	/**
	 * Settings instance.
	 *
	 * @var WCH_Settings
	 */
	private $settings;

	/**
	 * Available functions for OpenAI function calling.
	 *
	 * @var array
	 */
	private $functions = array();

	/**
	 * Constructor.
	 *
	 * @param array $config Configuration array with keys: api_key, model, temperature, max_tokens, system_prompt.
	 */
	public function __construct( $config = array() ) {
		$this->settings = WCH_Settings::getInstance();

		// Load config from settings or provided config.
		$this->api_key       = $config['api_key'] ?? $this->settings->get( 'ai.openai_api_key', '' );
		$this->model         = $config['model'] ?? $this->settings->get( 'ai.ai_model', 'gpt-4' );
		$this->temperature   = $config['temperature'] ?? $this->settings->get( 'ai.ai_temperature', 0.7 );
		$this->max_tokens    = $config['max_tokens'] ?? $this->settings->get( 'ai.ai_max_tokens', 500 );
		$this->system_prompt = $config['system_prompt'] ?? $this->settings->get( 'ai.ai_system_prompt', '' );

		// Initialize function definitions.
		$this->initialize_functions();
	}

	/**
	 * Initialize available functions for OpenAI function calling.
	 */
	private function initialize_functions() {
		$this->functions = array(
			array(
				'name'        => 'suggest_products',
				'description' => 'Search for and suggest products based on customer query',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array(
							'type'        => 'string',
							'description' => 'Search query for products',
						),
						'limit' => array(
							'type'        => 'integer',
							'description' => 'Maximum number of products to return',
							'default'     => 5,
						),
					),
					'required'   => array( 'query' ),
				),
			),
			array(
				'name'        => 'get_product_details',
				'description' => 'Get detailed information about a specific product',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array(
							'type'        => 'integer',
							'description' => 'The WooCommerce product ID',
						),
					),
					'required'   => array( 'product_id' ),
				),
			),
			array(
				'name'        => 'add_to_cart',
				'description' => 'Add a product to the customer\'s cart',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array(
							'type'        => 'integer',
							'description' => 'The WooCommerce product ID',
						),
						'quantity'   => array(
							'type'        => 'integer',
							'description' => 'Quantity to add',
							'default'     => 1,
						),
					),
					'required'   => array( 'product_id' ),
				),
			),
			array(
				'name'        => 'apply_coupon',
				'description' => 'Apply a coupon code to the customer\'s cart',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'code' => array(
							'type'        => 'string',
							'description' => 'The coupon code to apply',
						),
					),
					'required'   => array( 'code' ),
				),
			),
			array(
				'name'        => 'escalate_to_human',
				'description' => 'Escalate the conversation to a human agent',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'reason' => array(
							'type'        => 'string',
							'description' => 'Reason for escalation',
						),
					),
					'required'   => array( 'reason' ),
				),
			),
		);

		/**
		 * Filter available AI functions.
		 *
		 * @param array $functions Array of function definitions.
		 */
		$this->functions = apply_filters( 'wch_ai_functions', $this->functions );
	}

	/**
	 * Generate AI response for a user message.
	 *
	 * @param string $user_message User's message.
	 * @param array  $context Conversation context including state, customer profile, cart, etc.
	 * @return array Response data with keys: text, actions, error.
	 */
	public function generate_response( $user_message, $context = array() ) {
		// Check if AI is enabled.
		if ( ! $this->settings->get( 'ai.enable_ai', false ) ) {
			return array(
				'text'    => '',
				'actions' => array(),
				'error'   => 'AI assistant is not enabled',
			);
		}

		// Validate API key.
		if ( empty( $this->api_key ) ) {
			WCH_Logger::error( 'AI assistant called without API key configured' );
			return array(
				'text'    => '',
				'actions' => array(),
				'error'   => 'AI assistant is not configured',
			);
		}

		// Check rate limit (uses persistent storage).
		$conversation_id = $context['conversation_id'] ?? null;
		if ( $conversation_id && ! $this->check_rate_limit( $conversation_id ) ) {
			WCH_Logger::warning(
				'AI rate limit exceeded',
				array( 'conversation_id' => $conversation_id )
			);
			return array(
				'text'    => '',
				'actions' => array(),
				'error'   => 'Rate limit exceeded',
			);
		}

		try {
			// SECURITY: Sanitize user input to prevent prompt injection attacks.
			$sanitized_message = $this->sanitize_user_input( $user_message );

			// Build system prompt dynamically with injection protection.
			$system_prompt = $this->build_system_prompt( $context );

			// Build messages array with clear boundary markers.
			// The system prompt now includes injection-resistant instructions.
			$messages = array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
				array(
					'role'    => 'user',
					'content' => $sanitized_message,
				),
			);

			// Make API request.
			$api_response = $this->call_openai_api( $messages );

			if ( is_wp_error( $api_response ) ) {
				WCH_Logger::error(
					'OpenAI API error',
					array(
						'error'           => $api_response->get_error_message(),
						'conversation_id' => $conversation_id,
					)
				);
				return array(
					'text'    => '',
					'actions' => array(),
					'error'   => $api_response->get_error_message(),
				);
			}

			// Track token usage and cost.
			$this->track_usage( $conversation_id, $api_response );

			// Process response.
			$result = $this->process_api_response( $api_response, $context );

			// Increment rate limit counter.
			if ( $conversation_id ) {
				$this->increment_rate_limit( $conversation_id );
			}

			return $result;

		} catch ( Exception $e ) {
			WCH_Logger::error(
				'AI assistant exception',
				array(
					'error'           => $e->getMessage(),
					'conversation_id' => $conversation_id,
				)
			);
			return array(
				'text'    => '',
				'actions' => array(),
				'error'   => 'An error occurred while processing your request',
			);
		}
	}

	/**
	 * Build system prompt dynamically based on context.
	 *
	 * @param array $context Conversation context.
	 * @return string System prompt.
	 */
	private function build_system_prompt( $context ) {
		// Start with base system prompt from settings.
		$prompt = $this->system_prompt;

		if ( empty( $prompt ) ) {
			$prompt = 'You are a helpful customer service assistant for an e-commerce store.';
		}

		// Add business identity.
		$business_name = $this->settings->get( 'general.business_name', get_bloginfo( 'name' ) );
		$prompt       .= "\n\nBusiness: {$business_name}";

		// Add tone guidelines.
		$prompt .= "\n\nTone Guidelines: Be friendly, professional, and concise. Use emojis sparingly.";

		// Add product information.
		$product_summary = $this->get_products_summary();
		if ( ! empty( $product_summary ) ) {
			$prompt .= "\n\nAvailable Products:\n{$product_summary}";
		}

		// Add current conversation state.
		if ( ! empty( $context['current_state'] ) ) {
			$state   = $context['current_state'];
			$prompt .= "\n\nCurrent State: {$state}";

			// State-specific instructions.
			switch ( $state ) {
				case 'BROWSING':
					$prompt .= "\nHelp the customer find products they're looking for. Suggest products based on their interests.";
					break;
				case 'VIEWING_PRODUCT':
					$prompt .= "\nProvide detailed information about products. Help answer questions about features, pricing, and availability.";
					break;
				case 'CART_MANAGEMENT':
					$prompt .= "\nHelp the customer review their cart, answer questions, and guide them toward checkout.";
					break;
				case 'CHECKOUT_ADDRESS':
					$prompt .= "\nAssist with collecting accurate shipping address information.";
					break;
				case 'CHECKOUT_PAYMENT':
					$prompt .= "\nHelp select payment method and answer payment-related questions.";
					break;
			}
		}

		// Add customer profile if known.
		if ( ! empty( $context['customer_name'] ) ) {
			$prompt .= "\n\nCustomer Name: {$context['customer_name']}";
		}

		if ( ! empty( $context['previous_orders'] ) ) {
			$order_count = count( $context['previous_orders'] );
			$prompt     .= "\n\nThis customer has {$order_count} previous orders.";
		}

		// Add cart information.
		if ( ! empty( $context['cart_items'] ) ) {
			$cart_count = count( $context['cart_items'] );
			$prompt    .= "\n\nCurrent cart has {$cart_count} items.";
		}

		/**
		 * Filter the system prompt before sending to OpenAI.
		 *
		 * @param string $prompt Built system prompt.
		 * @param array  $context Conversation context.
		 */
		return apply_filters( 'wch_ai_system_prompt', $prompt, $context );
	}

	/**
	 * Get summary of available products.
	 *
	 * @return string Product summary.
	 */
	private function get_products_summary() {
		$summary = '';

		// Get top categories.
		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => 5,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);

		if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
			$cat_names = array_map(
				function ( $cat ) {
					return $cat->name;
				},
				$categories
			);
			$summary  .= 'Top Categories: ' . implode( ', ', $cat_names );
		}

		// Get current promotions/sale products.
		$sale_products = wc_get_product_ids_on_sale();
		if ( ! empty( $sale_products ) && count( $sale_products ) > 0 ) {
			$summary .= "\nCurrent Promotions: We have " . count( $sale_products ) . ' products on sale.';
		}

		return $summary;
	}

	/**
	 * Call OpenAI API.
	 *
	 * @param array $messages Messages array for chat completion.
	 * @return array|WP_Error Response data or WP_Error.
	 */
	private function call_openai_api( $messages ) {
		$body = array(
			'model'       => $this->model,
			'messages'    => $messages,
			'temperature' => $this->temperature,
			'max_tokens'  => $this->max_tokens,
			'functions'   => $this->functions,
		);

		$response = wp_remote_post(
			self::API_ENDPOINT,
			array(
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		// Handle errors.
		if ( is_wp_error( $response ) ) {
			// Retry once on 5xx errors.
			$error_message = $response->get_error_message();
			if ( strpos( $error_message, '5' ) === 0 ) {
				sleep( 1 );
				$response = wp_remote_post(
					self::API_ENDPOINT,
					array(
						'timeout' => self::REQUEST_TIMEOUT,
						'headers' => array(
							'Content-Type'  => 'application/json',
							'Authorization' => 'Bearer ' . $this->api_key,
						),
						'body'    => wp_json_encode( $body ),
					)
				);
			}

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code >= 500 ) {
			// Retry once on 5xx errors.
			sleep( 1 );
			$response = wp_remote_post(
				self::API_ENDPOINT,
				array(
					'timeout' => self::REQUEST_TIMEOUT,
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $this->api_key,
					),
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
		}

		if ( $status_code !== 200 ) {
			$body = wp_remote_retrieve_body( $response );
			return new WP_Error( 'api_error', 'OpenAI API error: ' . $status_code . ' - ' . $body );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! isset( $data['choices'][0] ) ) {
			return new WP_Error( 'invalid_response', 'Invalid OpenAI API response' );
		}

		return $data;
	}

	/**
	 * Process API response and extract text and function calls.
	 *
	 * @param array $api_response OpenAI API response.
	 * @param array $context Conversation context.
	 * @return array Processed response with text and actions.
	 */
	private function process_api_response( $api_response, $context ) {
		$choice  = $api_response['choices'][0];
		$message = $choice['message'];

		$result = array(
			'text'    => '',
			'actions' => array(),
			'error'   => null,
		);

		// Check for function call.
		if ( isset( $message['function_call'] ) ) {
			$function_call = $message['function_call'];
			$function_name = $function_call['name'];
			$arguments     = json_decode( $function_call['arguments'], true );

			// Process the function call.
			$function_result = $this->process_function_call( $function_name, $arguments, $context );

			// Store the action.
			$result['actions'][] = array(
				'function' => $function_name,
				'args'     => $arguments,
				'result'   => $function_result,
			);

			// If function returns text, use it as the response.
			if ( isset( $function_result['text'] ) ) {
				$result['text'] = $function_result['text'];
			}
		} elseif ( isset( $message['content'] ) ) {
			// Regular text response.
			$result['text'] = $message['content'];

			// Apply content filtering.
			if ( ! $this->is_content_safe( $result['text'] ) ) {
				WCH_Logger::warning(
					'AI response failed content filter',
					array( 'response' => $result['text'] )
				);
				$result['text'] = 'I apologize, but I cannot provide that response. How else can I help you?';
			}
		}

		return $result;
	}

	/**
	 * Sanitize user input to prevent prompt injection attacks.
	 *
	 * Removes or neutralizes common injection patterns while preserving
	 * legitimate user intent.
	 *
	 * @param string $input Raw user input.
	 * @return string Sanitized input.
	 */
	private function sanitize_user_input( $input ) {
		// Normalize whitespace and trim.
		$sanitized = trim( preg_replace( '/\s+/', ' ', $input ) );

		// Remove or escape potential injection patterns.
		$injection_patterns = array(
			// Instruction override attempts.
			'/ignore\s+(all\s+)?(previous|above|prior)\s+(instructions?|prompts?|rules?)/i',
			'/forget\s+(everything|all|your)\s+(you\s+)?(know|were\s+told)/i',
			'/you\s+are\s+now\s+(a\s+)?different/i',
			'/pretend\s+(to\s+be|you\s+are)/i',
			'/act\s+as\s+(if\s+)?you/i',
			'/your\s+new\s+(instructions?|role|persona)/i',
			// System prompt extraction attempts.
			'/repeat\s+(your\s+)?(system\s+)?prompt/i',
			'/show\s+(me\s+)?(your\s+)?instructions/i',
			'/what\s+(are\s+)?your\s+(system\s+)?instructions/i',
			'/output\s+(your\s+)?initialization/i',
			// Role playing escalation.
			'/\[system\]/i',
			'/\[assistant\]/i',
			'/\[developer\]/i',
			'/developer\s+mode/i',
			'/jailbreak/i',
		);

		foreach ( $injection_patterns as $pattern ) {
			if ( preg_match( $pattern, $sanitized ) ) {
				WCH_Logger::warning(
					'Potential prompt injection detected',
					array(
						'pattern' => $pattern,
						'input'   => substr( $sanitized, 0, 100 ) . '...',
					)
				);

				// Replace the malicious pattern with a safe placeholder.
				$sanitized = preg_replace( $pattern, '[FILTERED]', $sanitized );
			}
		}

		// Escape any remaining special sequences that could be interpreted as commands.
		$sanitized = str_replace(
			array( '```', '<<<', '>>>' ),
			array( '` ` `', '< < <', '> > >' ),
			$sanitized
		);

		// Limit length to prevent context window abuse.
		$max_length = 2000;
		if ( mb_strlen( $sanitized ) > $max_length ) {
			$sanitized = mb_substr( $sanitized, 0, $max_length ) . '...';
		}

		return $sanitized;
	}

	/**
	 * Process a function call.
	 *
	 * SECURITY: Validates authorization before executing sensitive functions.
	 *
	 * @param string $function_name Function name.
	 * @param array  $arguments Function arguments.
	 * @param array  $context Conversation context.
	 * @return array Function result.
	 */
	public function process_function_call( $function_name, $arguments, $context = array() ) {
		// SECURITY: Validate function is in the allowed list.
		$allowed_functions = array(
			'suggest_products',
			'get_product_details',
			'add_to_cart',
			'apply_coupon',
			'escalate_to_human',
		);

		if ( ! in_array( $function_name, $allowed_functions, true ) ) {
			WCH_Logger::warning(
				'Unauthorized AI function call attempted',
				array(
					'function'  => $function_name,
					'context'   => $context['conversation_id'] ?? 'unknown',
				)
			);

			return array(
				'success' => false,
				'error'   => 'Function not authorized',
			);
		}

		// SECURITY: Validate context for functions that modify state.
		$state_modifying_functions = array( 'add_to_cart', 'apply_coupon' );
		if ( in_array( $function_name, $state_modifying_functions, true ) ) {
			// Require customer phone for cart operations.
			if ( empty( $context['customer_phone'] ) ) {
				WCH_Logger::warning(
					'AI function call missing customer context',
					array(
						'function' => $function_name,
					)
				);

				return array(
					'success' => false,
					'error'   => 'Customer context required for this operation',
				);
			}
		}

		WCH_Logger::info(
			'Processing AI function call',
			array(
				'function'  => $function_name,
				'arguments' => $arguments,
			)
		);

		$result = array();

		switch ( $function_name ) {
			case 'suggest_products':
				$result = $this->function_suggest_products( $arguments );
				break;

			case 'get_product_details':
				$result = $this->function_get_product_details( $arguments );
				break;

			case 'add_to_cart':
				$result = $this->function_add_to_cart( $arguments, $context );
				break;

			case 'apply_coupon':
				$result = $this->function_apply_coupon( $arguments, $context );
				break;

			case 'escalate_to_human':
				$result = $this->function_escalate_to_human( $arguments, $context );
				break;

			default:
				$result = array(
					'success' => false,
					'error'   => 'Unknown function: ' . $function_name,
				);
				break;
		}

		/**
		 * Filter function call result.
		 *
		 * @param array  $result Function result.
		 * @param string $function_name Function name.
		 * @param array  $arguments Function arguments.
		 * @param array  $context Conversation context.
		 */
		return apply_filters( 'wch_ai_function_result', $result, $function_name, $arguments, $context );
	}

	/**
	 * Function: Suggest products based on query.
	 *
	 * @param array $args Arguments with 'query' and optional 'limit'.
	 * @return array Result with products.
	 */
	private function function_suggest_products( $args ) {
		$query = $args['query'] ?? '';
		$limit = $args['limit'] ?? 5;

		$products = wc_get_products(
			array(
				's'      => $query,
				'limit'  => $limit,
				'status' => 'publish',
			)
		);

		$product_list = array();
		foreach ( $products as $product ) {
			$product_list[] = array(
				'id'    => $product->get_id(),
				'name'  => $product->get_name(),
				'price' => $product->get_price(),
			);
		}

		$text = '';
		if ( empty( $product_list ) ) {
			$text = "I couldn't find any products matching '{$query}'. Would you like to browse our categories?";
		} else {
			$text = "Here are some products I found:\n\n";
			foreach ( $product_list as $p ) {
				$text .= "- {$p['name']} - " . wc_price( $p['price'] ) . "\n";
			}
		}

		return array(
			'success'  => true,
			'products' => $product_list,
			'text'     => $text,
		);
	}

	/**
	 * Function: Get product details.
	 *
	 * @param array $args Arguments with 'product_id'.
	 * @return array Result with product details.
	 */
	private function function_get_product_details( $args ) {
		$product_id = $args['product_id'] ?? 0;
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			return array(
				'success' => false,
				'error'   => 'Product not found',
				'text'    => 'Sorry, I could not find that product.',
			);
		}

		$details = array(
			'id'          => $product->get_id(),
			'name'        => $product->get_name(),
			'price'       => $product->get_price(),
			'description' => $product->get_short_description(),
			'in_stock'    => $product->is_in_stock(),
		);

		$text  = "{$details['name']}\n";
		$text .= 'Price: ' . wc_price( $details['price'] ) . "\n";
		if ( ! empty( $details['description'] ) ) {
			$text .= "\n" . wp_strip_all_tags( $details['description'] );
		}
		$text .= "\n\nStock: " . ( $details['in_stock'] ? 'In Stock' : 'Out of Stock' );

		return array(
			'success' => true,
			'product' => $details,
			'text'    => $text,
		);
	}

	/**
	 * Function: Add product to cart.
	 *
	 * @param array $args Arguments with 'product_id' and optional 'quantity'.
	 * @param array $context Conversation context.
	 * @return array Result.
	 */
	private function function_add_to_cart( $args, $context ) {
		$product_id = $args['product_id'] ?? 0;
		$quantity   = $args['quantity'] ?? 1;

		// This would typically trigger a flow action.
		// For now, return success message.
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array(
				'success' => false,
				'error'   => 'Product not found',
				'text'    => 'Sorry, I could not add that product to your cart.',
			);
		}

		return array(
			'success'    => true,
			'product_id' => $product_id,
			'quantity'   => $quantity,
			'text'       => "I'll add {$quantity} x {$product->get_name()} to your cart.",
			'action'     => 'ADD_TO_CART',
		);
	}

	/**
	 * Function: Apply coupon code.
	 *
	 * @param array $args Arguments with 'code'.
	 * @param array $context Conversation context.
	 * @return array Result.
	 */
	private function function_apply_coupon( $args, $context ) {
		$code = $args['code'] ?? '';

		if ( empty( $code ) ) {
			return array(
				'success' => false,
				'error'   => 'No coupon code provided',
				'text'    => 'Please provide a coupon code.',
			);
		}

		// Validate coupon exists.
		$coupon = new WC_Coupon( $code );
		if ( ! $coupon->get_id() ) {
			return array(
				'success' => false,
				'error'   => 'Invalid coupon',
				'text'    => "Sorry, the coupon code '{$code}' is not valid.",
			);
		}

		return array(
			'success' => true,
			'code'    => $code,
			'text'    => "I'll apply the coupon code '{$code}' to your order.",
			'action'  => 'APPLY_COUPON',
		);
	}

	/**
	 * Function: Escalate to human agent.
	 *
	 * @param array $args Arguments with 'reason'.
	 * @param array $context Conversation context.
	 * @return array Result.
	 */
	private function function_escalate_to_human( $args, $context ) {
		$reason = $args['reason'] ?? 'Customer request';

		WCH_Logger::info(
			'AI escalating to human',
			array(
				'reason'          => $reason,
				'conversation_id' => $context['conversation_id'] ?? null,
			)
		);

		return array(
			'success' => true,
			'reason'  => $reason,
			'text'    => "I understand you'd like to speak with someone. Let me connect you to a human agent.",
			'action'  => 'REQUEST_HUMAN',
		);
	}

	/**
	 * Check if content is safe (basic content filtering).
	 *
	 * @param string $text Text to check.
	 * @return bool True if safe, false otherwise.
	 */
	private function is_content_safe( $text ) {
		// Basic content filtering - check for inappropriate content.
		$forbidden_patterns = array(
			'/\b(hack|crack|pirate|steal)\b/i',
			'/\b(password|credit card|ssn)\b/i',
		);

		foreach ( $forbidden_patterns as $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return false;
			}
		}

		/**
		 * Filter content safety check.
		 *
		 * @param bool   $is_safe Whether content is safe.
		 * @param string $text Text being checked.
		 */
		return apply_filters( 'wch_ai_content_safe', true, $text );
	}

	/**
	 * Check rate limit for conversation.
	 *
	 * Uses database-backed transients for persistent rate limiting.
	 * wp_cache is not persistent by default and can be easily bypassed.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return bool True if within limit, false if exceeded.
	 */
	private function check_rate_limit( $conversation_id ) {
		global $wpdb;

		// Use database-backed rate limiting for persistence.
		$rate_table = $wpdb->prefix . 'wch_rate_limits';

		// Check if rate_limits table exists, fall back to transients if not.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $rate_table )
		);

		if ( $table_exists ) {
			// Database-backed rate limiting (most secure).
			$window_start = gmdate( 'Y-m-d H:00:00' ); // Current hour window.
			$identifier   = 'ai_' . $conversation_id;

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$calls = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT request_count FROM $rate_table
					WHERE identifier_hash = %s AND limit_type = %s AND window_start = %s",
					hash( 'sha256', $identifier ),
					'ai_calls',
					$window_start
				)
			);

			return $calls < self::RATE_LIMIT_CALLS_PER_HOUR;
		}

		// Fallback to transients (persistent, but not as robust).
		$transient_key = 'wch_ai_rate_' . $conversation_id;
		$calls         = get_transient( $transient_key );

		if ( false === $calls ) {
			return true; // No calls yet.
		}

		return (int) $calls < self::RATE_LIMIT_CALLS_PER_HOUR;
	}

	/**
	 * Increment rate limit counter.
	 *
	 * Uses database-backed storage for persistence.
	 *
	 * @param int $conversation_id Conversation ID.
	 */
	private function increment_rate_limit( $conversation_id ) {
		global $wpdb;

		$rate_table = $wpdb->prefix . 'wch_rate_limits';

		// Check if rate_limits table exists.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $rate_table )
		);

		if ( $table_exists ) {
			// Database-backed rate limiting with atomic increment.
			$window_start   = gmdate( 'Y-m-d H:00:00' );
			$identifier     = 'ai_' . $conversation_id;
			$identifier_hash = hash( 'sha256', $identifier );

			// Use INSERT ... ON DUPLICATE KEY UPDATE for atomic operation.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO $rate_table (identifier_hash, limit_type, request_count, window_start, created_at)
					VALUES (%s, %s, 1, %s, %s)
					ON DUPLICATE KEY UPDATE request_count = request_count + 1",
					$identifier_hash,
					'ai_calls',
					$window_start,
					current_time( 'mysql', true )
				)
			);
			return;
		}

		// Fallback to transients.
		$transient_key = 'wch_ai_rate_' . $conversation_id;
		$calls         = get_transient( $transient_key );

		if ( false === $calls ) {
			$calls = 0;
		}

		++$calls;
		set_transient( $transient_key, $calls, HOUR_IN_SECONDS );
	}

	/**
	 * Track token usage and cost.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $api_response API response data.
	 */
	private function track_usage( $conversation_id, $api_response ) {
		if ( ! isset( $api_response['usage'] ) ) {
			return;
		}

		$usage             = $api_response['usage'];
		$prompt_tokens     = $usage['prompt_tokens'] ?? 0;
		$completion_tokens = $usage['completion_tokens'] ?? 0;
		$total_tokens      = $usage['total_tokens'] ?? 0;

		// Calculate estimated cost (GPT-4 pricing as example).
		// Input: $0.03 per 1K tokens, Output: $0.06 per 1K tokens.
		$estimated_cost = ( $prompt_tokens * 0.03 / 1000 ) + ( $completion_tokens * 0.06 / 1000 );

		// Log the usage.
		WCH_Logger::info(
			'AI token usage',
			array(
				'conversation_id'   => $conversation_id,
				'prompt_tokens'     => $prompt_tokens,
				'completion_tokens' => $completion_tokens,
				'total_tokens'      => $total_tokens,
				'estimated_cost'    => $estimated_cost,
				'model'             => $this->model,
			)
		);

		// Track monthly usage.
		$this->track_monthly_usage( $total_tokens, $estimated_cost );
	}

	/**
	 * Track monthly usage and check budget.
	 *
	 * @param int   $tokens Tokens used.
	 * @param float $cost Estimated cost.
	 */
	private function track_monthly_usage( $tokens, $cost ) {
		$current_month = gmdate( 'Y-m' );
		$usage_key     = 'wch_ai_usage_' . $current_month;

		$usage = get_option(
			$usage_key,
			array(
				'tokens' => 0,
				'cost'   => 0.0,
				'calls'  => 0,
			)
		);

		$usage['tokens'] += $tokens;
		$usage['cost']   += $cost;
		++$usage['calls'];

		update_option( $usage_key, $usage );

		// Check budget cap.
		$monthly_budget = $this->settings->get( 'ai.monthly_budget_cap', 0 );
		if ( $monthly_budget > 0 ) {
			$usage_percent = ( $usage['cost'] / $monthly_budget ) * 100;

			// Alert at 80%.
			if ( $usage_percent >= 80 && $usage_percent < 100 ) {
				WCH_Logger::warning(
					'AI budget at 80%',
					array(
						'usage'  => $usage['cost'],
						'budget' => $monthly_budget,
					)
				);
			}

			// Alert at 100%.
			if ( $usage_percent >= 100 ) {
				WCH_Logger::critical(
					'AI budget exceeded',
					array(
						'usage'  => $usage['cost'],
						'budget' => $monthly_budget,
					)
				);
			}
		}
	}

	/**
	 * Get current month usage statistics.
	 *
	 * @return array Usage statistics.
	 */
	public function get_monthly_usage() {
		$current_month = gmdate( 'Y-m' );
		$usage_key     = 'wch_ai_usage_' . $current_month;

		return get_option(
			$usage_key,
			array(
				'tokens' => 0,
				'cost'   => 0.0,
				'calls'  => 0,
			)
		);
	}
}
