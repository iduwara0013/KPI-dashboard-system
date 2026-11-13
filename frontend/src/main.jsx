import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './app/App.jsx';
import ThemeToggle from './components/ThemeToggle.jsx';
import './styles/styles.css';
import './styles/premium.css';
import './styles/workspace-navigation.css';
import './styles/theme.css';
import './styles/page-heading.css';
import './styles/scan-friendly.css';

createRoot(document.getElementById('root')).render(<React.StrictMode><App /><ThemeToggle /></React.StrictMode>);
