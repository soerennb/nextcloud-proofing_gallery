<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { onMounted, ref, watch } from 'vue'

import {
	fetchManagers,
	removeManager,
	saveManager,
	searchPrincipals,
	type PrincipalOption,
} from '../services/galleryApi.ts'
import type { GalleryManager } from '../types.ts'

const props = defineProps<{ galleryId: number }>()
const emit = defineEmits<{ changed: [] }>()
const managers = ref<GalleryManager[]>([])
const loading = ref(true)
const query = ref('')
const suggestions = ref<PrincipalOption[]>([])
const selected = ref<PrincipalOption | null>(null)
const role = ref<'viewer' | 'editor'>('viewer')
const saving = ref(false)
let searchTimer: ReturnType<typeof setTimeout> | undefined

async function load() {
	loading.value = true
	try {
		managers.value = await fetchManagers(props.galleryId)
	} catch {
		showError(t('proofing_gallery', 'Gallery managers could not be loaded.'))
	} finally {
		loading.value = false
	}
}

watch(query, value => {
	if (selected.value?.label === value) return
	selected.value = null
	clearTimeout(searchTimer)
	searchTimer = setTimeout(async () => {
		try {
			suggestions.value = await searchPrincipals(value)
		} catch {
			suggestions.value = []
		}
	}, 250)
})

function choose(option: PrincipalOption) {
	selected.value = option
	query.value = option.label
	suggestions.value = []
}

async function add() {
	if (!selected.value) return
	saving.value = true
	try {
		const manager = await saveManager(props.galleryId, {
			type: selected.value.type,
			principalId: selected.value.id,
			role: role.value,
		})
		const index = managers.value.findIndex(item => item.id === manager.id)
		if (index === -1) managers.value.push(manager)
		else managers.value[index] = manager
		emit('changed')
		query.value = ''
		selected.value = null
		showSuccess(t('proofing_gallery', 'Gallery access updated.'))
	} catch {
		showError(t('proofing_gallery', 'Gallery access could not be updated.'))
	} finally {
		saving.value = false
	}
}

async function remove(manager: GalleryManager) {
	if (!window.confirm(t('proofing_gallery', 'Remove access for “{name}”?', { name: manager.principalId }))) return
	try {
		await removeManager(props.galleryId, manager.id)
		managers.value = managers.value.filter(item => item.id !== manager.id)
		emit('changed')
		showSuccess(t('proofing_gallery', 'Gallery access removed.'))
	} catch {
		showError(t('proofing_gallery', 'Gallery access could not be removed.'))
	}
}

onMounted(load)
</script>

<template>
	<section class="manager-panel">
		<header>
			<h2>{{ t('proofing_gallery', 'Managers') }}</h2>
			<p>{{ t('proofing_gallery', 'Give a Nextcloud user or group view-only or editing access.') }}</p>
		</header>

		<div class="manager-search">
			<label>
				<span>{{ t('proofing_gallery', 'User or group') }}</span>
				<input
					v-model="query"
					name="managerSearch"
					autocomplete="off"
					:placeholder="t('proofing_gallery', 'Search by name')">
			</label>
			<label>
				<span>{{ t('proofing_gallery', 'Permission') }}</span>
				<select v-model="role" name="managerRole">
					<option value="viewer">{{ t('proofing_gallery', 'Can view') }}</option>
					<option value="editor">{{ t('proofing_gallery', 'Can edit') }}</option>
				</select>
			</label>
			<NcButton variant="primary" :disabled="!selected || saving" @click="add">
				{{ t('proofing_gallery', 'Add') }}
			</NcButton>
			<ul v-if="suggestions.length" class="manager-suggestions">
				<li v-for="option in suggestions" :key="`${option.type}:${option.id}`">
					<button type="button" @click="choose(option)">
						<strong>{{ option.label }}</strong>
						<span>{{ option.type === 'group' ? t('proofing_gallery', 'Group') : t('proofing_gallery', 'User') }}</span>
					</button>
				</li>
			</ul>
		</div>

		<div v-if="loading" class="manager-loading">
			<NcLoadingIcon :size="24" />
		</div>
		<ul v-else class="manager-list">
			<li v-for="manager in managers" :key="manager.id">
				<div>
					<strong>{{ manager.principalId }}</strong>
					<span>
						{{ manager.type === 'group' ? t('proofing_gallery', 'Group') : t('proofing_gallery', 'User') }}
						· {{ manager.role === 'editor' ? t('proofing_gallery', 'Can edit') : t('proofing_gallery', 'Can view') }}
					</span>
				</div>
				<NcButton variant="tertiary" @click="remove(manager)">
					{{ t('proofing_gallery', 'Remove') }}
				</NcButton>
			</li>
			<li v-if="managers.length === 0" class="manager-list__empty">
				{{ t('proofing_gallery', 'Only the gallery owner currently has access.') }}
			</li>
		</ul>
	</section>
</template>

<style scoped>
.manager-panel {
	display: grid;
	gap: 18px;
}

.manager-panel h2,
.manager-panel p {
	margin: 0;
}

.manager-panel h2 {
	font-size: 20px;
}

.manager-panel p,
.manager-list span {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.manager-search {
	position: relative;
	display: grid;
	grid-template-columns: minmax(180px, 1fr) 150px auto;
	align-items: end;
	gap: 8px;
}

.manager-search label {
	display: grid;
	gap: 5px;
	font-size: 13px;
}

.manager-search input,
.manager-search select {
	min-height: 44px;
	padding: 0 10px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.manager-suggestions {
	position: absolute;
	z-index: 5;
	top: 70px;
	inset-inline: 0 166px;
	margin: 0;
	padding: 4px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	box-shadow: 0 2px 8px var(--color-box-shadow);
	list-style: none;
}

.manager-suggestions button {
	display: flex;
	width: 100%;
	justify-content: space-between;
	padding: 9px;
	border: 0;
	border-radius: 4px;
	background: transparent;
	color: var(--color-main-text);
	text-align: start;
	cursor: pointer;
}

.manager-suggestions button:hover,
.manager-suggestions button:focus-visible {
	background: var(--color-background-hover);
}

.manager-suggestions span {
	color: var(--color-text-maxcontrast);
}

.manager-list {
	margin: 0;
	padding: 0;
	border-top: 1px solid var(--color-border);
	list-style: none;
}

.manager-list li {
	display: flex;
	min-height: 54px;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	border-bottom: 1px solid var(--color-border);
}

.manager-list li > div {
	display: grid;
}

.manager-list__empty {
	color: var(--color-text-maxcontrast);
}

.manager-loading {
	display: grid;
	min-height: 80px;
	place-items: center;
}

@media (max-width: 700px) {
	.manager-search {
		grid-template-columns: 1fr;
	}

	.manager-suggestions {
		top: 70px;
		inset-inline-end: 0;
	}
}
</style>
