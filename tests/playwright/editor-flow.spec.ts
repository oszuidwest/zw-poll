import { test, expect, Page } from '@playwright/test';
import { login } from './utils';

/**
 * Publishes or updates a poll and reopens its edit screen in a fresh page.
 *
 * After the classic publish redirect chain, headless Chromium stops
 * producing animation frames for the page, which wedges every later
 * Playwright click on its actionability (stability) checks — even across
 * goto/reload. Only a new page gets a healthy renderer again, so callers
 * must continue on the returned page.
 */
async function publishAndReopen( page: Page ): Promise< Page > {
	await page.click( '#publish' );
	await page.waitForURL( /post\.php\?post=\d+&action=edit/ );
	const postId = new URL( page.url() ).searchParams.get( 'post' );
	const fresh = await page.context().newPage();
	await page.close();
	await fresh.goto( `/wp-admin/post.php?post=${ postId }&action=edit` );
	return fresh;
}

/**
 * Covers the custom poll admin surface against the seeded demo poll.
 *
 * Block-editor insertion is intentionally excluded: it is slow and flaky to
 * drive, while these assertions cover the plugin-owned admin UI.
 */
test.describe( 'Admin / poll-beheer', () => {
	test.beforeEach( async ( { page } ) => {
		await login( page );
	} );

	test( 'de poll-lijst toont de custom kolommen en de demo-poll', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/edit.php?post_type=zw_poll' );

		// PollAdminColumns owns these list-table columns; the title column
		// is relabeled because the poll title is the reader-facing question.
		await expect( page.locator( 'th#title' ) ).toContainText( 'Vraag' );
		await expect( page.locator( 'th#poll_status' ) ).toBeVisible();
		await expect( page.locator( 'th#votes' ) ).toBeVisible();
		await expect( page.locator( 'th#usage' ) ).toBeVisible();
		await expect( page.locator( 'th#shortcode' ) ).toBeVisible();

		const row = page.locator( 'tr', {
			has: page.getByText( 'Wat drink jij het liefst op de redactie?' ),
		} );
		await expect( row ).toBeVisible();
		await expect( row.locator( '.zw-poll-status-badge' ) ).toContainText(
			/open/i
		);
		await expect( row.locator( 'code' ) ).toContainText(
			/\[zw_poll id="\d+"\]/
		);
	} );

	test( 'redacteur maakt een poll via het klassieke formulier', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/post-new.php?post_type=zw_poll' );
		// The title is the reader-facing question; there is no separate field.
		await page.fill( '#title', 'Vind je dit formulier handig?' );

		const labels = page.locator( '.zw-poll-edit-option__label' );
		await labels.nth( 0 ).fill( 'Ja' );
		await labels.nth( 1 ).fill( 'Nee' );
		await page.click( '.zw-poll-edit-options__add' );
		await labels.nth( 2 ).fill( 'Geen mening' );

		const editor = await publishAndReopen( page );
		const saved = editor.locator( '.zw-poll-edit-option__label' );

		await expect( editor.locator( '#title' ) ).toHaveValue(
			'Vind je dit formulier handig?'
		);
		await expect( saved ).toHaveCount( 3 );
		await expect( saved.nth( 2 ) ).toHaveValue( 'Geen mening' );

		await expect( editor.locator( '.notice-warning' ) ).toHaveCount( 0 );

		await expect(
			editor.locator( '#zw-poll-edit-shortcode-value' )
		).toHaveValue( /\[zw_poll id="\d+"\]/ );
		await expect( editor.locator( '.zw-poll-status-badge' ) ).toContainText(
			/open/i
		);
	} );

	test( 'gepubliceerde poll zonder opties toont de onvolledig-waarschuwing', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/post-new.php?post_type=zw_poll' );
		await page.fill( '#title', 'Poll zonder opties?' );

		const editor = await publishAndReopen( page );

		await expect( editor.locator( '.notice-warning' ) ).toContainText(
			'onvolledig'
		);
	} );

	test( 'REST: een poll met titel en opties rendert de vraag veilig', async ( {
		page,
	} ) => {
		// The Status meta box on the demo poll carries a wp_rest nonce.
		await page.goto( '/wp-admin/edit.php?post_type=zw_poll' );
		await page
			.getByRole( 'link', {
				name: 'Wat drink jij het liefst op de redactie?',
			} )
			.first()
			.click();
		const nonce = await page
			.locator( '.zw-poll-status-toggle' )
			.getAttribute( 'data-rest-nonce' );

		const question = 'Is 1 < 2 en 3 > 2?';

		// The title is the question; this is the payload shape REST clients
		// (and the classic-editor poll picker) rely on.
		const created = await page.request.post( '/wp-json/wp/v2/zw-polls', {
			headers: { 'X-WP-Nonce': nonce ?? '' },
			data: {
				title: question,
				status: 'publish',
				meta: {
					_zw_poll_options: [ { label: 'Ja' }, { label: 'Nee' } ],
				},
			},
		} );
		expect( created.ok() ).toBeTruthy();
		const poll = await created.json();
		expect( poll.title.raw ).toBe( question );

		await page.goto( `/wp-admin/post.php?post=${ poll.id }&action=edit` );
		await expect( page.locator( '.notice-warning' ) ).toHaveCount( 0 );

		const pageCreated = await page.request.post( '/wp-json/wp/v2/pages', {
			headers: { 'X-WP-Nonce': nonce ?? '' },
			data: {
				title: 'REST poll frontend check',
				status: 'publish',
				content: `[zw_poll id="${ poll.id }"]`,
			},
		} );
		expect( pageCreated.ok() ).toBeTruthy();
		const frontendPage = await pageCreated.json();

		await page.goto( frontendPage.link );
		await expect( page.locator( '.zw-poll__question' ) ).toHaveText(
			question
		);
	} );

	test( 'bewerken behoudt bestaande option-IDs', async ( { page } ) => {
		// Votes are keyed by option ID: a save that regenerates IDs would
		// silently orphan cast votes. Use a fresh poll so the test does not
		// depend on shared seed state.
		await page.goto( '/wp-admin/post-new.php?post_type=zw_poll' );
		await page.fill( '#title', 'Blijven IDs behouden?' );

		const labels = page.locator( '.zw-poll-edit-option__label' );
		await labels.nth( 0 ).fill( 'Eerste' );
		await labels.nth( 1 ).fill( 'Tweede' );
		const editor = await publishAndReopen( page );

		const hiddenIds =
			'.zw-poll-edit-options__list input[type="hidden"][name$="[id]"]';
		const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/;
		await expect( editor.locator( hiddenIds ) ).toHaveCount( 2 );
		const idsBefore = await editor
			.locator( hiddenIds )
			.evaluateAll( ( els ) =>
				els.map( ( el ) => ( el as HTMLInputElement ).value )
			);
		for ( const id of idsBefore ) {
			expect( id ).toMatch( uuid );
		}

		const savedLabels = editor.locator( '.zw-poll-edit-option__label' );
		await savedLabels.nth( 0 ).fill( 'Eerste (hernoemd)' );
		await editor
			.locator( '.zw-poll-edit-option__move-down' )
			.first()
			.click();
		await editor.click( '.zw-poll-edit-options__add' );
		await savedLabels.nth( 2 ).fill( 'Derde' );
		const reopened = await publishAndReopen( editor );

		const finalLabels = reopened.locator( '.zw-poll-edit-option__label' );
		await expect( finalLabels.nth( 0 ) ).toHaveValue( 'Tweede' );
		await expect( finalLabels.nth( 1 ) ).toHaveValue(
			'Eerste (hernoemd)'
		);
		await expect( finalLabels.nth( 2 ) ).toHaveValue( 'Derde' );

		const idsAfter = await reopened
			.locator( hiddenIds )
			.evaluateAll( ( els ) =>
				els.map( ( el ) => ( el as HTMLInputElement ).value )
			);
		expect( idsAfter[ 0 ] ).toBe( idsBefore[ 1 ] );
		expect( idsAfter[ 1 ] ).toBe( idsBefore[ 0 ] );
		expect( idsAfter[ 2 ] ).toMatch( uuid );
		expect( idsBefore ).not.toContain( idsAfter[ 2 ] );
	} );
} );
