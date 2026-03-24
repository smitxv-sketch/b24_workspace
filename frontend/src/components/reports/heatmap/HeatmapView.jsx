import React from 'react'

export function HeatmapView({ heatmap, scale, onChangeScale }) {
  const timeline = heatmap?.timeline || []
  const departments = heatmap?.departments || []
  const summary = heatmap?.summary || { overloaded: [], normal: [], free: [] }
  const todayIndex = Number(heatmap?.today_index ?? -1)
  const [tooltip, setTooltip] = React.useState({ open: false, x: 0, y: 0, items: [] })
  const [mode, setMode] = React.useState('employees') // employees|departments|projects

  const rows = React.useMemo(() => {
    if (mode === 'departments') return buildDepartmentRows(departments, timeline)
    if (mode === 'projects') return buildProjectRows(departments, timeline)
    return buildEmployeeRows(departments)
  }, [mode, departments, timeline])

  return (
    <div style={{ display: 'grid', gap: 10 }}>
      <div style={summaryWrapStyle}>
        <SummaryCol title="Перегружены" color="#d70015" items={summary.overloaded || []} />
        <SummaryCol title="В норме" color="#ff9500" items={summary.normal || []} />
        <SummaryCol title="Есть ресурс" color="#34c759" items={summary.free || []} />
      </div>

      <div style={{ background: '#fff', border: '1px solid #e9e9ed', borderRadius: 12, overflow: 'hidden' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '10px 12px', borderBottom: '1px solid #efeff4' }}>
          <div style={{ fontSize: 13, fontWeight: 700, color: '#1d1d1f' }}>Тепловая карта загрузки</div>
          <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
            <button onClick={() => setMode('employees')} style={scaleBtn(mode === 'employees')}>Сотрудники</button>
            <button onClick={() => setMode('departments')} style={scaleBtn(mode === 'departments')}>Отделы</button>
            <button onClick={() => setMode('projects')} style={scaleBtn(mode === 'projects')}>Проекты</button>
            <div style={{ width: 8 }} />
            <button onClick={() => onChangeScale('day')} style={scaleBtn(scale === 'day')}>Дни</button>
            <button onClick={() => onChangeScale('week')} style={scaleBtn(scale === 'week')}>Недели</button>
            <button onClick={() => onChangeScale('month')} style={scaleBtn(scale === 'month')}>Месяцы</button>
          </div>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: '320px 1fr', minHeight: 360 }}>
          <div style={{ borderRight: '1px solid #e5e5ea', background: '#fff', padding: '8px 10px' }}>
            {(rows || []).map((r) => (
              <div key={r.key} style={{ fontSize: 12, color: '#3a3a3c', lineHeight: '24px', paddingLeft: 8 }}>
                {r.label}
              </div>
            ))}
          </div>

          <div style={{ position: 'relative', overflowX: 'auto' }}>
            <div style={{ minWidth: Math.max(900, timeline.length * 34), position: 'relative' }}>
              <div style={{ display: 'grid', gridTemplateColumns: `repeat(${timeline.length}, 34px)`, borderBottom: '1px solid #e5e5ea' }}>
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
                  left: todayIndex >= 0 ? `${todayIndex * 34 + 17}px` : '0px',
                  width: 2,
                  background: '#0071e3',
                  opacity: 0.45,
                  pointerEvents: 'none',
                }}
              />

              <div style={{ display: 'grid', gap: 8, padding: '8px 0' }}>
                {(rows || []).map((r) => (
                  <div key={r.key}>
                    <div style={{ display: 'grid', gridTemplateColumns: `repeat(${timeline.length}, 34px)` }}>
                      {timeline.map((bucket, idx) => {
                          const cell = (r.cells || []).find(c => Number(c.index) === idx)
                          const intensity = Number(cell?.intensity || 0)
                          const color = heatColor(intensity)
                          return (
                            <div
                              key={idx}
                              onMouseEnter={(e) => {
                                const items = cell?.tasks_preview || []
                                if (items.length === 0) return
                                setTooltip({ open: true, x: e.clientX, y: e.clientY, items })
                              }}
                              onMouseLeave={() => setTooltip(s => ({ ...s, open: false }))}
                              style={{
                                height: 24,
                                borderRight: '1px solid #f2f2f7',
                                borderBottom: '1px solid #f7f7fa',
                                background: color,
                                cursor: (cell?.tasks_preview || []).length > 0 ? 'pointer' : 'default',
                              }}
                              title={cell?.hours ? `${cell.hours} ч` : ''}
                            />
                          )
                      })}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
      </div>

      <HeatmapTooltip state={tooltip} />
    </div>
  )
}

function buildEmployeeRows(departments) {
  const rows = []
  ;(departments || []).forEach((d) => {
    ;(d.users || []).forEach((u) => {
      rows.push({ key: `u-${u.user_id}`, label: u.user_name, cells: u.cells || [] })
    })
  })
  return rows
}

function buildDepartmentRows(departments, timeline) {
  const rows = []
  ;(departments || []).forEach((d) => {
    const cells = (timeline || []).map((t) => {
      let hours = 0
      const tasks = []
      ;(d.users || []).forEach((u) => {
        const c = (u.cells || []).find(x => Number(x.index) === Number(t.index))
        if (!c) return
        hours += Number(c.hours || 0)
        ;(c.tasks_preview || []).forEach((task) => tasks.push(task))
      })
      return { index: t.index, hours: round2(hours), intensity: intensityByHours(hours), tasks_preview: tasks }
    })
    rows.push({ key: `d-${d.id}`, label: d.name, cells })
  })
  return rows
}

function buildProjectRows(departments, timeline) {
  const map = new Map()
  ;(departments || []).forEach((d) => {
    ;(d.users || []).forEach((u) => {
      ;(u.cells || []).forEach((c) => {
        ;(c.tasks_preview || []).forEach((t) => {
          const name = t.project_name || 'Без проекта'
          if (!map.has(name)) {
            map.set(name, { key: `p-${name}`, label: name, cellsMap: new Map() })
          }
          const row = map.get(name)
          const idx = Number(c.index)
          const prev = row.cellsMap.get(idx) || { hours: 0, tasks_preview: [] }
          prev.hours += Number(t.hours || 0)
          prev.tasks_preview.push(t)
          row.cellsMap.set(idx, prev)
        })
      })
    })
  })
  const rows = []
  map.forEach((row) => {
    const cells = (timeline || []).map((t) => {
      const c = row.cellsMap.get(Number(t.index)) || { hours: 0, tasks_preview: [] }
      return { index: t.index, hours: round2(c.hours), intensity: intensityByHours(c.hours), tasks_preview: c.tasks_preview }
    })
    rows.push({ key: row.key, label: row.label, cells })
  })
  rows.sort((a, b) => a.label.localeCompare(b.label, 'ru'))
  return rows
}

function intensityByHours(hours) {
  const h = Number(hours || 0)
  if (h <= 0) return 0
  return Math.min(1, h / 12)
}

function round2(v) {
  return Math.round(Number(v || 0) * 100) / 100
}

function SummaryCol({ title, color, items }) {
  return (
    <div style={{ background: '#fff', border: '1px solid #e9e9ed', borderRadius: 12, padding: 10 }}>
      <div style={{ fontSize: 12, fontWeight: 700, color, marginBottom: 6 }}>{title}</div>
      <div style={{ display: 'grid', gap: 4 }}>
        {(items || []).slice(0, 6).map((u) => (
          <div key={u.user_id} style={{ fontSize: 12, color: '#1d1d1f' }}>
            {u.user_name} — {u.load_now_pct}% · {u.active_projects} пр.
          </div>
        ))}
        {(!items || items.length === 0) && <div style={{ fontSize: 12, color: '#8e8e93' }}>—</div>}
      </div>
    </div>
  )
}

function HeatmapTooltip({ state }) {
  if (!state.open || !state.items || state.items.length === 0) return null
  return (
    <div style={{ position: 'fixed', left: state.x + 10, top: state.y + 10, zIndex: 80, width: 360, maxHeight: 320, overflowY: 'auto', background: '#fff', border: '1px solid #d2d2d7', borderRadius: 10, boxShadow: '0 8px 24px rgba(0,0,0,.15)', padding: 10 }}>
      <div style={{ fontSize: 11, fontWeight: 700, color: '#6e6e73', marginBottom: 6 }}>Задачи за период: {state.items.length}</div>
      <div style={{ display: 'grid', gap: 6 }}>
        {state.items.map((t) => (
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

function heatColor(intensity) {
  const v = Math.max(0, Math.min(1, Number(intensity || 0)))
  const start = { r: 224, g: 240, b: 255 }
  const end = { r: 0, g: 113, b: 227 }
  const r = Math.round(start.r + (end.r - start.r) * v)
  const g = Math.round(start.g + (end.g - start.g) * v)
  const b = Math.round(start.b + (end.b - start.b) * v)
  return `rgb(${r},${g},${b})`
}

const summaryWrapStyle = {
  display: 'grid',
  gridTemplateColumns: 'repeat(3, minmax(0, 1fr))',
  gap: 10,
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

