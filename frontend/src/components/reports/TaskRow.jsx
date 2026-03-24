import React from 'react'

export function TaskRow({ task, showProjectSuffix = false }) {
  const fact = Number(task.fact_hours ?? task.hours ?? 0)
  const plan = task.plan_hours === null || task.plan_hours === undefined ? null : Number(task.plan_hours)
  const variance = task.variance_hours === null || task.variance_hours === undefined ? null : Number(task.variance_hours)
  const status = task.status_label || 'Неизвестно'
  const tone = task.is_overdue
    ? { bg: 'rgba(215,0,21,.08)', fg: '#d70015' }
    : (variance !== null && variance > 0)
      ? { bg: 'rgba(255,149,0,.12)', fg: '#a05a00' }
      : { bg: 'rgba(52,199,89,.12)', fg: '#248a3d' }

  return (
    <div style={{ padding: '8px 12px', borderTop: '1px dashed #efeff4' }}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr auto', gap: 10, alignItems: 'center' }}>
        <div style={{ fontSize: 13, color: '#1d1d1f' }}>
          <a
            href={`/company/personal/user/0/tasks/task/view/${task.task_id}/`}
            target="_blank"
            rel="noreferrer"
            style={{ color: '#1d1d1f', textDecoration: 'none' }}
          >
            <span style={{ color: '#8e8e93' }}>#{task.task_id}</span> {task.task_name}
          </a>
          {showProjectSuffix && task.project_name && (
            <span style={{ marginLeft: 6, color: '#6e6e73' }}>
              ({String(task.project_name).slice(0, 15)}{String(task.project_name).length > 15 ? '...' : ''})
            </span>
          )}
        </div>
        <span style={{ fontSize: 11, padding: '3px 8px', borderRadius: 999, background: tone.bg, color: tone.fg }}>{status}</span>
      </div>
      <div style={{ marginTop: 5, display: 'grid', gridTemplateColumns: 'repeat(5, minmax(0, auto))', gap: 12, fontSize: 12, color: '#6e6e73' }}>
        <span>Дата: {task.date || '—'}</span>
        <span>Срок: {task.deadline || '—'}</span>
        <span>План: {plan === null ? '—' : `${plan.toFixed(2)} ч`}</span>
        <span style={{ color: '#1d1d1f' }}>Факт: {fact.toFixed(2)} ч</span>
        <span style={{ color: variance === null ? '#6e6e73' : (variance > 0 ? '#d70015' : '#248a3d') }}>
          Δ: {variance === null ? '—' : `${variance.toFixed(2)} ч`}
        </span>
      </div>
    </div>
  )
}

