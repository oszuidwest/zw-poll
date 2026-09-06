import { test, expect } from '@playwright/test';
import { login, publishAndReopen, startNewPoll } from './utils';

test( 'the editor stores an expired deadline and keeps warning about it', async ( {
	page,
} ) => {
	await login( page );
	await startNewPoll( page, 'Poll met verstreken einddatum', [
		'Ja',
		'Nee',
	] );

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
