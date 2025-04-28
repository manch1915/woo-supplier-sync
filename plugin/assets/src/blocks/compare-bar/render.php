<?php
/**
 * Comparison tray and table dialog.
 *
 * @package SupplierSync
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

wp_interactivity_state(
	'supplier-sync/compare',
	[
		'restUrl'         => rest_url( 'supplier-sync/v1/compare' ),
		'items'           => [],
		'products'        => [],
		'isLoading'       => false,
		'onlyDifferences' => false,
		'error'           => '',
		'hasItems'        => false,
		'hasProducts'     => false,
		'canCompare'      => false,
		'compareLabel'    => __( 'Compare', 'woo-supplier-sync' ),
		'hint'            => '',
		'rows'            => [],
		'labels'          => [
			'add'          => __( 'Compare', 'woo-supplier-sync' ),
			'remove'       => __( 'In comparison', 'woo-supplier-sync' ),
			'full'         => __( 'Compare: 4 max', 'woo-supplier-sync' ),
			'compare'      => __( 'Compare', 'woo-supplier-sync' ),
			'price'        => __( 'Price', 'woo-supplier-sync' ),
			'availability' => __( 'Availability', 'woo-supplier-sync' ),
			'sku'          => __( 'SKU', 'woo-supplier-sync' ),
			/* translators: %s: product name. */
			'removeItem'   => __( 'Remove %s from comparison', 'woo-supplier-sync' ),
			'addOneMore'   => __( 'Add one more product to compare.', 'woo-supplier-sync' ),
			/* translators: 1: products picked, 2: limit. */
			'countOf'      => __( '%1$d of %2$d products', 'woo-supplier-sync' ),
			'loadError'    => __( 'Could not load the products. Check your connection and try again.', 'woo-supplier-sync' ),
		],
	]
);

$supplier_sync_close_icon = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
?>
<div
	<?php echo get_block_wrapper_attributes( [ 'class' => 'wss-compare' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by core. ?>
	data-wp-interactive="supplier-sync/compare"
	data-wp-init="callbacks.restore"
	data-wp-on-window--storage="callbacks.syncTabs"
>
	<section class="wss-compare-tray" aria-label="<?php esc_attr_e( 'Products to compare', 'woo-supplier-sync' ); ?>" data-wp-bind--hidden="!state.hasItems" hidden>
		<ul class="wss-compare-tray__items">
			<template data-wp-each="state.items" data-wp-each-key="context.item.id">
				<li class="wss-compare-tray__item">
					<img data-wp-bind--src="context.item.image" alt="" width="44" height="44" loading="lazy" />
					<span class="wss-compare-tray__name" data-wp-text="context.item.name"></span>
					<button type="button" class="wss-compare-tray__remove" data-wp-on--click="actions.remove" data-wp-bind--aria-label="state.removeLabel">
						<?php echo $supplier_sync_close_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
					</button>
				</li>
			</template>
		</ul>
		<p class="wss-compare-tray__hint" data-wp-text="state.hint" aria-live="polite"></p>
		<div class="wss-compare-tray__actions">
			<button type="button" class="wss-compare-btn is-ghost" data-wp-on--click="actions.clear"><?php esc_html_e( 'Clear', 'woo-supplier-sync' ); ?></button>
			<button type="button" class="wss-compare-btn" data-wp-on--click="actions.open" data-wp-bind--disabled="!state.canCompare" data-wp-text="state.compareLabel"><?php esc_html_e( 'Compare', 'woo-supplier-sync' ); ?></button>
		</div>
	</section>

	<dialog id="wss-compare-dialog" class="wss-compare-dialog" aria-labelledby="wss-compare-title" data-wp-on--click="actions.closeOnBackdrop">
		<div class="wss-compare-dialog__inner">
			<header class="wss-compare-dialog__head">
				<h2 id="wss-compare-title" class="wss-compare-dialog__title"><?php esc_html_e( 'Compare products', 'woo-supplier-sync' ); ?></h2>
				<label class="wss-compare-switch">
					<input type="checkbox" data-wp-on--change="actions.toggleDifferences" data-wp-bind--checked="state.onlyDifferences" />
					<span><?php esc_html_e( 'Only show differences', 'woo-supplier-sync' ); ?></span>
				</label>
				<button type="button" class="wss-compare-dialog__close" data-wp-on--click="actions.close" aria-label="<?php esc_attr_e( 'Close comparison', 'woo-supplier-sync' ); ?>">
					<?php echo $supplier_sync_close_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				</button>
			</header>

			<p class="wss-compare-dialog__status" data-wp-bind--hidden="!state.isLoading" role="status"><?php esc_html_e( 'Loading…', 'woo-supplier-sync' ); ?></p>
			<p class="wss-compare-dialog__status is-error" data-wp-bind--hidden="!state.error" data-wp-text="state.error" role="alert"></p>

			<div class="wss-compare-table-wrap" data-wp-bind--hidden="!state.hasProducts">
				<table class="wss-compare-table">
					<caption class="screen-reader-text"><?php esc_html_e( 'Rows where the products differ are highlighted.', 'woo-supplier-sync' ); ?></caption>
					<thead>
						<tr>
							<td></td>
							<template data-wp-each--product="state.products" data-wp-each-key="context.product.id">
								<th scope="col" class="wss-compare-table__product">
									<a data-wp-bind--href="context.product.url">
										<img data-wp-bind--src="context.product.image" alt="" width="140" height="140" loading="lazy" />
										<span data-wp-text="context.product.name"></span>
									</a>
									<button type="button" class="wss-compare-table__remove" data-wp-on--click="actions.removeFromTable"><?php esc_html_e( 'Remove', 'woo-supplier-sync' ); ?></button>
								</th>
							</template>
						</tr>
					</thead>
					<tbody>
						<template data-wp-each--row="state.rows" data-wp-each-key="context.row.label">
							<tr data-wp-class--is-different="context.row.differs" data-wp-bind--hidden="state.rowHidden">
								<th scope="row" data-wp-text="context.row.label"></th>
								<template data-wp-each--cell="context.row.cells" data-wp-each-key="context.cell.key">
									<td data-wp-text="context.cell.value"></td>
								</template>
							</tr>
						</template>
					</tbody>
				</table>
			</div>
		</div>
	</dialog>
</div>
