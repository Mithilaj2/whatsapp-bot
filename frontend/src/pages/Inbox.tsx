import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { Button } from '../components/Field'
import { ApiError, api, post } from '../lib/api'
import { useAuth } from '../lib/auth'
import type { ChatMessage, ConversationSummary, PhoneNumberSummary } from '../lib/types'

// Polling for now; live updates over WebSockets come with the agent inbox phase.
const LIST_POLL_MS = 5000
const THREAD_POLL_MS = 3000

export function Inbox() {
  const [selected, setSelected] = useState<string | null>(null)
  const numbers = useQuery({ queryKey: ['phone-numbers'], queryFn: () => api<{ phone_numbers: PhoneNumberSummary[] }>('/phone-numbers') })
  const list = useQuery({
    queryKey: ['conversations'],
    queryFn: () => api<{ conversations: ConversationSummary[] }>('/conversations'),
    refetchInterval: LIST_POLL_MS,
  })

  if (numbers.data && numbers.data.phone_numbers.length === 0) {
    return (
      <div className="rounded-xl bg-white p-5 text-sm text-slate-600 shadow-sm ring-1 ring-slate-200">
        No WhatsApp number is connected to this business yet.
      </div>
    )
  }

  const conversations = list.data?.conversations ?? []
  const current = conversations.find((c) => c.id === selected) ?? null

  return (
    <div className="grid gap-4 md:grid-cols-[18rem_1fr]">
      <ul className={`divide-y divide-slate-100 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200 ${current ? 'hidden md:block' : ''}`}>
        {conversations.length === 0 && <li className="p-4 text-sm text-slate-500">No conversations yet.</li>}
        {conversations.map((c) => (
          <li key={c.id}>
            <button
              onClick={() => setSelected(c.id)}
              className={`w-full px-4 py-3 text-left text-sm hover:bg-slate-50 ${c.id === selected ? 'bg-emerald-50' : ''}`}
            >
              <div className="font-medium">{c.contact?.name ?? c.contact?.wa_phone ?? 'Unknown'}</div>
              <div className="text-xs text-slate-500">{c.contact?.wa_phone} · {formatTime(c.last_message_at)}</div>
            </button>
          </li>
        ))}
      </ul>
      {current ? (
        <Thread conversation={current} onBack={() => setSelected(null)} />
      ) : (
        <div className="hidden rounded-xl bg-white p-6 text-sm text-slate-500 shadow-sm ring-1 ring-slate-200 md:block">
          Pick a conversation.
        </div>
      )}
    </div>
  )
}

function Thread({ conversation, onBack }: { conversation: ConversationSummary; onBack: () => void }) {
  const { tenant } = useAuth()
  const canSend = tenant ? ['owner', 'admin', 'supervisor', 'agent'].includes(tenant.role) : false
  const queryClient = useQueryClient()
  const key = ['messages', conversation.id]
  const thread = useQuery({
    queryKey: key,
    queryFn: () => api<{ window_open: boolean; messages: ChatMessage[] }>(`/conversations/${conversation.id}/messages`),
    refetchInterval: THREAD_POLL_MS,
  })
  const [text, setText] = useState('')
  const [template, setTemplate] = useState({ name: 'hello_world', language: 'en_US' })
  const send = useMutation({
    mutationFn: (body: unknown) => post(`/conversations/${conversation.id}/messages`, body),
    onSuccess: () => {
      setText('')
      queryClient.invalidateQueries({ queryKey: key })
    },
  })

  const windowOpen = thread.data?.window_open ?? conversation.window_open

  const submit = (e: FormEvent) => {
    e.preventDefault()
    send.mutate(windowOpen ? { type: 'text', text } : { type: 'template', template })
  }

  return (
    <section className="flex min-h-[60vh] flex-col rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <header className="flex items-center gap-3 border-b border-slate-100 px-4 py-3">
        <button onClick={onBack} className="text-sm text-emerald-700 md:hidden">Back</button>
        <div>
          <div className="font-medium">{conversation.contact?.name ?? 'Unknown'}</div>
          <div className="text-xs text-slate-500">
            {conversation.contact?.wa_phone} · via {conversation.phone_number?.display_number}
          </div>
        </div>
      </header>
      <ol className="flex-1 space-y-2 overflow-y-auto p-4">
        {thread.data?.messages.map((m) => (
          <li key={m.id} className={`flex ${m.direction === 'out' ? 'justify-end' : 'justify-start'}`}>
            <div className={`max-w-[80%] rounded-lg px-3 py-2 text-sm ${m.direction === 'out' ? 'bg-emerald-100' : 'bg-slate-100'}`}>
              <div className="whitespace-pre-wrap break-words">{m.text}</div>
              <div className="mt-1 text-right text-[11px] text-slate-500">
                {formatTime(m.sent_at)}
                {m.direction === 'out' && ` · ${m.status}`}
              </div>
              {m.error && <div className="mt-1 text-xs text-red-600">{m.error}</div>}
            </div>
          </li>
        ))}
      </ol>
      {canSend && (
        <form onSubmit={submit} className="space-y-2 border-t border-slate-100 p-3">
          {windowOpen ? (
            <div className="flex gap-2">
              <input
                value={text}
                onChange={(e) => setText(e.target.value)}
                placeholder="Type a reply"
                className="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm"
                required
              />
              <Button type="submit" disabled={send.isPending}>Send</Button>
            </div>
          ) : (
            <>
              <p className="text-xs text-slate-600">
                More than 24 hours since the customer last wrote, so only an approved template can be sent.
              </p>
              <div className="flex flex-wrap gap-2">
                <input
                  value={template.name}
                  onChange={(e) => setTemplate({ ...template, name: e.target.value })}
                  aria-label="Template name"
                  className="min-w-0 flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm"
                  required
                />
                <input
                  value={template.language}
                  onChange={(e) => setTemplate({ ...template, language: e.target.value })}
                  aria-label="Template language"
                  className="w-24 rounded-md border border-slate-300 px-3 py-2 text-sm"
                  required
                />
                <Button type="submit" disabled={send.isPending}>Send template</Button>
              </div>
            </>
          )}
          {send.error && (
            <p className="text-sm text-red-600">{send.error instanceof ApiError ? send.error.message : 'Could not send.'}</p>
          )}
        </form>
      )}
    </section>
  )
}

function formatTime(iso: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  const today = new Date().toDateString() === d.toDateString()
  return today
    ? d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })
    : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })
}
