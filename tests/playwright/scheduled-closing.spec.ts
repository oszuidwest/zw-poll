import { test, expect } from '@playwright/test';
import { login, publishAndReopen } from './utils';

test( 'the editor stores an expired deadline and keeps warning about it', async ( {
	page,
} ) => {
	await login( page );
	await page.goto( '/wp-admin/post-new.php?post_type=zw_poll' );

	await page.fill( '#title', 'Poll met verstreken einddatum' );
	await page.locator( '[name="zw_poll_options[0][label]"]' ).fill( 'Ja' );
	await page.locator( '[name="zw_poll_options[1][label]"]' ).fill( 'Nee' );

	const warning = page.locator( '.zw-poll-edit-planning__warning' );
	await expect( warning ).toBeHidden();
	await page.locator( '#zw-poll-closes-at' ).fill( '2020-01-01T12:00' );
	await expect( warning ).toBeVisible();

	// The stored UTC timestamp renders back as the same site wall-clock value.
	const editor = await publishAndReopen( page );
	await expect( editor.locator( '#zw-poll-closes-at' ) ).toHaveValue(
		'2020-01-01T12:00'
	);
	await expect(
		editor.locator( '.zw-poll-edit-planning__warning' )
	).toBeVisible();
	await expect( editor.locator( '.zw-poll-status-badge' ) ).toContainText(
		/open/i
	);
} );
