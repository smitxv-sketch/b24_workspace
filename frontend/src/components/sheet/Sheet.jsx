import React, { useEffect, useState } from 'react'
import { useWorkspaceStore } from '../../store/useWorkspaceStore.js'
import { ALERT, AVATAR, formatHours, formatRelativeTime } from '../../utils/index.js'
import { SheetMetroLine } from './SheetMetroLine.jsx'

export function Sheet() {
  const { isSheetOpen, dealDetail, isLoadingDetail, closeSheet } = useWorkspaceStore()
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    if (isSheetOpen) setTimeout(() => setVisible(true), 10)
    else setVisible(false)
  }, [isSheetOpen])

  if (!isSheetOpen) return null

  const detail = dealDetail

  return (
    <div
      style={{
        position: 'fixed', inset: 0, zIndex: 50,
        background: visible ? 'rgba(0,0,0,.28)' : 'transparent',
        backdropFilter: visible ? 'blur(6px)' : 'none',
        WebkitBackdropFilter: visible ? 'blur(6px)' : 'none',
        display: 'flex', alignItems: 'flex-end',
        transition: 'background .3s, backdrop-filter .3s',
      }}
      onClick={e => e.target === e.currentTarget && closeSheet()}
    >
      <div style={{
        background: '#f5f5f7', borderRadius: '22px 22px 0 0',
        width: '100%', maxHeight: '88vh', overflowY: 'auto',
        transform: visible ? 'translateY(0)' : 'translateY(100%)',
        transition: 'transform .38s cubic-bezier(.25,.46,.45,.94)',
      }}>
        {/* Handle */}
        <div style={{ width: 38, height: 4, background: '#c7c7cc', borderRadius: 2, margin: '10px auto 0' }} />

        {isLoadingDetail ? <SheetSkeleton /> : detail ? <SheetContent detail={detail} /> : null}
      </div>
    </div>
  )
}

function SheetContent({ detail }) {
  const closeSheet = useWorkspaceStore(s => s.closeSheet)
  const sections   = detail.sections_to_show || []

  return (
    <>
      {/* Header */}
      <div style={{ padding: '14px 20px 0', display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 10 }}>
        <div>
          <div style={{ fontSize: 20, fontWeight: 700, color: '#1d1d1f', letterSpacing: '-.04em', lineHeight: 1.2 }}>
            {detail.title}
            {detail.is_rework && (
              <span style={{ display: 'inline-flex', alignItems: 'center', background: 'rgba(255,149,0,.1)', color: '#ff9500', fontSize: 11, fontWeight: 600, padding: '2px 8px', borderRadius: 8, marginLeft: 8, verticalAlign: 'middle' }}>
                ↩ v{detail.version}
              </span>
            )}
          </div>
          <div style={{ fontSize: 12, color: '#86868b', marginTop: 3, letterSpacing: '-.01em', lineHeight: 1.5 }}>{detail.subtitle}</div>
        </div>
        <button onClick={closeSheet} style={{ width: 28, height: 28, borderRadius: '50%', background: 'rgba(0,0,0,.07)', border: 'none', cursor: 'pointer', fontSize: 13, color: '#6e6e73', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0, marginTop: 2 }}>✕</button>
      </div>

      {/* Chips */}
      <div style={{ display: 'flex', gap: 6, padding: '12px 20px 0', flexWrap: 'wrap' }}>
        {(detail.chips || []).map((chip, i) => (
          <a key={i} href={chip.url} target="_blank" rel="noreferrer" style={{
            fontSize: 12, fontWeight: 500, padding: '5px 12px', borderRadius: 12,
            border: '.5px solid rgba(0,0,0,.1)', textDecoration: 'none', letterSpacing: '-.01em',
            background: chip.style === 'primary' ? '#0071e3' : '#fff',
            color: chip.style === 'primary' ? '#fff' : '#3a3a3c',
          }}>{chip.label}</a>
        ))}
      </div>

      {/* Alert */}
      {sections.includes('alert') && detail.alert && <AlertBanner alert={detail.alert} />}

      {/* Participants */}
      {sections.includes('participants') && detail.participants?.length > 0 && (
        <Section title="Команда">
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
            {detail.participants.map((p, i) => <ParticipantChip key={i} p={p} />)}
          </div>
        </Section>
      )}

      {/* Timeline */}
      {sections.includes('timeline') && detail.timeline?.length > 0 && (
        <Section title="Ход процесса">
          <SheetMetroLine steps={detail.timeline} />
        </Section>
      )}

      {/* Tasks */}
      {sections.includes('tasks') && detail.tasks?.length > 0 && (
        <Section title="Задачи">
          <TaskList tasks={detail.tasks} />
        </Section>
      )}

      {/* Discipline */}
      {sections.includes('discipline') && detail.discipline?.length > 0 && (
        <Section title=""><DisciplineBlock rows={detail.discipline} /></Section>
      )}

      {/* Feed */}
      {sections.includes('feed') && detail.feed?.length > 0 && (
        <Section title="Лента событий">
          <EventFeed events={detail.feed} />
        </Section>
      )}

      <div style={{ height: 32 }} />
    </>
  )
}

