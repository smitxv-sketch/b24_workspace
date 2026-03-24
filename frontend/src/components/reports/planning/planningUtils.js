export function intensityToColor(intensity) {
  const v = Math.max(0, Math.min(1, Number(intensity || 0)))
  // От светло-голубого к тёмно-синему.
  const start = { r: 224, g: 240, b: 255 }
  const end = { r: 0, g: 113, b: 227 }
  const r = Math.round(start.r + (end.r - start.r) * v)
  const g = Math.round(start.g + (end.g - start.g) * v)
  const b = Math.round(start.b + (end.b - start.b) * v)
  return `rgb(${r},${g},${b})`
}

export function pickCell(cells, index) {
  return (cells || []).find(c => Number(c.index) === Number(index)) || null
}

