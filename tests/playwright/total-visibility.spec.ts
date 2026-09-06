import { test, expect } from '@playwright/test';
import { login, THRESHOLD_DEMO_PAGE } from './utils';

test.describe( 'Zichtbaarheid stemtotaal', () => {
	test.beforeEach( async ( { page } ) => {
		const admin = await page.context().newPage();
		await login( admin );
		await admin.goto( '/wp-admin/edit.php?post_type=zw_poll' );
		await admin
			.getByRole( 'link', { name: 'Waar lees jij nieuws?', exact: true } )
			.first()
			.click();

		const pollId = new URL( admin.url() ).searchParams.get( 'post' );
		const nonce = await admin
			.locator( '.zw-poll-status-toggle' )
			.getAttribute( 'data-rest-nonce' );
		expect( pollId ).toMatch( /^\d+$/ );
		expect( nonce ).toBeTruthy();

		const reset = await admin.request.post(
			`/wp-json/zw-poll/v1/poll/${ pollId }/reset`,
			{ headers: { 'X-WP-Nonce': nonce ?? '' } }
		);
		expect( reset.ok() ).toBeTruthy();
		await admin.close();
	} );

	test( 'toont het totaal direct wanneer een stem de drempel bereikt', async ( {
		page,
	} ) => {
		await page.goto( THRESHOLD_DEMO_PAGE );

		const poll = page.locator( '.zw-poll' );
		const total = poll.locator( '.zw-poll__total' );
		await expect( total ).toBeHidden();

		await poll.locator( 'input[type="radio"]' ).first().check();
		await poll.locator( '.zw-poll__submit' ).click();

		await expect( poll.locator( '.zw-poll__results' ) ).toBeVisible();
		await expect( total ).toBeVisible();
		await expect( total ).toHaveText( 'Totaal aantal stemmen: 1' );
	} );
} );
