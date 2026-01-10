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
class AdminServiceProvider implements ServiceProviderInterface {

	/**
	 * Register services.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @return void
	 */
	public function register( ContainerInterface $container ): void {
		// Register Logs Page.
		$container->singleton(
			LogsPage::class,
			static fn() => new LogsPage()
		);

		// Register Jobs Page.
		$container->singleton(
			JobsPage::class,
			static fn() => new JobsPage()
		);

		// Register Inbox Page.
		$container->singleton(
			InboxPage::class,
			static fn() => new InboxPage()
		);

		// Register Analytics Page.
		$container->singleton(
			AnalyticsPage::class,
			static fn() => new AnalyticsPage()
		);

		// Register Templates Page.
		$container->singleton(
			TemplatesPage::class,
			static fn() => new TemplatesPage()
		);

		// Register Catalog Sync Page.
		$container->singleton(
			CatalogSyncPage::class,
			static fn() => new CatalogSyncPage()
		);

		// Register Dashboard Widgets.
		$container->singleton(
			DashboardWidgets::class,
			static fn() => new DashboardWidgets()
		);

		// Convenience aliases.
		$container->singleton(
			'wch.admin.logs',
			static fn( ContainerInterface $c ) => $c->get( LogsPage::class )
		);

		$container->singleton(
			'wch.admin.jobs',
			static fn( ContainerInterface $c ) => $c->get( JobsPage::class )
		);

		$container->singleton(
			'wch.admin.inbox',
			static fn( ContainerInterface $c ) => $c->get( InboxPage::class )
		);

		$container->singleton(
			'wch.admin.analytics',
			static fn( ContainerInterface $c ) => $c->get( AnalyticsPage::class )
		);

		$container->singleton(
			'wch.admin.templates',
			static fn( ContainerInterface $c ) => $c->get( TemplatesPage::class )
		);

		$container->singleton(
			'wch.admin.catalog_sync',
			static fn( ContainerInterface $c ) => $c->get( CatalogSyncPage::class )
		);

		$container->singleton(
			'wch.admin.dashboard_widgets',
			static fn( ContainerInterface $c ) => $c->get( DashboardWidgets::class )
		);
	}

	/**
	 * Boot services.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @return void
	 */
	public function boot( ContainerInterface $container ): void {
		// Only initialize admin pages in the admin context.
		if ( ! is_admin() ) {
			return;
		}

		// Initialize all admin pages.
		$container->get( LogsPage::class )->init();
		$container->get( JobsPage::class )->init();
		$container->get( InboxPage::class )->init();
		$container->get( AnalyticsPage::class )->init();
		$container->get( TemplatesPage::class )->init();
		$container->get( CatalogSyncPage::class )->init();
		$container->get( DashboardWidgets::class )->init();
	}

	/**
	 * Get the services provided by this provider.
	 *
	 * @return array<string>
	 */
	public function provides(): array {
		return array(
			LogsPage::class,
			JobsPage::class,
			InboxPage::class,
			AnalyticsPage::class,
			TemplatesPage::class,
			CatalogSyncPage::class,
			DashboardWidgets::class,
			'wch.admin.logs',
			'wch.admin.jobs',
			'wch.admin.inbox',
			'wch.admin.analytics',
			'wch.admin.templates',
			'wch.admin.catalog_sync',
			'wch.admin.dashboard_widgets',
		);
	}
}
