import React from 'react'
import { useWorkspaceStore } from '../../store/useWorkspaceStore.js'
import { DealCard } from './DealCard.jsx'

const FILTER_DEFS = {
  all:     { label: 'Все',            base: '#1d1d1f', baseOff: 'rgba(0,0,0,.07)', textOff: '#6e6e73' },
  overdue: { label: 'Просрочено',     base: 'rgba(255,59,48,.12)', text: '#ff3b30', off: 'rgba(0,0,0,.06)' },
  action:  { label: 'Нужно решение', base: 'rgba(0,113,227,.1)',  text: '#0071e3', off: 'rgba(0,0,0,.06)' },
  wait:    { label: 'Ожидание',       base: 'rgba(255,149,0,.1)',  text: '#ff9500', off: 'rgba(0,0,0,.06)' },
}

export function DealsScreen() {
  const { items, sections, workspaceConfig, activeFilter, isLoadingItems, setFilter } = useWorkspaceStore()

  const availableFilters  = ['all', ...(workspaceConfig?.filters || [])]
  const totalAttention    = sections.attention?.count || 0
  const totalActive       = sections.active?.count || 0
  const totalItems        = items.length

  const bySection = { attention: [], active: [], closed: [] }
  items.forEach(item => { (bySection[item.section] || bySection.active).push(item) })

  const s = {
    wrap: { flex: 1, overflowY: 'auto', padding: '16px 20px 80px' },
    head: { marginBottom: 14 },
    title: { fontSize: 20, fontWeight: 700, color: '#1d1d1f', letterSpacing: '-.04em' },
    sub: { fontSize: 11, color: '#86868b', marginTop: 3, letterSpacing: '-.01em' },
    pills: { display: 'flex', gap: 6, marginBottom: 16, flexWrap: 'wrap' },
    secLabel: { fontSize: 11, fontWeight: 700, color: '#86868b', textTransform: 'uppercase', letterSpacing: '.07em', margin: '4px 0 8px 2px' },
  }

  if (isLoadingItems && items.length === 0) return <Skeleton />

  return (
    <div style={s.wrap} className="fade-in">
      <div style={s.head}>
        <div style={s.title}>Заявки ТКП</div>
        <div style={s.sub}>
          {totalActive + totalAttention} активных
          {totalAttention > 0 && ` · ${totalAttention} требуют внимания`}
        </div>
      </div>

      <div style={s.pills}>
        {availableFilters.map(key => (
          <FilterPill
            key={key}
            filterKey={key}
            active={activeFilter === key}
            onClick={() => setFilter(key)}
          />
        ))}
      </div>

      {bySection.attention.length > 0 && (
        <>
          <div style={s.secLabel}>Требуют внимания</div>
          {bySection.attention.map(item => <DealCard key={item.id} item={item} />)}
        </>
      )}

      {bySection.active.length > 0 && (
        <>
          <div style={{ ...s.secLabel, marginTop: bySection.attention.length > 0 ? 10 : 0 }}>В работе</div>
          {bySection.active.map(item => <DealCard key={item.id} item={item} />)}
        </>
      )}

      {bySection.closed.length > 0 && (
        <>
          <div style={{ ...s.secLabel, marginTop: 10 }}>Закрыты</div>
          {bySection.closed.map(item => <DealCard key={item.id} item={item} />)}
        </>
      )}

      {totalItems === 0 && !isLoadingItems && (
        <div style={{ textAlign: 'center', padding: '60px 0', color: '#aeaeb2', fontSize: 13 }}>
          Нет заявок по выбранному фильтру
        </div>
      )}
    </div>
  )
}

function FilterPill({ filterKey, active, onClick }) {
  const def = FILTER_DEFS[filterKey] || { label: filterKey }
  const style = {
    fontSize: 11, fontWeight: 500, padding: '5px 12px', borderRadius: 14,
    cursor: 'pointer', letterSpacing: '-.01em', border: 'none', fontFamily: 'inherit',
    transition: 'all .15s',
    ...(filterKey === 'all'
      ? { background: active ? '#1d1d1f' : 'rgba(0,0,0,.07)', color: active ? '#fff' : '#6e6e73' }
      : { background: active ? def.base : 'rgba(0,0,0,.06)', color: active ? def.text : '#6e6e73' }
    ),
  }
  return <button style={style} onClick={onClick}>{def.label}</button>
}

function Skeleton() {
  return (
    <div style={{ padding: '16px 20px' }}>
      {[1,2,3].map(i => (
        <div key={i} className="skeleton" style={{ height: 96, marginBottom: 8, borderRadius: 16 }} />
      ))}
    </div>
  )
}
