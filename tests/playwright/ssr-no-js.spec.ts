import { test, expect } from '@playwright/test';
import { CLOSED_DEMO_PAGE, DEMO_PAGE } from './utils';

/**
 * Server rendering contract: WordPress processes the Interactivity directives
 * on the server, so the HTML must be complete and correct before (or without)
 * JavaScript. This is also exactly what full-page caches store.
 *
 * The derived-state closures in PollRenderer.php are the only server-side
 * source of these values, and this is the only suite that runs real directive
 * processing — assert every element bound to derived state here.
 */
test.use( { javaScriptEnabled: false } );

test.describe( 'Server-rendering zonder JavaScript', () => {
	test( 'open poll toont het stemformulier met disabled knop en gevulde percentages', async ( {
		page,
	} ) => {
		await page.goto( DEMO_PAGE );

		const poll = page.locator( '.zw-poll' );
		await expect( poll ).toBeVisible();
		await expect( poll.locator( '.zw-poll__form' ) ).toBeVisible();
		await expect( poll.locator( '.zw-poll__submit' ) ).toBeDisabled();
		await expect( poll.locator( '.zw-poll__results' ) ).toBeHidden();

		// Directive processing must fill the percentages, not wipe them.
		for ( const value of await poll
			.locator( '.zw-poll__bar-value' )
			.all() ) {
			await expect( value ).toHaveText( /^\d+%$/ );
		}
		await expect( poll.locator( '.zw-poll__total' ) ).toHaveText(
			/Totaal aantal stemmen: \d/
		);
	} );

	test( 'gesloten poll toont de einduitslag en verbergt het stemformulier', async ( {
		page,
	} ) => {
		await page.goto( CLOSED_DEMO_PAGE );

		const poll = page.locator( '.zw-poll' );
		await expect( poll ).toBeVisible();
		await expect( poll.locator( '.zw-poll__form' ) ).toBeHidden();

		const results = poll.locator( '.zw-poll__results' );
		await expect( results ).toBeVisible();
		await expect( results.locator( '.zw-poll__final' ) ).toHaveText(
			'Einduitslag'
		);
		// Seeded aggregate: 3 vs 2 votes (see playground/blueprint.json).
		await expect(
			results.locator( '.zw-poll__bar-value' ).first()
		).toHaveText( '60%' );
		await expect(
			results.locator( '.zw-poll__bar-value' ).nth( 1 )
		).toHaveText( '40%' );
		await expect( results.locator( '.zw-poll__total' ) ).toHaveText(
			'Totaal aantal stemmen: 5'
		);

		const fill = results.locator( '.zw-poll__bar-fill' ).first();
		await expect( fill ).toHaveAttribute( 'style', /width:\s*60%/ );
	} );
} );
