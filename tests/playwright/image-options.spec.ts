import { test, expect } from '@playwright/test';
import { IMAGE_DEMO_PAGE, IMAGE_FALLBACK_DEMO_PAGE, login } from './utils';

test.describe( 'poll option images', () => {
	test( 'editor selects and removes an image from the media library', async ( {
		page,
	} ) => {
		await login( page );
		await page.goto( '/wp-admin/post-new.php?post_type=zw_poll' );

		const row = page.locator( '.zw-poll-edit-option' ).first();
		await row.getByRole( 'button', { name: 'Afbeelding kiezen' } ).click();
		const modal = page.locator( '.media-modal' );
		await expect( modal ).toBeVisible();
		await modal.getByRole( 'tab', { name: 'Media Library' } ).click();
		await modal.locator( '.attachments .attachment' ).first().click();
		await modal.getByRole( 'button', { name: 'Afbeelding gebruiken' } ).click();

		await expect( row.locator( '.zw-poll-edit-option__image-id' ) ).not.toHaveValue( '0' );
		await expect( row.locator( '.zw-poll-edit-option__thumbnail' ) ).toHaveCount( 1 );
		await row.getByRole( 'button', { name: 'Afbeelding verwijderen' } ).click();
		await expect( row.locator( '.zw-poll-edit-option__image-id' ) ).toHaveValue( '0' );
		await expect( row.locator( '.zw-poll-edit-option__thumbnail' ) ).toHaveCount( 0 );
	} );

	test( 'image cards remain native labels and show images in results', async ( {
		page,
	} ) => {
		await page.goto( IMAGE_DEMO_PAGE );
		const poll = page.locator( '.zw-poll' );
		await expect( poll ).toHaveClass( /zw-poll--images/ );
		await expect( poll ).toHaveAttribute( 'data-option-count', '3' );
		await expect( poll ).toHaveCSS( '--zw-poll-image-columns', '3' );
		await page.setViewportSize( { width: 760, height: 720 } );
		await expect( poll ).toHaveCSS( '--zw-poll-image-columns', '2' );
		await page.setViewportSize( { width: 520, height: 720 } );
		await expect( poll ).toHaveCSS( '--zw-poll-image-columns', '1' );

		const options = poll.locator( '.zw-poll__option' );
		await expect( options ).toHaveCount( 3 );
		await expect( options.locator( '.zw-poll__image' ) ).toHaveCount( 3 );
		for ( const image of await options.locator( 'img' ).all() ) {
			await expect( image ).toHaveAttribute( 'alt', '' );
		}

		await options.first().locator( '.zw-poll__media' ).click();
		await expect( options.first().locator( 'input[type="radio"]' ) ).toBeChecked();
		await poll.locator( '.zw-poll__submit' ).click();

		const results = poll.locator( '.zw-poll__results' );
		await expect( results ).toBeVisible();
		await expect( results.locator( '.zw-poll__image' ) ).toHaveCount( 3 );
		await expect( results.locator( '.zw-poll__bar-value' ).first() ).toHaveText( /^\d+%$/ );
	} );

	test( 'partial image sets fall back to the text layout', async ( { page } ) => {
		await page.goto( IMAGE_FALLBACK_DEMO_PAGE );
		const poll = page.locator( '.zw-poll' );
		await expect( poll ).not.toHaveClass( /zw-poll--images/ );
		await expect( poll.locator( '.zw-poll__media' ) ).toHaveCount( 0 );
		await expect( poll.locator( 'img' ) ).toHaveCount( 0 );
	} );
} );
