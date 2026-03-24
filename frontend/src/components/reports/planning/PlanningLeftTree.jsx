import React from 'react'

export function PlanningLeftTree({ departments }) {
  return (
    <div style={{ minWidth: 300, maxWidth: 300, borderRight: '1px solid #e5e5ea', background: '#fff' }}>
      <div style={{ padding: '8px 10px', fontSize: 11, color: '#6e6e73', fontWeight: 700, textTransform: 'uppercase' }}>
        Отделы и сотрудники
      </div>
      <div style={{ display: 'grid', gap: 8, padding: '0 10px 10px' }}>
        {(departments || []).map((d) => (
          <div key={d.id}>
            <div style={{ fontSize: 12, color: '#1d1d1f', fontWeight: 700, marginBottom: 4 }}>{d.name}</div>
            <div style={{ display: 'grid', gap: 4 }}>
              {(d.users || []).map((u) => (
                <div key={u.user_id} style={{ fontSize: 12, color: '#3a3a3c', paddingLeft: 10 }}>
                  {u.user_name}
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

