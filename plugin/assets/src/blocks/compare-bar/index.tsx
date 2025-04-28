import { registerBlockType, type BlockConfiguration } from '@wordpress/blocks';
import metadata from './block.json';
import './style.scss';

registerBlockType( metadata as BlockConfiguration, {
	edit: () => null,
	save: () => null,
} );
