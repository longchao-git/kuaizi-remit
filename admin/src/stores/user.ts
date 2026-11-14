import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { UserInfo } from '@/modules/auth/domain/UserInfo'
import type { SessionContext } from '@/modules/auth/domain/SessionContext'
import type { MenuVO } from '@/shared/types/menu'
import { TOKEN_STORAGE_KEY, SESSION_STORAGE_KEY } from '@/config/storage'

type UserProfile = UserInfo & Record<string, unknown>

function isUserInfoLike(value: unknown): value is UserProfile {
  if (!value || typeof value !== 'object') return false
  const profile = value as { id?: unknown; username?: unknown }
  return typeof profile.id === 'string' && typeof profile.username === 'string'
}

function isMenuList(value: unknown): value is MenuVO[] {
  return Array.isArray(value)
}

function isPermissionList(value: unknown): value is string[] {
  return Array.isArray(value) && value.every(item => typeof item === 'string')
}

function emptySession(): SessionContext {
  return { profile: null, menus: [], permissions: [] }
}

function getStoredSession(): SessionContext {
  const stored = localStorage.getItem(SESSION_STORAGE_KEY)
  if (!stored || stored === 'null' || stored === 'undefined') return emptySession()

  try {
    const parsed = JSON.parse(stored) as {
      profile?: unknown
      menus?: unknown
      permissions?: unknown
    }
    return {
      profile: isUserInfoLike(parsed.profile) ? parsed.profile : null,
      menus: isMenuList(parsed.menus) ? parsed.menus : [],
      permissions: isPermissionList(parsed.permissions) ? parsed.permissions : []
    }
  } catch {
    return emptySession()
  }
}

export const useUserStore = defineStore('user', () => {
  const token = ref(localStorage.getItem(TOKEN_STORAGE_KEY) || '')
  const session = ref<SessionContext>(getStoredSession())
  const sessionInitialized = ref(false)
  const routesLoaded = ref(false)

  const profile = computed(() => session.value.profile)
  const menus = computed(() => session.value.menus)
  const permissions = computed(() => session.value.permissions)
  const hasCachedSession = computed(() => Boolean(profile.value))

  function setToken(newToken: string) {
    token.value = newToken
    localStorage.setItem(TOKEN_STORAGE_KEY, newToken)
  }

  function clearToken() {
    token.value = ''
    localStorage.removeItem(TOKEN_STORAGE_KEY)
    routesLoaded.value = false
    sessionInitialized.value = false
  }

  function setSession(nextSession: SessionContext) {
    session.value = {
      profile: nextSession.profile,
      menus: Array.isArray(nextSession.menus) ? nextSession.menus : [],
      permissions: Array.isArray(nextSession.permissions) ? nextSession.permissions : []
    }
    localStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify(session.value))
    sessionInitialized.value = true
  }

  function clearSession() {
    session.value = emptySession()
    localStorage.removeItem(SESSION_STORAGE_KEY)
    clearToken()
  }

  function setSessionInitialized(value: boolean) {
    sessionInitialized.value = value
  }

  function setRoutesLoaded(value: boolean) {
    routesLoaded.value = value
  }

  return {
    token,
    session,
    profile,
    menus,
    permissions,
    hasCachedSession,
    sessionInitialized,
    routesLoaded,
    setToken,
    clearToken,
    setSession,
    clearSession,
    setSessionInitialized,
    setRoutesLoaded
  }
})
