import { store, getContext } from '@wordpress/interactivity';

const __ = window.wp?.i18n?.__ || ( ( text ) => text );

// One page-load token makes retries and double-submits share a dedup key.
const generateToken = () => {
	const bytes = new Uint8Array( 16 );
	window.crypto.getRandomValues( bytes );
	return Array.from( bytes, ( b ) =>
		b.toString( 16 ).padStart( 2, '0' )
	).join( '' );
};

const formatNumber = ( n ) =>
	new Intl.NumberFormat(
		document.documentElement.lang?.replace( '_', '-' ) || 'nl-NL'
	).format( n );

const percentage = ( count, total ) =>
	total > 0 ? Math.round( ( count / total ) * 100 ) : 0;

// Vote cookies use the strict epoch:token:optionId format.
const votedOptionIdFromCookie = ( value, epoch ) => {
	const parts = value.split( ':' );
	const current = Number( epoch || 0 );
	if (
		parts.length !== 3 ||
		! /^\d+$/.test( parts[ 0 ] ) ||
		Number( parts[ 0 ] ) !== current
	) {
		return '';
	}
	return parts[ 2 ];
};

// setcookie() URL-encodes ":"; decode before epoch matching.
const decodeCookieValue = ( value ) => {
	try {
		return decodeURIComponent( value );
	} catch {
		return value;
	}
};

const currentVotedOptionIdFromCookie = ( ctx ) => {
	const cookieName = `${ state.cookiePrefix }${ ctx.pollId }`;
	const cookie = document.cookie
		.split( '; ' )
		.find( ( c ) => c.startsWith( `${ cookieName }=` ) );
	if ( ! cookie ) {
		return '';
	}
	return votedOptionIdFromCookie(
		decodeCookieValue( cookie.slice( cookieName.length + 1 ) ),
		ctx.voteEpoch
	);
};

const errorMessages = {
	rate_limited: __(
		'Even rustig aan — probeer over een minuutje opnieuw.',
		'zw-poll'
	),
	poll_not_found: __( 'Deze poll bestaat niet meer.', 'zw-poll' ),
	poll_closed: __( 'Deze poll is gesloten.', 'zw-poll' ),
	invalid_option: __( 'Kies eerst een optie.', 'zw-poll' ),
	already_voted: __( 'Je hebt al gestemd op deze poll.', 'zw-poll' ),
	invalid_origin: __(
		'Stemmen vanaf deze pagina is niet toegestaan.',
		'zw-poll'
	),
	vote_forbidden: __( 'Stemmen op deze poll is niet toegestaan.', 'zw-poll' ),
	insert_failed: __(
		'Stem niet opgeslagen. Probeer het later opnieuw.',
		'zw-poll'
	),
	default: __( 'Er ging iets mis. Probeer het later opnieuw.', 'zw-poll' ),
};

const errorMessage = ( code, fallback = '' ) => {
	return errorMessages[ code ] || fallback || errorMessages.default;
};

// Voting hides the focused submit; move focus to revealed live results.
const focusResults = ( poll ) => {
	const results = poll?.querySelector?.( '.zw-poll__results' );
	if ( results ) {
		window.requestAnimationFrame( () => results.focus() );
	}
};

const { state } = store( 'zw-poll', {
	// Must stay in sync with the derived-state closures in PollRenderer.php,
	// which produce the server-processed HTML for these same getters.
	state: {
		get showResults() {
			const ctx = getContext();
			return ctx.voted || ctx.closed;
		},
		get showTotalCount() {
			const ctx = getContext();
			return ctx.total >= ctx.totalMinVotes;
		},
		get cannotSubmit() {
			const ctx = getContext();
			return ctx.busy || ! ctx.selected || ctx.closed;
		},
		get barFillStyle() {
			const ctx = getContext();
			const count = ( ctx.counts && ctx.counts[ ctx.optionId ] ) || 0;
			return `width: ${ percentage( count, ctx.total ) }%`;
		},
		get barText() {
			const ctx = getContext();
			const count = ( ctx.counts && ctx.counts[ ctx.optionId ] ) || 0;
			const pct = percentage( count, ctx.total );
			return `${ pct }%`;
		},
		get totalText() {
			const ctx = getContext();
			const n = ctx.total || 0;
			/* translators: %s: total number of votes. */
			const template = __( 'Totaal aantal stemmen: %s', 'zw-poll' );
			return template.replace( '%s', formatNumber( n ) );
		},
		get isVotedOption() {
			const ctx = getContext();
			return !! ctx.votedOptionId && ctx.optionId === ctx.votedOptionId;
		},
	},

	actions: {
		select( event ) {
			getContext().selected = event.target.value;
		},
		*submit( event ) {
			const ctx = getContext();
			if ( ! ctx.selected || ctx.busy || ctx.closed ) {
				return;
			}
			const poll = event?.target?.closest?.( '.zw-poll' );
			ctx.busy = true;
			ctx.errorMessage = '';
			let voteAccepted = false;

			try {
				const res = yield fetch( state.restUrl, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					credentials: 'same-origin',
					body: JSON.stringify( {
						poll_id: ctx.pollId,
						option_id: ctx.selected,
						token: ctx.token,
					} ),
				} );
				const data = yield res.json().catch( () => ( {} ) );

				if ( ! res.ok ) {
					const code = data?.code || 'default';
					const message =
						typeof data?.message === 'string' ? data.message : '';
					if ( code === 'already_voted' ) {
						voteAccepted = true;
						ctx.errorMessage = '';
						ctx.votedOptionId =
							currentVotedOptionIdFromCookie( ctx ) ||
							ctx.selected;
						ctx.voted = true;
						focusResults( poll );
						return;
					}
					ctx.errorMessage = errorMessage( code, message );
					if ( code === 'poll_closed' ) {
						ctx.closed = true;
					}
					return;
				}

				voteAccepted = true;
				ctx.votedOptionId = ctx.selected;
				ctx.voted = true;
				if ( data.aggregate && typeof data.aggregate === 'object' ) {
					ctx.counts = { ...ctx.counts, ...data.aggregate };
				}
				if ( typeof data.total === 'number' ) {
					ctx.total = data.total;
				}
				focusResults( poll );
			} catch ( error ) {
				// eslint-disable-next-line no-console -- Field diagnostics for post-submit UI errors.
				console.error( 'zw-poll: vote submission failed', error );
				if ( ! voteAccepted ) {
					ctx.errorMessage = errorMessage( 'default' );
				}
			} finally {
				ctx.busy = false;
			}
		},
	},

	callbacks: {
		init() {
			const ctx = getContext();
			const votedOptionId = currentVotedOptionIdFromCookie( ctx );
			if ( votedOptionId ) {
				ctx.voted = true;
				ctx.votedOptionId = votedOptionId;
			}
			if ( ! ctx.token ) {
				ctx.token = generateToken();
			}
		},
	},
} );
