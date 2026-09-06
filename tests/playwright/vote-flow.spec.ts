import { test, expect, Locator } from '@playwright/test';
import { DEMO_PAGE } from './utils';

/**
 * Casts a demo vote after Interactivity hydration.
 *
 * Repeat votes can return already_voted; that still closes the form, which is
 * the cache-safe UX contract under test.
 */
async function castVote(poll: Locator): Promise<void> {
	const submit = poll.locator('.zw-poll__submit');
	await expect(submit).toBeDisabled();

	await poll.locator('input[type="radio"]').first().check();
	await expect(submit).toBeEnabled();
	await submit.click();

	await expect(poll.locator('.zw-poll__form')).toBeHidden();
}

test.describe('Stem-flow (frontend)', () => {
	test('lezer kan stemmen en ziet daarna de resultaten', async ({ page }) => {
		await page.goto(DEMO_PAGE);

		const poll = page.locator('.zw-poll');
		await expect(poll).toBeVisible();
		await expect(poll.locator('.zw-poll__question')).toContainText(/redactie/i);
		await expect(poll.locator('.zw-poll__option')).toHaveCount(3);
		await expect(poll.locator('.zw-poll__results')).toBeHidden();
		await expect(poll.locator('.zw-poll__peek')).toHaveCount(0);

		await castVote(poll);

		const results = poll.locator('.zw-poll__results');
		await expect(results).toBeVisible();
		await expect(results.locator('.zw-poll__bar')).toHaveCount(3);
		await expect(results.locator('.zw-poll__bar-value').first()).toContainText('%');
		await expect(
			results.locator('.zw-poll__bar').first().locator('.zw-poll__bar-vote')
		).toBeVisible();
		await expect(
			results.locator('.zw-poll__bar').first().locator('.zw-poll__bar-vote')
		).toContainText('Gestemd');
	});

	test('na herladen blijven de resultaten en gekozen optie zichtbaar via de stem-cookie', async ({
		page,
	}) => {
		await page.goto(DEMO_PAGE);
		const poll = page.locator('.zw-poll');
		await castVote(poll);

		// Cached renders reopen results from the zwpoll_voted_* cookie.
		await page.reload();
		await expect(poll.locator('.zw-poll__form')).toBeHidden();
		await expect(poll.locator('.zw-poll__results')).toBeVisible();
		await expect(poll.locator('.zw-poll__voted')).toHaveCount(0);
		await expect(
			poll.locator('.zw-poll__bar').first().locator('.zw-poll__bar-vote')
		).toBeVisible();
		await expect(
			poll.locator('.zw-poll__bar').first().locator('.zw-poll__bar-vote')
		).toContainText('Gestemd');
	});

	test('open poll heeft geen tussenstand-link voor het stemmen', async ({ browser }) => {
		const context = await browser.newContext();
		const page = await context.newPage();
		await page.goto(DEMO_PAGE);

		const poll = page.locator('.zw-poll');
		await expect(poll.locator('.zw-poll__submit')).toBeDisabled();
		await expect(poll.locator('.zw-poll__form')).toBeVisible();
		await expect(poll.locator('.zw-poll__results')).toBeHidden();
		await expect(poll.locator('.zw-poll__peek')).toHaveCount(0);
		await expect(page.getByText('Eerst de tussenstand bekijken')).toHaveCount(0);
		await expect(page.getByText('Terug naar stemmen')).toHaveCount(0);

		await context.close();
	});

	test('markeert de gekozen optie in de resultaten', async ({ browser }) => {
		const context = await browser.newContext();
		const page = await context.newPage();
		await page.goto(DEMO_PAGE);

		const poll = page.locator('.zw-poll');
		await expect(poll.locator('.zw-poll__submit')).toBeDisabled();
		await expect(poll.locator('.zw-poll__voted')).toHaveCount(0);
		await expect(poll.locator('.zw-poll__peek')).toHaveCount(0);

		await castVote(poll);

		await expect(
			poll.locator('.zw-poll__bar').first().locator('.zw-poll__bar-vote')
		).toBeVisible();
		await expect(
			poll.locator('.zw-poll__bar').first().locator('.zw-poll__bar-vote')
		).toContainText('Gestemd');
		await expect(
			poll.locator('.zw-poll__bar').nth(1).locator('.zw-poll__bar-vote')
		).toBeHidden();
		await expect(
			poll.locator('.zw-poll__bar').nth(2).locator('.zw-poll__bar-vote')
		).toBeHidden();
		await expect(poll.locator('.zw-poll__voted')).toHaveCount(0);
		await expect(poll.locator('.zw-poll__peek')).toHaveCount(0);

		await context.close();
	});

	test('markeert de gekozen optie na een already_voted response', async ({
		browser,
	}) => {
		const context = await browser.newContext();
		const page = await context.newPage();
		await page.route('**/wp-json/zw-poll/v1/vote*', async (route) => {
			await route.fulfill({
				status: 409,
				contentType: 'application/json',
				body: JSON.stringify({
					code: 'already_voted',
					message: 'Je hebt al gestemd op deze poll.',
				}),
			});
		});
		await page.goto(DEMO_PAGE);

		const poll = page.locator('.zw-poll');
		const submit = poll.locator('.zw-poll__submit');
		await expect(submit).toBeDisabled(); // Hydration has not enabled submit yet.
		await poll.locator('input[type="radio"]').nth(1).check();
		await expect(submit).toBeEnabled();
		await submit.click();

		await expect(poll.locator('.zw-poll__form')).toBeHidden();
		await expect(poll.locator('.zw-poll__error')).toBeHidden();
		await expect(poll.locator('.zw-poll__results')).toBeVisible();
		await expect(
			poll.locator('.zw-poll__bar').nth(1).locator('.zw-poll__bar-vote')
		).toBeVisible();
		await expect(
			poll.locator('.zw-poll__bar').first().locator('.zw-poll__bar-vote')
		).toBeHidden();
		await expect(
			poll.locator('.zw-poll__bar').nth(2).locator('.zw-poll__bar-vote')
		).toBeHidden();

		await context.close();
	});
});
