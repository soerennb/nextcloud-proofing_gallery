<script setup lang="ts">
import { FilePickerType, getFilePickerBuilder, showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref, watch } from 'vue'

import { createProject } from '../services/galleryApi.ts'
import type { Gallery, GalleryPurpose } from '../types.ts'

defineProps<{ show: boolean }>()

const emit = defineEmits<{
	close: []
	created: [gallery: Gallery]
}>()

const purposes: Array<{ id: Exclude<GalleryPurpose, 'custom'>; title: string; description: string }> = [
	{ id: 'delivery', title: t('proofing_gallery', 'Deliver finished photos'), description: t('proofing_gallery', 'A polished gallery with individual and complete downloads.') },
	{ id: 'showcase', title: t('proofing_gallery', 'Show photos only'), description: t('proofing_gallery', 'An image-first presentation without downloads or review tools.') },
	{ id: 'selection', title: t('proofing_gallery', 'Collect a selection'), description: t('proofing_gallery', 'Clients choose their favorites and submit one clear result.') },
	{ id: 'proofing', title: t('proofing_gallery', 'Review together'), description: t('proofing_gallery', 'Selections, likes, colors, comments and image annotations.') },
	{ id: 'uploads', title: t('proofing_gallery', 'Receive files'), description: t('proofing_gallery', 'Clients send files into a moderated project inbox.') },
]

const step = ref<1 | 2>(1)
const purpose = ref<Exclude<GalleryPurpose, 'custom'>>('delivery')
const sourceMode = ref<'existing' | 'new' | 'collection'>('existing')
const title = ref('')
const folderId = ref<number | null>(null)
const folderName = ref('')
const parentFolderId = ref<number | null>(null)
const parentFolderName = ref('')
const newFolderName = ref('')
const saving = ref(false)

const canSubmit = computed(() => title.value.trim() !== '' && !saving.value && (
	sourceMode.value === 'collection'
		|| (sourceMode.value === 'existing' && folderId.value !== null)
		|| (sourceMode.value === 'new' && parentFolderId.value !== null && newFolderName.value.trim() !== '')
))

watch(title, value => {
	if (sourceMode.value === 'new' && newFolderName.value === '') newFolderName.value = value
})

async function chooseFolder(kind: 'source' | 'parent') {
	try {
		const nodes = await getFilePickerBuilder(kind === 'source'
			? t('proofing_gallery', 'Choose the project folder')
			: t('proofing_gallery', 'Choose where the project folder will be created'))
			.setMultiSelect(false)
			.allowDirectories()
			.setType(FilePickerType.Choose)
			.setCanPick(node => node.type === 'folder')
			.build()
			.pickNodes()
		const folder = nodes[0]
		if (folder?.fileid === undefined) {
			showError(t('proofing_gallery', 'The selected folder has no compatible file ID.'))
			return
		}
		if (kind === 'source') {
			folderId.value = folder.fileid
			folderName.value = folder.displayname
			if (!title.value) title.value = folder.displayname
		} else {
			parentFolderId.value = folder.fileid
			parentFolderName.value = folder.displayname
			localStorage.setItem('proofing-gallery:last-parent', JSON.stringify({ id: folder.fileid, name: folder.displayname }))
		}
	} catch {
		// Closing the picker is not an error.
	}
}

function continueToSource() {
	step.value = 2
	if (parentFolderId.value === null) {
		try {
			const stored = JSON.parse(localStorage.getItem('proofing-gallery:last-parent') ?? 'null') as { id?: number; name?: string } | null
			if (stored?.id) {
				parentFolderId.value = stored.id
				parentFolderName.value = stored.name ?? ''
			}
		} catch { /* Ignore a stale browser preference. */ }
	}
}

