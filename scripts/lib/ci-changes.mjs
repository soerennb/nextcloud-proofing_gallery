const match = (file, patterns) => patterns.some((pattern) => pattern.test(file))

/**
 * Classify changed paths into the smallest CI surface that can validate them.
 * Integration checks deliberately imply the two fast application baselines.
 */
export function classifyCiChanges(files) {
	const selected = {
		web: false,
		php: false,
		workflow: false,
		docs: false,
		dependencies: false,
		integration: false,
		compatibility: false,
		upgrade: false,
		codeql: false,
	}

	for (const file of files) {
		if (match(file, [/^src\//, /^l10n\//, /^(vite|vitest|eslint|stylelint|tsconfig)\./, /^playwright\.config\.ts$/, /^package(-lock)?\.json$/])) selected.web = true
		if (match(file, [/^lib\//, /^appinfo\//, /^templates\//, /^tests\/(Unit|smoke)\//, /^composer\.(json|lock)$/, /^phpstan\.neon$/])) selected.php = true
		if (match(file, [/^\.github\/workflows\//, /^\.github\/actions\//, /^scripts\/(classify-ci-changes|check-workflows)\.mjs$/, /^scripts\/lib\/ci-changes/])) selected.workflow = true
		if (match(file, [/^src\//, /^\.github\/(workflows|actions)\//])) selected.codeql = true
		if (match(file, [/^docs\//, /^README\.md$/, /^docs\.config\./, /^scripts\/(build-docs-site|check-docs)\.mjs$/])) selected.docs = true
		if (match(file, [/^package(-lock)?\.json$/, /^composer\.(json|lock)$/])) selected.dependencies = true
		if (match(file, [/^src\//, /^lib\//, /^appinfo\//, /^templates\//, /^tests\/(e2e|smoke|context_agent)\//, /^integrations\//, /^compose\.yaml$/, /^scripts\/(run-e2e|test-context-agent|test-user-migration)\./])) selected.integration = true
		if (match(file, [/^lib\//, /^appinfo\//, /^integrations\//, /^tests\/compat\//, /^scripts\/compatibility-matrix\.sh$/, /^compose\.yaml$/, /^composer\.(json|lock)$/])) selected.compatibility = true
		if (match(file, [/^lib\/(Migration|Db)\//, /^appinfo\/info\.xml$/, /^scripts\/(test-upgrade|build-appstore|verify-appstore-package|validate-appstore-package)\./, /^Makefile$/, /^composer\.(json|lock)$/])) selected.upgrade = true
	}

	if (selected.integration) {
		selected.web = true
		selected.php = true
	}
	if (selected.compatibility || selected.upgrade) {
		selected.web = true
		selected.php = true
	}

	return selected
}
