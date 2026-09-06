import { test, expect } from '@playwright/test';
import { login } from './utils';

test( 'the editor stores an expired deadline for the scheduled sweep', async ( {
	page,
} ) => {
	await login( page );
	await page.goto( '/wp-admin/post-new.php?post_type=zw_poll' );

	await page.fill( '#title', 'Poll met verstreken einddatum' );
	await page.locator( '[name="zw_poll_options[0][label]"]' ).fill( 'Ja' );
	await page.locator( '[name="zw_poll_options[1][label]"]' ).fill( 'Nee' );
	await page.locator( '#zw-poll-closes-at' ).fill( '2020-01-01T12:00' );
	await expect(
		page.locator( '.zw-poll-edit-planning__warning' )
	).toBeVisible();
	await page.click( '#publish' );
	await expect( page.locator( '#message' ) ).toContainText(
		/published|gepubliceerd/i
	);

	const pollId = Number( new URL( page.url() ).searchParams.get( 'post' ) );
	expect( pollId ).toBeGreaterThan( 0 );
	const nonce = await page
		.locator( '.zw-poll-status-toggle' )
		.getAttribute( 'data-rest-nonce' );
	expect( nonce ).toBeTruthy();
	const headers = { 'X-WP-Nonce': nonce ?? '' };

	const response = await page.request.get(
		`/wp-json/wp/v2/zw-polls/${ pollId }?context=edit`,
		{ headers }
	);
	expect( response.ok() ).toBeTruthy();
	const poll = ( await response.json() ) as {
		meta: { _zw_poll_closes_at: number; _zw_poll_status: string };
	};
	expect( poll.meta._zw_poll_closes_at ).toBeLessThan(
		Math.floor( Date.now() / 1000 )
	);
	expect( poll.meta._zw_poll_status ).toBe( 'open' );
} );
