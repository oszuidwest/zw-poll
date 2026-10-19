import { test, expect } from '@playwright/test';
import { login, startNewPoll, publishAndReopen } from './utils';

test( 'abilities valideren invoer, beschermen beheer en hergebruiken onderhoud', async ( {
	page,
	request,
} ) => {
	await login( page );
	await startNewPoll( page, 'Abilities onderhoud', [ 'Ja', 'Nee' ] );
	page = await publishAndReopen( page );
	const id = Number( new URL( page.url() ).searchParams.get( 'post' ) );
	const nonce = await page
		.locator( '[data-rest-nonce]' )
		.first()
		.getAttribute( 'data-rest-nonce' );
	const headers = { 'X-WP-Nonce': nonce ?? '' };
	const base = '/wp-json/wp-abilities/v1/abilities/zw-poll/';
	const poll = await ( await page.request.get( `/wp-json/wp/v2/zw-polls/${ id }`, { headers } ) ).json();
	const voted = await request.post( '/wp-json/zw-poll/v1/vote', {
		headers: { Origin: new URL( page.url() ).origin },
		data: { poll_id: id, option_id: poll.meta._zw_poll_options[ 0 ].id },
	} );
	expect( voted.ok(), await voted.text() ).toBeTruthy();

	const discovery = await page.request.get(
		'/wp-json/wp-abilities/v1/abilities?per_page=100',
		{ headers }
	);
	expect( discovery.ok() ).toBeTruthy();
	const abilities = ( await discovery.json() ).filter(
		( ability: { name: string } ) => ability.name.startsWith( 'zw-poll/' )
	);
	expect( abilities ).toHaveLength( 6 );
	const reset = abilities.find( ( ability: { name: string } ) =>
		ability.name.endsWith( '/reset-votes' )
	);
	expect( reset.meta.annotations ).toMatchObject( {
		readonly: false,
		destructive: true,
		idempotent: false,
	} );
	const listed = await page.request.get( `${ base }list-polls/run`, {
		headers,
		params: { 'input[search]': 'Abilities onderhoud', 'input[per_page]': 100 },
	} );
	expect( listed.ok(), await listed.text() ).toBeTruthy();
	expect( ( await listed.json() ).map( ( item: { id: number } ) => item.id ) ).toContain( id );

	const run = ( name: string, input: object ) =>
		page.request.post( `${ base }${ name }/run`, {
			headers,
			data: { input },
		} );
	for ( const input of [
		{ id },
		{ id: -1, confirm: true },
		{ id: 1.5, confirm: true },
		{ id: 'not-a-number', confirm: true },
		{ id, confirm: false },
		{ id, confirm: true, unknown: true },
	] ) {
		expect( ( await run( 'reset-votes', input ) ).status() ).toBe( 400 );
	}
	expect(
		(
			await request.post( `${ base }reset-votes/run`, {
				data: { input: { id, confirm: true } },
			} )
		).status()
	).toBe( 401 );
	const results = await page.request.get( `${ base }get-results/run`, {
		headers,
		params: { 'input[id]': id },
	} );
	expect( results.ok(), await results.text() ).toBeTruthy();
	expect( ( await results.json() ).total ).toBe( 1 );
	expect(
		( await ( await run( 'set-status', { id, status: 'closed' } ) ).json() )
			.status
	).toBe( 'closed' );
	expect(
		( await ( await run( 'set-status', { id, status: 'open' } ) ).json() )
			.status
	).toBe( 'open' );
	expect( ( await run( 'rebuild-aggregate', { id } ) ).ok() ).toBeTruthy();
	const cleared = await run( 'reset-votes', { id, confirm: true } );
	expect( cleared.ok() ).toBeTruthy();
	expect( await cleared.json() ).toMatchObject( {
		deleted: 1,
		results: { id, total: 0 },
	} );
	expect( ( await run( 'close-expired', {} ) ).ok() ).toBeTruthy();
} );

test( 'aangemelde abonnees mogen geen poll-abilities uitvoeren', async ( {
	page,
	browser,
} ) => {
	await login( page );
	const nonce = await ( await page.request.get(
		'/wp-admin/admin-ajax.php?action=rest-nonce'
	) ).text();
	const headers = { 'X-WP-Nonce': nonce };
	const username = `abilities-subscriber-${ Date.now() }`;
	const password = 'Abilities subscriber test password';
	const created = await page.request.post( '/wp-json/wp/v2/users', {
		headers,
		data: {
			username,
			password,
			email: `${ username }@example.test`,
			roles: [ 'subscriber' ],
		},
	} );
	expect( created.ok(), await created.text() ).toBeTruthy();
	const user = await created.json();
	const subscriber = await browser.newContext( {
		baseURL: new URL( page.url() ).origin,
	} );
	try {
		const profile = await subscriber.newPage();
		await profile.goto( '/wp-login.php' );
		await profile.fill( '#user_login', username );
		await profile.fill( '#user_pass', password );
		await profile.click( '#wp-submit' );
		await expect( profile ).toHaveURL( /wp-admin/ );
		const subscriberNonce = await ( await subscriber.request.get(
			'/wp-admin/admin-ajax.php?action=rest-nonce'
		) ).text();
		const polls = await ( await page.request.get( '/wp-json/wp/v2/zw-polls', {
			headers,
		} ) ).json();
		const id = polls[ 0 ].id;
		for ( const [ name, input, readonly ] of [
			[ 'list-polls', {}, true ],
			[ 'get-results', { id }, true ],
			[ 'set-status', { id, status: 'closed' }, false ],
			[ 'rebuild-aggregate', { id }, false ],
			[ 'reset-votes', { id, confirm: true }, false ],
			[ 'close-expired', {}, false ],
		] as const ) {
			const url = `/wp-json/wp-abilities/v1/abilities/zw-poll/${ name }/run`;
			const auth = { 'X-WP-Nonce': subscriberNonce };
			const denied = readonly
				? await subscriber.request.get( url, {
					headers: auth,
					params: name === 'get-results' ? { 'input[id]': id } : { 'input[page]': 1 },
				} )
				: await subscriber.request.post( url, {
					headers: auth,
					data: { input },
				} );
			expect( denied.status(), `${ name }: ${ await denied.text() }` ).toBe( 403 );
		}
	} finally {
		await subscriber.close();
		await page.request.delete( `/wp-json/wp/v2/users/${ user.id }`, {
			headers,
			data: { force: true, reassign: 1 },
		} );
	}
} );
