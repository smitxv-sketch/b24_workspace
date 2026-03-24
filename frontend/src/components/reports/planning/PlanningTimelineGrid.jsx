import React from 'react'
import { intensityToColor, pickCell } from './planningUtils.js'

export function PlanningTimelineGrid({ planning, onHoverCell, onLeaveCell }) {
  const timeline = planning?.timeline || []
  const departments = planning?.departments || []
  const todayIndex = Number(planning?.today_index ?? -1)
  const anchorIdx = Number(planning?.anchor_quarter_index ?? 0)

  const cellWidth = 36
  const stickyLeft = Math.max(0, (anchorIdx * cellWidth))

  return (
    <div style={{ position: 'relative', overflowX: 'auto', background: '#fff' }}>
      <div style={{ minWidth: Math.max(900, timeline.length * cellWidth), position: 'relative' }}>
        <div style={{ display: 'grid', gridTemplateColumns: `repeat(${timeline.length}, ${cellWidth}px)`, borderBottom: '1px solid #e5e5ea' }}>
          {timeline.map((t, idx) => (
            <div key={idx} style={{ fontSize: 10, color: '#6e6e73', textAlign: 'center', padding: '6px 2px', borderRight: '1px solid #f2f2f7' }}>
              {t.label}
            </div>
          ))}
        </div>

        <div
          style={{
            position: 'absolute',
            top: 0,
            bottom: 0,
            left: todayIndex >= 0 ? `${todayIndex * cellWidth + cellWidth / 2}px` : `${stickyLeft}px`,
            width: 2,
            background: '#0071e3',
            opacity: 0.45,
            pointerEvents: 'none',
          }}
        />

        <div style={{ display: 'grid', gap: 8, padding: '8px 0' }}>
          {departments.map((d) => (
            <div key={d.id}>
              {(d.users || []).map((u) => (
                <div key={u.user_id} style={{ display: 'grid', gridTemplateColumns: `repeat(${timeline.length}, ${cellWidth}px)` }}>
                  {timeline.map((bucket, idx) => {
                    const cell = pickCell(u.cells, idx)
                    const intensity = Number(cell?.intensity || 0)
                    return (
                      <div
                        key={idx}
                        onMouseEnter={(e) => onHoverCell?.(e, cell?.tasks_preview || [])}
                        onMouseLeave={() => onLeaveCell?.()}
                        style={{
                          height: 24,
                          borderRight: '1px solid #f2f2f7',
                          borderBottom: '1px solid #f7f7fa',
                          background: intensity > 0 ? intensityToColor(intensity) : '#fff',
                          cursor: (cell?.tasks_preview || []).length > 0 ? 'pointer' : 'default',
                        }}
                        title={cell?.load ? `Задач: ${cell.load}` : ''}
                      />
                    )
                  })}
                </div>
              ))}
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}

