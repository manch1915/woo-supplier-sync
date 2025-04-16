const wpScripts = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...wpScripts,
	{
		files: [ '**/*.ts', '**/*.tsx' ],
		rules: {
			// TypeScript already documents parameters; JSDoc would repeat the types.
			'jsdoc/require-param': 'off',
			'jsdoc/require-param-type': 'off',
			'jsdoc/require-returns-type': 'off',
			// REST payloads use the snake_case keys of the PHP side.
			camelcase: [ 'error', { properties: 'never', ignoreDestructuring: true } ],
		},
		settings: {
			'import/resolver': { node: { extensions: [ '.js', '.ts', '.tsx' ] } },
		},
	},
];
