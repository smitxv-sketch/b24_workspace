import React, { useState } from 'react'
import { TaskRow } from './TaskRow.jsx'

export function ProjectRow({ project, onOpenDetails }) {
  const [open, setOpen] = useState(false)
  return (
    <div style={{ marginTop: 8, border: '1px solid #f1f1f4', borderRadius: 10, overflow: 'hidden' }}>
      <div style={{ width: '100%', display: 'grid', gridTemplateColumns: '1fr auto 120px', gap: 10, padding: '9px 12px', border: 'none', background: '#fafafd', textAlign: 'left' }}>
        <button
          onClick={() => setOpen(v => !v)}
          style={{ border: 'none', background: 'transparent', cursor: 'pointer', textAlign: 'left', padding: 0 }}
        >
          <span style={{ fontSize: 13, color: '#1d1d1f' }}>
            {open ? '▾' : '▸'} {project.project_name}
          </span>
        </button>
        <button
          onClick={() => onOpenDetails?.(project)}
          style={{ border: '1px solid #d2d2d7', background: '#fff', borderRadius: 6, fontSize: 11, padding: '3px 7px', cursor: 'pointer' }}
        >
          Детали
        </button>
        <span style={{ fontSize: 13, color: '#1d1d1f', fontWeight: 600, textAlign: 'right' }}>
          {Number(project.hours || 0).toFixed(2)} ч
        </span>
      </div>
      {open && (
        <div>
          {(project.tasks || []).map((task, idx) => (
            <TaskRow key={`${task.task_id || idx}-${idx}`} task={task} />
          ))}
        </div>
      )}
    </div>
  )
}

