<?php
/**
 * Demo only: catalog template for Twenty Twenty-Five with core Product Filters in a sidebar.
 * The filters work on the global attributes (Brand, Color, Size...) created by the importer.
 * Run with: wp eval-file /demo/shop-template.php
 *
 * @package SupplierSyncDemo
 *
 * No strict_types here: wp eval-file runs the file through eval().
 */


$supplier_sync_attribute = static function ( string $label ): int {
	foreach ( wc_get_attribute_taxonomies() as $attribute ) {
		if ( 0 === strcasecmp( $attribute->attribute_label, $label ) ) {
			return (int) $attribute->attribute_id;
		}
	}
	return 0;
};

$supplier_sync_filter = static function ( string $label ) use ( $supplier_sync_attribute ): string {
	$id = $supplier_sync_attribute( $label );
	if ( 0 === $id ) {
		return '';
	}
	return sprintf(
		'<!-- wp:woocommerce/product-filter-attribute {"attributeId":%1$d,"showCounts":true,"queryType":"or","sortOrder":"name-asc"} -->
<div class="wp-block-woocommerce-product-filter-attribute"><!-- wp:heading {"level":3,"fontSize":"medium"} --><h3 class="wp-block-heading has-medium-font-size">%2$s</h3><!-- /wp:heading -->
<!-- wp:woocommerce/product-filter-checkbox-list /--></div>
<!-- /wp:woocommerce/product-filter-attribute -->',
		$id,
		esc_html( $label )
	);
};

$supplier_sync_content = '<!-- wp:template-part {"slug":"header"} /-->

<!-- wp:group {"tagName":"main","align":"wide","layout":{"type":"constrained","wideSize":"1340px"}} -->
<main class="wp-block-group alignwide">
<!-- wp:woocommerce/breadcrumbs {"align":"wide"} /-->
<!-- wp:query-title {"type":"archive","showPrefix":false,"align":"wide"} /-->
<!-- wp:woocommerce/store-notices /-->

<!-- wp:woocommerce/product-collection {"queryId":0,"query":{"woocommerceAttributes":[],"woocommerceStockStatus":["instock","outofstock","onbackorder"],"taxQuery":{},"isProductCollectionBlock":true,"perPage":12,"pages":0,"offset":0,"postType":"product","order":"asc","orderBy":"title","author":"","search":"","exclude":[],"sticky":"","inherit":true},"tagName":"div","displayLayout":{"type":"flex","columns":3,"shrinkColumns":true},"queryContextIncludes":["collection"],"align":"wide"} -->
<div class="wp-block-woocommerce-product-collection alignwide">
<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"left":"var:preset|spacing|50"}}}} -->
<div class="wp-block-columns alignwide">
<!-- wp:column {"width":"260px","className":"wss-demo-filters"} -->
<div class="wp-block-column wss-demo-filters" style="flex-basis:260px">
<!-- wp:woocommerce/product-filters -->
<div class="wp-block-woocommerce-product-filters">
<!-- wp:heading {"level":2,"fontSize":"large"} --><h2 class="wp-block-heading has-large-font-size">Filters</h2><!-- /wp:heading -->
<!-- wp:woocommerce/product-filter-active -->
<div class="wp-block-woocommerce-product-filter-active"><!-- wp:woocommerce/product-filter-removable-chips /-->
<!-- wp:woocommerce/product-filter-clear-button /--></div>
<!-- /wp:woocommerce/product-filter-active -->
<!-- wp:woocommerce/product-filter-price -->
<div class="wp-block-woocommerce-product-filter-price"><!-- wp:heading {"level":3,"fontSize":"medium"} --><h3 class="wp-block-heading has-medium-font-size">Price</h3><!-- /wp:heading -->
<!-- wp:woocommerce/product-filter-price-slider /--></div>
<!-- /wp:woocommerce/product-filter-price -->
' . $supplier_sync_filter( 'Brand' ) . '
' . $supplier_sync_filter( 'Color' ) . '
' . $supplier_sync_filter( 'Size' ) . '
' . $supplier_sync_filter( 'Water resistance' ) . '
<!-- wp:woocommerce/product-filter-status -->
<div class="wp-block-woocommerce-product-filter-status"><!-- wp:heading {"level":3,"fontSize":"medium"} --><h3 class="wp-block-heading has-medium-font-size">Availability</h3><!-- /wp:heading -->
<!-- wp:woocommerce/product-filter-checkbox-list /--></div>
<!-- /wp:woocommerce/product-filter-status -->
</div>
<!-- /wp:woocommerce/product-filters -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
<div class="wp-block-group"><!-- wp:woocommerce/product-results-count /-->
<!-- wp:woocommerce/catalog-sorting /--></div>
<!-- /wp:group -->
<!-- wp:woocommerce/product-template -->
<!-- wp:woocommerce/product-image {"showSaleBadge":true,"imageSizing":"thumbnail","isDescendentOfQueryLoop":true} -->
<!-- wp:woocommerce/product-sale-badge {"isDescendentOfQueryLoop":true,"align":"right"} /-->
<!-- /wp:woocommerce/product-image -->
<!-- wp:post-title {"textAlign":"center","level":2,"isLink":true,"fontSize":"medium","__woocommerceNamespace":"woocommerce/product-collection/product-title"} /-->
<!-- wp:woocommerce/product-price {"textAlign":"center","isDescendentOfQueryLoop":true,"fontSize":"small"} /-->
<!-- wp:woocommerce/product-button {"textAlign":"center","isDescendentOfQueryLoop":true,"fontSize":"small"} /-->
<!-- /wp:woocommerce/product-template -->
<!-- wp:query-pagination {"layout":{"type":"flex","justifyContent":"center"}} -->
<!-- wp:query-pagination-previous /-->
<!-- wp:query-pagination-numbers /-->
<!-- wp:query-pagination-next /-->
<!-- /wp:query-pagination -->
<!-- wp:woocommerce/product-collection-no-results -->
<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center">No products match these filters.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce/product-collection-no-results -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:woocommerce/product-collection -->
</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer"} /-->';

$supplier_sync_theme = get_stylesheet();
foreach ( [ 'archive-product', 'taxonomy-product_cat' ] as $supplier_sync_slug ) {
	$supplier_sync_existing = get_posts(
		[
			'post_type'      => 'wp_template',
			'name'           => $supplier_sync_slug,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => 'wp_theme',
					'field'    => 'name',
					'terms'    => $supplier_sync_theme,
				],
			],
		]
	);

	$supplier_sync_id = wp_insert_post(
		[
			'ID'           => $supplier_sync_existing ? $supplier_sync_existing[0]->ID : 0,
			'post_type'    => 'wp_template',
			'post_name'    => $supplier_sync_slug,
			'post_title'   => 'archive-product' === $supplier_sync_slug ? 'Product Catalog' : 'Products by Category',
			'post_status'  => 'publish',
			'post_content' => $supplier_sync_content,
		],
		true
	);
	if ( ! is_wp_error( $supplier_sync_id ) ) {
		wp_set_object_terms( $supplier_sync_id, $supplier_sync_theme, 'wp_theme' );
		WP_CLI::log( "Template $supplier_sync_slug saved (#$supplier_sync_id)." );
	}
}
