<?php
/**
 * Action Service Provider
 *
 * Registers action handlers with the DI container.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Actions\ActionRegistry;
use WhatsAppCommerceHub\Actions\AbstractAction;
use WhatsAppCommerceHub\Actions\AddToCartAction;
use WhatsAppCommerceHub\Actions\ShowCartAction;
use WhatsAppCommerceHub\Actions\ShowCategoryAction;
use WhatsAppCommerceHub\Actions\ShowProductAction;
use WhatsAppCommerceHub\Actions\ShowMainMenuAction;
use WhatsAppCommerceHub\Actions\RequestAddressAction;
use WhatsAppCommerceHub\Actions\ConfirmOrderAction;
use WhatsAppCommerceHub\Actions\ProcessPaymentAction;
use WhatsAppCommerceHub\Contracts\Services\CartServiceInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ActionServiceProvider
 *
 * Registers and configures action handlers.
 */
class ActionServiceProvider extends AbstractServiceProvider {

	/**
	 * Action handler classes.
	 *
	 * @var string[]
	 */
	private array $actionHandlers = array(
		AddToCartAction::class,
		ShowCartAction::class,
		ShowCategoryAction::class,
		ShowProductAction::class,
		ShowMainMenuAction::class,
		RequestAddressAction::class,
		ConfirmOrderAction::class,
		ProcessPaymentAction::class,
	);

	/**
	 * Register services with the container.
	 *
	 * @return void
	 */
	public function register(): void {
		// Register ActionRegistry as singleton.
		$this->container->singleton(
			ActionRegistry::class,
			function () {
				return new ActionRegistry();
			}
		);

		// Register individual action handlers.
		foreach ( $this->actionHandlers as $handlerClass ) {
			$this->container->bind(
				$handlerClass,
				function ( $container ) use ( $handlerClass ) {
					$handler = new $handlerClass();

					// Inject dependencies if handler extends AbstractAction.
					if ( $handler instanceof AbstractAction ) {
						$this->injectDependencies( $container, $handler );
					}

					return $handler;
				}
			);
		}

		// Register alias.
		$this->container->alias( 'action_registry', ActionRegistry::class );
	}

	/**
	 * Bootstrap services after all providers are registered.
	 *
	 * @return void
	 */
	public function boot(): void {
		$registry = $this->container->get( ActionRegistry::class );

		// Register all action handlers with the registry.
		foreach ( $this->actionHandlers as $handlerClass ) {
			$handler = $this->container->get( $handlerClass );
			$registry->register( $handler );
		}

		// Allow external handlers to register.
		do_action( 'wch_register_action_handlers', $registry, $this->container );

		\WCH_Logger::log(
			'Action handlers registered',
			array(
				'count'   => $registry->count(),
				'actions' => $registry->getRegisteredActions(),
			),
			'debug'
		);
	}

	/**
	 * Get services provided by this provider.
	 *
	 * @return array
	 */
	public function provides(): array {
		return array_merge(
			array( ActionRegistry::class ),
			$this->actionHandlers
		);
	}

	/**
	 * Inject dependencies into action handler.
	 *
	 * @param mixed          $container DI container.
	 * @param AbstractAction $handler   Action handler.
	 * @return void
	 */
	private function injectDependencies( $container, AbstractAction $handler ): void {
		// Inject CartService if available.
		if ( $container->has( CartServiceInterface::class ) ) {
			$handler->setCartService( $container->get( CartServiceInterface::class ) );
		}

		// Inject CustomerService if available.
		if ( class_exists( 'WCH_Customer_Service' ) ) {
			$handler->setCustomerService( \WCH_Customer_Service::instance() );
		}
	}

	/**
	 * Add custom action handler class.
	 *
	 * Allows external code to register additional handlers before boot.
	 *
	 * @param string $handlerClass Fully qualified class name.
	 * @return void
	 */
	public function addHandler( string $handlerClass ): void {
		if ( ! in_array( $handlerClass, $this->actionHandlers, true ) ) {
			$this->actionHandlers[] = $handlerClass;
		}
	}
}
