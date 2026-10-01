<?php
/**
 * <module_context>
 *     <name>VoiceEngineSetupService</name>
 *     <description>The guided natural-voice setup (docs/specs/AGENT_VOICE.md,
 *     "#323 guided setup") and, with the same code, the local dictation setup
 *     (docs/specs/VOICE_ENGINE_SETUP.md: engine 'stt' = a Speaches Whisper
 *     server; Connect reads an installed container's real settings, #381 #382).
 *     Checks that Docker is on, finds an existing Kokoro
 *     container, or writes a corrected Kokoro-FastAPI-CPU template to dockerMan's
 *     user templates (no host folder mapped, a free host port) so Unraid's own
 *     Add Container page can create the container.
 *     Then it probes the engine, saves `tts_url`/`tts_voice`, and puts the
 *     container in the reserved Folder View 3 folder.</description>
 *     <dependencies>AdminService (setSetting for tts_url/tts_voice), AtomicWriteService
 *     (template and docker.json writes).</dependencies>
 *     <constraints>Never runs docker and never calls a Docker API write endpoint:
 *     only GET /_ping, /containers/json, /containers/{id}/json and
 *     /containers/{id}/logs. No shell. The
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

    // ---- engine profiles (docs/specs/VOICE_ENGINE_SETUP.md R1) -----------------
    public const STT_IMAGE        = 'ghcr.io/speaches-ai/speaches';
    public const STT_TAG          = 'latest-cpu';
    public const STT_MODEL        = 'Systran/faster-whisper-small';
    /** Named Docker volume for the Whisper model cache: no host folder (R5, the #378 lesson). */
    public const STT_CACHE_VOLUME = 'aicli-speaches-hf-cache';
    public const PROFILES = [
        'tts' => ['label' => 'Kokoro', 'name' => 'Kokoro-FastAPI-CPU', 'template' => 'my-Kokoro-FastAPI-CPU.xml',
            'image' => 'ghcr.io/remsky/kokoro-fastapi-cpu', 'prefix' => 'ghcr.io/remsky/kokoro-fastapi',
            'port' => 8880, 'first' => 8880, 'last' => 8899],
        'stt' => ['label' => 'Speaches', 'name' => 'Speaches-CPU', 'template' => 'my-Speaches-CPU.xml',
            'image' => 'ghcr.io/speaches-ai/speaches', 'prefix' => 'ghcr.io/speaches-ai/speaches',
            'port' => 8000, 'first' => 8010, 'last' => 8029],
    ];

    /** The engine key: 'stt' or (anything else) 'tts'. */
    public static function engineKey(?string $engine): string
    {
        return $engine === 'stt' ? 'stt' : 'tts';
    }

    /** @return array{label:string,name:string,template:string,image:string,prefix:string,port:int,first:int,last:int} */
    public static function profile(?string $engine): array
    {
        return self::PROFILES[self::engineKey($engine)];
    }

    // ---- test seams (null = the real path / transport) ----------------------
    public static ?string $dockerCfgPath = null;
    public static ?string $templatesDir = null;
    public static ?string $folderViewPluginDir = null;
    public static ?string $folderViewCfgDir = null;
    /** @var string[]|null /proc/net/tcp-style files to read listening ports from. */
    public static ?array $procNetPaths = null;
    /** @var callable|null fn(string $path): ?array — decoded JSON of a Docker API GET, null on failure. */
    public static $dockerApi = null;
    /** @var callable|null fn(string $path): ?string — raw body of a Docker API GET (the logs stream), null on failure. */
    public static $dockerRaw = null;
    /** @var callable|null fn(string $url): array{status:int,body:string} — GET for the engine probe. */
    public static $httpGet = null;
    /** @var callable|null fn(string $url): array{status:int,body:string,timedOut?:bool} — the POST that starts the model download. */
    public static $httpPost = null;
    /** @var string|null where the "download started" marker lives (default: the plugin's RAM tmp folder). */
    public static ?string $downloadMarker = null;
    /** A download that started longer ago than this, and still shows no model, is started again. */
    public const DOWNLOAD_RETRY_SECONDS = 1800;
    /** @var callable|null fn(): string — this server's LAN address. */
    public static $serverIp = null;
    /** @var callable|null fn(): int — clock for the backup file name. */
    public static $now = null;

    public static function resetSeams(): void
    {
        self::$dockerCfgPath = self::$templatesDir = self::$folderViewPluginDir = self::$folderViewCfgDir = null;
        self::$procNetPaths = null;
        self::$dockerApi = self::$dockerRaw = self::$httpGet = self::$httpPost = self::$serverIp = self::$now = null;
        self::$downloadMarker = null;
    }

    private static function templatesDir(): string
    {
        return rtrim(self::$templatesDir ?? '/boot/config/plugins/dockerMan/templates-user', '/');
    }

    public static function templatePath(string $engine = 'tts'): string
    {
        return self::templatesDir() . '/' . self::profile($engine)['template'];
    }

    /** Unraid's own Add Container page for the plugin's template. */
    public static function addContainerUrl(string $engine = 'tts'): string
    {
        return '/Docker/AddContainer?xmlTemplate=' . rawurlencode('user:' . self::templatePath($engine));
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

    /** @return string|null the raw body of a Docker API GET, or null when Docker does not answer. */
    private static function dockerRawBody(string $path): ?string
    {
        if (self::$dockerRaw !== null) {
            $r = (self::$dockerRaw)($path);
            return is_string($r) ? $r : null;
        }
        if (!function_exists('curl_init') || !file_exists('/var/run/docker.sock')) return null;
        $ch = curl_init('http://localhost' . $path);
        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_MAXFILESIZE, 262144);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($body === false || $status < 200 || $status >= 300) ? null : (string)$body;
    }

    /**
     * #382: the last non-empty line the container wrote (stdout or stderr), for a
     * stopped or crashing container. Docker's logs body is a stream of frames (an
     * 8-byte header, then the text) unless the container has a tty, then plain text.
     * The line is cleaned of control and colour codes and cut to 200 characters.
     * Empty when Docker does not answer or the container wrote nothing.
     */
    public static function lastLogLine(string $containerId): string
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $containerId)) return '';
        $raw = self::dockerRawBody('/containers/' . $containerId . '/logs?stdout=1&stderr=1&tail=5');
        if ($raw === null || $raw === '') return '';
        $text = '';
        $len = strlen($raw);
        $framed = $len >= 8 && in_array(ord($raw[0]), [0, 1, 2], true) && substr($raw, 1, 3) === "\0\0\0";
        if ($framed) {
            $i = 0;
            while ($i + 8 <= $len) {
                $size = unpack('N', substr($raw, $i + 4, 4))[1];
                $text .= substr($raw, $i + 8, $size);
                $i += 8 + $size;
            }
        } else {
            $text = $raw;
        }
        $text = (string)preg_replace('/\x1b\[[0-9;?]*[A-Za-z]/', '', $text);
        $lines = preg_split('/\r?\n/', $text) ?: [];
        for ($k = count($lines) - 1; $k >= 0; $k--) {
            $line = trim((string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', $lines[$k]));
            if ($line !== '') return function_exists('mb_substr') ? mb_substr($line, 0, 200) : substr($line, 0, 200);
        }
        return '';
    }

    /**
     * #382: how a container that is not running is doing. From `docker inspect`:
     * 'crashing' when it is restarting, exited with a non-zero code, or has an error;
     * 'stopped' otherwise. The last log line is added for both.
     *
     * @return array{state:string,exitCode:int,error:string,logLine:string}
     */
    public static function stoppedState(string $containerId): array
    {
        $info = preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $containerId) ? self::docker('/containers/' . $containerId . '/json') : null;
        $st = is_array($info) ? (array)($info['State'] ?? []) : [];
        $exit = (int)($st['ExitCode'] ?? 0);
        $err = trim((string)($st['Error'] ?? ''));
        $crashing = !empty($st['Restarting']) || $exit !== 0 || $err !== '';
        return [
            'state' => $crashing ? 'crashing' : 'stopped',
            'exitCode' => $exit,
            'error' => function_exists('mb_substr') ? mb_substr($err, 0, 200) : substr($err, 0, 200),
            'logLine' => self::lastLogLine($containerId),
        ];
    }

    /**
     * The existing engine container, if any. A running one wins over a
     * stopped one.
     *
     * @return array{id:string,name:string,running:bool,state:string,image:string}|null
     */
    public static function findExisting(string $engine = 'tts'): ?array
    {
        $list = self::docker('/containers/json?all=1');
        if (!is_array($list)) return null;
        $best = null;
        foreach ($list as $c) {
            if (!is_array($c)) continue;
            $image = (string)($c['Image'] ?? '');
            if (stripos($image, self::profile($engine)['prefix']) !== 0) continue;
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
    public static function engineUrlFor(string $containerId, string $engine = 'tts'): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $containerId)) return null;
        $info = self::docker('/containers/' . $containerId . '/json');
        if (!is_array($info)) return null;
        $cport = self::profile($engine)['port'];
        $tpl = self::templateSettings($engine);
        $mode = (string)($info['HostConfig']['NetworkMode'] ?? 'bridge');
        $key = $cport . '/tcp';
        if ($mode === 'host') {
            return self::urlFor(self::serverIp(), $cport);
        }
        if ($mode === 'bridge' || $mode === 'default' || $mode === '') {
            $port = 0;
            foreach ([(array)($info['NetworkSettings']['Ports'][$key] ?? []), (array)($info['HostConfig']['PortBindings'][$key] ?? [])] as $bindings) {
                foreach ($bindings as $b) {
                    $p = (int)($b['HostPort'] ?? 0);
                    if ($p > 0) { $port = $p; break 2; }
                }
            }
            // #382: a container that reports no binding (stopped and never started): the
            // host port the user applied on the Add Container page, from their template.
            if ($port <= 0) $port = (int)($tpl['hostPort'] ?? 0);
            return $port > 0 ? self::urlFor(self::serverIp(), $port) : null;
        }
        foreach ((array)($info['NetworkSettings']['Networks'] ?? []) as $net) {
            $ip = (string)($net['IPAddress'] ?? '');
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return self::urlFor($ip, $cport);
            }
        }
        // #382: stopped on a custom network reports no address; the template's own
        // address (MyIP) is what the user applied.
        $myIp = (string)($tpl['ip'] ?? '');
        if ($myIp !== '' && filter_var($myIp, FILTER_VALIDATE_IP)) return self::urlFor($myIp, $cport);
        return null;
    }

    /**
     * #382: what the user applied on Unraid's Add Container page, read from their
     * user template (written by this wizard, then possibly edited by hand): the host
     * port of the engine's port, the container's own address (MyIP), the network and
     * the model in the preload variable. Empty values when there is no readable
     * template. Nothing here is taken from a request.
     *
     * @return array{hostPort:int,ip:string,network:string,model:string}
     */
    public static function templateSettings(string $engine = 'tts'): array
    {
        $out = ['hostPort' => 0, 'ip' => '', 'network' => '', 'model' => ''];
        $path = self::templatePath($engine);
        if (!is_file($path)) return $out;
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '' || stripos($raw, '<!DOCTYPE') !== false || stripos($raw, '<!ENTITY') !== false) return $out;
        $prev = libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$xml instanceof \SimpleXMLElement) return $out;
        $out['network'] = trim((string)($xml->Network ?? ''));
        $out['ip'] = trim((string)($xml->MyIP ?? ''));
        $cport = (string)self::profile($engine)['port'];
        foreach ($xml->Config ?? [] as $cfg) {
            $type = (string)($cfg['Type'] ?? '');
            $target = (string)($cfg['Target'] ?? '');
            $value = trim((string)$cfg);
            if ($type === 'Port' && $target === $cport && ctype_digit($value)) $out['hostPort'] = (int)$value;
            if ($type === 'Variable' && $target === 'PRELOAD_MODELS') {
                $list = json_decode($value, true);
                if (is_array($list) && isset($list[0]) && is_string($list[0])) $out['model'] = $list[0];
            }
        }
        return $out;
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

    /** The first free host port in the engine's range (tts 8880–8899, stt 8010–8029), or null. */
    public static function choosePort(string $engine = 'tts'): ?int
    {
        $used = self::usedPorts();
        $prof = self::profile($engine);
        for ($p = $prof['first']; $p <= $prof['last']; $p++) {
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

    /**
     * The Speaches (Whisper) template for local dictation (docs/specs/VOICE_ENGINE_SETUP.md).
     * Bridge network, one host port, NO host folder: the model cache is a Docker named
     * volume given in the extra parameters, so Docker gives it the image's own owner
     * (the image runs as uid 1000; a host folder made by Unraid as 99:100 would refuse
     * the model download — the #378 lesson). The container downloads the model at its
     * first start (PRELOAD_MODELS) and runs it in int8 on the CPU.
     */
    public static function sttTemplateXml(int $port): string
    {
        if ($port < 1024 || $port > 65535) throw new \InvalidArgumentException('invalid port');
        $p = self::profile('stt');
        $x = static fn(string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $var = static function (string $name, string $target, string $value, string $desc) use ($x): string {
            return '  <Config Name="' . $x($name) . '" Target="' . $x($target) . '" Default="' . $x($value) . '" Description="' . $x($desc)
                . '" Type="Variable" Display="advanced-hide" Required="false" Mask="false">' . $x($value) . "</Config>\n";
        };
        $extra = "--health-cmd='curl -fsS -m 5 http://localhost:" . $p['port'] . "/health -o /dev/null || exit 1' --health-interval=60s --health-timeout=10s --health-retries=3"
            . ' -v ' . self::STT_CACHE_VOLUME . ':/home/ubuntu/.cache/huggingface/hub';
        $out  = "<?xml version=\"1.0\"?>\n<Container version=\"2\">\n";
        $out .= '  <Name>' . $p['name'] . "</Name>\n";
        $out .= '  <Repository>' . self::STT_IMAGE . ':' . self::STT_TAG . "</Repository>\n";
        $out .= '  <Registry>' . self::STT_IMAGE . "</Registry>\n";
        $out .= "  <Network>bridge</Network>\n";
        $out .= '  <ExtraParams>' . $x($extra) . "</ExtraParams>\n";
        $out .= '  <WebUI>http://[IP]:[PORT:' . $p['port'] . "]/docs</WebUI>\n";
        $out .= "  <Privileged>false</Privileged>\n";
        $out .= "  <Support>https://github.com/speaches-ai/speaches/issues</Support>\n";
        $out .= "  <Project>https://github.com/speaches-ai/speaches</Project>\n";
        $out .= '  <Overview>' . $x('Speaches: a local Whisper speech-to-text server (CPU build) with an OpenAI-compatible API, for dictation in the AI CLI Agents plugin. Written by the plugin\'s "Set up local dictation" button. No host folder is mapped: the model cache is the Docker volume ' . self::STT_CACHE_VOLUME . ', which Docker creates with the image\'s own owner. The plugin asks the server to download the model (' . self::STT_MODEL . ', about 500 MB) when you press Connect.') . "</Overview>\n";
        $out .= "  <Beta>False</Beta>\n";
        $out .= "  <Category>AI: Productivity: Tools: Other: Status:Stable</Category>\n";
        $out .= '  <Config Name="Web UI Port" Target="' . $p['port'] . '" Default="' . $p['port'] . '" Mode="tcp" Description="Host port for the transcription API. The plugin picked a free one." Type="Port" Display="always" Required="true" Mask="false">' . $port . "</Config>\n";
        $out .= $var('Preload model', 'PRELOAD_MODELS', json_encode([self::STT_MODEL], JSON_UNESCAPED_SLASHES), 'Models the container downloads at its first start.');
        $out .= $var('Compute type', 'WHISPER__COMPUTE_TYPE', 'int8', 'int8 is the fast, small choice on a CPU.');
        $out .= $var('Log Level', 'LOG_LEVEL', 'INFO', 'Logging level for the API');
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
    public static function prepare(string $engine = 'tts'): array
    {
        $engine = self::engineKey($engine);
        $prof = self::profile($engine);
        $docker = self::dockerState();
        if (!$docker['running']) return ['status' => 'error', 'code' => $docker['enabled'] ? 'docker_stopped' : 'docker_off', 'message' => $docker['message']];

        $existing = self::findExisting($engine);
        if ($existing !== null) {
            return ['status' => 'ok', 'mode' => 'existing', 'container' => $existing['name'], 'running' => $existing['running']];
        }

        $port = self::choosePort($engine);
        if ($port === null) {
            return ['status' => 'error', 'code' => 'no_port', 'message' => 'Ports ' . $prof['first'] . ' to ' . $prof['last'] . ' are all in use. Free one, then try again.'];
        }

        $xml = $engine === 'stt' ? self::sttTemplateXml($port) : self::templateXml($port);
        $dir = self::templatesDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['status' => 'error', 'code' => 'write_failed', 'message' => 'Could not create the Docker templates folder on the flash drive.'];
        }
        $path = self::templatePath($engine);
        $backup = null;
        if (is_file($path)) {
            if ((string)@file_get_contents($path) !== $xml) {
                $ts = self::$now !== null ? (int)(self::$now)() : time();
                $backup = $path . '.aicli-bak-' . date('Ymd-His', $ts);
                if (!@rename($path, $backup)) {
                    return ['status' => 'error', 'code' => 'write_failed', 'message' => 'Could not keep a copy of the existing ' . $prof['label'] . ' template, so it was not replaced.'];
                }
            }
        }
        if (!AtomicWriteService::write($path, $xml)) {
            return ['status' => 'error', 'code' => 'write_failed', 'message' => 'Could not write the ' . $prof['label'] . ' template to the flash drive.'];
        }
        $out = ['status' => 'ok', 'mode' => 'template', 'addContainerUrl' => self::addContainerUrl($engine), 'port' => $port];
        if ($backup !== null) $out['backup'] = basename($backup);
        return $out;
    }

    /**
     * The sentence for a container that is not running (#382): stopped or crashing,
     * with the last log line, and the permission help for the natural voice (#378).
     *
     * @param array{state:string,exitCode:int,error:string,logLine:string} $st
     */
    private static function notRunningMessage(string $engine, string $name, array $st): string
    {
        if ($st['state'] === 'crashing') {
            $msg = 'The ' . $name . ' container keeps stopping' . ($st['exitCode'] !== 0 ? ' (exit code ' . $st['exitCode'] . ')' : '') . '.';
            if ($st['error'] !== '') $msg .= ' Docker says: ' . $st['error'] . '.';
        } else {
            $msg = 'The ' . $name . ' container is stopped. Start it from the Docker tab.';
        }
        if ($st['logLine'] !== '') $msg .= ' Last log line: ' . $st['logLine'];
        if ($engine === 'tts') $msg .= ' ' . self::PERMISSION_HELP;
        return $msg;
    }

    /**
     * Step 4 (the poll). Read-only.
     * state: docker_off | docker_stopped | no_container | stopped | crashing | starting | ready
     */
    public static function check(string $engine = 'tts'): array
    {
        $engine = self::engineKey($engine);
        $prof = self::profile($engine);
        $docker = self::dockerState();
        if (!$docker['running']) {
            return ['status' => 'ok', 'state' => $docker['enabled'] ? 'docker_stopped' : 'docker_off', 'message' => $docker['message']];
        }
        $c = self::findExisting($engine);
        if ($c === null) {
            return ['status' => 'ok', 'state' => 'no_container', 'message' => 'Waiting for you to press Apply on the Add Container page. Unraid downloads the image first; this can take several minutes.'];
        }
        if (!$c['running']) {
            $st = self::stoppedState($c['id']);
            return ['status' => 'ok', 'state' => $st['state'], 'container' => $c['name'], 'message' => self::notRunningMessage($engine, $c['name'], $st)];
        }
        $url = self::engineUrlFor($c['id'], $engine);
        if ($url === null) {
            return ['status' => 'ok', 'state' => 'starting', 'container' => $c['name'], 'message' => 'The ' . $c['name'] . ' container has no port for the ' . ($engine === 'stt' ? 'transcription' : 'speech') . ' API. Check its port on the Docker tab.'];
        }
        $found = self::probe($url, $engine);
        if ($found === []) {
            // Speaches answers but lists no model: the image does not install one by itself
            // (PRELOAD_MODELS is ignored), so the plugin asks it to download the model (R6).
            if ($engine === 'stt' && self::engineHealthy($url)) {
                $since = self::downloadStartedAt();
                if ($since !== null) {
                    $secs = max(0, self::nowTs() - $since);
                    return ['status' => 'ok', 'state' => 'downloading', 'container' => $c['name'], 'url' => $url, 'elapsed' => $secs,
                        'message' => 'Downloading the Whisper model (about 500 MB). ' . self::elapsedText($secs) . ' The speech server stays quiet until it is done.'];
                }
                return ['status' => 'ok', 'state' => 'needs_model', 'container' => $c['name'], 'url' => $url,
                    'message' => 'Speaches is running, but it has no Whisper model yet. Starting the download (about 500 MB).'];
            }
            $msg = $engine === 'stt'
                ? 'Speaches is starting. This can take a minute.'
                : 'Kokoro is starting. The first start can take a minute.';
            return ['status' => 'ok', 'state' => 'starting', 'container' => $c['name'], 'url' => $url, 'message' => $msg];
        }
        return ['status' => 'ok', 'state' => 'ready', 'container' => $c['name'], 'url' => $url]
            + ($engine === 'stt' ? ['models' => count($found)] : ['voices' => count($found)]);
    }

    private static function nowTs(): int
    {
        return self::$now !== null ? (int)(self::$now)() : time();
    }

    private static function elapsedText(int $secs): string
    {
        if ($secs < 60) return 'Started less than a minute ago.';
        $m = intdiv($secs, 60);
        return 'Started ' . $m . ($m === 1 ? ' minute' : ' minutes') . ' ago.';
    }

    private static function downloadMarkerPath(): string
    {
        if (self::$downloadMarker !== null) return self::$downloadMarker;
        $base = getenv('AICLI_TMP_BASE') ?: '/tmp/unraid-aicliagents';
        return rtrim($base, '/') . '/voice-stt-download.json';
    }

    /** @return int|null when the model download was started, or null when none is running (or it is too old to trust). */
    private static function downloadStartedAt(): ?int
    {
        $d = json_decode((string)@file_get_contents(self::downloadMarkerPath()), true);
        $t = is_array($d) ? (int)($d['startedAt'] ?? 0) : 0;
        if ($t <= 0 || self::nowTs() - $t >= self::DOWNLOAD_RETRY_SECONDS) return null;
        return $t;
    }

    /** True when Speaches answers its health check. */
    private static function engineHealthy(string $url): bool
    {
        return (int)(self::httpGetJson(rtrim($url, '/') . '/health')['status'] ?? 0) === 200;
    }

    /**
     * The model download (R6). POST only. Asks the running Speaches to download the
     * fixed model STT_MODEL (nothing comes from the request). The server keeps downloading
     * after this short request ends, so the request is cut after 3 seconds and the page
     * polls check(). A marker file stops a second download from starting.
     */
    public static function startModelDownload(): array
    {
        $c = self::findExisting('stt');
        if ($c === null || !$c['running']) return ['status' => 'error', 'message' => 'The Speaches container is not running.'];
        $url = self::engineUrlFor($c['id'], 'stt');
        if ($url === null || !self::engineHealthy($url)) return ['status' => 'error', 'message' => 'Speaches does not answer yet. Wait a moment and try again.'];
        if (self::probe($url, 'stt') !== []) return ['status' => 'ok', 'state' => 'ready', 'message' => 'The Whisper model is already installed.'];
        $since = self::downloadStartedAt();
        if ($since !== null) return ['status' => 'ok', 'state' => 'downloading', 'elapsed' => max(0, self::nowTs() - $since), 'message' => 'The download is already running.'];

        $marker = self::downloadMarkerPath();
        $dir = dirname($marker);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!AtomicWriteService::write($marker, json_encode(['startedAt' => self::nowTs(), 'model' => self::STT_MODEL]))) {
            return ['status' => 'error', 'message' => 'Could not record the download start. Try again.'];
        }
        $target = rtrim($url, '/') . '/v1/models/' . self::STT_MODEL;
        $r = self::$httpPost !== null ? (array)(self::$httpPost)($target) : self::curlPost($target);
        $code = (int)($r['status'] ?? 0);
        $ok = !empty($r['timedOut']) || ($code >= 200 && $code < 300);
        if (!$ok) {
            @unlink($marker);
            return ['status' => 'error', 'message' => 'Speaches refused to download the Whisper model' . ($code > 0 ? ' (HTTP ' . $code . ')' : '') . '. Check the Speaches container log.'];
        }
        return ['status' => 'ok', 'state' => 'downloading', 'elapsed' => 0, 'message' => 'The Whisper model download started.'];
    }

    /** @return array{status:int,body:string,timedOut:bool} one POST, cut after 3 seconds (the engine keeps working). */
    private static function curlPost(string $target): array
    {
        $ch = curl_init($target);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        $body = curl_exec($ch);
        $r = ['status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $body === false ? '' : (string)$body,
              'timedOut' => curl_errno($ch) === CURLE_OPERATION_TIMEDOUT];
        curl_close($ch);
        return $r;
    }

    /**
     * #382 (docs/specs/VOICE_ENGINE_SETUP.md R2): read-only. What is installed, in what
     * state, and what Connect would connect to. The page calls it on load to choose
     * between "Set up ..." and "Connect".
     *
     * installed=false: no container of the engine's image (or Docker is off or stopped).
     * state as check(), plus 'not_installed'.
     */
    public static function status(string $engine = 'tts'): array
    {
        $engine = self::engineKey($engine);
        $docker = self::dockerState();
        if (!$docker['running']) {
            return ['status' => 'ok', 'engine' => $engine, 'installed' => false, 'state' => $docker['enabled'] ? 'docker_stopped' : 'docker_off', 'message' => $docker['message']];
        }
        $c = self::findExisting($engine);
        if ($c === null) {
            return ['status' => 'ok', 'engine' => $engine, 'installed' => false, 'state' => 'not_installed', 'message' => ''];
        }
        $r = self::check($engine);
        $r['engine'] = $engine;
        $r['installed'] = true;
        // A container that exists but has no answer yet may still have a known URL from its settings.
        if (!isset($r['url'])) {
            $u = self::engineUrlFor($c['id'], $engine);
            if ($u !== null) $r['url'] = $u;
        }
        return $r;
    }

    /**
     * @return string[] what the engine lists: voice ids (tts) or installed transcription
     *                  model ids (stt); [] when it does not answer yet (or, for stt, has no model yet).
     */
    public static function probe(string $url, string $engine = 'tts'): array
    {
        $engine = self::engineKey($engine);
        $base = rtrim($url, '/');
        if ($engine === 'stt') {
            // Healthy first, then the models the engine has really installed.
            if ((int)(self::httpGetJson($base . '/health')['status'] ?? 0) !== 200) return [];
            $r = self::httpGetJson($base . '/v1/models?task=automatic-speech-recognition');
            if ((int)($r['status'] ?? 0) !== 200) return [];
            $data = json_decode((string)($r['body'] ?? ''), true);
            $ids = [];
            foreach ((array)($data['data'] ?? []) as $m) {
                $id = is_array($m) ? (string)($m['id'] ?? '') : (string)$m;
                if ($id !== '' && preg_match('#^[\w.\-/]{1,64}$#', $id)) $ids[] = $id;
            }
            return $ids;
        }
        $r = self::httpGetJson($base . '/v1/audio/voices');
        if ((int)($r['status'] ?? 0) !== 200) return [];
        $data = json_decode((string)($r['body'] ?? ''), true);
        $ids = [];
        foreach ((array)($data['voices'] ?? []) as $v) {
            $id = is_array($v) ? (string)($v['id'] ?? $v['name'] ?? '') : (string)$v;
            if ($id !== '' && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $id)) $ids[] = $id;
        }
        return $ids;
    }

    /** @return array{status:int,body:string} one GET to the engine (through the test seam when set). */
    private static function httpGetJson(string $target): array
    {
        if (self::$httpGet !== null) {
            return (array)(self::$httpGet)($target);
        }
        $ch = curl_init($target);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_MAXFILESIZE, 1048576);
        $body = curl_exec($ch);
        $r = ['status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $body === false ? '' : (string)$body];
        curl_close($ch);
        return $r;
    }

    /**
     * Step 5 (Connect). Reads the engine's real settings (see engineUrlFor), probes
     * it, saves tts_url and tts_voice (or stt_url and stt_model) and files the
     * container in the Folder View 3 folder.
     */
    public static function finish(string $engine = 'tts'): array
    {
        $engine = self::engineKey($engine);
        $prof = self::profile($engine);
        $c = self::findExisting($engine);
        if ($c === null || !$c['running']) {
            if ($c !== null) {
                $st = self::stoppedState($c['id']);
                return ['status' => 'error', 'state' => $st['state'], 'message' => self::notRunningMessage($engine, $c['name'], $st)];
            }
            return ['status' => 'error', 'message' => 'The ' . $prof['label'] . ' container is not running yet.'];
        }
        $url = self::engineUrlFor($c['id'], $engine);
        $found = $url !== null ? self::probe($url, $engine) : [];
        if ($url === null || $found === []) {
            return ['status' => 'error', 'message' => $prof['label'] . ' does not answer yet. Wait a moment and try again.'];
        }
        if ($engine === 'stt') {
            $wanted = array_values(array_filter([self::templateSettings('stt')['model'], self::STT_MODEL]));
            $model = $found[0];
            foreach ($wanted as $w) { if (in_array($w, $found, true)) { $model = $w; break; } }
            $save = ['stt_url' => $url, 'stt_model' => $model];
        } else {
            $voice = in_array(self::DEFAULT_VOICE, $found, true) ? self::DEFAULT_VOICE : $found[0];
            $save = ['tts_url' => $url, 'tts_voice' => $voice];
        }
        foreach ($save as $k => $v) {
            $r = AdminService::setSetting($k, $v);
            if (isset($r['error'])) return ['status' => 'error', 'message' => (string)$r['error']];
        }
        $folder = self::addToFolderView($c['name'], $c['image']);
        return ['status' => 'ok', 'url' => $url, 'container' => $c['name'], 'folder' => $folder]
            + ($engine === 'stt' ? ['model' => $save['stt_model']] : ['voice' => $save['tts_voice']]);
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
