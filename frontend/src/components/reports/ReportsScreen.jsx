import React from 'react'
import { useWorkspaceStore } from '../../store/useWorkspaceStore.js'
import { ReportTotals } from './ReportTotals.jsx'
import { DetailsModal } from './DetailsModal.jsx'
import { TaskRow } from './TaskRow.jsx'

export function ReportsScreen() {
  const [reportView, setReportView] = React.useState('workload') // workload | occupancy
  const {
    reportsData,
    reportsDeptId,
    reportsDateFrom,
    reportsDateTo,
    reportsPeriodPreset,
    reportsGroupMode,
    reportsIncludeSubdepts,
    reportsSortBy,
    reportsEmployeeId,
    reportsUserModal,
    reportsProjectModal,
    isLoadingReports,
    reportsError,
    setReportsDept,
    setReportsPeriod,
    setReportsPreset,
    setReportsGroupMode,
    setReportsIncludeSubdepts,
    setReportsSortBy,
    setReportsEmployee,
    resetReportsFilters,
    exportReportsCsv,
    openReportsUserModal,
    closeReportsUserModal,
    openReportsProjectModal,
    closeReportsProjectModal,
    loadReports,
  } = useWorkspaceStore()

  const period = reportsData?.period || {}
  const rows = reportsData?.rows || []
  const currentWork = reportsData?.current_work || []
  const periodDone = reportsData?.period_done || []
  const [activeTab, setActiveTab] = React.useState('in_work')
  const [deptSearch, setDeptSearch] = React.useState('')

  const deptOptions = React.useMemo(() => flattenDeptTree(reportsData?.dept_tree || []), [reportsData])
  const selectedDeptName = React.useMemo(() => {
    const selectedId = Number(reportsDeptId || reportsData?.selected_dept_id || 0)
    if (!selectedId) return ''
    const found = deptOptions.find(d => Number(d.id) === selectedId)
    return found?.name || ''
  }, [deptOptions, reportsDeptId, reportsData])
  const filteredDeptOptions = deptSearch
    ? deptOptions.filter(d => d.name.toLowerCase().includes(deptSearch.toLowerCase()))
    : deptOptions

  const employeeOptions = React.useMemo(() => {
    const src = (activeTab === 'done' ? periodDone : currentWork) || []
    return src
      .map(r => ({ id: String(r.user_id), name: r.user_name, dept: r.dept_name }))
      .sort((a, b) => lastName(a.name).localeCompare(lastName(b.name), 'ru'))
  }, [activeTab, currentWork, periodDone])

  const sourceRows = activeTab === 'done' ? periodDone : currentWork
  const scopedRows = (sourceRows || []).filter(r => !reportsEmployeeId || String(r.user_id) === String(reportsEmployeeId))
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
      <div style={{ display: 'grid', gridTemplateColumns: '320px 1fr', gap: 14 }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          <div style={cardStyle}>
            <div style={titleStyle}>Фильтры</div>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 8 }}>
              {[
                ['today', 'Сегодня'],
                ['7d', '7 дн'],
                ['30d', '30 дн'],
                ['month', 'Месяц'],
                ['quarter', 'Квартал'],
              ].map(([v, label]) => (
                <button key={v} onClick={() => setReportsPreset(v)} style={chipStyle(reportsPeriodPreset === v)}>{label}</button>
              ))}
            </div>
            <div style={{ display: 'grid', gap: 8, marginTop: 8 }}>
              <input
                value={deptSearch}
                onChange={(e) => setDeptSearch(e.target.value)}
                placeholder="Поиск подразделения..."
                style={inputStyle}
              />
              <select value={String(reportsDeptId || '')} onChange={(e) => setReportsDept(Number(e.target.value) || null)} style={inputStyle}>
                <option value="">Выберите подразделение ▼</option>
                {filteredDeptOptions.map((d) => (
                  <option key={d.id} value={d.id}>{d.breadcrumb}</option>
                ))}
              </select>
              <select value={String(reportsEmployeeId || '')} onChange={(e) => setReportsEmployee(e.target.value)} style={inputStyle}>
                <option value="">Все сотрудники ▼</option>
                {employeeOptions.map((u) => (
                  <option key={u.id} value={u.id}>{u.name} · {u.dept}</option>
                ))}
              </select>
              <input type="date" value={reportsDateFrom || period.from || ''} onChange={(e) => setReportsPeriod(e.target.value, reportsDateTo || period.to || '', 'custom')} style={inputStyle} />
              <input type="date" value={reportsDateTo || period.to || ''} onChange={(e) => setReportsPeriod(reportsDateFrom || period.from || '', e.target.value, 'custom')} style={inputStyle} />
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
              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <span style={{ fontSize: 12, color: '#6e6e73' }}>Представление</span>
                <select value={reportView} onChange={(e) => setReportView(e.target.value)} style={{ ...inputStyle, width: 170, padding: '6px 8px' }}>
                  <option value="workload">Трудозатраты</option>
                  <option value="occupancy">Занятость</option>
                </select>
              </div>
              <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
                {selectedDeptName && (
                  <div style={{ fontSize: 12, color: '#1d1d1f', fontWeight: 600 }}>
                    {selectedDeptName}
                  </div>
                )}
                <div style={{ fontSize: 12, color: '#6e6e73' }}>{period.label || 'Период'}</div>
              </div>
            </div>
            {reportView === 'workload' && (
              <div style={{ display: 'flex', gap: 6, marginTop: 10, flexWrap: 'wrap' }}>
                <div style={chipGroupStyle}>
                  <button onClick={() => setReportsGroupMode('employees')} style={chipStyle(reportsGroupMode === 'employees')}>Сотрудники</button>
                  <button onClick={() => setReportsGroupMode('departments')} style={chipStyle(reportsGroupMode === 'departments')}>Отделы</button>
                  <button onClick={() => setReportsGroupMode('projects')} style={chipStyle(reportsGroupMode === 'projects')}>Проекты</button>
                </div>
                <div style={chipGroupStyle}>
                  <button onClick={() => setActiveTab('in_work')} style={chipStyle(activeTab === 'in_work')}>Задачи в работе</button>
                  <button onClick={() => setActiveTab('done')} style={chipStyle(activeTab === 'done')}>Выполненные задачи</button>
                </div>
              </div>
            )}
            <div style={{ marginTop: 10 }}>
              <ReportTotals totals={reportsData?.totals || {}} />
            </div>
          </div>

          {isLoadingReports && <div style={infoStyle}>Загрузка отчёта...</div>}
          {reportsError && <div style={{ ...infoStyle, color: '#d70015' }}>Ошибка: {reportsError}</div>}
          {!isLoadingReports && !reportsError && rows.length === 0 && <div style={infoStyle}>Нет данных за выбранный период.</div>}

          {!isLoadingReports && !reportsError && rows.length > 0 && reportView === 'workload' && (
            <div style={{ display: 'grid', gap: 12 }}>
              {reportsGroupMode === 'employees' && employeesSorted.map((u) => (
                <GroupCard key={`u-${u.user_id}`} title={`${u.user_name} · ${u.dept_name}`} total={u.total_hours}>
                  <button onClick={() => openReportsUserModal(u)} style={smallBtnStyle}>Детали сотрудника</button>
                  {u.tasks.map((t, i) => <TaskRow key={`${t.task_id}-${i}`} task={t} showProjectSuffix />)}
                </GroupCard>
              ))}

              {reportsGroupMode === 'projects' && groupedProjects.map((p, idx) => (
                <GroupCard key={`p-${p.project_id ?? 'none'}-${idx}`} title={p.project_name} total={p.total_hours}>
                  <button onClick={() => openReportsProjectModal(p)} style={smallBtnStyle}>Детали проекта</button>
                  {p.users.map((u) => (
                    <div key={`pu-${u.user_id}`} style={{ marginTop: 6 }}>
                      <div style={{ fontSize: 12, color: '#6e6e73', marginBottom: 4 }}>{u.user_name} · {Number(u.total_hours).toFixed(2)} ч</div>
                      {u.tasks.map((t, i) => <TaskRow key={`${u.user_id}-${t.task_id}-${i}`} task={t} />)}
                    </div>
                  ))}
                </GroupCard>
              ))}

              {reportsGroupMode === 'departments' && groupedDepartments.map((d) => (
                <DeptCard key={`d-${d.id}`} node={d} depth={0} />
              ))}
            </div>
          )}
          {!isLoadingReports && !reportsError && reportView === 'occupancy' && (
            <div style={{ display: 'grid', gap: 12 }}>
              {occupancyProjects.map((p, idx) => (
                <GroupCard key={`occ-${p.project_id ?? 'none'}-${idx}`} title={p.project_name} total={p.total_hours}>
                  {(p.users || []).map((u) => (
                    <div key={`occ-u-${u.user_id}`} style={{ marginTop: 6 }}>
                      <div style={{ fontSize: 12, color: '#1d1d1f', fontWeight: 600 }}>
                        {u.user_name} · {Number(u.total_hours || 0).toFixed(2)} ч
                      </div>
                      {(u.tasks || []).map((t, i) => (
                        <TaskRow key={`occ-${u.user_id}-${t.task_id}-${i}`} task={t} />
                      ))}
                    </div>
                  ))}
                </GroupCard>
              ))}
            </div>
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

function DeptCard({ node, depth }) {
  return (
    <div style={{ ...sectionStyle, marginLeft: depth * 18 }}>
      <div style={{ fontSize: 13, color: '#6e6e73', fontWeight: 700, marginBottom: 8 }}>
        {node.name} · Итого: {Number(node.total_hours || 0).toFixed(2)} ч
      </div>
      {(node.users || []).map((u) => (
        <div key={`du-${u.user_id}`} style={{ marginBottom: 8, marginLeft: 12 }}>
          <div style={{ fontSize: 12, color: '#1d1d1f', fontWeight: 600 }}>{u.user_name} · {Number(u.total_hours || 0).toFixed(2)} ч</div>
          {(u.tasks || []).map((t, i) => <TaskRow key={`${u.user_id}-${t.task_id}-${i}`} task={t} showProjectSuffix />)}
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

