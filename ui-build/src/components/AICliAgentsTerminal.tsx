import React, { useState, useEffect, useRef } from 'react';

const THEMES = {
    dark: '{"background":"#1e1e1e","foreground":"#ffffff"}',
    light: '{"background":"#ffffff","foreground":"#1e1e1e"}',
    solarized: '{"background":"#002b36","foreground":"#839496"}'
};

interface Session {
    id: string;
    name: string;
    path: string;
    agentId: string;
    lastActive: number;
    title?: string;
    chatSessionId?: string;
}

// D-20: Fallback registry — backend response takes priority when available
const AGENT_REGISTRY_FALLBACK: Record<string, { name: string, icon: string }> = {
    'gemini-cli': { name: 'Gemini CLI', icon: '/plugins/unraid-aicliagents/assets/icons/google-gemini.png?v=stable' },
    'claude-code': { name: 'Claude', icon: '/plugins/unraid-aicliagents/assets/icons/claude.ico' },
    'opencode': { name: 'OpenCode', icon: '/plugins/unraid-aicliagents/assets/icons/opencode.ico' },
    'kilocode': { name: 'Kilo Code', icon: '/plugins/unraid-aicliagents/assets/icons/kilocode.ico' },
    'pi-coder': { name: 'Pi Coder', icon: '/plugins/unraid-aicliagents/assets/icons/picoder.png' },
    'codex-cli': { name: 'Codex CLI', icon: '/plugins/unraid-aicliagents/assets/icons/codex.png' },
    'factory-cli': { name: 'Factory', icon: '/plugins/unraid-aicliagents/assets/icons/factory.png' },
    'nanocoder': { name: 'NanoCoder', icon: '/plugins/unraid-aicliagents/assets/icons/nanocoder.png' }
};

