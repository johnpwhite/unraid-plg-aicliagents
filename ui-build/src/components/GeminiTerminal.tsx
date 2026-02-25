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

    const handleFontSize = (delta: number) => {
        const next = Math.min(Math.max(fontSize + delta, 8), 32);
        setFontSize(next);
    };

    // Load preferences
    useEffect(() => {
        const savedSize = localStorage.getItem('gemini_terminal_font_size');
        const savedTheme = localStorage.getItem('gemini_terminal_theme');
        if (savedSize) setFontSize(parseInt(savedSize));
        if (savedTheme && THEMES[savedTheme as keyof typeof THEMES]) {
            setThemeName(savedTheme as any);
        }
    }, []);

    // Save preferences
    useEffect(() => {
        localStorage.setItem('gemini_terminal_font_size', fontSize.toString());
        localStorage.setItem('gemini_terminal_theme', themeName);
    }, [fontSize, themeName]);

    const syncSession = async (hard: boolean = false) => {
        setIsSyncing(true);
        try {
            // action=restart kills both ttyd and the tmux session for a clean start
            const action = hard ? 'restart' : 'start';
            await fetch(`/plugins/unraid-geminicli/includes/GeminiSettings.php?action=${action}`);
            setKey(Date.now());
        } catch (e) {
            console.error("Sync failed", e);
        } finally {
            setTimeout(() => setIsSyncing(false), 1000);
        }
    };

    const themeParams = encodeURIComponent(THEMES[themeName]);
    // ttyd supports theme and fontSize via query params
    const terminalUrl = `/webterminal/geminiterm/?theme=${themeParams}&fontSize=${fontSize}&fontFamily=monospace&disableLeaveAlert=true&v=${key}`;

    return (
        <div className="flex-1 flex flex-col bg-[#1e1e1e] rounded-sm border border-[#333] overflow-hidden shadow-2xl h-full">
            {/* Toolbar - Standard Unraid Style */}
            <div className="flex items-center justify-between px-4 py-2 bg-[#2a2a2a] border-b border-[#333] select-none min-h-[52px]">
                <div className="flex items-center gap-3">
                    <i className="fa fa-terminal text-orange-500 text-lg"></i>
                    <div className="flex flex-col">
                        <span className="text-white font-bold text-[11px] tracking-wider uppercase">GEMINI CLI</span>
                        <span className="text-gray-500 text-[9px] font-mono uppercase tracking-tighter opacity-80">Restricted Access /mnt</span>
                    </div>
                </div>

                <div className="flex items-center gap-4">
                    {/* Font Size Group */}
                    <div className="flex items-center bg-[#1e1e1e] rounded-sm border border-[#444] p-0.5 shadow-inner">
                        <button 
                            onClick={() => handleFontSize(-1)} 
                            className="w-8 h-8 flex items-center justify-center hover:bg-orange-600 text-gray-400 hover:text-white transition-all"
                            title="Decrease"
                        >
                            <i className="fa fa-minus text-[10px]"></i>
                        </button>
                        
                        <div className="w-10 text-center text-xs font-bold text-white font-mono border-x border-[#333]">
                            {fontSize}
                        </div>

                        <button 
                            onClick={() => handleFontSize(1)} 
                            className="w-8 h-8 flex items-center justify-center hover:bg-orange-600 text-gray-400 hover:text-white transition-all"
                            title="Increase"
                        >
                            <i className="fa fa-plus text-[10px]"></i>
                        </button>
                    </div>
                    
                    {/* Theme Selector */}
                    <div className="relative">
                        <select 
                            value={themeName} 
                            onChange={(e) => setThemeName(e.target.value as any)}
                            className="bg-[#1e1e1e] text-white text-[10px] pl-3 pr-8 h-9 rounded-sm border border-[#444] outline-none cursor-pointer hover:border-orange-500 transition-all appearance-none uppercase font-bold tracking-tight shadow-inner min-w-[120px]"
                        >
                            <option value="dark">Dark</option>
                            <option value="light">Light</option>
                            <option value="solarized">Solarized</option>
                        </select>
                        <div className="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-gray-500">
                            <i className="fa fa-caret-down text-[10px]"></i>
                        </div>
                    </div>

                    {/* Sync Button */}
                    <div className="flex gap-1">
                        <button 
                            onClick={() => syncSession(false)}
                            disabled={isSyncing}
                            className="h-9 flex items-center gap-2 px-4 bg-[#333] hover:bg-[#444] text-white text-[10px] rounded-sm transition-all font-bold uppercase border border-[#444]"
                            title="Reconnect to existing session"
                        >
                            <i className={`fa fa-refresh ${isSyncing ? 'fa-spin' : ''}`}></i>
                            Reconnect
                        </button>
                        <button 
                            onClick={() => syncSession(true)}
                            disabled={isSyncing}
                            className="h-9 flex items-center gap-2 px-4 bg-orange-600 hover:bg-orange-500 text-white text-[10px] rounded-sm transition-all font-black tracking-widest shadow-lg active:scale-95 uppercase"
                            title="Hard Reset"
                        >
                            Hard Reset
                        </button>
                    </div>
                </div>
            </div>

            {/* Terminal Container */}
            <div className="flex-1 w-full bg-[#1e1e1e] relative overflow-hidden h-full">
                <iframe 
                    key={`${themeName}-${fontSize}-${key}`}
                    src={terminalUrl}
                    className="absolute inset-0 w-full h-full border-none"
                    title="Gemini Terminal"
                    style={{ height: '100%', width: '100%', border: 'none' }}
                />
            </div>
        </div>
    );
};
