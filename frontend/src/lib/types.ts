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

export interface ConversationSummary {
  id: string
  contact: { id: string; name: string | null; wa_phone: string | null } | null
  phone_number: { id: string; display_number: string | null; display_name: string | null } | null
  status: string
  window_open: boolean
  csw_expires_at: string | null
  last_message_at: string | null
}

export interface ChatMessage {
  id: string
  direction: 'in' | 'out'
  type: string
  text: string
  status: string
  error: string | null
  sender_type: string
  sent_at: string
}

export interface PhoneNumberSummary {
  id: string
  display_number: string | null
  display_name: string | null
  quality: string | null
  status: string
}
