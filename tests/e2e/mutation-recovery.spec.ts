import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import { expect, test } from '@playwright/test'

test('native links and staged uploads recover after persistence interruption', async () => {
	test.setTimeout(120000)
	const { stdout } = await promisify(execFile)('docker', ['compose', 'exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '/var/www/html/custom_apps/proofing_gallery/tests/smoke/MutationRecovery.php'])
	expect(stdout).toContain('Native link rollback and both upload interruption boundaries verified.')
})
