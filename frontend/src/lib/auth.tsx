import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, getTenantId, post, refreshSession, setAccessToken, setTenantId } from './api'
import type { Me, TenantSummary, TokenResponse } from './types'

interface AuthState {
  me: Me | null
  tenant: TenantSummary | null
  loading: boolean
  login: (email: string, password: string) => Promise<void>
  register: (data: { name: string; email: string; password: string; business_name: string }) => Promise<void>
  logout: () => Promise<void>
  switchTenant: (id: string) => void
  reload: () => Promise<void>
}

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [me, setMe] = useState<Me | null>(null)
  const [tenantId, setTenant] = useState<string | null>(getTenantId())
  const [loading, setLoading] = useState(true)

  const reload = useCallback(async () => {
    const data = await api<Me>('/me')
    setMe(data)
    // Keep the chosen business if the user still belongs to it.
    const current = data.tenants.find((t) => t.id === getTenantId()) ?? data.tenants[0] ?? null
    setTenantId(current?.id ?? null)
    setTenant(current?.id ?? null)
  }, [])

  // On page load, try to resume the session from the refresh cookie.
  useEffect(() => {
    refreshSession()
      .then((ok) => (ok ? reload() : undefined))
      .finally(() => setLoading(false))
  }, [reload])

  const start = async (res: TokenResponse) => {
    setAccessToken(res.access_token)
    if (res.tenant_id) setTenantId(res.tenant_id)
    await reload()
  }

  const value: AuthState = {
    me,
    tenant: me?.tenants.find((t) => t.id === tenantId) ?? null,
    loading,
    login: async (email, password) => start(await post<TokenResponse>('/auth/login', { email, password })),
    register: async (data) => start(await post<TokenResponse>('/auth/register', data)),
    logout: async () => {
      await post('/auth/logout').catch(() => undefined)
      setAccessToken(null)
      setTenantId(null)
      setMe(null)
    },
    switchTenant: (id) => {
      setTenantId(id)
      setTenant(id)
    },
    reload,
  }

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>')
  return ctx
}
