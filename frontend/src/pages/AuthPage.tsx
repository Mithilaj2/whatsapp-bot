import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Button, Field } from '../components/Field'
import { ApiError } from '../lib/api'
import { useAuth } from '../lib/auth'

export function AuthPage({ mode }: { mode: 'login' | 'register' }) {
  const { login, register } = useAuth()
  const navigate = useNavigate()
  const [form, setForm] = useState({ name: '', business_name: '', email: '', password: '' })
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [message, setMessage] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const set = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm({ ...form, [key]: e.target.value })

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setMessage(null)
    try {
      if (mode === 'login') await login(form.email, form.password)
      else await register(form)
      navigate('/')
    } catch (err) {
      if (err instanceof ApiError) {
        setErrors(err.errors)
        setMessage(err.message)
      } else setMessage('Could not reach the server.')
    } finally {
      setBusy(false)
    }
  }

  const isLogin = mode === 'login'

  return (
    <div className="flex min-h-screen items-center justify-center px-4">
      <form onSubmit={submit} className="w-full max-w-sm space-y-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
        <h1 className="text-xl font-semibold">{isLogin ? 'Sign in' : 'Create your account'}</h1>
        {!isLogin && (
          <>
            <Field label="Your name" value={form.name} onChange={set('name')} error={errors.name?.[0]} required />
            <Field label="Business name" value={form.business_name} onChange={set('business_name')} error={errors.business_name?.[0]} required />
          </>
        )}
        <Field label="Email" type="email" value={form.email} onChange={set('email')} error={errors.email?.[0]} required autoComplete="email" />
        <Field
          label="Password"
          type="password"
          value={form.password}
          onChange={set('password')}
          error={errors.password?.[0]}
          required
          autoComplete={isLogin ? 'current-password' : 'new-password'}
        />
        {message && Object.keys(errors).length === 0 && <p className="text-sm text-red-600">{message}</p>}
        <Button type="submit" disabled={busy} className="w-full">
          {isLogin ? 'Sign in' : 'Create account'}
        </Button>
        <p className="text-center text-sm text-slate-600">
          {isLogin ? (
            <>New here? <Link className="text-emerald-700 underline" to="/register">Create an account</Link></>
          ) : (
            <>Already have an account? <Link className="text-emerald-700 underline" to="/login">Sign in</Link></>
          )}
        </p>
      </form>
    </div>
  )
}
