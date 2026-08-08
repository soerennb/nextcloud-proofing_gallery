import { createApp } from 'vue'

import AdminSettingsApp from './components/AdminSettingsApp.vue'
import type { AdminSettingsState } from './types/adminSettings.ts'
import './admin.css'

const root = document.querySelector<HTMLElement>('#proofing-gallery-admin')
if (root) {
	const initialState = JSON.parse(root.dataset.state ?? '{}') as AdminSettingsState
	createApp(AdminSettingsApp, { initialState }).mount(root)
}
