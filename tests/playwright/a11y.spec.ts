import { test, expect, Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { DEMO_PAGE } from './utils';

/** Scope Axe to plugin markup so theme/core issues do not count. */
function scanPoll( page: Page ) {
	return new AxeBuilder({ page })
		.include('.zw-poll')
		.withTags(['wcag2a', 'wcag2aa']);
}

async function applyDarkTheme(
	page: Page,
	{ declareColorScheme = true }: { declareColorScheme?: boolean } = {}
) {
	await page.emulateMedia({ colorScheme: 'dark' });
	await page.addStyleTag({
		content: `
			${
				declareColorScheme
					? `html {
						color-scheme: dark;
					}`
					: ''
			}

			body {
				background: #111;
				color: #f4f4f5;
			}
		`,
	});
}

test.describe('Toegankelijkheid', () => {
	test('geen a11y-overtredingen vóór het stemmen', async ({ page }) => {
		await page.goto(DEMO_PAGE);
		await expect(page.locator('.zw-poll')).toBeVisible();

		const results = await scanPoll(page).analyze();
		expect(results.violations).toEqual([]);
	});

	test('statusmeldingen blijven leesbaar op donker theme zonder color-scheme', async ({ page }) => {
		await page.goto(DEMO_PAGE);
		await applyDarkTheme(page, { declareColorScheme: false });
		await page.locator('.zw-poll').evaluate((poll) => {
			poll.insertAdjacentHTML(
				'beforeend',
				'<p class="zw-poll__error">Stem niet opgeslagen. Probeer het later opnieuw.</p>'
			);
			poll.insertAdjacentHTML(
				'afterend',
				'<div class="zw-poll zw-poll--missing">Poll niet beschikbaar.</div>'
			);
		});

		const results = await scanPoll(page).analyze();
		expect(results.violations).toEqual([]);
	});

	test('geen a11y-overtredingen vóór het stemmen op donker schema', async ({ page }) => {
		await page.goto(DEMO_PAGE);
		await applyDarkTheme(page);
		await expect(page.locator('.zw-poll')).toBeVisible();

		const results = await scanPoll(page).analyze();
		expect(results.violations).toEqual([]);
	});

	test('geen a11y-overtredingen op de resultaten-weergave', async ({ page }) => {
		await page.goto(DEMO_PAGE);
		const poll = page.locator('.zw-poll');

		const submit = poll.locator('.zw-poll__submit');
		await expect(submit).toBeDisabled(); // Hydration has not enabled submit yet.
		await poll.locator('input[type="radio"]').first().check();
		await expect(submit).toBeEnabled();
		await submit.click();
		await expect(poll.locator('.zw-poll__results')).toBeVisible();

		const scan = await scanPoll(page).analyze();
		expect(scan.violations).toEqual([]);
	});

	test('geen a11y-overtredingen op resultaten-weergave met donker schema', async ({ page }) => {
		await page.goto(DEMO_PAGE);
		await applyDarkTheme(page);
		const poll = page.locator('.zw-poll');

		const submit = poll.locator('.zw-poll__submit');
		await expect(submit).toBeDisabled(); // Hydration has not enabled submit yet.
		await poll.locator('input[type="radio"]').first().check();
		await expect(submit).toBeEnabled();
		await submit.click();
		await expect(poll.locator('.zw-poll__results')).toBeVisible();

		const scan = await scanPoll(page).analyze();
		expect(scan.violations).toEqual([]);
	});
});
