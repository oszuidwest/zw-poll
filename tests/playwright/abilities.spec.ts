import { test, expect } from '@playwright/test';
import { login, startNewPoll, publishAndReopen } from './utils';

test( 'abilities zijn vindbaar en de resetknop gebruikt reset-votes', async ( {
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

	const discovery = await page.request.get( '/wp-json/wp-abilities/v1/abilities?per_page=100', { headers } );
	const names = ( await discovery.json() ).map( ( ability: { name: string } ) => ability.name );
	expect( names.filter( ( name: string ) => name.startsWith( 'zw-poll/' ) ) ).toHaveLength( 6 );
	expect( ( await page.request.get( `${ base }list-polls/run`, { headers } ) ).ok() ).toBeTruthy();
	const results = await page.request.get( `${ base }get-results/run`, { headers, params: { 'input[id]': id } } );
	expect( ( await results.json() ).total ).toBe( 1 );
	const anonymous = await request.post( `${ base }reset-votes/run`, { data: { input: { id, confirm: true } } } );
	expect( anonymous.status() ).toBe( 401 );

	await page.reload();
	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await page.getByRole( 'button', { name: 'Alle stemmen verwijderen…' } ).click();
	await expect( page.getByText( 'Totaal aantal stemmen: 0' ) ).toBeVisible();
} );
