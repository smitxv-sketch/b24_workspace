import React, { useEffect, useRef } from 'react'
import { useWorkspaceStore } from './store/useWorkspaceStore.js'
import { TopNav } from './components/layout/TopNav.jsx'
import { DealsScreen } from './components/deals/DealsScreen.jsx'
import { Sheet } from './components/sheet/Sheet.jsx'
import { AnalyticsScreen } from './components/analytics/AnalyticsScreen.jsx'
import { ReportsScreen } from './components/reports/ReportsScreen.jsx'

export default function App() {
  const { isBootstrapped, bootstrapError, activeScreen, bootstrap, refreshItems } = useWorkspaceStore()
  const pollRef = useRef(null)

  // Bootstrap при загрузке
  useEffect(() => {
    bootstrap()
  }, [])

  // Polling каждые 30 сек
  useEffect(() => {
    if (!isBootstrapped) return
    pollRef.current = setInterval(() => refreshItems(), 30_000)
    return () => clearInterval(pollRef.current)
  }, [isBootstrapped])

  // Горячая клавиша: Escape → закрыть sheet
  useEffect(() => {
    const handler = (e) => {
      if (e.key === 'Escape') useWorkspaceStore.getState().closeSheet()
    }
    document.addEventListener('keydown', handler)
    return () => document.removeEventListener('keydown', handler)
  }, [])

  if (!isBootstrapped) return <LoadingScreen />
  if (bootstrapError)   return <ErrorScreen message={bootstrapError} onRetry={bootstrap} />

  return (
    <div style={{ display: 'flex', flexDirection: 'column', height: '100vh', overflow: 'hidden', background: '#f5f5f7' }}>
      <TopNav />
      <div style={{ flex: 1, overflow: 'hidden', display: 'flex', flexDirection: 'column' }}>
        {activeScreen === 'deals'     && <DealsScreen />}
        {activeScreen === 'analytics' && <AnalyticsScreen />}
        {activeScreen === 'reports'   && <ReportsScreen />}
      </div>
      <Sheet />
    </div>
  )
}

function LoadingScreen() {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', height: '100vh', background: '#f5f5f7', gap: 12 }}>
      <div style={{ width: 32, height: 32, border: '3px solid rgba(0,0,0,.1)', borderTopColor: '#0071e3', borderRadius: '50%', animation: 'spin .6s linear infinite' }} />
      <div style={{ fontSize: 13, color: '#86868b', letterSpacing: '-.01em' }}>Загружаем рабочее место...</div>
      <style>{`@keyframes spin { to { transform: rotate(360deg); } }`}</style>
    </div>
  )
}

function ErrorScreen({ message, onRetry }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', height: '100vh', background: '#f5f5f7', gap: 12 }}>
      <div style={{ fontSize: 32, opacity: .3 }}>⚠</div>
      <div style={{ fontSize: 14, color: '#1d1d1f', fontWeight: 500 }}>Не удалось загрузить воркспейс</div>
      <div style={{ fontSize: 12, color: '#86868b' }}>{message}</div>
      <button onClick={onRetry} style={{ marginTop: 8, fontSize: 13, background: '#0071e3', color: '#fff', border: 'none', padding: '8px 20px', borderRadius: 10, cursor: 'pointer', fontFamily: 'inherit' }}>
        Повторить
      </button>
    </div>
  )
}
