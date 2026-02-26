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
        fetch('/plugins/unraid-geminicli/includes/GeminiSettings.php?action=debug')
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
        fetch(`/plugins/unraid-geminicli/includes/GeminiSettings.php?action=start&id=${activeId}&path=${encodeURIComponent(session.path)}`)
            .then(() => {
                setTimeout(() => setIsStarting(false), 500);
            })
            .catch(e => {
                console.error('Gemini Start Error:', e);
                setIsStarting(false);
            });
    }, [activeId, config]);

    const browseTo = (path: string) => {
        fetch(`/plugins/unraid-geminicli/includes/GeminiSettings.php?action=list_dir&path=${encodeURIComponent(path)}`)
            .then(r => r.json())
            .then(data => {
                if (data.error) throw new Error(data.error);
                setCurrentPath(data.path);
                setDirItems(data.items);
            })
            .catch(e => console.error('Gemini Browse Error:', e));
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
        // Shorter, very safe alphanumeric ID for NGINX compatibility
        const newId = 's' + Math.random().toString(36).substring(2, 7);
        const newSessions = [...sessions, { id: newId, name: name, path: currentPath, lastActive: Date.now() }];
        setSessions(newSessions);
        setActiveId(newId);
        setBrowserOpen(false);
    };

    const createFolder = () => {
        if (!newDirName) return;
        const formData = new FormData();
        formData.append('parent', currentPath);
        formData.append('name', newDirName);

        fetch('/plugins/unraid-geminicli/includes/GeminiSettings.php?action=create_dir', {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-Token': (window as any).csrf_token || ''
            }
        }).then(r => r.json())
            .then(data => {
                if (data.status === 'ok') {
                    setNewDirName('');
                    browseTo(currentPath);
                } else {
                    alert('Error creating folder: ' + (data.message || 'unknown error'));
                }
            }).catch(e => console.error('Gemini CreateFolder Error:', e));
    };

    const closeTab = (e: React.MouseEvent, id: string) => {
        e.stopPropagation();
        if (id === 'default') return;
        const filtered = sessions.filter(s => s.id !== id);
        setSessions(filtered);
        if (activeId === id) setActiveId('default');
        fetch(`/plugins/unraid-geminicli/includes/GeminiSettings.php?action=stop&id=${id}&hard=1`);
    };

    if (!config) {
        return (
            <div className="flex-1 flex items-center justify-center bg-[#1e1e1e] font-mono text-sm text-gray-500 uppercase tracking-[0.2em] animate-pulse">
                Initializing Gemini Session...
            </div>
        );
    }

    const activeSession = sessions.find(s => s.id === activeId);
    const themeJson = THEMES[config.theme as keyof typeof THEMES] || THEMES.dark;
    const themeParams = encodeURIComponent(themeJson);
    const terminalUrl = `/webterminal/geminiterm-${activeId}/?theme=${themeParams}&fontSize=${config.font_size}&fontFamily=monospace&disableLeaveAlert=true&v=${activeSession?.lastActive || Date.now()}`;

    return (
        <div className="flex-1 flex flex-col bg-[var(--background-color)] text-[var(--text-color)] overflow-hidden h-full relative">
            {/* Session Header */}
            <div className="flex items-end justify-between px-4 pt-2 bg-[var(--header-background,#2d2d2d)] border-b border-[var(--border-color,#444)] select-none min-h-[48px] z-10">
                <div className="flex items-end gap-1 overflow-x-auto no-scrollbar max-w-[70%]">
                    {sessions.map(s => {
                        const displayName = s.id === 'default'
                            ? (s.path === config?.root_path ? 'Main' : s.path.split('/').pop() || 'Main')
                            : s.name;
                        return (
                            <div
                                key={s.id}
                                onClick={() => setActiveId(s.id)}
                                className={`flex items-center gap-2 px-4 py-2 rounded-t-md cursor-pointer transition-all border-t border-x text-[13px] font-bold uppercase tracking-tight whitespace-nowrap mb-[-1px] ${activeId === s.id
                                    ? 'bg-[var(--background-color)] border-[var(--border-color,#444)] text-orange-400 z-10'
                                    : 'bg-[rgba(0,0,0,0.05)] border-transparent text-[var(--text-color)] opacity-50 hover:bg-[rgba(0,0,0,0.1)] hover:opacity-80'
                                    }`}
                            >
                                <i className={`fa ${s.id === 'default' ? 'fa-home' : 'fa-folder-open'} ${activeId === s.id ? 'opacity-100' : 'opacity-60'}`}></i>
                                {displayName}
                                {s.id !== 'default' && (
                                    <i
                                        className="fa fa-times ml-2 hover:text-white opacity-40 hover:opacity-100 transition-opacity"
                                        onClick={(e) => closeTab(e, s.id)}
                                    ></i>
                                )}
                            </div>
                        );
                    })}
                </div>

                <div className="flex items-center gap-2 pb-2">
                    <button
                        onClick={openBrowser}
                        className="flex items-center justify-center gap-2 px-4 h-9 bg-[var(--button-bg,#3a3a3a)] hover:bg-[var(--button-hover,#4a4a4a)] text-[var(--button-text,#fff)] text-[13px] font-bold uppercase rounded-sm transition-all border border-[var(--border-color,#444)] active:scale-95"
                    >
                        <i className="fa fa-plus-circle text-orange-400"></i>
                        New Workspace
                    </button>
                    <button
                        onClick={() => {
                            const newSessions = sessions.map(s => s.id === activeId ? { ...s, lastActive: Date.now() } : s);
                            setSessions(newSessions);
                            fetch(`/plugins/unraid-geminicli/includes/GeminiSettings.php?action=restart&id=${activeId}&path=${encodeURIComponent(activeSession?.path || '')}`);
                        }}
                        className="w-11 h-9 flex items-center justify-center bg-[var(--button-bg,#3a3a3a)] hover:bg-orange-600 text-[var(--button-text,#fff)] rounded-sm transition-all border border-[var(--border-color,#444)]"
                        title="Restart Session"
                    >
                        <i className="fa fa-refresh"></i>
                    </button>
                </div>
            </div>

            {/* Terminal Viewport */}
            <div className="flex-1 w-full bg-[var(--background-color)] relative overflow-hidden h-full z-0">
                {isStarting && (
                    <div className="absolute inset-0 z-10 flex items-center justify-center bg-[var(--background-color)] bg-opacity-80 backdrop-blur-sm">
                        <div className="flex flex-col items-center gap-3">
                            <i className="fa fa-circle-o-notch fa-spin text-orange-500 text-2xl"></i>
                            <span className="text-xs font-mono text-gray-400 uppercase tracking-widest">Waking Gemini...</span>
                        </div>
                    </div>
                )}
                <iframe
                    key={activeId + (activeSession?.lastActive || '')}
                    src={terminalUrl}
                    className="absolute inset-0 w-full h-full border-none"
                    title="Gemini Terminal"
                    style={{ height: '100%', width: '100%' }}
                />
            </div>

            {/* Absolute Overlay Modal */}
            {browserOpen && (
                <div className="fixed inset-0 z-[99999] flex items-center justify-center bg-black bg-opacity-70 backdrop-blur-md">
                    <div className="w-[500px] bg-[var(--background-color)] rounded-lg shadow-2xl border border-[var(--border-color,#444)] overflow-hidden animate-in fade-in zoom-in duration-200">
                        <div className="px-4 py-3 bg-[var(--header-background,#333)] border-b border-[var(--border-color,#444)] flex items-center justify-between font-sans">
                            <h3 className="text-[var(--text-color)] font-bold text-sm uppercase tracking-wider flex items-center gap-2">
                                <i className="fa fa-folder-open text-orange-500"></i>
                                Select Workspace
                            </h3>
                            <button onClick={() => setBrowserOpen(false)} className="text-[var(--text-color)] opacity-50 hover:opacity-100 transition-opacity">
                                <i className="fa fa-times"></i>
                            </button>
                        </div>

                        <div className="p-4">
                            <div className="mb-3 flex items-center gap-2 px-2 py-1.5 bg-[rgba(0,0,0,0.05)] rounded border border-[var(--border-color,#444)] text-xs font-mono text-[var(--text-color)] opacity-70">
                                <i className="fa fa-hdd-o"></i>
                                {currentPath}
                            </div>

                            <div className="h-[250px] overflow-y-auto bg-[rgba(0,0,0,0.05)] rounded border border-[var(--border-color,#444)] mb-4 custom-scrollbar">
                                {dirItems.map((item, i) => (
                                    <div
                                        key={i}
                                        onClick={() => browseTo(item.path)}
                                        className="flex items-center gap-3 px-3 py-2 hover:bg-[#333] cursor-pointer group transition-colors border-b border-[#222] last:border-0"
                                    >
                                        <i className={`fa ${item.name === '..' ? 'fa-level-up' : 'fa-folder'} ${item.name === '..' ? 'text-gray-500' : 'text-orange-500'} opacity-60 group-hover:opacity-100`}></i>
                                        <span className="text-[var(--text-color)] text-[13px] group-hover:opacity-100 opacity-80">{item.name}</span>
                                    </div>
                                ))}
                            </div>

                            <div className="flex gap-2">
                                <input
                                    type="text"
                                    placeholder="New Folder..."
                                    value={newDirName}
                                    onChange={(e) => setNewDirName(e.target.value)}
                                    className="flex-1 h-9 bg-[var(--background-color)] border border-[var(--border-color,#444)] text-[var(--text-color)] text-[13px] px-3 py-2 rounded outline-none focus:border-orange-500 transition-colors"
                                />
                                <button
                                    onClick={createFolder}
                                    className="px-4 h-9 bg-[var(--button-bg,#3a3a3a)] hover:bg-[var(--button-hover,#4a4a4a)] text-[var(--button-text,#fff)] text-xs font-bold uppercase rounded transition-colors border border-[var(--border-color,#444)]"
                                >
                                    Create
                                </button>
                            </div>
                        </div>

                        <div className="px-4 py-3 bg-[var(--header-background,#3a3a3a)] border-t border-[var(--border-color,#444)] flex justify-end gap-2">
                            <button
                                onClick={() => setBrowserOpen(false)}
                                className="px-4 py-1.5 text-[var(--text-color)] opacity-60 hover:opacity-100 text-xs font-bold uppercase transition-colors"
                            >
                                Cancel
                            </button>
                            <button
                                onClick={confirmWorkspace}
                                className="px-6 py-1.5 bg-orange-600 hover:bg-orange-500 text-white text-xs font-black uppercase rounded shadow-lg transition-all active:scale-95"
                            >
                                Open Workspace
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};
