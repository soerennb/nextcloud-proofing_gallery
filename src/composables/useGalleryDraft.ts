import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { canonicalGallerySettings } from '../domain/gallerySettings.ts'
import { updateGallery } from '../services/galleryApi.ts'
import type { Gallery } from '../types.ts'

type SaveState = 'saved' | 'pending' | 'saving' | 'offline' | 'error' | 'conflict' | 'invalid'

function createDraftState(gallery: Gallery) {
	const saving = ref(false)
	const saveState = ref<SaveState>('saved')
	const saveTimer = ref<ReturnType<typeof setTimeout> | null>(null)
	const serverRevision = ref(gallery.revision)
	const conflictGallery = ref<Gallery | null>(null)
	const baseline = ref('')
	const draft = reactive({
		title: gallery.title,
		settings: structuredClone(gallery.settings),
	})
	const serializedDraft = computed(() => JSON.stringify({ title: draft.title, settings: draft.settings }))
	const dirty = computed(() => serializedDraft.value !== baseline.value)

	return { saving, saveState, saveTimer, serverRevision, conflictGallery, baseline, draft, serializedDraft, dirty }
}

interface DraftOptions { gallery(): Gallery; updated(gallery: Gallery): void; saved(): void }

function revisionConflict(error: unknown): Gallery | null {
	if (typeof error !== 'object' || error === null || !('response' in error)) return null
	const response = (error as { response?: { status?: number; data?: { code?: string; gallery?: Gallery } } }).response
	return response?.status === 409 && response.data?.code === 'revision_conflict'
		? response.data.gallery ?? null
		: null
}

/**
 * Owns one gallery's draft, debounce and serialized persistence.
 *
 * @param options Gallery ownership and persistence callbacks.
 */
export function useGalleryDraft(options: DraftOptions) {
	let disposed = false
	const { saving, saveState, saveTimer, serverRevision, conflictGallery, baseline, draft, serializedDraft, dirty } = createDraftState(options.gallery())
	let savePromise: Promise<boolean> | null = null

	watch(serializedDraft, () => {
		if (!options.gallery().permissions.canEdit) return
		if (!dirty.value) {
			if (!saving.value) saveState.value = 'saved'
			return
		}
		scheduleSave()
	})

	function resetDraft(gallery = options.gallery()) {
		draft.title = gallery.title
		draft.settings = structuredClone(gallery.settings)
		baseline.value = JSON.stringify({ title: draft.title, settings: draft.settings })
		serverRevision.value = gallery.revision
		conflictGallery.value = null
		saveState.value = 'saved'
	}

	function clearSaveTimer() {
		if (saveTimer.value !== null) {
			clearTimeout(saveTimer.value)
			saveTimer.value = null
		}
	}

	function scheduleSave(delay = 650) {
		if (disposed) return
		clearSaveTimer()
		if (!draft.title.trim()) {
			saveState.value = 'invalid'
			return
		}
		if (!navigator.onLine) {
			saveState.value = 'offline'
			return
		}
		saveState.value = 'pending'
		saveTimer.value = setTimeout(() => { save().catch(() => {}) }, delay)
	}

	function save(showConfirmation = false): Promise<boolean> {
		if (savePromise !== null) {
			return savePromise.then(saved => dirty.value ? save(showConfirmation) : saved)
		}
		savePromise = persistSave(showConfirmation).finally(() => { savePromise = null })
		return savePromise
	}

	async function persistSave(showConfirmation = false): Promise<boolean> {
		if (disposed || !options.gallery().permissions.canEdit || !dirty.value) return true
		if (!draft.title.trim()) {
			saveState.value = 'invalid'
			return false
		}
		clearSaveTimer()
		const snapshot = serializedDraft.value
		const title = draft.title.trim()
		const settings = canonicalGallerySettings(draft.settings)
		saving.value = true
		saveState.value = 'saving'
		try {
			const gallery = await updateGallery(options.gallery().id, {
				title,
				settings,
				expectedRevision: serverRevision.value,
			})
			if (disposed) return true
			serverRevision.value = gallery.revision
			baseline.value = snapshot
			options.updated(gallery)
			if (serializedDraft.value === snapshot) {
				saveState.value = 'saved'
			} else {
				scheduleSave(0)
			}
			if (showConfirmation) showSuccess(t('proofing_gallery', 'Gallery settings saved.'))
			options.saved()
			return true
		} catch (error) {
			if (disposed) return false
			const current = revisionConflict(error)
			if (current) {
				conflictGallery.value = current
				saveState.value = 'conflict'
			} else if (!navigator.onLine) {
				saveState.value = 'offline'
			} else {
				saveState.value = 'error'
			}
			return false
		} finally {
			saving.value = false
		}
	}

	async function flushSave(): Promise<boolean> {
		clearSaveTimer()
		return dirty.value ? save() : true
	}
	onBeforeUnmount(() => { disposed = true; clearSaveTimer() })
	return { draft, dirty, saving, saveState, serverRevision, conflictGallery, resetDraft, scheduleSave, save, flushSave, clearSaveTimer }
}
