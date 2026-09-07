import { Page, expect } from '@playwright/test';

/** Logs in with the Playground default admin credentials. */
export async function login( page: Page ): Promise< void > {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await expect( page ).toHaveURL( /wp-admin/ );
}

export async function startNewPoll(
	page: Page,
	question: string,
	labels: string[]
): Promise< void > {
	await page.goto( '/wp-admin/post-new.php?post_type=zw_poll' );
	await page.fill( '#title', question );
	const fields = page.locator( '.zw-poll-edit-option__label' );
	for ( let i = 0; i < labels.length; i++ ) {
		await fields.nth( i ).fill( labels[ i ] );
	}
}

/**
 * Publishes or updates a poll and reopens its edit screen in a fresh page.
 *
 * A fresh page avoids missing animation frames after the classic publish
 * redirect, which otherwise block later Chromium actionability checks.
 */
export async function publishAndReopen( page: Page ): Promise< Page > {
	await page.click( '#publish' );
	await page.waitForURL( /post\.php\?post=\d+&action=edit/ );
	const postId = new URL( page.url() ).searchParams.get( 'post' );
	const fresh = await page.context().newPage();
	await page.close();
	await fresh.goto( `/wp-admin/post.php?post=${ postId }&action=edit` );
	return fresh;
}

/**
 * A user agent core does not recognise as rich-edit capable, which makes
 * `user_can_richedit()` return false without changing profile state. The
 * "Disable the visual editor when writing" option reaches the same outcome
 * through a different check inside that core function.
 */
export const NO_RICH_EDIT_UA = 'ZwPollNoRichEdit/1.0';

export const DEMO_PAGE = '/poll-demo/';

export const CLOSED_DEMO_PAGE = '/poll-gesloten-demo/';

export const THRESHOLD_DEMO_PAGE = '/poll-drempel-demo/';

export const CLOSED_THRESHOLD_DEMO_PAGE = '/poll-drempel-gesloten-demo/';

export const IMAGE_DEMO_PAGE = '/poll-afbeeldingen-demo/';

export const IMAGE_FALLBACK_DEMO_PAGE = '/poll-afbeeldingen-fallback/';
