import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './App.jsx'
import './index.css'

// Инициализация BX24 если в iframe
if (window.BX24) {
  window.BX24.init(() => {
    ReactDOM.createRoot(document.getElementById('root')).render(
      <React.StrictMode><App /></React.StrictMode>
    )
  })
} else {
  // Dev-режим вне iframe
  ReactDOM.createRoot(document.getElementById('root')).render(
    <React.StrictMode><App /></React.StrictMode>
  )
}
