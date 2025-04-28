// @wordpress/block-editor ships without type declarations; only useBlockProps is used here.
declare module '@wordpress/block-editor' {
	export function useBlockProps(
		props?: Record< string, unknown >
	): Record< string, unknown >;
}
