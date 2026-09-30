# Unraid AI CLI Agents

Run modern AI coding agents — Claude Code, Gemini CLI, GitHub Copilot,
OpenCode, Kilo Code, Codex CLI, Goose, Qwen Code, Pi Coder, Factory (Droid)
CLI, NanoCoder, Antigravity CLI, Grok Build, and Kimi Code — directly inside the Unraid WebGUI. Each agent runs on
the server itself, in its own workspace, so it keeps working after you close
the browser tab.

## Features

- **14 agents out of the box.** Install, upgrade, and switch versions from a
  single Agent Store page.
- **Web terminal in the Unraid GUI.** Every workspace opens an embedded
  terminal backed by a session manager on the server called tmux. Close the
  browser tab, come back tomorrow, and your agent is still running.
- **Reattach from your local terminal.** Register an SSH public key in
  Settings, click the key icon on a workspace, and paste the copied command
  into Windows Terminal, iTerm, or your own shell. No new client, no extra
  ports.
- **Per-workspace environment and secrets.** API keys, environment
  variables, and CLI arguments can be set per agent and overridden per
  workspace, with hot-apply so a change takes effect on the next session
  without a reinstall.
- **Persistent storage with low USB wear.** Agent files and your home
  directory live on compressed, read-only layers with a fast in-RAM write
  buffer, so they survive reboots without wearing out your flash drive.
  Choose where they live: flash, the array, a cache pool, or an Unassigned
  Devices disk.
- **Array-aware.** When the array is stopped or storage is unavailable, the
  plugin explains why and offers Emergency Mode — install an agent into RAM
  and work until the array is back. A workspace whose storage lives on flash
  keeps running uninterrupted when the array stops.
- **Version management.** A version picker and channel choice (latest, beta,
  next) per agent, with upgrade notices through Unraid's own notification
  system.
- **Graceful upgrades.** Installing or upgrading an agent finds every active
  session using it, saves each one's place, closes them cleanly, swaps the
  binary, and offers a Resume button when you come back.
