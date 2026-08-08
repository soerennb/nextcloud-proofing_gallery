import { createApp } from 'vue'

import PersonalSettingsApp from './components/PersonalSettingsApp.vue'
import './personal.css'

const root = document.querySelector<HTMLElement>('#proofing-gallery-personal')
if (root) createApp(PersonalSettingsApp, { initialState: JSON.parse(root.dataset.state ?? '{}') }).mount(root)
