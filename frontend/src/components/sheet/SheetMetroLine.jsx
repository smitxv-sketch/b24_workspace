import React, { useMemo, useState } from 'react'

export function SheetMetroLine({ steps }) {
  const safeSteps = Array.isArray(steps) ? steps : []

  const initialActive = useMemo(() => {
    const idx = safeSteps.findIndex(s => s.state === 'current' || s.state === 'late')
    return idx >= 0 ? idx : 0
  }, [safeSteps])

  const [activeStep, setActiveStep] = useState(initialActive)

  if (safeSteps.length === 0) return null

  const active = safeSteps[Math.min(activeStep, safeSteps.length - 1)]

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'flex-start', overflowX: 'auto', padding: '4px 0 16px' }}>
        {safeSteps.map((step, i) => {
          const isLast = i === safeSteps.length - 1
          const isActive = i === activeStep
          const dotColor = step.state === 'done'
            ? '#34c759'
            : step.state === 'late'
              ? '#ff3b30'
              : step.state === 'current'
                ? '#0071e3'
                : '#d1d1d6'

          return (
            <React.Fragment key={step.step_key || String(i)}>
              <div
                style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', cursor: 'pointer', minWidth: 52 }}
                onClick={() => setActiveStep(i)}
              >
                <div
                  style={{
                    width: isActive ? 20 : 14,
                    height: isActive ? 20 : 14,
                    borderRadius: '50%',
                    background: dotColor,
                    border: isActive ? `2px solid ${dotColor}` : `1.5px solid ${dotColor}`,
                    boxShadow: isActive ? `0 0 0 3px ${dotColor}30` : 'none',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontSize: 8,
                    color: '#fff',
                    fontWeight: 700,
                    transition: 'all .2s',
                    flexShrink: 0,
                  }}
                >
                  {step.state === 'done' ? '✓' : ''}
                </div>
                <div
                  title={step.label}
                  style={{
                    fontSize: 9,
                    marginTop: 4,
                    textAlign: 'center',
                    color: isActive ? dotColor : '#aeaeb2',
                    fontWeight: isActive ? 600 : 400,
                    maxWidth: 52,
                    lineHeight: 1.3,
                    whiteSpace: 'nowrap',
                    overflow: 'hidden',
                    textOverflow: 'ellipsis',
                  }}
                >
                  {shortLabel(step.label)}
                </div>
              </div>

              {!isLast && (
                <div
                  style={{
                    flex: 1,
                    height: 1.5,
                    minWidth: 8,
                    margin: '6px 4px 0',
                    background: step.state === 'done' ? '#34c759' : '#e5e5ea',
                  }}
                />
              )}
            </React.Fragment>
          )
        })}
      </div>

      {active && (
        <div
          style={{
            background: '#fff',
            borderRadius: 12,
            padding: '10px 14px',
            border: '.5px solid rgba(0,0,0,.07)',
            marginTop: 4,
          }}
        >
          <div style={{ fontSize: 12, fontWeight: 600, color: '#1d1d1f', marginBottom: 4 }}>
            {active.label}
          </div>
          {active.timing && (
            <div style={{ fontSize: 11, color: '#86868b' }}>
              {active.timing}
              {active.is_overdue && (
                <span style={{ marginLeft: 6, color: '#ff3b30', fontSize: 10 }}>
                  ▲ {active.overdue_label}
                </span>
              )}
            </div>
          )}
          {active.voters?.length > 0 && (
            <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap', marginTop: 6 }}>
              {active.voters.map((v, vi) => (
                <span
                  key={vi}
                  style={{
                    fontSize: 10,
                    padding: '2px 7px',
                    borderRadius: 8,
                    background: v.verdict === 'approve' ? 'rgba(52,199,89,.1)' : 'rgba(255,149,0,.1)',
                    color: v.verdict === 'approve' ? '#34c759' : '#ff9500',
                  }}
                >
                  {v.name} — {v.label}
                </span>
              ))}
            </div>
          )}
          {active.state === 'pending' && (
            <div style={{ fontSize: 11, color: '#c7c7cc', fontStyle: 'italic', marginTop: 4 }}>
              Ожидает очереди
            </div>
          )}
        </div>
      )}
    </div>
  )
}

function shortLabel(value) {
  const label = String(value || '').trim()
  if (!label) return '—'
  return label.split(' ')[0]
}
