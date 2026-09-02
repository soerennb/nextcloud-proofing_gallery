<script setup lang="ts">
import { FilePickerType, getFilePickerBuilder, showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref, watch } from 'vue'

import { fallbackProjectCreationOptions, projectPurposeCopy, validSourceModes } from '../domain/projectCreation.ts'
import type { BuiltInGalleryPurpose, ProjectCreationOptions, ProjectDeliveryMode, ProjectSourceMode } from '../domain/projectCreation.ts'
import { createProject, fetchPresets, fetchUserPreferences, updateUserPreferences } from '../services/galleryApi.ts'
import type { Gallery, GalleryPreset } from '../types.ts'

defineProps<{ show: boolean }>()
const emit = defineEmits<{ close: []; created: [gallery: Gallery] }>()

const purposeOrder: BuiltInGalleryPurpose[] = ['delivery', 'showcase', 'selection', 'proofing', 'uploads']
const options = ref<ProjectCreationOptions>(fallbackProjectCreationOptions())
const step = ref<1 | 2>(1)
const purpose = ref<BuiltInGalleryPurpose>('delivery')
const sourceMode = ref<ProjectSourceMode>('existing')
const deliveryMode = ref<ProjectDeliveryMode>('standard')
const title = ref('')
const folderId = ref<number | null>(null)
const folderName = ref('')
const parentFolderId = ref<number | null>(null)
const parentFolderName = ref('')
const newFolderName = ref('')
const saving = ref(false)
const creationAllowed = ref(true)
const presets = ref<GalleryPreset[]>([])
const presetMode = ref<'inherit' | 'instance' | 'preset'>('inherit')
const presetId = ref<number | null>(null)
const rememberPreset = ref(false)
const liveMessage = ref('')

const copy = computed(() => projectPurposeCopy(purpose.value))
const recipe = computed(() => options.value[purpose.value])
const availableSources = computed(() => validSourceModes(options.value, purpose.value, deliveryMode.value))
const usesExistingFolder = computed(() => sourceMode.value === 'existing')
const purposeChoices = computed(() => purposeOrder.map(id => ({ id, ...projectPurposeCopy(id) })))
const audienceChoices = computed(() => recipe.value.deliveryModes.map(id => ({
	id,
	title: id === 'event' ? copy.value.eventTitle : copy.value.standardTitle,
	description: id === 'event' ? copy.value.eventDescription : copy.value.standardDescription,
})))
const sourceChoices = computed(() => availableSources.value.map(id => ({
	id,
	title: id === 'existing'
		? (purpose.value === 'uploads' ? t('proofing_gallery', 'Existing inbox folder') : t('proofing_gallery', 'Existing photo folder'))
		: id === 'new'
			? (purpose.value === 'uploads' ? t('proofing_gallery', 'New upload inbox') : t('proofing_gallery', 'New project folder'))
			: t('proofing_gallery', 'Curated collection'),
	description: id === 'existing'
		? (purpose.value === 'uploads' ? t('proofing_gallery', 'Receive uploads in a folder that already exists.') : t('proofing_gallery', 'Use photos already stored in Nextcloud.'))
		: id === 'new'
			? (purpose.value === 'uploads' ? t('proofing_gallery', 'Create an empty folder for incoming client files.') : t('proofing_gallery', 'Create the folder now and add photos next.'))
			: t('proofing_gallery', 'Combine selected photos from several galleries.'),
})))

const canSubmit = computed(() => title.value.trim() !== '' && !saving.value
	&& (presetMode.value !== 'preset' || presetId.value !== null) && (
	sourceMode.value === 'collection'
	|| (usesExistingFolder.value && folderId.value !== null)
	|| (sourceMode.value === 'new' && parentFolderId.value !== null && newFolderName.value.trim() !== '')
))
const sourceSummary = computed(() => sourceChoices.value.find(item => item.id === sourceMode.value)?.title ?? '')
const audienceSummary = computed(() => audienceChoices.value.find(item => item.id === deliveryMode.value)?.title ?? '')
const designSummary = computed(() => presetMode.value === 'inherit'
	? t('proofing_gallery', 'My default design')
	: presetMode.value === 'instance'
		? t('proofing_gallery', 'Studio default')
		: presets.value.find(item => item.id === presetId.value)?.name ?? t('proofing_gallery', 'Saved design'))

