import React, { useState, useEffect } from 'react';

const THEMES = {
    dark: '{"background":"#1e1e1e","foreground":"#ffffff"}',
    light: '{"background":"#ffffff","foreground":"#1e1e1e"}',
    solarized: '{"background":"#002b36","foreground":"#839496"}'
};

export const GeminiTerminal: React.FC = () => {
    const [fontSize, setFontSize] = useState(14);
    const [themeName, setThemeName] = useState<'dark' | 'light' | 'solarized'>('dark');
    const [key, setKey] = useState(Date.now());
    const [isSyncing, setIsSyncing] = useState(false);
    const [containerHeight, setContainerHeight] = useState('calc(100vh - 120px)');

    const handleFontSize = (delta: number) => {
        const next = Math.min(Math.max(fontSize + delta, 8), 32);
        setFontSize(next);
    };

    // Load saved preferences
    useEffect(() => {
        const savedSize = localStorage.getItem('gemini_terminal_font_size');
        const savedTheme = localStorage.getItem('gemini_terminal_theme');
        if (savedSize) setFontSize(parseInt(savedSize));
        if (savedTheme && (savedTheme === 'dark' || savedTheme === 'light' || savedTheme === 'solarized')) {
            setThemeName(savedTheme);
        }

        const updateHeight = () => {
            const h = window.innerHeight - 140;
            setContainerHeight(`${h}px`);
        };
        window.addEventListener('resize', updateHeight);
        updateHeight();
        return () => window.removeEventListener('resize', updateHeight);
    }, []);

    // Save preferences
    useEffect(() => {
        localStorage.setItem('gemini_terminal_font_size', fontSize.toString());
        localStorage.setItem('gemini_terminal_theme', themeName);
    }, [fontSize, themeName]);

    const syncSession = async () => {
        setIsSyncing(true);
        try {
            // Force stop and restart ttyd
            await fetch('/plugins/unraid-geminicli/includes/GeminiSettings.php?action=stop');
            await fetch('/plugins/unraid-geminicli/includes/GeminiSettings.php?action=start');
            setKey(Date.now());
        } catch (e) {
            console.error("Sync failed", e);
        } finally {
            setTimeout(() => setIsSyncing(false), 500);
        }
    };

    const themeParams = encodeURIComponent(THEMES[themeName]);
    // Standard ttyd URL structure
    const terminalUrl = `/webterminal/geminiterm/?theme=${themeParams}&fontSize=${fontSize}&v=${key}`;

    return (
        <div className="flex-1 flex flex-col bg-[#1e1e1e] rounded-md border border-[#333] overflow-hidden shadow-2xl" style={{ height: '100%' }}>
            {/* Standard Unraid Style Toolbar */}
            <div className="flex items-center justify-between px-4 py-2 bg-[#2a2a2a] border-b border-[#333] select-none min-h-[50px]">
                <div className="flex items-center gap-3">
                    <i className="fa fa-terminal text-orange-500 text-lg"></i>
                    <div className="flex flex-col">
                        <span className="text-white font-bold text-[11px] tracking-widest uppercase">GEMINI CLI</span>
                        <span className="text-gray-500 text-[9px] font-mono leading-none">RESTRICTED /mnt</span>
                    </div>
                </div>

                <div className="flex items-center gap-4">
                    {/* Font Scaling Group */}
                    <div className="flex items-center gap-1">
                        <button 
                            onClick={() => handleFontSize(-1)} 
                            className="w-8 h-8 flex items-center justify-center bg-[#1e1e1e] hover:bg-orange-600 text-white rounded border border-[#444] transition-all active:scale-90"
                            title="Smaller Font"
                        >
                            <i className="fa fa-minus text-[10px]"></i>
                        </button>
                        
                        <div className="w-10 h-8 flex items-center justify-center bg-[#1e1e1e] border border-[#444] rounded text-white font-mono text-xs shadow-inner">
                            {fontSize}
                        </div>

                        <button 
                            onClick={() => handleFontSize(1)} 
                            className="w-8 h-8 flex items-center justify-center bg-[#1e1e1e] hover:bg-orange-600 text-white rounded border border-[#444] transition-all active:scale-90"
                            title="Larger Font"
                        >
                            <i className="fa fa-plus text-[10px]"></i>
                        </button>
                    </div>
                    
                    {/* Theme Selector - Custom Unraid Styled */}
                    <div className="relative group">
                        <select 
                            value={themeName} 
                            onChange={(e) => setThemeName(e.target.value as any)}
                            className="bg-[#1e1e1e] text-white text-[10px] pl-3 pr-8 h-8 rounded border border-[#444] outline-none cursor-pointer hover:border-orange-500 transition-all appearance-none uppercase font-bold tracking-tight shadow-inner"
                        >
                            <option value="dark">Dark</option>
                            <option value="light">Light</option>
                            <option value="solarized">Solarized</option>
                        </select>
                        <div className="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-gray-500 group-hover:text-orange-500 transition-colors">
                            <i className="fa fa-caret-down text-[10px]"></i>
                        </div>
                    </div>

                    {/* Sync Button - Unraid Primary Style */}
                    <button 
                        onClick={syncSession}
                        disabled={isSyncing}
                        className={`h-8 flex items-center gap-2 px-4 bg-orange-600 hover:bg-orange-500 disabled:bg-gray-700 text-white text-[10px] rounded transition-all font-black tracking-widest shadow-lg active:scale-95 uppercase ${isSyncing ? 'opacity-50' : ''}`}
                    >
                        <i className={`fa fa-refresh ${isSyncing ? 'fa-spin' : ''}`}></i>
                        {isSyncing ? 'Syncing...' : 'Sync Session'}
                    </button>
                </div>
            </div>

            {/* Terminal Iframe Wrapper - FORCED HEIGHT */}
            <div className="flex-1 w-full bg-[#1e1e1e] relative" style={{ height: containerHeight }}>
                <iframe 
                    key={`${themeName}-${fontSize}-${key}`}
                    src={terminalUrl}
                    className="absolute inset-0 w-full h-full border-none"
                    style={{ minHeight: '100%' }}
                    title="Gemini Terminal"
                />
            </div>
        </div>
    );
};
