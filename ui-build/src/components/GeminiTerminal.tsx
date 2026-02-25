import React, { useState, useEffect } from 'react';

const THEMES = {
    dark: '{"background":"#1e1e1e","foreground":"#ffffff"}',
    light: '{"background":"#ffffff","foreground":"#1e1e1e"}',
    solarized: '{"background":"#002b36","foreground":"#839496"}'
};

export const GeminiTerminal: React.FC = () => {
    const [fontSize, setFontSize] = useState(14);
    const [themeName, setThemeName] = useState<'dark' | 'light' | 'solarized'>('dark');
    const [key, setKey] = useState(0);

    const handleFontSize = (delta: number) => {
        const next = Math.min(Math.max(fontSize + delta, 8), 32);
        setFontSize(next);
    };

    // Load saved preferences from localStorage
    useEffect(() => {
        const savedSize = localStorage.getItem('gemini_terminal_font_size');
        const savedTheme = localStorage.getItem('gemini_terminal_theme');
        if (savedSize) setFontSize(parseInt(savedSize));
        if (savedTheme && (savedTheme === 'dark' || savedTheme === 'light' || savedTheme === 'solarized')) {
            setThemeName(savedTheme);
        }
    }, []);

    // Save preferences
    useEffect(() => {
        localStorage.setItem('gemini_terminal_font_size', fontSize.toString());
        localStorage.setItem('gemini_terminal_theme', themeName);
    }, [fontSize, themeName]);

    const themeParams = encodeURIComponent(THEMES[themeName]);
    const terminalUrl = `/webterminal/geminiterm/?theme=${themeParams}&fontSize=${fontSize}&v=${key}`;

    return (
        <div className="flex-1 flex flex-col bg-[#1e1e1e] rounded-lg border border-[#333] overflow-hidden h-full shadow-lg">
            {/* Professional Toolbar inspired by Unraid / UI-UX-PRO-MAX */}
            <div className="flex items-center justify-between px-4 py-3 bg-[#2a2a2a] border-b border-[#333] select-none">
                <div className="flex items-center gap-4">
                    <div className="flex items-center gap-2">
                        <i className="fa fa-terminal text-orange-500"></i>
                        <span className="text-white font-bold tracking-wider text-xs">GEMINI TERMINAL</span>
                    </div>
                    <div className="h-4 w-px bg-[#444]"></div>
                    <span className="text-gray-400 text-[10px] font-mono uppercase opacity-60">Persistent Session (/mnt)</span>
                </div>

                <div className="flex items-center gap-4">
                    {/* Font Scaling */}
                    <div className="flex items-center bg-[#1e1e1e] rounded border border-[#444] p-0.5 overflow-hidden shadow-inner">
                        <button 
                            onClick={() => handleFontSize(-1)} 
                            className="w-7 h-7 flex items-center justify-center hover:bg-orange-600 text-gray-300 hover:text-white transition-colors"
                            title="Decrease Font Size"
                        >
                            <i className="fa fa-minus text-[10px]"></i>
                        </button>
                        <div className="w-8 text-center text-[11px] font-mono text-white border-x border-[#333]">
                            {fontSize}
                        </div>
                        <button 
                            onClick={() => handleFontSize(1)} 
                            className="w-7 h-7 flex items-center justify-center hover:bg-orange-600 text-gray-300 hover:text-white transition-colors"
                            title="Increase Font Size"
                        >
                            <i className="fa fa-plus text-[10px]"></i>
                        </button>
                    </div>
                    
                    {/* Theme Selector */}
                    <div className="relative">
                        <select 
                            value={themeName} 
                            onChange={(e) => setThemeName(e.target.value as any)}
                            className="appearance-none bg-[#1e1e1e] text-white text-[11px] pl-3 pr-8 py-1.5 rounded border border-[#444] outline-none cursor-pointer hover:border-orange-500 transition-all shadow-inner uppercase font-semibold"
                        >
                            <option value="dark">Dark Theme</option>
                            <option value="light">Light Theme</option>
                            <option value="solarized">Solarized</option>
                        </select>
                        <i className="fa fa-chevron-down absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 text-[10px] pointer-events-none"></i>
                    </div>

                    {/* Reconnect / Refresh */}
                    <button 
                        onClick={() => setKey(prev => prev + 1)}
                        className="flex items-center gap-2 px-4 py-1.5 bg-orange-600 hover:bg-orange-500 text-white text-[11px] rounded transition-all font-bold shadow-md active:scale-95"
                    >
                        <i className={`fa fa-refresh ${key > 0 ? 'fa-spin' : ''}`}></i>
                        SYNC SESSION
                    </button>
                </div>
            </div>

            {/* Terminal Iframe Wrapper */}
            <div className="flex-1 w-full bg-[#1e1e1e] relative">
                <iframe 
                    key={`${themeName}-${fontSize}-${key}`}
                    src={terminalUrl}
                    className="absolute inset-0 w-full h-full border-none"
                    title="Gemini Terminal"
                />
            </div>
        </div>
    );
};
