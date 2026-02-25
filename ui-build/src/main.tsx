import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'

const rootId = 'gemini-cli-root';
const el = document.getElementById(rootId);

if (el) {
  createRoot(el).render(
    <StrictMode>
      <App />
    </StrictMode>
  );
}
