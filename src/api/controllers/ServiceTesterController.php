<?php
// /api/controllers/ServiceTesterController.php

namespace App\Controllers;

use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

class ServiceTesterController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    // =========================
    // UI entry (HTML)
    // =========================
    public function index()
    {
        // Only enable in development/debug environments
        if (!$this->isDevAllowed()) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['error' => 'Service tester is disabled outside development environment']);
            exit;
        }

        // Clear output buffers to prevent blank page
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/html; charset=UTF-8');
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1.0" />
            <title>Service Tester</title>
            <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
            <style>
                :root {
                    --bg: #0d1117;
                    --panel: #161b22;
                    --border: #30363d;
                    --text: #e6edf3;
                    --muted: #8b949e;
                    --brand: #8b5cf6;
                    --brand-dark: #7c3aed;
                    --ok: #3fb950;
                    --ok-bg: rgba(46, 160, 67, .15);
                    --err: #f85149;
                    --err-bg: rgba(248, 81, 73, .15);
                    --pill: #a78bfa;
                }
                * { box-sizing: border-box; }
                html, body { height: 100%; }
                body {
                    margin: 0; padding: 12px; background: var(--bg); color: var(--text);
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    font-size: 16px; line-height: 1.6; overflow: hidden;
                }
                .container { max-width: 1680px; margin: 0 auto; height: 100%; display: flex; flex-direction: column; min-height: 0; }
                .header {
                    display: flex; align-items: center; justify-content: space-between;
                    background: linear-gradient(135deg, #3c286a 0%, #5f5972 100%);
                    padding: 6px 12px; border-radius: 8px; margin-bottom: 10px;
                    box-shadow: 0 4px 12px rgba(139, 92, 246, .15); flex: 0 0 auto;
                }
                .header h1 { margin: 0; font-size: 18px; color: #fff; }
                .header .right { display: flex; align-items: center; gap: 8px; }
                .header .small { font-size: 12px; color: #e9d5ff; opacity: .9; }
                .header .copy-btn {
                    background: #8b5cf6; border: 1px solid #8b5cf6; color: #fff;
                    padding: 4px 8px; border-radius: 4px; font-size: 12px; cursor: pointer;
                }
                .header .copy-btn:hover { background: #7c3aed; }
                .main { display: grid; grid-template-columns: 35% 65%; gap: 12px; flex: 1 1 auto; min-height: 0; }
                .panel {
                    background: var(--panel); border: 1px solid var(--border); border-radius: 8px;
                    display: flex; flex-direction: column; min-height: 0;
                }
                .left .search {
                    display: flex; align-items: center; gap: 8px; background: #0f141b; border-bottom: 1px solid var(--border);
                    padding: 8px 10px; border-top-left-radius: 8px; border-top-right-radius: 8px; flex: 0 0 auto;
                }
                .left .search input { flex: 1; border: none; outline: none; background: transparent; color: var(--text); font-size: 15px; }
                .left .scroll { flex: 1 1 auto; overflow-y: auto; padding: 10px; }
                .service-block { border-top: 1px solid var(--border); padding-top: 8px; }
                .service-header { display: flex; align-items: center; justify-content: space-between; cursor: pointer; padding: 8px 6px; border-radius: 6px; }
                .service-header:hover { background: #0f141b; }
                .service-name { font-weight: 600; color: var(--pill); font-size: 15px; }
                .service-fqcn { color: var(--muted); font-size: 12px; margin-left: 8px; }
                .caret { color: var(--muted); transition: transform .2s ease; margin-left: 8px; }
                .collapsed .caret { transform: rotate(-90deg); }
                .methods { margin-top: 6px; }
                .method {
                    padding: 9px 10px; margin: 6px 0; border: 1px solid var(--border); border-radius: 6px;
                    cursor: pointer; transition: border-color .15s ease; font-size: 15px; background: #0d1117;
                }
                .method:hover { border-color: var(--brand); }
                .right .head {
                    padding: 12px; border-bottom: 1px solid var(--border); display: flex; align-items: center;
                    justify-content: space-between; flex: 0 0 auto;
                }
                .right .scroll { flex: 1 1 auto; overflow-y: auto; padding: 12px; }
                .row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
                .label { display: block; font-size: 14px; color: #c9d1d9; margin-bottom: 6px; }
                .input, .textarea {
                    width: 100%; background: #0d1117; border: 1px solid var(--border); color: var(--text);
                    border-radius: 6px; padding: 11px 12px; font-family: 'Monaco','Menlo',monospace; font-size: 14px;
                }
                .textarea { min-height: 150px; resize: vertical; }
                .actions { display: flex; gap: 10px; align-items: center; margin-top: 10px; }
                .btn { padding: 10px 14px; border: 1px solid var(--border); border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; }
                .btn-primary { background: var(--brand); border-color: var(--brand); color: #fff; }
                .btn-primary:hover { background: var(--brand-dark); }
                .btn-secondary { background: #21262d; color: #c9d1d9; }
                .status { font-size: 13px; margin-left: auto; padding: 6px 10px; border-radius: 6px; display: none; }
                .ok { background: var(--ok-bg); color: var(--ok); display: inline-block; }
                .err { background: var(--err-bg); color: var(--err); display: inline-block; }
                pre {
                    background: #0d1117; border: 1px solid var(--border); border-radius: 6px; padding: 12px;
                    white-space: pre-wrap; color: var(--text); font-size: 15px;
                }
                @media (max-width:1100px) {
                    .main { grid-template-columns: 1fr; }
                    .header { flex-wrap: wrap; gap: 6px; }
                    .header .right { flex-wrap: wrap; }
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>🧪 Service Tester</h1>
                    <div class="right">
                        <button class="copy-btn" id="copyApiList">📋 Copy API List</button>
                        <div class="small" id="envStatus"></div>
                    </div>
                </div>

                <div class="main">
                    <div class="panel left">
                        <div class="search">
                            <span>🔎</span>
                            <input id="search" placeholder="Search service or method..." />
                            <button class="btn btn-secondary" id="refresh">Refresh</button>
                        </div>
                        <div class="scroll">
                            <div id="services"></div>
                        </div>
                    </div>

                    <div class="panel right">
                        <div class="head">
                            <h3 style="margin:0; font-size:18px;">Method</h3>
                            <div id="respBadge" class="status"></div>
                        </div>
                        <div class="scroll">
                            <div class="row">
                                <div>
                                    <label class="label">Service (FQCN or short)</label>
                                    <input class="input" id="svc" placeholder="App\Services\UserService" />
                                </div>
                                <div>
                                    <label class="label">Method</label>
                                    <input class="input" id="met" placeholder="createUser" />
                                </div>
                            </div>

                            <div style="margin-top:10px;">
                                <label class="label">Args JSON <span id="argsHint" style="color:#8b949e; font-size:12px;"></span></label>
                                <textarea class="textarea" id="args"></textarea>
                            </div>

                            <div class="actions">
                                <button class="btn btn-primary" id="runBtn">▶ Run</button>
                                <button class="btn btn-secondary" id="clearBtn">✕ Clear</button>
                            </div>

                            <div style="margin-top:10px;">
                                <label class="label">Response</label>
                                <pre id="resp"></pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                let catalog = [];
                let collapsed = {};

                const $services = $('#services');
                const $search = $('#search');
                const $svc = $('#svc');
                const $met = $('#met');
                const $args = $('#args');
                const $argsHint = $('#argsHint');
                const $resp = $('#resp');
                const $respBadge = $('#respBadge');
                const $envStatus = $('#envStatus');

                function badge(text, ok = true) {
                    $respBadge.text(text).removeClass('ok err').addClass(ok ? 'ok' : 'err').show();
                }
                function fmtJson(obj) { return JSON.stringify(obj, null, 2); }

                function makeArgsTemplate(method) {
                    const out = {};
                    (method.params || []).forEach(p => {
                        const name = p.name;
                        if (p.hasDefault) out[name] = p.default;
                        else if (p.type === 'int' || p.type === 'integer') out[name] = 0;
                        else if (p.type === 'float') out[name] = 0.0;
                        else if (p.type === 'bool' || p.type === 'boolean') out[name] = false;
                        else if (p.type === 'array') out[name] = [];
                        else out[name] = null;
                    });
                    return out;
                }

                function matchFilter(svc, q) {
                    if (!q) return true;
                    const qq = q.toLowerCase();
                    if (svc.class.toLowerCase().includes(qq) || svc.short.toLowerCase().includes(qq)) return true;
                    return svc.methods.some(m => m.name.toLowerCase().includes(qq));
                }

                function render(q = '') {
                    $services.empty();
                    const filtered = catalog.filter(s => matchFilter(s, q));
                    filtered.forEach(svc => {
                        const id = svc.class;
                        const isCollapsed = collapsed[id] ?? false;

                        const $block = $('<div class="service-block"></div>');
                        const $header = $('<div class="service-header"></div>');
                        const $left = $('<div></div>');
                        const $name = $('<span class="service-name"></span>').text(svc.short);
                        const $fqcn = $('<span class="service-fqcn"></span>').text(' ' + svc.class);
                        $left.append($name).append($fqcn);

                        const $right = $('<div></div>');
                        const $caret = $('<span class="caret">▾</span>');
                        $right.append($caret);

                        $header.append($left).append($right);
                        if (isCollapsed) $header.addClass('collapsed');

                        const $methods = $('<div class="methods" ' + (isCollapsed ? 'style="display:none;"' : '') + '></div>');
                        svc.methods.forEach(m => {
                            if (q && !(svc.class.toLowerCase().includes(q.toLowerCase()) || svc.short.toLowerCase().includes(q.toLowerCase()) || m.name.toLowerCase().includes(q.toLowerCase()))) {
                                return;
                            }
                            const sig = m.name + '(' + (m.params || []).map(p => p.name).join(', ') + ')';
                            const $m = $('<div class="method"></div>').text(sig);
                            $m.on('click', () => {
                                $respBadge.hide();
                                $resp.text('');
                                $argsHint.text('Required: ' + (m.params || []).filter(p => !p.optional && !p.hasDefault).map(p => p.name).join(', '));
                                $svc.val(svc.class);
                                $met.val(m.name);
                                $args.val(fmtJson(makeArgsTemplate(m)));
                            });
                            $methods.append($m);
                        });

                        $header.on('click', () => {
                            const now = $methods.is(':visible');
                            if (now) {
                                $methods.slideUp(120);
                                $header.addClass('collapsed');
                                collapsed[id] = true;
                            } else {
                                $methods.slideDown(120);
                                $header.removeClass('collapsed');
                                collapsed[id] = false;
                            }
                        });

                        $block.append($header).append($methods);
                        $services.append($block);
                    });

                    if (!filtered.length) {
                        $services.html('<div style="color:#8b949e; padding:8px;">No services match your search.</div>');
                    }
                }

                function fetchCatalog() {
                    $services.html('<div style="color:#8b949e;">Loading...</div>');
                    $.getJSON('/api/service-test/services', function (data) {
                        const env = (data.env || {});
                        $envStatus.text(`env: ${env.app_env || '-'} | debug: ${env.debug ? 'on' : 'off'}`);
                        catalog = (data.data && data.data.services) ? data.data.services : [];
                        render($search.val());
                    }).fail(function (xhr) {
                        $services.html('Failed to load services: ' + (xhr.responseText || xhr.status));
                    });
                }

                function generateApiList() {
                    if (!catalog.length) {
                        return 'No services found. Please refresh to load services.';
                    }
                    let output = 'Available Service Classes and Methods:\n\n';
                    catalog.forEach(svc => {
                        output += `${svc.short} (${svc.class}):\n`;
                        if (!svc.methods.length) {
                            output += '  - No public methods\n';
                        } else {
                            svc.methods.forEach(m => {
                                const params = (m.params || []).map(p => {
                                    let param = p.name;
                                    if (p.type) param = p.type + ' ' + param;
                                    if (p.hasDefault) param += ' = ' + JSON.stringify(p.default);
                                    if (p.optional) param += '?';
                                    return param;
                                }).join(', ');
                                const returnType = m.returnType ? ': ' + m.returnType : '';
                                output += `  - ${m.name}(${params})${returnType}\n`;
                            });
                        }
                        output += '\n';
                    });
                    return output.trim();
                }

                $('#copyApiList').on('click', function () {
                    const apiList = generateApiList();
                    navigator.clipboard.writeText(apiList).then(() => {
                        const btn = $(this);
                        const original = btn.text();
                        btn.text('✓ Copied!').css('background', '#3fb950');
                        setTimeout(() => { btn.text(original).css('background', '#8b5cf6'); }, 2000);
                    }).catch(() => { alert('Failed to copy to clipboard'); });
                });

                $('#refresh').on('click', fetchCatalog);
                $search.on('input', () => render($search.val()));

                function splitDebugAndJson(raw) {
                    // Try to locate the JSON object by scanning for a parsable JSON starting point
                    for (let i = raw.lastIndexOf('{'); i >= 0; i = raw.lastIndexOf('{', i - 1)) {
                        const candidate = raw.slice(i).trim();
                        try {
                            const json = JSON.parse(candidate);
                            const dbg = raw.slice(0, i).trim();
                            return { debug: dbg, json };
                        } catch (e) { /* keep scanning */ }
                    }
                    return { debug: raw.trim(), json: null };
                }

                $('#runBtn').on('click', function () {
                    const svc = $svc.val().trim();
                    const met = $met.val().trim();
                    if (!svc || !met) { badge('Missing service or method', false); return; }
                    let args = {};
                    const txt = $args.val().trim();
                    if (txt) {
                        try { args = JSON.parse(txt); }
                        catch (e) { badge('Invalid JSON: ' + e.message, false); return; }
                    }

                    $resp.text('Running...');
                    $respBadge.hide();

                    $.ajax({
                        url: '/api/service-test/call',
                        method: 'POST',
                        contentType: 'application/json',
                        dataType: 'text', // IMPORTANT: receive raw text (debug + JSON)
                        data: JSON.stringify({ service: svc, method: met, args }),
                        success: function (raw) {
                            const { debug, json } = splitDebugAndJson(raw || '');
                            const ok = !!(json && json.status === 'success');
                            badge(ok ? 'OK' : 'Error', ok);
                            const jsonStr = json ? JSON.stringify(json, null, 2) : '';
                            $resp.text((debug ? debug + '\n\n' : '') + jsonStr);
                        },
                        error: function (xhr) {
                            const raw = xhr.responseText || '';
                            const { debug, json } = splitDebugAndJson(raw);
                            badge('Error', false);
                            const jsonStr = json ? JSON.stringify(json, null, 2) : JSON.stringify({
                                status: 'error',
                                message: xhr.statusText || 'Request failed',
                                statusCode: xhr.status
                            }, null, 2);
                            $resp.text((debug ? debug + '\n\n' : '') + jsonStr);
                        }
                    });
                });

                $('#clearBtn').on('click', function () {
                    $svc.val(''); $met.val(''); $args.val(''); $resp.text(''); $respBadge.hide();
                });

                // init
                fetchCatalog();
            </script>
        </body>
        </html>
        <?php
    }

    // =========================
    // API: List services/methods
    // =========================
    public function listServices()
    {
        if (!$this->isDevAllowed()) {
            return $this->sendError('Service tester is disabled outside development environment', 403);
        }

        $services = $this->discoverServiceCatalog();
        $env = [
            'app_env' => $_ENV['APP_ENV'] ?? ($_ENV['APPENV'] ?? ''),
            'debug' => (($_ENV['DEBUG_MODE'] ?? ($_ENV['DEBUGMODE'] ?? '')) === 'true')
        ];

        return $this->sendSuccess('Service catalog', ['services' => $services, 'count' => count($services)], ['env' => $env]);
    }

    // =========================
    // API: Invoke method
    // =========================
    public function call()
    {
        if (!$this->isDevAllowed()) {
            // No debug in JSON; return pure JSON-only error body if blocked
            $this->respondWithDebugAndJson(403, [
                'status' => 'error',
                'message' => 'Service tester is disabled outside development environment'
            ], '');
        }

        $data = $this->getRequestData();
        $service = $data['service'] ?? '';
        $method = $data['method'] ?? '';
        $argsIn = $data['args'] ?? [];

        if (!$service || !$method) {
            $this->respondWithDebugAndJson(400, [
                'status' => 'error',
                'message' => 'Missing service or method'
            ], '');
        }

        // Allow short or FQCN
        if (!str_contains($service, '\\')) {
            $service = 'App\\Services\\' . ltrim($service, '\\');
        }

        if (!class_exists($service)) {
            $this->respondWithDebugAndJson(404, [
                'status' => 'error',
                'message' => 'Service class not found: ' . $service
            ], '');
        }

        // Capture pp/ppp/plain echoes; send them before JSON (not in JSON)
        ob_start();
        try {
            $ref = new ReflectionClass($service);
            if ($ref->isAbstract()) {
                $debug = ob_get_clean();
                $this->respondWithDebugAndJson(400, [
                    'status' => 'error',
                    'message' => 'Service is abstract: ' . $service
                ], $debug);
            }

            if (!$ref->hasMethod($method)) {
                $debug = ob_get_clean();
                $this->respondWithDebugAndJson(404, [
                    'status' => 'error',
                    'message' => 'Method not found on service: ' . $method
                ], $debug);
            }

            $m = $ref->getMethod($method);
            if (!$m->isPublic() || $m->isConstructor() || str_starts_with($m->getName(), '__')) {
                $debug = ob_get_clean();
                $this->respondWithDebugAndJson(400, [
                    'status' => 'error',
                    'message' => 'Method not invokable'
                ], $debug);
            }

            // Prepare instance (inject $this->conn where sensible)
            $instance = null;
            if (!$m->isStatic()) {
                $instance = $this->newServiceInstance($ref);
            }

            // Build method args
            $args = $this->buildMethodArgs($m, $argsIn);

            // Invoke
            $result = $m->invokeArgs($instance, $args);

            $debug = ob_get_clean();
            $this->respondWithDebugAndJson(200, [
                'status' => 'success',
                'message' => 'OK',
                'data' => [
                    'service' => $service,
                    'method' => $method,
                    'result' => $result
                ]
            ], $debug);
        } catch (\Throwable $e) {
            pp($e);
            $debug = ob_get_clean();
            $this->respondWithDebugAndJson(500, [
                'status' => 'error',
                'message' => 'Invocation failed: ' . $e->getMessage()
            ], $debug);
        }
    }

    // Return debug first, then JSON, with text/plain so the client reads raw and splits
    private function respondWithDebugAndJson(int $statusCode, array $payload, string $debug): void
    {
        while (ob_get_level()) { ob_end_clean(); } // ensure clean output
        http_response_code($statusCode);
        header('Content-Type: text/plain; charset=UTF-8');
        $debug = trim((string)$debug);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo ($debug !== '' ? $debug . "\n\n" : '') . $json;
        exit;
    }

    // =========================
    // Helpers
    // =========================
    private function isDevAllowed(): bool
    {
        $env = $_ENV['APP_ENV'] ?? ($_ENV['APPENV'] ?? '');
        $debug = ($_ENV['DEBUG_MODE'] ?? ($_ENV['DEBUGMODE'] ?? '')) === 'true';
        return $debug || in_array(strtolower($env), ['local', 'development', 'dev'], true);
    }

    private function discoverServiceCatalog(): array
    {
        // Controllers directory is /api/controllers -> services at /api/services per PSR-4
        $apiRoot = dirname(__DIR__, 1);
        $servicesDir = $apiRoot . '/services';
        $catalog = [];

        if (!is_dir($servicesDir)) {
            return $catalog;
        }

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($servicesDir));
        foreach ($rii as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') continue;

            $relative = str_replace($servicesDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $class = 'App\\Services\\' . str_replace(['/', '\\', '.php'], ['\\', '\\', ''], $relative);

            if (!class_exists($class)) continue;

            try {
                $ref = new ReflectionClass($class);
                if ($ref->isAbstract()) continue;

                $methods = [];
                foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
                    if ($m->isConstructor()) continue;
                    $name = $m->getName();
                    if (str_starts_with($name, '__')) continue;

                    $params = [];
                    foreach ($m->getParameters() as $p) {
                        $params[] = [
                            'name' => $p->getName(),
                            'hasDefault' => $p->isDefaultValueAvailable(),
                            'default' => $p->isDefaultValueAvailable() ? $this->safeDefault($p) : null,
                            'type' => $p->hasType() ? (string)$p->getType() : null,
                            'optional' => $p->isOptional(),
                            'variadic' => $p->isVariadic(),
                        ];
                    }

                    $methods[] = [
                        'name' => $name,
                        'static' => $m->isStatic(),
                        'params' => $params,
                        'returnType' => $m->hasReturnType() ? (string)$m->getReturnType() : null,
                    ];
                }

                $catalog[] = [
                    'class' => $class,
                    'short' => $ref->getShortName(),
                    'methods' => $methods,
                ];
            } catch (\Throwable $e) {
                continue;
            }
        }

        usort($catalog, fn($a, $b) => strcmp($a['short'], $b['short']));
        foreach ($catalog as &$svc) {
            usort($svc['methods'], fn($a, $b) => strcmp($a['name'], $b['name']));
        }

        return $catalog;
    }

    private function safeDefault(ReflectionParameter $p)
    {
        try { return $p->getDefaultValue(); }
        catch (\Throwable) { return null; }
    }

    private function newServiceInstance(ReflectionClass $ref)
    {
        $ctor = $ref->getConstructor();
        if (!$ctor) return $ref->newInstance();

        $params = $ctor->getParameters();
        $args = [];

        foreach ($params as $p) {
            $name = strtolower($p->getName());
            $type = $p->hasType() ? ltrim((string)$p->getType(), '?\\') : null;

            // Auto-inject DB connection when obvious
            if ($type === 'PDO' || in_array($name, ['conn', 'pdo', 'db', 'connection', 'dbconn'], true)) {
                $args[] = $this->conn;
                continue;
            }

            if ($p->isDefaultValueAvailable()) {
                $args[] = $this->safeDefault($p);
                continue;
            }

            if ($p->isOptional()) {
                continue;
            }

            throw new \RuntimeException('Cannot resolve constructor param: ' . $p->getName());
        }

        return $ref->newInstanceArgs($args);
    }

    private function buildMethodArgs(ReflectionMethod $m, $input): array
    {
        $params = $m->getParameters();
        $out = [];

        $assoc = [];
        if (is_object($input)) $input = (array)$input;
        if (is_array($input)) {
            $isAssoc = array_keys($input) !== range(0, count($input) - 1);
            if ($isAssoc) $assoc = $input;
        }

        foreach ($params as $idx => $p) {
            $name = $p->getName();

            $hasProvided = array_key_exists($name, $assoc);
            $val = $hasProvided ? $assoc[$name] : null;

            if (!$hasProvided && is_array($input) && array_key_exists($idx, $input)) {
                $val = $input[$idx];
                $hasProvided = true;
            }

            $t = $p->hasType() ? ltrim((string)$p->getType(), '?\\') : null;
            $lname = strtolower($name);
            if (!$hasProvided && ($t === 'PDO' || in_array($lname, ['conn', 'pdo', 'db', 'connection', 'dbconn'], true))) {
                $out[] = $this->conn;
                continue;
            }

            if ($hasProvided) {
                $out[] = $this->coerceType($t, $val);
                continue;
            }

            if ($p->isDefaultValueAvailable()) {
                $out[] = $this->safeDefault($p);
                continue;
            }

            if ($p->isOptional()) {
                continue;
            }

            throw new \InvalidArgumentException('Missing required argument: ' . $name);
        }

        return $out;
    }

    private function coerceType(?string $type, $val)
    {
        if ($type === null) return $val;
        $base = strtolower(ltrim($type, '?\\'));
        try {
            return match ($base) {
                'int', 'integer' => is_numeric($val) ? (int)$val : (int)($val ?? 0),
                'float', 'double' => is_numeric($val) ? (float)$val : (float)($val ?? 0),
                'bool', 'boolean' => is_bool($val) ? $val : in_array(strtolower((string)$val), ['1', 'true', 'yes', 'on'], true),
                'array' => is_array($val) ? $val : (is_string($val) ? json_decode($val, true, 512, JSON_THROW_ON_ERROR) : (array)$val),
                'string' => is_string($val) ? $val : json_encode($val),
                default => $val,
            };
        } catch (\Throwable) {
            return $val;
        }
    }
}
