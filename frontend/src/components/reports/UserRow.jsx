import React, { useState } from 'react'
import { ProjectRow } from './ProjectRow.jsx'
import { TaskRow } from './TaskRow.jsx'

export function UserRow({ row, mode = 'users_projects', onOpenUserDetails, onOpenProjectDetails }) {
  const [open, setOpen] = useState(false)
  const allTasks = row.tasks || []
  const activeNow = allTasks.filter(t => [1, 2, 3, 4].includes(Number(t.status_code))).length
  const doneCount = allTasks.filter(t => Number(t.status_code) === 5).length
  return (
    <div style={{ border: '1px solid #e9e9ed', borderRadius: 12, background: '#fff', overflow: 'hidden' }}>
      <div style={{ width: '100%', background: '#fff', padding: '12px 14px', display: 'grid', gridTemplateColumns: '1fr auto 120px 140px', gap: 10, textAlign: 'left', alignItems: 'center' }}>
        <button
          onClick={() => setOpen(v => !v)}
          style={{ border: 'none', background: 'transparent', cursor: 'pointer', textAlign: 'left', padding: 0 }}
        >
          <div style={{ fontSize: 15, color: '#1d1d1f', fontWeight: 600 }}>{open ? '▾' : '▸'} {row.user_name}</div>
          <div style={{ fontSize: 12, color: '#6e6e73', marginTop: 2 }}>
            {row.dept_name || '—'} · сейчас: {activeNow} · закрыто: {doneCount}
          </div>
        </button>
        <button
          onClick={() => onOpenUserDetails?.(row)}
          style={{ border: '1px solid #d2d2d7', background: '#fff', borderRadius: 6, fontSize: 11, padding: '3px 7px', cursor: 'pointer' }}
        >
          Детали
        </button>
        <div style={{ fontSize: 14, color: '#1d1d1f', fontWeight: 600, alignSelf: 'center', textAlign: 'right' }}>
          {Number(row.total_hours || 0).toFixed(2)} ч
        </div>
        <div style={{ fontSize: 14, color: '#1d1d1f', fontWeight: 600, alignSelf: 'center', textAlign: 'right' }}>
          {Number(row.salary || 0).toFixed(2)} ₽
        </div>
      </div>
      {open && (
        <div style={{ padding: '0 10px 10px 10px' }}>
          {mode === 'users_only'
            ? (row.tasks || []).map((task, idx) => (
                <TaskRow key={`${task.task_id || idx}-${idx}`} task={task} />
              ))
            : (row.projects || []).map((project, idx) => (
                <ProjectRow
                  key={`${project.project_id ?? 'none'}-${idx}`}
                  project={project}
                  onOpenDetails={onOpenProjectDetails}
                />
              ))}
        </div>
      )}
    </div>
  )
}