onMounted(async () => {
	try {
		const response = await fetchUserPreferences()
		options.value = response.projectCreationOptions ?? fallbackProjectCreationOptions()
		creationAllowed.value = response.effectiveCapabilities.galleryCreation.allowed
		const preferredPurpose = response.preferences.defaultPurpose ?? response.instanceDefaultPurpose
		if (preferredPurpose !== 'custom') purpose.value = preferredPurpose
		if (response.preferences.designPresetId !== null) presetId.value = response.preferences.designPresetId
		presets.value = await fetchPresets()
		if (response.preferences.parentFolder) {
			parentFolderId.value = response.preferences.parentFolder.id
			parentFolderName.value = response.preferences.parentFolder.name
			return
		}
		const stored = JSON.parse(localStorage.getItem('proofing-gallery:last-parent') ?? 'null') as { id?: number; name?: string } | null
		if (stored?.id) {
			parentFolderId.value = stored.id
			parentFolderName.value = stored.name ?? ''
			await updateUserPreferences({ parentFolder: { id: stored.id, name: stored.name ?? '' } })
			localStorage.removeItem('proofing-gallery:last-parent')
		}
	} catch { /* Optional preferences do not block project creation. */ }
})

watch(title, value => {
	if (sourceMode.value === 'new' && newFolderName.value === '') newFolderName.value = value
})

watch(purpose, value => {
	// Before the setup step is filled, a newly selected job starts with its
	// recommended recipe. Returning later preserves every still-valid choice.
	if (step.value === 1 && title.value === '' && folderId.value === null && newFolderName.value === '') {
		deliveryMode.value = options.value[value].defaults.deliveryMode
		sourceMode.value = options.value[value].defaults.sourceMode
	}
})

watch([purpose, deliveryMode], () => {
	const currentRecipe = options.value[purpose.value]
	let adjusted = false
	if (!currentRecipe.deliveryModes.includes(deliveryMode.value)) {
		deliveryMode.value = currentRecipe.defaults.deliveryMode
		adjusted = true
	}
	const sources = validSourceModes(options.value, purpose.value, deliveryMode.value)
	if (!sources.includes(sourceMode.value)) {
		sourceMode.value = sources.includes(currentRecipe.defaults.sourceMode) ? currentRecipe.defaults.sourceMode : sources[0]
		adjusted = true
	}
	if (adjusted) liveMessage.value = t('proofing_gallery', 'The setup was adjusted to match the selected project type.')
})

async function chooseFolder(kind: 'source' | 'parent') {
	try {
		const nodes = await getFilePickerBuilder(kind === 'source'
			? t('proofing_gallery', 'Choose the project folder')
			: t('proofing_gallery', 'Choose where the project folder will be created'))
			.setMultiSelect(false).allowDirectories().setType(FilePickerType.Choose)
			.setCanPick(node => node.type === 'folder').build().pickNodes()
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
			updateUserPreferences({ parentFolder: { id: folder.fileid, name: folder.displayname } }).catch(() => undefined)
		}
	} catch { /* Closing the picker is not an error. */ }
}

function continueToSource() {
	step.value = 2
	if (parentFolderId.value !== null) return
	try {
		const stored = JSON.parse(localStorage.getItem('proofing-gallery:last-parent') ?? 'null') as { id?: number; name?: string } | null
		if (stored?.id) {
			parentFolderId.value = stored.id
			parentFolderName.value = stored.name ?? ''
		}
	} catch { /* Ignore stale browser data. */ }
}

async function submit() {
	if (!canSubmit.value || !creationAllowed.value) return
	saving.value = true
	try {
		const gallery = await createProject({
			title: title.value.trim(), purpose: purpose.value, sourceMode: sourceMode.value,
			folderId: usesExistingFolder.value ? folderId.value : null,
			parentFolderId: sourceMode.value === 'new' ? parentFolderId.value : null,
			folderName: sourceMode.value === 'new' ? newFolderName.value.trim() : undefined,
			deliveryMode: deliveryMode.value, designPreset: selectedDesignPreset(),
		})
		if (rememberPreset.value && presetMode.value !== 'inherit') {
			await updateUserPreferences({ designPresetId: presetMode.value === 'preset' ? presetId.value : null })
		}
		emit('created', gallery)
		reset()
	} catch (error) {
		const message = typeof error === 'object' && error !== null && 'response' in error
			? (error as { response?: { data?: { message?: string } } }).response?.data?.message
			: null
		showError(message || t('proofing_gallery', 'The project could not be created.'))
	} finally { saving.value = false }
}

function selectedDesignPreset(): { mode: 'inherit' | 'instance' } | { mode: 'preset'; id: number } {
	if (presetMode.value === 'preset' && presetId.value !== null) return { mode: 'preset', id: presetId.value }
	return { mode: presetMode.value === 'preset' ? 'inherit' : presetMode.value }
}

