import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from './lib/auth'
import { AuthPage } from './pages/AuthPage'
import { Dashboard } from './pages/Dashboard'

export default function App() {
  const { me, loading } = useAuth()

  if (loading) return <div className="p-6 text-sm text-slate-500">Loading…</div>

  return (
    <Routes>
      <Route path="/login" element={me ? <Navigate to="/" /> : <AuthPage mode="login" />} />
      <Route path="/register" element={me ? <Navigate to="/" /> : <AuthPage mode="register" />} />
      <Route path="/*" element={me ? <Dashboard /> : <Navigate to="/login" />} />
    </Routes>
  )
}
