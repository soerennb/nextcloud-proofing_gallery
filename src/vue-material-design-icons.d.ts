declare module 'vue-material-design-icons/*.vue' {
	import type { DefineComponent } from 'vue'

	const icon: DefineComponent<{
		title?: string
		fillColor?: string
		size?: number | string
	}, object, unknown>

	export default icon
}
