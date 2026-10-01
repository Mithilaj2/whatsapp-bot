// Small fetch wrapper for the Laravel API.
//
// The access token lives only in memory. The refresh token is an httpOnly
// cookie the browser sends to /api/auth/refresh, so script on the page can
// never read it. On a 401 we refresh once and retry.

const TENANT_KEY = 'wabot.tenantId'

let accessToken: string | null = null
let refreshing: Promise<boolean> | null = null

export class ApiError extends Error {
  status: number
  errors: Record<string, string[]>

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
}

export function setAccessToken(token: string | null) {
  accessToken = token
}

export function getTenantId(): string | null {
  return localStorage.getItem(TENANT_KEY)
}

export function setTenantId(id: string | null) {
  if (id) localStorage.setItem(TENANT_KEY, id)
  else localStorage.removeItem(TENANT_KEY)
}

export async function refreshSession(): Promise<boolean> {
  refreshing ??= fetch('/api/auth/refresh', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  })
    .then(async (res) => {
      if (!res.ok) {
        setAccessToken(null)
        return false
      }
      const body = (await res.json()) as { access_token: string }
      setAccessToken(body.access_token)
      return true
    })
    .finally(() => {
      refreshing = null
    })
  return refreshing
}

export async function api<T>(path: string, init: RequestInit = {}, retry = true): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (init.body && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json')
  if (accessToken) headers.set('Authorization', `Bearer ${accessToken}`)
  const tenantId = getTenantId()
  if (tenantId) headers.set('X-Tenant-Id', tenantId)

  const res = await fetch(`/api${path}`, { ...init, headers, credentials: 'same-origin' })

  if (res.status === 401 && retry && !path.startsWith('/auth/')) {
    if (await refreshSession()) return api<T>(path, init, false)
  }

  if (res.status === 204) return undefined as T

  const body = await res.json().catch(() => ({}))
  if (!res.ok) throw new ApiError(res.status, body.message ?? 'Something went wrong.', body.errors)
  return body as T
}

export const post = <T>(path: string, data?: unknown) =>
  api<T>(path, { method: 'POST', body: data === undefined ? undefined : JSON.stringify(data) })
export const patch = <T>(path: string, data: unknown) =>
  api<T>(path, { method: 'PATCH', body: JSON.stringify(data) })
export const del = <T>(path: string) => api<T>(path, { method: 'DELETE' })
