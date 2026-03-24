import React from 'react'

function DeptNode({ node, selectedDeptId, onSelect, level = 0, expandedIds = [], onToggleExpand }) {
  const isSelected = Number(node.id) === Number(selectedDeptId)
  const hasChildren = (node.children || []).length > 0
  const isExpanded = expandedIds.includes(node.id)
  return (
    <div>
      <div style={{ display: 'grid', gridTemplateColumns: '18px 1fr', alignItems: 'center', gap: 4 }}>
        <button
          onClick={() => hasChildren && onToggleExpand(node.id)}
          style={{ border: 'none', background: 'transparent', cursor: hasChildren ? 'pointer' : 'default', color: '#8e8e93', fontSize: 11, lineHeight: '11px' }}
          aria-label="toggle"
        >
          {hasChildren ? (isExpanded ? '▾' : '▸') : ''}
        </button>
        <button
          onClick={() => onSelect(node.id)}
          style={{
            width: '100%',
            textAlign: 'left',
            border: 'none',
            background: isSelected ? 'rgba(0,113,227,.08)' : 'transparent',
            color: isSelected ? '#0071e3' : '#1d1d1f',
            padding: '7px 8px',
            paddingLeft: 8 + level * 12,
            borderRadius: 10,
            cursor: 'pointer',
            fontSize: 13,
            fontWeight: isSelected ? 600 : 500,
          }}
        >
          {node.name}
        </button>
      </div>
      {hasChildren && isExpanded && (node.children || []).map(child => (
        <DeptNode
          key={child.id}
          node={child}
          selectedDeptId={selectedDeptId}
          onSelect={onSelect}
          level={level + 1}
          expandedIds={expandedIds}
          onToggleExpand={onToggleExpand}
        />
      ))}
    </div>
  )
}

export function DeptTree({ tree, selectedDeptId, onSelect, expandedIds, onToggleExpand }) {
  return (
    <div style={{ border: '1px solid #e9e9ed', borderRadius: 12, background: '#fff', padding: 8 }}>
      {(tree || []).map(node => (
        <DeptNode
          key={node.id}
          node={node}
          selectedDeptId={selectedDeptId}
          onSelect={onSelect}
          expandedIds={expandedIds}
          onToggleExpand={onToggleExpand}
        />
      ))}
    </div>
  )
}

