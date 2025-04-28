import { useBlockProps } from '@wordpress/block-editor';
import { registerBlockType, type BlockConfiguration } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import './style.scss';

function Edit() {
	return (
		<div { ...useBlockProps( { className: 'wss-compare-toggle' } ) }>
			<span className="wss-compare-toggle__button" aria-disabled="true">
				{ __( 'Compare', 'woo-supplier-sync' ) }
			</span>
		</div>
	);
}

registerBlockType( metadata as BlockConfiguration, {
	edit: Edit,
	save: () => null,
} );
