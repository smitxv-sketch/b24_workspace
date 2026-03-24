import React from 'react'
import { ACCENT } from '../../utils/index.js'
import { useWorkspaceStore } from '../../store/useWorkspaceStore.js'

export function DealCard({ item }) {
  const openSheet = useWorkspaceStore(s => s.openSheet)
  const accent    = ACCENT[item.accent_color] || ACCENT.blue

  const s = {
    card: {
      background: '#fff', borderRadius: 16, padding: '14px 16px',
      cursor: 'pointer', marginBottom: 8, position: 'relative', overflow: 'hidden',
      transition: 'transform .12s, box-shadow .12s',
      boxShadow: '0 1px 3px rgba(0,0,0,.04)',
    },
    stripe: {
      position: 'absolute', left: 0, top: 0, bottom: 0,
      width: 3.5, background: accent.stripe, borderRadius: '3.5px 0 0 3.5px',
    },
    head: { display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', marginBottom: 4 },
    name: { fontSize: 14, fontWeight: 600, color: '#1d1d1f', letterSpacing: '-.025em', lineHeight: 1.25 },
    id:   { fontSize: 10, color: '#aeaeb2', marginTop: 2, letterSpacing: '-.01em' },
    rw:   {
      display: 'inline-flex', alignItems: 'center', gap: 3,
      background: 'rgba(255,149,0,.1)', color: '#ff9500',
      fontSize: 9, fontWeight: 600, padding: '2px 6px', borderRadius: 6, marginLeft: 6,
    },
    tag: {
      fontSize: 10, fontWeight: 600, padding: '3px 8px', borderRadius: 8,
      flexShrink: 0, marginTop: 1, letterSpacing: '-.01em',
      background: accent.tagBg, color: accent.tagText,
    },
    step: { fontSize: 11, color: '#6e6e73', marginBottom: 8, letterSpacing: '-.01em' },
    pbWrap: { height: 3, background: 'rgba(0,0,0,.07)', borderRadius: 2, overflow: 'hidden', marginBottom: 8 },
    pbFill: { height: '100%', borderRadius: 2, background: accent.stripe, width: item.progress_percent + '%', transition: 'width .4s ease' },
    foot: { display: 'flex', alignItems: 'center', justifyContent: 'space-between' },
    avatars: { display: 'flex' },
    hint: { fontSize: 10, color: '#aeaeb2', letterSpacing: '-.01em' },
  }

  return (
    <div
      style={s.card}
      onClick={() => openSheet(item.entity_id, item.process_key)}
      onMouseEnter={e => { e.currentTarget.style.boxShadow = '0 4px 16px rgba(0,0,0,.09)'; e.currentTarget.style.transform = 'translateY(-1px)' }}
      onMouseLeave={e => { e.currentTarget.style.boxShadow = '0 1px 3px rgba(0,0,0,.04)'; e.currentTarget.style.transform = '' }}
      onMouseDown={e => { e.currentTarget.style.transform = 'scale(.989)' }}
      onMouseUp={e => { e.currentTarget.style.transform = '' }}
    >
      <div style={s.stripe} />
      <div style={s.head}>
        <div>
          <div style={s.name}>
            {item.title}
            {item.is_rework && <span style={s.rw}>↩ v{item.version}</span>}
          </div>
          <div style={s.id}>#{item.entity_id}</div>
        </div>
        <span style={s.tag}>{item.status_label}</span>
      </div>
      {item.current_step_label && (
        <div style={s.step}>
          Сейчас: <strong style={{ color: '#3a3a3c', fontWeight: 500 }}>{item.current_step_label}</strong>
        </div>
      )}
      <div style={s.pbWrap}><div style={s.pbFill} /></div>
      <div style={s.foot}>
        <div style={s.avatars}>
          {(item.participants_preview || []).map((p, i) => (
            <Avatar key={i} initials={p.initials} color={p.color} index={i} />
          ))}
        </div>
        <div style={s.hint}>{item.hint}</div>
      </div>
    </div>
  )
}

function Avatar({ initials, color, index }) {
  const COLORS = {
    green:  { bg: 'rgba(52,199,89,.15)',  text: '#34c759' },
    blue:   { bg: 'rgba(0,113,227,.12)', text: '#0071e3' },
    amber:  { bg: 'rgba(255,149,0,.15)', text: '#ff9500' },
    red:    { bg: 'rgba(255,59,48,.12)', text: '#ff3b30' },
    gray:   { bg: 'rgba(0,0,0,.06)',    text: '#86868b' },
  }
  const c = COLORS[color] || COLORS.gray
  return (
    <div style={{
      width: 20, height: 20, borderRadius: '50%', border: '2px solid #fff',
      background: c.bg, color: c.text, display: 'flex', alignItems: 'center',
      justifyContent: 'center', fontSize: 7, fontWeight: 700,
      marginLeft: index === 0 ? 0 : -5, zIndex: 10 - index,
    }}>
      {initials}
    </div>
  )
}
