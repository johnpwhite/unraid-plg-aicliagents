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

    useEffect(() => {
        const savedSize = localStorage.getItem('gemini_terminal_font_size');
        const savedTheme = localStorage.getItem('gemini_terminal_theme');
        if (savedSize) setFontSize(parseInt(savedSize));
        if (savedTheme && THEMES[savedTheme as keyof typeof THEMES]) {
            setThemeName(savedTheme as any);
        }
    }, []);

    useEffect(() => {
        localStorage.setItem('gemini_terminal_font_size', fontSize.toString());
        localStorage.setItem('gemini_terminal_theme', themeName);
    }, [fontSize, themeName]);

    const syncSession = async () => {
        setIsSyncing(true);
        try {
            await fetch('/plugins/unraid-geminicli/includes/GeminiSettings.php?action=stop');
            await fetch('/plugins/unraid-geminicli/includes/GeminiSettings.php?action=start');
            setKey(Date.now());
        } catch (e) {
            console.error("Sync failed", e);
        } finally {
            setTimeout(() => setIsSyncing(false), 800);
        }
    };

    const themeParams = encodeURIComponent(THEMES[themeName]);
    const terminalUrl = `/webterminal/geminiterm/?theme=${themeParams}&fontSize=${fontSize}&fontFamily=monospace&disableLeaveAlert=true&v=${key}`;

    return (
        <div className="flex-1 flex flex-col bg-[#1e1e1e] rounded-md border border-[#333] overflow-hidden shadow-xl" style={{ height: '100%', minHeight: '600px' }}>
            {/* Toolbar */}
            <div className="flex items-center justify-between px-4 py-3 bg-[#2a2a2a] border-b border-[#333] select-none">
                <div className="flex items-center gap-3">
                    <i className="fa fa-terminal text-orange-500 text-lg"></i>
                    <div className="flex flex-col">
                        <span className="text-white font-black text-[10px] tracking-widest uppercase">GEMINI CLI</span>
                        <span className="text-gray-500 text-[9px] font-mono leading-tight uppercase opacity-60">Persistent Session (/mnt)</span>
                    </div>
                </div>

                <div className="flex items-center gap-6">
                    <div className="flex items-center">
                        <button 
                            onClick={() => handleFontSize(-1)} 
                            className="w-8 h-8 flex items-center justify-center bg-[#1e1e1e] hover:bg-orange-600 text-white rounded-l border border-[#444] border-r-0 transition-all active:scale-95"
                        >
                            <i className="fa fa-minus text-[10px]"></i>
                        </button>
                        
                        <div className="w-12 h-8 flex items-center justify-center bg-[#1e1e1e] border border-[#444] text-white font-mono text-xs font-bold shadow-inner">
                            {fontSize}
                        </div>

                        <button 
                            onClick={() => handleFontSize(1)} 
                            className="w-8 h-8 flex items-center justify-center bg-[#1e1e1e] hover:bg-orange-600 text-white rounded-r border border-[#444] border-l-0 transition-all active:scale-95"
                        >
                            <i className="fa fa-plus text-[10px]"></i>
                        </button>
                    </div>
                    
                    <div className="relative">
                        <select 
                            value={themeName} 
                            onChange={(e) => setThemeName(e.target.value as any)}
                            className="bg-[#1e1e1e] text-white text-[10px] pl-3 pr-8 h-8 rounded border border-[#444] outline-none cursor-pointer hover:border-orange-500 transition-all appearance-none uppercase font-bold tracking-tight shadow-inner min-w-[120px]"
                        >
                            <option value="dark">Dark Theme</option>
                            <option value="light">Light Theme</option>
                            <option value="solarized">Solarized</option>
                        </select>
                        <div className="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-gray-500">
                            <i className="fa fa-caret-down text-[10px]"></i>
                        </div>
                    </div>

                    <button 
                        onClick={syncSession}
                        disabled={isSyncing}
                        className={`h-8 flex items-center gap-2 px-5 bg-orange-600 hover:bg-orange-500 disabled:bg-gray-700 text-white text-[10px] rounded transition-all font-black tracking-widest shadow-lg active:scale-95 uppercase ${isSyncing ? 'opacity-50 cursor-wait' : 'cursor-pointer'}`}
                    >
                        <i className={`fa fa-refresh ${isSyncing ? 'fa-spin' : ''}`}></i>
                        {isSyncing ? 'SYNCING' : 'SYNC SESSION'}
                    </button>
                </div>
            </div>

            {/* Terminal Container */}
            <div className="flex-1 w-full bg-[#1e1e1e] relative min-h-0">
                <iframe 
                    key={`${themeName}-${fontSize}-${key}`}
                    src={terminalUrl}
                    className="absolute inset-0 w-full h-full border-none"
                    title="Gemini Terminal"
                    onLoad={(e) => {
                        // Attempt to trigger internal resize of ttyd when iframe finishes loading
                        try {
                            const win = (e.target as HTMLIFrameElement).contentWindow;
                            if (win) win.dispatchEvent(new Event('resize'));
                        } catch (err) {
                            // Likely cross-origin if domain differs, but /webterminal/ should be same-origin
                        }
                    }}
                />
            </div>
        </div>
    );
};
