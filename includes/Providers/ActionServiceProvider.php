<?php
/**
 * Action Service Provider
 *
 * Registers action handlers and provides extension points for custom actions.
 *
 * This provider is responsible for:
 * - Registering ActionRegistry as a singleton
 * - Binding core action handler classes with dependency injection
 * - Registering handlers with the ActionRegistry during boot
 * - Providing the 'wch_register_action_handlers' hook for external handler registration
 *
 * EXTENSION POINT:
 * Use the 'wch_register_action_handlers' WordPress action hook to register custom handlers:
 *
 * ```php
 * add_action('wch_register_action_handlers', function($registry, $container) {
 *     // Create custom handler
 *     $handler = new MyCustomAction();
 *
 *     // Inject dependencies (optional)
 *     $handler->setLogger($container->get('logger'));
 *     $handler->setCartService($container->get(CartServiceInterface::class));
 *
 *     // Register with registry
 *     $registry->register($handler);
 * }, 10, 2);
 * ```
 *
 * Or use the addHandler() method before boot:
 *
 * ```php
 * $actionProvider = $container->get(ActionServiceProvider::class);
 * $actionProvider->addHandler(MyCustomAction::class);
 * ```
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 * @see ActionRegistry Handler registry
 * @see ActionHandlerInterface Handler contract
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
use WhatsAppCommerceHub\Contracts\Services\CustomerServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ActionServiceProvider
 *
 * Registers and configures action handlers with dependency injection and extension hooks.
 */
class ActionServiceProvider extends AbstractServiceProvider {

	/**
	 * Action handler classes.
	 *
	 * @var string[]
	 */
	private array $actionHandlers = [
		AddToCartAction::class,
		ShowCartAction::class,
		ShowCategoryAction::class,
		ShowProductAction::class,
		ShowMainMenuAction::class,
		RequestAddressAction::class,
		ConfirmOrderAction::class,
		ProcessPaymentAction::class,
	];

	/**
	 * Register services with the container.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
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
	 * Registers core action handlers and fires the 'wch_register_action_handlers' hook
	 * for external code to register custom handlers.
	 *
	 * @return void
	 */
	protected function doBoot(): void {
		$registry = $this->container->get( ActionRegistry::class );

		// Register all core action handlers with the registry.
		foreach ( $this->actionHandlers as $handlerClass ) {
			$handler = $this->container->get( $handlerClass );
			$registry->register( $handler );
		}

		/**
		 * Fires after core action handlers are registered.
		 *
		 * This is the PRIMARY EXTENSION POINT for registering custom action handlers.
		 * Use this hook to add your own handlers to the ActionRegistry.
		 *
		 * @since 3.0.0
		 * @param ActionRegistry $registry  The action registry instance.
		 * @param Container      $container The DI container for resolving dependencies.
		 *
		 * @example
		 * add_action('wch_register_action_handlers', function($registry, $container) {
		 *     $handler = new MyCustomAction();
		 *     $handler->setLogger($container->get('logger'));
		 *     $registry->register($handler);
		 * }, 10, 2);
		 */
		do_action( 'wch_register_action_handlers', $registry, $this->container );

		$logger = $this->container->get( LoggerInterface::class );
		$logger->debug(
			'Action handlers registered',
			'actions',
			[
				'count'   => $registry->count(),
				'actions' => $registry->getRegisteredActions(),
			]
		);
	}

	/**
	 * @return array<class-string<\WhatsAppCommerceHub\Container\ServiceProviderInterface>>
	 */
	public function dependsOn(): array {
		return [
			\WhatsAppCommerceHub\Providers\BusinessServiceProvider::class,
		];
	}

	public function provides(): array {
		return array_merge(
			[ ActionRegistry::class ],
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
		if ( $container->has( CustomerServiceInterface::class ) ) {
			$handler->setCustomerService( $container->get( CustomerServiceInterface::class ) );
		}
	}

	/**
	 * Add custom action handler class.
	 *
	 * ALTERNATIVE EXTENSION POINT: Use this method to register action handlers
	 * early in the plugin lifecycle, before the boot phase.
	 *
	 * This is useful when you need to ensure your handler is registered alongside
	 * core handlers with full dependency injection support.
	 *
	 * @since 3.0.0
	 * @param string $handlerClass Fully qualified class name implementing ActionHandlerInterface.
	 * @return void
	 *
	 * @example
	 * // In your plugin initialization
	 * $actionProvider = $container->get(ActionServiceProvider::class);
	 * $actionProvider->addHandler(MyCustomAction::class);
	 */
	public function addHandler( string $handlerClass ): void {
		if ( ! in_array( $handlerClass, $this->actionHandlers, true ) ) {
			$this->actionHandlers[] = $handlerClass;
		}
	}
}
