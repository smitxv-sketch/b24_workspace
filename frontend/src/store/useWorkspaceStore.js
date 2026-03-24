import { create } from 'zustand'
import { api } from '../api/client.js'

export const useWorkspaceStore = create((set, get) => ({
  // ── Bootstrap ─────────────────────────────────────────────────────────────
  user:           null,
  nav:            [],
  canAnalytics:   false,
  isBootstrapped: false,
  bootstrapError: null,

  // ── Текущий процесс ───────────────────────────────────────────────────────
  activeProcessKey:  null,
  workspaceConfig:   null,  // { sections, filters, folders }
  activeScreen:      'deals', // 'deals' | 'analytics'

  // ── Список заявок ─────────────────────────────────────────────────────────
  items:         [],
  sections:      {},
  activeFilter:  'all',
  isLoadingItems: false,
  itemsError:    null,

  // ── Sheet ─────────────────────────────────────────────────────────────────
  isSheetOpen:      false,
  openEntityId:     null,
  openProcessKey:   null,
  dealDetail:       null,
  isLoadingDetail:  false,
  detailError:      null,

  // ── Analytics ─────────────────────────────────────────────────────────────
  analyticsData:       null,
  analyticsPeriod:     'month',
  isLoadingAnalytics:  false,

  // ── Actions ───────────────────────────────────────────────────────────────

  bootstrap: async () => {
    try {
      const data = await api.bootstrap()
      const firstActive = data.nav.find(n => !n.disabled)
      set({
        user:           data.user,
        nav:            data.nav,
        canAnalytics:   data.can_analytics,
        isBootstrapped: true,
        activeProcessKey: firstActive?.key ?? null,
        bootstrapError: null,
      })
      if (firstActive) get().loadItems(firstActive.key)
    } catch (e) {
      set({ bootstrapError: e.message, isBootstrapped: true })
    }
  },

  setActiveProcess: (key) => {
    set({ activeProcessKey: key, activeScreen: 'deals', activeFilter: 'all', items: [], sections: {} })
    get().loadItems(key)
  },

  setActiveScreen: (screen) => {
    set({ activeScreen: screen })
    if (screen === 'analytics') {
      const key    = get().activeProcessKey
      const period = get().analyticsPeriod
      if (key) get().loadAnalytics(key, period)
    }
  },

  loadItems: async (processKey, filter) => {
    const f = filter ?? get().activeFilter
    set({ isLoadingItems: true, itemsError: null })
    try {
      const data = await api.processItems(processKey, f)
      set({
        items:          data.items,
        sections:       data.sections,
        workspaceConfig: data.workspace_config,
        isLoadingItems: false,
      })
    } catch (e) {
      set({ itemsError: e.message, isLoadingItems: false })
    }
  },

  setFilter: (filter) => {
    set({ activeFilter: filter })
    const key = get().activeProcessKey
    if (key) get().loadItems(key, filter)
  },

  openSheet: async (entityId, processKey) => {
    set({ isSheetOpen: true, openEntityId: entityId, openProcessKey: processKey, dealDetail: null, isLoadingDetail: true, detailError: null })
    // Блокируем скролл body
    document.body.style.overflow = 'hidden'
    try {
      const data = await api.dealDetail(entityId, processKey)
      set({ dealDetail: data, isLoadingDetail: false })
    } catch (e) {
      set({ detailError: e.message, isLoadingDetail: false })
    }
  },

  closeSheet: () => {
    set({ isSheetOpen: false, openEntityId: null, dealDetail: null })
    document.body.style.overflow = ''
  },

  loadAnalytics: async (processKey, period) => {
    set({ isLoadingAnalytics: true })
    try {
      const data = await api.analytics(processKey, period)
      set({ analyticsData: data, isLoadingAnalytics: false })
    } catch (e) {
      set({ isLoadingAnalytics: false })
    }
  },

  setAnalyticsPeriod: (period) => {
    set({ analyticsPeriod: period })
    const key = get().activeProcessKey
    if (key) get().loadAnalytics(key, period)
  },

  // Polling — обновить список (вызывать каждые 30 сек)
  refreshItems: () => {
    const { activeProcessKey, activeFilter, isSheetOpen } = get()
    if (!activeProcessKey || isSheetOpen) return
    get().loadItems(activeProcessKey, activeFilter)
  },
}))
