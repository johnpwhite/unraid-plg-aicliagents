import React, { useState, useEffect } from 'react';

const THEMES = {
    dark: '{"background":"#1e1e1e","foreground":"#ffffff"}',
    light: '{"background":"#ffffff","foreground":"#1e1e1e"}',
    solarized: '{"background":"#002b36","foreground":"#839496"}'
};

interface Session {
    id: string;
    name: string;
    path: string;
    lastActive: number;
}

export const GeminiTerminal: React.FC = () => {
    const [config, setConfig] = useState<any>(null);
    const [sessions, setSessions] = useState<Session[]>([]);
    const [activeId, setActiveId] = useState<string>('default');
    const [browserOpen, setBrowserOpen] = useState(false);
    const [currentPath, setCurrentPath] = useState<string>('');
    const [dirItems, setDirItems] = useState<any[]>([]);
    const [newDirName, setNewDirName] = useState('');
    const [isStarting, setIsStarting] = useState(false);

    // Initial Load
    useEffect(() => {
        fetch('/plugins/unraid-geminicli/GeminiAjax.php?action=debug')
            .then(r => r.json())
            .then(data => {
                if (data && data.config) {
                    setConfig(data.config);
                    const savedSessions = localStorage.getItem('gemini_sessions');
                    if (savedSessions) {
                        setSessions(JSON.parse(savedSessions));
                    } else {
                        const initial = [{ id: 'default', name: 'Main', path: data.config.root_path, lastActive: Date.now() }];
                        setSessions(initial);
                        localStorage.setItem('gemini_sessions', JSON.stringify(initial));
                    }
                }
            })
            .catch(e => console.error('Gemini Initial Load Error:', e));
    }, []);

    // Session Persistence
    useEffect(() => {
        if (sessions.length > 0) {
            localStorage.setItem('gemini_sessions', JSON.stringify(sessions));
        }
    }, [sessions]);

    // Ensure active session is running
    useEffect(() => {
        if (!activeId || !config) return;
        const session = sessions.find(s => s.id === activeId);
        if (!session) return;

        setIsStarting(true);
        const startUrl = `/plugins/unraid-geminicli/GeminiAjax.php?action=start&id=${activeId}&path=${encodeURIComponent(session.path)}`;
        console.log('[Gemini] Starting session:', activeId, startUrl);
        fetch(startUrl)
            .then(r => {
                console.log('[Gemini] Start response status:', r.status);
                return r.text();
            })
            .then(text => {
                console.log('[Gemini] Start response body:', text);
                // Give ttyd time to spin up — new sessions need more time
                setTimeout(() => setIsStarting(false), 2500);
            })
            .catch(e => {
                console.error('[Gemini] Start Error:', e);
                setIsStarting(false);
            });
    }, [activeId, config]);

    const browseTo = (path: string) => {
        const browseUrl = `/plugins/unraid-geminicli/GeminiAjax.php?action=list_dir&path=${encodeURIComponent(path)}`;
        console.log('[Gemini] Browsing to:', browseUrl);
        fetch(browseUrl)
            .then(r => {
                console.log('[Gemini] Browse response status:', r.status);
                return r.text();
            })
            .then(text => {
                console.log('[Gemini] Browse response body:', text.substring(0, 200));
                const data = JSON.parse(text);
                if (data.error) throw new Error(data.error);
                setCurrentPath(data.path);
                setDirItems(data.items);
            })
            .catch(e => console.error('[Gemini] Browse Error:', e));
    };

    const openBrowser = () => {
        try {
            const session = sessions.find(s => s.id === activeId);
            browseTo(session?.path || config?.root_path || '/mnt');
            setBrowserOpen(true);
        } catch (e) {
            console.error('Gemini OpenBrowser Error:', e);
        }
    };

    const confirmWorkspace = () => {
        const name = currentPath.split('/').pop() || 'Workspace';
        // Short safe alphanumeric ID for NGINX compatibility
        const newId = 's' + Math.random().toString(36).substring(2, 7);
        const newSessions = [...sessions, { id: newId, name: name, path: currentPath, lastActive: Date.now() }];
        setSessions(newSessions);
        setActiveId(newId);
        setBrowserOpen(false);
    };

    const createFolder = () => {
        if (!newDirName) {
            console.log('[Gemini] CreateFolder: empty name, returning');
            return;
        }
        console.log('[Gemini] CreateFolder: creating "' + newDirName + '" in ' + currentPath);
        const csrfToken = (window as any).csrf_token || '';
        console.log('[Gemini] CreateFolder: CSRF token length:', csrfToken.length, 'present:', !!csrfToken);

        // Use GET — Unraid NGINX does not handle POST to .php files
        const createUrl = `/plugins/unraid-geminicli/GeminiAjax.php?action=create_dir&parent=${encodeURIComponent(currentPath)}&name=${encodeURIComponent(newDirName)}&csrf_token=${encodeURIComponent(csrfToken)}`;
        console.log('[Gemini] CreateFolder: fetching', createUrl);
        fetch(createUrl)
            .then(r => {
                console.log('[Gemini] CreateFolder: response status:', r.status, r.statusText);
                return r.text();
            })
            .then(text => {
                console.log('[Gemini] CreateFolder: response body:', text);
                try {
                    const data = JSON.parse(text);
                    if (data.status === 'ok') {
                        console.log('[Gemini] CreateFolder: SUCCESS');
                        setNewDirName('');
                        browseTo(currentPath);
                    } else {
                        console.error('[Gemini] CreateFolder: ERROR response:', data);
                        alert('Error creating folder: ' + (data.message || data.error || 'unknown error'));
                    }
                } catch (parseErr) {
                    console.error('[Gemini] CreateFolder: JSON parse failed on:', text.substring(0, 500));
                    alert('Server returned invalid response. Check browser console for details.');
                }
            })
            .catch(e => {
                console.error('[Gemini] CreateFolder: fetch error:', e);
                alert('Network error creating folder: ' + e.message);
            });
    };

    const closeTab = (e: React.MouseEvent, id: string) => {
        e.stopPropagation();
        if (id === 'default') return;
        const filtered = sessions.filter(s => s.id !== id);
        setSessions(filtered);
        if (activeId === id) setActiveId('default');
        fetch(`/plugins/unraid-geminicli/GeminiAjax.php?action=stop&id=${id}&hard=1`);
    };

    if (!config) {
        return (
            <div style={styles.loading}>
                Initializing Gemini Session...
            </div>
        );
    }

    const activeSession = sessions.find(s => s.id === activeId);
    const themeJson = THEMES[config.theme as keyof typeof THEMES] || THEMES.dark;
    const themeParams = encodeURIComponent(themeJson);
    const terminalUrl = `/webterminal/geminiterm-${activeId}/?theme=${themeParams}&fontSize=${config.font_size}&fontFamily=monospace&disableLeaveAlert=true&v=${activeSession?.lastActive || Date.now()}`;

    return (
        <div style={styles.root}>
            {/* Action Toolbar — sits in whitespace above tabs */}
            <div style={styles.toolbar}>
                <button onClick={openBrowser} style={styles.toolbarBtn}>
                    <i className="fa fa-plus-circle" style={{ color: 'var(--orange, #e68a00)' }}></i>
                    New Workspace
                </button>
                <button
                    onClick={() => {
                        const newSessions = sessions.map(s => s.id === activeId ? { ...s, lastActive: Date.now() } : s);
                        setSessions(newSessions);
                        fetch(`/plugins/unraid-geminicli/GeminiAjax.php?action=restart&id=${activeId}&path=${encodeURIComponent(activeSession?.path || '')}`);
                    }}
                    style={{ ...styles.toolbarBtn, width: 28, paddingLeft: 0, paddingRight: 0 }}
                    title="Restart Session"
                >
                    <i className="fa fa-refresh"></i>
                </button>
            </div>

            {/* Slim Tab Strip */}
            <div style={styles.header}>
                <div style={styles.tabStrip}>
                    {sessions.map(s => {
                        const displayName = s.id === 'default'
                            ? (s.path === config?.root_path ? 'Main' : s.path.split('/').pop() || 'Main')
                            : s.name;
                        const isActive = activeId === s.id;
                        return (
                            <div
                                key={s.id}
                                onClick={() => setActiveId(s.id)}
                                style={{
                                    ...styles.tab,
                                    ...(isActive ? styles.tabActive : styles.tabInactive),
                                }}
                                title={s.path}
                            >
                                <i className={`fa ${s.id === 'default' ? 'fa-home' : 'fa-folder-open'}`} style={{ opacity: isActive ? 1 : 0.6, fontSize: 11, flexShrink: 0 }}></i>
                                <span style={styles.tabLabel}>{displayName}</span>
                                {s.id !== 'default' && (
                                    <i
                                        className="fa fa-times"
                                        style={styles.tabClose}
                                        onClick={(e) => closeTab(e, s.id)}
                                    ></i>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* Terminal Viewport */}
            <div style={styles.viewport}>
                {isStarting && (
                    <div style={styles.startingOverlay}>
                        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 8 }}>
                            <i className="fa fa-circle-o-notch fa-spin" style={{ fontSize: 24, color: 'var(--orange, #e68a00)' }}></i>
                            <span style={{ fontSize: 12, fontFamily: 'monospace', opacity: 0.6, textTransform: 'uppercase' as const, letterSpacing: '0.1em' }}>Waking Gemini...</span>
                        </div>
                    </div>
                )}
                {!isStarting && (
                    <iframe
                        key={activeId + (activeSession?.lastActive || '')}
                        src={terminalUrl}
                        style={styles.iframe}
                        title="Gemini Terminal"
                    />
                )}
            </div>

            {/* Workspace Browser Modal */}
            {browserOpen && (
                <div style={styles.modalBackdrop}>
                    <div style={styles.modalBox}>
                        {/* Modal Header */}
                        <div style={styles.modalHeader}>
                            <span style={styles.modalTitle}>
                                <i className="fa fa-folder-open" style={{ color: 'var(--orange, #e68a00)' }}></i>
                                {' '}Select Workspace
                            </span>
                        </div>

                        {/* Modal Body */}
                        <div style={styles.modalBody}>
                            <div style={styles.pathBar}>
                                <i className="fa fa-hdd-o"></i>
                                {currentPath}
                            </div>

                            <div style={styles.dirList}>
                                {dirItems.map((item, i) => (
                                    <div
                                        key={i}
                                        onClick={() => browseTo(item.path)}
                                        style={styles.dirItem}
                                        onMouseEnter={e => (e.currentTarget.style.backgroundColor = 'var(--title-header-background-color, rgba(0,0,0,0.08))')}
                                        onMouseLeave={e => (e.currentTarget.style.backgroundColor = 'transparent')}
                                    >
                                        <i className={`fa ${item.name === '..' ? 'fa-level-up' : 'fa-folder'}`} style={{ color: item.name === '..' ? 'inherit' : 'var(--orange, #e68a00)', opacity: 0.7 }}></i>
                                        <span>{item.name}</span>
                                    </div>
                                ))}
                            </div>

                            <div style={styles.createRow}>
                                <input
                                    type="text"
                                    placeholder="New Folder..."
                                    value={newDirName}
                                    onChange={(e) => setNewDirName(e.target.value)}
                                    style={styles.createInput}
                                />
                                <button onClick={createFolder} style={styles.createBtn}>
                                    Create
                                </button>
                            </div>
                        </div>

                        {/* Modal Footer */}
                        <div style={styles.modalFooter}>
                            <button onClick={() => setBrowserOpen(false)} style={styles.cancelBtn}>
                                Cancel
                            </button>
                            <button onClick={confirmWorkspace} style={styles.openBtn}>
                                Open Workspace
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

/* ─── Inline styles using Unraid CSS variables ─── */
const styles: Record<string, React.CSSProperties> = {
    root: {
        flex: 1,
        display: 'flex',
        flexDirection: 'column',
        overflow: 'hidden',
        height: '100%',
        position: 'relative',
        fontFamily: 'inherit',
        fontSize: 13,
        color: 'var(--text-color, inherit)',
        backgroundColor: 'var(--content-background-color, var(--body-background, #f5f5f5))',
    },
    loading: {
        flex: 1,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontFamily: 'monospace',
        fontSize: 14,
        opacity: 0.5,
        textTransform: 'uppercase' as const,
        letterSpacing: '0.2em',
    },

    /* ── Toolbar (above tabs, in whitespace) ── */
    toolbar: {
        display: 'flex',
        justifyContent: 'flex-end',
        alignItems: 'center',
        gap: 4,
        padding: '1px 8px',
    },
    toolbarBtn: {
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        gap: 5,
        padding: '0 10px',
        height: 24,
        fontSize: 11,
        fontWeight: 700,
        textTransform: 'uppercase' as const,
        border: '1px solid var(--button-border, var(--border-color, #bbb))',
        borderRadius: 3,
        backgroundColor: 'var(--button-background, var(--mild-background-color, #e8e8e8))',
        color: 'var(--button-text-color, inherit)',
        cursor: 'pointer',
        transition: 'all 0.15s',
    },

    /* ── Tab Header (slim) ── */
    header: {
        display: 'flex',
        alignItems: 'flex-end',
        padding: '3px 8px 0',
        backgroundColor: 'var(--title-header-background-color, var(--mild-background-color, #ededed))',
        borderBottom: '1px solid var(--border-color, #ccc)',
        userSelect: 'none',
        overflow: 'hidden',
    },
    tabStrip: {
        display: 'flex',
        alignItems: 'flex-end',
        gap: 2,
        overflow: 'hidden',
        flex: 1,
        minWidth: 0,
    },
    tab: {
        display: 'flex',
        alignItems: 'center',
        gap: 4,
        padding: '4px 10px',
        borderRadius: '4px 4px 0 0',
        cursor: 'pointer',
        fontSize: 12,
        fontWeight: 700,
        textTransform: 'uppercase' as const,
        letterSpacing: '-0.02em',
        maxWidth: 180,
        flexShrink: 1,
        minWidth: 0,
        marginBottom: -1,
        transition: 'all 0.15s',
        borderTop: '1px solid transparent',
        borderLeft: '1px solid transparent',
        borderRight: '1px solid transparent',
    },
    tabLabel: {
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap',
        flex: 1,
        minWidth: 0,
    },
    tabActive: {
        backgroundColor: 'var(--content-background-color, var(--body-background, #fff))',
        borderColor: 'var(--border-color, #ccc)',
        color: 'var(--orange, #e68a00)',
    },
    tabInactive: {
        backgroundColor: 'transparent',
        color: 'inherit',
        opacity: 0.55,
        borderColor: 'var(--border-color, #ccc)',
    },
    tabClose: {
        marginLeft: 4,
        opacity: 0.4,
        cursor: 'pointer',
        transition: 'opacity 0.15s',
        flexShrink: 0,
    },

    /* ── Terminal ── */
    viewport: {
        flex: 1,
        width: '100%',
        position: 'relative',
        overflow: 'hidden',
        height: '100%',
        zIndex: 0,
    },
    startingOverlay: {
        position: 'absolute',
        inset: 0,
        zIndex: 10,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: 'var(--content-background-color, rgba(255,255,255,0.85))',
        backdropFilter: 'blur(4px)',
    },
    iframe: {
        position: 'absolute',
        inset: 0,
        width: '100%',
        height: '100%',
        border: 'none',
    },

    /* ── Modal ── */
    modalBackdrop: {
        position: 'fixed',
        inset: 0,
        zIndex: 99999,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: 'rgba(0,0,0,0.5)',
        backdropFilter: 'blur(6px)',
    },
    modalBox: {
        width: 500,
        borderRadius: 8,
        overflow: 'hidden',
        boxShadow: '0 20px 60px rgba(0,0,0,0.3)',
        border: '1px solid var(--border-color, #ccc)',
        backgroundColor: 'var(--content-background-color, var(--body-background, #fff))',
        color: 'var(--text-color, inherit)',
    },
    modalHeader: {
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        padding: '8px 14px',
        backgroundColor: 'var(--title-header-background-color, var(--mild-background-color, #ededed))',
        borderBottom: '1px solid var(--border-color, #ccc)',
    },
    modalTitle: {
        fontWeight: 700,
        fontSize: 13,
        textTransform: 'uppercase' as const,
        letterSpacing: '0.05em',
        display: 'flex',
        alignItems: 'center',
        gap: 8,
    },
    modalBody: {
        padding: '12px 14px',
    },
    pathBar: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        padding: '6px 10px',
        marginBottom: 12,
        fontSize: 12,
        fontFamily: 'monospace',
        opacity: 0.65,
        borderRadius: 4,
        border: '1px solid var(--border-color, #ccc)',
        backgroundColor: 'var(--mild-background-color, rgba(0,0,0,0.03))',
    },
    dirList: {
        height: 250,
        overflowY: 'auto',
        borderRadius: 4,
        border: '1px solid var(--border-color, #ccc)',
        marginBottom: 12,
    },
    dirItem: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '8px 12px',
        cursor: 'pointer',
        fontSize: 13,
        transition: 'background-color 0.15s',
        borderBottom: '1px solid var(--border-color, rgba(0,0,0,0.06))',
    },
    createRow: {
        display: 'flex',
        alignItems: 'center',
        gap: 6,
    },
    createInput: {
        flex: 1,
        height: 28,
        padding: '0 8px',
        fontSize: 12,
        border: '1px solid var(--border-color, #ccc)',
        borderRadius: 3,
        backgroundColor: 'var(--input-bg-color, var(--mild-background-color, #fff))',
        color: 'inherit',
        outline: 'none',
    },
    createBtn: {
        height: 28,
        padding: '0 12px',
        fontSize: 11,
        fontWeight: 700,
        textTransform: 'uppercase' as const,
        border: '1px solid var(--button-border, var(--border-color, #bbb))',
        borderRadius: 3,
        backgroundColor: 'var(--button-background, var(--mild-background-color, #e8e8e8))',
        color: 'var(--button-text-color, inherit)',
        cursor: 'pointer',
        transition: 'all 0.15s',
    },
    modalFooter: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 6,
        padding: '8px 14px',
        backgroundColor: 'var(--title-header-background-color, var(--mild-background-color, #ededed))',
        borderTop: '1px solid var(--border-color, #ccc)',
    },
    cancelBtn: {
        padding: '4px 12px',
        fontSize: 11,
        fontWeight: 700,
        textTransform: 'uppercase' as const,
        backgroundColor: 'transparent',
        border: '1px solid var(--border-color, #ccc)',
        borderRadius: 3,
        color: 'inherit',
        cursor: 'pointer',
        opacity: 0.7,
        transition: 'all 0.15s',
    },
    openBtn: {
        padding: '4px 16px',
        fontSize: 11,
        fontWeight: 900,
        textTransform: 'uppercase' as const,
        backgroundColor: 'var(--orange, #e68a00)',
        border: 'none',
        borderRadius: 3,
        color: '#fff',
        cursor: 'pointer',
        transition: 'all 0.15s',
        boxShadow: '0 2px 8px rgba(0,0,0,0.15)',
    },
};
