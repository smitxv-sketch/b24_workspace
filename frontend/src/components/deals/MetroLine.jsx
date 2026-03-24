import React from 'react'

export function MetroLine({ steps }) {
  if (!Array.isArray(steps) || steps.length === 0) return null

  return (
    <div
      style={{
        display: 'flex',
        alignItems: 'center',
        gap: 0,
        margin: '8px 0',
        overflowX: 'auto',
        paddingBottom: 2,
        scrollbarWidth: 'none',
        msOverflowStyle: 'none',
      }}
    >
      {steps.map((step, i) => {
        const isLast = i === steps.length - 1
        const isDone = step.state === 'done'
        const isCurrent = step.state === 'current'
        const isOver = Boolean(step.is_over)

        const dot = isDone
          ? { bg: '#34c759', border: '#34c759', icon: '✓', color: '#fff', size: 16, glow: 'none' }
          : isCurrent
            ? {
                bg: isOver ? '#ff3b30' : '#0071e3',
                border: isOver ? '#ff3b30' : '#0071e3',
                icon: '●',
                color: '#fff',
                size: 18,
                glow: isOver ? '0 0 0 3px rgba(255,59,48,.2)' : '0 0 0 3px rgba(0,113,227,.15)',
              }
            : { bg: '#fff', border: '#d1d1d6', icon: '', color: 'transparent', size: 14, glow: 'none' }

        return (
          <React.Fragment key={step.key || String(i)}>
            <div
              title={step.label || step.key || ''}
              style={{
                width: dot.size,
                height: dot.size,
                borderRadius: '50%',
                background: dot.bg,
                border: `1.5px solid ${dot.border}`,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                fontSize: dot.size === 16 ? 9 : 7,
                color: dot.color,
                fontWeight: 700,
                flexShrink: 0,
                boxShadow: dot.glow,
                transition: 'all .2s',
              }}
            >
              {dot.icon}
            </div>

            {!isLast && (
              <div
                style={{
                  flex: isDone ? '1 1 auto' : '0 0 8px',
                  minWidth: 6,
                  maxWidth: isDone ? 32 : 12,
                  height: 1.5,
                  background: isDone ? '#34c759' : '#d1d1d6',
                  transition: 'background .2s',
                }}
              />
            )}
          </React.Fragment>
        )
      })}
    </div>
  )
}
