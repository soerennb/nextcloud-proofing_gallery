<script setup lang="ts">
import { n, t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue'

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
const modeFilter = ref<'all' | 'presentation' | 'collaboration'>('all')
const sourceFilter = ref<'all' | 'folder' | 'collection'>('all')
const statusFilter = ref<'all' | 'draft' | 'published'>('all')
const gallerySort = ref<'updated' | 'title' | 'created'>('updated')
const savedDashboardView = localStorage.getItem('proofing-gallery-dashboard-view')
const dashboardView = ref<'list' | 'grid'>(savedDashboardView === 'list' ? 'list' : 'grid')
const mobileFiltersOpen = ref(false)
const mobileViewportQuery = window.matchMedia('(max-width: 600px)')
const mobileViewport = ref(mobileViewportQuery.matches)
const showCreate = ref(false)
const selectedGallery = ref<Gallery | null>(null)
const shareGallery = ref<Gallery | null>(null)
let searchTimer: ReturnType<typeof setTimeout> | undefined

const visibleGalleries = computed(() => [...galleries.value]
	.filter(gallery => modeFilter.value === 'all' || gallery.settings.mode === modeFilter.value)
	.filter(gallery => sourceFilter.value === 'all' || gallery.sourceType === sourceFilter.value)
	.filter(gallery => archived.value || statusFilter.value === 'all' || gallery.status === statusFilter.value)
	.sort((left, right) => {
		if (gallerySort.value === 'title') return left.title.localeCompare(right.title)
		if (gallerySort.value === 'created') return right.createdAt - left.createdAt
		return right.updatedAt - left.updatedAt
	}))
const activeFilterCount = computed(() => Number(modeFilter.value !== 'all')
	+ Number(sourceFilter.value !== 'all')
	+ Number(!archived.value && statusFilter.value !== 'all'))

watch(dashboardView, value => localStorage.setItem('proofing-gallery-dashboard-view', value))

function resetFilters() {
	modeFilter.value = 'all'
	sourceFilter.value = 'all'
	statusFilter.value = 'all'
	mobileFiltersOpen.value = false
}

async function notify(kind: 'error' | 'success', message: string) {
	const dialogs = await import('@nextcloud/dialogs')
	if (kind === 'error') dialogs.showError(message)
	else dialogs.showSuccess(message)
}

async function load() {
	loading.value = true
	try {
		galleries.value = (await fetchGalleries({ archived: archived.value, search: search.value })).items
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
onMounted(() => {
	load()
	mobileViewportQuery.addEventListener('change', onMobileViewportChange)
})
onBeforeUnmount(() => mobileViewportQuery.removeEventListener('change', onMobileViewportChange))

function onMobileViewportChange(event: MediaQueryListEvent) {
	mobileViewport.value = event.matches
	if (!event.matches) mobileFiltersOpen.value = false
}
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
					<div class="gallery-page__actions">
						<div class="view-switch" :aria-label="t('proofing_gallery', 'Gallery view')">
							<button type="button"
								:aria-pressed="dashboardView === 'grid'"
								:aria-label="t('proofing_gallery', 'Grid')"
								@click="dashboardView = 'grid'">
								▦
							</button>
							<button type="button"
								:aria-pressed="dashboardView === 'list'"
								:aria-label="t('proofing_gallery', 'List')"
								@click="dashboardView = 'list'">
								☷
							</button>
						</div>
						<NcButton v-if="!archived" variant="primary" @click="showCreate = true">
							{{ t('proofing_gallery', 'Create gallery') }}
						</NcButton>
					</div>
				</header>

				<div class="gallery-toolbar">
					<NcTextField
						v-model="search"
						type="search"
						:label="t('proofing_gallery', 'Search galleries')" />
					<button class="gallery-toolbar__filter-button"
						type="button"
						:aria-expanded="mobileFiltersOpen"
						@click="mobileFiltersOpen = !mobileFiltersOpen">
						{{ t('proofing_gallery', 'Filter') }}<span v-if="activeFilterCount">{{ activeFilterCount }}</span>
					</button>
					<button v-if="mobileFiltersOpen"
						class="gallery-toolbar__backdrop"
						type="button"
						:aria-label="t('proofing_gallery', 'Close')"
						@click="mobileFiltersOpen = false" />
					<div class="gallery-toolbar__filters"
						:class="{ 'gallery-toolbar__filters--open': mobileFiltersOpen }"
						:aria-hidden="mobileViewport && !mobileFiltersOpen ? 'true' : undefined"
						:inert="mobileViewport && !mobileFiltersOpen">
						<label>
							<span>{{ t('proofing_gallery', 'Mode') }}</span>
							<select v-model="modeFilter">
								<option value="all">{{ t('proofing_gallery', 'All') }}</option>
								<option value="presentation">{{ t('proofing_gallery', 'Presentation') }}</option>
								<option value="collaboration">{{ t('proofing_gallery', 'Proofing') }}</option>
							</select>
						</label>
						<label>
							<span>{{ t('proofing_gallery', 'Source') }}</span>
							<select v-model="sourceFilter">
								<option value="all">{{ t('proofing_gallery', 'All') }}</option>
								<option value="folder">{{ t('proofing_gallery', 'Folder') }}</option>
								<option value="collection">{{ t('proofing_gallery', 'Collection') }}</option>
							</select>
						</label>
						<label v-if="!archived">
							<span>{{ t('proofing_gallery', 'Status') }}</span>
							<select v-model="statusFilter">
								<option value="all">{{ t('proofing_gallery', 'All') }}</option>
								<option value="draft">{{ t('proofing_gallery', 'Draft') }}</option>
								<option value="published">{{ t('proofing_gallery', 'Published') }}</option>
							</select>
						</label>
						<label>
							<span>{{ t('proofing_gallery', 'Sort') }}</span>
							<select v-model="gallerySort">
								<option value="updated">{{ t('proofing_gallery', 'Last changed') }}</option>
								<option value="created">{{ t('proofing_gallery', 'Newest') }}</option>
								<option value="title">{{ t('proofing_gallery', 'Title') }}</option>
							</select>
						</label>
						<p>{{ n('proofing_gallery', '%n gallery', '%n galleries', visibleGalleries.length) }}</p>
						<button v-if="activeFilterCount"
							class="gallery-toolbar__reset"
							type="button"
							@click="resetFilters">
							{{ t('proofing_gallery', 'Reset') }}
						</button>
					</div>
				</div>

				<div v-if="loading" class="gallery-loading">
					<NcLoadingIcon :size="32" />
					<span>{{ t('proofing_gallery', 'Loading galleries…') }}</span>
				</div>

				<GalleryList
					v-else-if="visibleGalleries.length > 0"
					:galleries="visibleGalleries"
					:archived="archived"
					:view="dashboardView"
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
	box-sizing: border-box;
	width: 100%;
	min-width: 0;
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

.gallery-page__actions,
.view-switch {
	display: flex;
	align-items: center;
	gap: 8px;
}

.view-switch {
	gap: 2px;
	padding: 2px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 8px;
}

.view-switch button {
	display: grid;
	width: 38px;
	height: 36px;
	place-items: center;
	border: 0;
	border-radius: 6px;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 19px;
	cursor: pointer;
}

.view-switch button[aria-pressed="true"] {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
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
	flex-wrap: wrap;
	gap: 10px;
	margin-bottom: 20px;
}

.gallery-toolbar > :first-child {
	width: min(320px, 100%);
	margin-inline-end: auto;
}

.gallery-toolbar__filters {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 10px;
}

.gallery-toolbar__filter-button,
.gallery-toolbar__backdrop,
.gallery-toolbar__reset { display: none; }

.gallery-toolbar label {
	display: grid;
	gap: 3px;
	color: var(--color-text-maxcontrast);
	font-size: 11px;
}

.gallery-toolbar select {
	min-height: 36px;
	padding: 0 8px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
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
		display: grid;
		grid-template-columns: minmax(0, 1fr);
		align-items: center;
		margin-bottom: 24px;
	}

	.gallery-page__header h1 { font-size: 26px; }
	.gallery-page__actions { width: 100%; justify-content: space-between; gap: 8px; }
	.view-switch button { width: 34px; }

	.gallery-toolbar {
		display: grid;
		grid-template-columns: minmax(0, 1fr) auto;
		align-items: end;
	}

	.gallery-toolbar > :first-child {
		width: 100%;
		margin: 0;
	}

	.gallery-toolbar__filter-button {
		display: inline-flex;
		min-height: 44px;
		align-items: center;
		gap: 7px;
		padding: 0 12px;
		border: 1px solid var(--color-border-maxcontrast);
		border-radius: 8px;
		background: var(--color-main-background);
		color: var(--color-main-text);
		cursor: pointer;
	}

	.gallery-toolbar__filter-button span {
		padding: 1px 6px;
		border-radius: 4px;
		background: var(--color-primary-element);
		color: var(--color-primary-element-text);
	}

	.gallery-toolbar__backdrop {
		position: fixed;
		z-index: 90;
		inset: 0;
		display: block;
		width: 100%;
		height: 100%;
		padding: 0;
		border: 0;
		border-radius: 0;
		background: rgb(0 0 0 / 58%);
	}

	.gallery-toolbar__filters {
		position: fixed;
		z-index: 91;
		inset: auto 0 0;
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 12px;
		padding: 18px 16px calc(18px + env(safe-area-inset-bottom));
		border-top: 4px solid var(--color-primary-element);
		background: var(--color-main-background);
		box-shadow: 0 -8px 28px var(--color-box-shadow);
		opacity: 0;
		pointer-events: none;
		transform: translateY(105%);
		transition: opacity 160ms ease, transform 220ms cubic-bezier(.2,.75,.25,1);
	}

	.gallery-toolbar__filters--open {
		opacity: 1;
		pointer-events: auto;
		transform: translateY(0);
	}

	.gallery-toolbar__filters label { min-width: 0; }
	.gallery-toolbar__filters select { width: 100%; }
	.gallery-toolbar__reset {
		display: block;
		min-height: 40px;
		grid-column: 1 / -1;
		border: 1px solid var(--color-primary-element);
		border-radius: 7px;
		background: transparent;
		color: var(--color-main-text);
	}

	.gallery-toolbar p {
		display: none;
	}
}

@media (prefers-reduced-motion: reduce) {
	.gallery-toolbar__filters { transition: none; }
}
</style>
