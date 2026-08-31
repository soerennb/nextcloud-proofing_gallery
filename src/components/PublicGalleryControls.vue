<script setup lang="ts">
/* eslint-disable vue/no-deprecated-slot-attribute -- Ionic Vue maps Web Component slots through the slot attribute. */
import { n, t } from '@nextcloud/l10n'
import {
	IonActionSheet,
	IonButton,
	IonButtons,
	IonIcon,
	IonInput,
	IonItem,
	IonLabel,
	IonList,
	IonModal,
	IonSearchbar,
	IonSegment,
	IonSegmentButton,
	IonSelect,
	IonSelectOption,
	IonTextarea,
	IonToolbar,
} from '@ionic/vue'
import {
	closeOutline,
	downloadOutline,
	gridOutline,
	imagesOutline,
	optionsOutline,
	swapVerticalOutline,
} from 'ionicons/icons'
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue'

type Panel = 'menu' | 'search' | 'view' | 'pages' | 'download' | 'selection' | null
type Layout = 'grid' | 'masonry' | 'list' | 'story'

const props = withDefaults(defineProps<{
	total: number
	page: number
	pageCount: number
	mobile: boolean
	panel: Panel
	canFolderGroup: boolean
	hasStory: boolean
	downloadScope: 'none' | 'individual' | 'selection' | 'all'
	selectedCount: number
	contactSheet: boolean
	canSelect: boolean
	canCompare: boolean
	canSaveSelection: boolean
	savingSelection: boolean
	theme: 'auto' | 'light' | 'dark'
	hideChrome?: boolean
}>(), { hideChrome: false })

const search = defineModel<string>('search', { required: true })
const sortBy = defineModel<'name' | 'modified' | 'size'>('sortBy', { required: true })
const sortDirection = defineModel<'asc' | 'desc'>('sortDirection', { required: true })
const groupBy = defineModel<'none' | 'type' | 'folder'>('groupBy', { required: true })
const layout = defineModel<Layout>('layout', { required: true })
const selectionName = defineModel<string>('selectionName', { required: true })
const selectionMessage = defineModel<string>('selectionMessage', { required: true })

const emit = defineEmits<{
	apply: []
	search: []
	navigate: [page: number]
	'update:panel': [panel: Panel]
	'select-downloads': []
	'download-gallery': []
	'download-selection': []
	'contact-sheet': []
	'start-selection': []
	'compare-selection': []
	'save-selection': []
}>()

const searchInput = useTemplateRef<InstanceType<typeof IonSearchbar>>('searchInput')
const canDownloadSelection = computed(() => ['selection', 'all'].includes(props.downloadScope))
const actionSheetClass = computed(() => ['gallery-action-sheet', `proofing-action-sheet--${props.theme}`])
const jumpPage = ref(props.page)
const pageNumbers = computed(() => {
	if (props.pageCount <= 7) return Array.from({ length: props.pageCount }, (_, index) => index + 1)
	const values = new Set([1, props.pageCount, props.page - 1, props.page, props.page + 1])
	return [...values].filter(value => value >= 1 && value <= props.pageCount).sort((left, right) => left - right)
})
const menuButtons = computed(() => props.selectedCount > 0
	? [
			...(canDownloadSelection.value ? [{ text: t('proofing_gallery', 'Download ZIP'), handler: () => emit('download-selection') }] : []),
			...(props.contactSheet ? [{ text: t('proofing_gallery', 'Print contact sheet'), handler: () => emit('contact-sheet') }] : []),
			...(props.canCompare ? [{ text: t('proofing_gallery', 'Compare'), handler: () => emit('compare-selection') }] : []),
			...(props.canSaveSelection ? [{ text: t('proofing_gallery', 'Save selection'), handler: () => emit('update:panel', 'selection') }] : []),
			{ text: t('proofing_gallery', 'Cancel'), role: 'cancel' },
		]
	: [
			...(props.canSelect ? [{ text: t('proofing_gallery', 'Select'), handler: () => emit('start-selection') }] : []),
			{ text: t('proofing_gallery', 'Display'), handler: () => emit('update:panel', 'view') },
			...(props.downloadScope === 'all' ? [{ text: t('proofing_gallery', 'Download entire gallery'), icon: downloadOutline, handler: () => emit('download-gallery') }] : []),
			...(props.downloadScope !== 'none' ? [{ text: props.downloadScope === 'all' ? t('proofing_gallery', 'More download options') : t('proofing_gallery', 'Download'), handler: () => emit('update:panel', 'download') }] : []),
			{ text: t('proofing_gallery', 'Cancel'), role: 'cancel' },
		])

