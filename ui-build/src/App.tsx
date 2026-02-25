import { GeminiTerminal } from "./components/GeminiTerminal";

export default function App() {
  return (
    <div className="gemini-cli-ui h-full w-full flex flex-col p-4 bg-transparent">
      <GeminiTerminal />
    </div>
  );
}
