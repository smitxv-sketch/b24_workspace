import React from 'react'
import { PlanningLeftTree } from './PlanningLeftTree.jsx'
import { PlanningTimelineGrid } from './PlanningTimelineGrid.jsx'
import { PlanningTooltip } from './PlanningTooltip.jsx'

export function PlanningView({ planning, scale, onChangeScale }) {
  const [tooltip, setTooltip] = React.useState({ open: false, x: 0, y: 0, items: [] })

  return (
    <div style={{ background: '#fff', border: '1px solid #e9e9ed', borderRadius: 12, overflow: 'hidden' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '10px 12px', borderBottom: '1px solid #efeff4' }}>
        <div style={{ fontSize: 13, fontWeight: 700, color: '#1d1d1f' }}>Планирование Задачи/день</div>
        <div style={{ display: 'flex', gap: 6 }}>
          <button onClick={() => onChangeScale('day')} style={scaleBtn(scale === 'day')}>Дни</button>
          <button onClick={() => onChangeScale('week')} style={scaleBtn(scale === 'week')}>Недели</button>
          <button onClick={() => onChangeScale('month')} style={scaleBtn(scale === 'month')}>Месяцы</button>
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '300px 1fr', minHeight: 420 }}>
        <PlanningLeftTree departments={planning?.departments || []} />
        <PlanningTimelineGrid
          planning={planning}
          onHoverCell={(e, items) => {
            if (!items || items.length === 0) return
            setTooltip({ open: true, x: e.clientX, y: e.clientY, items })
          }}
          onLeaveCell={() => setTooltip((s) => ({ ...s, open: false }))}
        />
      </div>

      <PlanningTooltip open={tooltip.open} x={tooltip.x} y={tooltip.y} items={tooltip.items} />
    </div>
  )
}

const scaleBtn = (active) => ({
  border: '1px solid ' + (active ? '#0071e3' : '#d2d2d7'),
  background: active ? 'rgba(0,113,227,.08)' : '#fff',
  color: active ? '#0071e3' : '#1d1d1f',
  borderRadius: 999,
  padding: '5px 10px',
  fontSize: 12,
  cursor: 'pointer',
  fontFamily: 'inherit',
})

