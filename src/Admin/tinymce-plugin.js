( function () {
	'use strict';

	if ( ! window.tinymce?.PluginManager ) {
		// eslint-disable-next-line no-console -- A missing TinyMCE runtime makes the toolbar integration unavailable.
		console.warn( 'zw-poll: TinyMCE PluginManager is not available.' );
		return;
	}

	function config() {
		return window.zwPollClassic || {};
	}

	function missingPickerMessage() {
		return (
			config().pickerUnavailableMessage ||
			'De pollkiezer kan niet worden geopend.'
		);
	}

	function notifyMissingPicker( editor ) {
		const message = missingPickerMessage();
		if ( editor.notificationManager?.open ) {
			editor.notificationManager.open( {
				type: 'error',
				text: message,
			} );
		}
		// eslint-disable-next-line no-console -- Keep the missing picker diagnosable from the browser console.
		console.error( 'zw-poll: classic editor picker is not available.' );
	}

	window.tinymce.PluginManager.add( 'zwPoll', function ( editor ) {
		editor.addButton( 'zw_poll', {
			icon: 'zw-poll',
			// TinyMCE loads this file itself, so wp_set_script_translations
			// cannot bind a locale to it; PHP localizes the label instead.
			tooltip: config().insertLabel || 'Poll invoegen',
			onclick() {
				const openPicker = config().openPicker;
				if ( typeof openPicker === 'function' ) {
					openPicker( editor.id );
					return;
				}
				notifyMissingPicker( editor );
			},
		} );
	} );
} )();
