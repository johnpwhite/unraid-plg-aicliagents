import React, { useEffect, useRef, useState } from 'react';
import { Terminal } from '@xterm/xterm';
import { WebLinksAddon } from '@xterm/addon-web-links';
import { FitAddon } from '@xterm/addon-fit';
import '@xterm/xterm/css/xterm.css';

const THEMES = {
    dark: { background: '#1e1e1e', foreground: '#ffffff' },
    light: { background: '#ffffff', foreground: '#1e1e1e' },
    solarized: { background: '#002b36', foreground: '#839496' }
};

export const GeminiTerminal: React.FC = () => {
    const terminalRef = useRef<HTMLDivElement>(null);
    const xtermRef = useRef<Terminal | null>(null);
    const fitAddonRef = useRef<FitAddon | null>(null);
    const socketRef = useRef<WebSocket | null>(null);

    const [fontSize, setFontSize] = useState(14);
    const [themeName, setThemeName] = useState<'dark' | 'light' | 'solarized'>('dark');

    const handleFontSize = (delta: number) => {
        const next = Math.min(Math.max(fontSize + delta, 8), 32);
        setFontSize(next);
    };

    const clearTerminal = () => {
        xtermRef.current?.clear();
        xtermRef.current?.focus();
    };

    useEffect(() => {
        if (!xtermRef.current) return;
        xtermRef.current.options.fontSize = fontSize;
        fitAddonRef.current?.fit();
    }, [fontSize]);

    useEffect(() => {
        if (!xtermRef.current) return;
        xtermRef.current.options.theme = THEMES[themeName];
    }, [themeName]);

    useEffect(() => {
        if (!terminalRef.current) return;

        const term = new Terminal({
            cursorBlink: true,
            fontSize: fontSize,
            fontFamily: 'Menlo, Monaco, "Courier New", monospace',
            theme: THEMES[themeName],
            allowProposedApi: true,
            scrollback: 1000,
        });

        const fitAddon = new FitAddon();
        term.loadAddon(fitAddon);
        term.loadAddon(new WebLinksAddon((_event, url) => {
            window.open(url, '_blank');
        }));

        term.open(terminalRef.current);
        fitAddon.fit();
        
        xtermRef.current = term;
        fitAddonRef.current = fitAddon;

        const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
        const socketUrl = `${protocol}//${window.location.host}/webterminal/geminiterm/ws`;
        
        term.writeln('\x1b[1;33mConnecting to Gemini CLI Terminal...\x1b[0m');
        
        const socket = new WebSocket(socketUrl);
        socketRef.current = socket;
        
        socket.onopen = () => {
            term.writeln('\x1b[1;32mConnected.\x1b[0m');
            // Protocol 1.7+ : type '1' + JSON for resize
            socket.send('1' + JSON.stringify({ cols: term.cols, rows: term.rows }));
            term.focus();
        };

        socket.onmessage = async (event) => {
            let data: string | ArrayBuffer;
            if (event.data instanceof Blob) {
                data = await event.data.text();
            } else {
                data = event.data;
            }

            if (typeof data === 'string') {
                // Some ttyd versions send data with '0' prefix, others raw
                if (data.startsWith('0')) {
                    term.write(data.substring(1));
                } else {
                    term.write(data);
                }
            }
        };

        socket.onclose = () => {
            term.writeln('\r\n\x1b[1;31mConnection closed.\x1b[0m');
        };

        term.onData((data) => {
            if (socket.readyState === WebSocket.OPEN) {
                // Prepend '0' for data per standard ttyd protocol
                socket.send('0' + data);
            }
        });

        const handleResize = () => {
            fitAddon.fit();
            if (socket.readyState === WebSocket.OPEN) {
                socket.send('1' + JSON.stringify({ cols: term.cols, rows: term.rows }));
            }
        };

        window.addEventListener('resize', handleResize);
        
        // Focus terminal on click anywhere in container
        terminalRef.current.addEventListener('click', () => term.focus());

        return () => {
            window.removeEventListener('resize', handleResize);
            term.dispose();
            socket.close();
        };
    }, []);

    return (
        <div className="flex-1 flex flex-col bg-[#1e1e1e] rounded-lg border border-[#333] overflow-hidden">
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
                        onChange={(e) => setThemeName(e.target.value as any)}
                        className="bg-[#1e1e1e] text-white text-xs px-2 py-1 rounded border border-[#333] outline-none cursor-pointer hover:border-orange-500 transition-all"
                    >
                        <option value="dark">Dark</option>
                        <option value="light">Light</option>
                        <option value="solarized">Solarized</option>
                    </select>

                    <div className="flex gap-1">
                        <button 
                            onClick={clearTerminal}
                            className="p-1 px-3 bg-[#333] hover:bg-[#444] text-white text-xs rounded transition-colors"
                        >
                            Clear
                        </button>
                        <button 
                            onClick={() => window.dispatchEvent(new Event('resize'))}
                            className="p-1 px-3 bg-orange-600 hover:bg-orange-500 text-white text-xs rounded transition-colors font-bold"
                        >
                            Fit
                        </button>
                    </div>
                </div>
            </div>

            {/* Terminal Container */}
            <div 
                ref={terminalRef} 
                className="flex-1 w-full h-full p-2 cursor-text"
                style={{ backgroundColor: THEMES[themeName].background }}
            />
        </div>
    );
};
