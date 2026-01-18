<?php
/**
 * PHPStan Rule: No WordPress Global Variables in Domain Layer
 *
 * Enforces architectural boundary - Domain layer must not access WordPress globals.
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
 * Rule to prevent WordPress global variable access in Domain layer.
 *
 * @implements Rule<Node\Expr\Variable>
 */
class NoWordPressGlobalsInDomainRule implements Rule {

	/**
	 * Forbidden WordPress global variables in Domain layer.
	 */
	private const FORBIDDEN_GLOBALS = [
		'wpdb',
		'wp_query',
		'wp_the_query',
		'post',
		'wp_rewrite',
		'wp',
	];

	/**
	 * Get node type this rule applies to.
	 *
	 * @return string
	 */
	public function getNodeType(): string {
		return Node\Expr\Variable::class;
	}

	/**
	 * Process node and return errors.
	 *
	 * @param Node\Expr\Variable $node  Variable node.
	 * @param Scope              $scope Analysis scope.
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	public function processNode( Node $node, Scope $scope ): array {
		// Only check files in includes/Domain/
		$file = $scope->getFile();
		if ( ! str_contains( $file, '/includes/Domain/' ) && ! str_contains( $file, '\\includes\\Domain\\' ) ) {
			return [];
		}

		// Get variable name
		if ( ! is_string( $node->name ) ) {
			return [];
		}

		$var_name = $node->name;

		// Check if this is a global access
		// Look for: global $wpdb; or just $wpdb in global scope
		if ( in_array( $var_name, self::FORBIDDEN_GLOBALS, true ) ) {
			// Check if we're in a global declaration or using it after global
			$parent = $scope->getParentScope();
			if ( $parent === null || $this->isGlobalAccess( $node, $scope ) ) {
				return [
					RuleErrorBuilder::message(
						sprintf(
							'Domain layer must not access WordPress global $%s. Move this code to Infrastructure layer or inject dependency.',
							$var_name
						)
					)->identifier( 'wch.domainPurity' )->build(),
				];
			}
		}

		return [];
	}

	/**
	 * Check if variable is being accessed as a global.
	 *
	 * @param Node\Expr\Variable $node  Variable node.
	 * @param Scope              $scope Analysis scope.
	 * @return bool
	 */
	private function isGlobalAccess( Node\Expr\Variable $node, Scope $scope ): bool {
		// If variable is used outside a class method/function, it could be global
		// Or if it's preceded by a global statement
		// For simplicity, we'll flag all uses in Domain layer
		return true;
	}
}