watch(() => props.panel, async panel => {
	if (panel !== 'search') return
	await nextTick()
	const searchbar = searchInput.value?.$el as HTMLElement & { setFocus?: () => Promise<void> }
	await searchbar?.setFocus?.()
})
watch(() => props.page, page => { jumpPage.value = page })

function navigateToInputPage() {
	emit('navigate', Math.max(1, Math.min(props.pageCount, Math.round(jumpPage.value))))
}

function dismissMenu() {
	if (props.panel === 'menu') emit('update:panel', null)
}
</script>

<template>
	<IonActionSheet v-if="!hideChrome"
		:is-open="panel === 'menu'"
		:css-class="actionSheetClass"
		:header="t('proofing_gallery', 'Gallery options')"
		:buttons="menuButtons"
		@did-dismiss="dismissMenu" />
	<IonModal v-if="!hideChrome && panel !== 'menu'"
		:is-open="panel !== null"
		class="gallery-sheet"
		:initial-breakpoint="mobile ? 0.72 : 1"
		:breakpoints="mobile ? [0, 0.42, 0.72, 1] : [0, 1]"
		:handle="mobile"
		handle-behavior="cycle"
		@did-dismiss="emit('update:panel', null)">
		<div class="gallery-sheet__content">
			<IonToolbar>
				<strong>{{ panel === 'search' ? t('proofing_gallery', 'Search photos') : panel === 'view' ? t('proofing_gallery', 'Display') : panel === 'pages' ? t('proofing_gallery', 'Choose page') : panel === 'selection' ? t('proofing_gallery', 'Save selection') : t('proofing_gallery', 'Download') }}</strong>
				<IonButtons slot="end">
					<IonButton :aria-label="t('proofing_gallery', 'Close')" @click="emit('update:panel', null)">
						<IonIcon slot="icon-only" :icon="closeOutline" />
					</IonButton>
				</IonButtons>
			</IonToolbar>

			<div v-if="panel === 'search'" class="gallery-sheet__section">
				<IonSearchbar ref="searchInput"
					v-model="search"
					:placeholder="t('proofing_gallery', 'Search photos')"
					@ion-input="emit('search')" />
			</div>

			<div v-else-if="panel === 'view'" class="gallery-sheet__section gallery-sheet__display">
				<IonLabel>{{ t('proofing_gallery', 'Layout') }}</IonLabel>
				<IonSegment v-model="layout">
					<IonSegmentButton value="grid">
						<IonIcon :icon="gridOutline" /><IonLabel>{{ t('proofing_gallery', 'Grid') }}</IonLabel>
					</IonSegmentButton>
					<IonSegmentButton value="masonry">
						<IonIcon :icon="imagesOutline" /><IonLabel>{{ t('proofing_gallery', 'Masonry') }}</IonLabel>
					</IonSegmentButton>
					<IonSegmentButton value="list">
						<IonIcon :icon="optionsOutline" /><IonLabel>{{ t('proofing_gallery', 'List') }}</IonLabel>
					</IonSegmentButton>
					<IonSegmentButton v-if="hasStory" value="story">
						<IonLabel>{{ t('proofing_gallery', 'Story') }}</IonLabel>
					</IonSegmentButton>
				</IonSegment>
				<IonList inset>
					<IonItem>
						<IonSelect v-model="sortBy"
							:label="t('proofing_gallery', 'Sort')"
							label-placement="stacked"
							interface="popover"
							@ion-change="emit('apply')">
							<IonSelectOption value="name">
								{{ t('proofing_gallery', 'Filename') }}
							</IonSelectOption><IonSelectOption value="modified">
								{{ t('proofing_gallery', 'Last changed') }}
							</IonSelectOption><IonSelectOption value="size">
								{{ t('proofing_gallery', 'File size') }}
							</IonSelectOption>
						</IonSelect>
					</IonItem>
					<IonItem button @click="sortDirection = sortDirection === 'asc' ? 'desc' : 'asc'; emit('apply')">
						<IonIcon slot="start" :icon="swapVerticalOutline" /><IonLabel>{{ sortDirection === 'asc' ? t('proofing_gallery', 'Ascending') : t('proofing_gallery', 'Descending') }}</IonLabel>
					</IonItem>
					<IonItem>
						<IonSelect v-model="groupBy"
							:label="t('proofing_gallery', 'Group')"
							label-placement="stacked"
							interface="popover"
							@ion-change="emit('apply')">
							<IonSelectOption value="none">
								{{ t('proofing_gallery', 'None') }}
							</IonSelectOption><IonSelectOption value="type">
								{{ t('proofing_gallery', 'File type') }}
							</IonSelectOption><IonSelectOption v-if="canFolderGroup" value="folder">
								{{ t('proofing_gallery', 'Folder') }}
							</IonSelectOption>
						</IonSelect>
					</IonItem>
				</IonList>
			</div>

			<div v-else-if="panel === 'pages'" class="gallery-sheet__section gallery-sheet__pages">
				<p>{{ n('proofing_gallery', '%n photo', '%n photos', total) }}</p>
				<div>
					<IonButton v-for="value in pageNumbers"
						:key="value"
						:fill="value === page ? 'solid' : 'outline'"
						@click="emit('navigate', value)">
						{{ value }}
					</IonButton>
				</div>
				<form @submit.prevent="navigateToInputPage">
					<IonInput v-model.number="jumpPage"
						type="number"
						inputmode="numeric"
						:label="t('proofing_gallery', 'Go to page')"
						label-placement="stacked"
						:min="1"
						:max="pageCount" /><IonButton type="submit">
							{{ t('proofing_gallery', 'Go') }}
						</IonButton>
				</form>
			</div>

			<div v-else-if="panel === 'selection'" class="gallery-sheet__section">
				<IonList inset>
					<IonItem>
						<IonInput v-model="selectionName"
							:label="t('proofing_gallery', 'Selection name')"
							label-placement="stacked"
							:maxlength="120" />
					</IonItem>
					<IonItem>
						<IonTextarea v-model="selectionMessage"
							:label="t('proofing_gallery', 'Message (optional)')"
							label-placement="stacked"
							:maxlength="2000"
							auto-grow />
					</IonItem>
				</IonList>
				<IonButton expand="block"
					:disabled="savingSelection || !selectionName.trim()"
					@click="emit('save-selection')">
					{{ savingSelection ? t('proofing_gallery', 'Saving…') : t('proofing_gallery', 'Save selection') }}
				</IonButton>
			</div>

			<IonList v-else inset class="gallery-sheet__downloads">
				<IonItem v-if="downloadScope === 'all'" button @click="emit('download-gallery')">
					<IonIcon slot="start" :icon="downloadOutline" /><IonLabel><strong>{{ t('proofing_gallery', 'Download entire gallery') }}</strong><p>{{ t('proofing_gallery', 'Original files in one ZIP archive') }}</p></IonLabel>
				</IonItem>
				<IonItem v-if="downloadScope === 'individual'" lines="none">
					<IonIcon slot="start" :icon="imagesOutline" /><IonLabel><strong>{{ t('proofing_gallery', 'Open a photo to download it') }}</strong></IonLabel>
				</IonItem>
				<IonItem v-if="canDownloadSelection && selectedCount === 0" button @click="emit('select-downloads')">
					<IonIcon slot="start" :icon="imagesOutline" /><IonLabel><strong>{{ t('proofing_gallery', 'Choose photos') }}</strong><p>{{ t('proofing_gallery', 'Select photos across pages, then download them together.') }}</p></IonLabel>
				</IonItem>
				<IonItem v-if="canDownloadSelection && selectedCount > 0" button @click="emit('download-selection')">
					<IonIcon slot="start" :icon="downloadOutline" /><IonLabel><strong>{{ n('proofing_gallery', 'Download %n selected photo', 'Download %n selected photos', selectedCount) }}</strong><p>{{ t('proofing_gallery', 'ZIP archive') }}</p></IonLabel>
				</IonItem>
				<IonItem v-if="contactSheet && selectedCount > 0" button @click="emit('contact-sheet')">
					<IonIcon slot="start" :icon="imagesOutline" /><IonLabel><strong>{{ t('proofing_gallery', 'Create contact sheet') }}</strong><p>{{ t('proofing_gallery', 'Printable PDF') }}</p></IonLabel>
				</IonItem>
			</IonList>
		</div>
	</IonModal>
</template>

<style scoped src="./styles/PublicGalleryControls.css"></style>
