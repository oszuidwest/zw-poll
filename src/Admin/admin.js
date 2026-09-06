( function () {
	'use strict';

	const i18n = ( window.wp && window.wp.i18n ) || null;
	const __ = i18n ? i18n.__ : ( s ) => s;
	const sprintf = i18n
		? i18n.sprintf
		: ( s, ...args ) => s.replace( /%d/, args[ 0 ] );

	async function runButtonAction(
		btn,
		{ body, errorPrefix, confirmMessage } = {}
	) {
		// eslint-disable-next-line no-alert -- Native confirm matches wp-admin destructive flows.
		if ( confirmMessage && ! window.confirm( confirmMessage ) ) {
			return;
		}

		const original = btn.textContent;
		btn.disabled = true;
		btn.textContent = __( 'Bezig…', 'zw-poll' );

		try {
			const res = await fetch( btn.dataset.restUrl, {
				method: 'POST',
				headers: {
					'X-WP-Nonce': btn.dataset.restNonce,
					...( body ? { 'Content-Type': 'application/json' } : {} ),
				},
				credentials: 'same-origin',
				...( body ? { body: JSON.stringify( body ) } : {} ),
			} );
			if ( ! res.ok ) {
				throw new Error( 'HTTP ' + res.status );
			}
			window.location.reload();
		} catch ( e ) {
			const detail =
				e && e.message ? e.message : __( 'onbekende fout', 'zw-poll' );
			// eslint-disable-next-line no-alert -- Native alert keeps this admin helper dependency-free.
			window.alert( errorPrefix + ' ' + detail );
			btn.disabled = false;
			btn.textContent = original;
		}
	}

	document.querySelectorAll( '.zw-poll-status-toggle' ).forEach( ( btn ) => {
		btn.addEventListener( 'click', () =>
			runButtonAction( btn, {
				body: {
					meta: {
						[ btn.dataset.metaKey ]: btn.dataset.targetStatus,
					},
				},
				errorPrefix: __( 'Status wijzigen mislukt:', 'zw-poll' ),
			} )
		);
	} );

	document.querySelectorAll( '.zw-poll-admin-reset' ).forEach( ( btn ) => {
		btn.addEventListener( 'click', () =>
			runButtonAction( btn, {
				errorPrefix: __( 'Verwijderen mislukt:', 'zw-poll' ),
				confirmMessage: __(
					'Alle stemmen voor deze poll worden permanent verwijderd. Dit kan niet ongedaan gemaakt worden. Doorgaan?',
					'zw-poll'
				),
			} )
		);
	} );

	// Shortcode copy buttons (poll edit screen and list table). Core bundles
	// ClipboardJS (script dependency), which also covers plain-HTTP admins
	// where navigator.clipboard is unavailable.
	const flashLabel = ( btn, label ) => {
		btn.dataset.label ??= btn.textContent;
		btn.textContent = label;
		setTimeout( () => {
			btn.textContent = btn.dataset.label;
		}, 1500 );
	};
	const clipboard = new window.ClipboardJS( '.zw-poll-copy-shortcode', {
		text: ( btn ) => btn.dataset.shortcode,
	} );
	clipboard.on( 'success', ( event ) => {
		event.clearSelection();
		flashLabel( event.trigger, __( 'Gekopieerd!', 'zw-poll' ) );
	} );
	clipboard.on( 'error', ( event ) => {
		flashLabel( event.trigger, __( 'Kopiëren mislukt', 'zw-poll' ) );
	} );

	// Poll screens: the title is the reader-facing question. Mirror
	// PollPostType::MAX_QUESTION_LEN so typing stops where PHP truncates.
	// Quick edit clones the #inline-edit template row, so capping the
	// template input covers every opened quick-edit row.
	if ( document.body.classList.contains( 'post-type-zw_poll' ) ) {
		document
			.querySelectorAll( '#title, #inline-edit input.ptitle' )
			.forEach( ( field ) => {
				field.maxLength = 200;
			} );
	}

	// Classic poll edit form: options repeater.
	document.querySelectorAll( '.zw-poll-edit-options' ).forEach( ( box ) => {
		const list = box.querySelector( '.zw-poll-edit-options__list' );
		const addBtn = box.querySelector( '.zw-poll-edit-options__add' );
		const template = box.parentElement.querySelector(
			'.zw-poll-edit-options__template'
		);
		const min = parseInt( box.dataset.min, 10 ) || 2;
		const max = parseInt( box.dataset.max, 10 ) || 10;
		let nextIndex = list.children.length;

		const refresh = () => {
			const rows = list.children;
			addBtn.disabled = rows.length >= max;
			Array.from( rows ).forEach( ( row, i ) => {
				row.querySelector( '.zw-poll-edit-option__move-up' ).disabled =
					i === 0;
				row.querySelector(
					'.zw-poll-edit-option__move-down'
				).disabled = i === rows.length - 1;
				row.querySelector( '.zw-poll-edit-option__remove' ).disabled =
					rows.length <= min;
				// Keep the accessible name in sync with the row position.
				const optionName = sprintf(
					/* translators: %d: answer number. */
					__( 'Antwoord %d', 'zw-poll' ),
					i + 1
				);
				const label = row.querySelector(
					'.zw-poll-edit-option__label'
				);
				label.setAttribute( 'aria-label', optionName );
				label.setAttribute( 'placeholder', optionName );
			} );
		};

		addBtn.addEventListener( 'click', () => {
			if ( list.children.length >= max ) {
				return;
			}
			const row = template.content.firstElementChild.cloneNode( true );
			row.querySelectorAll( 'input[name]' ).forEach( ( input ) => {
				input.name = input.name.replace(
					'__INDEX__',
					String( nextIndex )
				);
			} );
			nextIndex++;
			list.append( row );
			refresh();
			row.querySelector( '.zw-poll-edit-option__label' ).focus();
		} );

		list.addEventListener( 'click', ( event ) => {
			const row = event.target.closest( '.zw-poll-edit-option' );
			if ( ! row ) {
				return;
			}
			if (
				event.target.closest( '.zw-poll-edit-option__remove' ) &&
				list.children.length > min
			) {
				// Removing the focused button drops focus to <body>; hand it
				// to a neighbouring row instead (WCAG 2.4.3).
				const neighbour =
					row.nextElementSibling || row.previousElementSibling;
				row.remove();
				refresh();
				(
					neighbour?.querySelector( '.zw-poll-edit-option__label' ) ??
					addBtn
				).focus();
				return;
			}
			const moveBtn = event.target.closest(
				'.zw-poll-edit-option__move-up, .zw-poll-edit-option__move-down'
			);
			if ( ! moveBtn ) {
				return;
			}
			const isUp = moveBtn.classList.contains(
				'zw-poll-edit-option__move-up'
			);
			if ( isUp && row.previousElementSibling ) {
				row.previousElementSibling.before( row );
			} else if ( ! isUp && row.nextElementSibling ) {
				row.nextElementSibling.after( row );
			} else {
				return;
			}
			refresh();
			// A button disabled by refresh() cannot keep focus; fall back to
			// the opposite move button, then the row's label input.
			const opposite = row.querySelector(
				isUp
					? '.zw-poll-edit-option__move-down'
					: '.zw-poll-edit-option__move-up'
			);
			if ( ! moveBtn.disabled ) {
				moveBtn.focus();
			} else if ( opposite && ! opposite.disabled ) {
				opposite.focus();
			} else {
				row.querySelector( '.zw-poll-edit-option__label' ).focus();
			}
		} );

		refresh();
	} );

	// datetime-local is a wall-clock value in the WordPress site timezone.
	document
		.querySelectorAll( '.zw-poll-edit-planning__field' )
		.forEach( ( field ) => {
			const warning = field
				.closest( '.inside' )
				?.querySelector( '.zw-poll-edit-planning__warning' );
			if ( ! warning || ! window.wp?.date?.getDate ) {
				return;
			}

			const refreshWarning = () => {
				const selected = field.value
					? window.wp.date.getDate( field.value )
					: null;
				warning.hidden =
					! selected ||
					Number.isNaN( selected.getTime() ) ||
					selected.getTime() >= Date.now();
			};

			field.addEventListener( 'input', refreshWarning );
			refreshWarning();
		} );
} )();
