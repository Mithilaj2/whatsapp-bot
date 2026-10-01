import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { NavLink, Route, Routes } from 'react-router-dom'
import { Button, Field } from '../components/Field'
import { ApiError, api, post } from '../lib/api'
import { useAuth } from '../lib/auth'
import type { Team } from '../lib/types'
import { Inbox } from './Inbox'

export function Dashboard() {
  const { me, tenant, switchTenant, logout } = useAuth()

  return (
    <div className="min-h-screen">
      <header className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3 sm:px-6">
        <nav className="flex items-center gap-4 text-sm">
          <span className="font-semibold">WhatsApp Bot</span>
          <NavLink to="/" end className={navClass}>Inbox</NavLink>
          <NavLink to="/teams" className={navClass}>Teams</NavLink>
        </nav>
        <div className="flex items-center gap-3 text-sm">
          {me && me.tenants.length > 1 && (
            <select
              value={tenant?.id ?? ''}
              onChange={(e) => switchTenant(e.target.value)}
              className="rounded-md border border-slate-300 px-2 py-1"
              aria-label="Business"
            >
              {me.tenants.map((t) => (
                <option key={t.id} value={t.id}>{t.name}</option>
              ))}
            </select>
          )}
          <span className="text-slate-600">{me?.user.name}</span>
          <button onClick={logout} className="text-emerald-700 underline">Sign out</button>
        </div>
      </header>
      <main className="mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6">
        {tenant ? (
          <>
            <section>
              <h1 className="text-2xl font-semibold">{tenant.name}</h1>
              <p className="text-sm text-slate-600">
                Your role: {tenant.role} · Business ID: <code className="select-all text-xs">{tenant.id}</code>
              </p>
            </section>
            <Routes>
              <Route index element={<Inbox key={tenant.id} />} />
              <Route path="teams" element={<Teams key={tenant.id} canManage={['owner', 'admin', 'supervisor'].includes(tenant.role)} />} />
            </Routes>
          </>
        ) : (
          <p>You are not a member of any business yet.</p>
        )}
      </main>
    </div>
  )
}

const navClass = ({ isActive }: { isActive: boolean }) =>
  isActive ? 'font-medium text-emerald-700' : 'text-slate-600 hover:text-slate-900'

function Teams({ canManage }: { canManage: boolean }) {
  const queryClient = useQueryClient()
  const [name, setName] = useState('')
  const teams = useQuery({ queryKey: ['teams'], queryFn: () => api<{ teams: Team[] }>('/teams') })
  const create = useMutation({
    mutationFn: (teamName: string) => post('/teams', { name: teamName }),
    onSuccess: () => {
      setName('')
      queryClient.invalidateQueries({ queryKey: ['teams'] })
    },
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    create.mutate(name)
  }

  return (
    <section className="space-y-3 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <h2 className="font-semibold">Teams</h2>
      {teams.isLoading && <p className="text-sm text-slate-500">Loading…</p>}
      <ul className="divide-y divide-slate-100">
        {teams.data?.teams.map((t) => (
          <li key={t.id} className="flex justify-between py-2 text-sm">
            <span>{t.name}</span>
            <span className="text-slate-500">{t.members_count} members</span>
          </li>
        ))}
        {teams.data?.teams.length === 0 && <li className="py-2 text-sm text-slate-500">No teams yet.</li>}
      </ul>
      {canManage && (
        <form onSubmit={submit} className="flex items-end gap-2">
          <div className="flex-1">
            <Field
              label="New team"
              value={name}
              onChange={(e) => setName(e.target.value)}
              error={create.error instanceof ApiError ? create.error.errors.name?.[0] : undefined}
              required
            />
          </div>
          <Button type="submit" disabled={create.isPending}>Add</Button>
        </form>
      )}
    </section>
  )
}
