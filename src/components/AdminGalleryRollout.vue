<script setup lang="ts">
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref } from 'vue'

import type { AdminGalleryRolloutItem, AdminGalleryRolloutPage } from '../types/adminSettings.ts'

defineProps<{ defaultsSaved: boolean }>()

interface ImpactItem extends AdminGalleryRolloutItem { changed: boolean }
interface RolloutResult { applied: Array<{ id: number; revision: number }>; conflicts: Array<{ id: number }> }

const search = ref('')
const items = ref<AdminGalleryRolloutItem[]>([])
const total = ref(0)
const selected = ref<number[]>([])
const loading = ref(false)
const applying = ref(false)
const inspected = ref<ImpactItem[]>([])
const allVisibleSelected = computed(() => items.value.length > 0 && items.value.every(item => selected.value.includes(item.id)))

async function load() {
	loading.value = true
	inspected.value = []
	try {
		const { data } = await axios.get<AdminGalleryRolloutPage>(generateOcsUrl('/apps/proofing_gallery/api/v1/admin/galleries'), {
			params: { limit: 100, offset: 0, search: search.value.trim() || undefined },
		})
		items.value = data.items
		total.value = data.total
		selected.value = selected.value.filter(id => data.items.some(item => item.id === id))
	} catch {
		showError(t('proofing_gallery', 'Galleries could not be loaded.'))
	} finally { loading.value = false }
}

function toggleVisible() {
	selected.value = allVisibleSelected.value ? [] : items.value.map(item => item.id)
	inspected.value = []
}

async function inspect() {
	if (!selected.value.length) return
	loading.value = true
	try {
		const { data } = await axios.post<{ items: ImpactItem[] }>(generateOcsUrl('/apps/proofing_gallery/api/v1/admin/settings/impact'), {
			galleryIds: selected.value,
			categories: ['appearance', 'branding'],
		})
		inspected.value = data.items
	} catch {
		showError(t('proofing_gallery', 'The rollout preview could not be created.'))
	} finally { loading.value = false }
}

async function apply() {
	const changed = inspected.value.filter(item => item.changed)
	if (!changed.length || !window.confirm(n('proofing_gallery', 'Apply the new design to %n gallery?', 'Apply the new design to %n galleries?', changed.length))) return
	applying.value = true
	try {
		const expectedRevisions = Object.fromEntries(inspected.value.map(item => [item.id, item.revision]))
		const { data } = await axios.post<RolloutResult>(generateOcsUrl('/apps/proofing_gallery/api/v1/admin/settings/apply'), {
			galleryIds: selected.value,
			categories: ['appearance', 'branding'],
			expectedRevisions,
		})
		reportResult(data)
		await load()
	} catch (error) {
		const result = rolloutErrorResult(error)
		if (result?.conflicts) {
			reportResult(result)
			await load()
		} else showError(t('proofing_gallery', 'The design could not be applied to the selected galleries.'))
	} finally { applying.value = false }
}

function reportResult(data: RolloutResult) {
	if (data.conflicts.length) showError(t('proofing_gallery', '{count} galleries changed in the meantime and were not updated.', { count: data.conflicts.length }))
	if (data.applied.length) showSuccess(n('proofing_gallery', 'Updated %n gallery.', 'Updated %n galleries.', data.applied.length))
}

function rolloutErrorResult(error: unknown): RolloutResult | null {
	if (typeof error !== 'object' || error === null || !('response' in error)) return null
	const response = (error as { response?: { data?: unknown } }).response
	const data = response?.data
	if (typeof data !== 'object' || data === null || !('applied' in data) || !('conflicts' in data)) return null
	const result = data as Partial<RolloutResult>
	return Array.isArray(result.applied) && Array.isArray(result.conflicts) ? result as RolloutResult : null
}
</script>

<template>
	<div class="admin-rollout">
		<div class="admin-rollout__intro">
			<div>
				<strong>{{ t('proofing_gallery', 'Apply design to existing galleries') }}</strong>
				<p>{{ t('proofing_gallery', 'Choose galleries explicitly. Their content and review workflow remain unchanged.') }}</p>
			</div>
			<NcButton variant="tertiary" :disabled="!defaultsSaved" @click="load">
				{{ items.length ? t('proofing_gallery', 'Refresh gallery list') : t('proofing_gallery', 'Choose galleries') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="!defaultsSaved" type="info">
			{{ t('proofing_gallery', 'Save the new defaults before applying them to existing galleries.') }}
		</NcNoteCard>
		<template v-if="items.length || loading">
			<div class="admin-rollout__toolbar">
				<NcTextField v-model="search" :label="t('proofing_gallery', 'Search title or owner')" @keyup.enter="load" />
				<NcButton variant="tertiary" :disabled="loading" @click="load">
					{{ t('proofing_gallery', 'Search') }}
				</NcButton>
			</div>
			<p class="admin-rollout__summary">
				{{ t('proofing_gallery', '{shown} of {total} galleries', { shown: items.length, total }) }}
			</p>
			<div class="admin-rollout__list">
				<label class="admin-rollout__select-all"><input type="checkbox" :checked="allVisibleSelected" @change="toggleVisible">{{ t('proofing_gallery', 'Select all shown') }}</label>
				<label v-for="item in items" :key="item.id">
					<input v-model="selected"
						type="checkbox"
						:value="item.id"
						@change="inspected = []">
					<span><strong>{{ item.title }}</strong><small>{{ item.ownerUid }} · #{{ item.id }} · {{ item.published ? t('proofing_gallery', 'Published') : item.status }}</small></span>
					<em v-if="inspected.find(result => result.id === item.id)" :class="{ 'admin-rollout__unchanged': !inspected.find(result => result.id === item.id)?.changed }">
						{{ inspected.find(result => result.id === item.id)?.changed ? t('proofing_gallery', 'Will change') : t('proofing_gallery', 'Already current') }}
					</em>
				</label>
			</div>
			<div class="admin-rollout__actions">
				<NcButton :disabled="!selected.length || loading" @click="inspect">
					{{ t('proofing_gallery', 'Preview changes') }}
				</NcButton>
				<NcButton v-if="inspected.length"
					variant="primary"
					:disabled="applying || !inspected.some(item => item.changed)"
					@click="apply">
					{{ applying ? t('proofing_gallery', 'Applying…') : t('proofing_gallery', 'Apply design') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>
