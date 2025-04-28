<?php
/**
 * Compare toggle for one product.
 *
 * @package SupplierSync
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var WP_Block             $block      Block instance.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$supplier_sync_product = wc_get_product( (int) ( $block->context['postId'] ?? get_the_ID() ) );
if ( ! $supplier_sync_product instanceof WC_Product ) {
	return;
}

$supplier_sync_image = wp_get_attachment_image_url( (int) $supplier_sync_product->get_image_id(), 'thumbnail' );

wp_interactivity_state(
	'supplier-sync/compare',
	[
		'labels'      => [
			'add'    => __( 'Compare', 'woo-supplier-sync' ),
			'remove' => __( 'In comparison', 'woo-supplier-sync' ),
			'full'   => __( 'Compare: 4 max', 'woo-supplier-sync' ),
		],
		'isSelected'  => false,
		'isDisabled'  => false,
		'toggleLabel' => __( 'Compare', 'woo-supplier-sync' ),
	]
);
wp_enqueue_script_module( 'supplier-sync-compare-bar-view-script-module' );

$supplier_sync_context = [
	'id'    => $supplier_sync_product->get_id(),
	'name'  => html_entity_decode( $supplier_sync_product->get_name() ),
	'image' => $supplier_sync_image ? $supplier_sync_image : wc_placeholder_img_src( 'thumbnail' ),
];
?>
<div
	<?php echo get_block_wrapper_attributes( [ 'class' => 'wss-compare-toggle' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by core. ?>
	data-wp-interactive="supplier-sync/compare"
	<?php echo wp_interactivity_data_wp_context( $supplier_sync_context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by core. ?>
>
	<button
		type="button"
		class="wss-compare-toggle__button"
		data-wp-on--click="actions.toggle"
		data-wp-bind--aria-pressed="state.isSelected"
		data-wp-bind--disabled="state.isDisabled"
		data-wp-class--is-selected="state.isSelected"
		aria-pressed="false"
	>
		<svg class="wss-compare-toggle__icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
			<path d="M8 4v16M16 4v16M4 8h8M12 16h8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
		</svg>
		<span data-wp-text="state.toggleLabel"><?php esc_html_e( 'Compare', 'woo-supplier-sync' ); ?></span>
	</button>
</div>
