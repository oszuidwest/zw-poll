import { test, expect, Page } from '@playwright/test';
import { login } from './utils';

function captureErrors( page: Page ): string[] {
	const errors: string[] = [];
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );
	page.on( 'console', ( message ) => {
		if ( message.type() === 'error' ) {
			errors.push( message.text() );
		}
	} );
	return errors;
}

async function selectReplacement( page: Page ) {
	await page.evaluate( () => {
		const editor = ( window as any ).tinymce.activeEditor;
		editor.setContent( '<p>Voor Vervangen Na</p>' );
		editor.focus();
		const text = editor.getBody().firstChild.firstChild;
		const range = editor.getDoc().createRange();
		range.setStart( text, 5 );
		range.setEnd( text, 14 );
		editor.selection.setRng( range );
	} );
}

test.describe( 'Picker keyboard and iframe compatibility', () => {
	test.beforeEach( async ( { page } ) => {
		await login( page );
	} );

	test( 'Classic block inserts and previews a poll in the WordPress 7.1 iframe', async ( {
		page,
	} ) => {
		const errors = captureErrors( page );
		await page.goto(
			'/wp-admin/post-new.php?block-editor&classic-editor__forget'
		);
		await page.waitForFunction( () =>
			Boolean(
				( window as any ).wp?.data
					?.select( 'core/editor' )
					?.getCurrentPostId()
			)
		);
		await page.evaluate( () => {
			const wp = ( window as any ).wp;
			wp.data
				.dispatch( 'core/preferences' )
				.set( 'core/edit-post', 'welcomeGuide', false );
			wp.data
				.dispatch( 'core/block-editor' )
				.resetBlocks( [ wp.blocks.createBlock( 'core/freeform' ) ] );
		} );
		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await expect( canvas.locator( '.wp-block-freeform' ) ).toBeVisible();
		await canvas.locator( '.wp-block-freeform' ).click();
		await canvas.getByRole( 'button', { name: 'Edit contents' } ).click();
		const trigger = page.locator(
			'.mce-toolbar-grp [aria-label="Poll invoegen"]'
		);
		await expect( trigger ).toBeVisible();
		await selectReplacement( page );
		await trigger.focus();
		await trigger.press( 'Enter' );
		const picker = page.locator( '.zw-poll-picker' );
		const search = picker.locator( '#zw-poll-picker-search' );
		await expect( search ).toBeFocused();
		await picker.getByRole( 'button', { name: 'Annuleren' } ).focus();
		await page.keyboard.press( 'Enter' );
		await expect( picker ).toBeHidden();
		await expect( trigger ).toBeFocused();
		await trigger.press( 'Enter' );
		await search.press( 'Escape' );
		await expect( picker ).toBeHidden();
		await expect(
			page.getByRole( 'dialog', { name: 'Classic Editor', exact: true } )
		).toBeVisible();
		await expect( trigger ).toBeFocused();
		await trigger.press( 'Enter' );
		await search.fill( 'redactie' );
		const match = picker.locator( '.zw-poll-picker__poll' );
		await expect( match ).toHaveCount( 1 );
		const id = await match.getAttribute( 'data-poll-id' );
		await search.press( 'Tab' );
		await expect( match ).toBeFocused();
		await match.press( 'Enter' );
		await expect( picker ).toBeHidden();
		const editor = page.frameLocator( 'iframe[id$="_ifr"]' );
		await expect( editor.locator( '.zw-poll-mce' ) ).toContainText(
			/op de redactie/i
		);
		await expect( editor.locator( 'body' ) ).toBeFocused();
		const content = await page.evaluate( () =>
			( window as any ).tinymce.activeEditor.getContent()
		);
		expect( content ).toContain( `[zw_poll id="${ id }"]` );
		expect( content ).toMatch( /Voor[\s\S]*\[zw_poll[\s\S]*Na/ );
		expect( content ).not.toContain( 'Vervangen' );
		await page.screenshot( {
			path: test.info().outputPath( 'classic-block-picker-preview.png' ),
		} );
		expect( errors ).toEqual( [] );
	} );

	test( 'Classic Editor TinyMCE restores selection and focus after keyboard insertion', async ( {
		page,
	} ) => {
		const errors = captureErrors( page );
		await page.goto(
			'/wp-admin/post-new.php?classic-editor&classic-editor__forget'
		);
		await page.locator( '#content-tmce' ).click();
		const trigger = page.locator(
			'.mce-toolbar-grp [aria-label="Poll invoegen"]'
		);
		await expect( trigger ).toBeVisible();
		await selectReplacement( page );
		await trigger.focus();
		await trigger.press( 'Enter' );
		const picker = page.locator( '.zw-poll-picker' );
		const search = picker.locator( '#zw-poll-picker-search' );
		await expect( search ).toBeFocused();
		await search.press( 'Escape' );
		await expect( trigger ).toBeFocused();
		await trigger.press( 'Enter' );
		await search.fill( 'redactie' );
		const match = picker.locator( '.zw-poll-picker__poll' );
		await expect( match ).toHaveCount( 1 );
		await search.press( 'Tab' );
		await match.press( 'Enter' );
		const editor = page.frameLocator( '#content_ifr' );
		await expect( editor.locator( '.zw-poll-mce' ) ).toContainText(
			/op de redactie/i
		);
		await expect( editor.locator( 'body' ) ).toBeFocused();
		const content = await page.evaluate( () =>
			( window as any ).tinymce.activeEditor.getContent()
		);
		expect( content ).toMatch(
			/Voor[\s\S]*\[zw_poll id="\d+"\][\s\S]*Na/
		);
		expect( content ).not.toContain( 'Vervangen' );
		expect( errors ).toEqual( [] );
	} );

	test( 'Classic Editor preserves the caret and restores keyboard focus', async ( {
		page,
	} ) => {
		const errors = captureErrors( page );
		await page.goto(
			'/wp-admin/post-new.php?classic-editor&classic-editor__forget'
		);
		await page.locator( '#content-html' ).click();
		const content = page.locator( '#content' );
		await content.fill( 'Voor Na' );
		await content.evaluate( ( textarea: HTMLTextAreaElement ) =>
			textarea.setSelectionRange( 5, 5 )
		);
		const trigger = page.locator( '#qt_content_zw_poll' );
		await trigger.focus();
		await trigger.press( 'Enter' );
		const dialog = page.locator( '.zw-poll-picker' );
		const search = dialog.locator( '#zw-poll-picker-search' );
		await expect( search ).toBeFocused();
		await search.press( 'Escape' );
		await expect( dialog ).toBeHidden();
		await expect( trigger ).toBeFocused();
		await trigger.press( 'Enter' );
		await search.fill( 'redactie' );
		await search.press( 'Enter' );
		await expect( dialog ).toBeVisible();
		const poll = dialog.locator( '.zw-poll-picker__poll' );
		await expect( poll ).toHaveCount( 1 );
		const id = await poll.getAttribute( 'data-poll-id' );
		await search.press( 'Tab' );
		await expect( poll ).toBeFocused();
		await poll.press( 'Enter' );
		await expect( dialog ).toBeHidden();
		await expect( content ).toHaveValue( `Voor [zw_poll id="${ id }"]Na` );
		await expect( content ).toBeFocused();
		expect( errors ).toEqual( [] );
	} );
} );
