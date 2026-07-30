<script setup lang="ts">
import { FilePickerType, getFilePickerBuilder, showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref } from 'vue'

import { createGallery } from '../services/galleryApi.ts'
import type { Gallery } from '../types.ts'

defineProps<{ show: boolean }>()

const emit = defineEmits<{
	close: []
	created: [gallery: Gallery]
}>()

const title = ref('')
const folderId = ref<number | null>(null)
const folderName = ref('')
const mode = ref<'presentation' | 'collaboration'>('presentation')
const saving = ref(false)
const canSubmit = computed(() => title.value.trim() !== '' && folderId.value !== null && !saving.value)

async function chooseFolder() {
	try {
		const nodes = await getFilePickerBuilder(t('proofing_gallery', 'Choose the gallery folder'))
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
		folderId.value = folder.fileid
		folderName.value = folder.displayname
		if (title.value === '') {
			title.value = folder.displayname
		}
	} catch {
		// Closing the picker is not an error.
	}
}

async function submit() {
	if (!canSubmit.value || folderId.value === null) {
		return
	}
	saving.value = true
	try {
		const gallery = await createGallery({
			folderId: folderId.value,
			title: title.value.trim(),
			mode: mode.value,
		})
		emit('created', gallery)
		reset()
	} catch {
		showError(t('proofing_gallery', 'The gallery could not be created.'))
	} finally {
		saving.value = false
	}
}

function reset() {
	title.value = ''
	folderId.value = null
	folderName.value = ''
	mode.value = 'presentation'
}

function updateOpen(open: boolean) {
	if (!open) emit('close')
}
</script>

<template>
	<NcDialog
		:open="show"
		:name="t('proofing_gallery', 'Create gallery')"
		size="normal"
		@update:open="updateOpen">
		<form class="create-gallery" @submit.prevent="submit">
			<div class="create-gallery__intro">
				<p>
					{{ t('proofing_gallery', 'The original files stay in their Nextcloud folder. The gallery only keeps a reference.') }}
				</p>
			</div>

			<section class="create-gallery__section">
				<h3>{{ t('proofing_gallery', 'Source folder') }}</h3>
				<button class="folder-choice" type="button" @click="chooseFolder">
					<span class="folder-choice__mark" aria-hidden="true" />
					<span>
						<strong>{{ folderName || t('proofing_gallery', 'Choose a folder') }}</strong>
						<small>{{ folderName ? t('proofing_gallery', 'Change folder') : t('proofing_gallery', 'Images and supported videos will be shown') }}</small>
					</span>
				</button>
			</section>

			<section class="create-gallery__section">
				<NcTextField
					id="proofing-gallery-title"
					v-model="title"
					name="title"
					:label="t('proofing_gallery', 'Gallery title')"
					:placeholder="t('proofing_gallery', 'Wedding, portrait session, event…')" />
			</section>

			<fieldset class="create-gallery__section">
				<legend>{{ t('proofing_gallery', 'Starting mode') }}</legend>
				<div class="mode-options">
					<label :class="{ 'mode-option--selected': mode === 'presentation' }">
						<input v-model="mode"
							name="mode"
							type="radio"
							value="presentation">
						<strong>{{ t('proofing_gallery', 'Presentation') }}</strong>
						<span>{{ t('proofing_gallery', 'A quiet, image-first delivery gallery.') }}</span>
					</label>
					<label :class="{ 'mode-option--selected': mode === 'collaboration' }">
						<input v-model="mode"
							name="mode"
							type="radio"
							value="collaboration">
						<strong>{{ t('proofing_gallery', 'Proofing') }}</strong>
						<span>{{ t('proofing_gallery', 'Prepare likes, colors, comments and selections.') }}</span>
					</label>
				</div>
			</fieldset>

			<footer class="create-gallery__actions">
				<NcButton @click="emit('close')">
					{{ t('proofing_gallery', 'Cancel') }}
				</NcButton>
				<NcButton type="submit" variant="primary" :disabled="!canSubmit">
					{{ saving ? t('proofing_gallery', 'Creating…') : t('proofing_gallery', 'Create draft') }}
				</NcButton>
			</footer>
			<p v-if="!canSubmit && !saving" class="create-gallery__requirement">
				{{ t('proofing_gallery', 'Choose a folder and enter a title to create the draft.') }}
			</p>
		</form>
	</NcDialog>
</template>

<style scoped>
.create-gallery {
	padding: 32px;
}

.create-gallery__intro {
	max-width: 560px;
	margin-bottom: 20px;
}

.create-gallery__intro p {
	margin: 0;
	color: var(--color-text-maxcontrast);
	line-height: 1.5;
}

.create-gallery__section {
	padding: 20px 0;
	border: 0;
	border-top: 1px solid var(--color-border);
}

.create-gallery__section h3,
.create-gallery__section legend {
	margin: 0 0 12px;
	padding: 0;
	font-size: 14px;
	font-weight: 650;
}

.folder-choice {
	display: flex;
	width: 100%;
	align-items: center;
	gap: 14px;
	padding: 14px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 8px;
	background: transparent;
	color: var(--color-main-text);
	text-align: start;
	cursor: pointer;
}

.folder-choice:hover,
.folder-choice:focus-visible {
	border-color: var(--color-primary-element);
	background: var(--color-background-hover);
}

.folder-choice__mark {
	width: 34px;
	height: 25px;
	border-radius: 3px;
	background: var(--color-primary-element);
	clip-path: polygon(0 16%, 38% 16%, 46% 0, 100% 0, 100% 100%, 0 100%);
}

.folder-choice strong,
.folder-choice small {
	display: block;
}

.folder-choice small {
	margin-top: 2px;
	color: var(--color-text-maxcontrast);
}

.mode-options {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
}

.mode-options label {
	position: relative;
	min-height: 104px;
	padding: 14px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	cursor: pointer;
}

.mode-options label:hover {
	background: var(--color-background-hover);
}

.mode-options label.mode-option--selected {
	border-color: var(--color-primary-element);
	box-shadow: inset 0 0 0 1px var(--color-primary-element);
}

.mode-options input {
	position: absolute;
	opacity: 0;
}

.mode-options strong,
.mode-options span {
	display: block;
}

.mode-options span {
	margin-top: 6px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	line-height: 1.4;
}

.create-gallery__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	padding-top: 20px;
	border-top: 1px solid var(--color-border);
}

.create-gallery__requirement {
	margin: 8px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	text-align: end;
}

@media (max-width: 600px) {
	.create-gallery {
		padding: 20px;
	}

	.mode-options {
		grid-template-columns: 1fr;
	}
}
</style>
