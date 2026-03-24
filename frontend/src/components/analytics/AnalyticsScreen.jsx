import React from 'react'
import { useWorkspaceStore } from '../../store/useWorkspaceStore.js'
import { formatAmount } from '../../utils/index.js'

const COLORS = {
  green:     '#34c759', green_dim: '#34c75980',
  blue:      '#0071e3', blue_dim:  '#0071e380',
  amber:     '#ff9500', amber_dim: '#ff950080',
  red:       '#ff3b30', red_dim:   '#ff3b3080',
}

export function AnalyticsScreen() {
  const { analyticsData, analyticsPeriod, isLoadingAnalytics, setAnalyticsPeriod, activeProcessKey } = useWorkspaceStore()

  const s = {
    wrap:   { flex: 1, overflowY: 'auto', padding: '16px 20px 60px' },
    title:  { fontSize: 20, fontWeight: 700, color: '#1d1d1f', letterSpacing: '-.04em', marginBottom: 3 },
    sub:    { fontSize: 11, color: '#86868b', letterSpacing: '-.01em', marginBottom: 16 },
    card:   { background: '#fff', borderRadius: 16, padding: '16px 18px', marginBottom: 12 },
    secLbl: { fontSize: 10, fontWeight: 700, color: '#aeaeb2', textTransform: 'uppercase', letterSpacing: '.07em', marginBottom: 14 },
    sw:     { display: 'inline-flex', background: 'rgba(0,0,0,.07)', borderRadius: 10, padding: 2, marginBottom: 18 },
    swBtn:  (on) => ({ fontSize: 11, fontWeight: 500, padding: '5px 14px', borderRadius: 8, cursor: 'pointer', color: on ? '#1d1d1f' : '#6e6e73', border: 'none', fontFamily: 'inherit', background: on ? '#fff' : 'transparent', boxShadow: on ? '0 1px 4px rgba(0,0,0,.1)' : 'none', letterSpacing: '-.01em' }),
  }

  if (isLoadingAnalytics || !analyticsData) {
    return (
      <div style={s.wrap}>
        <div style={s.title}>Аналитика</div>
        <div style={{ ...s.card, marginTop: 16 }} className="skeleton" style={{ height: 200 }} />
      </div>
    )
  }

  const d = analyticsData
  const maxCount = Math.max(...(d.funnel || []).map(f => f.count), 1)

  return (
    <div style={s.wrap} className="fade-in">
      <div style={s.title}>Аналитика</div>
      <div style={s.sub}>Заявки ТКП — воронка и дисциплина</div>

      <div style={s.sw}>
        <button style={s.swBtn(analyticsPeriod === 'month')} onClick={() => setAnalyticsPeriod('month')}>
          {d.period_label}
        </button>
        <button style={s.swBtn(analyticsPeriod === 'year')} onClick={() => setAnalyticsPeriod('year')}>
          С начала года
        </button>
      </div>

      {/* Воронка */}
      <div style={s.card}>
        <div style={s.secLbl}>Воронка по статусам</div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 9 }}>
          {(d.funnel || []).map(row => {
            const color  = COLORS[row.color] || COLORS.blue
            const width  = Math.max(Math.round(row.count / maxCount * 100), row.count > 0 ? 4 : 0)
            return (
              <div key={row.key} style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <div style={{ fontSize: 11, color: '#3a3a3c', width: 168, flexShrink: 0, letterSpacing: '-.01em' }}>{row.label}</div>
                <div style={{ flex: 1, display: 'flex', alignItems: 'center', gap: 8 }}>
                  <div style={{
                    height: 22, borderRadius: 6, minWidth: 4,
                    background: color, width: width + '%',
                    display: 'flex', alignItems: 'center', padding: '0 9px',
                    transition: 'width .5s ease',
                    opacity: row.color.endsWith('_dim') ? .6 : 1,
                  }}>
                    {row.count > 0 && (
                      <span style={{ fontSize: 10, fontWeight: 700, color: '#fff', whiteSpace: 'nowrap' }}>{row.count}</span>
                    )}
                  </div>
                  <div style={{ fontSize: 10, color: '#86868b', whiteSpace: 'nowrap' }}>
                    {row.amount > 0 ? formatAmount(row.amount) : row.count === 0 ? '—' : ''}
                  </div>
                </div>
              </div>
            )
          })}
        </div>
      </div>

      {/* Среднее время */}
      {d.step_averages?.length > 0 && (
        <div style={s.card}>
          <div style={s.secLbl}>Среднее время по шагам (план vs факт)</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            {d.step_averages.map(row => {
              if (row.avg_hours === null) return null
              const ratio      = row.avg_hours / row.plan_hours
              const isOver     = row.avg_hours > row.plan_hours
              const factColor  = isOver ? '#ff3b30' : '#34c759'
              const planWidth  = 100
              const factWidth  = Math.round(ratio * 100)
              return (
                <div key={row.step_key} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <div style={{ fontSize: 10, color: '#6e6e73', width: 140, flexShrink: 0, textAlign: 'right', letterSpacing: '-.01em' }}>
                    {row.label} ({row.plan_hours} ч)
                  </div>
                  <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 3 }}>
                    <div style={{ height: 7, borderRadius: 3, background: '#e5e5ea', width: planWidth + '%' }} />
                    <div style={{ height: 7, borderRadius: 3, background: factColor, width: Math.min(factWidth, 120) + '%', transition: 'width .5s ease' }} />
                  </div>
                  <div style={{ fontSize: 9, width: 46, color: factColor, flexShrink: 0, letterSpacing: '-.01em' }}>
                    {row.avg_hours} ч{isOver ? ' ▲' : ''}
                  </div>
                </div>
              )
            })}
          </div>
        </div>
      )}

      {/* Детализация */}
      {d.deal_breakdown?.length > 0 && (
        <div style={s.card}>
          <div style={s.secLbl}>Детализация по заявкам</div>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 11 }}>
            <thead>
              <tr>
                <th style={{ padding: '6px 8px', textAlign: 'left', fontSize: 9, fontWeight: 700, color: '#86868b', textTransform: 'uppercase', letterSpacing: '.06em', borderBottom: '.5px solid rgba(0,0,0,.07)' }}>Заявка</th>
                {d.step_averages?.filter(s => s.avg_hours !== null).slice(0, 4).map(s => (
                  <th key={s.step_key} style={{ padding: '6px 8px', textAlign: 'right', fontSize: 9, fontWeight: 700, color: '#86868b', textTransform: 'uppercase', letterSpacing: '.06em', borderBottom: '.5px solid rgba(0,0,0,.07)' }}>
                    {s.label.split(' ')[0]}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {d.deal_breakdown.map(deal => (
                <tr key={deal.entity_id} style={{ ':hover': { background: 'rgba(0,0,0,.02)' } }}>
                  <td style={{ padding: '7px 8px', borderBottom: '.5px solid rgba(0,0,0,.05)', color: '#1d1d1f', letterSpacing: '-.01em' }}>{deal.title}</td>
                  {d.step_averages?.filter(s => s.avg_hours !== null).slice(0, 4).map(sa => {
                    const cell = deal.steps?.[sa.step_key]
                    const color = !cell || cell.state === 'pending' ? '#c7c7cc'
                                : cell.state === 'running' ? '#ff9500'
                                : cell.state === 'overdue' ? '#ff3b30'
                                : '#34c759'
                    return (
                      <td key={sa.step_key} style={{ padding: '7px 8px', borderBottom: '.5px solid rgba(0,0,0,.05)', textAlign: 'right', color, fontWeight: cell?.state === 'overdue' ? 600 : 400, letterSpacing: '-.01em' }}>
                        {cell?.hours != null ? cell.hours + ' ч' + (cell.state === 'running' ? '…' : '') : '—'}
                      </td>
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
