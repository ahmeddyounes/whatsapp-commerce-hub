<?php
/**
 * Product Sync Admin UI Service
 *
 * Handles admin columns, bulk actions, and notices for product sync.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services\ProductSync;

use WhatsAppCommerceHub\Contracts\Services\ProductSync\ProductValidatorInterface;
use WhatsAppCommerceHub\Contracts\Services\ProductSync\ProductSyncOrchestratorInterface;
use WhatsAppCommerceHub\Contracts\Services\ProductSync\ProductSyncMetadata;
use WhatsAppCommerceHub\Contracts\Services\ProductSync\ProductSyncStatus;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProductSyncAdminUI
 *
 * Handles admin UI for product sync status.
 */
class ProductSyncAdminUI {

	/**
	 * Constructor.
	 *
	 * @param ProductValidatorInterface        $validator    Product validator.
	 * @param ProductSyncOrchestratorInterface $orchestrator Product sync orchestrator.
	 */
	public function __construct(
		protected ProductValidatorInterface $validator,
		protected ProductSyncOrchestratorInterface $orchestrator
	) {
	}

	/**
	 * Initialize WordPress hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'manage_product_posts_columns', [ $this, 'addSyncStatusColumn' ] );
		add_action( 'manage_product_posts_custom_column', [ $this, 'renderSyncStatusColumn' ], 10, 2 );
		add_filter( 'bulk_actions-edit-product', [ $this, 'addBulkActions' ] );
		add_filter( 'handle_bulk_actions-edit-product', [ $this, 'handleBulkActions' ], 10, 3 );
		add_action( 'admin_notices', [ $this, 'showBulkActionNotices' ] );
	}

	/**
	 * Add sync status column to products list.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function addSyncStatusColumn( array $columns ): array {
		$newColumns = [];

		foreach ( $columns as $key => $value ) {
			$newColumns[ $key ] = $value;
			if ( 'name' === $key ) {
				$newColumns['wch_sync_status'] = __( 'WhatsApp Sync', 'whatsapp-commerce-hub' );
			}
		}

		return $newColumns;
	}

	/**
	 * Render sync status column.
	 *
	 * @param string $column  Column name.
	 * @param int    $postId  Post ID.
	 * @return void
	 */
	public function renderSyncStatusColumn( string $column, int $postId ): void {
		if ( 'wch_sync_status' !== $column ) {
			return;
		}

		if ( ! $this->validator->isSyncEnabled() ) {
			echo '<span style="color: #999;" title="' . esc_attr__( 'Sync disabled', 'whatsapp-commerce-hub' ) . '">&mdash;</span>';
			return;
		}

		$status      = get_post_meta( $postId, ProductSyncMetadata::SYNC_STATUS, true );
		$catalogId   = get_post_meta( $postId, ProductSyncMetadata::CATALOG_ID, true );
		$lastSynced  = get_post_meta( $postId, ProductSyncMetadata::LAST_SYNCED, true );
		$syncMessage = get_post_meta( $postId, ProductSyncMetadata::SYNC_MESSAGE, true );

		$icon  = '';
		$title = '';
		$color = '';

		switch ( $status ) {
			case ProductSyncStatus::SYNCED:
				$icon  = '&#10003;'; // Checkmark.
				$color = '#46b450';
				$title = __( 'Synced', 'whatsapp-commerce-hub' );
				if ( $lastSynced ) {
					$title .= ' - ' .
						human_time_diff( strtotime( $lastSynced ), current_time( 'timestamp' ) ) .
						' ' . __( 'ago', 'whatsapp-commerce-hub' );
				}
				break;

			case ProductSyncStatus::ERROR:
				$icon  = '&#10007;'; // X mark.
				$color = '#dc3232';
				$title = __( 'Sync error', 'whatsapp-commerce-hub' );
				if ( $syncMessage ) {
					$title .= ': ' . $syncMessage;
				}
				break;

			case ProductSyncStatus::PARTIAL:
				$icon  = '&#9680;'; // Half-filled circle.
				$color = '#ffb900';
				$title = __( 'Partially synced', 'whatsapp-commerce-hub' );
				if ( $syncMessage ) {
					$title .= ': ' . $syncMessage;
				}
				break;

			default:
				$icon  = '&#9675;'; // Empty circle.
				$color = '#999';
				$title = __( 'Not synced', 'whatsapp-commerce-hub' );
				break;
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $icon is a safe HTML entity.
		printf(
			'<span style="color: %s; font-size: 16px;" title="%s">%s</span>',
			esc_attr( $color ),
			esc_attr( $title ),
			$icon
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Add bulk actions to products list.
	 *
	 * @param array $actions Existing actions.
	 * @return array Modified actions.
	 */
	public function addBulkActions( array $actions ): array {
		if ( $this->validator->isSyncEnabled() ) {
			$actions['wch_sync_to_whatsapp']     = __( 'Sync to WhatsApp', 'whatsapp-commerce-hub' );
			$actions['wch_remove_from_whatsapp'] = __( 'Remove from WhatsApp', 'whatsapp-commerce-hub' );
		}

		return $actions;
	}

	/**
	 * Handle bulk actions.
	 *
	 * @param string $redirectTo Redirect URL.
	 * @param string $action     Action name.
	 * @param array  $postIds    Post IDs.
	 * @return string Modified redirect URL.
	 */
	public function handleBulkActions( string $redirectTo, string $action, array $postIds ): string {
		if ( 'wch_sync_to_whatsapp' === $action ) {
			$synced = 0;

			foreach ( $postIds as $postId ) {
				$result = $this->orchestrator->syncProduct( (int) $postId );
				if ( ! empty( $result['success'] ) ) {
					++$synced;
				}
			}

			$redirectTo = add_query_arg(
				[
					'wch_bulk_synced' => $synced,
					'wch_bulk_total'  => count( $postIds ),
				],
				$redirectTo
			);
		} elseif ( 'wch_remove_from_whatsapp' === $action ) {
			$removed = 0;

			foreach ( $postIds as $postId ) {
				$result = $this->orchestrator->deleteProduct( (int) $postId );
				if ( ! empty( $result['success'] ) ) {
					++$removed;
				}
			}

			$redirectTo = add_query_arg(
				[
					'wch_bulk_removed' => $removed,
					'wch_bulk_total'   => count( $postIds ),
				],
				$redirectTo
			);
		}

		return $redirectTo;
	}

	/**
	 * Show bulk action admin notices.
	 *
	 * @return void
	 */
	public function showBulkActionNotices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.EscapeOutput.OutputNotEscaped
		// Values are integers cast from $_REQUEST.
		if ( ! empty( $_REQUEST['wch_bulk_synced'] ) ) {
			$synced = (int) $_REQUEST['wch_bulk_synced'];
			$total  = (int) ( $_REQUEST['wch_bulk_total'] ?? 0 );

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				sprintf(
					/* translators: %1$d: number of synced products, %2$d: total products */
					esc_html__( 'Successfully synced %1$d of %2$d products to WhatsApp.', 'whatsapp-commerce-hub' ),
					$synced,
					$total
				)
			);
		}

		if ( ! empty( $_REQUEST['wch_bulk_removed'] ) ) {
			$removed = (int) $_REQUEST['wch_bulk_removed'];
			$total   = (int) ( $_REQUEST['wch_bulk_total'] ?? 0 );

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				sprintf(
					/* translators: %1$d: number of removed products, %2$d: total products */
					esc_html__( 'Successfully removed %1$d of %2$d products from WhatsApp.', 'whatsapp-commerce-hub' ),
					$removed,
					$total
				)
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Get sync status summary for a product.
	 *
	 * @param int $productId Product ID.
	 * @return array{status: string, synced: bool, last_synced: string|null, error: string|null}
	 */
	public function getProductSyncStatus( int $productId ): array {
		$status      = get_post_meta( $productId, ProductSyncMetadata::SYNC_STATUS, true );
		$catalogId   = get_post_meta( $productId, ProductSyncMetadata::CATALOG_ID, true );
		$lastSynced  = get_post_meta( $productId, ProductSyncMetadata::LAST_SYNCED, true );
		$syncMessage = get_post_meta( $productId, ProductSyncMetadata::SYNC_MESSAGE, true );

		return [
			'status'      => $status ?: ProductSyncStatus::NOT_SYNCED,
			'synced'      => ProductSyncStatus::SYNCED === $status,
			'catalog_id'  => $catalogId ?: null,
			'last_synced' => $lastSynced ?: null,
			'error'       => ProductSyncStatus::ERROR === $status ? ( $syncMessage ?: null ) : null,
		];
	}
}
