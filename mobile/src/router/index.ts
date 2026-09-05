import { createRouter, createWebHistory } from '@ionic/vue-router'
import type { RouteRecordRaw } from 'vue-router'
import HomePage from '../views/HomePage.vue'
import ProvisioningPage from '../views/ProvisioningPage.vue'
import AttendancePage from '../views/AttendancePage.vue'

const routes: RouteRecordRaw[] = [
  { path: '/', redirect: '/provision' },
  { path: '/provision', name: 'Provisioning', component: ProvisioningPage },
  { path: '/home', name: 'Home', component: HomePage },
  { path: '/attendance/:employeeId/:assignmentUuid', name: 'Attendance', component: AttendancePage },
]

export default createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})
