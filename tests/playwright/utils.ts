import { Page, expect } from '@playwright/test';

/** Logs in with the Playground default admin credentials. */
export async function login( page: Page ): Promise< void > {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await expect( page ).toHaveURL( /wp-admin/ );
}

/**
 * Publishes or updates a poll and reopens its edit screen in a fresh page.
 *
 * After the classic publish redirect chain, headless Chromium stops
 * producing animation frames for the page, which wedges every later
 * Playwright click on its actionability (stability) checks — even across
 * goto/reload. Only a new page gets a healthy renderer again, so callers
 * must continue on the returned page.
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
