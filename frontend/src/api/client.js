/**
 * api/client.js — базовый fetch-клиент для workspace API
 */

const BASE = '/local/ws/api/workspace'

async function apiFetch(endpoint, params = {}) {
  const url = new URL(BASE + endpoint, window.location.origin)

  // Debug-режим через localStorage
  if (localStorage.getItem('ws_debug') === '1') {
    params.debug = 'Y'
  }

  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && v !== null) url.searchParams.set(k, v)
  })

  const res = await fetch(url.toString(), {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    credentials: 'same-origin',
  })

  if (res.status === 401) throw new Error('unauthorized')

  const data = await res.json()

  if (data.status === 'error') throw new Error(data.message || 'api_error')

  return data.data
}

export const api = {
  bootstrap:    ()                         => apiFetch('/bootstrap.php'),
  processItems: (processKey, filter = 'all') => apiFetch('/process_items.php', { process_key: processKey, filter }),
  dealDetail:   (entityId, processKey)     => apiFetch('/deal_detail.php', { entity_id: entityId, process_key: processKey }),
  analytics:    (processKey, period = 'month') => apiFetch('/analytics.php', { process_key: processKey, period }),
  reports:      (params = {})              => apiFetch('/reports.php', params),
  reportsCsvUrl: (params = {}) => {
    const url = new URL(BASE + '/reports.php', window.location.origin)
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v)
    })
    return url.toString()
  },
}
