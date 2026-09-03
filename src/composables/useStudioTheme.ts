import { computed, onBeforeUnmount, ref, watch } from 'vue'

export type StudioThemePreference = 'auto' | 'light' | 'dark'

const storageKey = 'proofing-gallery-studio-theme'

function storedPreference(): StudioThemePreference {
	const stored = localStorage.getItem(storageKey)
	return stored === 'light' || stored === 'dark' ? stored : 'auto'
}

export function useStudioTheme() {
	const systemTheme = window.matchMedia('(prefers-color-scheme: dark)')
	const preference = ref<StudioThemePreference>(storedPreference())
	const systemDark = ref(systemTheme.matches)
	const resolved = computed<'light' | 'dark'>(() => preference.value === 'auto'
		? (systemDark.value ? 'dark' : 'light')
		: preference.value)
	const apply = () => {
		const root = document.getElementById('proofing_gallery')
		if (root) {
			root.dataset.studioTheme = resolved.value
			root.dataset.studioThemePreference = preference.value
		}
		document.body.dataset.proofingGalleryTheme = resolved.value
	}
	const onSystemTheme = (event: MediaQueryListEvent) => { systemDark.value = event.matches }

	watch(preference, value => {
		if (value === 'auto') localStorage.removeItem(storageKey)
		else localStorage.setItem(storageKey, value)
		apply()
	}, { immediate: true })
	watch(resolved, apply)
	systemTheme.addEventListener('change', onSystemTheme)
	onBeforeUnmount(() => {
		systemTheme.removeEventListener('change', onSystemTheme)
		delete document.body.dataset.proofingGalleryTheme
	})

	return { preference, resolved }
}
