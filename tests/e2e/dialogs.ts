import { expect, type Page } from '@playwright/test'

/**
 * Nextcloud dialogs 7.5 exposes toast feedback through an accessible status
 * instead of the former Toastify-specific DOM classes.
 */
export async function expectSuccessToast(page: Page, message: string | RegExp): Promise<void> {
	await expect(page.getByRole('status').filter({ hasText: message }).last()).toBeVisible()
}