async function submit() {
	if (!canSubmit.value) return
	saving.value = true
	try {
		const gallery = await createProject({
			title: title.value.trim(),
			purpose: purpose.value,
			sourceMode: sourceMode.value,
			folderId: sourceMode.value === 'existing' ? folderId.value : null,
			parentFolderId: sourceMode.value === 'new' ? parentFolderId.value : null,
			folderName: sourceMode.value === 'new' ? newFolderName.value.trim() : undefined,
		})
		emit('created', gallery)
		reset()
	} catch (error) {
		const message = typeof error === 'object' && error !== null && 'response' in error
			? (error as { response?: { data?: { message?: string } } }).response?.data?.message
			: null
		showError(message || t('proofing_gallery', 'The project could not be created.'))
	} finally {
		saving.value = false
	}
}

function reset() {
	step.value = 1
	purpose.value = 'delivery'
	sourceMode.value = 'existing'
	title.value = ''
	folderId.value = null
	folderName.value = ''
	newFolderName.value = ''
}

function updateOpen(open: boolean) {
	if (!open) emit('close')
}
</script>

<template>
	<NcDialog
		:open="show"
		:name="step === 1 ? t('proofing_gallery', 'What do you want to do?') : t('proofing_gallery', 'Set up the project')"
		size="large"
		@update:open="updateOpen">
		<form class="project-wizard" @submit.prevent="submit">
			<div class="wizard-progress" :aria-label="t('proofing_gallery', 'Project setup progress')">
				<span :aria-current="step === 1 ? 'step' : undefined">{{ t('proofing_gallery', 'Purpose') }}</span>
				<span :aria-current="step === 2 ? 'step' : undefined">{{ t('proofing_gallery', 'Files and title') }}</span>
			</div>

			<div v-if="step === 1" class="purpose-list">
				<label v-for="(option, index) in purposes" :key="option.id" :class="{ selected: purpose === option.id }">
					<input v-model="purpose"
						type="radio"
						name="purpose"
						:value="option.id">
					<span class="purpose-number" aria-hidden="true">{{ String(index + 1).padStart(2, '0') }}</span>
					<span><strong>{{ option.title }}</strong><small>{{ option.description }}</small></span>
					<span class="purpose-arrow" aria-hidden="true">→</span>
				</label>
			</div>

			<div v-else class="source-setup">
				<NcTextField
					id="proofing-gallery-title"
					v-model="title"
					name="title"
					:label="t('proofing_gallery', 'Project title')"
					:placeholder="t('proofing_gallery', 'Wedding, portrait session, event…')" />

				<fieldset>
					<legend>{{ t('proofing_gallery', 'Where are the photos?') }}</legend>
					<div class="source-options">
						<label :class="{ selected: sourceMode === 'existing' }"><input v-model="sourceMode" type="radio" value="existing"><strong>{{ t('proofing_gallery', 'Existing folder') }}</strong><span>{{ t('proofing_gallery', 'Use photos already stored in Nextcloud.') }}</span></label>
						<label :class="{ selected: sourceMode === 'new' }"><input v-model="sourceMode" type="radio" value="new"><strong>{{ t('proofing_gallery', 'New project folder') }}</strong><span>{{ t('proofing_gallery', 'Create the folder here and upload next.') }}</span></label>
						<label :class="{ selected: sourceMode === 'collection' }"><input v-model="sourceMode" type="radio" value="collection"><strong>{{ t('proofing_gallery', 'Curated collection') }}</strong><span>{{ t('proofing_gallery', 'Combine files from several galleries.') }}</span></label>
					</div>
				</fieldset>

				<button v-if="sourceMode === 'existing'"
					class="folder-choice"
					type="button"
					@click="chooseFolder('source')">
					<strong>{{ folderName || t('proofing_gallery', 'Choose project folder') }}</strong>
					<span>{{ folderName ? t('proofing_gallery', 'Choose another folder') : t('proofing_gallery', 'Original files stay in this Nextcloud folder.') }}</span>
				</button>

				<div v-if="sourceMode === 'new'" class="new-folder-fields">
					<button class="folder-choice" type="button" @click="chooseFolder('parent')">
						<strong>{{ parentFolderName || t('proofing_gallery', 'Choose parent folder') }}</strong>
						<span>{{ t('proofing_gallery', 'The new project folder will be created here.') }}</span>
					</button>
					<NcTextField id="proofing-gallery-folder-name" v-model="newFolderName" :label="t('proofing_gallery', 'New folder name')" />
				</div>
			</div>

			<footer>
				<NcButton v-if="step === 1" @click="emit('close')">
					{{ t('proofing_gallery', 'Cancel') }}
				</NcButton>
				<NcButton v-else @click="step = 1">
					{{ t('proofing_gallery', 'Back') }}
				</NcButton>
				<NcButton v-if="step === 1" variant="primary" @click="continueToSource">
					{{ t('proofing_gallery', 'Continue') }}
				</NcButton>
				<NcButton v-else
					type="submit"
					variant="primary"
					:disabled="!canSubmit">
					{{ saving ? t('proofing_gallery', 'Creating…') : t('proofing_gallery', 'Create project') }}
				</NcButton>
			</footer>
		</form>
	</NcDialog>
