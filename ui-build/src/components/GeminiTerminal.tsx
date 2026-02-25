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

    // Terminal Settings Toolbar
    const handleFontSize = (delta: number) => {
        const next = Math.min(Math.max(fontSize + delta, 8), 32);
        setFontSize(next);
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
            // Send initial resize
            const dims = { cols: term.cols, rows: term.rows };
            socket.send('1' + JSON.stringify(dims));
        };

        socket.onmessage = (event) => {
            // ttyd sends data directly (first byte 0 in some versions, or raw in others)
            // If it's a blob, read it. If it's a string, write it.
            if (event.data instanceof Blob) {
                const reader = new FileReader();
                reader.onload = () => {
                    const data = reader.result as string;
                    term.write(data);
                };
                reader.readAsText(event.data);
            } else if (typeof event.data === 'string') {
                term.write(event.data);
            }
        };

        socket.onclose = () => {
            term.writeln('\r\n\x1b[1;31mConnection closed.\x1b[0m');
        };

        term.onData((data) => {
            if (socket.readyState === WebSocket.OPEN) {
                // Prepend '0' for data per ttyd protocol
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

        return () => {
            window.removeEventListener('resize', handleResize);
            term.dispose();
            socket.close();
        };
    }, []);

    return (
        <div className="flex-1 flex flex-col bg-[#1e1e1e] rounded-lg border border-[#333] overflow-hidden">
            {/* Toolbar */}
            <div className="flex items-center justify-between px-3 py-2 bg-[#2a2a2a] border-bottom border-[#333] gap-4">
                <div className="flex items-center gap-4 text-xs font-mono text-gray-400">
                    <span className="text-orange-500 font-bold uppercase">GEMINI CLI</span>
                    <span>/mnt restricted</span>
                </div>
                <div className="flex items-center gap-3">
                    <div className="flex items-center bg-[#1e1e1e] rounded border border-[#333] overflow-hidden">
                        <button onClick={() => handleFontSize(-1)} className="px-2 py-1 hover:bg-[#333] text-white">A-</button>
                        <span className="px-2 py-1 text-xs text-gray-400 border-x border-[#333] min-w-[30px] text-center">{fontSize}</span>
                        <button onClick={() => handleFontSize(1)} className="px-2 py-1 hover:bg-[#333] text-white">A+</button>
                    </div>
                    
                    <select 
                        value={themeName} 
                        onChange={(e) => setThemeName(e.target.value as any)}
                        className="bg-[#1e1e1e] text-white text-xs px-2 py-1 rounded border border-[#333] outline-none"
                    >
                        <option value="dark">Dark</option>
                        <option value="light">Light</option>
                        <option value="solarized">Solarized</option>
                    </select>

                    <button 
                        onClick={() => window.dispatchEvent(new Event('resize'))}
                        className="p-1 px-2 bg-orange-600 hover:bg-orange-500 text-white text-xs rounded transition-colors"
                    >
                        Fit
                    </button>
                </div>
            </div>

            {/* Terminal Container */}
            <div ref={terminalRef} className="flex-1 w-full h-full p-2" />
        </div>
    );
};
