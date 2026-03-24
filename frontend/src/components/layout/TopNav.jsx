import React from 'react'
import { useWorkspaceStore } from '../../store/useWorkspaceStore.js'

const ICONS = {
  grid:  <svg width="15" height="15" viewBox="0 0 15 15" fill="none"><rect x="1.5" y="1.5" width="5" height="5" rx="1.5" fill="currentColor"/><rect x="8.5" y="1.5" width="5" height="5" rx="1.5" fill="currentColor" opacity=".4"/><rect x="1.5" y="8.5" width="5" height="5" rx="1.5" fill="currentColor" opacity=".4"/><rect x="8.5" y="8.5" width="5" height="5" rx="1.5" fill="currentColor" opacity=".15"/></svg>,
  doc:   <svg width="15" height="15" viewBox="0 0 15 15" fill="none"><path d="M3 2h6l3 3v8H3V2z" stroke="currentColor" strokeWidth="1.3" fill="none" strokeLinejoin="round"/><path d="M9 2v3h3" stroke="currentColor" strokeWidth="1.3" fill="none"/><path d="M5 7h5M5 10h3" stroke="currentColor" strokeWidth="1.1" strokeLinecap="round"/></svg>,
  clock: <svg width="15" height="15" viewBox="0 0 15 15" fill="none"><circle cx="7.5" cy="7.5" r="5.5" stroke="currentColor" strokeWidth="1.3"/><path d="M7.5 5v2.5l2 1.2" stroke="currentColor" strokeWidth="1.1" strokeLinecap="round"/></svg>,
  chart: <svg width="15" height="15" viewBox="0 0 15 15" fill="none"><rect x="1.5" y="8.5" width="3" height="5" rx="1" fill="currentColor"/><rect x="6" y="5.5" width="3" height="8" rx="1" fill="currentColor" opacity=".6"/><rect x="10.5" y="2" width="3" height="11.5" rx="1" fill="currentColor" opacity=".35"/></svg>,
}

export function TopNav() {
  const { user, nav, canAnalytics, activeProcessKey, activeScreen, setActiveProcess, setActiveScreen } = useWorkspaceStore()

  const s = {
    nav: {
      display: 'flex', alignItems: 'center', justifyContent: 'space-between',
      padding: '0 20px', background: 'rgba(255,255,255,.82)',
      borderBottom: '.5px solid rgba(0,0,0,.08)', height: 48, flexShrink: 0,
    },
    left: { display: 'flex', alignItems: 'center', gap: 2 },
    tab: (active, disabled) => ({
      display: 'flex', alignItems: 'center', gap: 6,
      padding: '6px 14px', borderRadius: 10, cursor: disabled ? 'default' : 'pointer',
      fontSize: 13, fontWeight: 500, letterSpacing: '-.02em', border: 'none',
      fontFamily: 'inherit', transition: 'background .12s, color .12s',
      background: active ? 'rgba(0,113,227,.08)' : 'transparent',
      color: active ? '#0071e3' : (disabled ? '#c7c7cc' : '#6e6e73'),
      opacity: disabled ? .5 : 1,
      pointerEvents: disabled ? 'none' : 'auto',
    }),
    badge: {
      background: '#ff3b30', color: '#fff', fontSize: 9, fontWeight: 700,
      padding: '1px 5px', borderRadius: 8, marginLeft: 2,
    },
    soon: { fontSize: 9, color: '#c7c7cc', marginLeft: 2 },
    right: { display: 'flex', alignItems: 'center', gap: 10 },
    avatar: {
      width: 28, height: 28, borderRadius: '50%',
      background: 'linear-gradient(135deg,#0071e3,#34aadc)',
      display: 'flex', alignItems: 'center', justifyContent: 'center',
      fontSize: 10, fontWeight: 700, color: '#fff',
    },
    userName: { fontSize: 12, fontWeight: 500, color: '#3a3a3c', letterSpacing: '-.01em' },
    userRole: { fontSize: 10, color: '#86868b', letterSpacing: '-.01em' },
  }

  const initials = user?.name
    ? user.name.split(' ').slice(0,2).map(p => p[0]).join('').toUpperCase()
    : 'КД'

  return (
    <nav style={s.nav}>
      <div style={s.left}>
        {nav.map(item => {
          const isActive = activeScreen === 'deals' && item.key === activeProcessKey
          return (
            <button
              key={item.key}
              style={s.tab(isActive, item.disabled)}
              onClick={() => !item.disabled && setActiveProcess(item.key)}
            >
              <span style={{ color: isActive ? '#0071e3' : '#aeaeb2', display:'flex' }}>
                {ICONS[item.icon] || ICONS.grid}
              </span>
              {item.label}
              {item.badge > 0 && <span style={s.badge}>{item.badge}</span>}
              {item.disabled && <span style={s.soon}>скоро</span>}
            </button>
          )
        })}

        {canAnalytics && (
          <button
            style={s.tab(activeScreen === 'analytics', false)}
            onClick={() => setActiveScreen('analytics')}
          >
            <span style={{ color: activeScreen === 'analytics' ? '#0071e3' : '#aeaeb2', display:'flex' }}>
              {ICONS.chart}
            </span>
            Аналитика
          </button>
        )}
      </div>

      <div style={s.right}>
        {user && (
          <div style={s.right}>
            <div style={s.avatar}>{initials}</div>
            <div>
              <div style={s.userName}>{user.name}</div>
              <div style={s.userRole}>{user.workspace_role_label}</div>
            </div>
          </div>
        )}
      </div>
    </nav>
  )
}
