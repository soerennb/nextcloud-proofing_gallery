<script setup lang="ts">
import axios from '@nextcloud/axios'
import { FilePickerType, getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import { computed, ref } from 'vue'

import SettingsSaveBar from './SettingsSaveBar.vue'

interface NotificationChannel { enabled: boolean; events: string[]; frequency?: string }
interface PersonalPreferences {
	defaultPurpose: string | null
	publicLocale: string
	designPresetId: number | null
	parentFolder: { id: number; name: string } | null
	notifications: { nextcloud: NotificationChannel; email: NotificationChannel & { frequency: string } }
	lifecycle: { enabled: boolean; trigger: string; revokeAfterDays: number; archiveAfterDays: number }
}
interface PersonalState {
	preferences: PersonalPreferences
	capabilities: { lifecycleAutomation: { allowed: boolean } }
	instanceSettings: { workflow: { defaultPurpose: string } }
	presets: Array<{ id: number; name: string }>
}
const props = defineProps<{ initialState: PersonalState }>()
const saved = ref(structuredClone(props.initialState.preferences))
const draft = ref(structuredClone(props.initialState.preferences))
const saving = ref(false)
const dirty = computed(() => JSON.stringify(saved.value) !== JSON.stringify(draft.value))
const lifecycleAllowed = computed(() => props.initialState.capabilities.lifecycleAutomation.allowed)
const events = [
	['upload.received', t('proofing_gallery', 'New client uploads')],
	['comment.created', t('proofing_gallery', 'New comments')],
	['selection.created', t('proofing_gallery', 'Completed selections')],
] as const

function eventEnabled(channel: 'nextcloud' | 'email', event: string) { return draft.value.notifications[channel].events.includes(event) }
function setEvent(channel: 'nextcloud' | 'email', event: string, enabled: boolean) {
	const values = draft.value.notifications[channel].events as string[]
	draft.value.notifications[channel].events = enabled ? [...new Set([...values, event])] : values.filter(value => value !== event)
}
function discard() { draft.value = structuredClone(saved.value) }
async function chooseFolder() {
	try {
		const nodes = await getFilePickerBuilder(t('proofing_gallery', 'Choose the default project folder')).setMultiSelect(false).allowDirectories().setType(FilePickerType.Choose).setCanPick(node => node.type === 'folder').build().pickNodes()
		const folder = nodes[0]
		if (folder?.fileid !== undefined) draft.value.parentFolder = { id: folder.fileid, name: folder.displayname }
	} catch { /* Closing the picker is not an error. */ }
}
async function save() {
	if (!dirty.value || saving.value) return
	saving.value = true
	try {
		await axios.put(generateOcsUrl('/apps/proofing_gallery/api/v1/user/preferences'), { preferences: draft.value })
		saved.value = structuredClone(draft.value)
		localStorage.removeItem('proofing-gallery:last-parent')
		showSuccess(t('proofing_gallery', 'Settings saved.'))
	} catch { showError(t('proofing_gallery', 'Settings could not be saved.')) } finally { saving.value = false }
}
</script>

<template>
	<div class="personal-settings">
		<header><h2>{{ t('proofing_gallery', 'Proofing Gallery') }}</h2><p>{{ t('proofing_gallery', 'Your defaults follow you across devices and keep new projects consistent.') }}</p></header>
		<NcSettingsSection :name="t('proofing_gallery', 'New projects')" :description="t('proofing_gallery', 'Choose a small set of defaults. Every project can still be adjusted later.')">
			<div class="personal-fields">
				<label>{{ t('proofing_gallery', 'Preferred purpose') }}<select v-model="draft.defaultPurpose"><option :value="null">{{ t('proofing_gallery', 'Use instance default') }} ({{ initialState.instanceSettings.workflow.defaultPurpose }})</option><option v-for="purpose in ['delivery', 'showcase', 'selection', 'proofing', 'uploads', 'custom']" :key="purpose" :value="purpose">{{ purpose }}</option></select></label><label>{{ t('proofing_gallery', 'Public language') }}<select v-model="draft.publicLocale"><option value="auto">{{ t('proofing_gallery', 'Automatic') }}</option><option value="de">Deutsch</option><option value="en">English</option></select></label><label>{{ t('proofing_gallery', 'Preferred design preset') }}<select v-model="draft.designPresetId"><option :value="null">{{ t('proofing_gallery', 'Use instance design') }}</option><option v-for="preset in initialState.presets" :key="preset.id" :value="preset.id">{{ preset.name }}</option></select></label>
			</div>
			<div class="personal-folder">
				<div><strong>{{ t('proofing_gallery', 'Default parent folder') }}</strong><span>{{ draft.parentFolder?.name || t('proofing_gallery', 'No folder selected') }}</span></div><NcButton @click="chooseFolder">
					{{ t('proofing_gallery', 'Choose folder') }}
				</NcButton><NcButton v-if="draft.parentFolder" variant="tertiary" @click="draft.parentFolder = null">
					{{ t('proofing_gallery', 'Clear') }}
				</NcButton>
			</div>
		</NcSettingsSection>

		<NcSettingsSection :name="t('proofing_gallery', 'Notifications')" :description="t('proofing_gallery', 'Select a channel first, then the events that matter there.')">
			<div class="notification-groups">
				<fieldset>
					<legend>{{ t('proofing_gallery', 'Nextcloud') }}</legend><NcCheckboxRadioSwitch v-model="draft.notifications.nextcloud.enabled" type="switch">
						{{ t('proofing_gallery', 'Show important updates in Nextcloud') }}
					</NcCheckboxRadioSwitch><div v-if="draft.notifications.nextcloud.enabled" class="notification-events">
						<NcCheckboxRadioSwitch v-for="event in events"
							:key="event[0]"
							:model-value="eventEnabled('nextcloud', event[0])"
							@update:model-value="setEvent('nextcloud', event[0], $event)">
							{{ event[1] }}
						</NcCheckboxRadioSwitch>
					</div>
				</fieldset><fieldset>
					<legend>{{ t('proofing_gallery', 'Email') }}</legend><NcCheckboxRadioSwitch v-model="draft.notifications.email.enabled" type="switch">
						{{ t('proofing_gallery', 'Also send gallery updates by email') }}
					</NcCheckboxRadioSwitch><template v-if="draft.notifications.email.enabled">
						<label>{{ t('proofing_gallery', 'Email delivery') }}<select v-model="draft.notifications.email.frequency"><option value="immediate">{{ t('proofing_gallery', 'As soon as possible') }}</option><option value="daily">{{ t('proofing_gallery', 'Daily digest') }}</option></select></label><div class="notification-events">
							<NcCheckboxRadioSwitch v-for="event in events"
								:key="event[0]"
								:model-value="eventEnabled('email', event[0])"
								@update:model-value="setEvent('email', event[0], $event)">
								{{ event[1] }}
							</NcCheckboxRadioSwitch>
						</div>
					</template>
				</fieldset>
			</div>
		</NcSettingsSection>

		<NcSettingsSection :name="t('proofing_gallery', 'Lifecycle suggestion')" :description="t('proofing_gallery', 'Keep finished client projects from remaining public indefinitely.')">
			<NcNoteCard v-if="!lifecycleAllowed" type="info">
				{{ t('proofing_gallery', 'Lifecycle automation was disabled by the administrator.') }}
			</NcNoteCard><NcCheckboxRadioSwitch v-model="draft.lifecycle.enabled" type="switch" :disabled="!lifecycleAllowed">
				{{ t('proofing_gallery', 'Suggest lifecycle automation for new galleries') }}
			</NcCheckboxRadioSwitch><div v-if="draft.lifecycle.enabled && lifecycleAllowed" class="personal-fields personal-fields--two">
				<label>{{ t('proofing_gallery', 'Revoke after completion (days)') }}<input v-model.number="draft.lifecycle.revokeAfterDays"
					type="number"
					min="1"
					max="3650"></label><label>{{ t('proofing_gallery', 'Archive after revocation (days)') }}<input v-model.number="draft.lifecycle.archiveAfterDays"
						type="number"
						min="1"
						max="3650"></label>
			</div>
		</NcSettingsSection>

		<SettingsSaveBar :visible="dirty"
			:saving="saving"
			@discard="discard"
			@save="save" />
	</div>
</template>
