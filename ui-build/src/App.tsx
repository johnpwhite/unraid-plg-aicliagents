import { GeminiTerminal } from "./components/GeminiTerminal";

export default function App() {
  return (
    <div className="gemini-cli-ui h-full w-full flex flex-col bg-transparent">
      <GeminiTerminal />
    </div>
  );
}