function reset() {
	step.value = 1; purpose.value = 'delivery'; sourceMode.value = 'existing'; deliveryMode.value = 'standard'
	title.value = ''; folderId.value = null; folderName.value = ''; newFolderName.value = ''
	presetMode.value = 'inherit'; presetId.value = null; rememberPreset.value = false; liveMessage.value = ''
}
</script>

<template>
	<NcDialog :open="show"
		:name="step === 1 ? t('proofing_gallery', 'Create a project') : copy.title"
		size="large"
		@update:open="open => !open && emit('close')">
		<form class="project-wizard" @submit.prevent="submit">
			<p class="sr-only" aria-live="polite">
				{{ liveMessage }}
			</p>
			<p v-if="!creationAllowed" class="wizard-policy-message" role="alert">
				{{ t('proofing_gallery', 'Gallery creation was disabled by the administrator.') }}
			</p>
			<div class="wizard-progress" :aria-label="t('proofing_gallery', 'Project setup progress')">
				<span :aria-current="step === 1 ? 'step' : undefined"><b>1</b>{{ t('proofing_gallery', 'Project type') }}</span>
				<span :aria-current="step === 2 ? 'step' : undefined"><b>2</b>{{ t('proofing_gallery', 'Set up') }}</span>
			</div>
			<Transition name="wizard-step" mode="out-in">
				<div v-if="step === 1" key="purpose" class="purpose-step">
					<header class="wizard-intro">
						<span>{{ t('proofing_gallery', 'Start with the client’s job') }}</span><h2>{{ t('proofing_gallery', 'What should happen with these photos?') }}</h2><p>{{ t('proofing_gallery', 'Your choice prepares the right tools. Everything can still be refined inside the project.') }}</p>
					</header>
					<div class="purpose-list">
						<label v-for="option in purposeChoices" :key="option.id" :class="{ selected: purpose === option.id }"><input v-model="purpose"
							type="radio"
							name="purpose"
							:value="option.id"><span class="purpose-marker" aria-hidden="true" /><span><strong>{{ option.title }}</strong><small>{{ option.description }}</small></span><span class="purpose-arrow" aria-hidden="true">→</span></label>
					</div>
				</div>
				<div v-else key="setup" class="source-setup">
					<header class="selected-purpose">
						<div><span>{{ t('proofing_gallery', 'Selected project type') }}</span><h2>{{ copy.title }}</h2><p>{{ copy.description }}</p></div><button type="button" @click="step = 1">
							{{ t('proofing_gallery', 'Change') }}
						</button>
					</header>
					<NcTextField id="proofing-gallery-title"
						v-model="title"
						name="title"
						:label="t('proofing_gallery', 'Project title')"
						:placeholder="t('proofing_gallery', 'Wedding, portrait session, event…')" />
					<fieldset>
						<legend>{{ copy.audienceQuestion }}</legend><div class="choice-grid" :class="{ 'choice-grid--single': audienceChoices.length === 1 }">
							<label v-for="choice in audienceChoices" :key="choice.id" :class="{ selected: deliveryMode === choice.id }"><input v-model="deliveryMode" type="radio" :value="choice.id"><span><strong>{{ choice.title }}</strong><small>{{ choice.description }}</small></span></label>
						</div>
					</fieldset>
					<fieldset>
						<legend>{{ copy.sourceQuestion }}</legend><div class="choice-grid choice-grid--sources">
							<label v-for="choice in sourceChoices" :key="choice.id" :class="{ selected: sourceMode === choice.id }"><input v-model="sourceMode" type="radio" :value="choice.id"><span><strong>{{ choice.title }}</strong><small>{{ choice.description }}</small></span></label>
						</div>
					</fieldset>
					<button v-if="sourceMode === 'existing'"
						class="folder-choice"
						type="button"
						@click="chooseFolder('source')">
						<span><strong>{{ folderName || t('proofing_gallery', 'Choose project folder') }}</strong><small>{{ folderName ? t('proofing_gallery', 'Choose another folder') : t('proofing_gallery', 'Original files stay in this Nextcloud folder.') }}</small></span><b aria-hidden="true">→</b>
					</button>
					<div v-if="sourceMode === 'new'" class="new-folder-fields">
						<button class="folder-choice" type="button" @click="chooseFolder('parent')">
							<span><strong>{{ parentFolderName || t('proofing_gallery', 'Choose parent folder') }}</strong><small>{{ t('proofing_gallery', 'The new project folder will be created here.') }}</small></span><b aria-hidden="true">→</b>
						</button><NcTextField id="proofing-gallery-folder-name" v-model="newFolderName" :label="t('proofing_gallery', 'New folder name')" />
					</div>
					<fieldset class="design-choice">
						<legend>{{ t('proofing_gallery', 'Starting design') }}</legend><label><span>{{ t('proofing_gallery', 'Design source') }}</span><select v-model="presetMode"><option value="inherit">{{ t('proofing_gallery', 'My default design') }}</option><option value="instance">{{ t('proofing_gallery', 'Studio default') }}</option><option v-if="presets.length" value="preset">{{ t('proofing_gallery', 'Choose a saved design') }}</option></select></label><label v-if="presetMode === 'preset'"><span>{{ t('proofing_gallery', 'Saved design') }}</span><select v-model.number="presetId" required><option :value="null" disabled>{{ t('proofing_gallery', 'Choose a design') }}</option><option v-for="preset in presets" :key="preset.id" :value="preset.id">{{ preset.name }}</option></select></label><label v-if="presetMode !== 'inherit'" class="remember-design"><input v-model="rememberPreset" type="checkbox">{{ t('proofing_gallery', 'Use this design for new projects') }}</label>
					</fieldset>
					<dl class="project-summary">
						<div><dt>{{ t('proofing_gallery', 'Client experience') }}</dt><dd>{{ audienceSummary }}</dd></div><div><dt>{{ t('proofing_gallery', 'Photo source') }}</dt><dd>{{ sourceSummary }}</dd></div><div><dt>{{ t('proofing_gallery', 'Design') }}</dt><dd>{{ designSummary }}</dd></div>
					</dl>
				</div>
			</Transition>
			<footer>
				<NcButton v-if="step === 1" @click="emit('close')">
					{{ t('proofing_gallery', 'Cancel') }}
				</NcButton><NcButton v-else @click="step = 1">
					{{ t('proofing_gallery', 'Back') }}
				</NcButton><NcButton v-if="step === 1" variant="primary" @click="continueToSource">
					{{ t('proofing_gallery', 'Continue with {type}', { type: copy.title }) }}
				</NcButton><NcButton v-else
					type="submit"
					variant="primary"
					:disabled="!canSubmit || !creationAllowed">
					{{ saving ? t('proofing_gallery', 'Creating…') : t('proofing_gallery', 'Create project') }}
				</NcButton>
			</footer>
		</form>
	</NcDialog>
