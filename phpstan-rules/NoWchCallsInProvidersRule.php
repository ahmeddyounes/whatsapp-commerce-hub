<?php
/**
 * PHPStan Rule: No wch() Calls in Service Providers
 *
 * Enforces architectural boundary - Service providers must use injected $container exclusively.
 * This ensures providers are deterministic and testable.
 * See: .plans/C03-04.md
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
 * Rule to prevent wch() function calls in Service Providers.
 *
 * @implements Rule<Node\Expr\FuncCall>
 */
class NoWchCallsInProvidersRule implements Rule {

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
		// Only check files in includes/Providers/
		$file = $scope->getFile();
		if ( ! str_contains( $file, '/includes/Providers/' ) && ! str_contains( $file, '\\includes\\Providers\\' ) ) {
			return [];
		}

		// Get function name
		if ( ! $node->name instanceof Node\Name ) {
			return [];
		}

		$function_name = $node->name->toString();

		// Check if function is wch()
		if ( 'wch' === $function_name ) {
			return [
				RuleErrorBuilder::message(
					'Service providers must not call wch(). Use the injected $container parameter instead. This ensures providers are deterministic and testable.'
				)->identifier( 'wch.providerPurity' )->build(),
			];
		}

		return [];
	}
}
