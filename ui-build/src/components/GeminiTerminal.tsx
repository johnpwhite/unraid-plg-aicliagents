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
    title?: string;
    chatSessionId?: string;
}

export const GeminiTerminal: React.FC = () => {
    const [config, setConfig] = useState<any>(null);
    const [sessions, setSessions] = useState<Session[]>([]);
    const [activeId, setActiveId] = useState<string>(() => {
        return localStorage.getItem('gemini_active_id') || 'default';
    });
    const [hoveredId, setHoveredId] = useState<string | null>(null);

    useEffect(() => {
        if (activeId) {
            localStorage.setItem('gemini_active_id', activeId);
        }
    }, [activeId]);

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
                    let initial = [{ id: 'default', name: 'Main', path: data.config.root_path, lastActive: Date.now(), title: '', chatSessionId: '' }];
                    
                    if (savedSessions) {
                        const parsed = JSON.parse(savedSessions);
                        if (parsed.length > 0) {
                            initial = parsed;
                        }
                    }
                    
                    // Update initial sessions with their chat IDs from backend
                    Promise.all(initial.map(s => 
                        fetch(`/plugins/unraid-geminicli/GeminiAjax.php?action=get_chat_session&path=${encodeURIComponent(s.path)}`)
                            .then(r => r.json())
                            .then(cData => ({ ...s, chatSessionId: cData.chatId || '' }))
                            .catch(() => s)
                    )).then(updated => {
                        setSessions(updated);
                        localStorage.setItem('gemini_sessions', JSON.stringify(updated));
                    });
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

    // Dynamic Title Polling
    useEffect(() => {
        if (!sessions.length) return;
        
        const poll = () => {
            sessions.forEach(s => {
                fetch(`/plugins/unraid-geminicli/GeminiAjax.php?action=get_title&id=${s.id}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.status === 'ok' && data.title && data.title !== s.title) {
                            setSessions(prev => prev.map(ps => 
                                ps.id === s.id ? { ...ps, title: data.title } : ps
                            ));
                        }
                    })
                    .catch(() => {}); // Silent fail for polling
            });
        };

        const timer = setInterval(poll, 4000);
        poll(); // Initial check
        return () => clearInterval(timer);
    }, [sessions.map(s => s.id).join(',')]); // Re-run if session IDs change

    // Ensure active session is running
    useEffect(() => {
        if (!activeId || !config) return;
        const session = sessions.find(s => s.id === activeId);
        if (!session) return;

        setIsStarting(true);
        const startUrl = `/plugins/unraid-geminicli/GeminiAjax.php?action=start&id=${activeId}&path=${encodeURIComponent(session.path)}&chatId=${encodeURIComponent(session.chatSessionId || '')}`;
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
    }, [activeId, config, sessions.find(s => s.id === activeId)?.path, sessions.find(s => s.id === activeId)?.lastActive, sessions.find(s => s.id === activeId)?.chatSessionId]);

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
        let name = currentPath.split('/').pop() || 'Workspace';
        if (currentPath === '/') name = 'Root';
        // Short safe alphanumeric ID for NGINX compatibility
        const newId = 's' + Math.random().toString(36).substring(2, 7);
        
        fetch(`/plugins/unraid-geminicli/GeminiAjax.php?action=get_chat_session&path=${encodeURIComponent(currentPath)}`)
            .then(r => r.json())
            .then(data => {
                const newSessions = [...sessions, { 
                    id: newId, 
                    name: name, 
                    path: currentPath, 
                    lastActive: Date.now(),
                    chatSessionId: data.chatId || ''
                }];
                setSessions(newSessions);
                setActiveId(newId);
                setBrowserOpen(false);
            })
            .catch(() => {
                const newSessions = [...sessions, { id: newId, name: name, path: currentPath, lastActive: Date.now(), chatSessionId: '' }];
                setSessions(newSessions);
                setActiveId(newId);
                setBrowserOpen(false);
            });
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
        
        const index = sessions.findIndex(s => s.id === id);
        const filtered = sessions.filter(s => s.id !== id);
        
        let nextId = activeId;
        if (activeId === id) {
            if (filtered.length > 0) {
                // Switch to the next tab, or the previous if we were at the end
                const nextIndex = Math.min(index, filtered.length - 1);
                nextId = filtered[nextIndex].id;
            } else {
                // Last tab closed, create new default
                const newDefault = { 
                    id: 'default', 
                    name: 'Main', 
                    path: config.root_path, 
                    lastActive: Date.now(), 
                    title: '' 
                };
                filtered.push(newDefault);
                nextId = 'default';
            }
        }

        setSessions(filtered);
        setActiveId(nextId);
        fetch(`/plugins/unraid-geminicli/GeminiAjax.php?action=stop&id=${id}&hard=1`);
    };

    const [drawerOpen, setDrawerOpen] = useState(false);
    
    // DRAGGABLE TAB POSITION
    const [tabBottom, setTabBottom] = useState(() => {
        const saved = localStorage.getItem('gemini_tab_y');
        return saved ? parseInt(saved, 10) : 20;
    });
    const [isDragging, setIsDragging] = useState(false);
    const [dragStart, setDragStart] = useState<{ y: number, bottom: number } | null>(null);

    useEffect(() => {
        if (!dragStart) return;
        const handleMouseMove = (e: MouseEvent) => {
            const deltaY = dragStart.y - e.clientY;
            if (!isDragging && Math.abs(deltaY) > 5) {
                setIsDragging(true);
            }
            if (isDragging || Math.abs(deltaY) > 5) {
                const newBottom = dragStart.bottom + deltaY;
                const clamped = Math.max(10, Math.min(window.innerHeight - 60, newBottom));
                setTabBottom(clamped);
            }
        };
        const handleMouseUp = () => {
            if (isDragging) {
                localStorage.setItem('gemini_tab_y', tabBottom.toString());
            }
            // Use a small timeout to ensure onClick handler can check isDragging before it's reset
            setTimeout(() => {
                setIsDragging(false);
                setDragStart(null);
            }, 50);
        };
        window.addEventListener('mousemove', handleMouseMove);
        window.addEventListener('mouseup', handleMouseUp);
        return () => {
            window.removeEventListener('mousemove', handleMouseMove);
            window.removeEventListener('mouseup', handleMouseUp);
        };
    }, [dragStart, isDragging, tabBottom]);

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
            {/* Horizontal Drawer (Left) */}
            <div style={{
                ...styles.drawer,
                transform: drawerOpen ? 'translateX(0)' : 'translateX(-100%)',
            }}>
                <div style={styles.drawerContent}>
                    {/* Top Section: New Workspace */}
                    <div style={styles.drawerTop}>
                        <button onClick={() => { openBrowser(); setDrawerOpen(false); }} style={styles.drawerBtnPrimary}>
                            <i className="fa fa-plus-circle"></i>
                            New Workspace
                        </button>
                    </div>

                    {/* Middle Section: Tabs (Scrollable) */}
                    <div style={styles.drawerTabs}>
                        {sessions.map(s => {
                            const baseName = s.id === 'default'
                                ? (s.path === config?.root_path ? 'Main' : s.path.split('/').pop() || 'Main')
                                : s.name;
                            const displayName = s.title || baseName;
                            const isActive = activeId === s.id;
                            return (
                                <div
                                    key={s.id}
                                    onClick={() => { setActiveId(s.id); setDrawerOpen(false); }}
                                    onMouseEnter={() => setHoveredId(s.id)}
                                    onMouseLeave={() => setHoveredId(null)}
                                    style={{
                                        ...styles.drawerTab,
                                        ...(isActive ? styles.drawerTabActive : {}),
                                        position: 'relative',
                                    }}
                                >
                                    <i className={`fa ${s.id === 'default' ? 'fa-home' : 'fa-folder-open'}`} style={{ fontSize: 14, opacity: isActive ? 1 : 0.6 }}></i>
                                    <span style={styles.drawerTabLabel}>{displayName}</span>
                                    <i
                                        className="fa fa-times"
                                        style={styles.drawerTabClose}
                                        onClick={(e) => closeTab(e, s.id)}
                                    ></i>

                                    {/* Metadata Overlay Card */}
                                    {hoveredId === s.id && (
                                        <div style={styles.tabOverlay}>
                                            <div style={styles.overlayRow}>
                                                <i className="fa fa-folder" style={styles.overlayIcon}></i>
                                                <span style={styles.overlayText}>{s.path}</span>
                                            </div>
                                            {s.chatSessionId && (
                                                <div style={styles.overlayRow}>
                                                    <i className="fa fa-comments" style={styles.overlayIcon}></i>
                                                    <span style={styles.overlayText}>
                                                        Gemini: <span style={{ fontFamily: 'monospace', fontSize: 11, opacity: 0.8 }}>{s.chatSessionId}</span>
                                                    </span>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>

                    {/* Bottom Section: Sync & Settings */}
                    <div style={styles.drawerBottom}>
                        <button
                            onClick={() => {
                                const newSessions = sessions.map(s => s.id === activeId ? { ...s, lastActive: Date.now() } : s);
                                setSessions(newSessions);
                                fetch(`/plugins/unraid-geminicli/GeminiAjax.php?action=restart&id=${activeId}&path=${encodeURIComponent(activeSession?.path || '')}`);
                                setDrawerOpen(false);
                            }}
                            style={styles.drawerBtn}
                            title="Restart Session"
                        >
                            <i className="fa fa-refresh"></i>
                            Sync / Restart
                        </button>
                        <button
                            onClick={() => window.location.href = '/Settings/GeminiSettings'}
                            style={styles.drawerBtn}
                            title="Plugin Settings"
                        >
                            <i className="fa fa-cog"></i>
                            Settings
                        </button>
                    </div>
                </div>

                {/* Sticking out Tab Bottom Left */}
                <div 
                    onClick={() => {
                        if (!isDragging) setDrawerOpen(!drawerOpen);
                    }}
                    onMouseDown={(e) => {
                        setDragStart({ y: e.clientY, bottom: tabBottom });
                        e.preventDefault();
                    }}
                    style={{
                        ...styles.drawerToggle,
                        bottom: tabBottom,
                        cursor: isDragging ? 'grabbing' : 'grab',
                        transition: isDragging ? 'none' : 'transform 0.3s, bottom 0.3s'
                    }}
                >
                    <i className={`fa ${drawerOpen ? 'fa-chevron-left' : 'fa-bars'}`} style={{ fontSize: 14 }}></i>
                </div>
            </div>

            {/* Terminal Viewport - Stretched to fill */}
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

    /* ── Drawer (Left) ── */
    drawer: {
        position: 'absolute',
        left: 0,
        top: 0,
        bottom: 0,
        width: 240,
        zIndex: 1000,
        backgroundColor: 'var(--content-background-color, var(--body-background, #fff))',
        borderRight: '1px solid var(--border-color, #ccc)',
        transition: 'transform 0.3s ease-in-out',
        display: 'flex',
        flexDirection: 'column',
        boxShadow: '10px 0 30px rgba(0,0,0,0.1)',
    },
    drawerContent: {
        flex: 1,
        display: 'flex',
        flexDirection: 'column',
        height: '100%',
        overflow: 'hidden',
    },
    drawerTop: {
        padding: '20px 16px 10px',
        borderBottom: '1px solid var(--border-color, #eee)',
    },
    drawerBottom: {
        padding: '10px 16px 20px',
        borderTop: '1px solid var(--border-color, #eee)',
        display: 'flex',
        flexDirection: 'column',
        gap: 8,
    },
    drawerTabs: {
        flex: 1,
        overflowY: 'auto',
        padding: '10px 0',
    },
    drawerTab: {
        display: 'flex',
        alignItems: 'center',
        gap: 12,
        padding: '12px 20px',
        cursor: 'pointer',
        fontSize: 13,
        fontWeight: 500,
        transition: 'all 0.2s',
        color: 'var(--text-color, inherit)',
        opacity: 0.8,
        borderLeft: '4px solid transparent',
        position: 'relative' as const,
    },
    tabOverlay: {
        position: 'absolute' as const,
        left: '100%',
        top: '50%',
        transform: 'translateY(-50%)',
        marginLeft: 12,
        width: 320,
        padding: '12px 14px',
        backgroundColor: 'var(--content-background-color, var(--body-background, #fff))',
        border: '1px solid var(--border-color, #ccc)',
        borderRadius: 8,
        boxShadow: '0 10px 30px rgba(0,0,0,0.15)',
        zIndex: 2000,
        pointerEvents: 'none' as const,
        display: 'flex',
        flexDirection: 'column' as const,
        gap: 8,
    },
    overlayRow: {
        display: 'flex',
        alignItems: 'flex-start',
        gap: 10,
        fontSize: 11,
        lineHeight: '1.4em',
    },
    overlayIcon: {
        fontSize: 12,
        color: 'var(--orange, #e68a00)',
        width: 14,
        textAlign: 'center' as const,
        marginTop: 2,
    },
    overlayText: {
        flex: 1,
        wordBreak: 'break-all' as const,
        opacity: 0.9,
    },
    drawerTabActive: {
        backgroundColor: 'var(--mild-background-color, rgba(0,0,0,0.03))',
        color: 'var(--orange, #e68a00)',
        opacity: 1,
        borderLeftColor: 'var(--orange, #e68a00)',
        fontWeight: 700,
    },
    drawerTabLabel: {
        flex: 1,
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap',
    },
    drawerTabClose: {
        opacity: 0.3,
        padding: 4,
        fontSize: 12,
    },
    drawerBtnPrimary: {
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        gap: 8,
        width: '100%',
        height: 40,
        fontSize: 12,
        fontWeight: 700,
        textTransform: 'uppercase' as const,
        border: 'none',
        borderRadius: 6,
        backgroundColor: 'var(--orange, #e68a00)',
        color: '#fff',
        cursor: 'pointer',
        boxShadow: '0 4px 12px rgba(230, 138, 0, 0.2)',
    },
    drawerBtn: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        width: '100%',
        padding: '10px 12px',
        fontSize: 12,
        fontWeight: 600,
        border: '1px solid var(--border-color, #ccc)',
        borderRadius: 4,
        backgroundColor: 'var(--button-background, transparent)',
        color: 'var(--text-color, inherit)',
        cursor: 'pointer',
    },
    drawerToggle: {
        position: 'absolute',
        left: '100%',
        bottom: 20,
        width: 32,
        height: 48,
        backgroundColor: 'var(--content-background-color, var(--body-background, #fff))',
        border: '1px solid var(--border-color, #ccc)',
        borderLeft: 'none',
        borderRadius: '0 8px 8px 0',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        cursor: 'pointer',
        boxShadow: '4px 0 12px rgba(0,0,0,0.05)',
        color: 'var(--orange, #e68a00)',
        zIndex: 1001,
    },

    /* ── Terminal ── */
    viewport: {
        flex: 1,
        width: '100%',
        position: 'relative',
        overflow: 'hidden',
        height: '100%',
        zIndex: 0,
        backgroundColor: '#000',
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
        overflow: 'hidden',
    },
    iframe: {
        position: 'absolute',
        inset: 0,
        width: '100%',
        height: '100%',
        border: 'none',
        overflow: 'hidden',
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