export const AICliAgentsTerminal: React.FC = () => {
    const [config, setConfig] = useState<any>(null);
    const [registry, setRegistry] = useState<Record<string, any>>({});

    // D-20: Helper to derive agent info from backend registry, falling back to local constants
    const getAgentInfo = (agentId: string) => {
        const backendAgent = registry[agentId];
        const fallback = AGENT_REGISTRY_FALLBACK[agentId] || AGENT_REGISTRY_FALLBACK['gemini-cli'];
        return {
            name: backendAgent?.name || fallback.name,
            icon: backendAgent?.icon_url || fallback.icon
        };
    };
    const [sessions, setSessions] = useState<Session[]>([]);
    const [activeId, setActiveId] = useState<string>(() => {
        return localStorage.getItem('aicliagents_active_id') || 'default';
    });
    const [selectedAgentId, setSelectedAgentId] = useState<string>(() => {
        return localStorage.getItem('aicliagents_selected_agent') || 'gemini-cli';
    });
    const [hoveredId, setHoveredId] = useState<string | null>(null);
    const [hoveredY, setHoveredY] = useState<number>(0);

    // Track the last successfully started session to prevent loops
    const lastStartedKey = useRef<string>('');
    const lastSuccessfulStartKey = useRef<string>('');

    useEffect(() => {
        localStorage.setItem('aicliagents_active_id', activeId || '');
    }, [activeId]);

    useEffect(() => {
        localStorage.setItem('aicliagents_selected_agent', selectedAgentId);
    }, [selectedAgentId]);

    useEffect(() => {
        if (Object.keys(registry).length === 0) return;
        const installedAgents = Object.keys(registry).filter(id => registry[id]?.is_installed);
        if (installedAgents.length > 0 && !registry[selectedAgentId]?.is_installed) {
            setSelectedAgentId(installedAgents[0]);
        }
    }, [registry, selectedAgentId]);

    const [browserOpen, setBrowserOpen] = useState(false);
    const [currentPath, setCurrentPath] = useState<string>('');
    const [dirItems, setDirItems] = useState<any[]>([]);
    const [newDirName, setNewDirName] = useState('');
    const [isStarting, setIsStarting] = useState(false);
    const [isSyncing, setIsSyncing] = useState(false);
    const [startAttempts, setStartAttempts] = useState<Record<string, number>>({});

    // File Upload State
    const [isUploading, setIsUploading] = useState(false);
    const [uploadProgress, setUploadProgress] = useState(0);
    const [uploadFilename, setUploadFilename] = useState('');

    // Environment Variables State
    const [envModalOpen, setEnvModalOpen] = useState(false);
    const [workspaceEnvs, setWorkspaceEnvs] = useState<Record<string, string>>({});
    const [isSavingEnvs, setIsSavingEnvs] = useState(false);

    // Load configuration and Registry
    useEffect(() => {
        // MIGRATION: Copy old gemini sessions if they exist and new ones don't
        const oldSessions = localStorage.getItem('gemini_sessions');
        const oldActiveId = localStorage.getItem('gemini_active_id');
        if (oldSessions && !localStorage.getItem('aicliagents_sessions')) {
            localStorage.setItem('aicliagents_sessions', oldSessions);
            if (oldActiveId) localStorage.setItem('aicliagents_active_id', oldActiveId);
            console.log('[AICli] Migrated legacy Gemini sessions to AICliAgents');
        }

        const fetchRegistry = () => {
            const csrf = (window as any).csrf_token || '';
            fetch(`/plugins/unraid-aicliagents/AICliAjax.php?action=debug&csrf_token=${csrf}`)
                .then(r => r.json())
                .then(data => {
                    if (data && data.config) {
                        setConfig(data.config);
                        // Deep compare or simple update? Let's just update if changed
                        setRegistry(prev => JSON.stringify(prev) !== JSON.stringify(data.registry) ? data.registry : prev);
                    }
                })
                .catch(e => console.error('AICli Initial Load Error:', e));
        };

        fetchRegistry();
        const regTimer = setInterval(fetchRegistry, 60000); // D-14: Reduced from 30s to 60s to save processes

        const savedSessions = localStorage.getItem('aicliagents_sessions');
        let initial: Session[] = [];

        if (savedSessions) {
            try {
                const parsed = JSON.parse(savedSessions);
                if (parsed && Array.isArray(parsed)) {
                    // CLEANUP: Remove old-style sessions without agentId
                    initial = parsed.filter((s: any) => s.agentId !== undefined);
                }
            } catch (e) {
                console.error('AICli Session Parse Error:', e);
            }
        }

        const csrf = (window as any).csrf_token || '';
        fetch(`/plugins/unraid-aicliagents/AICliAjax.php?action=debug&csrf_token=${csrf}`)
            .then(r => r.json())
            .then(data => {
                if (data && data.config) {
                    Promise.all(initial.map(s => {
                        return fetch(`/plugins/unraid-aicliagents/AICliAjax.php?action=get_chat_session&path=${encodeURIComponent(s.path)}&id=${s.id}&agentId=${s.agentId}&csrf_token=${csrf}`)
                            .then(r => r.json())
                            .then(cData => ({ ...s, chatSessionId: cData.chatId || '', title: cData.title || s.title }))
                            .catch(() => ({ ...s, chatSessionId: s.chatSessionId || '' }));
                    })).then(updated => {
                        setSessions(updated);
                    });
                }
            });

        return () => clearInterval(regTimer);
    }, []);

    // Session Persistence
    useEffect(() => {
        localStorage.setItem('aicliagents_sessions', JSON.stringify(sessions));
    }, [sessions]);

    // Dynamic Status Polling (Title & Chat ID)
    useEffect(() => {
        if (!sessions.length) return;

        const poll = () => {
            const csrf = (window as any).csrf_token || '';
            sessions.forEach(s => {
                fetch(`/plugins/unraid-aicliagents/AICliAjax.php?action=get_session_status&id=${s.id}&path=${encodeURIComponent(s.path)}&agentId=${s.agentId}&csrf_token=${csrf}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.status === 'ok') {
                            const newChatId = data.chatId || '';
                            const titleChanged = data.title && data.title !== s.title;
                            const chatChanged = newChatId !== s.chatSessionId;

                            if (titleChanged || chatChanged) {
                                setSessions(prev => prev.map(ps =>
                                    ps.id === s.id ? { ...ps, title: data.title, chatSessionId: newChatId } : ps
                                ));
                            }
                        }
                    })
                    .catch(() => { });
            });
        };

        const timer = setInterval(poll, 20000); // D-14: Reduced from 10s to 20s to prevent process pileup
        poll();
        return () => clearInterval(timer);
    }, [sessions.map(s => s.id).join(',')]);

    // Ensure active session is running
    useEffect(() => {
        if (!activeId || !config || Object.keys(registry).length === 0) return;
        const session = sessions.find(s => s.id === activeId);
        if (!session) return;

        const isInstalled = registry[session.agentId]?.is_installed;
        const currentKey = `${activeId}-${session.agentId}-${session.chatSessionId || 'none'}`;

        // STABILITY CHECK: If we've successfully started this configuration, or are already starting it, don't repeat.
        if (lastSuccessfulStartKey.current === currentKey || lastStartedKey.current === currentKey) return;

        // Skip if agent not installed
        if (!isInstalled) {
            setIsStarting(false);
            return;
        }

        // CIRCUIT BREAKER
        const attempts = startAttempts[currentKey] || 0;
        if (attempts > 3) {
            console.error(`[AICli] Giving up on session ${session.chatSessionId} after ${attempts} failed attempts.`);
            if (session.chatSessionId) {
                console.log('[AICli] Attempting fresh session fallback...');
                setSessions(prev => prev.map(s => s.id === activeId ? { ...s, chatSessionId: '' } : s));
            }
            setIsStarting(false);
            return;
        }

        console.log('[AICli] Executing Start for:', currentKey);
        setIsStarting(true);
        lastStartedKey.current = currentKey;

        const csrf = (window as any).csrf_token || '';
        const startUrl = `/plugins/unraid-aicliagents/AICliAjax.php?action=start&id=${activeId}&agentId=${session.agentId}&path=${encodeURIComponent(session.path)}&chatId=${encodeURIComponent(session.chatSessionId || '')}&csrf_token=${csrf}`;

        fetch(startUrl)
            .then(r => r.text())
            .then(() => {
                // SUCCESS: Wait for ttyd to initialize before hiding overlay
                setTimeout(() => {
                    lastSuccessfulStartKey.current = currentKey;
                    setIsStarting(false);
                    setStartAttempts(prev => ({ ...prev, [currentKey]: 0 }));
                }, 2000);
            })
            .catch(e => {
                console.error('[AICli] Start Error:', e);
                lastStartedKey.current = ''; // Allow retry
                setStartAttempts(prev => ({ ...prev, [currentKey]: (prev[currentKey] || 0) + 1 }));
                setIsStarting(false);
            });
    }, [activeId, !!config, Object.keys(registry).length, sessions.find(s => s.id === activeId)?.chatSessionId, sessions.find(s => s.id === activeId)?.agentId]);

    const browseTo = (path: string) => {
        const csrf = (window as any).csrf_token || '';
        const browseUrl = `/plugins/unraid-aicliagents/AICliAjax.php?action=list_dir&path=${encodeURIComponent(path)}&csrf_token=${csrf}`;
        fetch(browseUrl)
            .then(r => r.json())
            .then(data => {
                if (data.error) throw new Error(data.error);
                setCurrentPath(data.path);
                setDirItems(data.items);
            })
            .catch(e => console.error('[AICli] Browse Error:', e));
    };

    const openBrowser = () => {
        try {
            const currentSession = sessions.find(s => s.id === activeId);
            browseTo(currentSession?.path || config?.root_path || '/mnt');
            setBrowserOpen(true);
        } catch (e) {
            console.error('AICli OpenBrowser Error:', e);
        }
    };

    const confirmWorkspace = () => {
        let name = currentPath.split('/').pop() || 'Workspace';
        if (currentPath === '/') name = 'Root';

        const newId = 's' + Math.random().toString(36).substring(2, 7);
        const csrf = (window as any).csrf_token || '';

        fetch(`/plugins/unraid-aicliagents/AICliAjax.php?action=get_chat_session&path=${encodeURIComponent(currentPath)}&agentId=${selectedAgentId}&csrf_token=${csrf}`)
            .then(r => r.json())
            .then(data => {
                const newSessions = [...sessions, {
                    id: newId,
                    name: name,
                    path: currentPath,
                    agentId: selectedAgentId,
                    lastActive: Date.now(),
                    chatSessionId: data.chatId || ''
                }];
                setSessions(newSessions);
                setActiveId(newId);
                setBrowserOpen(false);
            })
            .catch(() => {
                const newSessions = [...sessions, {
                    id: newId,
                    name: name,
                    path: currentPath,
                    agentId: selectedAgentId,
                    lastActive: Date.now(),
                    chatSessionId: ''
                }];
                setSessions(newSessions);
                setActiveId(newId);
                setBrowserOpen(false);
            });
    };

    const createFolder = () => {
        if (!newDirName) return;
        const csrf_token = (window as any).csrf_token || '';
        const url = `/plugins/unraid-aicliagents/AICliAjax.php?action=create_dir&parent=${encodeURIComponent(currentPath)}&name=${encodeURIComponent(newDirName)}&csrf_token=${encodeURIComponent(csrf_token)}`;

        fetch(url)
            .then(r => r.json())
            .then(data => {
                if (data.status === 'ok') {
                    setNewDirName('');
                    browseTo(currentPath);
                } else {
                    if (typeof (window as any).swal === 'function') {
                        (window as any).swal('Error', 'Error creating folder: ' + (data.message || data.error), 'error');
                    } else {
                        (window as any).swal({
                            title: "Error",
                            text: "Error creating folder: " + (data.message || data.error),
                            type: "error"
                        });
                    }
                }
            });
    };

    const closeTab = (e: React.MouseEvent, id: string) => {
        e.stopPropagation();
        const index = sessions.findIndex(s => s.id === id);
        const filtered = sessions.filter(s => s.id !== id);
        let nextId = activeId;

        const isLastSession = filtered.length === 0;

        if (activeId === id) {
            if (filtered.length > 0) {
                const nextIndex = Math.min(index, filtered.length - 1);
                nextId = filtered[nextIndex].id;
            } else {
                nextId = '';
            }
        }

        if (isLastSession) setIsSyncing(true);

        setSessions(filtered);
        setActiveId(nextId);
        
        const csrf = (window as any).csrf_token || '';
        fetch(`/plugins/unraid-aicliagents/AICliAjax.php?action=stop&id=${id}&hard=1&csrf_token=${csrf}`)
            .then(() => {
                if (isLastSession) {
                    setTimeout(() => {
                        setIsSyncing(false);
                        openBrowser();
                    }, 1500);
                }
            })
            .catch(() => {
                if (isLastSession) setIsSyncing(false);
            });
    };

    const resetSession = (e: React.MouseEvent, id: string) => {
        e.stopPropagation();
        const session = sessions.find(s => s.id === id);
        if (!session) return;

        setIsStarting(true);
        const csrf = (window as any).csrf_token || '';
        const agentId = session.agentId || 'gemini-cli';

        // Call restart on backend (stop + start), THEN refresh the iframe
        fetch(`/plugins/unraid-aicliagents/AICliAjax.php?action=restart&id=${id}&agentId=${agentId}&path=${encodeURIComponent(session.path)}&chatId=&csrf_token=${csrf}`)
            .then(() => {
                // Backend has restarted. Wait for ttyd to initialize, then refresh iframe.
                setTimeout(() => {
                    const newTs = Date.now();
                    const newKey = `${id}-${agentId}-none`;
                    lastStartedKey.current = newKey;
                    lastSuccessfulStartKey.current = newKey;
                    setSessions(prev => prev.map(s => s.id === id ? { ...s, chatSessionId: '', lastActive: newTs } : s));
                    setIsStarting(false);
                }, 2500);
            })
            .catch(() => {
                setIsStarting(false);
            });
    };

    const openEnvModal = () => {
        const active = sessions.find(s => s.id === activeId);
        if (!active) return;
        
        const csrf = (window as any).csrf_token || '';
        fetch(`/plugins/unraid-aicliagents/AICliAjax.php?action=get_env&path=${encodeURIComponent(active.path)}&agentId=${active.agentId}&csrf_token=${csrf}`)
            .then(r => r.json())
            .then(data => {
                if (data.status === 'ok') {
                    setWorkspaceEnvs(data.envs || {});
                    setEnvModalOpen(true);
                }
            })
            .catch(e => console.error('[AICli] Fetch Env Error:', e));
    };

    const saveEnvs = () => {
        const active = sessions.find(s => s.id === activeId);
        if (!active) return;

        setIsSavingEnvs(true);
        const csrf = (window as any).csrf_token || '';
        // Unraid NGINX prefers GET for standalone PHP scripts to avoid hanging
        const envData = encodeURIComponent(JSON.stringify(workspaceEnvs));
        fetch(`/plugins/unraid-aicliagents/AICliAjax.php?action=save_env&path=${encodeURIComponent(active.path)}&agentId=${active.agentId}&envs=${envData}&csrf_token=${csrf}`)
            .then(r => r.json())
            .then(data => {
                setIsSavingEnvs(false);
                if (data.status === 'ok') {
                    setEnvModalOpen(false);
                    // Optionally restart session to apply ENVs
                    if (typeof (window as any).swal === 'function') {
                        (window as any).swal({
                            title: 'Apply Changes?',
                            text: 'Environment variables saved. Restart session now to apply?',
                            type: 'info',
                            showCancelButton: true,
                            confirmButtonText: 'Restart',
                            cancelButtonText: 'Later'
                        }, (confirmed: boolean) => {
                            if (confirmed) resetSession({ stopPropagation: () => { } } as any, activeId);
                        });
                    } else {
                        (window as any).swal({
                            title: "Saved",
                            text: "Environment variables saved. Restart session now to apply?",
                            type: "success",
                            showCancelButton: true,
                            confirmButtonText: "Restart Now",
                            cancelButtonText: "Later"
                        }, (confirmed: boolean) => {
                            if (confirmed) {
                                resetSession({ stopPropagation: () => { } } as any, activeId);
                            }
                        });
                    }
                }
            })
            .catch(e => {
                setIsSavingEnvs(false);
                console.error('[AICli] Save Env Error:', e);
            });
    };

    const [drawerOpen, setDrawerOpen] = useState(false);
    useEffect(() => {
        if (!drawerOpen) setHoveredId(null);
    }, [drawerOpen]);

    const [tabY, setTabY] = useState(() => {
        const saved = localStorage.getItem('aicliagents_tab_y');
        return saved ? parseInt(saved, 10) : 20;
    });
    const [isDragging, setIsDragging] = useState(false);
    const [dragStart, setDragStart] = useState<{ y: number, bottom: number } | null>(null);

    useEffect(() => {
        if (!dragStart) return;
        const onMove = (e: MouseEvent) => {
            const delta = dragStart.y - e.clientY;
            if (!isDragging && Math.abs(delta) > 5) setIsDragging(true);
            if (isDragging || Math.abs(delta) > 5) {
                const newY = dragStart.bottom + delta;
                const capped = Math.max(10, Math.min(window.innerHeight - 60, newY));
                setTabY(capped);
            }
        };
        const onUp = () => {
            if (isDragging) localStorage.setItem('aicliagents_tab_y', tabY.toString());
            setTimeout(() => { setIsDragging(false); setDragStart(null); }, 50);
        };
        window.addEventListener('mousemove', onMove);
        window.addEventListener('mouseup', onUp);
        return () => {
            window.removeEventListener('mousemove', onMove);
            window.removeEventListener('mouseup', onUp);
        };
    }, [dragStart, isDragging, tabY]);

    if (!config) return <div style={styles.loading}>Initializing AICli Session...</div>;

    const activeSession = sessions.find(s => s.id === activeId);
    const activeAgent = getAgentInfo(activeSession?.agentId || 'gemini-cli');
    const hasAnyAgent = Object.values(registry).some(a => a.is_installed);
    const isVerbose = config.log_level === '3';

    const themeJson = THEMES[config.theme as keyof typeof THEMES] || THEMES.dark;
    const themeEncoded = encodeURIComponent(themeJson);
    const terminalUrl = `/webterminal/aicliterm-${activeId}/?theme=${themeEncoded}&fontSize=${config.font_size}&fontFamily=monospace&v=${activeSession?.lastActive || 'stable'}${isVerbose ? '' : '&quiet=1'}`;

    const handleIframeLoad = (e: React.SyntheticEvent<HTMLIFrameElement>) => {
        try {
            const iframe = e.currentTarget;
            const win = iframe.contentWindow;
            const doc = iframe.contentDocument || win?.document;
            if (doc && win) {
                // 1. Inject Scrollbar Killer CSS
                const style = doc.createElement('style');
                style.textContent = `
                    #terminal .xterm-viewport::-webkit-scrollbar { width: 0px !important; display: none !important; }
                    .xterm-viewport { overflow-y: hidden !important; }
                `;
                doc.head.appendChild(style);

                // 2. Surgical Log Suppression (Frontend noise reduction)
                if (!isVerbose) {
                    const script = doc.createElement('script');
                    script.textContent = `
                        (function() {
                            const _log = console.log;
                            const _info = console.info;
                            const _warn = console.warn;
                            const shouldSuppress = (args) => {
                                if (!args[0] || typeof args[0] !== 'string') return false;
                                const msg = args[0].toLowerCase();
                                return msg.includes('[ttyd]') || msg.includes('[xterm]');
                            };
                            console.log = function(...args) { if (!shouldSuppress(args)) _log.apply(console, args); };
                            console.info = function(...args) { if (!shouldSuppress(args)) _info.apply(console, args); };
                            console.warn = function(...args) { if (!shouldSuppress(args)) _warn.apply(console, args); };
                        })();
                    `;
                    doc.head.appendChild(script);
                }
                console.log('[AICli] Terminal iframe initialized' + (isVerbose ? ' (Verbose)' : ' (Quiet Mode)'));
            }
        } catch (err) {
            console.warn('[AICli] Could not access iframe internals (cross-origin?):', err);
        }
    };

    return (
        <div style={styles.root}>
            {drawerOpen && (
                <div
                    onClick={() => setDrawerOpen(false)}
                    style={{ position: 'absolute', inset: 0, zIndex: 999, backgroundColor: 'transparent' }}
                />
            )}

            <div
                data-drawer="true"
                style={{ ...styles.drawer, transform: drawerOpen ? 'translateX(0)' : 'translateX(-100%)' }}
            >
                <div style={styles.drawerContent}>
                    <div style={styles.drawerTop}>
                        <button 
                            onClick={() => { openBrowser(); setDrawerOpen(false); }} 
                            style={{...styles.drawerBtnPrimary, opacity: hasAnyAgent ? 1 : 0.5}}
                            disabled={!hasAnyAgent}
                        >
                            <i className="fa fa-plus-circle"></i>
                            New Workspace
                        </button>
                    </div>

                    <div style={styles.drawerTabs}>
                        {sessions.map(s => {
                            const displayName = (s.title ? s.title + ' ' : '') + s.name;
                            const isActive = activeId === s.id;
                            return (
                                <div
                                    key={s.id}
                                    onClick={() => { setActiveId(s.id); setDrawerOpen(false); }}
                                    onMouseEnter={(e) => {
                                        setHoveredId(s.id);
                                        const rect = e.currentTarget.getBoundingClientRect();
                                        const drawerRect = e.currentTarget.closest('[data-drawer="true"]')?.getBoundingClientRect();
                                        if (drawerRect) setHoveredY(rect.top - drawerRect.top);
                                    }}
                                    onMouseLeave={(e) => {
                                        const related = e.relatedTarget as HTMLElement;
                                        if (related && (related.closest('[data-overlay="true"]') || related.closest('[data-drawer="true"]'))) return;
                                        setHoveredId(null);
                                    }}
                                    style={{
                                        ...styles.drawerTab,
                                        ...(isActive ? styles.drawerTabActive : {}),
                                        position: 'relative',
                                    }}
                                >
                                    <img src={getAgentInfo(s.agentId).icon} style={{ width: 16, height: 16, opacity: isActive ? 1 : 0.6 }} alt="" />
                                    <span style={styles.drawerTabLabel}>{displayName}</span>
                                    <i
                                        className="fa fa-times"
                                        style={styles.drawerTabClose}
                                        onClick={(e) => closeTab(e, s.id)}
                                    ></i>
                                </div>
                            );
                        })}
                    </div>

                    <div style={styles.drawerBottom}>
                        <button
                            onClick={openEnvModal}
                            style={{
                                ...styles.drawerBtn,
                                opacity: activeSession ? 1 : 0.5,
                                cursor: activeSession ? 'pointer' : 'not-allowed'
                            }}
                            title="Manage Environment Variables"
                            disabled={!activeSession}
                        >
                            <i className="fa fa-list-alt"></i>
                            MANAGE ENV
                        </button>
                        <button
                            onClick={() => document.getElementById('aicli-file-upload')?.click()}
                            style={{
                                ...styles.drawerBtn,
                                opacity: activeSession ? 1 : 0.5,
                                cursor: activeSession ? 'pointer' : 'not-allowed'
                            }}
                            title="Upload File"
                            disabled={!activeSession}
                        >
                            <i className="fa fa-upload"></i>
                            UPLOAD FILE
                        </button>
                        <input
                            id="aicli-file-upload"
                            type="file"
                            style={{ display: 'none' }}
                            disabled={!activeSession || isUploading}
                            onChange={(e) => {
                                const file = e.target.files?.[0];
                                if (!file || !activeSession) return;

                                const CHUNK_SIZE = 2 * 1024 * 1024; // 2MB
                                const totalChunks = Math.ceil(file.size / CHUNK_SIZE);

                                setIsUploading(true);
                                setUploadProgress(0);
                                setUploadFilename(file.name);

                                const uploadChunk = (chunkIndex: number) => {
                                    if (chunkIndex >= totalChunks) {
                                        setIsUploading(false);
                                        return;
                                    }

                                    const start = chunkIndex * CHUNK_SIZE;
                                    const end = Math.min(start + CHUNK_SIZE, file.size);
                                    const chunk = file.slice(start, end);

                                    const reader = new FileReader();
                                    reader.onload = function (evt) {
                                        const base64 = evt.target?.result as string;
                                        const b64Data = base64.split(',')[1] || base64; // Strip Data URL prefix

                                        const csrf = (window as any).csrf_token || '';
                                        const formData = new URLSearchParams();
                                        formData.append('csrf_token', csrf);
                                        formData.append('filename', file.name);
                                        formData.append('path', activeSession.path);
                                        formData.append('filedata', b64Data);
                                        formData.append('chunk_index', chunkIndex.toString());
                                        formData.append('total_chunks', totalChunks.toString());

                                        fetch(`/plugins/unraid-aicliagents/AICliAjax.php?action=upload_chunk&csrf_token=${csrf}`, {
                                            method: 'POST',
                                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                            body: formData
                                        })
                                            .then(async r => {
                                                const text = await r.text();
                                                try {
                                                    const data = JSON.parse(text);
                                                    if (data.status === 'ok') {
                                                        setUploadProgress(Math.round(((chunkIndex + 1) / totalChunks) * 100));
                                                        if (data.complete) {
                                                            if (typeof (window as any).swal === 'function') {
                                                                (window as any).swal({ title: 'File Uploaded', text: `Successfully uploaded ${file.name} to ${activeSession.path}`, type: 'success', timer: 2500, showConfirmButton: false });
                                                            } else {
                                                                // Swapped alert for swal or minimal fallback
                                                                console.log('File Uploaded!');
                                                            }
                                                            setIsUploading(false);
                                                        } else {
                                                            uploadChunk(chunkIndex + 1);
                                                        }
                                                    } else {
                                                        setIsUploading(false);
                                                        const errMsg = data.error || data.message || 'Unknown Server Error';
                                                        console.error('[AICli] Server logical error:', data);
                                                        if (typeof (window as any).swal === 'function') {
                                                            (window as any).swal('Upload Failed', errMsg, 'error');
                                                        } else {
                                                            console.error('Upload failed: ' + errMsg);
                                                        }
                                                    }
                                                } catch (e) {
                                                    setIsUploading(false);
                                                    console.error('[AICli] JSON Parse Error on Upload:', e);
                                                    if (typeof (window as any).swal === 'function') {
                                                        (window as any).swal('Upload Failed', 'Invalid server response: ' + text.substring(0, 100), 'error');
                                                    } else {
                                                        console.error('Upload failed: Invalid server response');
                                                    }
                                                }
                                            })
                                            .catch(err => {
                                                setIsUploading(false);
                                                console.error('[AICli] File upload network/fetch error:', err);
                                                if (typeof (window as any).swal === 'function') {
                                                    (window as any).swal('Upload Failed', 'Network Error: ' + err.message, 'error');
                                                } else {
                                                    console.error('Upload failed: Network Error (' + err.message + ')');
                                                }
                                            });
                                    };
                                    reader.readAsDataURL(chunk);
                                };

                                uploadChunk(0);
                                e.target.value = '';
                            }}
                        />
                        <button 
                            onClick={(e) => { e.stopPropagation(); window.open('https://gitlab.johnpwhite.com/johner/unraid-plg-aicliagents/-/blob/master/docs/USER_GUIDE.md', '_blank'); }} 
                            style={{ ...styles.drawerBtn, backgroundColor: '#3a87ad' }} 
                            title="User & Architecture Guide"
                        >
                            <i className="fa fa-book"></i>
                            Help Guide
                        </button>
                        <button onClick={() => window.location.href = '/Settings/AICliAgentsManager'} style={styles.drawerBtn} title="Plugin Settings">
                            <i className="fa fa-cog"></i>
                            Settings
                        </button>
                    </div>
                </div>

                {/* Metadata Overlay Card */}
                {hoveredId && (
                    <div
                        data-overlay="true"
                        onMouseLeave={() => setHoveredId(null)}
                        style={{ ...styles.tabOverlay, top: hoveredY + 22, transform: 'translateY(-50%)', pointerEvents: 'auto', paddingLeft: 40, marginLeft: 0 }}
                    >
                        <div style={{ backgroundColor: 'var(--content-background-color, var(--body-background, #fff))', border: '1px solid var(--border-color, #ccc)', borderRadius: 8, boxShadow: '0 10px 30px rgba(0,0,0,0.15)', padding: '12px 14px', display: 'flex', flexDirection: 'column', gap: 8 }}>
                            {sessions.find(s => s.id === hoveredId) && (
                                <>
                                    <div style={styles.overlayRow}>
                                        <i className="fa fa-folder" style={styles.overlayIcon}></i>
                                        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                                            <span style={styles.overlayText}>{sessions.find(s => s.id === hoveredId)?.path}</span>
                                            <span style={{ fontSize: 10, opacity: 0.5, textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                                                Agent: {getAgentInfo(sessions.find(s => s.id === hoveredId)?.agentId || 'gemini-cli').name}
                                            </span>
                                        </div>
                                    </div>
                                    {sessions.find(s => s.id === hoveredId)?.chatSessionId && (
                                        <div style={{ ...styles.overlayRow, marginTop: 4, display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
                                            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                                <i className="fa fa-comments" style={styles.overlayIcon}></i>
                                                <span style={styles.overlayText}>
                                                    Chat: <span style={{ fontFamily: 'monospace', fontSize: 11, opacity: 0.8 }}>{sessions.find(s => s.id === hoveredId)?.chatSessionId}</span>
                                                </span>
                                            </div>
                                            <button
                                                onClick={(e) => resetSession(e, hoveredId)}
                                                style={{
                                                    background: 'transparent',
                                                    border: '1px solid #ff4444',
                                                    color: '#ff4444',
                                                    fontSize: 9,
                                                    padding: '2px 6px',
                                                    borderRadius: 4,
                                                    cursor: 'pointer',
                                                    pointerEvents: 'auto'
                                                }}
                                                title="Forget Session"
                                            >
                                                <i className="fa fa-trash-o"></i> RESET
                                            </button>
                                        </div>
                                    )}
                                </>
                            )}
                        </div>
                    </div>
                )}

                <div
                    onClick={() => { if (!isDragging) setDrawerOpen(!drawerOpen); }}
                    onMouseDown={(e) => { setDragStart({ y: e.clientY, bottom: tabY }); e.preventDefault(); }}
                    style={{ ...styles.drawerToggle, bottom: tabY, cursor: isDragging ? 'grabbing' : 'grab', transition: isDragging ? 'none' : 'transform 0.3s, bottom 0.3s' }}
                >
                    <i className={`fa ${drawerOpen ? 'fa-chevron-left' : 'fa-bars'}`} style={{ fontSize: 14 }}></i>
                </div>
            </div>

            {/* Terminal Viewport - Stretched to fill */}
            <div style={styles.viewport}>
                {isUploading && (
                    <div style={{ ...styles.startingOverlay, zIndex: 100 }}>
                        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 16, backgroundColor: 'var(--content-background-color, #fff)', padding: '30px 40px', borderRadius: 8, boxShadow: '0 10px 40px rgba(0,0,0,0.2)', border: '1px solid var(--border-color, #ccc)', minWidth: 300 }}>
                            <i className="fa fa-cloud-upload" style={{ fontSize: 36, color: 'var(--orange, #e68a00)' }}></i>
                            <div style={{ textAlign: 'center' }}>
                                <div style={{ fontWeight: 700, marginBottom: 4 }}>Uploading File</div>
                                <div style={{ fontSize: 11, opacity: 0.6, fontFamily: 'monospace' }}>{uploadFilename}</div>
                            </div>
                            <div style={{ width: '100%', height: 6, backgroundColor: 'var(--mild-background-color, #eee)', borderRadius: 3, overflow: 'hidden' }}>
                                <div style={{ height: '100%', width: `${uploadProgress}%`, backgroundColor: 'var(--orange, #e68a00)', transition: 'width 0.2s ease-out' }}></div>
                            </div>
                            <div style={{ fontSize: 12, fontWeight: 700 }}>{uploadProgress}%</div>
                        </div>
                    </div>
                )}

                {isSyncing && (
                    <div style={{ ...styles.startingOverlay, zIndex: 101 }}>
                        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 16, backgroundColor: 'var(--content-background-color, #fff)', padding: '30px 40px', borderRadius: 8, boxShadow: '0 10px 40px rgba(0,0,0,0.2)', border: '1px solid var(--border-color, #ccc)', minWidth: 300 }}>
                            <i className="fa fa-refresh fa-spin" style={{ fontSize: 36, color: 'var(--orange, #e68a00)' }}></i>
                            <div style={{ textAlign: 'center' }}>
                                <div style={{ fontWeight: 700, marginBottom: 4 }}>Automatic Syncing</div>
                                <div style={{ fontSize: 11, opacity: 0.6 }}>Mirroring home directory to Flash drive...</div>
                            </div>
                        </div>
                    </div>
                )}

                {!hasAnyAgent && (
                    <div style={styles.startingOverlay}>
                        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 16, textAlign: 'center', padding: 40 }}>
                            <div style={{ padding: 25, borderRadius: '50%', backgroundColor: 'var(--mild-background-color, rgba(230,138,0,0.05))', border: '1px solid var(--orange, #e68a00)', opacity: 0.8 }}>
                                <i className="fa fa-warning" style={{ fontSize: 48, color: 'var(--orange, #e68a00)' }}></i>
                            </div>
                            <div>
                                <h3 style={{ margin: '0 0 8px 0', color: 'var(--orange, #e68a00)' }}>No Agents Installed</h3>
                                <p style={{ margin: 0, opacity: 0.6, fontSize: 13, maxWidth: 350 }}>
                                    To use AI CLI Agents, you must first install and configure at least one agent (e.g., Gemini, Claude) from the settings page.
                                </p>
                            </div>
                            <button
                                onClick={() => window.location.href = '/Settings/AICliAgentsManager'}
                                style={{ ...styles.openBtn, padding: '12px 32px', fontSize: 13, borderRadius: 6 }}
                            >
                                <i className="fa fa-cog"></i> Go to Agent Settings
                            </button>
                        </div>
                    </div>
                )}

                {hasAnyAgent && sessions.length === 0 && (
                    <div style={styles.startingOverlay}>
                        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 20, textAlign: 'center', padding: 40 }}>
                            <div style={{ padding: 25, borderRadius: '50%', backgroundColor: 'var(--mild-background-color, rgba(0,0,0,0.03))', border: '1px solid var(--border-color, #eee)' }}>
                                <i className="fa fa-terminal" style={{ fontSize: 48, opacity: 0.15 }}></i>
                            </div>
                            <div>
                                <h2 style={{ margin: '0 0 10px 0', fontWeight: 700, opacity: 0.8 }}>No Active Workspaces</h2>
                                <p style={{ margin: 0, opacity: 0.5, fontSize: 13, maxWidth: 300 }}>
                                    Select a workspace directory and an AI agent to begin a new session.
                                </p>
                            </div>
                            <button
                                onClick={openBrowser}
                                style={{ ...styles.openBtn, padding: '12px 32px', fontSize: 13, borderRadius: 6 }}
                            >
                                <i className="fa fa-folder-open"></i> Select Workspace
                            </button>
                        </div>
                    </div>
                )}

                {hasAnyAgent && activeSession && !registry[activeSession.agentId]?.is_installed && (
                    <div style={styles.startingOverlay}>
                        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 16, textAlign: 'center', padding: 40 }}>
                            <img src={getAgentInfo(activeSession.agentId).icon} style={{ width: 64, height: 64, opacity: 0.5 }} alt="" />
                            <div>
                                <h3 style={{ margin: '0 0 8px 0', color: 'var(--orange, #e68a00)' }}>
                                    {getAgentInfo(activeSession.agentId).name} Not Installed
                                </h3>
                                <p style={{ margin: 0, opacity: 0.6, fontSize: 13 }}>This workspace requires {getAgentInfo(activeSession.agentId).name} to be configured.</p>
                            </div>
                            <button
                                onClick={() => window.location.href = '/Settings/AICliAgentsManager'}
                                style={{ ...styles.openBtn, padding: '10px 24px', fontSize: 12 }}
                            >
                                <i className="fa fa-cog"></i> Go to Agent Settings
                            </button>
                        </div>
                    </div>
                )}

                {hasAnyAgent && isStarting && activeSession && registry[activeSession.agentId]?.is_installed && (
                    <div style={styles.startingOverlay}>
                        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 8 }}>
                            <i className="fa fa-circle-o-notch fa-spin" style={{ fontSize: 24, color: 'var(--orange, #e68a00)' }}></i>
                            <span style={{ fontSize: 12, fontFamily: 'monospace', opacity: 0.6, textTransform: 'uppercase' as const, letterSpacing: '0.1em' }}>
                                Waking {activeAgent.name}...
                            </span>
                        </div>
                    </div>
                )}
                {hasAnyAgent && !isStarting && registry[activeSession?.agentId || '']?.is_installed && (
                    <iframe
                        key={activeId + (activeSession?.agentId || '') + (activeSession?.lastActive || '')}
                        src={terminalUrl}
                        style={styles.iframe}
                        title="AICli Terminal"
                        onLoad={handleIframeLoad}
                        scrolling="no"
                        allow="clipboard-read; clipboard-write"
                    />
                )}
            </div>

            {/* Workspace Browser Modal */}
            {browserOpen && (
                <div style={styles.modalBackdrop}>
                    <div style={styles.modalBox}>
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

                            <div style={styles.agentSelector}>
                                {Object.entries(AGENT_REGISTRY_FALLBACK)
                                    .filter(([id]) => registry[id]?.is_installed)
                                    .map(([id, agent]) => (
                                        <div
                                            key={id}
                                            onClick={() => setSelectedAgentId(id)}
                                            style={{
                                                ...styles.agentOption,
                                                ...(selectedAgentId === id ? styles.agentOptionActive : {})
                                            }}
                                        >
                                            <img src={agent.icon} style={{ width: 16, height: 16 }} alt="" />
                                            <span>{agent.name}</span>
                                        </div>
                                    ))}
                                {Object.values(registry).filter(a => a.is_installed).length === 0 && (
                                    <div style={{ padding: 10, opacity: 0.5, fontSize: 12, textAlign: 'center', width: '100%' }}>
                                        No agents installed. Please go to Settings to install one.
                                    </div>
                                )}
                            </div>

                            <div style={styles.dirList}>
                                {dirItems.map((item, i) => (
                                    <div
                                        key={i}
                                        onClick={() => browseTo(item.path)}
                                        style={styles.dirItem}
                                        onMouseEnter={(e) => e.currentTarget.style.backgroundColor = 'var(--title-header-background-color, rgba(0,0,0,0.08))'}
                                        onMouseLeave={(e) => e.currentTarget.style.backgroundColor = 'transparent'}
                                    >
                                        <i
                                            className={`fa ${item.name === '..' ? 'fa-level-up' : 'fa-folder'}`}
                                            style={{ color: item.name === '..' ? 'inherit' : 'var(--orange, #e68a00)', opacity: 0.7 }}
                                        ></i>
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
                                <button onClick={createFolder} style={styles.createBtn}>Create</button>
                            </div>
                        </div>

                        {/* Modal Footer */}
                        <div style={styles.modalFooter}>
                            <button onClick={() => setBrowserOpen(false)} style={styles.cancelBtn}>Cancel</button>
                            <button onClick={confirmWorkspace} style={styles.openBtn}>Open Workspace</button>
                        </div>
                    </div>
                </div>
            )}

            {/* Environment Variables Modal */}
            {envModalOpen && (
                <div style={styles.modalBackdrop}>
                    <div style={{ ...styles.modalBox, width: 600 }}>
                        <div style={styles.modalHeader}>
                            <span style={styles.modalTitle}>
                                <i className="fa fa-list-alt" style={{ color: 'var(--orange, #e68a00)' }}></i>
                                {' '}Manage Session Environment
                            </span>
                            <i className="fa fa-times" style={{ cursor: 'pointer', opacity: 0.5 }} onClick={() => setEnvModalOpen(false)}></i>
                        </div>

                        <div style={styles.modalBody}>
                            <div style={{ ...styles.pathBar, marginBottom: 8 }}>
                                <img src={getAgentInfo(activeSession?.agentId || 'gemini-cli').icon} style={{ width: 14, height: 14 }} alt="" />
                                {activeSession?.agentId} @ {activeSession?.path}
                            </div>
                            
                            <p style={{ fontSize: 11, opacity: 0.6, margin: '0 0 15px 4px' }}>
                                These variables are specific to this workspace + agent combination.
                            </p>

                            <div style={{ maxHeight: 300, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 8, padding: 4 }}>
                                {Object.entries(workspaceEnvs).map(([key, value], idx) => (
                                    <div key={idx} style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                                        <input 
                                            style={{ ...styles.createInput, flex: 2, fontFamily: 'monospace' }} 
                                            value={key} 
                                            placeholder="VARIABLE_NAME"
                                            onChange={(e) => {
                                                const newEnvs = { ...workspaceEnvs };
                                                const val = e.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, '');
                                                delete newEnvs[key];
                                                newEnvs[val] = value;
                                                setWorkspaceEnvs(newEnvs);
                                            }}
                                        />
                                        <div style={{ opacity: 0.3 }}>=</div>
                                        <input 
                                            style={{ ...styles.createInput, flex: 3 }} 
                                            value={value} 
                                            placeholder="value"
                                            onChange={(e) => {
                                                setWorkspaceEnvs({ ...workspaceEnvs, [key]: e.target.value });
                                            }}
                                        />
                                        <button 
                                            onClick={() => {
                                                const newEnvs = { ...workspaceEnvs };
                                                delete newEnvs[key];
                                                setWorkspaceEnvs(newEnvs);
                                            }}
                                            style={{ ...styles.createBtn, color: '#ff4444', border: '1px solid #ff4444' }}
                                        >
                                            <i className="fa fa-trash-o"></i>
                                        </button>
                                    </div>
                                ))}

                                {Object.keys(workspaceEnvs).length === 0 && (
                                    <div style={{ textAlign: 'center', padding: '20px 0', opacity: 0.4, fontStyle: 'italic', fontSize: 12 }}>
                                        No custom environment variables defined.
                                    </div>
                                )}
                            </div>

                            <button 
                                onClick={() => setWorkspaceEnvs({ ...workspaceEnvs, '': '' })}
                                style={{ ...styles.createBtn, width: '100%', marginTop: 15, height: 32, borderStyle: 'dashed' }}
                            >
                                <i className="fa fa-plus"></i> Add Variable
                            </button>
                        </div>

                        <div style={styles.modalFooter}>
                            <button onClick={() => setEnvModalOpen(false)} style={styles.cancelBtn}>Cancel</button>
                            <button 
                                onClick={saveEnvs} 
                                style={{ ...styles.openBtn, opacity: isSavingEnvs ? 0.7 : 1 }}
                                disabled={isSavingEnvs}
                            >
                                {isSavingEnvs ? <i className="fa fa-circle-o-notch fa-spin"></i> : <i className="fa fa-check"></i>}
                                {' '}Save Changes
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

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
        backgroundColor: 'transparent',
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
    drawer: {
        position: 'absolute',
        left: 0,
        top: 0,
        bottom: 0,
        width: 240,
        zIndex: 1000,
        backgroundColor: 'var(--background-color, var(--body-background, #fff))',
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
        position: 'relative',
    },
    tabOverlay: {
        position: 'absolute',
        left: '100%',
        width: 360,
        zIndex: 2000,
        pointerEvents: 'none',
        display: 'flex',
        flexDirection: 'column',
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
        textAlign: 'center',
        marginTop: 2,
    },
    overlayText: {
        flex: 1,
        wordBreak: 'break-all',
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
        backgroundColor: 'var(--orange, #ff8c00)',
        color: '#fff',
        cursor: 'pointer',
        boxShadow: '0 4px 12px rgba(255, 140, 0, 0.3)',
        transition: 'all 0.2s ease',
    },
    drawerBtn: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        width: '100%',
        padding: '10px 12px',
        fontSize: 11,
        fontWeight: 700,
        textTransform: 'uppercase' as const,
        border: 'none',
        borderRadius: 4,
        backgroundColor: '#ff8c00',
        color: '#fff',
        cursor: 'pointer',
        boxShadow: '0 2px 6px rgba(0,0,0,0.2)',
        transition: 'all 0.15s ease',
    },
    drawerToggle: {
        position: 'absolute',
        left: '100%',
        bottom: 20,
        width: 32,
        height: 48,
        backgroundColor: 'var(--background-color, var(--body-background, #fff))',
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
    viewport: {
        flex: 1,
        margin: '8px',
        position: 'relative',
        overflow: 'hidden',
        zIndex: 0,
        backgroundColor: '#000',
        borderRadius: '4px',
        boxShadow: '0 4px 15px rgba(0,0,0,0.15)',
    },
    startingOverlay: {
        position: 'absolute',
        inset: 0,
        zIndex: 10,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: 'var(--background-color, rgba(255,255,255,0.85))',
        backdropFilter: 'blur(4px)',
        overflow: 'hidden',
    },
    iframe: {
        display: 'block',
        position: 'absolute',
        inset: 0,
        width: '100%',
        height: '100%',
        border: 'none',
        overflow: 'hidden',
    },
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
        backgroundColor: 'var(--background-color, var(--body-background, #fff))',
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
    agentSelector: {
        display: 'flex',
        flexWrap: 'wrap',
        gap: 8,
        marginBottom: 12,
    },
    agentOption: {
        flex: '1 1 calc(33.333% - 8px)',
        minWidth: 100,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        gap: 8,
        padding: '6px 8px',
        border: '1px solid var(--border-color, #ccc)',
        borderRadius: 4,
        cursor: 'pointer',
        fontSize: 12,
        fontWeight: 500,
        backgroundColor: 'var(--button-background, transparent)',
        transition: 'all 0.15s',
        outline: 'none',
        boxShadow: 'none',
    },
    agentOptionActive: {
        border: '1px solid var(--orange, #e68a00)',
        backgroundColor: 'var(--mild-background-color, rgba(230, 138, 0, 0.05))',
        color: 'var(--orange, #e68a00)',
        fontWeight: 600,
        outline: 'none',
        boxShadow: 'none',
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
        outline: "none",
    },
    createBtn: {
        height: 28,
        padding: '0 12px',
        fontSize: 10,
        fontWeight: 800,
        textTransform: 'uppercase' as const,
        border: 'none',
        borderRadius: 3,
        backgroundColor: '#ff8c00',
        color: '#fff',
        cursor: 'pointer',
        transition: 'all 0.15s',
        boxShadow: '0 1px 3px rgba(0,0,0,0.2)',
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
        backgroundColor: '#ff8c00',
        border: 'none',
        borderRadius: 3,
        color: '#fff',
        cursor: 'pointer',
        transition: 'all 0.15s',
        boxShadow: '0 2px 8px rgba(255, 140, 0, 0.4)',
    },
};
