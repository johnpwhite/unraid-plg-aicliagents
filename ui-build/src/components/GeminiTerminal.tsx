import React, { useEffect, useRef } from 'react';
import { Terminal } from '@xterm/xterm';
import { WebLinksAddon } from '@xterm/addon-web-links';
import '@xterm/xterm/css/xterm.css';

export const GeminiTerminal: React.FC = () => {
    const terminalRef = useRef<HTMLDivElement>(null);
    const xtermRef = useRef<Terminal | null>(null);

    useEffect(() => {
        if (!terminalRef.current) return;

        const term = new Terminal({
            cursorBlink: true,
            fontSize: 14,
            fontFamily: 'Menlo, Monaco, "Courier New", monospace',
            theme: {
                background: '#1e1e1e',
                foreground: '#ffffff',
            },
        });

        term.loadAddon(new WebLinksAddon((_event, url) => {
            window.open(url, '_blank');
        }));

        term.open(terminalRef.current);
        xtermRef.current = term;

        const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
        const socketUrl = `${protocol}//${window.location.host}/webterminal/geminiterm/ws`;
        
        term.writeln('Connecting to Gemini CLI Terminal...');
        
        if (window.location.hostname === 'localhost') {
            term.writeln('\x1b[1;32mWelcome to Gemini CLI Restricted Shell\x1b[0m');
            term.writeln('Current directory: /mnt');
            term.write('\r\n/mnt $ ');
        } else {
            const socket = new WebSocket(socketUrl);
            
            socket.onopen = () => {
                term.writeln('Connected.');
            };

            socket.onmessage = (event) => {
                term.write(event.data);
            };

            socket.onclose = () => {
                term.writeln('\r\nConnection closed.');
            };

            term.onData((data) => {
                socket.send(data);
            });
        }

        return () => {
            term.dispose();
        };
    }, []);

    return (
        <div className="flex-1 min-h-[500px] bg-[#1e1e1e] p-2 rounded-lg border border-[#333]">
            <div ref={terminalRef} className="h-full w-full" />
        </div>
    );
};
