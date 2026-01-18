<?php
/**
 * Provider Sorter
 *
 * Topologically sorts service providers based on optional dependsOn() metadata.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Container;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProviderSorter {
	/**
	 * @param ServiceProviderInterface[] $providers
	 * @return ServiceProviderInterface[]
	 */
	public static function sort( array $providers ): array {
		$by_class = [];
		foreach ( $providers as $provider ) {
			$by_class[ $provider::class ] = $provider;
		}

		$sorted  = [];
		$visited = []; // class => 0|1|2 (unseen|visiting|done)
		$stack   = [];

		$visit = function ( string $class ) use ( &$visit, &$sorted, &$visited, &$stack, $by_class ) : void {
			$state = $visited[ $class ] ?? 0;
			if ( 2 === $state ) {
				return;
			}
			if ( 1 === $state ) {
				$cycle_start = array_search( $class, $stack, true );
				$cycle       = false !== $cycle_start ? array_slice( $stack, $cycle_start ) : $stack;
				$cycle[]     = $class;
				throw ContainerException::providerDependencyCycle( $cycle );
			}

			$visited[ $class ] = 1;
			$stack[]           = $class;

			$provider = $by_class[ $class ];
			$deps     = [];
			if ( $provider instanceof DependentServiceProviderInterface ) {
				$deps = $provider->dependsOn();
			} elseif ( method_exists( $provider, 'dependsOn' ) ) {
				// Backwards compatible: treat dependsOn() as optional metadata.
				$deps = (array) $provider->dependsOn();
			}

			foreach ( $deps as $dep_class ) {
				if ( ! isset( $by_class[ $dep_class ] ) ) {
					throw ContainerException::missingProviderDependency( $class, (string) $dep_class );
				}
				$visit( $dep_class );
			}

			array_pop( $stack );
			$visited[ $class ] = 2;
			$sorted[]          = $provider;
		};

		foreach ( $providers as $provider ) {
			$visit( $provider::class );
		}

		return $sorted;
	}
}
