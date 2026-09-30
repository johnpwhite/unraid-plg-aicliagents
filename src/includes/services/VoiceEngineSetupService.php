<?php
/**
 * <module_context>
 *     <name>VoiceEngineSetupService</name>
 *     <description>The guided natural-voice setup (docs/specs/AGENT_VOICE.md,
 *     "#323 guided setup"). Checks that Docker is on, finds an existing Kokoro
 *     container, or writes a corrected Kokoro-FastAPI-CPU template to dockerMan's
 *     user templates (no host folder mapped, a free host port) so Unraid's own
 *     Add Container page can create the container.
 *     Then it probes the engine, saves `tts_url`/`tts_voice`, and puts the
 *     container in the reserved Folder View 3 folder.</description>
 *     <dependencies>AdminService (setSetting for tts_url/tts_voice), AtomicWriteService
 *     (template and docker.json writes).</dependencies>
 *     <constraints>Never runs docker and never calls a Docker API write endpoint:
 *     only GET /_ping, /containers/json and /containers/{id}/json. No shell. The
 *     engine URL is always built here from the container's own settings, never
 *     taken from a request. Never writes a /mnt/user path. Never deletes a file:
 *     an existing template is renamed to a dated .aicli-bak copy.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class VoiceEngineSetupService
{
    public const CONTAINER_NAME = 'Kokoro-FastAPI-CPU';
    public const TEMPLATE_FILE  = 'my-Kokoro-FastAPI-CPU.xml';
    public const IMAGE          = 'ghcr.io/remsky/kokoro-fastapi-cpu';
    /** Any Kokoro-FastAPI image (CPU or GPU) counts as an existing engine. */
    public const IMAGE_PREFIX   = 'ghcr.io/remsky/kokoro-fastapi';
    public const CONTAINER_PORT = 8880;
    public const PORT_FIRST     = 8880;
    public const PORT_LAST      = 8899;
    /**
     * The image's own model folder. The template must NEVER map a host folder
     * onto it (#378): the image runs as uid 1000 and bundles the model here, so
     * a mapped folder hides the model and, when Unraid creates it as 99:100 mode
     * 755, the container stops at once with "Permission denied".
     */
    public const MODELS_DIR     = '/app/api/src/models';
    /** Help for a container made from the older template that mapped a host model folder (#378). */
    public const PERMISSION_HELP = 'If the voice engine container stops with Permission denied, remove its Model cache path in the container settings.';
    public const FOLDER_ID      = 'gkGiU3d6w06mqID9sP4R';
    public const FOLDER_NAME    = 'aicliagents';
    public const DEFAULT_VOICE  = 'af_heart';

    // ---- test seams (null = the real path / transport) ----------------------
    public static ?string $dockerCfgPath = null;
    public static ?string $templatesDir = null;
    public static ?string $folderViewPluginDir = null;
    public static ?string $folderViewCfgDir = null;
    /** @var string[]|null /proc/net/tcp-style files to read listening ports from. */
    public static ?array $procNetPaths = null;
    /** @var callable|null fn(string $path): ?array — decoded JSON of a Docker API GET, null on failure. */
    public static $dockerApi = null;
    /** @var callable|null fn(string $url): array{status:int,body:string} — GET for the engine probe. */
    public static $httpGet = null;
    /** @var callable|null fn(): string — this server's LAN address. */
    public static $serverIp = null;
    /** @var callable|null fn(): int — clock for the backup file name. */
    public static $now = null;

    public static function resetSeams(): void
    {
        self::$dockerCfgPath = self::$templatesDir = self::$folderViewPluginDir = self::$folderViewCfgDir = null;
        self::$procNetPaths = null;
        self::$dockerApi = self::$httpGet = self::$serverIp = self::$now = null;
    }

    private static function templatesDir(): string
    {
        return rtrim(self::$templatesDir ?? '/boot/config/plugins/dockerMan/templates-user', '/');
    }

    public static function templatePath(): string
    {
        return self::templatesDir() . '/' . self::TEMPLATE_FILE;
    }

    /** Unraid's own Add Container page for the plugin's template. */
    public static function addContainerUrl(): string
    {
        return '/Docker/AddContainer?xmlTemplate=' . rawurlencode('user:' . self::templatePath());
    }

    // ---- Docker state (read-only) -------------------------------------------

    /**
     * @return array{enabled:bool,running:bool,message:string}
     */
    public static function dockerState(): array
    {
        $cfgPath = self::$dockerCfgPath ?? '/boot/config/docker.cfg';
        $cfg = is_file($cfgPath) ? (@parse_ini_file($cfgPath) ?: []) : [];
        if (strtolower((string)($cfg['DOCKER_ENABLED'] ?? '')) !== 'yes') {
            return ['enabled' => false, 'running' => false,
                'message' => 'Docker is turned off. Turn it on in Settings > Docker, then try again.'];
        }
        $ping = self::docker('/_ping');
        if ($ping === null) {
            return ['enabled' => true, 'running' => false,
                'message' => 'Docker is turned on but is not running (the array may be stopped). Start the array, then try again.'];
        }
        return ['enabled' => true, 'running' => true, 'message' => ''];
    }

    /** @return array|null decoded JSON, or null when Docker does not answer. */
    private static function docker(string $path): ?array
    {
        if (self::$dockerApi !== null) {
            return (self::$dockerApi)($path);
        }
        if (!function_exists('curl_init') || !file_exists('/var/run/docker.sock')) return null;
        $ch = curl_init('http://localhost' . $path);
        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false || $status < 200 || $status >= 300) return null;
        if ($path === '/_ping') return ['ok' => true];
        $data = json_decode((string)$body, true);
        return is_array($data) ? $data : null;
    }

    /**
     * The existing Kokoro-FastAPI container, if any. A running one wins over a
     * stopped one.
     *
     * @return array{id:string,name:string,running:bool,state:string,image:string}|null
     */
    public static function findExisting(): ?array
    {
        $list = self::docker('/containers/json?all=1');
        if (!is_array($list)) return null;
        $best = null;
        foreach ($list as $c) {
            if (!is_array($c)) continue;
            $image = (string)($c['Image'] ?? '');
            if (stripos($image, self::IMAGE_PREFIX) !== 0) continue;
            $name = ltrim((string)(($c['Names'][0] ?? '')), '/');
            $entry = [
                'id' => (string)($c['Id'] ?? ''),
                'name' => $name,
                'running' => ($c['State'] ?? '') === 'running',
                'state' => (string)($c['State'] ?? ''),
                'image' => $image,
            ];
            if ($best === null || ($entry['running'] && !$best['running'])) $best = $entry;
        }
        return $best;
    }

    /**
     * The engine URL for a container, built from ITS OWN settings — never from
     * a request. Bridge: server LAN IP + the host port bound to 8880. Host
     * network: server IP + 8880. Another network with its own address: that
     * address + 8880. null when no reachable address is known.
     */
    public static function engineUrlFor(string $containerId): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $containerId)) return null;
        $info = self::docker('/containers/' . $containerId . '/json');
        if (!is_array($info)) return null;
        $mode = (string)($info['HostConfig']['NetworkMode'] ?? 'bridge');
        $key = self::CONTAINER_PORT . '/tcp';
        if ($mode === 'host') {
            return self::urlFor(self::serverIp(), self::CONTAINER_PORT);
        }
        if ($mode === 'bridge' || $mode === 'default' || $mode === '') {
            $port = 0;
            foreach ([(array)($info['NetworkSettings']['Ports'][$key] ?? []), (array)($info['HostConfig']['PortBindings'][$key] ?? [])] as $bindings) {
                foreach ($bindings as $b) {
                    $p = (int)($b['HostPort'] ?? 0);
                    if ($p > 0) { $port = $p; break 2; }
                }
            }
            return $port > 0 ? self::urlFor(self::serverIp(), $port) : null;
        }
        foreach ((array)($info['NetworkSettings']['Networks'] ?? []) as $net) {
            $ip = (string)($net['IPAddress'] ?? '');
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return self::urlFor($ip, self::CONTAINER_PORT);
            }
        }
        return null;
    }

    private static function urlFor(string $host, int $port): ?string
    {
        if ($host === '' || $port < 1 || $port > 65535) return null;
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) $host = "[$host]";
        return "http://$host:$port";
    }

    private static function serverIp(): string
    {
        if (self::$serverIp !== null) return (string)(self::$serverIp)();
        $net = @parse_ini_file('/boot/config/network.cfg') ?: [];
        foreach (['IPADDR:0', 'IPADDR'] as $k) {
            if (!empty($net[$k]) && filter_var($net[$k], FILTER_VALIDATE_IP)) return (string)$net[$k];
        }
        $ini = @parse_ini_file('/var/local/emhttp/network.ini', true) ?: [];
        foreach ($ini as $section) {
            foreach (['IPADDR:0', 'IPADDR'] as $k) {
                if (is_array($section) && !empty($section[$k]) && filter_var($section[$k], FILTER_VALIDATE_IP)) return (string)$section[$k];
            }
        }
        $h = gethostbyname((string)gethostname());
        return filter_var($h, FILTER_VALIDATE_IP) ? $h : '127.0.0.1';
    }

    // ---- host port ------------------------------------------------------------

    /** @return array<int,bool> host ports in LISTEN state or bound by any container. */
    public static function usedPorts(): array
    {
        $used = [];
        foreach (self::$procNetPaths ?? ['/proc/net/tcp', '/proc/net/tcp6'] as $f) {
            foreach ((array)@file($f, FILE_IGNORE_NEW_LINES) as $i => $line) {
                if ($i === 0) continue;
                $cols = preg_split('/\s+/', trim((string)$line));
                if (count($cols) < 4 || $cols[3] !== '0A') continue; // 0A = LISTEN
                $local = explode(':', $cols[1]);
                $port = hexdec((string)end($local));
                if ($port > 0) $used[(int)$port] = true;
            }
        }
        $list = self::docker('/containers/json?all=1');
        foreach ((array)$list as $c) {
            if (!is_array($c)) continue;
            foreach ((array)($c['Ports'] ?? []) as $p) {
                $pub = (int)($p['PublicPort'] ?? 0);
                if ($pub > 0) $used[$pub] = true;
            }
            if (($c['State'] ?? '') !== 'running' && !empty($c['Id'])) {
                // A stopped container still owns its binding when it starts again.
                $info = self::docker('/containers/' . rawurlencode((string)$c['Id']) . '/json');
                foreach ((array)($info['HostConfig']['PortBindings'] ?? []) as $bindings) {
                    foreach ((array)$bindings as $b) {
                        $hp = (int)($b['HostPort'] ?? 0);
                        if ($hp > 0) $used[$hp] = true;
                    }
                }
            }
        }
        return $used;
    }

    /** The first free host port in 8880–8899, or null. */
    public static function choosePort(): ?int
    {
        $used = self::usedPorts();
        for ($p = self::PORT_FIRST; $p <= self::PORT_LAST; $p++) {
            if (!isset($used[$p])) return $p;
        }
        return null;
    }

    // ---- template -------------------------------------------------------------

    /**
     * The corrected Kokoro-FastAPI-CPU template (see the spec's diff table).
     * It maps no host folder: the image bundles the voice model (#378).
     * Throws on an invalid port.
     */
    public static function templateXml(int $port): string
    {
        if ($port < 1024 || $port > 65535) throw new \InvalidArgumentException('invalid port');
        $x = static fn(string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $cfg = static function (string $name, string $target, string $default, string $value, string $type, string $display, string $desc, string $extra = '') use ($x): string {
            return '  <Config Name="' . $x($name) . '" Target="' . $x($target) . '" Default="' . $x($default) . '"' . $extra
                . ' Description="' . $x($desc) . '" Type="' . $x($type) . '" Display="' . $x($display) . '" Required="true" Mask="false">'
                . $x($value) . "</Config>\n";
        };
        $health = "--health-cmd='curl -fsS -m 5 http://localhost:" . self::CONTAINER_PORT . "/v1/audio/voices -o /dev/null || exit 1' --health-interval=60s --health-timeout=10s --health-retries=3";
        $out  = "<?xml version=\"1.0\"?>\n<Container version=\"2\">\n";
        $out .= '  <Name>' . self::CONTAINER_NAME . "</Name>\n";
        $out .= '  <Repository>' . self::IMAGE . ":latest</Repository>\n";
        $out .= '  <Registry>' . self::IMAGE . "</Registry>\n";
        $out .= "  <Branch>\n    <Tag>latest</Tag>\n    <TagDescription>Latest stable release</TagDescription>\n  </Branch>\n";
        $out .= "  <Network>bridge</Network>\n";
        $out .= '  <ExtraParams>' . $x($health) . "</ExtraParams>\n";
        $out .= '  <WebUI>http://[IP]:[PORT:' . self::CONTAINER_PORT . "]/</WebUI>\n";
        $out .= "  <Privileged>false</Privileged>\n";
        $out .= "  <Support>https://github.com/remsky/Kokoro-FastAPI/issues</Support>\n";
        $out .= "  <Project>https://github.com/remsky/Kokoro-FastAPI</Project>\n";
        $out .= '  <Overview>' . $x('Kokoro-82M text-to-speech (CPU build), the natural voice for the AI CLI Agents plugin. Written by the plugin\'s "Set up natural voice" button from the nwithan8 template, corrected: no host folder is mapped. The image already contains the voice model (' . self::MODELS_DIR . '); a mapped folder hides the model or the app, and the container cannot start. Uses about 1.7 GB of memory while running.') . "</Overview>\n";
        $out .= "  <Beta>False</Beta>\n";
        $out .= "  <Category>AI: Productivity: Tools: Other: Status:Stable</Category>\n";
        $out .= "  <Icon>https://raw.githubusercontent.com/nwithan8/unraid_templates/master/images/kokoro-fastapi-icon.png</Icon>\n";
        $out .= "  <TemplateURL>https://raw.githubusercontent.com/nwithan8/unraid_templates/main/templates/kokoro_fastapi_cpu.xml</TemplateURL>\n";
        $out .= "  <Maintainer>\n    <WebPage>https://github.com/nwithan8</WebPage>\n  </Maintainer>\n";
        $out .= $cfg('Web UI Port', (string)self::CONTAINER_PORT, (string)self::CONTAINER_PORT, (string)$port, 'Port', 'always', 'Host port for the speech API. The plugin picked a free one.', ' Mode="tcp"');
        $out .= $cfg('Download Model', 'DOWNLOAD_MODEL', 'true', 'true', 'Variable', 'advanced-hide', 'Download the voice model only if the image does not already contain it.');
        $out .= $cfg('Python Path', 'PYTHONPATH', '/app:/app/api', '/app:/app/api', 'Variable', 'advanced-hide', 'Python path environment variable.');
        foreach ([
            ['ONNX Optimization - Thread Count', 'ONNX_NUM_THREADS', '8', 'Number of threads to use for ONNX model inference'],
            ['ONNX Optimization - Inter Op Thread Count', 'ONNX_INTER_OP_THREADS', '4', 'ONNX inter operation thread count'],
            ['ONNX Optimization - Execution Mode', 'ONNX_EXECUTION_MODE', 'parallel', 'ONNX execution mode'],
            ['ONNX Optimization - Optimization Level', 'ONNX_OPTIMIZATION_LEVEL', 'all', 'ONNX optimization level'],
            ['ONNX Optimization - Memory Pattern', 'ONNX_MEMORY_PATTERN', 'true', 'Enable ONNX memory pattern optimization'],
            ['ONNX Optimization - Arena Extend Strategy', 'ONNX_ARENA_EXTEND_STRATEGY', 'kNextPowerOfTwo', 'ONNX arena extend strategy'],
            ['Log Level', 'API_LOG_LEVEL', 'INFO', 'Logging level for the API'],
        ] as [$n, $t, $v, $d]) {
            $out .= $cfg($n, $t, $v, $v, 'Variable', 'advanced-hide', $d);
        }
        $out .= "</Container>\n";
        return $out;
    }

    // ---- the three steps --------------------------------------------------------

    /**
     * Step 2 of the flow. Returns one of:
     *  {status:error, message}                          — stop, say why
     *  {status:ok, mode:'existing', container, running} — connect only, no write
     *  {status:ok, mode:'template', addContainerUrl, port, backup?}
     */
    public static function prepare(): array
    {
        $docker = self::dockerState();
        if (!$docker['running']) return ['status' => 'error', 'code' => $docker['enabled'] ? 'docker_stopped' : 'docker_off', 'message' => $docker['message']];

        $existing = self::findExisting();
        if ($existing !== null) {
            return ['status' => 'ok', 'mode' => 'existing', 'container' => $existing['name'], 'running' => $existing['running']];
        }

        $port = self::choosePort();
        if ($port === null) {
            return ['status' => 'error', 'code' => 'no_port', 'message' => 'Ports ' . self::PORT_FIRST . ' to ' . self::PORT_LAST . ' are all in use. Free one, then try again.'];
        }

        $xml = self::templateXml($port);
        $dir = self::templatesDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['status' => 'error', 'code' => 'write_failed', 'message' => 'Could not create the Docker templates folder on the flash drive.'];
        }
        $path = self::templatePath();
        $backup = null;
        if (is_file($path)) {
            if ((string)@file_get_contents($path) !== $xml) {
                $ts = self::$now !== null ? (int)(self::$now)() : time();
                $backup = $path . '.aicli-bak-' . date('Ymd-His', $ts);
                if (!@rename($path, $backup)) {
                    return ['status' => 'error', 'code' => 'write_failed', 'message' => 'Could not keep a copy of the existing Kokoro template, so it was not replaced.'];
                }
            }
        }
        if (!AtomicWriteService::write($path, $xml)) {
            return ['status' => 'error', 'code' => 'write_failed', 'message' => 'Could not write the Kokoro template to the flash drive.'];
        }
        $out = ['status' => 'ok', 'mode' => 'template', 'addContainerUrl' => self::addContainerUrl(), 'port' => $port];
        if ($backup !== null) $out['backup'] = basename($backup);
        return $out;
    }

    /**
     * Step 4 (the poll). Read-only.
     * state: docker_off | docker_stopped | no_container | stopped | starting | ready
     */
    public static function check(): array
    {
        $docker = self::dockerState();
        if (!$docker['running']) {
            return ['status' => 'ok', 'state' => $docker['enabled'] ? 'docker_stopped' : 'docker_off', 'message' => $docker['message']];
        }
        $c = self::findExisting();
        if ($c === null) {
            return ['status' => 'ok', 'state' => 'no_container', 'message' => 'Waiting for you to press Apply on the Add Container page. Unraid downloads the image first; this can take several minutes.'];
        }
        if (!$c['running']) {
            return ['status' => 'ok', 'state' => 'stopped', 'container' => $c['name'], 'message' => 'The ' . $c['name'] . ' container is stopped. Start it from the Docker tab. ' . self::PERMISSION_HELP];
        }
        $url = self::engineUrlFor($c['id']);
        if ($url === null) {
            return ['status' => 'ok', 'state' => 'starting', 'container' => $c['name'], 'message' => 'The ' . $c['name'] . ' container has no port for the speech API. Check its port on the Docker tab.'];
        }
        $voices = self::probe($url);
        if ($voices === []) {
            return ['status' => 'ok', 'state' => 'starting', 'container' => $c['name'], 'url' => $url, 'message' => 'Kokoro is starting. The first start can take a minute.'];
        }
        return ['status' => 'ok', 'state' => 'ready', 'container' => $c['name'], 'url' => $url, 'voices' => count($voices)];
    }

    /** @return string[] voice ids the engine lists; [] when it does not answer yet. */
    public static function probe(string $url): array
    {
        $target = rtrim($url, '/') . '/v1/audio/voices';
        if (self::$httpGet !== null) {
            $r = (self::$httpGet)($target);
        } else {
            $ch = curl_init($target);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 4);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_MAXFILESIZE, 1048576);
            $body = curl_exec($ch);
            $r = ['status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $body === false ? '' : (string)$body];
            curl_close($ch);
        }
        if ((int)($r['status'] ?? 0) !== 200) return [];
        $data = json_decode((string)($r['body'] ?? ''), true);
        $ids = [];
        foreach ((array)($data['voices'] ?? []) as $v) {
            $id = is_array($v) ? (string)($v['id'] ?? $v['name'] ?? '') : (string)$v;
            if ($id !== '' && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $id)) $ids[] = $id;
        }
        return $ids;
    }

    /**
     * Step 5. Probes again, saves tts_url and tts_voice, files the container
     * in the Folder View 3 folder.
     */
    public static function finish(): array
    {
        $c = self::findExisting();
        if ($c === null || !$c['running']) {
            return ['status' => 'error', 'message' => 'The Kokoro container is not running yet.'];
        }
        $url = self::engineUrlFor($c['id']);
        $voices = $url !== null ? self::probe($url) : [];
        if ($url === null || $voices === []) {
            return ['status' => 'error', 'message' => 'Kokoro does not answer yet. Wait a moment and try again.'];
        }
        $voice = in_array(self::DEFAULT_VOICE, $voices, true) ? self::DEFAULT_VOICE : $voices[0];
        foreach (['tts_url' => $url, 'tts_voice' => $voice] as $k => $v) {
            $r = AdminService::setSetting($k, $v);
            if (isset($r['error'])) return ['status' => 'error', 'message' => (string)$r['error']];
        }
        $folder = self::addToFolderView($c['name'], $c['image']);
        return ['status' => 'ok', 'url' => $url, 'voice' => $voice, 'container' => $c['name'], 'folder' => $folder];
    }

    // ---- Folder View 3 ------------------------------------------------------------

    /** Folder View 3's own defaults for a new folder (from its 2026.08.28 release). */
    private const FOLDER_SETTINGS = [
        'folder_webui' => false, 'folder_webui_url' => '', 'preview' => 1, 'preview_hover' => false,
        'preview_update' => false, 'preview_update_folder' => false, 'preview_text_width' => '',
        'preview_grayscale' => false, 'preview_status' => 'none', 'preview_webui' => false,
        'preview_logs' => false, 'preview_console' => false, 'preview_vertical_bars' => false,
        'preview_overflow' => 0, 'preview_row_separator' => false, 'preview_row_separator_color' => '#000000',
        'context' => 1, 'context_trigger' => 0, 'context_graph' => 1, 'context_graph_time' => 60,
        'preview_border' => false, 'preview_border_color' => '#1d1b1b', 'preview_vertical_bars_color' => '#1d1b1b',
        'lock_colors' => false, 'update_column' => false, 'use_global_defaults' => false, 'default_action' => false,
        'expand_tab' => false, 'override_default_actions' => false, 'expand_dashboard' => false,
    ];

    /**
     * Put $container in the reserved `aicliagents` folder.
     * Returns: not_installed | added | already | other_folder:<name> | corrupt | write_failed
     */
    public static function addToFolderView(string $container, string $image): string
    {
        if (!is_dir(self::$folderViewPluginDir ?? '/usr/local/emhttp/plugins/folder.view3')) return 'not_installed';
        $path = rtrim(self::$folderViewCfgDir ?? '/boot/config/plugins/folder.view3', '/') . '/docker.json';
        // Decoded as OBJECTS, not arrays, so an empty `{}` anywhere in another
        // folder is written back as `{}`, not `[]` — every other folder keeps
        // its exact value.
        $data = new \stdClass();
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if ($raw === false) return 'corrupt';
            if (trim($raw) !== '') {
                $data = json_decode($raw);
                if (!($data instanceof \stdClass)) return 'corrupt';
            }
        }
        $id = self::FOLDER_ID;
        foreach (get_object_vars($data) as $fid => $folder) {
            if ((string)$fid === $id) continue;
            if ($folder instanceof \stdClass && is_array($folder->containers ?? null)
                && in_array($container, $folder->containers, true)) {
                return 'other_folder:' . (is_string($folder->name ?? null) ? $folder->name : (string)$fid);
            }
        }
        $folder = ($data->$id ?? null) instanceof \stdClass ? $data->$id : (object)[
            'name' => self::FOLDER_NAME, 'icon' => '', 'regex' => '', 'containers' => [],
            'containerImages' => new \stdClass(), 'hidden_preview' => [], 'actions' => [],
            'settings' => (object)self::FOLDER_SETTINGS,
        ];
        $members = is_array($folder->containers ?? null) ? array_values($folder->containers) : [];
        if (in_array($container, $members, true)) return 'already';
        $members[] = $container;
        $folder->containers = $members;
        if (!(($folder->containerImages ?? null) instanceof \stdClass)) $folder->containerImages = new \stdClass();
        $folder->containerImages->$container = (string)preg_replace('/[:@].*$/', '', $image);
        $data->$id = $folder;
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false || !AtomicWriteService::write($path, $json)) return 'write_failed';
        return 'added';
    }
}
