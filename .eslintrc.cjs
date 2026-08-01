module.exports = {
	extends: [
		'@nextcloud',
	],
	parserOptions: {
		parser: '@typescript-eslint/parser',
	},
	rules: {
		complexity: ['error', 16],
		'jsdoc/require-jsdoc': 'off',
		'max-lines-per-function': ['error', { max: 100, skipBlankLines: true, skipComments: true }],
		'vue/first-attribute-linebreak': 'off',
	},
}
