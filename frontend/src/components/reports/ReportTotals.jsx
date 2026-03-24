import React from 'react'

export function ReportTotals({ totals }) {
  const totalHours = Number(totals?.total_hours || 0).toFixed(2)
  const totalSalary = Number(totals?.total_salary || 0).toFixed(2)
  const usersCount = Number(totals?.users_count || 0)
  const avgHours = Number(totals?.avg_hours_per_user || 0).toFixed(2)

  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, minmax(0, 1fr))', gap: 10 }}>
      <div style={cardStyle}>
        <div style={labelStyle}>Всего часов</div>
        <div style={valueStyle}>{totalHours} ч</div>
      </div>
      <div style={cardStyle}>
        <div style={labelStyle}>ФОТ (оценка)</div>
        <div style={valueStyle}>{totalSalary} ₽</div>
      </div>
      <div style={cardStyle}>
        <div style={labelStyle}>Сотрудников</div>
        <div style={valueStyle}>{usersCount}</div>
      </div>
      <div style={cardStyle}>
        <div style={labelStyle}>Среднее на сотрудника</div>
        <div style={valueStyle}>{avgHours} ч</div>
      </div>
    </div>
  )
}

const cardStyle = {
  background: '#fff',
  border: '1px solid #e9e9ed',
  borderRadius: 12,
  padding: '12px 14px',
}

const labelStyle = { fontSize: 12, color: '#6e6e73' }
const valueStyle = { marginTop: 6, fontSize: 22, fontWeight: 700, color: '#1d1d1f' }

