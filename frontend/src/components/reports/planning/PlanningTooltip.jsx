import React from 'react'

export function PlanningTooltip({ open, x, y, items }) {
  if (!open || !items || items.length === 0) return null
  return (
    <div
      style={{
        position: 'fixed',
        left: x + 10,
        top: y + 10,
        zIndex: 80,
        width: 360,
        maxHeight: 320,
        overflowY: 'auto',
        background: '#fff',
        border: '1px solid #d2d2d7',
        borderRadius: 10,
        boxShadow: '0 8px 24px rgba(0,0,0,.15)',
        padding: 10,
      }}
    >
      <div style={{ fontSize: 11, fontWeight: 700, color: '#6e6e73', marginBottom: 6 }}>
        Задачи в выбранном периоде: {items.length}
      </div>
      <div style={{ display: 'grid', gap: 6 }}>
        {items.map((t) => (
          <div key={`${t.task_id}-${t.plan_start}-${t.plan_end}`} style={{ borderBottom: '1px dashed #efeff4', paddingBottom: 6 }}>
            <div style={{ fontSize: 12, color: '#1d1d1f' }}>
              <span style={{ color: '#8e8e93' }}>#{t.task_id}</span> {t.task_name}
            </div>
            <div style={{ fontSize: 11, color: '#6e6e73', marginTop: 2 }}>
              Начало: {t.plan_start || '—'} · Завершение: {t.plan_end || '—'}
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