// ── Секции ─────────────────────────────────────────────────────────────────

function Section({ title, children }) {
  return (
    <div style={{ padding: '16px 20px 0' }}>
      {title && <div style={{ fontSize: 11, fontWeight: 700, color: '#aeaeb2', textTransform: 'uppercase', letterSpacing: '.07em', marginBottom: 10 }}>{title}</div>}
      {children}
    </div>
  )
}

function AlertBanner({ alert }) {
  const colors = ALERT[alert.type] || ALERT.blue
  return (
    <div style={{ margin: '14px 20px 0', borderRadius: 14, padding: '12px 14px', background: colors.bg, border: `.5px solid ${colors.border}` }}>
      <div style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.07em', color: colors.label, marginBottom: 3 }}>{alert.label}</div>
      <div style={{ fontSize: 14, fontWeight: 600, color: '#1d1d1f', letterSpacing: '-.025em', marginBottom: 3 }}>{alert.title}</div>
      <div style={{ fontSize: 12, color: '#6e6e73', lineHeight: 1.5, letterSpacing: '-.01em', marginBottom: alert.activity_url ? 10 : 0 }}>{alert.description}</div>
      {alert.activity_url && (
        <a
          href={alert.activity_url}
          target="_blank"
          rel="noreferrer"
          style={{
            display: 'inline-flex', alignItems: 'center', gap: 4,
            fontSize: 13, fontWeight: 500, color: colors.label,
            background: 'rgba(255,255,255,.6)', padding: '5px 12px',
            borderRadius: 8, textDecoration: 'none', letterSpacing: '-.01em',
            border: `.5px solid ${colors.border}`,
          }}
        >
          ↗ Перейти к заданию
        </a>
      )}
    </div>
  )
}

function ParticipantChip({ p }) {
  const c = AVATAR[p.avatar_color] || AVATAR.gray
  const dotColor = p.status === 'active' || p.status === 'approve' ? '#34c759' : p.status === 'wait' ? '#ff9500' : '#c7c7cc'
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '5px 10px', borderRadius: 10, background: '#fff', border: '.5px solid rgba(0,0,0,.07)' }}>
      <div style={{ width: 22, height: 22, borderRadius: '50%', background: c.bg, color: c.text, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 8, fontWeight: 700 }}>{p.initials}</div>
      <div>
        <div style={{ fontSize: 11, fontWeight: 500, color: '#1d1d1f', letterSpacing: '-.01em' }}>{p.name}</div>
        <div style={{ fontSize: 10, color: '#86868b' }}>{p.role_label}</div>
      </div>
      <div style={{ width: 6, height: 6, borderRadius: '50%', background: dotColor, flexShrink: 0 }} />
    </div>
  )
}

function TaskList({ tasks }) {
  return (
    <div>
      {tasks.map(task => {
        const bgMap  = { done: 'rgba(52,199,89,.15)', review: 'rgba(255,149,0,.15)', open: 'rgba(0,0,0,.05)' }
        const icon   = task.state === 'done'
          ? <svg width="11" height="11" viewBox="0 0 11 11"><path d="M2 5.5l2.5 2.5L9 3" stroke="#34c759" strokeWidth="1.5" fill="none" strokeLinecap="round"/></svg>
          : task.state === 'review'
          ? <svg width="11" height="11" viewBox="0 0 11 11"><circle cx="5.5" cy="5.5" r="3" fill="#ff9500"/></svg>
          : null

        return (
          <div key={task.id} style={{ display: 'flex', gap: 10, padding: '9px 0', borderBottom: '.5px solid rgba(0,0,0,.05)' }}>
            <div style={{ width: 20, height: 20, borderRadius: 6, background: bgMap[task.state] || bgMap.open, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0, marginTop: 1 }}>
              {icon}
            </div>
            <div>
              <div style={{ fontSize: 13, fontWeight: 500, color: task.state === 'open' ? '#aeaeb2' : '#1d1d1f', letterSpacing: '-.02em' }}>{task.title}</div>
              {task.meta && <div style={{ fontSize: 11, color: '#86868b', marginTop: 2, letterSpacing: '-.01em' }}>{task.meta}</div>}
              {task.tag && (
                <span style={{ display: 'inline-block', fontSize: 10, padding: '2px 6px', borderRadius: 6, marginTop: 3, background: 'rgba(255,149,0,.1)', color: '#ff9500' }}>{task.tag}</span>
              )}
            </div>
          </div>
        )
      })}
    </div>
  )
}

