<?php
/**
 * Admin Diagnostics Page
 *
 * Provides admin UI for viewing container diagnostics including providers,
 * bindings, aliases, and boot status.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Presentation\Admin\Pages;

use WhatsAppCommerceHub\Container\ContainerInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Generic.Files.LineLength.MaxExceeded
// Long lines in inline CSS styles are acceptable for readability.

/**
 * Class DiagnosticsPage
 *
 * Handles the admin interface for viewing container diagnostics.
 */
class DiagnosticsPage {

	/**
	 * Page hook.
	 *
	 * @var string
	 */
	private string $pageHook = '';

	/**
	 * Initialize the admin diagnostics viewer.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'addMenuItem' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueScripts' ] );
	}

	/**
	 * Add menu item under WooCommerce.
	 *
	 * @return void
	 */
	public function addMenuItem(): void {
		$this->pageHook = add_submenu_page(
			'woocommerce',
			__( 'WCH Diagnostics', 'whatsapp-commerce-hub' ),
			__( 'WCH Diagnostics', 'whatsapp-commerce-hub' ),
			'manage_woocommerce',
			'wch-diagnostics',
			[ $this, 'renderPage' ]
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueueScripts( string $hook ): void {
		if ( 'woocommerce_page_wch-diagnostics' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wch-admin-diagnostics', false, [], WCH_VERSION );
		wp_add_inline_style( 'wch-admin-diagnostics', $this->getInlineStyles() );
	}

	/**
	 * Get inline CSS styles.
	 *
	 * @return string
	 */
	private function getInlineStyles(): string {
		return '
			.wch-diagnostics-page { margin: 20px 20px 20px 0; }
			.wch-diagnostics-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
			.wch-diagnostics-header h1 { margin: 0; }
			.wch-diagnostics-stats { display: flex; gap: 20px; margin-bottom: 20px; }
			.wch-diagnostics-stat { background: #fff; padding: 15px; border: 1px solid #ccd0d4; flex: 1; }
			.wch-diagnostics-stat-value { font-size: 24px; font-weight: 600; color: #2271b1; }
			.wch-diagnostics-stat-label { color: #666; font-size: 13px; }
			.wch-diagnostics-section { background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-bottom: 20px; }
			.wch-diagnostics-section h2 { margin-top: 0; font-size: 18px; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px; }
			.wch-diagnostics-table { width: 100%; border-collapse: collapse; }
			.wch-diagnostics-table th { text-align: left; padding: 10px; background: #f6f7f7; border-bottom: 2px solid #ccd0d4; font-weight: 600; }
			.wch-diagnostics-table td { padding: 10px; border-bottom: 1px solid #f0f0f1; vertical-align: top; }
			.wch-diagnostics-table tr:hover { background: #f6f7f7; }
			.wch-diagnostics-table code { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-size: 12px; }
			.wch-status-badge { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
			.wch-status-badge.booted { background: #00a32a; color: #fff; }
			.wch-status-badge.not-booted { background: #dba617; color: #fff; }
			.wch-status-badge.shared { background: #2271b1; color: #fff; }
			.wch-status-badge.not-shared { background: #646970; color: #fff; }
			.wch-status-badge.instantiated { background: #00a32a; color: #fff; }
			.wch-status-badge.alias { background: #8c8f94; color: #fff; }
			.wch-provider-name { font-weight: 600; color: #2271b1; }
			.wch-binding-abstract { font-family: monospace; font-size: 12px; word-break: break-all; }
			.wch-binding-concrete { font-family: monospace; font-size: 11px; color: #666; word-break: break-all; }
			.wch-no-data { padding: 40px; text-align: center; color: #666; }
			.wch-search-box { margin-bottom: 15px; }
			.wch-search-box input[type="text"] { width: 300px; padding: 6px 10px; }
		';
	}

	/**
	 * Render the diagnostics page.
	 *
	 * @return void
	 */
	public function renderPage(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'whatsapp-commerce-hub' ) );
		}

		// Only show in dev mode or if user has admin capability.
		if ( ! $this->isDevelopmentMode() && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Diagnostics are only available in development mode or for administrators.', 'whatsapp-commerce-hub' ) );
		}

		$container = wch_get_container();

		$providers = $container->getProviders();
		$bindings  = $container->getBindings();
		$instances = $container->getInstances();
		$isBooted  = $container->isBooted();

		// Analyze bindings to find aliases.
		$aliases         = $this->findAliases( $bindings );
		$regularBindings = $this->getRegularBindings( $bindings, $aliases );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search filter.
		$searchQuery = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';

		$this->renderPageHtml(
			$providers,
			$regularBindings,
			$aliases,
			$instances,
			$isBooted,
			$searchQuery
		);
	}

	/**
	 * Check if we're in development mode.
	 *
	 * @return bool
	 */
	private function isDevelopmentMode(): bool {
		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}

	/**
	 * Find aliases in bindings.
	 *
	 * An alias is a binding where the concrete is a string pointing to another binding.
	 *
	 * @param array<string, array{concrete: callable|string|null, shared: bool}> $bindings All bindings.
	 * @return array<string, string> Aliases map (alias => target).
	 */
	private function findAliases( array $bindings ): array {
		$aliases = [];

		foreach ( $bindings as $abstract => $binding ) {
			$concrete = $binding['concrete'];

			// If concrete is a string and not a callable, it's likely an alias.
			if ( is_string( $concrete ) && isset( $bindings[ $concrete ] ) ) {
				$aliases[ $abstract ] = $concrete;
			}
		}

		return $aliases;
	}

	/**
	 * Get regular bindings excluding aliases.
	 *
	 * @param array<string, array{concrete: callable|string|null, shared: bool}> $bindings All bindings.
	 * @param array<string, string>                                              $aliases  Aliases to exclude.
	 * @return array<string, array{concrete: callable|string|null, shared: bool}> Regular bindings.
	 */
	private function getRegularBindings( array $bindings, array $aliases ): array {
		return array_diff_key( $bindings, $aliases );
	}

	/**
	 * Render the page HTML.
	 *
	 * @param array<int, object>                                                 $providers        Providers.
	 * @param array<string, array{concrete: callable|string|null, shared: bool}> $bindings         Bindings.
	 * @param array<string, string>                                              $aliases          Aliases.
	 * @param array<string, mixed>                                               $instances        Instances.
	 * @param bool                                                               $isBooted         Boot status.
	 * @param string                                                             $searchQuery      Search query.
	 * @return void
	 */
	private function renderPageHtml(
		array $providers,
		array $bindings,
		array $aliases,
		array $instances,
		bool $isBooted,
		string $searchQuery
	): void {
		$totalProviders = count( $providers );
		$totalBindings  = count( $bindings );
		$totalAliases   = count( $aliases );
		$totalInstances = count( $instances );
		?>
		<div class="wrap wch-diagnostics-page">
			<div class="wch-diagnostics-header">
				<h1><?php esc_html_e( 'WhatsApp Commerce Hub - Container Diagnostics', 'whatsapp-commerce-hub' ); ?></h1>
				<span class="wch-status-badge <?php echo $isBooted ? 'booted' : 'not-booted'; ?>">
					<?php echo $isBooted ? esc_html__( 'Booted', 'whatsapp-commerce-hub' ) : esc_html__( 'Not Booted', 'whatsapp-commerce-hub' ); ?>
				</span>
			</div>

			<div class="wch-diagnostics-stats">
				<div class="wch-diagnostics-stat">
					<div class="wch-diagnostics-stat-value"><?php echo esc_html( $totalProviders ); ?></div>
					<div class="wch-diagnostics-stat-label"><?php esc_html_e( 'Service Providers', 'whatsapp-commerce-hub' ); ?></div>
				</div>
				<div class="wch-diagnostics-stat">
					<div class="wch-diagnostics-stat-value"><?php echo esc_html( $totalBindings ); ?></div>
					<div class="wch-diagnostics-stat-label"><?php esc_html_e( 'Bindings', 'whatsapp-commerce-hub' ); ?></div>
				</div>
				<div class="wch-diagnostics-stat">
					<div class="wch-diagnostics-stat-value"><?php echo esc_html( $totalAliases ); ?></div>
					<div class="wch-diagnostics-stat-label"><?php esc_html_e( 'Aliases', 'whatsapp-commerce-hub' ); ?></div>
				</div>
				<div class="wch-diagnostics-stat">
					<div class="wch-diagnostics-stat-value"><?php echo esc_html( $totalInstances ); ?></div>
					<div class="wch-diagnostics-stat-label"><?php esc_html_e( 'Instantiated', 'whatsapp-commerce-hub' ); ?></div>
				</div>
			</div>

			<!-- Search Box -->
			<div class="wch-diagnostics-section">
				<form method="get" action="">
					<input type="hidden" name="page" value="wch-diagnostics" />
					<div class="wch-search-box">
						<input
							type="text"
							name="search"
							placeholder="<?php esc_attr_e( 'Search bindings, aliases, providers...', 'whatsapp-commerce-hub' ); ?>"
							value="<?php echo esc_attr( $searchQuery ); ?>"
						/>
						<button type="submit" class="button"><?php esc_html_e( 'Search', 'whatsapp-commerce-hub' ); ?></button>
						<?php if ( ! empty( $searchQuery ) ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wch-diagnostics' ) ); ?>" class="button">
								<?php esc_html_e( 'Clear', 'whatsapp-commerce-hub' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</form>
			</div>

			<!-- Providers Section -->
			<div class="wch-diagnostics-section">
				<h2><?php esc_html_e( 'Service Providers', 'whatsapp-commerce-hub' ); ?></h2>
				<?php $this->renderProviders( $providers, $searchQuery ); ?>
			</div>

			<!-- Bindings Section -->
			<div class="wch-diagnostics-section">
				<h2><?php esc_html_e( 'Bindings', 'whatsapp-commerce-hub' ); ?></h2>
				<?php $this->renderBindings( $bindings, $instances, $searchQuery ); ?>
			</div>

			<!-- Aliases Section -->
			<div class="wch-diagnostics-section">
				<h2><?php esc_html_e( 'Aliases', 'whatsapp-commerce-hub' ); ?></h2>
				<?php $this->renderAliases( $aliases, $searchQuery ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render providers table.
	 *
	 * @param array<int, object> $providers   Providers.
	 * @param string             $searchQuery Search query.
	 * @return void
	 */
	private function renderProviders( array $providers, string $searchQuery ): void {
		if ( empty( $providers ) ) {
			?>
			<div class="wch-no-data"><?php esc_html_e( 'No service providers registered.', 'whatsapp-commerce-hub' ); ?></div>
			<?php
			return;
		}

		// Filter by search.
		if ( ! empty( $searchQuery ) ) {
			$providers = array_filter(
				$providers,
				static fn( $provider ): bool => str_contains( strtolower( get_class( $provider ) ), strtolower( $searchQuery ) )
			);
		}

		if ( empty( $providers ) ) {
			?>
			<div class="wch-no-data"><?php esc_html_e( 'No providers match the search query.', 'whatsapp-commerce-hub' ); ?></div>
			<?php
			return;
		}

		?>
		<table class="wch-diagnostics-table">
			<thead>
				<tr>
					<th><?php esc_html_e( '#', 'whatsapp-commerce-hub' ); ?></th>
					<th><?php esc_html_e( 'Provider', 'whatsapp-commerce-hub' ); ?></th>
					<th><?php esc_html_e( 'Services', 'whatsapp-commerce-hub' ); ?></th>
					<th><?php esc_html_e( 'Dependencies', 'whatsapp-commerce-hub' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$index = 1;
				foreach ( $providers as $provider ) :
					$className    = get_class( $provider );
					$services     = method_exists( $provider, 'provides' ) ? $provider->provides() : [];
					$dependencies = method_exists( $provider, 'dependsOn' ) ? $provider->dependsOn() : [];
					?>
					<tr>
						<td><?php echo esc_html( $index++ ); ?></td>
						<td>
							<div class="wch-provider-name"><?php echo esc_html( $this->getShortClassName( $className ) ); ?></div>
							<div class="wch-binding-concrete"><?php echo esc_html( $className ); ?></div>
						</td>
						<td>
							<?php if ( ! empty( $services ) ) : ?>
								<code><?php echo esc_html( count( $services ) ); ?> services</code>
							<?php else : ?>
								<span style="color: #999;">—</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( ! empty( $dependencies ) ) : ?>
								<?php foreach ( $dependencies as $dep ) : ?>
									<div class="wch-binding-concrete"><?php echo esc_html( $this->getShortClassName( $dep ) ); ?></div>
								<?php endforeach; ?>
							<?php else : ?>
								<span style="color: #999;">—</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render bindings table.
	 *
	 * @param array<string, array{concrete: callable|string|null, shared: bool}> $bindings    Bindings.
	 * @param array<string, mixed>                                               $instances   Instances.
	 * @param string                                                             $searchQuery Search query.
	 * @return void
	 */
	private function renderBindings( array $bindings, array $instances, string $searchQuery ): void {
		if ( empty( $bindings ) ) {
			?>
			<div class="wch-no-data"><?php esc_html_e( 'No bindings registered.', 'whatsapp-commerce-hub' ); ?></div>
			<?php
			return;
		}

		// Filter by search.
		if ( ! empty( $searchQuery ) ) {
			$bindings = array_filter(
				$bindings,
				static fn( $abstract ): bool => str_contains( strtolower( $abstract ), strtolower( $searchQuery ) ),
				ARRAY_FILTER_USE_KEY
			);
		}

		if ( empty( $bindings ) ) {
			?>
			<div class="wch-no-data"><?php esc_html_e( 'No bindings match the search query.', 'whatsapp-commerce-hub' ); ?></div>
			<?php
			return;
		}

		// Sort bindings alphabetically.
		ksort( $bindings );

		?>
		<table class="wch-diagnostics-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Abstract', 'whatsapp-commerce-hub' ); ?></th>
					<th><?php esc_html_e( 'Concrete', 'whatsapp-commerce-hub' ); ?></th>
					<th><?php esc_html_e( 'Status', 'whatsapp-commerce-hub' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $bindings as $abstract => $binding ) : ?>
					<?php
					$concrete        = $binding['concrete'];
					$isShared        = $binding['shared'];
					$isInstantiated  = isset( $instances[ $abstract ] );
					$concreteDisplay = is_callable( $concrete ) ? 'Closure' : ( is_string( $concrete ) ? $concrete : 'N/A' );
					?>
					<tr>
						<td>
							<div class="wch-binding-abstract"><?php echo esc_html( $abstract ); ?></div>
						</td>
						<td>
							<div class="wch-binding-concrete"><?php echo esc_html( $concreteDisplay ); ?></div>
						</td>
						<td>
							<?php if ( $isInstantiated ) : ?>
								<span class="wch-status-badge instantiated"><?php esc_html_e( 'Instantiated', 'whatsapp-commerce-hub' ); ?></span>
							<?php endif; ?>
							<?php if ( $isShared ) : ?>
								<span class="wch-status-badge shared"><?php esc_html_e( 'Shared', 'whatsapp-commerce-hub' ); ?></span>
							<?php else : ?>
								<span class="wch-status-badge not-shared"><?php esc_html_e( 'Not Shared', 'whatsapp-commerce-hub' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render aliases table.
	 *
	 * @param array<string, string> $aliases     Aliases.
	 * @param string                $searchQuery Search query.
	 * @return void
	 */
	private function renderAliases( array $aliases, string $searchQuery ): void {
		if ( empty( $aliases ) ) {
			?>
			<div class="wch-no-data"><?php esc_html_e( 'No aliases registered.', 'whatsapp-commerce-hub' ); ?></div>
			<?php
			return;
		}

		// Filter by search.
		if ( ! empty( $searchQuery ) ) {
			$aliases = array_filter(
				$aliases,
				static fn( $alias, $target ): bool => str_contains( strtolower( $alias ), strtolower( $searchQuery ) )
					|| str_contains( strtolower( $target ), strtolower( $searchQuery ) ),
				ARRAY_FILTER_USE_BOTH
			);
		}

		if ( empty( $aliases ) ) {
			?>
			<div class="wch-no-data"><?php esc_html_e( 'No aliases match the search query.', 'whatsapp-commerce-hub' ); ?></div>
			<?php
			return;
		}

		// Sort aliases alphabetically.
		ksort( $aliases );

		?>
		<table class="wch-diagnostics-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Alias', 'whatsapp-commerce-hub' ); ?></th>
					<th><?php esc_html_e( 'Target', 'whatsapp-commerce-hub' ); ?></th>
					<th><?php esc_html_e( 'Type', 'whatsapp-commerce-hub' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $aliases as $alias => $target ) : ?>
					<tr>
						<td>
							<div class="wch-binding-abstract"><?php echo esc_html( $alias ); ?></div>
						</td>
						<td>
							<div class="wch-binding-concrete"><?php echo esc_html( $target ); ?></div>
						</td>
						<td>
							<span class="wch-status-badge alias"><?php esc_html_e( 'Alias', 'whatsapp-commerce-hub' ); ?></span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Get short class name without namespace.
	 *
	 * @param string $className Full class name.
	 * @return string Short class name.
	 */
	private function getShortClassName( string $className ): string {
		$parts = explode( '\\', $className );
		return end( $parts );
	}
}
