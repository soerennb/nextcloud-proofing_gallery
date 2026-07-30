module.exports = {
	extends: [
		'@nextcloud',
	],
	parserOptions: {
		parser: '@typescript-eslint/parser',
	},
	rules: {
		'jsdoc/require-jsdoc': 'off',
		'vue/first-attribute-linebreak': 'off',
	},
}
