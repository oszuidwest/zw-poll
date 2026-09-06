import { defineConfig, devices } from '@playwright/test';

/**
 * Run `npm run playground` before `npm run test:e2e`.
 * Serial specs avoid SQLite contention and shared vote state.
 */
const PORT = process.env.PLAYGROUND_PORT || '9400';
const baseURL = process.env.PLAYGROUND_URL || `http://127.0.0.1:${PORT}`;

export default defineConfig({
	testDir: './tests/playwright',
	fullyParallel: false,
	workers: 1,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 2 : 0,
	timeout: 60_000,
	expect: { timeout: 15_000 },
	reporter: process.env.CI ? [['github'], ['list']] : 'list',
	use: {
		baseURL,
		trace: 'on-first-retry',
	},
	projects: [
		{ name: 'chromium', use: { ...devices['Desktop Chrome'] } },
	],
});