</template>

<style scoped>
.project-wizard { padding: 0 32px 28px; }

.wizard-progress { display: grid; grid-template-columns: 1fr 1fr; margin-bottom: 16px; border-bottom: 1px solid var(--color-border); color: var(--color-text-maxcontrast); }

.wizard-progress span { padding: 14px 0 12px; border-bottom: 2px solid transparent; }

.wizard-progress span[aria-current='step'] { border-color: var(--color-primary-element); color: var(--color-main-text); font-weight: 650; }

.purpose-list { display: grid; }

.purpose-list label { display: grid; grid-template-columns: 44px 1fr 32px; align-items: center; min-height: 78px; border-bottom: 1px solid var(--color-border); cursor: pointer; transition: background-color 160ms ease; }

.purpose-list label:hover, .purpose-list label.selected { background: var(--color-background-hover); }

.purpose-list input { position: absolute; opacity: 0; }

.purpose-number { color: var(--color-text-maxcontrast); font-variant-numeric: tabular-nums; }

.purpose-list label > span:nth-of-type(2) { display: grid; gap: 4px; }

.purpose-list strong { font-size: 18px; letter-spacing: -0.015em; }

.purpose-list small { color: var(--color-text-maxcontrast); font-size: 14px; }

.purpose-arrow { font-size: 22px; opacity: 0; transition: opacity 160ms ease; }

.purpose-list label.selected .purpose-arrow { opacity: 1; }

.source-setup { display: grid; gap: 24px; padding: 12px 0; }

.source-setup fieldset { margin: 0; padding: 0; border: 0; }

.source-setup legend { margin-bottom: 10px; font-weight: 650; }

.source-options { display: grid; grid-template-columns: repeat(3, 1fr); border: 1px solid var(--color-border); border-radius: 8px; overflow: hidden; }

.source-options label { display: grid; gap: 5px; min-height: 112px; padding: 16px; border-inline-end: 1px solid var(--color-border); cursor: pointer; }

.source-options label:last-child { border: 0; }

.source-options label.selected { background: var(--color-background-hover); box-shadow: inset 0 -3px var(--color-primary-element); }

.source-options input { width: 18px; height: 18px; }

.source-options span, .folder-choice span { color: var(--color-text-maxcontrast); line-height: 1.35; }

.folder-choice { display: grid; gap: 4px; width: 100%; min-height: 72px; padding: 14px 16px; border: 1px solid var(--color-border-maxcontrast); border-radius: 8px; background: var(--color-main-background); color: var(--color-main-text); text-align: start; cursor: pointer; }

.folder-choice:hover { border-color: var(--color-primary-element); }

.new-folder-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; align-items: start; }

footer { display: flex; justify-content: flex-end; gap: 8px; padding-top: 24px; }
@media (max-width: 700px) {
	.project-wizard { padding: 0 18px 20px; }
	.source-options, .new-folder-fields { grid-template-columns: 1fr; }
	.source-options label { min-height: 88px; border-inline-end: 0; border-bottom: 1px solid var(--color-border); }
	.purpose-list label { grid-template-columns: 36px 1fr 24px; min-height: 88px; }
}
@media (prefers-reduced-motion: reduce) { .purpose-list label, .purpose-arrow { transition: none; } }
</style>
