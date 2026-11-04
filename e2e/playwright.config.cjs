const { defineConfig } = require('@playwright/test');
const path = require('node:path');
const artifacts = process.env.E2E_ARTIFACT_DIR;
const browser = process.env.E2E_BROWSER_CHANNEL || 'msedge';
if (!artifacts || !process.env.E2E_ORIGIN) throw new Error('Run python scripts/check-e2e.py');
module.exports = defineConfig({
  testDir: __dirname,
  testMatch: 'import-flow.spec.cjs',
  workers: 1,
  retries: 0,
  timeout: 180000,
  expect: { timeout: 10000 },
  outputDir: path.join(artifacts, 'test-results'),
  reporter: [['line'], ['json', { outputFile: path.join(artifacts, 'results.json') }], ['html', { outputFolder: path.join(artifacts, 'html'), open: 'never' }]],
  use: {
    baseURL: process.env.E2E_ORIGIN,
    // No channel selects Playwright's version-matched Chromium headless shell for CI.
    channel: browser === 'chromium-headless-shell' ? undefined : browser,
    actionTimeout: 10000,
    navigationTimeout: 20000,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
});
