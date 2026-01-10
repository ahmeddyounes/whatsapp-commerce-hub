<?php
/**
 * Deprecation Handler
 *
 * Handles deprecation warnings for legacy classes during PSR-4 migration.
 *
 * @package WhatsAppCommerceHub
 * @subpackage Core
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Core;

/**
 * Class Deprecation
 *
 * Provides utilities for handling deprecated classes and functions during migration.
 */
class Deprecation {
	/**
	 * Tracked deprecations
	 *
	 * @var array
	 */
	private static array $deprecations = array();

	/**
	 * Trigger a deprecation warning
	 *
	 * @param string $old     The deprecated class/function name.
	 * @param string $new     The replacement class/function name.
	 * @param string $version The version when deprecated.
	 * @return void
	 */
	public static function trigger( string $old, string $new, string $version ): void {
		// Log the deprecation.
		self::logDeprecation( $old, $new, $version );

		// Trigger user warning in debug mode.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Deprecation warning for developers only.
			trigger_error(
				sprintf(
					'%s is deprecated since version %s. Use %s instead.',
					$old,
					$version,
					$new
				),
				E_USER_DEPRECATED
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		// Fire action for external logging.
		do_action( 'wch_deprecation_triggered', $old, $new, $version );
	}

	/**
	 * Log deprecation usage
	 *
	 * @param string $old     The deprecated class/function name.
	 * @param string $new     The replacement class/function name.
	 * @param string $version The version when deprecated.
	 * @return void
	 */
	public static function logDeprecation( string $old, string $new, string $version ): void {
		$key = md5( $old );

		if ( ! isset( self::$deprecations[ $key ] ) ) {
			self::$deprecations[ $key ] = array(
				'old'     => $old,
				'new'     => $new,
				'version' => $version,
				'count'   => 0,
				'first'   => time(),
			);
		}

		++self::$deprecations[ $key ]['count'];
		self::$deprecations[ $key ]['last'] = time();

		// Store in WordPress option for persistence.
		update_option( 'wch_deprecations', self::$deprecations, false );
	}

	/**
	 * Get all tracked deprecations
	 *
	 * @return array Array of deprecation data.
	 */
	public static function getDeprecations(): array {
		if ( empty( self::$deprecations ) ) {
			self::$deprecations = get_option( 'wch_deprecations', array() );
		}

		return self::$deprecations;
	}

	/**
	 * Clear deprecation log
	 *
	 * @return void
	 */
	public static function clearDeprecations(): void {
		self::$deprecations = array();
		delete_option( 'wch_deprecations' );
	}

	/**
	 * Check if a class is deprecated
	 *
	 * @param string $class_name The class name to check.
	 * @return bool True if deprecated.
	 */
	public static function isDeprecated( string $class_name ): bool {
		$mapper = LegacyClassMapper::getMapping();
		return isset( $mapper[ $class_name ] );
	}

	/**
	 * Get deprecation notice for admin
	 *
	 * @return string HTML for admin notice.
	 */
	public static function getAdminNotice(): string {
		$deprecations = self::getDeprecations();

		if ( empty( $deprecations ) ) {
			return '';
		}

		$count = count( $deprecations );

		ob_start();
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php esc_html_e( 'WhatsApp Commerce Hub:', 'whatsapp-commerce-hub' ); ?></strong>
				<?php
				printf(
					/* translators: %d: number of deprecated classes */
					esc_html(
						_n(
							'%d deprecated class is being used.',
							'%d deprecated classes are being used.',
							$count,
							'whatsapp-commerce-hub'
						)
					),
					absint( $count )
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wch-deprecations' ) ); ?>">
					<?php esc_html_e( 'View details', 'whatsapp-commerce-hub' ); ?>
				</a>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Display admin notice
	 *
	 * @return void
	 */
	public static function displayAdminNotice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo self::getAdminNotice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