- **Agent Relay.** A shared mailbox between the workspaces on this server,
  for one agent to hand work to another or keep a co-worker informed. See
  [Relay](#relay) below.
- **Favourites.** Bookmark a workspace's agent and folder so a workspace you
  closed completely comes back in one click. See
  [Workspaces](#workspaces) below.
- **File uploads.** Drag files onto the terminal, paste an image, or pick
  several at once. See [Copy, paste, and uploads](#copy-paste-and-uploads)
  below.
- **Config Hub.** One shared definition for MCP tools, instructions, skills,
  and commands, kept in sync across every agent that supports it. See
  [Config Hub](#config-hub) below.

## Getting started

Install the plugin from Community Applications (see
[Installation](#installation)), then open the **AI CLI Agents** tab.

The first time you open it, a short setup guide walks you through three
things: where to keep your data (the flash drive is the safest default; a
cache pool is faster and saves flash wear), how copy and paste work in the
terminal, and which of your installed agents need you to sign in before they
will work. Every step has a "Don't show again" choice, so you only see it
once.

To start working, press **+ New Workspace**. Pick an agent by its icon and
name, then type or browse to a folder. If that agent already has a saved
conversation for that folder, press **Resume**; otherwise press **Start
Fresh**. The workspace opens as a new tab in the drawer on the side of the
screen.

Each agent has its own sign-in step the first time you use it — an API key,
a browser login, or a device code, depending on the agent. Do this once per
agent; the credentials are saved in that agent's persistent home directory
and survive reboots and upgrades.

## Workspaces

Every agent you start lives in a workspace: an agent, a folder, and a
terminal session, kept together under one name. The drawer lists every open
workspace as a tab; click one to switch to it, or pin the drawer open so it
never collapses on its own.

A workspace's "..." menu holds the rest of what you can do to it:

- **Rename**, and **Move up** / **Move down** to reorder the drawer.
- **Session settings** — this workspace's own launch arguments, environment
  variables, secrets, and tmux profile, layered on top of the agent's
  defaults.
- **Config / assets** — the agent's file tree for this workspace (its config
  files, MCP servers, instructions, and the rest of what Config Hub can
  manage).
- **Upload file** — open the upload overlay for this workspace.
- **Relay inbox** / **Relay settings** — this workspace's Agent Relay
  mailbox and its topic subscriptions.
- **Export bundle** — save this workspace's settings (arguments, environment
  variables, tmux profile, and, if you choose, its secrets) as a portable
  file you can import into a new workspace or a fresh install.
- **Mute voice** / **Unmute voice** — stop this one workspace's agent from
  speaking, even while voice is on everywhere else.
- **Restart as new** — start a fresh conversation in the same folder,
  keeping the workspace itself and its saved settings.
- **Add to favourites** / **Update favourite** — see below.
- **Close** — end the terminal session. Nothing about the workspace's saved
  settings, secrets, or resume point is lost; closing only removes it from
  the drawer.

You can open the same workspace in two browser tabs, or on your phone and
your desktop at once. Both see the same terminal and stay in sync — neither
one knocks the other offline.

### Favourites

Bookmark a workspace's agent and folder, so you can bring it back after
closing it completely without browsing to the folder again.

- **Add it.** Open a workspace's "..." menu and choose "Add to favourites"
  (it reads "Update favourite" instead when one already exists for that
  agent and folder). The favourite saves the workspace's current name and
  voice switch too.
- **Open it from Select Workspace.** The "+ New Workspace" overlay shows a
  Favourites strip above the agent tiles. Click a chip to pre-fill the
  form — agent, folder, and name — then press Resume or Start Fresh as
  usual. A chip for an agent that is not installed shows dimmed. A chip for
  a workspace that is already open shows an "open" mark and focuses that
  workspace instead of creating a second one.
- **Open it from an empty drawer.** When every workspace is closed, the
  drawer shows your favourites right under "+ New Workspace" — one click
  opens it, resuming its last conversation when one exists.
- **Manage it.** In the overlay, each chip has a small × to remove it (one
  confirm) and a pencil to rename it.
- **What comes back on its own.** A favourite remembers only the agent, the
  folder, the name, and the voice switch. Launch arguments, environment
  variables, secrets, and the resume id are already saved per agent-and-folder
  pair and are never removed when a workspace closes — opening a favourite
  brings all of that back automatically, with nothing extra to set up.
- **Tools and commands.** An agent can list, add, and remove favourites, and
  create a workspace straight from one — see
  [Admin tools and the CLI](#admin-tools-and-the-cli).

## Terminal features

### File and web links

When an agent writes or mentions a file — `docs/specs/myspec.md`, for
example — it becomes a link you can click, opening straight in the Unraid
file editor instead of you hunting for it under Shares. A web address an
agent prints, such as an issue it just filed or a dashboard it started, is
clickable too: an ordinary address opens in a new tab, and a link to one of
this plugin's own pages opens in the tab you already have open, so a link
straight to a file opens the viewer instead of a second copy of your
terminal. Only web addresses and this plugin's own pages are ever made
clickable — nothing else in the terminal's output can turn into a link.

### Copy, paste, and uploads

**Copy and paste.** Text copied inside the terminal — by you, or by the
agent through its own copy helper — reaches your system clipboard
automatically. A Paste button sends your clipboard into the terminal when
your browser allows it; where it does not (plain HTTP, or a browser that
blocks the request), a manual copy/paste box appears instead. On iPhone and
iPad, tap and hold the terminal to open a paste box, or use the on-screen
Paste and Copy buttons — Copy grabs your current selection or the visible
screen text. Hold Shift while you drag to select text with the browser's own
selection instead of the terminal's mouse mode; a small hint about this
appears once and can be dismissed for good.

**Uploads.** Drag files onto the terminal, paste an image, or pick several
files at once; the overlay stays open while you drag and shows every file
with its size and a thumbnail. A large file uploads in small pieces, so it
works even on a slow network link. An upload always lands in the workspace
you have open, never a fixed default location. Set a size limit in Settings,
or allow files of any size.

## Voice

An agent can speak to you, on every device that has the terminal page open.
The agent tells you when it needs input, when a long task finishes, or when
an error stops its work.

Two switches control voice, and both must be on before anyone hears
anything:

- **One global switch, for every device.** Click the speaker icon docked
  next to Copy in the terminal drawer, or the "Voice (all devices)" toggle
  in Settings — either one turns voice on or off everywhere at once. There
  is no separate per-device choice: turning the drawer icon on also unlocks
  sound in that tab (a browser only allows sound after a click), and every
  other open tab's icon updates to match right away.
- **One switch per workspace.** A chatty agent does not have to speak as
  loudly as a quiet one. Mute a single workspace from its "..." menu ("Mute
  voice"); a muted row shows a small crossed-out speaker mark. A muted
  workspace's agent cannot speak even while the global switch is on — it
  hears back a clear message telling it so.

With no engine configured, your browser's own voice (the Web Speech API)
speaks the message — no install, no extra container. For a natural voice,
press **Set up natural voice** in Settings > Agent voice. The plugin checks
that Docker is on, prepares a corrected **Kokoro-FastAPI-CPU** template (its
voice model is kept on your pool drive, on a free port), and opens Unraid's
own Add Container page. Press Apply there. The Settings page waits until the
engine answers, connects it, and plays a test sentence. The download is about
3.3 GB plus a 0.7 GB voice model, and the engine uses about 1.7 GB of memory
while it runs. If Folder View 3 is installed, the container goes into an
`aicliagents` folder. If you already run Kokoro-FastAPI, the button just
connects it. You can also install it yourself and type its address as the
Endpoint URL.

**Settings.** Voice (all devices), Endpoint URL (empty means browser mode),
Voice, Speed, and an API key field (only needed if your engine requires one;
never shown back to you once set). When your engine can list its own voices
(Kokoro-FastAPI can), the Voice field becomes a dropdown filled from the
engine, with an "Other…" choice for a hand-typed id.

**Hooks for your agent.** Wire the plugin's `speak` command into your
agent's own hooks so it speaks without you asking. For Claude Code, add
these two lines to `~/.claude/settings.json` (the plugin's projected
`aicli-admin` skill carries the full JSON):

```
"Notification": [{"hooks": [{"type": "command", "command": "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/voice-hook.sh"}]}],
"Stop": [{"hooks": [{"type": "command", "command": "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/voice-hook.sh"}]}]
```

**Browser notes.** Safari only allows sound after you tap something in that
tab — turning the drawer icon on is that tap, so turn it on once in a tab
and later messages play normally there. Chrome only speaks after you click
somewhere in the page since it last loaded; after a reload with voice still
on, the first message shows a small "Voice: click to allow speech in this
tab" button instead of playing, and later messages play normally once you
click it.

**Keeping the engine up to date.** The Docker tab's own update check works
for Kokoro-FastAPI-CPU with no extra plugin — a badge shows when a newer
image is out. For unattended updates on a schedule, install the Community
Applications "Auto Update Applications" plugin and enable it for this
container.

### Voice input

Speak into an agent instead of typing. Tap the microphone icon next to the
speaker in the terminal drawer, say what you want to type, and tap again to
stop (or press `Ctrl+Shift+M`). Words are typed as you speak, phrase by
phrase, so you can watch them land while you keep talking. With a browser
recogniser, the words even appear while you are still mid-phrase and correct
themselves if the recogniser revises what it heard; turn this off with the
"Type while I speak" checkbox in the bubble if the corrections distract you.

The recognised text lands in the active workspace's terminal — it is never
sent on its own, so you always read it before you press Enter. A "Send on
stop" checkbox in the recording bubble presses Enter for you, but only once
the agent is idle at its own prompt; while the agent is busy the text still
lands, and the bubble tells you it is waiting to be sent. An info icon in
the bubble lists every spoken command and punctuation word below, for quick
reference.

With no engine configured, your browser's own speech recognition turns
speech into text on your device — no install, no extra container. This
works in Chrome, Edge, and Safari, including on an iPhone. Firefox, and
Chrome on iPhone, have no built-in recogniser; set a transcription endpoint
to use voice input there.

For a real transcription engine, install **Speaches** from Community
Applications (CPU build, OpenAI-compatible, free) and set its address as the
Transcription endpoint URL in Settings. Speaches ships with no model
installed by default — open its own web UI once and install a model (for
example `Systran/faster-whisper-small`), then set that exact name in the
Model field here. If Speaches reports it does not know the model you asked
for, the Test button names the model so the mismatch is obvious. Speaches'
own appdata folder must be owned by user id 1000, the container's own user,
or the model download fails with a permission error. Other Community
Applications templates that speak this same API: SenseVoice-API,
Fun-ASR-Nano-API, and Qwen3-ASR-API. The linuxserver "faster-whisper"
template does **not** work here — it speaks a different protocol (Wyoming),
not the one this plugin uses.

**Settings.** Transcription endpoint URL (empty means browser recognition),
Model, Language (empty means detect), and an API key field (only needed if
your engine requires one; never shown back to you once set). A Test button
records 3 seconds on this device and shows the text it recognised.

**Spoken commands.** A few words act on the terminal instead of getting
typed. Say one alone, or at the end of a longer phrase:

- "press enter" / "press return" / "enter" / "return" — press Enter.
- "press tab" — press Tab.
- "press escape" / "escape" — press Escape.
- "backspace" / "press backspace" — press Backspace once.
- "delete that" / "scratch that" — erase the words you just dictated.
- "new line" — press Enter (a terminal has no other line break).
- "press up" / "press down" / "press left" / "press right" — an arrow key.

**Spoken punctuation.** Say the name of a mark and it is typed as the mark,
attached the way you would write it by hand — a closing mark such as a
comma sticks to the word before it; an opening mark such as an open
parenthesis sticks to the word after it. For example, "hello comma world"
types "hello, world" and "open paren x close paren" types "(x)".

| Say | Types | Say | Types |
| :-- | :-- | :-- | :-- |
| comma | , | period / full stop | . |
| question mark | ? | exclamation mark / point | ! |
| colon | : | semicolon | ; |
| dash / hyphen | - | slash | / |
| backslash | \ | open/close paren | ( ) |
| open/close bracket | [ ] | open/close brace | { } |
| quote ... unquote | " ... " | apostrophe | ' |
| at sign | @ | hash / pound sign | # |
| dollar sign | $ | percent sign | % |
| ampersand | & | asterisk / star | * |
| plus sign | + | equals sign | = |
| underscore | _ | backtick | ` |
| pipe | \| | tilde | ~ |
| caret | ^ | less/greater than | < > |
| space | (one space) | | |

To say one of these words and have it typed as the word itself, put an
article or a naming verb in front of it — "the comma", "a period", "say
comma", "type comma", "word comma" all type the literal words, not the
mark. Put "literal" at the very start of a phrase to type the WHOLE phrase
exactly as spoken, commands and all — "literal press enter" types the words
"press enter", it does not press Enter.

**Privacy.** Nothing is stored: a clip lives in your browser's memory until
it is transcribed, the plugin forwards it to your engine and keeps no copy,
and the activity log records only how many characters came back, never the
words themselves.

## Home persistence

Your home directory — agent config, memory, transcripts, hub state, and
secrets — and each agent's own files live on compressed, read-only layers
with a fast in-RAM write buffer on top. Ordinary use writes to RAM; the
plugin saves a new layer to disk in the background as things change, so
nothing is lost if the power goes out mid-save, and your flash drive is not
worn down by constant small writes. Choose where these layers live —
flash (the default, works everywhere), a cache pool (faster, recommended if
you have one), the array, or an Unassigned Devices disk — from Settings,
Configuration, under Home Storage and Agent Storage.

That layered storage is not the same thing as a backup: it is one
continuously-updated copy. For a second, independent copy you can restore
from, use Home backup below.

### Where to put agent and home storage

Pick a location for Home Storage and Agent Storage in this order:

1. **A cache pool path**, for example `/mnt/cache/appdata/aicliagents`. This
   is the fastest and safest choice. The Storage picker recommends a path
   like this first, when one is available.
2. **An Unassigned Devices path**, for example `/mnt/disks/mydrive`. Use this
   when you have a spare drive that is not part of the array or a pool.
3. **A single array disk**, for example `/mnt/disk1`. This works, but an
   array disk spins up on every save.
4. **`/mnt/user`** (a user share). Use this only when none of the paths
   above is open to you — for example, you have no cache pool, no spare
   drive, and your share spans more than one disk.

Paths 1 to 3 write straight to a disk. `/mnt/user` sends every write through
Unraid's shared-folder layer, called shfs. Shfs has only about 10 worker
threads, so heavy save or merge activity through it can slow down or freeze
the whole server, not only the plugin. The Storage picker still lets you
choose a `/mnt/user` path — it warns you about this instead of blocking you,
because some setups genuinely have no other option.

When your `/mnt/user/<share>` path already lives on one pool, because the
share's "Use cache pool" setting is "Only", the plugin stores the direct
pool path instead. Your data then never passes through shfs, even though you
picked a `/mnt/user` path in the list.

### Home backup

The plugin can keep a second, plain copy of each user's home on a target you
choose. Without a second copy, a damaged layer, a failed flash drive, or a
bad test run can lose all of it.

- **Where.** Settings, Storage, Home backup. One card, one row per user
  with a home.
- **Cold or warm.** Cold is the default: the plugin closes the user's
  sessions, saves the home, copies it, then brings the sessions back. Cold
  gives the most consistent copy, because an agent's own database files are
  only safe to copy while the agent is not running. Warm skips the close —
  sessions keep running, and the copy is best effort. Use warm only when a
  short pause is not acceptable.
- **Target.** Pick a folder on a pool, an Unassigned Devices disk, or the
  array. A `/mnt/user` share path is not allowed: a home has many small
  files, and copying that many files over a share can freeze the server.
  Pick a share on the card and the plugin uses its real disk path instead.
  Click Check to see the resolved path, its filesystem, and its free space
  before you save.
- **Schedule.** Off, once a day at a time you choose, or once a week on a
  day and time you choose. The plugin owns one cron entry for this, the same
  way it owns the health-check schedule.
- **Retention.** The plugin keeps the newest copies and removes older ones,
  keeping only the number you set (5 by default). It never removes the
  newest copy.

### Restore

Each saved copy in the Home backup card has a Restore… button.

- **What it does.** Pick a saved copy and click Restore. Choose Replace or
  Merge, then confirm. Replace makes the home match the saved copy exactly;
  anything the home has that the saved copy does not have is removed. Merge
  copies the saved copy's files onto the home and removes nothing else. The
  plugin closes every session of that user first, does the restore, then
  brings the sessions back. A session that was working when it closed is
  told to continue.
- **Safety snapshot.** Before it changes anything, the plugin saves one more
  copy of the home as it is right now, labelled "pre-restore". Leave this
  checkbox on unless you have a good reason to turn it off. If a restore
  goes wrong, you can restore this safety copy to undo it.
- **A warm saved copy.** A copy made while sessions were running may not be
  perfectly consistent. The confirm tells you when this is the case.
- **Last restore.** The card shows the time, the saved copy used, the mode,
  and whether it worked. If it failed, it names the cause and the safety
  copy's location, so you can restore that copy by hand if you need to.
- **The `restore-home --yes` command.** This command line action is for your
  own shell only. It restores at once, with no confirm step. It refuses to
  run inside an agent workspace. An agent asks you to restore through the
  Manager UI instead, where you approve it first.

## Config Hub

Config Hub keeps one shared definition of the things every agent needs — MCP
servers (tools an agent can call), global instructions, skills, and
commands — and projects each one into the exact file format the agent
actually reads, whether that is JSON, TOML, or YAML.

- **One definition, every agent.** Add or change an MCP server once, tick
  which agents should receive it, and press Apply to agents. Each targeted
  agent gets it in its own config file the next time you restart it.
- **Drift is never silently overwritten.** If you edit a projected file by
  hand outside the hub, Config Hub notices and asks you to choose, per key:
  **Adopt** (pull your edit into the shared definition), **Overwrite** (put
  the shared definition back), or **Release** (stop managing that key).
- **Instructions, Skills, and Commands.** Write one instruction document or
  skill once, choose which agents should receive it, and Config Hub keeps
  each agent's own copy in sync — without touching content you wrote there
  yourself.
- **History.** Turn on the git-backed history to keep a version of your
  plugin settings and workspace list. Secrets and API keys are excluded, or
  replaced with a placeholder in what gets committed, so the record itself
  never carries a credential. Browse the timeline, see a per-file diff, and
  restore an earlier version, or push it to a remote git server you
  control.

Find all of this under Settings, Config Hub.

## Relay

Agent Relay is a shared mailbox between the workspaces on this server. It is
on from the moment you install the plugin. An agent can post to a topic
other workspaces are listening to, or send one workspace a direct message —
nothing is typed into a running terminal unless you choose to deliver it.

- **Topics are informational.** Subscribing a workspace to a topic never
  makes it responsible for acting on what arrives there. An administrator
  can name one workspace the accountable actor for a topic; only that
  workspace can act on a direct request sent to it.
- **Private messages.** Two workspaces can hold a focused conversation in a
  private thread without adding noise to a topic everyone else watches.
- **Native tools where they exist.** Claude Code and Codex CLI receive Relay
  as native MCP tools once you turn Relay MCP on for them (an already-running
  agent needs one restart to pick up the change). Every other supported
  agent gets the same operations through a command available in its own
  shell, with no extra setup.
- **Connect another machine.** Turn on "Allow other machines to connect" in
  Settings, Agent Relay, and give a remote its own access token. That
  machine then appears as a contact your workspaces can message, over the
  same encrypted connection as the web interface. Treat each token like a
  password: anyone holding it can read that remote's inbox and send
  messages as it, with no separate Unraid login required.
- **Unraid notifications, mirrored.** The plugin's own Unraid notifications
  are copied into a fixed Relay topic, so an agent can see them the same way
  a person would.

Find all of this under Settings, Agent Relay.

## Settings

The plugin's settings live on the **AI CLI Agents** page under Unraid's
Settings menu, in six tabs:

- **Configuration** — the plugin's own behaviour: which Unraid user runs
  your terminals, the folder a new workspace starts in, the upload size
  limit, terminal theme and font size, whether a workspace continues its
  work by itself after a restart, how often the plugin checks for new agent
  versions and how long it remembers the results, the automatic home-save
  interval, terminal input safety rules (commands the plugin will not type
  into a busy agent's terminal on your behalf), your device's label and
  whether a screen sample helps Relay recognise your agent's screen, the
  Plugin management toggle (see below), Agent voice and voice input, and
  your registered SSH keys.
- **Agent Store** — install, upgrade, and configure each agent: its version
  and update channel, its default secrets and environment variables, its
  default launch arguments, and its default terminal profile.
- **Home Storage** — where your home directory and each agent's files live,
  the tools to move or consolidate them, and the Home backup card.
- **Config Hub** — see [Config Hub](#config-hub) above.
- **Agent Relay** — see [Relay](#relay) above.
- **Debug Console** — see [Troubleshooting](#troubleshooting) below.

## Admin tools and the CLI

Turn on **Plugin management** in Settings, Configuration, and an agent
working inside a workspace can ask the plugin about its own state and make
routine changes to it, the same things you could do yourself from the
WebUI.

- **Read tools** answer a question and change nothing: list workspaces,
  describe one workspace, list installed agents, check storage, list recent
  activity, read a setting, read the log, and list favourites. Use these
  freely — no confirmation, no record in the Activity tray.
- **Change tools** take effect at once and are recorded in the Activity
  tray, the same as a change you make yourself: create or update a
  workspace, set its launch arguments or environment variables, turn an
  agent's auto-launch or update channel on or off, change one allow-listed
  setting, speak a message, type text into a workspace, add or remove a
  favourite, and save a user's home to its storage layers.
- **Destructive actions never run by themselves.** Deleting a workspace,
  upgrading an agent, backing up or restoring a home, and consolidating a
  home's storage layers (which closes its sessions) can only be **proposed**
  by an agent. The proposal waits in the Manager UI for you to approve it —
  an agent can never approve its own proposal, and it is told never to sit
  and wait for one.

Every one of these tools is available two ways: as native tools for agents
whose client supports MCP, and as plain commands from a shell, so an agent
that has no MCP tools — or one that refuses MCP on principle, like Pi
Coder — can still ask and act the same way. Secret values are never shown
back through either path, only the names of the keys that hold them.

## Health

A small coloured dot near the top of the Settings page shows the plugin's
own health at a glance: green for all clear, amber for a warning, red for
something that needs attention. Hover it to see what is not fine; click it
to jump straight to the Debug Console.

Behind the dot, the plugin checks itself roughly once a minute: that its
background supervisor is alive, that its storage layers match what it
expects to have mounted, that the flash drive and its working storage have
room to spare, that its own log is not growing without bound, and that
recent pushes to your browser have not started failing. While you have them
configured, it also checks that voice, voice input, favourites, and home
backup are working. A warning does not stop you working — it tells you
something is worth a look before it becomes a problem.

## Troubleshooting

Start with the **Debug Console** tab in Settings.

- The log viewer shows recent plugin activity. Filter it by agent or
  subsystem, and choose how many lines to show.
- **Download support bundle** builds a zip of your logs and configuration
  with every secret and API key removed, ready to attach to a bug report.
- **Copy forum post** copies a short, redacted summary formatted for the
  Unraid forum.
- **Create GitHub issue** opens a prefilled issue on the plugin's GitHub
  repository — nothing is posted until you review it and press submit
  yourself.
- **Check known issues** compares your version against a small public list
  the developer maintains, and tells you if what you are seeing is already
  known.

If a workspace will not start because its storage is not ready, the plugin
explains what is wrong and offers a fix: restore from a sibling copy, start
fresh, enter Emergency Mode to keep working in RAM, or confirm that this
flash drive really belongs to this server. If the Unraid array is stopped,
a workspace whose storage lives on flash keeps running uninterrupted; one
whose storage lives on the array offers Emergency Mode until the array
starts again.

If a home is damaged beyond a quick fix, restore it from a saved copy — see
[Restore](#restore) above.

For anything else, the forum thread below, or a Forgejo/GitHub issue, is the
place to ask.

## Requirements

- Unraid 7.2 or later
- Each agent has its own provider-side requirements (API key, login, etc.) — set them on the agent's Settings card

## Installation

Available via Community Applications. Search for "AI CLI Agents".

## Support

[Forum thread](https://forums.unraid.net/topic/197460-plugin-support-unraid-tab-for-ai-cli-coding-agents-gemini-cli-claude-code-opencode-kilo-code-pi-coder-codex-cli-factory-droid-cli-copilot-nano-coder/)
