<?php
/**
 * PHPStan Rule: No WordPress Functions in Domain Layer
 *
 * Enforces architectural boundary - Domain layer must not call WordPress/global functions.
 * See: .plans/C01-04.md
 *
 * @package WhatsApp_Commerce_Hub
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Rule to prevent WordPress function calls in Domain layer.
 *
 * @implements Rule<Node\Expr\FuncCall>
 */
class NoWordPressFunctionsInDomainRule implements Rule {

	/**
	 * Forbidden WordPress functions in Domain layer.
	 */
	private const FORBIDDEN_FUNCTIONS = [
		// WordPress hooks
		'do_action',
		'apply_filters',
		'add_action',
		'add_filter',
		'remove_action',
		'remove_filter',
		'did_action',
		'has_filter',
		// WordPress options
		'get_option',
		'update_option',
		'delete_option',
		'add_option',
		// WordPress post/meta functions
		'wp_insert_post',
		'wp_update_post',
		'get_post',
		'get_posts',
		'update_post_meta',
		'get_post_meta',
		'delete_post_meta',
		// WordPress data functions
		'wp_json_encode',
		'wp_json_decode',
		// WordPress time functions that depend on WP settings
		'current_time',
		// WordPress user functions
		'get_current_user_id',
		'wp_get_current_user',
		// WordPress query
		'get_posts',
		'get_post',
	];

	/**
	 * Get node type this rule applies to.
	 *
	 * @return string
	 */
	public function getNodeType(): string {
		return Node\Expr\FuncCall::class;
	}

	/**
	 * Process node and return errors.
	 *
	 * @param Node\Expr\FuncCall $node  Function call node.
	 * @param Scope              $scope Analysis scope.
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	public function processNode( Node $node, Scope $scope ): array {
		// Only check files in includes/Domain/
		$file = $scope->getFile();
		if ( ! str_contains( $file, '/includes/Domain/' ) && ! str_contains( $file, '\\includes\\Domain\\' ) ) {
			return [];
		}

		// Get function name
		if ( ! $node->name instanceof Node\Name ) {
			return [];
		}

		$function_name = $node->name->toString();

		// Check if function is forbidden
		if ( in_array( $function_name, self::FORBIDDEN_FUNCTIONS, true ) ) {
			return [
				RuleErrorBuilder::message(
					sprintf(
						'Domain layer must not call WordPress function %s(). Move this code to Infrastructure layer or inject dependency.',
						$function_name
					)
				)->identifier( 'wch.domainPurity' )->build(),
			];
		}

		return [];
	}
}
