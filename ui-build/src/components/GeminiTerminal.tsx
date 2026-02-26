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
            });
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
            });
    }, [activeId, config]);

    const browseTo = (path: string) => {
        fetch(`/plugins/unraid-geminicli/includes/GeminiSettings.php?action=list_dir&path=${encodeURIComponent(path)}`)
            .then(r => r.json())
            .then(data => {
                setCurrentPath(data.path);
                setDirItems(data.items);
            });
    };

    const openBrowser = () => {
        const session = sessions.find(s => s.id === activeId);
        browseTo(session?.path || config?.root_path || '/mnt');
        setBrowserOpen(true);
    };

    const confirmWorkspace = () => {
        const name = currentPath.split('/').pop() || 'Workspace';
        const newId = 'sess_' + Date.now();
        const newSessions = [...sessions, { id: newId, name, path: currentPath, lastActive: Date.now() }];
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
            headers: { 'X-CSRF-Token': (window as any).csrf_token || '' }
        }).then(() => {
            setNewDirName('');
            browseTo(currentPath);
        });
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
            <div className="flex-1 flex items-center justify-center bg-[#1e1e1e] font-mono text-[10px] text-gray-500 uppercase tracking-[0.2em] animate-pulse">
                Initializing Gemini Session...
            </div>
        );
    }

    const activeSession = sessions.find(s => s.id === activeId);
    const themeJson = THEMES[config.theme as keyof typeof THEMES] || THEMES.dark;
    const themeParams = encodeURIComponent(themeJson);
    const terminalUrl = `/webterminal/geminiterm-${activeId}/?theme=${themeParams}&fontSize=${config.font_size}&fontFamily=monospace&disableLeaveAlert=true&v=${activeSession?.lastActive || Date.now()}`;

    return (
        <div className="flex-1 flex flex-col bg-[#1e1e1e] overflow-hidden h-full">
            {/* Session Header */}
            <div className="flex items-center justify-between px-4 py-2 bg-[#2a2a2a] border-b border-[#333] select-none min-h-[48px]">
                <div className="flex items-center gap-1 overflow-x-auto no-scrollbar max-w-[70%]">
                    {sessions.map(s => (
                        <div 
                            key={s.id}
                            onClick={() => setActiveId(s.id)}
                            className={`flex items-center gap-2 px-3 py-1.5 rounded-t-sm cursor-pointer transition-all border-b-2 text-[10px] font-bold uppercase tracking-tight whitespace-nowrap ${
                                activeId === s.id 
                                ? 'bg-[#1e1e1e] text-orange-500 border-orange-500' 
                                : 'text-gray-500 border-transparent hover:text-gray-300'
                            }`}
                        >
                            <i className={`fa ${s.id === 'default' ? 'fa-home' : 'fa-folder-open'} ${activeId === s.id ? 'opacity-100' : 'opacity-40'}`}></i>
                            {s.name}
                            {s.id !== 'default' && (
                                <i 
                                    className="fa fa-times ml-1 hover:text-white opacity-40 hover:opacity-100 transition-opacity" 
                                    onClick={(e) => closeTab(e, s.id)}
                                ></i>
                            )}
                        </div>
                    ))}
                </div>

                <div className="flex items-center gap-3">
                    <button 
                        onClick={openBrowser}
                        className="flex items-center gap-2 px-3 py-1.5 bg-[#333] hover:bg-[#444] text-white text-[10px] font-bold uppercase rounded-sm transition-all border border-[#444] active:scale-95"
                    >
                        <i className="fa fa-plus-circle text-orange-500"></i>
                        New Workspace
                    </button>
                    <button 
                        onClick={() => {
                            const newSessions = sessions.map(s => s.id === activeId ? {...s, lastActive: Date.now()} : s);
                            setSessions(newSessions);
                            fetch(`/plugins/unraid-geminicli/includes/GeminiSettings.php?action=restart&id=${activeId}&path=${encodeURIComponent(activeSession?.path || '')}`);
                        }}
                        className="w-8 h-8 flex items-center justify-center bg-[#333] hover:bg-orange-600 text-white rounded-sm transition-all border border-[#444]"
                        title="Restart Session"
                    >
                        <i className="fa fa-refresh"></i>
                    </button>
                </div>
            </div>

            {/* Terminal Viewport */}
            <div className="flex-1 w-full bg-[#1e1e1e] relative overflow-hidden h-full">
                {isStarting && (
                    <div className="absolute inset-0 z-10 flex items-center justify-center bg-[#1e1e1e] bg-opacity-80 backdrop-blur-sm">
                        <div className="flex flex-col items-center gap-3">
                            <i className="fa fa-circle-o-notch fa-spin text-orange-500 text-2xl"></i>
                            <span className="text-[10px] font-mono text-gray-400 uppercase tracking-widest">Waking Gemini...</span>
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

            {/* Workspace Browser Modal */}
            {browserOpen && (
                <div className="fixed inset-0 z-[10002] flex items-center justify-center bg-black bg-opacity-70 backdrop-blur-md">
                    <div className="w-[500px] bg-[#2a2a2a] rounded-lg shadow-2xl border border-[#444] overflow-hidden animate-in fade-in zoom-in duration-200">
                        <div className="px-4 py-3 bg-[#333] border-b border-[#444] flex items-center justify-between">
                            <h3 className="text-white font-bold text-xs uppercase tracking-wider flex items-center gap-2">
                                <i className="fa fa-folder-open text-orange-500"></i>
                                Select Workspace
                            </h3>
                            <button onClick={() => setBrowserOpen(false)} className="text-gray-500 hover:text-white transition-colors">
                                <i className="fa fa-times"></i>
                            </button>
                        </div>
                        
                        <div className="p-4">
                            <div className="mb-3 flex items-center gap-2 px-2 py-1.5 bg-[#1e1e1e] rounded border border-[#444] text-[10px] font-mono text-gray-400">
                                <i className="fa fa-hdd-o"></i>
                                {currentPath}
                            </div>

                            <div className="h-[250px] overflow-y-auto bg-[#1e1e1e] rounded border border-[#444] mb-4 custom-scrollbar">
                                {dirItems.map((item, i) => (
                                    <div 
                                        key={i}
                                        onClick={() => browseTo(item.path)}
                                        className="flex items-center gap-3 px-3 py-2 hover:bg-[#333] cursor-pointer group transition-colors border-b border-[#222] last:border-0"
                                    >
                                        <i className={`fa ${item.name === '..' ? 'fa-level-up' : 'fa-folder'} ${item.name === '..' ? 'text-gray-500' : 'text-orange-500'} opacity-60 group-hover:opacity-100`}></i>
                                        <span className="text-gray-300 text-[11px] group-hover:text-white">{item.name}</span>
                                    </div>
                                ))}
                            </div>

                            <div className="flex gap-2">
                                <input 
                                    type="text" 
                                    placeholder="New Folder Name..."
                                    value={newDirName}
                                    onChange={(e) => setNewDirName(e.target.value)}
                                    className="flex-1 bg-[#1e1e1e] border border-[#444] text-white text-[11px] px-3 py-2 rounded outline-none focus:border-orange-500 transition-colors"
                                />
                                <button 
                                    onClick={createFolder}
                                    className="px-4 bg-[#333] hover:bg-[#444] text-white text-[10px] font-bold uppercase rounded transition-colors border border-[#444]"
                                >
                                    Create
                                </button>
                            </div>
                        </div>

                        <div className="px-4 py-3 bg-[#333] border-t border-[#444] flex justify-end gap-2">
                            <button 
                                onClick={() => setBrowserOpen(false)}
                                className="px-4 py-2 text-gray-400 hover:text-white text-[10px] font-bold uppercase transition-colors"
                            >
                                Cancel
                            </button>
                            <button 
                                onClick={confirmWorkspace}
                                className="px-6 py-2 bg-orange-600 hover:bg-orange-500 text-white text-[10px] font-black uppercase rounded shadow-lg transition-all active:scale-95"
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
