import React, { useState, useEffect } from 'react';

const THEMES = {
    dark: '{"background":"#1e1e1e","foreground":"#ffffff"}',
    light: '{"background":"#ffffff","foreground":"#1e1e1e"}',
    solarized: '{"background":"#002b36","foreground":"#839496"}'
};

export const GeminiTerminal: React.FC = () => {
    const [config, setConfig] = useState<any>(null);
    const [key, _] = useState(Date.now());
    
    useEffect(() => {
        fetch('/plugins/unraid-geminicli/includes/GeminiSettings.php?action=debug')
            .then(r => r.json())
            .then(data => {
                if (data && data.config) {
                    setConfig(data.config);
                }
            })
            .catch(err => console.error("Failed to load terminal config", err));
    }, []);

    if (!config) {
        return (
            <div className="flex-1 flex items-center justify-center bg-[#1e1e1e] font-mono text-[10px] text-gray-500 uppercase tracking-[0.2em] animate-pulse">
                Initializing Gemini Session...
            </div>
        );
    }

    const themeJson = THEMES[config.theme as keyof typeof THEMES] || THEMES.dark;
    const themeParams = encodeURIComponent(themeJson);
    const terminalUrl = `/webterminal/geminiterm/?theme=${themeParams}&fontSize=${config.font_size}&fontFamily=monospace&disableLeaveAlert=true&v=${key}`;

    return (
        <div className="flex-1 flex flex-col bg-[#1e1e1e] overflow-hidden h-full">
            <div className="flex-1 w-full bg-[#1e1e1e] relative overflow-hidden h-full">
                <iframe 
                    key={`${config.theme}-${config.font_size}-${key}`}
                    src={terminalUrl}
                    className="absolute inset-0 w-full h-full border-none"
                    title="Gemini Terminal"
                    style={{ height: '100%', width: '100%' }}
                />
            </div>
        </div>
    );
};
