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
	 * Product validator service.
	 *
	 * @var ProductValidatorInterface
	 */
	protected ProductValidatorInterface $validator;

	/**
	 * Product sync orchestrator.
	 *
	 * @var ProductSyncOrchestratorInterface
	 */
	protected ProductSyncOrchestratorInterface $orchestrator;

	/**
	 * Constructor.
	 *
	 * @param ProductValidatorInterface        $validator    Product validator.
	 * @param ProductSyncOrchestratorInterface $orchestrator Product sync orchestrator.
	 */
	public function __construct(
		ProductValidatorInterface $validator,
		ProductSyncOrchestratorInterface $orchestrator
	) {
		$this->validator    = $validator;
		$this->orchestrator = $orchestrator;
	}

	/**
	 * Initialize WordPress hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'manage_product_posts_columns', array( $this, 'addSyncStatusColumn' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'renderSyncStatusColumn' ), 10, 2 );
		add_filter( 'bulk_actions-edit-product', array( $this, 'addBulkActions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handleBulkActions' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'showBulkActionNotices' ) );
	}

	/**
	 * Add sync status column to products list.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function addSyncStatusColumn( array $columns ): array {
		$newColumns = array();

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

		$status      = get_post_meta( $postId, CatalogApiService::META_SYNC_STATUS, true );
		$catalogId   = get_post_meta( $postId, CatalogApiService::META_CATALOG_ID, true );
		$lastSynced  = get_post_meta( $postId, CatalogApiService::META_LAST_SYNCED, true );
		$syncMessage = get_post_meta( $postId, '_wch_sync_message', true );

		$icon  = '';
		$title = '';
		$color = '';

		switch ( $status ) {
			case 'synced':
				$icon  = '&#10003;'; // Checkmark.
				$color = '#46b450';
				$title = __( 'Synced', 'whatsapp-commerce-hub' );
				if ( $lastSynced ) {
					$title .= ' - ' .
						human_time_diff( strtotime( $lastSynced ), current_time( 'timestamp' ) ) .
						' ' . __( 'ago', 'whatsapp-commerce-hub' );
				}
				break;

			case 'error':
				$icon  = '&#10007;'; // X mark.
				$color = '#dc3232';
				$title = __( 'Sync error', 'whatsapp-commerce-hub' );
				if ( $syncMessage ) {
					$title .= ': ' . $syncMessage;
				}
				break;

			case 'partial':
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

		printf(
			'<span style="color: %s; font-size: 16px;" title="%s">%s</span>',
			esc_attr( $color ),
			esc_attr( $title ),
			$icon
		);
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
				array(
					'wch_bulk_synced' => $synced,
					'wch_bulk_total'  => count( $postIds ),
				),
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
				array(
					'wch_bulk_removed' => $removed,
					'wch_bulk_total'   => count( $postIds ),
				),
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
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
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Get sync status summary for a product.
	 *
	 * @param int $productId Product ID.
	 * @return array{status: string, synced: bool, last_synced: string|null, error: string|null}
	 */
	public function getProductSyncStatus( int $productId ): array {
		$status      = get_post_meta( $productId, CatalogApiService::META_SYNC_STATUS, true );
		$catalogId   = get_post_meta( $productId, CatalogApiService::META_CATALOG_ID, true );
		$lastSynced  = get_post_meta( $productId, CatalogApiService::META_LAST_SYNCED, true );
		$syncMessage = get_post_meta( $productId, '_wch_sync_message', true );

		return array(
			'status'      => $status ?: 'not_synced',
			'synced'      => 'synced' === $status,
			'catalog_id'  => $catalogId ?: null,
			'last_synced' => $lastSynced ?: null,
			'error'       => 'error' === $status ? ( $syncMessage ?: null ) : null,
		);
	}
}
