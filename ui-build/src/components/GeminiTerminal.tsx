import React, { useState } from 'react';

const THEMES = {
    dark: '{"background":"#1e1e1e","foreground":"#ffffff"}',
    light: '{"background":"#ffffff","foreground":"#1e1e1e"}',
    solarized: '{"background":"#002b36","foreground":"#839496"}'
};

export const GeminiTerminal: React.FC = () => {
    const [fontSize, setFontSize] = useState(14);
    const [themeName, setThemeName] = useState<'dark' | 'light' | 'solarized'>('dark');
    const [key, setKey] = useState(0); // For forcing iframe reload

    const handleFontSize = (delta: number) => {
        const next = Math.min(Math.max(fontSize + delta, 8), 32);
        setFontSize(next);
        setKey(prev => prev + 1);
    };

    const handleThemeChange = (newTheme: 'dark' | 'light' | 'solarized') => {
        setThemeName(newTheme);
        setKey(prev => prev + 1);
    };

    // Construct ttyd URL
    // Unraid Nginx proxy matches /webterminal/tag/ -> unix:/var/run/tag.sock
    // We pass theme and fontSize as query params (ttyd supports these)
    const themeParams = encodeURIComponent(THEMES[themeName]);
    const terminalUrl = `/webterminal/geminiterm/?theme=${themeParams}&fontSize=${fontSize}`;

    return (
        <div className="flex-1 h-full flex flex-col bg-[#1e1e1e] rounded-lg border border-[#333] overflow-hidden">
            {/* Toolbar */}
            <div className="flex items-center justify-between px-3 py-2 bg-[#2a2a2a] border-bottom border-[#333] gap-4 select-none">
                <div className="flex items-center gap-4 text-xs font-mono text-gray-400">
                    <span className="text-orange-500 font-bold uppercase">GEMINI CLI</span>
                    <span className="opacity-60">/mnt restricted</span>
                </div>
                <div className="flex items-center gap-3">
                    <div className="flex items-center bg-[#1e1e1e] rounded border border-[#333] overflow-hidden">
                        <button onClick={() => handleFontSize(-1)} className="px-2 py-1 hover:bg-[#333] text-white transition-colors" title="Decrease Font">A-</button>
                        <span className="px-2 py-1 text-xs text-gray-400 border-x border-[#333] min-w-[30px] text-center font-mono">{fontSize}</span>
                        <button onClick={() => handleFontSize(1)} className="px-2 py-1 hover:bg-[#333] text-white transition-colors" title="Increase Font">A+</button>
                    </div>
                    
                    <select 
                        value={themeName} 
                        onChange={(e) => handleThemeChange(e.target.value as any)}
                        className="bg-[#1e1e1e] text-white text-xs px-2 py-1 rounded border border-[#333] outline-none cursor-pointer hover:border-orange-500 transition-all"
                    >
                        <option value="dark">Dark</option>
                        <option value="light">Light</option>
                        <option value="solarized">Solarized</option>
                    </select>

                    <button 
                        onClick={() => setKey(prev => prev + 1)}
                        className="p-1 px-3 bg-orange-600 hover:bg-orange-500 text-white text-xs rounded transition-colors font-bold"
                    >
                        Reconnect
                    </button>
                </div>
            </div>

            {/* Terminal Iframe */}
            <iframe 
                key={key}
                src={terminalUrl}
                className="flex-1 w-full border-none"
                title="Gemini Terminal"
            />
        </div>
    );
};
