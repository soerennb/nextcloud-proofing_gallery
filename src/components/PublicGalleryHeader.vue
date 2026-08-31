<script setup lang="ts">
/* eslint-disable vue/no-deprecated-slot-attribute -- Ionic Vue maps Web Component slots through the slot attribute. */
import {
	IonButton,
	IonButtons,
	IonHeader,
	IonIcon,
	IonSearchbar,
	IonTitle,
	IonToolbar,
} from '@ionic/vue'
import { n, t } from '@nextcloud/l10n'
import {
	chevronBackOutline,
	chevronForwardOutline,
	closeOutline,
	downloadOutline,
	ellipsisHorizontal,
	gitCompareOutline,
	personCircleOutline,
	searchOutline,
	shareOutline,
} from 'ionicons/icons'
import type { GalleryTitleMode } from '../domain/galleryTitlePresentation.ts'

defineProps<{
	title: string
	titleMode: GalleryTitleMode
	studioName: string
	page: number
	pageCount: number
	searching: boolean
	selectionMode: boolean
	selectedCount: number
	canDownload: boolean
	canCompare: boolean
	collaboration: boolean
	logoUrl?: string | null
}>()

const search = defineModel<string>('search', { required: true })
const emit = defineEmits<{
	search: []
	'toggle-search': []
	share: []
	download: []
	compare: []
	more: []
	navigate: [page: number]
	pages: []
	collaboration: []
	'cancel-selection': []
}>()
</script>

<template>
	<IonHeader class="gallery-app-header" translucent>
		<IonToolbar v-if="selectionMode">
			<IonButtons slot="start">
				<IonButton :aria-label="t('proofing_gallery', 'Cancel selection')" @click="emit('cancel-selection')">
					<IonIcon slot="icon-only" :icon="closeOutline" />
				</IonButton>
			</IonButtons>
			<IonTitle>{{ n('proofing_gallery', '%n item selected', '%n items selected', selectedCount) }}</IonTitle>
			<IonButtons slot="end">
				<IonButton v-if="canCompare" :aria-label="t('proofing_gallery', 'Compare')" @click="emit('compare')">
					<IonIcon slot="icon-only" :icon="gitCompareOutline" />
				</IonButton>
				<IonButton v-if="canDownload"
					:aria-label="t('proofing_gallery', 'Download')"
					:disabled="selectedCount === 0"
					@click="emit('download')">
					<IonIcon slot="icon-only" :icon="downloadOutline" />
				</IonButton>
				<IonButton :aria-label="t('proofing_gallery', 'More options')" @click="emit('more')">
					<IonIcon slot="icon-only" :icon="ellipsisHorizontal" />
				</IonButton>
			</IonButtons>
		</IonToolbar>

		<template v-else>
			<IonToolbar v-if="searching" class="gallery-app-header__search">
				<IonSearchbar v-model="search"
					:placeholder="t('proofing_gallery', 'Search photos')"
					show-cancel-button="always"
					@ion-input="emit('search')"
					@ion-cancel="emit('toggle-search')" />
			</IonToolbar>
			<IonToolbar v-else>
				<IonButtons v-if="logoUrl" slot="start" class="gallery-app-header__brand">
					<img :src="logoUrl" :alt="t('proofing_gallery', 'Gallery logo')">
				</IonButtons>
				<IonTitle v-if="titleMode === 'compact' || studioName" class="gallery-app-header__identity">
					<span v-if="studioName" class="gallery-app-header__studio">{{ studioName }}</span>
					<h1 v-if="titleMode === 'compact'" class="gallery-app-header__title">
						{{ title }}
					</h1>
				</IonTitle>
				<IonButtons slot="end">
					<IonButton :aria-label="t('proofing_gallery', 'Search photos')" @click="emit('toggle-search')">
						<IonIcon slot="icon-only" :icon="searchOutline" />
					</IonButton>
					<IonButton v-if="canDownload" :aria-label="t('proofing_gallery', 'Download')" @click="emit('download')">
						<IonIcon slot="icon-only" :icon="downloadOutline" />
					</IonButton>
					<IonButton :aria-label="t('proofing_gallery', 'Share')" @click="emit('share')">
						<IonIcon slot="icon-only" :icon="shareOutline" />
					</IonButton>
					<IonButton v-if="collaboration" :aria-label="t('proofing_gallery', 'Review details')" @click="emit('collaboration')">
						<IonIcon slot="icon-only" :icon="personCircleOutline" />
					</IonButton>
					<IonButton :aria-label="t('proofing_gallery', 'More options')" @click="emit('more')">
						<IonIcon slot="icon-only" :icon="ellipsisHorizontal" />
					</IonButton>
				</IonButtons>
			</IonToolbar>

			<IonToolbar v-if="pageCount > 1" class="gallery-app-header__pager">
				<IonButtons slot="start">
					<IonButton :disabled="page <= 1" :aria-label="t('proofing_gallery', 'Previous page')" @click="emit('navigate', page - 1)">
						<IonIcon slot="icon-only" :icon="chevronBackOutline" />
					</IonButton>
				</IonButtons>
				<IonTitle>
					<IonButton fill="clear" @click="emit('pages')">
						{{ t('proofing_gallery', 'Page {page} of {pages}', { page, pages: pageCount }) }}
					</IonButton>
				</IonTitle>
				<IonButtons slot="end">
					<IonButton :disabled="page >= pageCount" :aria-label="t('proofing_gallery', 'Next page')" @click="emit('navigate', page + 1)">
						<IonIcon slot="icon-only" :icon="chevronForwardOutline" />
					</IonButton>
				</IonButtons>
			</IonToolbar>
		</template>
	</IonHeader>
</template>

<style scoped src="./styles/PublicGalleryHeader.css"></style>
