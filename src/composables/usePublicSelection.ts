import { computed, ref } from 'vue'
import type { Ref } from 'vue'
import type { GallerySettings } from '../domain/gallerySettings.ts'
import type { MediaItem } from '../publicTypes.ts'
import { loadPublicGalleryCompareIds, safelyStore } from '../domain/publicGalleryPreferences.ts'

export function usePublicSelection(options: {
	token: string
	preview: boolean
	settings: Ref<GallerySettings>
	mediaItems: Ref<MediaItem[]>
	closePanel(): void
	mutate(path: string, method: 'POST' | 'PUT' | 'DELETE', body?: unknown): Promise<boolean>
}) {
	const { settings, mediaItems } = options
	const selectedIds = ref<number[]>([])
	const selectionMode = ref(false)
	const compareIds = ref<number[]>(!options.preview && settings.value.mode === 'collaboration' ? loadPublicGalleryCompareIds(options.token) : [])
	const compareOpen = ref(false)
	const compareItems = computed(() => compareIds.value.map(id => mediaItems.value.find(item => item.id === id)).filter((item): item is MediaItem => !!item))
	const selectionName = ref('')
	const selectionMessage = ref('')
	const savingSelection = ref(false)
	async function saveSelection() {
		if (!selectionName.value.trim() || selectedIds.value.length === 0) return
		savingSelection.value = true
		if (await options.mutate('selections', 'POST', {
			name: selectionName.value,
			message: selectionMessage.value,
			fileIds: selectedIds.value,
		})) {
			selectionName.value = ''
			selectionMessage.value = ''
			selectedIds.value = []
			selectionMode.value = false
			options.closePanel()
		}
		savingSelection.value = false
	}

	function toggleSelection(item: MediaItem) {
		selectedIds.value = selectedIds.value.includes(item.id)
			? selectedIds.value.filter(id => id !== item.id)
			: [...selectedIds.value, item.id]
	}

	function startSelectionMode() {
		selectionMode.value = true
		options.closePanel()
	}

	function finishSelectionMode() {
		selectionMode.value = false
		selectedIds.value = []
	}

	function openSelectionCompare() {
		if (settings.value.mode !== 'collaboration' || selectedIds.value.length < 2) return
		compareIds.value = selectedIds.value.slice(0, 4)
		if (!options.preview) safelyStore(() => localStorage.setItem(`proofing-gallery-compare:${options.token}`, JSON.stringify(compareIds.value)))
		compareOpen.value = true
	}

	function toggleCompare(item: MediaItem) {
		if (settings.value.mode !== 'collaboration') return
		compareIds.value = compareIds.value.includes(item.id)
			? compareIds.value.filter(id => id !== item.id)
			: compareIds.value.length < 4 ? [...compareIds.value, item.id] : compareIds.value
		if (!options.preview) safelyStore(() => localStorage.setItem(`proofing-gallery-compare:${options.token}`, JSON.stringify(compareIds.value)))
	}
	return { selectedIds, selectionMode, compareIds, compareOpen, compareItems, selectionName, selectionMessage, savingSelection, saveSelection, toggleSelection, startSelectionMode, finishSelectionMode, openSelectionCompare, toggleCompare }
}
