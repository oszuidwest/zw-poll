( function () {
	'use strict';

	// All wp.* globals below are script dependencies, so they are present.
	const CONFIG = window.zwPollClassic;
	if (
		! CONFIG ||
		typeof CONFIG.shortcodeTag !== 'string' ||
		CONFIG.shortcodeTag === ''
	) {
		// eslint-disable-next-line no-console -- Missing localization would corrupt inserted shortcode tags.
		console.error( 'zw-poll: classic editor configuration is missing.' );
		return;
	}

	const { __ } = window.wp.i18n;
	const apiFetch = window.wp.apiFetch;
	const { decodeEntities } = window.wp.htmlEntities;
	const { escapeHTML } = window.wp.escapeHtml;
	const META_STATUS = '_zw_poll_status';

	function shortcodeFor( pollId ) {
		return '[' + CONFIG.shortcodeTag + ' id="' + pollId + '"]';
	}

	function insertIntoEditor( editorId, text ) {
		window.wpActiveEditor = editorId;
		if ( window.wp.media?.editor?.insert ) {
			window.wp.media.editor.insert( text );
			return true;
		}
		const textarea = document.getElementById( editorId );
		if ( textarea && 'value' in textarea ) {
			textarea.value += '\n' + text + '\n';
			return true;
		}
		return false;
	}

	// --- Picker dialog -------------------------------------------------

	const dialog = document.querySelector( '.zw-poll-picker' );

	function setupPicker() {
		const results = dialog.querySelector( '.zw-poll-picker__results' );
		const search = dialog.querySelector( '#zw-poll-picker-search' );
		let activeEditor = '';
		let searchTimer = 0;
		let requestSeq = 0;

		function showMessage( text ) {
			results.replaceChildren();
			const li = document.createElement( 'li' );
			li.className = 'zw-poll-picker__empty';
			li.textContent = text;
			results.append( li );
		}

		function showPolls( polls ) {
			results.replaceChildren();
			if ( ! polls.length ) {
				showMessage( __( 'Geen polls gevonden.', 'zw-poll' ) );
				return;
			}
			polls.forEach( ( poll ) => {
				const li = document.createElement( 'li' );
				const btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'zw-poll-picker__poll';
				btn.dataset.pollId = String( poll.id );

				const title = document.createElement( 'strong' );
				// REST title.rendered is entity-encoded; decode before
				// textContent so "Papier &amp; digitaal" reads correctly.
				title.textContent =
					poll.title && poll.title.rendered
						? decodeEntities( poll.title.rendered )
						: __( '(zonder titel)', 'zw-poll' );
				btn.append( title );

				btn.addEventListener( 'click', () => {
					const shortcode = shortcodeFor( poll.id );
					if ( insertIntoEditor( activeEditor, shortcode ) ) {
						dialog.close();
						return;
					}
					// No editor to insert into: keep the dialog open and
					// hand the editor a copyable shortcode instead of
					// silently doing nothing.
					showMessage(
						__(
							'Invoegen mislukt. Kopieer de shortcode handmatig:',
							'zw-poll'
						) +
							' ' +
							shortcode
					);
				} );
				li.append( btn );
				results.append( li );
			} );
		}

		async function loadPolls( term ) {
			const seq = ++requestSeq;
			showMessage( __( 'Laden…', 'zw-poll' ) );
			const args = new URLSearchParams( {
				per_page: '20',
				orderby: 'modified',
				order: 'desc',
				_fields: 'id,title.rendered',
			} );
			if ( term ) {
				args.set( 'search', term );
			}
			let polls;
			try {
				polls = await apiFetch( { path: '/wp/v2/zw-polls?' + args } );
			} catch ( error ) {
				// eslint-disable-next-line no-console -- Keep the cause diagnosable; the UI only shows a generic message.
				console.error( 'zw-poll: polls laden mislukt.', error );
				if ( seq === requestSeq ) {
					showMessage( __( 'Polls laden mislukt.', 'zw-poll' ) );
				}
				return;
			}
			if ( seq === requestSeq ) {
				showPolls( polls );
			}
		}

		search.addEventListener( 'keydown', ( event ) => {
			// Enter would submit the method="dialog" form and close the picker.
			if ( event.key === 'Enter' ) {
				event.preventDefault();
			}
		} );

		search.addEventListener( 'input', () => {
			clearTimeout( searchTimer );
			searchTimer = setTimeout(
				() => loadPolls( search.value.trim() ),
				300
			);
		} );

		function openPicker( editorId ) {
			activeEditor = editorId;
			search.value = '';
			dialog.showModal();
			loadPolls( '' );
		}

		function setupQuicktagsButton() {
			if ( ! CONFIG.quicktags || ! window.QTags ) {
				return;
			}

			const label = CONFIG.insertLabel || 'Poll invoegen';
			window.QTags.addButton(
				'zw_poll',
				CONFIG.quicktagsLabel || 'poll',
				( button, canvas, editor ) => {
					openPicker(
						editor?.id ||
							canvas?.id ||
							window.wpActiveEditor ||
							'content'
					);
				},
				'',
				'',
				label,
				111,
				'',
				{ ariaLabel: label }
			);
		}

		// tinymce-plugin.js calls this from the toolbar button when the dialog
		// is present on the page.
		CONFIG.openPicker = openPicker;
		setupQuicktagsButton();
	}

	if ( dialog ) {
		setupPicker();
	}

	// --- TinyMCE shortcode preview -------------------------------------

	function viewHtml( text, { closed = false, missing = false } = {} ) {
		return (
			'<div class="zw-poll-mce' +
			( missing ? ' zw-poll-mce--missing' : '' ) +
			'">' +
			'<span class="zw-poll-mce__label">' +
			escapeHTML( __( 'Poll', 'zw-poll' ) ) +
			'</span>' +
			( closed
				? '<span class="zw-poll-mce__closed">' +
				  escapeHTML( __( 'Gesloten', 'zw-poll' ) ) +
				  '</span>'
				: '' ) +
			'<span class="zw-poll-mce__question">' +
			escapeHTML( text ) +
			'</span>' +
			'</div>'
		);
	}

	function previewHtml( poll ) {
		// The title is the question. REST title.rendered is entity-encoded;
		// decode before it goes through escapeHTML in viewHtml.
		const question = poll.title?.rendered
			? decodeEntities( poll.title.rendered )
			: '';
		return viewHtml( question, {
			closed: poll.meta && poll.meta[ META_STATUS ] === 'closed',
		} );
	}

	if ( window.wp.mce?.views ) {
		const cache = {};
		window.wp.mce.views.register( CONFIG.shortcodeTag, {
			initialize() {
				const named = this.shortcode.attrs.named || {};
				const pollId = parseInt( named.id, 10 ) || 0;
				if ( ! pollId ) {
					this.render(
						viewHtml( __( 'Geen poll-ID opgegeven.', 'zw-poll' ), {
							missing: true,
						} )
					);
					return;
				}
				if ( cache[ pollId ] ) {
					this.render( previewHtml( cache[ pollId ] ) );
					return;
				}
				apiFetch( {
					path:
						'/wp/v2/zw-polls/' +
						pollId +
						'?_fields=id,title.rendered,meta.' +
						META_STATUS,
				} )
					.then( ( poll ) => {
						cache[ pollId ] = poll;
						this.render( previewHtml( poll ) );
					} )
					.catch( ( error ) => {
						// Only a 404 proves the poll is missing; a transient
						// failure must not read as "poll deleted".
						if ( error?.data?.status === 404 ) {
							this.render(
								viewHtml(
									__( 'Poll niet gevonden.', 'zw-poll' ),
									{ missing: true }
								)
							);
							return;
						}
						// eslint-disable-next-line no-console -- Keep the cause diagnosable; the UI only shows a generic message.
						console.error(
							'zw-poll: poll-preview laden mislukt.',
							error
						);
						this.render(
							viewHtml( __( 'Poll laden mislukt.', 'zw-poll' ), {
								missing: true,
							} )
						);
					} );
			},
			edit() {
				// The toolbar pencil: open the poll's own edit screen. The
				// shortcode has no other attributes to edit inline.
				const named = this.shortcode.attrs.named || {};
				const pollId = parseInt( named.id, 10 ) || 0;
				if ( ! pollId || ! CONFIG.editUrl ) {
					return;
				}
				window.open(
					CONFIG.editUrl + '?post=' + pollId + '&action=edit',
					'_blank',
					'noopener'
				);
			},
		} );
	}
} )();
