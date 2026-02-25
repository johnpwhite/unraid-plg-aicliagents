import { GeminiTerminal } from "./components/GeminiTerminal";

export default function App() {
  return (
    <div className="gemini-cli-ui h-full w-full flex flex-col p-4">
      <div className="mb-4">
        <h2 className="text-xl font-bold text-orange-500">Gemini CLI Restricted Terminal</h2>
        <p className="text-sm text-gray-400">Restricted access to /mnt and its subfolders.</p>
      </div>
      <GeminiTerminal />
    </div>
  );
}
