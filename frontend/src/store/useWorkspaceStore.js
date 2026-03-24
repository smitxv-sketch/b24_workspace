import { create } from 'zustand'
import { api } from '../api/client.js'

const REPORTS_PREFS_KEY = 'ws_reports_prefs_v1'

function readReportsPrefs() {
  try {
    const raw = localStorage.getItem(REPORTS_PREFS_KEY)
    if (!raw) return {}
    return JSON.parse(raw) || {}
  } catch {
    return {}
  }
}

function writeReportsPrefs(partial) {
  const prev = readReportsPrefs()
  const next = { ...prev, ...partial }
  localStorage.setItem(REPORTS_PREFS_KEY, JSON.stringify(next))
}

const reportsPrefs = readReportsPrefs()

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
  activeScreen:      'deals', // 'deals' | 'analytics' | 'reports'

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

  // ── Reports ───────────────────────────────────────────────────────────────
  reportsData:        null,
  reportsDeptId:      reportsPrefs.reportsDeptId ?? null,
  reportsDateFrom:    reportsPrefs.reportsDateFrom ?? '',
  reportsDateTo:      reportsPrefs.reportsDateTo ?? '',
  reportsPeriodPreset: reportsPrefs.reportsPeriodPreset ?? 'month',
  reportsGroupMode:   reportsPrefs.reportsGroupMode ?? 'users_projects',
  reportsIncludeSubdepts: reportsPrefs.reportsIncludeSubdepts ?? true,
  reportsSortBy:      reportsPrefs.reportsSortBy ?? 'hours_desc',
  reportsExpandedDeptIds: reportsPrefs.reportsExpandedDeptIds ?? [],
  reportsEmployeeId: reportsPrefs.reportsEmployeeId ?? '',
  reportsUserModal: null,
  reportsProjectModal: null,
  isLoadingReports:   false,
  reportsError:       null,

  // ── Actions ───────────────────────────────────────────────────────────────

  bootstrap: async () => {
    try {
      const data = await api.bootstrap()
      const firstActive = data.nav.find(n => !n.disabled && n.key !== 'reports')
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
    if (screen === 'reports') {
      get().loadReports()
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

  loadReports: async (overrides = {}) => {
    const prev = get()
    const params = {
      report: 'time_tracking',
      dept_id: overrides.dept_id ?? prev.reportsDeptId ?? undefined,
      date_from: overrides.date_from ?? (prev.reportsDateFrom || undefined),
      date_to: overrides.date_to ?? (prev.reportsDateTo || undefined),
      period_preset: overrides.period_preset ?? prev.reportsPeriodPreset ?? 'month',
      group_mode: overrides.group_mode ?? prev.reportsGroupMode ?? 'users_projects',
      include_subdepts: (overrides.include_subdepts ?? prev.reportsIncludeSubdepts) ? 'Y' : 'N',
      sort_by: overrides.sort_by ?? prev.reportsSortBy ?? 'hours_desc',
      expand_dept: overrides.expand_dept ?? undefined,
    }
    set({ isLoadingReports: true, reportsError: null })
    try {
      const data = await api.reports(params)
      set({
        reportsData: data,
        reportsDeptId: data.selected_dept_id ?? params.dept_id ?? null,
        reportsDateFrom: data.period?.from || prev.reportsDateFrom,
        reportsDateTo: data.period?.to || prev.reportsDateTo,
        reportsPeriodPreset: data.period?.preset || params.period_preset || prev.reportsPeriodPreset,
        reportsGroupMode: params.group_mode,
        reportsIncludeSubdepts: params.include_subdepts === 'Y',
        reportsSortBy: params.sort_by,
        isLoadingReports: false,
      })
      writeReportsPrefs({
        reportsDeptId: data.selected_dept_id ?? params.dept_id ?? null,
        reportsDateFrom: data.period?.from || prev.reportsDateFrom,
        reportsDateTo: data.period?.to || prev.reportsDateTo,
        reportsPeriodPreset: data.period?.preset || params.period_preset || prev.reportsPeriodPreset,
        reportsGroupMode: params.group_mode,
        reportsIncludeSubdepts: params.include_subdepts === 'Y',
        reportsSortBy: params.sort_by,
      })
    } catch (e) {
      set({ reportsError: e.message, isLoadingReports: false })
    }
  },

  setReportsDept: (deptId) => {
    set({ reportsDeptId: deptId })
    writeReportsPrefs({ reportsDeptId: deptId })
    get().loadReports({ dept_id: deptId, expand_dept: deptId })
  },

  setReportsPeriod: (dateFrom, dateTo, periodPreset = 'custom') => {
    set({ reportsDateFrom: dateFrom, reportsDateTo: dateTo })
    writeReportsPrefs({ reportsDateFrom: dateFrom, reportsDateTo: dateTo, reportsPeriodPreset: periodPreset })
    get().loadReports({ date_from: dateFrom, date_to: dateTo, period_preset: periodPreset })
  },

  setReportsPreset: (periodPreset) => {
    set({ reportsPeriodPreset: periodPreset })
    writeReportsPrefs({ reportsPeriodPreset: periodPreset })
    get().loadReports({ period_preset: periodPreset })
  },

  setReportsGroupMode: (groupMode) => {
    set({ reportsGroupMode: groupMode })
    writeReportsPrefs({ reportsGroupMode: groupMode })
    get().loadReports({ group_mode: groupMode })
  },

  setReportsIncludeSubdepts: (enabled) => {
    set({ reportsIncludeSubdepts: enabled })
    writeReportsPrefs({ reportsIncludeSubdepts: enabled })
    get().loadReports({ include_subdepts: enabled, expand_dept: get().reportsDeptId ?? undefined })
  },

  setReportsSortBy: (sortBy) => {
    set({ reportsSortBy: sortBy })
    writeReportsPrefs({ reportsSortBy: sortBy })
    get().loadReports({ sort_by: sortBy })
  },

  setReportsEmployee: (employeeId) => {
    set({ reportsEmployeeId: employeeId })
    writeReportsPrefs({ reportsEmployeeId: employeeId })
  },

  resetReportsFilters: () => {
    set({
      reportsEmployeeId: '',
      reportsGroupMode: 'employees',
      reportsSortBy: 'hours_desc',
      reportsPeriodPreset: 'month',
      reportsIncludeSubdepts: true,
      reportsDateFrom: '',
      reportsDateTo: '',
    })
    writeReportsPrefs({
      reportsEmployeeId: '',
      reportsGroupMode: 'employees',
      reportsSortBy: 'hours_desc',
      reportsPeriodPreset: 'month',
      reportsIncludeSubdepts: true,
      reportsDateFrom: '',
      reportsDateTo: '',
    })
    const deptId = get().reportsDeptId
    get().loadReports({
      dept_id: deptId ?? undefined,
      period_preset: 'month',
      group_mode: 'employees',
      include_subdepts: true,
      sort_by: 'hours_desc',
      date_from: undefined,
      date_to: undefined,
      expand_dept: deptId ?? undefined,
    })
  },

  toggleReportsDeptExpanded: (deptId) => {
    const prev = get().reportsExpandedDeptIds || []
    const has = prev.includes(deptId)
    const next = has ? prev.filter(id => id !== deptId) : [...prev, deptId]
    set({ reportsExpandedDeptIds: next })
    writeReportsPrefs({ reportsExpandedDeptIds: next })
  },

  exportReportsCsv: () => {
    const s = get()
    const url = api.reportsCsvUrl({
      report: 'time_tracking',
      format: 'csv',
      dept_id: s.reportsDeptId ?? undefined,
      date_from: s.reportsDateFrom || undefined,
      date_to: s.reportsDateTo || undefined,
      period_preset: s.reportsPeriodPreset || 'month',
      group_mode: s.reportsGroupMode || 'users_projects',
      include_subdepts: s.reportsIncludeSubdepts ? 'Y' : 'N',
      sort_by: s.reportsSortBy || 'hours_desc',
    })
    const a = document.createElement('a')
    a.href = url
    a.rel = 'noopener'
    document.body.appendChild(a)
    a.click()
    a.remove()
  },

  openReportsUserModal: (user) => set({ reportsUserModal: user || null }),
  closeReportsUserModal: () => set({ reportsUserModal: null }),
  openReportsProjectModal: (project) => set({ reportsProjectModal: project || null }),
  closeReportsProjectModal: () => set({ reportsProjectModal: null }),

  // Polling — обновить список (вызывать каждые 30 сек)
  refreshItems: () => {
    const { activeProcessKey, activeFilter, isSheetOpen, activeScreen } = get()
    if (activeScreen !== 'deals' || !activeProcessKey || isSheetOpen) return
    get().loadItems(activeProcessKey, activeFilter)
  },
}))