function DisciplineBlock({ rows }) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <div
        onClick={() => setOpen(!open)}
        style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '10px 0 6px', cursor: 'pointer', userSelect: 'none' }}
      >
        <span style={{ fontSize: 11, fontWeight: 700, color: '#aeaeb2', textTransform: 'uppercase', letterSpacing: '.07em' }}>Дисциплина по заявке</span>
        <span style={{ fontSize: 11, color: '#aeaeb2' }}>{open ? '▲' : '▼'}</span>
      </div>
      {open && (
        <div style={{ background: '#fff', borderRadius: 12, border: '.5px solid rgba(0,0,0,.07)', overflow: 'hidden', marginBottom: 6 }}>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 46px 46px 52px', fontSize: 10, padding: '6px 10px', borderBottom: '.5px solid rgba(0,0,0,.05)', background: 'rgba(0,0,0,.02)', color: '#86868b', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '.04em' }}>
            <span>Шаг</span><span style={{ textAlign: 'right' }}>План</span><span style={{ textAlign: 'right' }}>Факт</span><span style={{ textAlign: 'right' }}>Δ</span>
          </div>
          {rows.map((row, i) => (
            <div key={i} style={{ display: 'grid', gridTemplateColumns: '1fr 46px 46px 52px', fontSize: 11, padding: '6px 10px', borderBottom: i < rows.length - 1 ? '.5px solid rgba(0,0,0,.05)' : 'none', alignItems: 'center', letterSpacing: '-.01em' }}>
              <span style={{ color: '#1d1d1f' }}>{row.step_label}</span>
              <span style={{ textAlign: 'right', color: '#86868b' }}>{row.plan_hours} ч</span>
              <span style={{ textAlign: 'right', fontWeight: row.state === 'overdue' ? 600 : 400, color: row.state === 'ok' ? '#34c759' : row.state === 'overdue' ? '#ff3b30' : '#ff9500' }}>
                {formatHours(row.fact_hours, row.state === 'running')}
              </span>
              <span style={{ textAlign: 'right', fontSize: 10, color: row.delta === null ? '#ff9500' : row.delta > 0 ? '#ff3b30' : '#34c759' }}>
                {row.delta === null ? 'идёт' : (row.delta > 0 ? '+' : '') + row.delta}
              </span>
            </div>
          ))}
        </div>
      )}
    </>
  )
}

function EventFeed({ events }) {
  const COLORS = {
    green: { bg: 'rgba(52,199,89,.15)', text: '#34c759' },
    red:   { bg: 'rgba(255,59,48,.12)', text: '#ff3b30' },
    blue:  { bg: 'rgba(0,113,227,.12)', text: '#0071e3' },
    amber: { bg: 'rgba(255,149,0,.15)', text: '#ff9500' },
    system:{ bg: 'rgba(0,0,0,.05)',     text: '#aeaeb2' },
  }
  return (
    <div>
      {events.map((ev, i) => {
        const c = COLORS[ev.color] || COLORS.system
        return (
          <div key={i} style={{ display: 'flex', gap: 8, padding: '7px 0', borderBottom: i < events.length - 1 ? '.5px solid rgba(0,0,0,.05)' : 'none' }}>
            <div style={{ width: 24, height: 24, borderRadius: '50%', background: c.bg, color: c.text, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 8, fontWeight: 700, flexShrink: 0, marginTop: 1 }}>
              {ev.initials}
            </div>
            <div>
              <div style={{ fontSize: 12, color: '#1d1d1f', lineHeight: 1.5, letterSpacing: '-.01em' }}>{ev.text}</div>
              {ev.date_ts > 0 && <div style={{ fontSize: 10, color: '#aeaeb2', marginTop: 1 }}>{formatRelativeTime(new Date(ev.date_ts * 1000).toISOString())}</div>}
            </div>
          </div>
        )
      })}
    </div>
  )
}

function SheetSkeleton() {
  return (
    <div style={{ padding: '16px 20px' }}>
      <div className="skeleton" style={{ height: 28, width: '60%', marginBottom: 8 }} />
      <div className="skeleton" style={{ height: 16, width: '40%', marginBottom: 20 }} />
      <div className="skeleton" style={{ height: 72, borderRadius: 14, marginBottom: 16 }} />
      <div className="skeleton" style={{ height: 16, width: '20%', marginBottom: 12 }} />
      {[1,2,3,4].map(i => <div key={i} className="skeleton" style={{ height: 12, marginBottom: 10, width: `${70 + i*5}%` }} />)}
    </div>
  )
}
