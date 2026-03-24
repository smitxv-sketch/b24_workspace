import React from 'react'
import { useWorkspaceStore } from '../../store/useWorkspaceStore.js'
import { ReportTotals } from './ReportTotals.jsx'
import { DetailsModal } from './DetailsModal.jsx'
import { TaskRow } from './TaskRow.jsx'
import { PlanningView } from './planning/PlanningView.jsx'
import { HeatmapView } from './heatmap/HeatmapView.jsx'

export function ReportsScreen() {
  const autoSwitchedHeatmapRef = React.useRef(false)
  const {
    reportsData,
    reportsDeptId,
    reportsDateFrom,
    reportsDateTo,
    reportsGroupMode,
    reportsIncludeSubdepts,
    reportsSortBy,
    reportsEmployeeId,
    reportsProjectId,
    reportsView,
    reportsPlanningScale,
    reportsTaskDates,
    reportsUserModal,
    reportsProjectModal,
    isLoadingReports,
    reportsError,
    setReportsDept,
    setReportsGroupMode,
    setReportsIncludeSubdepts,
    setReportsSortBy,
    setReportsEmployee,
    setReportsProject,
    setReportsView,
    setReportsPlanningScale,
    setReportsTaskDateRange,
    clearReportsTaskDateRange,
    resetReportsFilters,
    exportReportsCsv,
    openReportsUserModal,
    closeReportsUserModal,
    openReportsProjectModal,
    closeReportsProjectModal,
    loadReports,
  } = useWorkspaceStore()

  const period = reportsData?.period || {}
  const heatmapEnabled = React.useMemo(() => {
    try {
      const href = String(window.location.href || '')
      const search = String(window.location.search || '')
      const hash = String(window.location.hash || '')
      const combined = `${href}&${search}&${hash}`.toLowerCase()
      return combined.includes('ws_heatmap=y')
    } catch {
      return false
    }
  }, [])
  const currentWork = reportsData?.current_work || []
  const periodDone = reportsData?.period_done || []
  const [taskScopes, setTaskScopes] = React.useState(['in_work'])
  const [deptInput, setDeptInput] = React.useState('')
  const [employeeInput, setEmployeeInput] = React.useState('')
  const [projectInput, setProjectInput] = React.useState('')

  const deptOptions = React.useMemo(() => flattenDeptTree(reportsData?.dept_tree || []), [reportsData])
  const selectedDeptName = React.useMemo(() => {
    const selectedId = Number(reportsDeptId || reportsData?.selected_dept_id || 0)
    if (!selectedId) return ''
    const found = deptOptions.find(d => Number(d.id) === selectedId)
    return found?.name || ''
  }, [deptOptions, reportsDeptId, reportsData])
  const selectedDeptOption = React.useMemo(() => {
    const id = Number(reportsDeptId || reportsData?.selected_dept_id || 0)
    if (!id) return null
    return deptOptions.find(d => Number(d.id) === id) || null
  }, [deptOptions, reportsDeptId, reportsData])

  const plannedRows = React.useMemo(() => filterRowsByTaskStatus(currentWork, [2]), [currentWork])
  const inWorkRows = React.useMemo(() => filterRowsByTaskStatus(currentWork, [3, 4]), [currentWork])
  const doneRows = React.useMemo(() => filterRowsByTaskStatus(periodDone, [5]), [periodDone])
  const sourceRows = React.useMemo(
    () => mergeRowsByUser({
      planned: taskScopes.includes('planned') ? plannedRows : [],
      in_work: taskScopes.includes('in_work') ? inWorkRows : [],
      done: taskScopes.includes('done') ? doneRows : [],
    }),
    [taskScopes, plannedRows, inWorkRows, doneRows]
  )

  const employeeOptions = React.useMemo(() => {
    const src = sourceRows || []
    return src
      .map(r => ({ id: String(r.user_id), name: r.user_name, dept: r.dept_name }))
      .sort((a, b) => lastName(a.name).localeCompare(lastName(b.name), 'ru'))
  }, [sourceRows])

  const projectOptions = React.useMemo(() => {
    const map = new Map()
    ;(sourceRows || []).forEach((u) => {
      ;(u.tasks || []).forEach((t) => {
        const id = String(t.project_id ?? '')
        if (!id) return
        if (!map.has(id)) {
          map.set(id, {
            id,
            name: t.project_name || `Проект #${id}`,
          })
        }
      })
    })
    return Array.from(map.values()).sort((a, b) => a.name.localeCompare(b.name, 'ru'))
  }, [sourceRows])

  React.useEffect(() => {
    setDeptInput(selectedDeptOption?.breadcrumb || '')
  }, [selectedDeptOption?.id])

  React.useEffect(() => {
    const found = employeeOptions.find(u => String(u.id) === String(reportsEmployeeId))
    setEmployeeInput(found ? `${found.name} · ${found.dept}` : '')
  }, [reportsEmployeeId, employeeOptions])

  React.useEffect(() => {
    const found = projectOptions.find(p => String(p.id) === String(reportsProjectId))
    setProjectInput(found ? found.name : 'Все')
  }, [reportsProjectId, projectOptions])

  React.useEffect(() => {
    if (!heatmapEnabled && reportsView === 'heatmap') {
      setReportsView('workload')
    }
  }, [heatmapEnabled, reportsView, setReportsView])

  React.useEffect(() => {
    if (!heatmapEnabled) return
    if (autoSwitchedHeatmapRef.current) return
    autoSwitchedHeatmapRef.current = true
    if (reportsView !== 'heatmap') {
      setReportsView('heatmap')
    }
  }, [heatmapEnabled, reportsView, setReportsView])

  const projectScopedRows = React.useMemo(
    () => filterRowsByProject(sourceRows, reportsProjectId),
    [sourceRows, reportsProjectId]
  )
  const scopedRows = (projectScopedRows || []).filter(r => !reportsEmployeeId || String(r.user_id) === String(reportsEmployeeId))
  const employeesSorted = [...scopedRows].sort((a, b) => lastName(a.user_name).localeCompare(lastName(b.user_name), 'ru'))
  const groupedProjects = groupByProjects(scopedRows)
  const occupancyProjects = groupByProjects(sourceRows || [])
  const groupedDepartments = buildDeptGroups(reportsData?.dept_tree || [], scopedRows)
  const projectModalData = React.useMemo(
    () => normalizeProjectModalData(reportsProjectModal),
    [reportsProjectModal]
  )

  return (
    <div style={{ padding: 16, overflow: 'auto', height: '100%' }}>
      <div style={{ display: 'grid', gridTemplateColumns: '380px 1fr', gap: 14 }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          <div style={cardStyle}>
            <div style={titleStyle}>Фильтры</div>
            <div style={{ display: 'grid', gap: 8, marginTop: 8 }}>
              <SearchableDropdown
                value={deptInput}
                onChange={setDeptInput}
                placeholder="Подразделение: начните ввод..."
                options={deptOptions.map((d) => ({
                  id: String(d.id),
                  label: d.breadcrumb,
                  search: `${d.name} ${d.breadcrumb} ${d.id}`.toLowerCase(),
                }))}
                onSelect={(opt) => {
                  setDeptInput(opt.label)
                  setReportsDept(Number(opt.id))
                }}
              />
              {selectedDeptOption?.breadcrumb && (
                <div style={{ fontSize: 11, color: '#6e6e73', lineHeight: 1.35, marginTop: -2 }}>
                  Выбрано: {selectedDeptOption.breadcrumb}
                </div>
              )}

              <SearchableDropdown
                value={employeeInput}
                onChange={setEmployeeInput}
                placeholder="Сотрудник: начните ввод..."
                options={employeeOptions.map((u) => ({
                  id: String(u.id),
                  label: `${u.name} · ${u.dept}`,
                  search: `${u.name} ${u.dept} ${u.id}`.toLowerCase(),
                }))}
                onSelect={(opt) => {
                  setEmployeeInput(opt.label)
                  setReportsEmployee(opt.id)
                }}
              />

              <SearchableDropdown
                value={projectInput}
                onChange={setProjectInput}
                placeholder="Проект: Все"
                options={[
                  { id: '', label: 'Все', search: 'все all' },
                  ...projectOptions.map((p) => ({
                    id: String(p.id),
                    label: p.name,
                    search: `${p.name} ${p.id}`.toLowerCase(),
                  })),
                ]}
                onSelect={(opt) => {
                  setProjectInput(opt.label)
                  setReportsProject(opt.id || '')
                }}
              />
              <TaskDateFilters
                value={reportsTaskDates || {}}
                onChange={setReportsTaskDateRange}
                onClear={clearReportsTaskDateRange}
              />
              <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#1d1d1f' }}>
                <input type="checkbox" checked={!!reportsIncludeSubdepts} onChange={(e) => setReportsIncludeSubdepts(e.target.checked)} />
                Включить подчинённые отделы
              </label>
              <select value={reportsSortBy} onChange={(e) => setReportsSortBy(e.target.value)} style={inputStyle}>
                <option value="hours_desc">Сортировка: часы (убыв.)</option>
                <option value="hours_asc">Сортировка: часы (возр.)</option>
                <option value="name_asc">Сортировка: имя А-Я</option>
                <option value="name_desc">Сортировка: имя Я-А</option>
              </select>
              <button onClick={() => exportReportsCsv()} style={{ ...refreshBtnStyle, background: '#1d1d1f' }}>Экспорт CSV</button>
              <button onClick={() => resetReportsFilters()} style={{ ...refreshBtnStyle, background: '#8e8e93' }}>Сбросить фильтры</button>
              <button onClick={() => loadReports()} style={refreshBtnStyle}>Обновить</button>
            </div>
          </div>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          <div style={{ ...cardStyle, paddingBottom: 12 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <span style={{ fontSize: 16, color: '#1d1d1f', fontWeight: 700 }}>Представление</span>
                <select value={reportsView} onChange={(e) => setReportsView(e.target.value)} style={{ ...inputStyle, width: 280, padding: '10px 12px', fontSize: 15, fontWeight: 600 }}>
                  <option value="workload">Трудозатраты</option>
                  <option value="occupancy">Занятость</option>
                  <option value="planning">Планирование Задачи/день</option>
                  {heatmapEnabled && <option value="heatmap">Тепловая карта (факт)</option>}
                </select>
              </div>
              <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
                {selectedDeptName && (
                  <div style={{ fontSize: 12, color: '#1d1d1f', fontWeight: 600 }}>
                    {selectedDeptName}
                  </div>
                )}
                <div style={{ fontSize: 12, color: '#6e6e73' }}>{period.label || 'Все время'}</div>
              </div>
            </div>
            <ReportScopeChips
              reportsGroupMode={reportsGroupMode}
              setReportsGroupMode={setReportsGroupMode}
              taskScopes={taskScopes}
              setTaskScopes={setTaskScopes}
            />
            {reportsView === 'workload' && (
              <div style={{ marginTop: 10 }}>
                <ReportTotals totals={reportsData?.totals || {}} />
              </div>
            )}
          </div>

          {isLoadingReports && <div style={infoStyle}>Загрузка отчёта...</div>}
          {reportsError && <div style={{ ...infoStyle, color: '#d70015' }}>Ошибка: {reportsError}</div>}
          {!isLoadingReports && !reportsError && reportsView !== 'planning' && reportsView !== 'heatmap' && sourceRows.length === 0 && (
            <div style={infoStyle}>Нет данных за выбранные фильтры.</div>
          )}

          {!isLoadingReports && !reportsError && sourceRows.length > 0 && reportsView === 'workload' && (
            <div style={{ display: 'grid', gap: 12 }}>
              {reportsGroupMode === 'employees' && employeesSorted.map((u) => (
                <GroupCard key={`u-${u.user_id}`} title={`${u.user_name} · ${u.dept_name}`} total={u.total_hours}>
                  <button onClick={() => openReportsUserModal(u)} style={smallBtnStyle}>Детали сотрудника</button>
                  {u.tasks.map((t, i) => <TaskRow key={`${t.task_id}-${i}`} task={t} showProjectSuffix viewMode={reportsView} />)}
                </GroupCard>
              ))}

              {reportsGroupMode === 'projects' && groupedProjects.map((p, idx) => (
                <GroupCard key={`p-${p.project_id ?? 'none'}-${idx}`} title={p.project_name} total={p.total_hours}>
                  <button onClick={() => openReportsProjectModal(p)} style={smallBtnStyle}>Детали проекта</button>
                  {p.users.map((u) => (
                    <div key={`pu-${u.user_id}`} style={{ marginTop: 6 }}>
                      <div style={{ fontSize: 12, color: '#6e6e73', marginBottom: 4 }}>{u.user_name} · {Number(u.total_hours).toFixed(2)} ч</div>
                      {u.tasks.map((t, i) => <TaskRow key={`${u.user_id}-${t.task_id}-${i}`} task={t} viewMode={reportsView} />)}
                    </div>
                  ))}
                </GroupCard>
              ))}

              {reportsGroupMode === 'departments' && groupedDepartments.map((d) => (
                <DeptCard key={`d-${d.id}`} node={d} depth={0} />
              ))}
            </div>
          )}
          {!isLoadingReports && !reportsError && sourceRows.length > 0 && reportsView === 'occupancy' && (
            <div style={{ display: 'grid', gap: 12 }}>
              {occupancyProjects.map((p, idx) => (
                <GroupCard key={`occ-${p.project_id ?? 'none'}-${idx}`} title={p.project_name} total={p.total_hours}>
                  {(p.users || []).map((u) => (
                    <div key={`occ-u-${u.user_id}`} style={{ marginTop: 6 }}>
                      <div style={{ fontSize: 12, color: '#1d1d1f', fontWeight: 600 }}>
                        {u.user_name} · {Number(u.total_hours || 0).toFixed(2)} ч
                      </div>
                      {(u.tasks || []).map((t, i) => (
                        <TaskRow key={`occ-${u.user_id}-${t.task_id}-${i}`} task={t} viewMode={reportsView} />
                      ))}
                    </div>
                  ))}
                </GroupCard>
              ))}
            </div>
          )}
          {!isLoadingReports && !reportsError && reportsView === 'planning' && (
            <PlanningView
              planning={reportsData?.planning || null}
              scale={reportsPlanningScale}
              onChangeScale={setReportsPlanningScale}
            />
          )}
          {!isLoadingReports && !reportsError && heatmapEnabled && reportsView === 'heatmap' && (
            <HeatmapView
              heatmap={reportsData?.heatmap || null}
              scale={reportsPlanningScale}
              onChangeScale={setReportsPlanningScale}
            />
          )}
        </div>
      </div>

      <DetailsModal
        title={reportsUserModal ? `Сотрудник: ${reportsUserModal.user_name}` : 'Сотрудник'}
        open={!!reportsUserModal}
        onClose={closeReportsUserModal}
      >
        {reportsUserModal && (
          <div style={{ display: 'grid', gap: 8 }}>
            <div style={modalMetaStyle}>
              Всего: {Number(reportsUserModal.total_hours || 0).toFixed(2)} ч · Проектов: {(reportsUserModal.projects || []).length}
            </div>
            {(reportsUserModal.projects || []).map((p, idx) => (
              <div key={`${p.project_id ?? 'none'}-${idx}`} style={modalLineStyle}>
                <span>{p.project_name}</span>
                <strong>{Number(p.hours || 0).toFixed(2)} ч</strong>
              </div>
            ))}
          </div>
        )}
      </DetailsModal>

      <DetailsModal
        title={reportsProjectModal ? `Проект: ${reportsProjectModal.project_name}` : 'Проект'}
        open={!!reportsProjectModal}
        onClose={closeReportsProjectModal}
      >
        {projectModalData && (
          <div style={{ display: 'grid', gap: 8 }}>
            <div style={modalMetaStyle}>
              Всего: {Number(projectModalData.totalHours || 0).toFixed(2)} ч · Задач: {(projectModalData.tasks || []).length}
            </div>
            {(projectModalData.tasks || []).map((t, idx) => (
              <div key={`${t.task_id || idx}-${idx}`} style={modalLineStyle}>
                <span>#{t.task_id} {t.task_name}</span>
                <strong>{Number(t.fact_hours ?? t.hours ?? 0).toFixed(2)} ч</strong>
              </div>
            ))}
          </div>
        )}
      </DetailsModal>
    </div>
  )
}

function GroupCard({ title, total, children }) {
  return (
    <div style={sectionStyle}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
        <div style={{ fontSize: 13, color: '#6e6e73', fontWeight: 700 }}>{title}</div>
        <div style={{ fontSize: 13, color: '#1d1d1f', fontWeight: 700 }}>Итого: {Number(total || 0).toFixed(2)} ч</div>
      </div>
      <div style={{ display: 'grid', gap: 8 }}>{children}</div>
    </div>
  )
}

function ReportScopeChips({ reportsGroupMode, setReportsGroupMode, taskScopes, setTaskScopes }) {
  return (
    <div style={{ display: 'flex', gap: 6, marginTop: 10, flexWrap: 'wrap' }}>
      <div style={chipGroupStyle}>
        <button onClick={() => setReportsGroupMode('employees')} style={chipStyle(reportsGroupMode === 'employees')}>Сотрудники</button>
        <button onClick={() => setReportsGroupMode('departments')} style={chipStyle(reportsGroupMode === 'departments')}>Отделы</button>
        <button onClick={() => setReportsGroupMode('projects')} style={chipStyle(reportsGroupMode === 'projects')}>Проекты</button>
      </div>
      <div style={chipGroupStyle}>
        <button
          title="Статус 2: Ждёт выполнения"
          onClick={() => toggleScope('planned', taskScopes, setTaskScopes)}
          style={chipStyle(taskScopes.includes('planned'))}
        >
          Плановые (ждут выполнения)
        </button>
        <button
          title="Статусы 3 и 4: Выполняется / Ждёт контроля"
          onClick={() => toggleScope('in_work', taskScopes, setTaskScopes)}
          style={chipStyle(taskScopes.includes('in_work'))}
        >
          В работе (выполняется/контроль)
        </button>
        <button
          title="Статус 5: Завершена"
          onClick={() => toggleScope('done', taskScopes, setTaskScopes)}
          style={chipStyle(taskScopes.includes('done'))}
        >
          Выполненные (завершены)
        </button>
      </div>
    </div>
  )
}

function DeptCard({ node, depth }) {
  return (
    <div style={{ ...sectionStyle, marginLeft: depth * 18 }}>
      <div style={{ fontSize: 13, color: '#6e6e73', fontWeight: 700, marginBottom: 8 }}>
        {node.name} · Итого: {Number(node.total_hours || 0).toFixed(2)} ч
      </div>
      {(node.users || []).map((u) => (
        <div key={`du-${u.user_id}`} style={{ marginBottom: 8, marginLeft: 12 }}>
          <div style={{ fontSize: 12, color: '#1d1d1f', fontWeight: 600 }}>{u.user_name} · {Number(u.total_hours || 0).toFixed(2)} ч</div>
          {(u.tasks || []).map((t, i) => <TaskRow key={`${u.user_id}-${t.task_id}-${i}`} task={t} showProjectSuffix viewMode={reportsView} />)}
        </div>
      ))}
      {(node.children || []).map((c) => <DeptCard key={`dch-${c.id}`} node={c} depth={depth + 1} />)}
    </div>
  )
}

function flattenDeptTree(tree, level = 0, out = [], path = []) {
  ;(tree || []).forEach((n) => {
    const nextPath = [...path, n.name]
    out.push({ id: n.id, name: n.name, level, breadcrumb: nextPath.join(' / ') })
    flattenDeptTree(n.children || [], level + 1, out, nextPath)
  })
  return out
}

function lastName(fullName = '') {
  const p = String(fullName).trim().split(/\s+/)
  return p[p.length - 1] || fullName
}

function groupByProjects(rows) {
  const map = new Map()
  rows.forEach((u) => {
    ;(u.tasks || []).forEach((t) => {
      const key = String(t.project_id ?? 'none')
      if (!map.has(key)) map.set(key, { project_id: t.project_id ?? null, project_name: t.project_name || 'Без проекта', total_hours: 0, users: [] })
      const p = map.get(key)
      p.total_hours += Number(t.fact_hours ?? t.hours ?? 0)
      let userRow = p.users.find(x => x.user_id === u.user_id)
      if (!userRow) {
        userRow = { user_id: u.user_id, user_name: u.user_name, total_hours: 0, tasks: [] }
        p.users.push(userRow)
      }
      userRow.total_hours += Number(t.fact_hours ?? t.hours ?? 0)
      userRow.tasks.push(t)
    })
  })
  return Array.from(map.values()).sort((a, b) => b.total_hours - a.total_hours)
}

function buildDeptGroups(tree, rows) {
  const byDept = new Map()
  rows.forEach((u) => {
    const deptId = Number(u.dept_id || 0)
    if (!byDept.has(deptId)) byDept.set(deptId, [])
    byDept.get(deptId).push(u)
  })
  const build = (node) => {
    const users = byDept.get(Number(node.id)) || []
    const children = (node.children || []).map(build)
    const usersHours = users.reduce((s, u) => s + Number(u.total_hours || 0), 0)
    const childHours = children.reduce((s, c) => s + Number(c.total_hours || 0), 0)
    return { id: node.id, name: node.name, users, children, total_hours: usersHours + childHours }
  }
  return (tree || []).map(build).filter(n => n.total_hours > 0 || (n.users || []).length > 0 || (n.children || []).length > 0)
}

function normalizeProjectModalData(project) {
  if (!project) return null
  const directTasks = Array.isArray(project.tasks) ? project.tasks : []
  if (directTasks.length > 0) {
    return {
      tasks: directTasks,
      totalHours: Number(project.hours ?? directTasks.reduce((s, t) => s + Number(t.fact_hours ?? t.hours ?? 0), 0)),
    }
  }
  const users = Array.isArray(project.users) ? project.users : []
  const tasks = []
  let totalHours = 0
  users.forEach((u) => {
    ;(u.tasks || []).forEach((t) => {
      tasks.push(t)
      totalHours += Number(t.fact_hours ?? t.hours ?? 0)
    })
  })
  return { tasks, totalHours }
}

function toggleScope(scopeKey, selected, setSelected) {
  const has = selected.includes(scopeKey)
  if (has) {
    // Не даём снять последний активный фильтр.
    if (selected.length === 1) return
    setSelected(selected.filter(k => k !== scopeKey))
    return
  }
  setSelected([...selected, scopeKey])
}

function filterRowsByTaskStatus(rows, allowedStatuses) {
  const set = new Set((allowedStatuses || []).map(Number))
  return (rows || [])
    .map((u) => {
      const tasks = (u.tasks || []).filter((t) => set.has(Number(t.status_code)))
      const total = tasks.reduce((sum, t) => sum + Number(t.fact_hours ?? t.hours ?? 0), 0)
      if (tasks.length === 0) return null
      return { ...u, tasks, total_hours: total }
    })
    .filter(Boolean)
}

function filterRowsByProject(rows, projectId) {
  const pid = String(projectId || '').trim()
  if (!pid) return rows || []
  return (rows || [])
    .map((u) => {
      const tasks = (u.tasks || []).filter((t) => String(t.project_id ?? '') === pid)
      const total = tasks.reduce((sum, t) => sum + Number(t.fact_hours ?? t.hours ?? 0), 0)
      if (tasks.length === 0) return null
      return { ...u, tasks, total_hours: total }
    })
    .filter(Boolean)
}

function mergeRowsByUser(groups) {
  const merged = new Map()
  Object.values(groups || {}).forEach((rows) => {
    ;(rows || []).forEach((u) => {
      const key = String(u.user_id)
      if (!merged.has(key)) {
        merged.set(key, {
          ...u,
          tasks: [],
          total_hours: 0,
        })
      }
      const dest = merged.get(key)
      ;(u.tasks || []).forEach((t) => {
        const tKey = [t.task_id, t.date || '', t.status_code || '', t.project_id || ''].join('|')
        if (!dest._taskKeys) dest._taskKeys = new Set()
        if (dest._taskKeys.has(tKey)) return
        dest._taskKeys.add(tKey)
        dest.tasks.push(t)
        dest.total_hours += Number(t.fact_hours ?? t.hours ?? 0)
      })
    })
  })
  return Array.from(merged.values()).map((u) => {
    const { _taskKeys, ...clean } = u
    return clean
  })
}

function TaskDateFilters({ value, onChange, onClear }) {
  const [quickFor, setQuickFor] = React.useState('')
  const rows = [
    { key: 'created', label: 'Дата создания' },
    { key: 'plan_start', label: 'Плановая дата начала' },
    { key: 'plan_end', label: 'Плановая дата завершения' },
    { key: 'deadline', label: 'Крайний срок' },
    { key: 'closed', label: 'Фактическая дата завершения' },
  ]
  const quickItems = buildQuickDateItems()

  return (
    <div style={{ display: 'grid', gap: 8 }}>
      {rows.map((row) => {
        const from = value?.[row.key]?.from || ''
        const to = value?.[row.key]?.to || ''
        const hasDate = Boolean(from || to)
        return (
          <div
            key={row.key}
            style={{
              position: 'relative',
              border: `1px solid ${hasDate ? '#b9d7ff' : '#ececf1'}`,
              borderRadius: 8,
              padding: 8,
              background: hasDate ? 'rgba(0,113,227,.04)' : '#fff',
            }}
          >
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6, gap: 8 }}>
              <div style={{ fontSize: 12, fontWeight: 600, color: hasDate ? '#0071e3' : '#1d1d1f' }}>
                {row.label}
              </div>
              <button
                onClick={() => setQuickFor(quickFor === row.key ? '' : row.key)}
                style={quickOpenBtnStyle(quickFor === row.key || hasDate)}
              >
                Быстрый ввод
              </button>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr auto', gap: 6 }}>
              <input
                type="date"
                value={from}
                onChange={(e) => onChange(row.key, e.target.value, to)}
                style={dateInputStyle(Boolean(from))}
              />
              <input
                type="date"
                value={to}
                onChange={(e) => onChange(row.key, from, e.target.value)}
                style={dateInputStyle(Boolean(to))}
              />
              <button
                title="Очистить даты"
                onClick={() => onClear(row.key)}
                style={clearIconBtnStyle(hasDate)}
              >
                <ClearIcon />
              </button>
            </div>
            {quickFor === row.key && (
              <div style={quickPanelStyle}>
                {quickItems.map((section, sectionIdx) => (
                  <div key={sectionIdx}>
                    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                      {section.map((item) => (
                        <button
                          key={item.key}
                          onClick={() => {
                            onChange(row.key, item.from, item.to)
                            setQuickFor('')
                          }}
                          style={miniPresetBtnStyle}
                        >
                          {item.label}
                        </button>
                      ))}
                    </div>
                    {sectionIdx < quickItems.length - 1 && <div style={quickDividerStyle} />}
                  </div>
                ))}
              </div>
            )}
          </div>
        )
      })}
    </div>
  )
}

function SearchableDropdown({ value, onChange, placeholder, options, onSelect }) {
  const [open, setOpen] = React.useState(false)
  const boxRef = React.useRef(null)

  React.useEffect(() => {
    const onDocClick = (e) => {
      if (!boxRef.current) return
      if (!boxRef.current.contains(e.target)) setOpen(false)
    }
    document.addEventListener('mousedown', onDocClick)
    return () => document.removeEventListener('mousedown', onDocClick)
  }, [])

  const filtered = React.useMemo(() => {
    const q = normalizeSearch(String(value || ''))
    if (!q) return options.slice(0, 50)

    const qAltRu = normalizeSearch(convertEnToRuLayout(q))
    const qAltLat = normalizeSearch(transliterateRuToLat(q))

    return options.filter((o) => {
      const raw = normalizeSearch(o.search || o.label || '')
      const rawLat = normalizeSearch(transliterateRuToLat(raw))
      return raw.includes(q) || raw.includes(qAltRu) || rawLat.includes(q) || rawLat.includes(qAltLat)
    }).slice(0, 50)
  }, [options, value])

  return (
    <div ref={boxRef} style={{ position: 'relative' }}>
      <input
        value={value}
        onFocus={() => setOpen(true)}
        onChange={(e) => {
          onChange(e.target.value)
          setOpen(true)
        }}
        placeholder={placeholder}
        style={inputStyle}
      />
      {open && filtered.length > 0 && (
        <div
          style={{
            position: 'absolute',
            top: 'calc(100% + 4px)',
            left: 0,
            right: 0,
            zIndex: 30,
            background: '#fff',
            border: '1px solid #d2d2d7',
            borderRadius: 10,
            boxShadow: '0 8px 24px rgba(0,0,0,.12)',
            maxHeight: 260,
            overflowY: 'auto',
            padding: 4,
          }}
        >
          {filtered.map((opt) => (
            <button
              key={opt.id}
              onClick={() => {
                onSelect(opt)
                setOpen(false)
              }}
              style={{
                width: '100%',
                textAlign: 'left',
                border: 'none',
                background: 'transparent',
                padding: '8px 10px',
                borderRadius: 8,
                cursor: 'pointer',
                fontFamily: 'inherit',
                fontSize: 14,
                color: '#1d1d1f',
                lineHeight: 1.3,
                whiteSpace: 'normal',
                wordBreak: 'break-word',
              }}
              title={opt.label}
            >
              {opt.label}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

function ClearIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true">
      <path
        d="M4.2 4.2l5.6 5.6M9.8 4.2L4.2 9.8"
        stroke="currentColor"
        strokeWidth="1.7"
        strokeLinecap="round"
      />
    </svg>
  )
}

const cardStyle = { background: '#fff', border: '1px solid #e9e9ed', borderRadius: 12, padding: 12 }
const titleStyle = { fontSize: 14, color: '#1d1d1f', fontWeight: 700 }
const inputStyle = { width: '100%', border: '1px solid #d2d2d7', borderRadius: 8, padding: '8px 10px', fontSize: 13, fontFamily: 'inherit' }
const refreshBtnStyle = { border: 'none', background: '#0071e3', color: '#fff', borderRadius: 8, padding: '8px 10px', fontSize: 13, cursor: 'pointer', fontFamily: 'inherit' }
const infoStyle = { background: '#fff', border: '1px solid #e9e9ed', borderRadius: 12, padding: '12px 14px', fontSize: 13, color: '#6e6e73' }
const sectionStyle = { background: '#fff', border: '1px solid #e9e9ed', borderRadius: 12, padding: 10 }
const modalMetaStyle = { fontSize: 12, color: '#6e6e73', paddingBottom: 6, borderBottom: '1px solid #efeff4' }
const modalLineStyle = { display: 'grid', gridTemplateColumns: '1fr auto', gap: 10, fontSize: 13, color: '#1d1d1f', padding: '6px 0', borderBottom: '1px dashed #efeff4' }
const smallBtnStyle = { border: '1px solid #d2d2d7', background: '#fff', borderRadius: 6, fontSize: 11, padding: '3px 7px', cursor: 'pointer', justifySelf: 'start' }
const chipGroupStyle = {
  display: 'flex',
  gap: 6,
  padding: 4,
  border: '1px solid #e5e5ea',
  borderRadius: 10,
  background: '#fafafd',
}
const chipStyle = (active) => ({
  border: '1px solid ' + (active ? '#0071e3' : '#d2d2d7'),
  background: active ? 'rgba(0,113,227,.08)' : '#fff',
  color: active ? '#0071e3' : '#1d1d1f',
  borderRadius: 999,
  padding: '6px 10px',
  fontSize: 12,
  cursor: 'pointer',
  fontFamily: 'inherit',
})
const miniPresetBtnStyle = {
  border: '1px solid #d2d2d7',
  background: '#fff',
  color: '#1d1d1f',
  borderRadius: 999,
  padding: '3px 7px',
  fontSize: 11,
  cursor: 'pointer',
  fontFamily: 'inherit',
}
const quickOpenBtnStyle = (active) => ({
  border: `1px solid ${active ? '#0071e3' : '#d2d2d7'}`,
  background: active ? 'rgba(0,113,227,.08)' : '#fff',
  color: active ? '#0071e3' : '#1d1d1f',
  borderRadius: 999,
  padding: '3px 8px',
  fontSize: 11,
  cursor: 'pointer',
  fontFamily: 'inherit',
  whiteSpace: 'nowrap',
})
const quickPanelStyle = {
  marginTop: 8,
  border: '1px solid #e5e5ea',
  borderRadius: 10,
  background: '#fafafd',
  padding: 8,
}
const quickDividerStyle = {
  height: 1,
  background: '#e5e5ea',
  margin: '8px 0',
}
const clearIconBtnStyle = (active) => ({
  width: 30,
  height: 34,
  border: `1px solid ${active ? '#ffd0cb' : '#d2d2d7'}`,
  background: active ? 'rgba(255,59,48,.06)' : '#fff',
  color: active ? '#ff3b30' : '#8e8e93',
  borderRadius: 8,
  cursor: 'pointer',
  fontFamily: 'inherit',
  padding: 0,
  display: 'inline-flex',
  alignItems: 'center',
  justifyContent: 'center',
})

const dateInputStyle = (active) => ({
  ...inputStyle,
  border: `1px solid ${active ? '#0071e3' : '#d2d2d7'}`,
  background: active ? '#f5faff' : '#fff',
})

function buildQuickDateItems() {
  const today = new Date()
  const startOfToday = dateOnly(today)
  const currentWeekStart = startOfWeek(today)
  const currentWeekEnd = addDays(currentWeekStart, 6)
  const currentMonthStart = new Date(today.getFullYear(), today.getMonth(), 1)
  const currentMonthEnd = new Date(today.getFullYear(), today.getMonth() + 1, 0)
  const currentQuarterStart = new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3, 1)
  const currentQuarterEnd = new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3 + 3, 0)
  const currentYearStart = new Date(today.getFullYear(), 0, 1)
  const currentYearEnd = new Date(today.getFullYear(), 11, 31)

  const lastMonthStart = new Date(today.getFullYear(), today.getMonth() - 1, 1)
  const lastMonthEnd = new Date(today.getFullYear(), today.getMonth(), 0)
  const thisQuarterStart = new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3, 1)
  const lastQuarterStart = new Date(thisQuarterStart.getFullYear(), thisQuarterStart.getMonth() - 3, 1)
  const lastQuarterEnd = new Date(thisQuarterStart.getFullYear(), thisQuarterStart.getMonth(), 0)

  const future7End = addDays(startOfToday, 6)
  const future30End = addDays(startOfToday, 29)
  const futureQuarterEnd = addDays(startOfToday, 89)
  const futureYearEnd = addDays(startOfToday, 364)

  return [
    [
      { key: 'cur_week', label: 'Текущая неделя', from: fmt(currentWeekStart), to: fmt(currentWeekEnd) },
      { key: 'cur_month', label: 'Текущий месяц', from: fmt(currentMonthStart), to: fmt(currentMonthEnd) },
      { key: 'cur_quarter', label: 'Текущий квартал', from: fmt(currentQuarterStart), to: fmt(currentQuarterEnd) },
      { key: 'cur_year', label: 'Текущий год', from: fmt(currentYearStart), to: fmt(currentYearEnd) },
    ],
    [
      { key: 'last_7', label: 'Последние 7 дней', from: fmt(addDays(startOfToday, -6)), to: fmt(startOfToday) },
      { key: 'last_month', label: 'Последний месяц', from: fmt(lastMonthStart), to: fmt(lastMonthEnd) },
      { key: 'last_quarter', label: 'Последний квартал', from: fmt(lastQuarterStart), to: fmt(lastQuarterEnd) },
    ],
    [
      { key: 'next_7', label: 'Ближайшие 7 дней', from: fmt(startOfToday), to: fmt(future7End) },
      { key: 'next_30', label: 'Ближайшие 30 дней', from: fmt(startOfToday), to: fmt(future30End) },
      { key: 'next_quarter', label: 'Ближайший квартал', from: fmt(startOfToday), to: fmt(futureQuarterEnd) },
      { key: 'next_year', label: 'Ближайший год', from: fmt(startOfToday), to: fmt(futureYearEnd) },
    ],
  ]
}

function startOfWeek(d) {
  const x = dateOnly(d)
  const day = (x.getDay() + 6) % 7
  x.setDate(x.getDate() - day)
  return x
}

function addDays(d, days) {
  const x = dateOnly(d)
  x.setDate(x.getDate() + days)
  return x
}

function dateOnly(d) {
  return new Date(d.getFullYear(), d.getMonth(), d.getDate())
}

function fmt(d) {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

function normalizeSearch(s) {
  return String(s || '')
    .toLowerCase()
    .replace(/ё/g, 'е')
    .replace(/\s+/g, ' ')
    .trim()
}

function convertEnToRuLayout(s) {
  const map = {
    q: 'й', w: 'ц', e: 'у', r: 'к', t: 'е', y: 'н', u: 'г', i: 'ш', o: 'щ', p: 'з',
    '[': 'х', ']': 'ъ', a: 'ф', s: 'ы', d: 'в', f: 'а', g: 'п', h: 'р', j: 'о', k: 'л',
    l: 'д', ';': 'ж', "'": 'э', z: 'я', x: 'ч', c: 'с', v: 'м', b: 'и', n: 'т', m: 'ь',
    ',': 'б', '.': 'ю', '`': 'ё',
  }
  return String(s || '')
    .split('')
    .map((ch) => map[ch] || ch)
    .join('')
}

function transliterateRuToLat(s) {
  const map = {
    а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z', и: 'i', й: 'y',
    к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't', у: 'u', ф: 'f',
    х: 'h', ц: 'ts', ч: 'ch', ш: 'sh', щ: 'sch', ъ: '', ы: 'y', ь: '', э: 'e', ю: 'yu', я: 'ya',
  }
  return String(s || '')
    .split('')
    .map((ch) => map[ch] ?? ch)
    .join('')
}