</template>

<style scoped>
.project-wizard{--wizard-line:var(--studio-line,var(--color-border));--wizard-muted:var(--studio-muted,var(--color-text-maxcontrast));padding:0 32px 28px;color:var(--studio-ink,var(--color-main-text))}

.wizard-policy-message{margin:0 0 14px;padding:10px 12px;border-inline-start:4px solid var(--color-warning);background:var(--studio-surface-raised,var(--color-background-dark))}

.wizard-progress{display:grid;grid-template-columns:1fr 1fr;margin-bottom:24px;border-bottom:1px solid var(--wizard-line);color:var(--wizard-muted)}

.wizard-progress span{display:flex;align-items:center;gap:8px;padding:12px 0;border-bottom:2px solid transparent}

.wizard-progress b{display:grid;width:22px;height:22px;place-items:center;border:1px solid currentColor;border-radius:50%;font-size:11px}

.wizard-progress span[aria-current=step]{border-color:var(--studio-accent,var(--color-primary-element));color:var(--studio-ink,var(--color-main-text));font-weight:700}

.wizard-intro{max-width:650px;padding:8px 0 24px}

.wizard-intro>span,.selected-purpose>div>span{color:var(--studio-accent,var(--color-primary-element));font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}

.wizard-intro h2,.selected-purpose h2{margin:5px 0 8px;font-family:NewsreaderVariable,Newsreader,serif;font-size:clamp(29px,5vw,43px);font-weight:520;letter-spacing:-.03em;line-height:1.04}

.wizard-intro p,.selected-purpose p{margin:0;color:var(--wizard-muted);line-height:1.5}

.purpose-list{display:grid;border-top:1px solid var(--wizard-line)}

.purpose-list label{display:grid;grid-template-columns:22px 1fr 32px;align-items:center;min-height:82px;gap:14px;border-bottom:1px solid var(--wizard-line);cursor:pointer;transition:padding 180ms ease,background-color 180ms ease}

.purpose-list label:hover,.purpose-list label.selected{padding-inline:12px;background:var(--studio-surface-raised,var(--color-background-hover))}

.purpose-list input{position:absolute;opacity:0}

.purpose-marker{width:12px;height:12px;border:1.5px solid var(--wizard-muted);border-radius:50%}

.purpose-list label.selected .purpose-marker{border:4px solid var(--studio-accent,var(--color-primary-element))}

