/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH or a Nextcloud affiliate company and Euro-Office contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { runOcc } from '@nextcloud/e2e-test-server/docker'
import { expect, test } from '../support/fixtures.mjs'

const SAMPLE_PDF = 'assets/document-templates/sample/sample.pdf'

/**
 * Open a page once it reflects the PDF default set through occ, which the web
 * server may still serve from its config cache for a moment.
 *
 * @param {import('@playwright/test').Page} page the page
 * @param {string} path the app route to open
 * @param {boolean} pdfDefault whether Euro-Office is expected to be the PDF default
 */
async function gotoWithPdfDefault(page, path, pdfDefault) {
	await expect.poll(async () => {
		await page.goto(path)
		return page.evaluate(() => OCA.Eurooffice?.setting?.formats?.pdf?.def)
	}).toBe(pdfDefault)
}

/**
 * The id of the first Viewer handler for PDFs, which is the one the Viewer uses
 *
 * @param {import('@playwright/test').Page} page the page
 */
async function pdfHandler(page) {
	await page.waitForFunction(() => OCA.Viewer?.availableHandlers.some((handler) => handler.id === 'pdf'))
	return page.evaluate(() => OCA.Viewer.availableHandlers.find((handler) => handler.mimes?.includes('application/pdf'))?.id)
}

test.describe('PDF with default settings', () => {
	test.beforeAll(async () => {
		await runOcc(['config:app:delete', 'eurooffice', 'defFormats'])
	})

	test('opens in files_pdfviewer', async ({ page, upload }) => {
		await upload(SAMPLE_PDF, 'sample.pdf')
		await gotoWithPdfDefault(page, 'apps/files/', false)
		await page.locator('[data-cy-files-list-row-name="sample.pdf"] .files-list__row-name-link').click()

		await expect(page.locator('iframe[src*="/apps/files_pdfviewer/"]')).toBeVisible()
		await expect(page.locator('#euroofficeViewerFrame')).toHaveCount(0)
	})

	test('is handled by files_pdfviewer in Talk', async ({ page }) => {
		await gotoWithPdfDefault(page, 'apps/spreed/', false)

		expect(await pdfHandler(page)).toBe('pdf')
	})
})

test.describe('PDF enabled as Euro-Office default format', () => {
	test.beforeAll(async () => {
		await runOcc(['config:app:set', 'eurooffice', 'defFormats', '--value={"pdf":"true"}'])
	})

	test.afterAll(async () => {
		await runOcc(['config:app:delete', 'eurooffice', 'defFormats'])
	})

	test('opens in Euro-Office from Files', async ({ page, upload }) => {
		await upload(SAMPLE_PDF, 'sample.pdf')
		await gotoWithPdfDefault(page, 'apps/files/', true)
		await page.locator('[data-cy-files-list-row-name="sample.pdf"] .files-list__row-name-link').click()

		await expect(page.locator('#euroofficeViewerFrame')).toBeVisible()
		await expect(page.locator('iframe[src*="/apps/files_pdfviewer/"]')).toHaveCount(0)
	})

	test('is handled by Euro-Office in Talk', async ({ page }) => {
		await gotoWithPdfDefault(page, 'apps/spreed/', true)

		expect(await pdfHandler(page)).toBe('eurooffice')
	})
})
