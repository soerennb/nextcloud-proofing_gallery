<script setup lang="ts">
import { n, t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { defineAsyncComponent, onMounted, ref, watch } from 'vue'

import GalleryList from './components/GalleryList.vue'
import { archiveGallery, fetchGalleries, restoreGallery } from './services/galleryApi.ts'
import type { Gallery } from './types.ts'

const CreateGalleryModal = defineAsyncComponent(() => import('./components/CreateGalleryModal.vue'))
const GallerySettings = defineAsyncComponent(() => import('./components/GallerySettings.vue'))
const SharingModal = defineAsyncComponent(() => import('./components/SharingModal.vue'))

const galleries = ref<Gallery[]>([])
const loading = ref(true)
const archived = ref(false)
const search = ref('')
const showCreate = ref(false)
const selectedGallery = ref<Gallery | null>(null)
const shareGallery = ref<Gallery | null>(null)
let searchTimer: ReturnType<typeof setTimeout> | undefined

async function notify(kind: 'error' | 'success', message: string) {
	const dialogs = await import('@nextcloud/dialogs')
	if (kind === 'error') dialogs.showError(message)
	else dialogs.showSuccess(message)
}

async function load() {
	loading.value = true
	try {
		galleries.value = (await fetchGalleries(archived.value, search.value)).items
		const match = window.location.hash.match(/^#gallery\/(\d+)/)
		if (match && !selectedGallery.value) {
			selectedGallery.value = galleries.value.find(gallery => gallery.id === Number(match[1])) ?? null
		}
	} catch {
		notify('error', t('proofing_gallery', 'Galleries could not be loaded.')).catch(() => {})
	} finally {
		loading.value = false
	}
}

function created(gallery: Gallery) {
	showCreate.value = false
	galleries.value.unshift(gallery)
	notify('success', t('proofing_gallery', 'Gallery draft created.')).catch(() => {})
}

function selectGallery(gallery: Gallery) {
	selectedGallery.value = gallery
	window.location.hash = `gallery/${gallery.id}`
}

function updateSelected(gallery: Gallery) {
	selectedGallery.value = gallery
	if (shareGallery.value?.id === gallery.id) {
		shareGallery.value = gallery
	}
	const index = galleries.value.findIndex(item => item.id === gallery.id)
	if (index !== -1) {
		galleries.value[index] = gallery
	}
}

function closeSettings() {
	selectedGallery.value = null
	history.replaceState(null, '', window.location.pathname)
}

async function archive(gallery: Gallery) {
	if (!window.confirm(t('proofing_gallery', 'Archive “{title}”? Its public link will stop working until the gallery is restored.', { title: gallery.title }))) {
		return
	}
	try {
		await archiveGallery(gallery.id)
		galleries.value = galleries.value.filter(item => item.id !== gallery.id)
		notify('success', t('proofing_gallery', 'Gallery archived.')).catch(() => {})
	} catch {
		notify('error', t('proofing_gallery', 'The gallery could not be archived.')).catch(() => {})
	}
}

async function restore(gallery: Gallery) {
	try {
		await restoreGallery(gallery.id)
		galleries.value = galleries.value.filter(item => item.id !== gallery.id)
		notify('success', t('proofing_gallery', 'Gallery restored.')).catch(() => {})
	} catch {
		notify('error', t('proofing_gallery', 'The gallery could not be restored.')).catch(() => {})
	}
}

watch(archived, load)
watch(search, () => {
	clearTimeout(searchTimer)
	searchTimer = setTimeout(load, 250)
})
onMounted(load)
</script>

<template>
	<NcContent app-name="proofing_gallery">
		<NcAppNavigation>
			<template #list>
				<li class="gallery-nav__entry">
					<button
						class="gallery-nav__item"
						:class="{ 'gallery-nav__item--active': !archived }"
						type="button"
						@click="archived = false">
						<span>{{ t('proofing_gallery', 'Galleries') }}</span>
					</button>
				</li>
				<li class="gallery-nav__entry">
					<button
						class="gallery-nav__item"
						:class="{ 'gallery-nav__item--active': archived }"
						type="button"
						@click="archived = true">
						<span>{{ t('proofing_gallery', 'Archive') }}</span>
					</button>
				</li>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<GallerySettings
				v-if="selectedGallery"
				:gallery="selectedGallery"
				@back="closeSettings"
				@updated="updateSelected" />
			<main v-else class="gallery-page">
				<header class="gallery-page__header">
					<h1>{{ archived ? t('proofing_gallery', 'Archive') : t('proofing_gallery', 'Galleries') }}</h1>
					<NcButton v-if="!archived" variant="primary" @click="showCreate = true">
						{{ t('proofing_gallery', 'Create gallery') }}
					</NcButton>
				</header>

				<div class="gallery-toolbar">
					<NcTextField
						v-model="search"
						type="search"
						:label="t('proofing_gallery', 'Search galleries')" />
					<p>{{ n('proofing_gallery', '%n gallery', '%n galleries', galleries.length) }}</p>
				</div>

				<div v-if="loading" class="gallery-loading">
					<NcLoadingIcon :size="32" />
					<span>{{ t('proofing_gallery', 'Loading galleries…') }}</span>
				</div>

				<GalleryList
					v-else-if="galleries.length > 0"
					:galleries="galleries"
					:archived="archived"
					@select="selectGallery"
					@share="shareGallery = $event"
					@archive="archive"
					@restore="restore" />

				<NcEmptyContent
					v-else
					:name="search
						? t('proofing_gallery', 'No matching galleries')
						: archived
							? t('proofing_gallery', 'The archive is empty')
							: t('proofing_gallery', 'No galleries yet')"
					:description="search
						? t('proofing_gallery', 'Try another title.')
						: t('proofing_gallery', 'Turn an existing Nextcloud folder into a polished client gallery.')">
					<template v-if="!archived && !search" #action>
						<NcButton variant="primary" @click="showCreate = true">
							{{ t('proofing_gallery', 'Create your first gallery') }}
						</NcButton>
					</template>
				</NcEmptyContent>
			</main>
		</NcAppContent>

		<CreateGalleryModal
			:show="showCreate"
			@close="showCreate = false"
			@created="created" />
		<SharingModal
			v-if="shareGallery"
			:show="true"
			:gallery="shareGallery"
			@close="shareGallery = null"
			@updated="updateSelected" />
	</NcContent>
</template>

<style scoped>
.gallery-nav__entry {
	display: block;
	padding: 0 8px;
}

.gallery-nav__entry:first-child {
	padding-top: 8px;
}

.gallery-nav__entry:last-child {
	padding-bottom: 8px;
}

.gallery-nav__item {
	display: block;
	width: 100%;
	min-height: 44px;
	padding: 11px 12px;
	border: 0;
	border-radius: 8px;
	background: transparent;
	color: var(--color-main-text);
	text-align: start;
	cursor: pointer;
}

.gallery-nav__item:hover,
.gallery-nav__item:focus-visible {
	background: var(--color-background-hover);
}

.gallery-nav__item--active {
	background: var(--color-primary-element-light);
	font-weight: 650;
}

.gallery-page {
	max-width: 1180px;
	margin: 0 auto;
	padding: 40px clamp(20px, 4vw, 56px) 80px;
}

.gallery-page__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 24px;
	margin-bottom: 36px;
}

.gallery-page h1 {
	margin: 0;
	font-size: 30px;
	font-weight: 600;
	line-height: 1.2;
}

.gallery-toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 20px;
	margin-bottom: 20px;
}

.gallery-toolbar > :first-child {
	width: min(360px, 100%);
}

.gallery-toolbar p {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	white-space: nowrap;
}

.gallery-loading {
	display: flex;
	min-height: 260px;
	align-items: center;
	justify-content: center;
	gap: 12px;
	color: var(--color-text-maxcontrast);
}

@media (max-width: 600px) {
	.gallery-page {
		padding: 28px 14px 64px 48px;
	}

	.gallery-page__header {
		align-items: center;
	}

	.gallery-toolbar p {
		display: none;
	}
}
</style>
