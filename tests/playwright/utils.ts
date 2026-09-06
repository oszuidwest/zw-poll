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
