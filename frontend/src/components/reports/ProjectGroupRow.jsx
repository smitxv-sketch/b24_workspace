import React, { useState } from 'react'
import { TaskRow } from './TaskRow.jsx'

function ProjectUserRow({ user }) {
  const [open, setOpen] = useState(false)
  return (
    <div style={{ marginTop: 8, border: '1px solid #f1f1f4', borderRadius: 10, overflow: 'hidden' }}>
      <button
        onClick={() => setOpen(v => !v)}
        style={{ width: '100%', display: 'grid', gridTemplateColumns: '1fr 120px', gap: 10, padding: '9px 12px', border: 'none', background: '#fafafd', cursor: 'pointer', textAlign: 'left' }}
      >
        <span style={{ fontSize: 13, color: '#1d1d1f' }}>{open ? '▾' : '▸'} {user.user_name}</span>
        <span style={{ fontSize: 13, color: '#1d1d1f', fontWeight: 600, textAlign: 'right' }}>{Number(user.hours || 0).toFixed(2)} ч</span>
      </button>
      {open && (
        <div>
          {(user.tasks || []).map((task, idx) => (
            <TaskRow key={`${task.task_id || idx}-${idx}`} task={task} />
          ))}
        </div>
      )}
    </div>
  )
}

export function ProjectGroupRow({ row }) {
  const [open, setOpen] = useState(false)
  return (
    <div style={{ border: '1px solid #e9e9ed', borderRadius: 12, background: '#fff', overflow: 'hidden' }}>
      <button
        onClick={() => setOpen(v => !v)}
        style={{ width: '100%', border: 'none', background: '#fff', cursor: 'pointer', padding: '12px 14px', display: 'grid', gridTemplateColumns: '1fr 120px', gap: 10, textAlign: 'left' }}
      >
        <div style={{ fontSize: 15, color: '#1d1d1f', fontWeight: 600 }}>{open ? '▾' : '▸'} {row.project_name}</div>
        <div style={{ fontSize: 14, color: '#1d1d1f', fontWeight: 600, textAlign: 'right' }}>{Number(row.hours || 0).toFixed(2)} ч</div>
      </button>
      {open && (
        <div style={{ padding: '0 10px 10px 10px' }}>
          {(row.users || []).map((u, idx) => (
            <ProjectUserRow key={`${u.user_id || idx}-${idx}`} user={u} />
          ))}
        </div>
      )}
    </div>
  )
}

