import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { ref } from 'vue'
import type {Ref} from 'vue'

import { canonicalGallerySettings } from '../domain/gallerySettings.ts'
import type {GallerySettings} from '../domain/gallerySettings.ts'
import { applyPreset, createPreset, deletePreset, fetchPresets, updatePreset } from '../services/galleryApi.ts'
import type { Gallery, GalleryPreset } from '../types.ts'

type GalleryPresetOptions = {
	gallery: () => Gallery
	settings: () => GallerySettings
	dirty: Readonly<Ref<boolean>>
	resetDraft: (gallery: Gallery) => void
	onUpdated: (gallery: Gallery) => void
}

export function useGalleryPresets(options: GalleryPresetOptions) {
	const presets = ref<GalleryPreset[]>([])
	const presetsLoading = ref(false)
	const presetSaving = ref(false)
	const selectedPresetId = ref<number | null>(null)
	const presetName = ref('')

	async function loadPresets(): Promise<void> {
		if (options.gallery().permissions.role !== 'owner') return
		presetsLoading.value = true
		try {
			presets.value = await fetchPresets()
		} catch {
			showError(t('proofing_gallery', 'Presets could not be loaded.'))
		} finally {
			presetsLoading.value = false
		}
	}

	function selectPreset(): void {
		presetName.value = presets.value.find(preset => preset.id === selectedPresetId.value)?.name ?? ''
	}

	async function applySelectedPreset(): Promise<void> {
		if (selectedPresetId.value === null) return
		if (options.dirty.value && !window.confirm(t('proofing_gallery', 'Apply the preset and discard unsaved changes?'))) return
		await withSaving(async () => {
			const gallery = await applyPreset(selectedPresetId.value!, options.gallery().id)
			options.resetDraft(gallery)
			options.onUpdated(gallery)
			showSuccess(t('proofing_gallery', 'Preset applied.'))
		}, t('proofing_gallery', 'The preset could not be applied.'))
	}

	async function saveNewPreset(): Promise<void> {
		if (!presetName.value.trim()) return
		await withSaving(async () => {
			const preset = await createPreset(presetName.value.trim(), canonicalGallerySettings(options.settings()))
			presets.value = [...presets.value, preset].sort((left, right) => left.name.localeCompare(right.name))
			selectedPresetId.value = preset.id
			showSuccess(t('proofing_gallery', 'Preset created.'))
		}, t('proofing_gallery', 'The preset could not be created. Check that its name is unique.'))
	}

	async function updateSelectedPreset(): Promise<void> {
		if (selectedPresetId.value === null || !presetName.value.trim()) return
		await withSaving(async () => {
			const preset = await updatePreset(selectedPresetId.value!, {
				name: presetName.value.trim(),
				settings: canonicalGallerySettings(options.settings()),
			})
			presets.value = presets.value.map(item => item.id === preset.id ? preset : item)
			showSuccess(t('proofing_gallery', 'Preset updated from the current settings.'))
		}, t('proofing_gallery', 'The preset could not be updated.'))
	}

	async function removeSelectedPreset(): Promise<void> {
		if (selectedPresetId.value === null || !window.confirm(t('proofing_gallery', 'Delete this preset? Existing galleries will not change.'))) return
		await withSaving(async () => {
			await deletePreset(selectedPresetId.value!)
			presets.value = presets.value.filter(preset => preset.id !== selectedPresetId.value)
			selectedPresetId.value = null
			presetName.value = ''
			showSuccess(t('proofing_gallery', 'Preset deleted.'))
		}, t('proofing_gallery', 'The preset could not be deleted.'))
	}

	async function withSaving(action: () => Promise<void>, errorMessage: string): Promise<void> {
		presetSaving.value = true
		try {
			await action()
		} catch {
			showError(errorMessage)
		} finally {
			presetSaving.value = false
		}
	}

	return {
		presets,
		presetsLoading,
		presetSaving,
		selectedPresetId,
		presetName,
		loadPresets,
		selectPreset,
		applySelectedPreset,
		saveNewPreset,
		updateSelectedPreset,
		removeSelectedPreset,
	}
}
