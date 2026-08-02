import { recommended } from '@nextcloud/eslint-config'

const plugins = Object.assign({}, ...recommended.map(config => config.plugins ?? {}))

export default [
	...recommended,
	{
		files: ['src/**/*.{js,mjs,ts,vue}'],
		plugins,
		rules: {
			// Preserve the app's established compact style while adopting the
			// current Nextcloud correctness, security and Vue rules.
			'@stylistic/arrow-parens': ['error', 'as-needed'],
			'@stylistic/exp-list-style': 'off',
			'@stylistic/function-paren-newline': 'off',
			'@stylistic/indent-binary-ops': 'off',
			'@stylistic/max-statements-per-line': 'off',
			'@stylistic/member-delimiter-style': 'off',
			'@stylistic/padded-blocks': 'off',
			'@typescript-eslint/no-unused-expressions': 'off',
			'@typescript-eslint/no-use-before-define': 'off',
			complexity: ['error', 16],
			curly: ['error', 'multi-line'],
			'import-extensions/extensions': 'off',
			'jsdoc/require-jsdoc': 'off',
			'max-lines-per-function': ['error', { max: 100, skipBlankLines: true, skipComments: true }],
			'perfectionist/sort-imports': 'off',
			'vue/attribute-hyphenation': ['error', 'always'],
			'vue/custom-event-name-casing': ['error', 'kebab-case'],
			'vue/define-macros-order': 'off',
			'vue/first-attribute-linebreak': 'off',
			'vue/v-on-event-hyphenation': ['error', 'always'],
		},
	},
]
