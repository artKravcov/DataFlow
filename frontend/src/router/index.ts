import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import AppLayout from '@/views/AppLayout.vue'
import LoginView from '@/views/LoginView.vue'
import RegisterView from '@/views/RegisterView.vue'
import OrganizationSelectView from '@/views/OrganizationSelectView.vue'
import OverviewView from '@/views/OverviewView.vue'
import ProjectsView from '@/views/ProjectsView.vue'
import ProjectView from '@/views/ProjectView.vue'
import PipelineView from '@/views/PipelineView.vue'

const router = createRouter({
	history: createWebHistory(import.meta.env.BASE_URL),
	routes: [
		{
			path: '/login',
			name: 'login',
			component: LoginView,
			meta: { guestOnly: true },
		},
		{
			path: '/register',
			name: 'register',
			component: RegisterView,
			meta: { guestOnly: true },
		},
		{
			path: '/organizations',
			name: 'organizations',
			component: OrganizationSelectView,
			meta: { requiresAuth: true },
		},
		{
			path: '/o/:org',
			component: AppLayout,
			meta: { requiresAuth: true },
			children: [
				{ path: 'overview', name: 'overview', component: OverviewView },
				{ path: 'projects', name: 'projects', component: ProjectsView },
				{ path: 'projects/:project', name: 'project', component: ProjectView },
				{ path: 'pipelines/:pipeline', name: 'pipeline', component: PipelineView },
			],
		},
		{ path: '/', redirect: '/organizations' },
		{ path: '/:pathMatch(.*)*', redirect: '/organizations' },
	],
})

router.beforeEach(async (to) => {
	const auth = useAuthStore()
	if (!auth.initialized) await auth.loadMe()
	if (to.meta.requiresAuth && !auth.isAuthenticated)
		return {
			name: 'login',
			query: {
				returnTo: to.fullPath.startsWith('/') ? to.fullPath : '/organizations',
			},
		}
	if (to.meta.guestOnly && auth.isAuthenticated) return { name: 'organizations' }
	const orgId = Number(to.params.org)
	if (
		to.meta.requiresAuth &&
		Number.isInteger(orgId) &&
		orgId > 0 &&
		!auth.organizationById(orgId)
	)
		return { name: 'organizations' }
})

export default router
