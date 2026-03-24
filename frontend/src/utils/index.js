// utils/colors.js
export const ACCENT = {
  red:       { stripe: '#ff3b30', tagBg: 'rgba(255,59,48,.1)',   tagText: '#ff3b30' },
  blue:      { stripe: '#0071e3', tagBg: 'rgba(0,113,227,.1)',   tagText: '#0071e3' },
  amber:     { stripe: '#ff9500', tagBg: 'rgba(255,149,0,.1)',   tagText: '#ff9500' },
  green:     { stripe: '#34c759', tagBg: 'rgba(52,199,89,.1)',   tagText: '#34c759' },
  gray:      { stripe: '#c7c7cc', tagBg: 'rgba(0,0,0,.06)',     tagText: '#86868b' },
}

export const ALERT = {
  red:   { bg: 'rgba(255,59,48,.07)',  border: 'rgba(255,59,48,.2)',  label: '#ff3b30' },
  blue:  { bg: 'rgba(0,113,227,.07)', border: 'rgba(0,113,227,.18)', label: '#0071e3' },
  amber: { bg: 'rgba(255,149,0,.07)', border: 'rgba(255,149,0,.2)',  label: '#ff9500' },
  green: { bg: 'rgba(52,199,89,.07)', border: 'rgba(52,199,89,.2)',  label: '#34c759' },
}

export const AVATAR = {
  green:  { bg: 'rgba(52,199,89,.15)',  text: '#34c759' },
  blue:   { bg: 'rgba(0,113,227,.12)', text: '#0071e3' },
  amber:  { bg: 'rgba(255,149,0,.15)', text: '#ff9500' },
  red:    { bg: 'rgba(255,59,48,.12)', text: '#ff3b30' },
  gray:   { bg: 'rgba(0,0,0,.06)',    text: '#86868b' },
  system: { bg: 'rgba(0,0,0,.05)',    text: '#aeaeb2' },
}

// utils/formatters.js
export function formatHours(hours, isRunning = false) {
  if (hours === null || hours === undefined) return '—'
  const h = Math.round(parseFloat(hours) * 10) / 10
  return h + ' ч' + (isRunning ? '…' : '')
}

export function formatAmount(rubles) {
  const n = parseInt(rubles, 10)
  if (n >= 1_000_000) return '₽ ' + Math.round(n / 1_000_000 * 10) / 10 + ' млн'
  if (n >= 1_000)     return '₽ ' + Math.round(n / 1_000) + ' тыс'
  return '₽ ' + n.toLocaleString('ru')
}

export function formatDate(isoString) {
  if (!isoString) return ''
  const d = new Date(isoString)
  return d.toLocaleDateString('ru', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

export function formatRelativeTime(isoString) {
  if (!isoString) return ''
  const diff = (Date.now() - new Date(isoString).getTime()) / 1000
  if (diff < 60)    return 'только что'
  if (diff < 3600)  return Math.round(diff / 60) + ' мин назад'
  if (diff < 86400) return Math.round(diff / 3600) + ' ч назад'
  return Math.round(diff / 86400) + ' дн. назад'
}
