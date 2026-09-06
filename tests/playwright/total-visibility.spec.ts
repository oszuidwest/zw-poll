import { test, expect } from '@playwright/test';
import { THRESHOLD_DEMO_PAGE } from './utils';

test.describe( 'Zichtbaarheid stemtotaal', () => {
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