.purpose-list label>span:nth-of-type(2){display:grid;gap:4px}

.purpose-list strong{font-size:17px;letter-spacing:-.015em}

.purpose-list small,.choice-grid small,.folder-choice small{color:var(--wizard-muted);font-size:13px;line-height:1.4}

.purpose-arrow{color:var(--studio-accent,var(--color-primary-element));font-size:22px;opacity:0;transform:translateX(-5px);transition:opacity 180ms ease,transform 180ms ease}

.purpose-list label.selected .purpose-arrow{opacity:1;transform:none}

.source-setup{display:grid;gap:26px}

.selected-purpose{display:flex;align-items:start;justify-content:space-between;gap:24px;padding:6px 0 20px;border-bottom:1px solid var(--wizard-line)}

.selected-purpose h2{font-size:clamp(27px,4vw,38px)}

.selected-purpose button{padding:6px 0;border:0;background:transparent;color:var(--studio-accent,var(--color-primary-element));font-weight:700;cursor:pointer}

.source-setup fieldset{margin:0;padding:0;border:0}

.source-setup legend{margin-bottom:11px;font-size:15px;font-weight:750}

.choice-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}

.choice-grid--sources{grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}

.choice-grid--single{grid-template-columns:1fr}

.choice-grid label{display:grid;grid-template-columns:20px 1fr;min-height:96px;gap:10px;padding:15px;border:1px solid var(--wizard-line);border-radius:var(--studio-radius,12px);background:var(--studio-surface,var(--color-main-background));cursor:pointer}

.choice-grid label.selected{border-color:var(--studio-accent,var(--color-primary-element));box-shadow:inset 0 -3px var(--studio-accent,var(--color-primary-element));background:var(--studio-accent-soft,var(--color-primary-element-light))}

.choice-grid input{width:18px;height:18px}

.choice-grid label span{display:grid;align-content:start;gap:5px}

.folder-choice{display:flex;align-items:center;justify-content:space-between;gap:18px;width:100%;min-height:76px;padding:15px 17px;border:1px solid var(--studio-line-strong,var(--color-border-maxcontrast));border-radius:var(--studio-radius,12px);background:var(--studio-surface,var(--color-main-background));color:inherit;text-align:start;cursor:pointer}

.folder-choice>span{display:grid;gap:4px}

.folder-choice>b{color:var(--studio-accent,var(--color-primary-element));font-size:21px}

.folder-choice:hover,.folder-choice:focus-visible{border-color:var(--studio-accent,var(--color-primary-element))}

.new-folder-fields,.design-choice{display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:start}

.design-choice{padding-top:22px!important;border-top:1px solid var(--wizard-line)!important}

.design-choice legend{grid-column:1/-1}

.design-choice label{display:grid;gap:6px}

.design-choice select{min-height:44px;padding:0 10px;border:1px solid var(--studio-line-strong,var(--color-border-maxcontrast));border-radius:9px;background:var(--studio-surface,var(--color-main-background));color:inherit}

.design-choice .remember-design{display:flex;grid-column:1/-1;align-items:center;gap:8px}

.project-summary{display:grid;grid-template-columns:repeat(3,1fr);margin:0;border-block:1px solid var(--wizard-line)}

.project-summary div{padding:13px 12px;border-inline-end:1px solid var(--wizard-line)}

.project-summary div:last-child{border:0}

.project-summary dt{color:var(--wizard-muted);font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase}

.project-summary dd{margin:4px 0 0;font-weight:700}

footer{display:flex;justify-content:flex-end;gap:8px;padding-top:26px}

.wizard-step-enter-active,.wizard-step-leave-active{transition:opacity 150ms ease,transform 180ms cubic-bezier(.2,.75,.25,1)}

.wizard-step-enter-from{opacity:0;transform:translateX(20px)}

.wizard-step-leave-to{opacity:0;transform:translateX(-12px)}

.sr-only{position:absolute;overflow:hidden;width:1px;height:1px;clip-path:inset(50%)}
@media(max-width:700px){.project-wizard{padding:0 16px 20px}.choice-grid,.choice-grid--sources,.new-folder-fields,.design-choice,.project-summary{grid-template-columns:1fr}.project-summary div{border-inline-end:0;border-bottom:1px solid var(--wizard-line)}.selected-purpose{align-items:flex-start}.wizard-intro h2{font-size:32px}.purpose-list label{grid-template-columns:20px 1fr 22px;min-height:92px}.choice-grid label{min-height:82px}}
@media(prefers-reduced-motion:reduce){.purpose-list label,.purpose-arrow,.wizard-step-enter-active,.wizard-step-leave-active{transition:none}}
</style>
