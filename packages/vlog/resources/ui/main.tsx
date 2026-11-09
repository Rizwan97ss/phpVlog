import { render } from 'preact';
import './app.css';
import { App } from './components/App';
import type { VlogConfig } from './types';

/**
 * Read the settings the server wrote into the page.
 */
function readConfig(): VlogConfig {
    const element = document.getElementById('vlog-config');
    const config = JSON.parse(element?.textContent ?? '{}') as Partial<VlogConfig>;

    return {
        apiUrl: config.apiUrl ?? './api',
        title: config.title ?? 'Vlog',
        canManage: config.canManage === true,
        canPreviewSource: config.canPreviewSource === true,
        csrfToken: typeof config.csrfToken === 'string' ? config.csrfToken : null,
        editor: typeof config.editor === 'string' ? config.editor : null,
    };
}

const root = document.getElementById('vlog');

if (root !== null) {
    render(<App config={readConfig()} />, root);
}
