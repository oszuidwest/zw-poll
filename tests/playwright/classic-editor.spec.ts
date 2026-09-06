import { test, expect } from '@playwright/test';
import { login, NO_RICH_EDIT_UA } from './utils';

// Editor selection stays explicit instead of relying on the Playground default.

test.describe( 'Classic editor (TinyMCE)', () => {
	test.beforeEach( async ( { page } ) => {
		await login( page );
	} );

	test( 'redacteur voegt een poll in via de picker en ziet de preview', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/post-new.php?classic-editor&classic-editor__forget' );
		await page.fill( '#title', 'Classic post met poll' );

		await page.click( '#content-tmce' );

		// The TinyMCE toolbar is the only Visual-mode entry; there is no
		// media-row entry. The Quicktags button shares the aria-label, so
		// scope the click to the TinyMCE toolbar.
		await expect( page.locator( '.zw-poll-insert' ) ).toHaveCount( 0 );
		await page
			.locator( '.mce-toolbar-grp [aria-label="Poll invoegen"]' )
			.click();

		const dialog = page.locator( '.zw-poll-picker' );
		await expect( dialog ).toBeVisible();
		await expect(
			dialog.locator( '.zw-poll-picker__poll' ).first()
		).toBeVisible();

		await dialog.locator( '#zw-poll-picker-search' ).fill( 'redactie' );
		const match = dialog.locator( '.zw-poll-picker__poll' );
		await expect( match ).toHaveCount( 1 );
		await expect( match ).toContainText(
			'Wat drink jij het liefst op de redactie?'
		);
		const pollId = await match.getAttribute( 'data-poll-id' );
		expect( pollId ).toMatch( /^\d+$/ );
		await match.click();

		await expect( dialog ).toBeHidden();
		await page.click( '#content-html' );
		await expect( page.locator( '#content' ) ).toHaveValue(
			new RegExp( '\\[zw_poll id="' + pollId + '"\\]' )
		);

		await expect(
			page.locator( '#qt_content_zw_poll' )
		).toBeVisible();

		await page.click( '#content-tmce' );
		const preview = page
			.frameLocator( '#content_ifr' )
			.locator( '.zw-poll-mce' );
		await expect( preview ).toBeVisible();
		await expect( preview ).toContainText( /op de redactie/i );
	} );

	test( 'gepubliceerde classic post toont de poll op de frontend', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/post-new.php?classic-editor&classic-editor__forget' );
		await page.fill( '#title', 'Classic post frontend check' );
		await page.click( '#content-tmce' );
		await page
			.locator( '.mce-toolbar-grp [aria-label="Poll invoegen"]' )
			.click();

		const dialog = page.locator( '.zw-poll-picker' );
		await dialog.locator( '#zw-poll-picker-search' ).fill( 'redactie' );
		const match = dialog.locator( '.zw-poll-picker__poll' );
		await expect( match ).toHaveCount( 1 );
		await match.click();

		await page.click( '#publish' );
		const viewLink = page.locator( '#message a' ).first();
		await expect( viewLink ).toBeVisible();
		await page.goto( ( await viewLink.getAttribute( 'href' ) ) ?? '' );

		const poll = page.locator( '.zw-poll' );
		await expect( poll ).toBeVisible();
		await expect( poll.locator( '.zw-poll__question' ) ).toContainText(
			/op de redactie/i
		);
	} );
} );

/**
 * Core only applies mce_buttons and mce_external_plugins when
 * user_can_richedit() is true, so without the visual editor the TinyMCE
 * toolbar button never exists. The Quicktags button must cover it.
 */
test.describe( 'Classic editor zonder visuele editor', () => {
	test.use( { userAgent: NO_RICH_EDIT_UA } );

	test.beforeEach( async ( { page } ) => {
		await login( page );
	} );

	test( 'redacteur voegt een poll in via de Quicktags-knop', async ( {
		page,
	} ) => {
		await page.goto(
			'/wp-admin/post-new.php?classic-editor&classic-editor__forget'
		);
		await page.fill( '#title', 'Classic post zonder visuele editor' );

		await expect( page.locator( '#content-tmce' ) ).toHaveCount( 0 );
		await expect( page.locator( 'i.mce-i-zw-poll' ) ).toHaveCount( 0 );

		await page.click( '#qt_content_zw_poll' );
		const dialog = page.locator( '.zw-poll-picker' );
		await expect( dialog ).toBeVisible();

		await dialog.locator( '#zw-poll-picker-search' ).fill( 'redactie' );
		const match = dialog.locator( '.zw-poll-picker__poll' );
		await expect( match ).toHaveCount( 1 );
		const pollId = await match.getAttribute( 'data-poll-id' );
		expect( pollId ).toMatch( /^\d+$/ );
		await match.click();

		await expect( dialog ).toBeHidden();
		await expect( page.locator( '#content' ) ).toHaveValue(
			new RegExp( '\\[zw_poll id="' + pollId + '"\\]' )
		);
	} );
} );
