import React from 'react'
import { ACCENT, formatDate } from '../../utils/index.js'
import { useWorkspaceStore } from '../../store/useWorkspaceStore.js'
import { MetroLine } from './MetroLine.jsx'

export function DealCard({ item }) {
  const openSheet = useWorkspaceStore(s => s.openSheet)
  const accentKey = ACCENT[item.accent_color] || ACCENT.blue
  const stageHex  = normalizeHex(item.stage_color)
  const accent    = stageHex
    ? {
        stripe: stageHex,
        tagBg: hexToRgba(stageHex, 0.12),
        tagText: stageHex,
      }
    : accentKey
  const createdLabel = item.created_at_label || safeFormatDate(item.created_at)

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
    name: { fontSize: 17, fontWeight: 600, color: '#1d1d1f', letterSpacing: '-.025em', lineHeight: 1.25 },
    id:   { fontSize: 12, color: '#8e8e93', marginTop: 3, letterSpacing: '-.01em' },
    rw:   {
      display: 'inline-flex', alignItems: 'center', gap: 3,
      background: 'rgba(255,149,0,.1)', color: '#ff9500',
      fontSize: 9, fontWeight: 600, padding: '2px 6px', borderRadius: 6, marginLeft: 6,
    },
    tag: {
      fontSize: 11, fontWeight: 600, padding: '3px 8px', borderRadius: 8,
      flexShrink: 0, marginTop: 1, letterSpacing: '-.01em',
      background: accent.tagBg, color: accent.tagText,
    },
    step: { fontSize: 13, color: '#6e6e73', marginBottom: 8, letterSpacing: '-.01em' },
    meta: { fontSize: 12, color: '#8e8e93', marginBottom: 9, letterSpacing: '-.01em' },
    actions: { display: 'flex', alignItems: 'center', gap: 6, marginTop: 10 },
    actionBtnSecondary: {
      display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
      fontSize: 12, fontWeight: 500, padding: '5px 10px', borderRadius: 10,
      textDecoration: 'none', letterSpacing: '-.01em',
      background: '#fff', color: '#3a3a3c', border: '0.5px solid rgba(0,0,0,.12)',
    },
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
      <div style={s.meta}>
        {createdLabel ? `Создана: ${createdLabel}` : 'Создана: —'}
        {' · '}
        {item.days_in_work !== null && item.days_in_work !== undefined ? `${item.days_in_work} дн. в работе` : '—'}
      </div>
      {item.current_step && !['_done', '_complete'].includes(item.current_step) && item.current_step_label && (
        <div style={s.step}>
          Сейчас: <strong style={{ color: '#3a3a3c', fontWeight: 500 }}>{item.current_step_label}</strong>
        </div>
      )}
      <MetroLine steps={item.steps_preview || []} />
      <div style={s.foot}>
        <div style={s.avatars}>
          {(item.participants_preview || []).map((p, i) => (
            <Avatar key={i} initials={p.initials} color={p.color} index={i} />
          ))}
        </div>
        <div style={s.hint}>{item.hint}</div>
      </div>
      <div style={s.actions}>
        {item.folder_url && (
          <a
            href={item.folder_url}
            target="_blank"
            rel="noreferrer"
            onClick={e => e.stopPropagation()}
            style={s.actionBtnSecondary}
          >
            📁 Открыть папку
          </a>
        )}
      </div>
    </div>
  )
}

function normalizeHex(value) {
  if (!value || typeof value !== 'string') return null
  const v = value.trim()
  if (/^#[0-9A-Fa-f]{6}$/.test(v)) return v.toUpperCase()
  return null
}

function hexToRgba(hex, alpha) {
  const h = normalizeHex(hex)
  if (!h) return `rgba(0,0,0,${alpha})`
  const r = parseInt(h.slice(1, 3), 16)
  const g = parseInt(h.slice(3, 5), 16)
  const b = parseInt(h.slice(5, 7), 16)
  return `rgba(${r},${g},${b},${alpha})`
}

function safeFormatDate(value) {
  if (!value) return ''
  try {
    const d = formatDate(value)
    return d === 'Invalid Date' ? '' : d
  } catch {
    return ''
  }
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
