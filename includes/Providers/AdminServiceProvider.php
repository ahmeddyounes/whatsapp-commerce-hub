<?php
/**
 * Admin Service Provider
 *
 * Registers admin pages and dashboard widgets.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Presentation\Admin\Pages\AnalyticsPage;
use WhatsAppCommerceHub\Presentation\Admin\Pages\CatalogSyncPage;
use WhatsAppCommerceHub\Presentation\Admin\Pages\DiagnosticsPage;
use WhatsAppCommerceHub\Presentation\Admin\Pages\InboxPage;
use WhatsAppCommerceHub\Presentation\Admin\Pages\JobsPage;
use WhatsAppCommerceHub\Presentation\Admin\Pages\LogsPage;
use WhatsAppCommerceHub\Presentation\Admin\Pages\TemplatesPage;
use WhatsAppCommerceHub\Presentation\Admin\Widgets\DashboardWidgets;
use WhatsAppCommerceHub\Container\ContainerInterface;
use WhatsAppCommerceHub\Container\ServiceProviderInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AdminServiceProvider
 *
 * Provides admin UI services including pages and dashboard widgets.
 */
class AdminServiceProvider extends AbstractServiceProvider {

	/**
	 * Register services.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		// Register Logs Page.
		$this->container->singleton(
			LogsPage::class,
			static fn() => new LogsPage()
		);

		// Register Jobs Page.
		$this->container->singleton(
			JobsPage::class,
			static fn() => new JobsPage()
		);

		// Register Inbox Page.
		$this->container->singleton(
			InboxPage::class,
			static fn() => new InboxPage()
		);

		// Register Analytics Page.
		$this->container->singleton(
			AnalyticsPage::class,
			static fn() => new AnalyticsPage()
		);

		// Register Templates Page.
		$this->container->singleton(
			TemplatesPage::class,
			static fn() => new TemplatesPage()
		);

		// Register Catalog Sync Page.
		$this->container->singleton(
			CatalogSyncPage::class,
			static fn() => new CatalogSyncPage()
		);

		// Register Diagnostics Page.
		$this->container->singleton(
			DiagnosticsPage::class,
			static fn() => new DiagnosticsPage()
		);

		// Register Dashboard Widgets.
		$this->container->singleton(
			DashboardWidgets::class,
			static fn() => new DashboardWidgets()
		);

		// Convenience aliases.
		$this->container->singleton(
			'wch.admin.logs',
			static fn( ContainerInterface $c ) => $c->get( LogsPage::class )
		);

		$this->container->singleton(
			'wch.admin.jobs',
			static fn( ContainerInterface $c ) => $c->get( JobsPage::class )
		);

		$this->container->singleton(
			'wch.admin.inbox',
			static fn( ContainerInterface $c ) => $c->get( InboxPage::class )
		);

		$this->container->singleton(
			'wch.admin.analytics',
			static fn( ContainerInterface $c ) => $c->get( AnalyticsPage::class )
		);

		$this->container->singleton(
			'wch.admin.templates',
			static fn( ContainerInterface $c ) => $c->get( TemplatesPage::class )
		);

		$this->container->singleton(
			'wch.admin.catalog_sync',
			static fn( ContainerInterface $c ) => $c->get( CatalogSyncPage::class )
		);

		$this->container->singleton(
			'wch.admin.diagnostics',
			static fn( ContainerInterface $c ) => $c->get( DiagnosticsPage::class )
		);

		$this->container->singleton(
			'wch.admin.dashboard_widgets',
			static fn( ContainerInterface $c ) => $c->get( DashboardWidgets::class )
		);
	}

	/**
	 * Boot services.
	 *
	 * @return void
	 */
	protected function doBoot(): void {
		// Initialize all admin pages.
		$this->container->get( LogsPage::class )->init();
		$this->container->get( JobsPage::class )->init();
		$this->container->get( InboxPage::class )->init();
		$this->container->get( AnalyticsPage::class )->init();
		$this->container->get( TemplatesPage::class )->init();
		$this->container->get( CatalogSyncPage::class )->init();
		$this->container->get( DiagnosticsPage::class )->init();
		$this->container->get( DashboardWidgets::class )->init();
	}

	/**
	 * Determine if this provider should boot in the current context.
	 *
	 * Admin pages should only boot in WordPress admin context.
	 *
	 * @return bool True if in admin context.
	 */
	public function shouldBoot(): bool {
		return $this->isAdmin();
	}

	/**
	 * Get the services provided by this provider.
	 *
	 * @return array<string>
	 */
	public function provides(): array {
		return [
			LogsPage::class,
			JobsPage::class,
			InboxPage::class,
			AnalyticsPage::class,
			TemplatesPage::class,
			CatalogSyncPage::class,
			DiagnosticsPage::class,
			DashboardWidgets::class,
			'wch.admin.logs',
			'wch.admin.jobs',
			'wch.admin.inbox',
			'wch.admin.analytics',
			'wch.admin.templates',
			'wch.admin.catalog_sync',
			'wch.admin.diagnostics',
			'wch.admin.dashboard_widgets',
		];
	}
}
