import React from 'react'

export function DetailsModal({ title, open, onClose, children }) {
  if (!open) return null
  return (
    <div style={overlayStyle} onClick={onClose}>
      <div style={modalStyle} onClick={(e) => e.stopPropagation()}>
        <div style={headStyle}>
          <div style={{ fontSize: 16, fontWeight: 700 }}>{title}</div>
          <button onClick={onClose} style={closeStyle}>Закрыть</button>
        </div>
        <div style={{ marginTop: 10, maxHeight: '70vh', overflow: 'auto' }}>
          {children}
        </div>
      </div>
    </div>
  )
}

const overlayStyle = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,.24)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000 }
const modalStyle = { width: 'min(980px, 92vw)', background: '#fff', borderRadius: 14, border: '1px solid #e9e9ed', padding: 14 }
const headStyle = { display: 'flex', alignItems: 'center', justifyContent: 'space-between' }
const closeStyle = { border: '1px solid #d2d2d7', background: '#fff', borderRadius: 8, fontSize: 12, padding: '6px 10px', cursor: 'pointer' }

