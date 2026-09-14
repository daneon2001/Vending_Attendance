import { createRouter, createWebHistory } from '@ionic/vue-router'
import type { RouteRecordRaw } from 'vue-router'
import HomePage from '../views/HomePage.vue'
import ProvisioningPage from '../views/ProvisioningPage.vue'
import AttendancePage from '../views/AttendancePage.vue'
import StartupErrorPage from '../views/StartupErrorPage.vue'

const routes: RouteRecordRaw[] = [
  { path: '/', redirect: '/home' },
  { path: '/diagnostics', name: 'Diagnostics', component: () => import('@/views/DiagnosticsPage.vue') },
  { path: '/provision', name: 'Provisioning', component: ProvisioningPage },
  { path: '/home', name: 'Home', component: HomePage },
  { path: '/my-device', name: 'FieldMobile', component: () => import('@/fieldIdentity/FieldMobilePage.vue') },
  { path: '/my-activities', name: 'FieldActivities', component: () => import('@/fieldSupport/FieldActivitiesPage.vue') },
  { path: '/my-activities/:uuid', name: 'FieldActivity', component: () => import('@/fieldSupport/FieldActivitiesPage.vue') },
  { path: '/attendance/:employeeId/:assignmentUuid', name: 'Attendance', component: AttendancePage },
  { path: '/startup-error', name: 'StartupError', component: StartupErrorPage },
  { path: '/support', name: 'Support', component: () => import('@/support/SupportHomePage.vue') },
  { path: '/support/report', name: 'SupportReport', component: () => import('@/support/SupportReportPage.vue') },
  { path: '/support/reports', name: 'SupportReports', component: () => import('@/support/SupportReportsPage.vue') },
  { path: '/support/tickets/:localUuid', name: 'SupportTicket', component: () => import('@/support/SupportTicketPage.vue') },
  { path: '/support/verify', name: 'SupportVerification', component: () => import('@/support/SupportVerificationPage.vue') },
]

export default createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})
