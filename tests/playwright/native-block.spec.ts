import { test, expect } from '@playwright/test';
import { login } from './utils';

test( 'poll block: inserter, preview, publish, voting and usage cleanup', async ( {
	page,
	browser,
}, testInfo ) => {
	test.setTimeout( 120_000 );
	page.setDefaultTimeout( 15_000 );
	await login( page );
	await page.goto( '/wp-admin/edit.php?post_type=zw_poll' );
	await page
		.getByRole( 'link', {
			name: 'Wat drink jij het liefst op de redactie?',
			exact: true,
		} )
		.click();
	const nonce = await page
		.locator( '.zw-poll-status-toggle' )
		.getAttribute( 'data-rest-nonce' );
	const headers = { 'X-WP-Nonce': nonce ?? '' };
	const created = await page.request.post( '/wp-json/wp/v2/zw-polls', {
		headers,
		data: {
			title: 'Werkt het native pollblok?',
			status: 'publish',
			meta: {
				_zw_poll_options: [ { label: 'Zeker' }, { label: 'Nog niet' } ],
				_zw_poll_total_visibility: 'show',
			},
		},
	} );
	expect( created.ok() ).toBeTruthy();
	const poll = await created.json();
	await page.goto(
		'/wp-admin/post-new.php?block-editor&classic-editor__forget'
	);
	await page.waitForFunction( () =>
		( window as any ).wp?.data?.select( 'core/editor' ).getCurrentPostId()
	);
	await page.evaluate( () =>
		( window as any ).wp.data
			.dispatch( 'core/preferences' )
			.set( 'core/edit-post', 'welcomeGuide', false )
	);
	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	await canvas
		.locator( '.editor-post-title__input' )
		.fill( 'Native poll block E2E' );
	await page.getByRole( 'button', { name: 'Block Inserter' } ).click();
	await page.getByRole( 'searchbox', { name: 'Search' } ).fill( 'Poll' );
	await page
		.locator( '.block-editor-block-types-list__item' )
		.filter( { hasText: /^Poll$/ } )
		.click();
	await expect(
		canvas.getByText( 'Vul een poll-ID in', { exact: false } )
	).toBeVisible();
	await page.getByRole( 'tab', { name: 'Block', exact: true } ).click();
	const pollControl = page.getByRole( 'spinbutton', { name: 'Poll-ID' } );
	await pollControl.fill( '999999' );
	await expect( canvas.locator( '.zw-poll--missing' ) ).toContainText(
		'Poll niet beschikbaar'
	);
	for ( const status of [ 'draft', 'publish' ] ) {
		const unavailableResponse = await page.request.post(
			'/wp-json/wp/v2/zw-polls',
			{
				headers,
				data: { title: 'Poll zonder antwoorden', status },
			}
		);
		expect( unavailableResponse.ok() ).toBeTruthy();
		const unavailable = await unavailableResponse.json();
		await pollControl.fill( String( unavailable.id ) );
		await expect(
			canvas.locator(
				status === 'draft'
					? '.zw-poll--missing'
					: '.zw-poll--incomplete'
			)
		).toBeVisible();
	}
	await page
		.getByRole( 'spinbutton', { name: 'Poll-ID' } )
		.fill( String( poll.id ) );
	await expect( canvas.locator( '.zw-poll__question' ) ).toHaveText(
		poll.title.raw
	);
	await page
		.getByRole( 'button', { name: 'Close Block Inserter', exact: true } )
		.click();
	await page.screenshot( {
		path: testInfo.outputPath( 'poll-block-editor.png' ),
		fullPage: true,
	} );
	await testInfo.attach( 'Poll block in editor', {
		path: testInfo.outputPath( 'poll-block-editor.png' ),
		contentType: 'image/png',
	} );
	await page.getByRole( 'button', { name: 'Publish', exact: true } ).click();
	await page
		.getByRole( 'region', { name: 'Editor publish' } )
		.getByRole( 'button', { name: 'Publish', exact: true } )
		.click();
	await expect(
		page.getByRole( 'region', { name: 'Editor publish' } )
	).toContainText( 'is now live.' );
	const postId = new URL( page.url() ).searchParams.get( 'post' );
	const savedResponse = await page.request.get(
		`/wp-json/wp/v2/posts/${ postId }?context=edit`,
		{ headers }
	);
	const saved = await savedResponse.json();
	expect( saved.content.raw ).toContain( `"pollId":${ poll.id }` );
	await page.reload();
	await expect( canvas.locator( '.zw-poll__question' ) ).toHaveText(
		poll.title.raw
	);
	await expect(
		canvas.getByText( 'This block contains unexpected or invalid content.' )
	).toHaveCount( 0 );
	const anonymous = await browser.newContext();
	const frontend = await anonymous.newPage();
	await frontend.goto( saved.link );
	await expect(
		frontend.locator( '.wp-block-zw-poll-poll .zw-poll__question' )
	).toHaveText( poll.title.raw );
	await frontend.getByRole( 'radio', { name: 'Zeker', exact: true } ).check();
	await frontend.getByRole( 'button', { name: 'Stem', exact: true } ).click();
	await expect( frontend.locator( '.zw-poll__results' ) ).toBeVisible();
	await expect( frontend.locator( '.zw-poll__total' ) ).toHaveText(
		'Totaal aantal stemmen: 1'
	);
	await anonymous.close();
	const noScript = await browser.newContext( { javaScriptEnabled: false } );
	const serverRendered = await noScript.newPage();
	await serverRendered.goto( saved.link );
	await expect( serverRendered.locator( '.zw-poll__form' ) ).toBeVisible();
	await expect( serverRendered.locator( '.zw-poll__results' ) ).toBeHidden();
	await expect( serverRendered.locator( '.zw-poll__submit' ) ).toBeDisabled();
	await expect(
		serverRendered.locator( '.zw-poll__bar-value' ).first()
	).toHaveText( '100%' );
	expect(
		(
			await page.request.post( `/wp-json/wp/v2/zw-polls/${ poll.id }`, {
				headers,
				data: { meta: { _zw_poll_status: 'closed' } },
			} )
		).ok()
	).toBeTruthy();
	await serverRendered.reload();
	await expect( serverRendered.locator( '.zw-poll__form' ) ).toBeHidden();
	await expect( serverRendered.locator( '.zw-poll__results' ) ).toBeVisible();
	await expect( serverRendered.locator( '.zw-poll__final' ) ).toHaveText(
		'Einduitslag'
	);
	await expect(
		serverRendered.locator( '.zw-poll__bar-fill' ).first()
	).toHaveAttribute( 'style', /width:\s*100%/ );
	await noScript.close();
	const assertUsage = async ( used: boolean ) => {
		await page.goto( '/wp-admin/edit.php?post_type=zw_poll' );
		const usage = page.locator( `#post-${ poll.id } .column-usage` );
		if ( used ) {
			await expect(
				usage.getByRole( 'link', { name: '1 artikel' } )
			).toHaveAttribute(
				'href',
				new RegExp( `post=${ postId }(?:&|$)` )
			);
		} else {
			await expect( usage ).toHaveText( 'Niet in gebruik' );
		}
	};
	await assertUsage( true );
	expect(
		(
			await page.request.post( `/wp-json/wp/v2/posts/${ postId }`, {
				headers,
				data: { content: '' },
			} )
		).ok()
	).toBeTruthy();
	await assertUsage( false );
	expect(
		(
			await page.request.post( `/wp-json/wp/v2/posts/${ postId }`, {
				headers,
				data: { content: saved.content.raw },
			} )
		).ok()
	).toBeTruthy();
	await assertUsage( true );
	expect(
		(
			await page.request.delete(
				`/wp-json/wp/v2/posts/${ postId }?force=true`,
				{
					headers,
				}
			)
		).ok()
	).toBeTruthy();
	await assertUsage( false );
} );
