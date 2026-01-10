<?php
/**
 * Compatibility Layer
 *
 * Provides backward compatibility utilities for legacy code during migration.
 *
 * @package WhatsAppCommerceHub
 * @subpackage Core
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Core;

/**
 * Class CompatibilityLayer
 *
 * Helps create backward compatibility wrappers for legacy classes.
 */
class CompatibilityLayer {
	/**
	 * Registered wrappers
	 *
	 * @var array
	 */
	private static array $wrappers = [];

	/**
	 * Create a legacy class wrapper
	 *
	 * Creates a simple class wrapper that proxies all method calls to the new class.
	 *
	 * @param string $old_class Legacy class name.
	 * @param string $new_class New PSR-4 class name.
	 * @param string $version   Version when deprecated.
	 * @return bool True if wrapper created successfully.
	 */
	public static function wrapLegacyClass( string $old_class, string $new_class, string $version = '2.0.0' ): bool {
		// Check if class already exists.
		if ( class_exists( $old_class ) || isset( self::$wrappers[ $old_class ] ) ) {
			return false;
		}

		// Check if new class exists.
		if ( ! class_exists( $new_class ) ) {
			return false;
		}

		// Create wrapper.
		$wrapper_code = self::generateWrapperCode( $old_class, $new_class, $version );

		// Evaluate wrapper code.
		eval( $wrapper_code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

		// Mark as wrapped.
		self::$wrappers[ $old_class ] = $new_class;

		return true;
	}

	/**
	 * Generate wrapper class code
	 *
	 * @param string $old_class Legacy class name.
	 * @param string $new_class New PSR-4 class name.
	 * @param string $version   Version when deprecated.
	 * @return string PHP code for wrapper class.
	 */
	private static function generateWrapperCode( string $old_class, string $new_class, string $version ): string {
		$code = <<<PHP
class {$old_class} {
	private \$instance;
	
	public function __construct( ...\$args ) {
		\WhatsAppCommerceHub\Core\Deprecation::trigger(
			'{$old_class}',
			'{$new_class}',
			'{$version}'
		);
		
		if ( function_exists( 'wch' ) ) {
			\$this->instance = wch( '{$new_class}' );
		} else {
			\$this->instance = new {$new_class}( ...\$args );
		}
	}
	
	public function __call( \$method, \$args ) {
		if ( method_exists( \$this->instance, \$method ) ) {
			return \$this->instance->\$method( ...\$args );
		}
		
		throw new \BadMethodCallException(
			sprintf( 'Method %s does not exist on %s', \$method, '{$new_class}' )
		);
	}
	
	public static function __callStatic( \$method, \$args ) {
		\WhatsAppCommerceHub\Core\Deprecation::trigger(
			'{$old_class}::{$method}',
			'{$new_class}::{$method}',
			'{$version}'
		);
		
		if ( method_exists( '{$new_class}', \$method ) ) {
			return forward_static_call_array( array( '{$new_class}', \$method ), \$args );
		}
		
		throw new \BadMethodCallException(
			sprintf( 'Static method %s does not exist on %s', \$method, '{$new_class}' )
		);
	}
	
	public function __get( \$property ) {
		return \$this->instance->\$property ?? null;
	}
	
	public function __set( \$property, \$value ) {
		\$this->instance->\$property = \$value;
	}
	
	public function __isset( \$property ) {
		return isset( \$this->instance->\$property );
	}
}
PHP;

		return $code;
	}

	/**
	 * Get all registered wrappers
	 *
	 * @return array Array of old => new class mappings.
	 */
	public static function getWrappers(): array {
		return self::$wrappers;
	}

	/**
	 * Check if a class has been wrapped
	 *
	 * @param string $class_name Class name to check.
	 * @return bool True if wrapped.
	 */
	public static function isWrapped( string $class_name ): bool {
		return isset( self::$wrappers[ $class_name ] );
	}

	/**
	 * Create instance singleton wrapper
	 *
	 * For legacy classes using getInstance() pattern.
	 *
	 * @param string $old_class Legacy class name.
	 * @param string $new_class New PSR-4 class name.
	 * @param string $version   Version when deprecated.
	 * @return bool True if wrapper created successfully.
	 */
	public static function wrapSingletonClass( string $old_class, string $new_class, string $version = '2.0.0' ): bool {
		// Check if class already exists.
		if ( class_exists( $old_class ) || isset( self::$wrappers[ $old_class ] ) ) {
			return false;
		}

		$wrapper_code = <<<PHP
class {$old_class} {
	private static \$instance = null;
	
	public static function getInstance() {
		\WhatsAppCommerceHub\Core\Deprecation::trigger(
			'{$old_class}::getInstance()',
			'wch( {$new_class}::class )',
			'{$version}'
		);
		
		if ( null === self::\$instance ) {
			if ( function_exists( 'wch' ) ) {
				self::\$instance = wch( '{$new_class}' );
			} else {
				self::\$instance = new {$new_class}();
			}
		}
		
		return self::\$instance;
	}
	
	public static function instance() {
		return self::getInstance();
	}
}
PHP;

		eval( $wrapper_code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		self::$wrappers[ $old_class ] = $new_class;

		return true;
	}

	/**
	 * Proxy function call to new function
	 *
	 * @param string $old_function Legacy function name.
	 * @param string $new_function New function name.
	 * @param array  $args         Function arguments.
	 * @param string $version      Version when deprecated.
	 * @return mixed Function result.
	 */
	public static function proxyFunction( string $old_function, string $new_function, array $args, string $version = '2.0.0' ) {
		Deprecation::trigger( $old_function . '()', $new_function . '()', $version );

		if ( function_exists( $new_function ) ) {
			return call_user_func_array( $new_function, $args );
		}

		throw new \BadFunctionCallException(
			sprintf( 'Function %s does not exist', $new_function )
		);
	}
}
