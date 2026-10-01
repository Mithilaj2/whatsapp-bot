export type TenantRole = 'owner' | 'admin' | 'supervisor' | 'agent' | 'viewer' | 'developer'

export interface User {
  id: string
  name: string
  email: string
  is_platform_admin?: boolean
}

export interface TenantSummary {
  id: string
  name: string
  role: TenantRole
}

export interface Me {
  user: User
  tenants: TenantSummary[]
}

export interface TokenResponse {
  access_token: string
  expires_at: string
  user: User
  tenant_id?: string
}

export interface Team {
  id: string
  name: string
  members_count: number
}
