const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

// Resolved by WordPress' script-module import map at runtime, not by npm.
const wordpressExternals = [ '@wordpress/interactivity' ];

module.exports = [
	...defaultConfig,
	{
		settings: {
			'import/core-modules': wordpressExternals,
		},
	},
];
