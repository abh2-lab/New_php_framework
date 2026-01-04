<?php
// api/controllers/RepositoryTesterController.php

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Database;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

class RepositoryTesterController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    // UI entry (HTML)
    public function index(): void
    {
        // Only enable in development/debug environments
        if (!$this->isDevAllowed()) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['error' => 'Repository tester is disabled outside development environment']);
            exit;
        }

        // Clear output buffers to prevent blank page
        while (ob_get_level())
            ob_end_clean();

        header('Content-Type: text/html; charset=UTF-8');
        ?>
        <!DOCTYPE html>
        <html lang="en">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Repository Tester</title>
            <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
            <style>
                :root {
                    --bg: #0d1117;
                    --panel: #161b22;
                    --border: #30363d;
                    --text: #e6edf3;
                    --muted: #8b949e;
                    --brand: #f97316;
                    --brand-dark: #ea580c;
                    --ok: #3fb950;
                    --ok-bg: rgba(46, 160, 67, .15);
                    --err: #f85149;
                    --err-bg: rgba(248, 81, 73, .15);
                    --pill: #fb923c;
                }

                * {
                    box-sizing: border-box;
                }

                html,
                body {
                    height: 100%;
                }

                body {
                    margin: 0;
                    padding: 12px;
                    background: var(--bg);
                    color: var(--text);
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    font-size: 16px;
                    line-height: 1.6;
                    overflow: hidden;
                }

                .container {
                    max-width: 1680px;
                    margin: 0 auto;
                    height: 100%;
                    display: flex;
                    flex-direction: column;
                    min-height: 0;
                }

                .header {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    background: linear-gradient(135deg, #ea580c 0%, #f97316 100%);
                    padding: 6px 12px;
                    border-radius: 8px;
                    margin-bottom: 10px;
                    box-shadow: 0 4px 12px rgba(249, 115, 22, .15);
                    flex: 0 0 auto;
                }

                .header h1 {
                    margin: 0;
                    font-size: 18px;
                    color: #fff;
                }

                .header .right {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }

                .header .small {
                    font-size: 12px;
                    color: #fed7aa;
                    opacity: .9;
                }

                .header .copy-btn {
                    background: #f97316;
                    border: 1px solid #f97316;
                    color: #fff;
                    padding: 4px 8px;
                    border-radius: 4px;
                    font-size: 12px;
                    cursor: pointer;
                }

                .header .copy-btn:hover {
                    background: #ea580c;
                }

                .main {
                    display: grid;
                    grid-template-columns: 35% 65%;
                    gap: 12px;
                    flex: 1 1 auto;
                    min-height: 0;
                }

                .panel {
                    background: var(--panel);
                    border: 1px solid var(--border);
                    border-radius: 8px;
                    display: flex;
                    flex-direction: column;
                    min-height: 0;
                }

                .left .search {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    background: #0f141b;
                    border-bottom: 1px solid var(--border);
                    padding: 8px 10px;
                    border-top-left-radius: 8px;
                    border-top-right-radius: 8px;
                    flex: 0 0 auto;
                }

                .left .search input {
                    flex: 1;
                    border: none;
                    outline: none;
                    background: transparent;
                    color: var(--text);
                    font-size: 15px;
                }

                .left .scroll {
                    flex: 1 1 auto;
                    overflow-y: auto;
                    padding: 10px;
                }

                .repository-block {
                    border-top: 1px solid var(--border);
                    padding-top: 8px;
                }

                .repository-header {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    cursor: pointer;
                    padding: 8px 6px;
                    border-radius: 6px;
                }

                .repository-header:hover {
                    background: #0f141b;
                }

                .repository-name {
                    font-weight: 600;
                    color: var(--pill);
                    font-size: 15px;
                }

                .repository-fqcn {
                    color: var(--muted);
                    font-size: 12px;
                    margin-left: 8px;
                }

                .caret {
                    color: var(--muted);
                    transition: transform .2s ease;
                    margin-left: 8px;
                }

                .collapsed .caret {
                    transform: rotate(-90deg);
                }

                .methods {
                    margin-top: 6px;
                }

                .method {
                    padding: 9px 10px;
                    margin: 6px 0;
                    border: 1px solid var(--border);
                    border-radius: 6px;
                    cursor: pointer;
                    transition: border-color .15s ease;
                    font-size: 15px;
                    background: #0d1117;
                }

                .method:hover {
                    border-color: var(--brand);
                }

                .right .head {
                    padding: 12px;
                    border-bottom: 1px solid var(--border);
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    flex: 0 0 auto;
                }

                .right .scroll {
                    flex: 1 1 auto;
                    overflow-y: auto;
                    padding: 12px;
                }

                .row {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 10px;
                }

                .label {
                    display: block;
                    font-size: 14px;
                    color: #c9d1d9;
                    margin-bottom: 6px;
                }

                .input,
                .textarea {
                    width: 100%;
                    background: #0d1117;
                    border: 1px solid var(--border);
                    color: var(--text);
                    border-radius: 6px;
                    padding: 11px 12px;
                    font-family: 'Monaco', 'Menlo', monospace;
                    font-size: 14px;
                }

                .textarea {
                    min-height: 150px;
                    resize: vertical;
                }

                .actions {
                    display: flex;
                    gap: 10px;
                    align-items: center;
                    margin-top: 10px;
                }

                .btn {
                    padding: 10px 14px;
                    border: 1px solid var(--border);
                    border-radius: 6px;
                    cursor: pointer;
                    font-weight: 600;
                    font-size: 14px;
                }

                .btn-primary {
                    background: var(--brand);
                    border-color: var(--brand);
                    color: #fff;
                }

                .btn-primary:hover {
                    background: var(--brand-dark);
                }

                .btn-secondary {
                    background: #21262d;
                    color: #c9d1d9;
                }

                .status {
                    font-size: 13px;
                    margin-left: auto;
                    padding: 6px 10px;
                    border-radius: 6px;
                    display: none;
                }

                .ok {
                    background: var(--ok-bg);
                    color: var(--ok);
                    display: inline-block;
                }

                .err {
                    background: var(--err-bg);
                    color: var(--err);
                    display: inline-block;
                }

                pre {
                    background: #0d1117;
                    border: 1px solid var(--border);
                    border-radius: 6px;
                    padding: 12px;
                    white-space: pre-wrap;
                    color: var(--text);
                    font-size: 15px;
                }

                @media (max-width:1100px) {
                    .main {
                        grid-template-columns: 1fr;
                    }

                    .header {
                        flex-wrap: wrap;
                        gap: 6px;
                    }

                    .header .right {
                        flex-wrap: wrap;
                    }
                }
            </style>
        </head>

        <body>
            <div class="container">
                <div class="header">
                    <h1>🗄️ Repository Tester</h1>
                    <div class="right">
                        <button class="copy-btn" id="copyApiList">Copy API List</button>
                        <div class="small" id="envStatus"></div>
                    </div>
                </div>

                <div class="main">
                    <div class="panel left">
                        <div class="search">
                            <span>🔍</span>
                            <input id="search" placeholder="Search repository or method...">
                            <button class="btn btn-secondary" id="refresh">Refresh</button>
                        </div>
                        <div class="scroll">
                            <div id="repositories"></div>
                        </div>
                    </div>

                    <div class="panel right">
                        <div class="head">
                            <h3 style="margin:0; font-size:18px">Method</h3>
                            <div id="respBadge" class="status"></div>
                        </div>
                        <div class="scroll">
                            <div class="row">
                                <div>
                                    <label class="label">Repository FQCN or short</label>
                                    <input class="input" id="repo" placeholder="App\Repositories\OrderRepository">
                                </div>
                                <div>
                                    <label class="label">Method</label>
                                    <input class="input" id="met" placeholder="findById">
                                </div>
                            </div>
                            <div style="margin-top:10px">
                                <label class="label">Args (JSON) <span id="argsHint"
                                        style="color:#8b949e; font-size:12px"></span></label>
                                <textarea class="textarea" id="args"></textarea>
                            </div>
                            <div class="actions">
                                <button class="btn btn-primary" id="runBtn">▶ Run</button>
                                <button class="btn btn-secondary" id="clearBtn">Clear</button>
                            </div>
                            <div style="margin-top:10px">
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

                const repositories = $('#repositories');
                const search = $('#search');
                const repo = $('#repo');
                const met = $('#met');
                const args = $('#args');
                const argsHint = $('#argsHint');
                const resp = $('#resp');
                const respBadge = $('#respBadge');
                const envStatus = $('#envStatus');

                function badge(text, ok = true) {
                    respBadge.text(text).removeClass('ok err').addClass(ok ? 'ok' : 'err').show();
                }

                function fmtJson(obj) {
                    return JSON.stringify(obj, null, 2);
                }

                function makeArgsTemplate(method) {
                    const out = {};
                    method.params.forEach(p => {
                        const name = p.name;
                        if (p.hasDefault) {
                            out[name] = p.default;
                        } else if (p.type === 'int' || p.type === 'integer') {
                            out[name] = 0;
                        } else if (p.type === 'float') {
                            out[name] = 0.0;
                        } else if (p.type === 'bool' || p.type === 'boolean') {
                            out[name] = false;
                        } else if (p.type === 'array') {
                            out[name] = [];
                        } else {
                            out[name] = null;
                        }
                    });
                    return out;
                }

                function matchFilter(repo, q) {
                    if (!q) return true;
                    const qq = q.toLowerCase();
                    if (repo.class.toLowerCase().includes(qq) || repo.short.toLowerCase().includes(qq)) return true;
                    return repo.methods.some(m => m.name.toLowerCase().includes(qq));
                }

                function render(q) {
                    repositories.empty();
                    const filtered = catalog.filter((r) => matchFilter(r, q));

                    // FIX: Changed loop variable from 'repo' to 'r' to avoid conflict
                    filtered.forEach(r => {
                        const id = r.class;
                        const isCollapsed = collapsed[id] ?? false;

                        const block = $('<div class="repository-block"></div>');
                        const header = $('<div class="repository-header"></div>');

                        const left = $('<div></div>');
                        const name = $('<span class="repository-name"></span>').text(r.short);
                        const fqcn = $('<span class="repository-fqcn"></span>').text('(' + r.class + ')');
                        left.append(name).append(fqcn);

                        const right = $('<div></div>');
                        const caret = $('<span class="caret">▼</span>');
                        right.append(caret);

                        header.append(left).append(right);
                        if (isCollapsed) header.addClass('collapsed');

                        const methods = $('<div class="methods"></div>');
                        if (isCollapsed) methods.css('display', 'none');

                        r.methods.forEach(m => {
                            if (q && !(r.class.toLowerCase().includes(q.toLowerCase()) || r.short.toLowerCase().includes(q.toLowerCase()))) {
                                if (!m.name.toLowerCase().includes(q.toLowerCase())) return;
                            }
                            const sig = m.name + '(' + m.params.map(p => p.name).join(', ') + ')';
                            const $m = $('<div class="method"></div>').text(sig);

                            $m.on('click', () => {
                                respBadge.hide();
                                resp.text('');
                                argsHint.text('Required: ' + m.params.filter(p => !p.optional && !p.hasDefault).map(p => p.name).join(', '));

                                // FIX: Now 'repo' refers to the global $('#repo') element
                                // and 'r' refers to the current repository data object
                                repo.val(r.class);

                                met.val(m.name);
                                args.val(fmtJson(makeArgsTemplate(m)));
                            });
                            methods.append($m);
                        });

                        header.on('click', () => {
                            const now = methods.is(':visible');
                            if (now) {
                                methods.slideUp(120);
                                header.addClass('collapsed');
                                collapsed[id] = true;
                            } else {
                                methods.slideDown(120);
                                header.removeClass('collapsed');
                                collapsed[id] = false;
                            }
                        });

                        block.append(header).append(methods);
                        repositories.append(block);
                    });

                    if (!filtered.length) {
                        repositories.html('<div style="color:#8b949e; padding:8px">No repositories match your search.</div>');
                    }
                }


                function fetchCatalog() {
                    repositories.html('<div style="color:#8b949e">Loading...</div>');
                    $.getJSON('/api/repository-test/repositories', function (data) {
                        // Fix: Access env from data.data.env
                        const env = data.data.env;

                        if (env) {
                            envStatus.text(`${env.appenv} - debug: ${env.debug ? 'on' : 'off'}`);
                        }

                        // You were already doing it correctly for repositories here:
                        catalog = data.data.repositories || [];
                        render(search.val());
                    }).fail(function (xhr) {
                        repositories.html(`Failed to load repositories: ${xhr.responseText} (${xhr.status})`);
                    });
                }


                function generateApiList() {
                    if (!catalog.length) {
                        return "No repositories found. Please refresh to load repositories.";
                    }
                    let output = "Available Repository Classes and Methods:\n\n";
                    catalog.forEach(repo => {
                        output += `${repo.short} (${repo.class})\n`;
                        if (!repo.methods.length) {
                            output += "  - No public methods\n";
                        } else {
                            repo.methods.forEach(m => {
                                const params = m.params.map(p => {
                                    let param = p.name;
                                    if (p.type) param = `${p.type} ${param}`;
                                    if (p.hasDefault) param += ` = ${JSON.stringify(p.default)}`;
                                    if (p.optional) param += '?';
                                    return param;
                                }).join(', ');
                                const returnType = m.returnType ? `: ${m.returnType}` : '';
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
                        setTimeout(() => {
                            btn.text(original).css('background', '#f97316');
                        }, 2000);
                    }).catch(() => {
                        alert('Failed to copy to clipboard');
                    });
                });

                $('#refresh').on('click', fetchCatalog);

                search.on('input', () => render(search.val()));

                function splitDebugAndJson(raw) {
                    for (let i = raw.lastIndexOf('{'); i >= 0; i = raw.lastIndexOf('{', i - 1)) {
                        const candidate = raw.slice(i).trim();
                        try {
                            const json = JSON.parse(candidate);
                            const dbg = raw.slice(0, i).trim();
                            return { debug: dbg, json };
                        } catch (e) {
                            // keep scanning
                        }
                    }
                    return { debug: raw.trim(), json: null };
                }

                $('#runBtn').on('click', function () {
                    const repo = $('#repo').val().trim();
                    const method = met.val().trim();
                    if (!repo || !method) {
                        badge('Missing repository or method', false);
                        return;
                    }
                    let argsVal;
                    const txt = args.val().trim();
                    if (txt) {
                        try {
                            argsVal = JSON.parse(txt);
                        } catch (e) {
                            badge('Invalid JSON: ' + e.message, false);
                            return;
                        }
                    }
                    resp.text('Running...');
                    respBadge.hide();

                    $.ajax({
                        url: '/api/repository-test/call',
                        method: 'POST',
                        contentType: 'application/json',
                        dataType: 'text',
                        data: JSON.stringify({ repository: repo, method, args: argsVal }),
                        success: function (raw) {
                            const { debug, json } = splitDebugAndJson(raw);
                            const ok = !!json && json.status === 'success';
                            badge(ok ? 'OK' : 'Error', ok);
                            const jsonStr = json ? JSON.stringify(json, null, 2) : '';
                            resp.text((debug ? debug + '\n\n' : '') + jsonStr);
                        },
                        error: function (xhr) {
                            const raw = xhr.responseText;
                            const { debug, json } = splitDebugAndJson(raw);
                            badge('Error', false);
                            const jsonStr = json ? JSON.stringify(json, null, 2) : JSON.stringify({
                                status: 'error',
                                message: `${xhr.statusText} (Request failed)`,
                                statusCode: xhr.status
                            }, null, 2);
                            resp.text((debug ? debug + '\n\n' : '') + jsonStr);
                        }
                    });
                });

                $('#clearBtn').on('click', function () {
                    repo.val('');
                    met.val('');
                    args.val('');
                    resp.text('');
                    respBadge.hide();
                });

                // Init
                fetchCatalog();
            </script>
        </body>

        </html>
        <?php
    }

    // API: List repositories/methods
    public function listRepositories(): void
    {
        if (!$this->isDevAllowed()) {
            $this->sendError('Repository tester is disabled outside development environment', 403);
            return;
        }

        $repositories = $this->discoverRepositoryCatalog();
        $env = [
            'appenv' => $_ENV['APP_ENV'] ?? ($_ENV['APPENV'] ?? 'unknown'),
            'debug' => $_ENV['DEBUG_MODE'] ?? ($_ENV['DEBUGMODE'] ?? true)
        ];
        $this->sendSuccess('Repository catalog', [
            'repositories' => $repositories,
            'count' => count($repositories),
            'env' => $env
        ]);
    }

    // API: Invoke method
    public function call(): void
    {
        if (!$this->isDevAllowed()) {
            $this->respondWithDebugAndJson(403, [
                'status' => 'error',
                'message' => 'Repository tester is disabled outside development environment'
            ], '');
            return;
        }

        $data = $this->getRequestData();
        $repository = $data['repository'] ?? null;
        $method = $data['method'] ?? null;
        $argsIn = $data['args'] ?? [];

        if (!$repository || !$method) {
            $this->respondWithDebugAndJson(400, [
                'status' => 'error',
                'message' => 'Missing repository or method'
            ], '');
            return;
        }

        // Allow short or FQCN
        if (!str_contains($repository, '\\')) {
            $repository = 'App\\Repositories\\' . ltrim($repository, '\\');
        }

        if (!class_exists($repository)) {
            $this->respondWithDebugAndJson(404, [
                'status' => 'error',
                'message' => 'Repository class not found: ' . $repository
            ], '');
            return;
        }

        // Capture plain echoes
        ob_start();
        try {
            $ref = new ReflectionClass($repository);
            if ($ref->isAbstract()) {
                $debug = ob_get_clean();
                $this->respondWithDebugAndJson(400, [
                    'status' => 'error',
                    'message' => 'Repository is abstract: ' . $repository
                ], $debug);
                return;
            }

            if (!$ref->hasMethod($method)) {
                $debug = ob_get_clean();
                $this->respondWithDebugAndJson(404, [
                    'status' => 'error',
                    'message' => 'Method not found on repository: ' . $method
                ], $debug);
                return;
            }

            $m = $ref->getMethod($method);
            if (!$m->isPublic() || $m->isConstructor() || str_starts_with($m->getName(), '__')) {
                $debug = ob_get_clean();
                $this->respondWithDebugAndJson(400, [
                    'status' => 'error',
                    'message' => 'Method not invokable'
                ], $debug);
                return;
            }

            // Prepare instance - inject Database
            $instance = null;
            if (!$m->isStatic()) {
                $instance = $this->newRepositoryInstance($ref);
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
                    'repository' => $repository,
                    'method' => $method,
                    'result' => $result
                ]
            ], $debug);

        } catch (\Exception $e) {
            $debug = ob_get_clean();
            $this->respondWithDebugAndJson(500, [
                'status' => 'error',
                'message' => 'Invocation failed: ' . $e->getMessage()
            ], $debug);
        }
    }

    // Return debug first, then JSON
    private function respondWithDebugAndJson(int $statusCode, array $payload, string $debug = ''): void
    {
        while (ob_get_level())
            ob_end_clean();
        http_response_code($statusCode);
        header('Content-Type: text/plain; charset=UTF-8');

        $debug = trim((string) $debug);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo ($debug !== '' ? $debug . "\n\n" : '') . $json;
        exit;
    }

    // Helpers
    private function isDevAllowed(): bool
    {
        $env = $_ENV['APP_ENV'] ?? ($_ENV['APPENV'] ?? 'production');
        $debug = $_ENV['DEBUG_MODE'] ?? ($_ENV['DEBUGMODE'] ?? false);
        return $debug || in_array(strtolower($env), ['local', 'development', 'dev'], true);
    }

    private function discoverRepositoryCatalog(): array
    {
        $apiRoot = dirname(__DIR__, 1);
        $repositoriesDir = $apiRoot . '/repositories';
        $catalog = [];

        if (!is_dir($repositoriesDir)) {
            return $catalog;
        }

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($repositoriesDir));
        foreach ($rii as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php')
                continue;

            $relative = str_replace($repositoriesDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $class = 'App\\Repositories\\' . str_replace(['/', '.php'], ['\\', ''], $relative);

            if (!class_exists($class))
                continue;

            try {
                $ref = new ReflectionClass($class);
                if ($ref->isAbstract())
                    continue;

                $methods = [];
                foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
                    if ($m->isConstructor())
                        continue;
                    $name = $m->getName();
                    if (str_starts_with($name, '__'))
                        continue;

                    $params = [];
                    foreach ($m->getParameters() as $p) {
                        $params[] = [
                            'name' => $p->getName(),
                            'hasDefault' => $p->isDefaultValueAvailable(),
                            'default' => $p->isDefaultValueAvailable() ? $this->safeDefault($p) : null,
                            'type' => $p->hasType() ? (string) $p->getType() : null,
                            'optional' => $p->isOptional(),
                            'variadic' => $p->isVariadic()
                        ];
                    }

                    $methods[] = [
                        'name' => $name,
                        'static' => $m->isStatic(),
                        'params' => $params,
                        'returnType' => $m->hasReturnType() ? (string) $m->getReturnType() : null
                    ];
                }

                $catalog[] = [
                    'class' => $class,
                    'short' => $ref->getShortName(),
                    'methods' => $methods
                ];

            } catch (\Exception $e) {
                continue;
            }
        }

        usort($catalog, fn($a, $b) => strcmp($a['short'], $b['short']));
        foreach ($catalog as &$repo) {
            usort($repo['methods'], fn($a, $b) => strcmp($a['name'], $b['name']));
        }

        return $catalog;
    }

    private function safeDefault(ReflectionParameter $p)
    {
        try {
            return $p->getDefaultValue();
        } catch (\Exception $e) {
            return null;
        }
    }

    private function newRepositoryInstance(ReflectionClass $ref)
    {
        $ctor = $ref->getConstructor();
        if (!$ctor) {
            return $ref->newInstance();
        }

        $params = $ctor->getParameters();
        $args = [];

        foreach ($params as $p) {
            $name = strtolower($p->getName());
            $type = $p->hasType() ? ltrim((string) $p->getType(), '?') : null;

            // Auto-inject Database when obvious
            if ($type === 'App\\Core\\Database' || $type === Database::class || in_array($name, ['db', 'database'], true)) {
                $args[] = $this->db;
                continue;
            }

            if ($p->isDefaultValueAvailable()) {
                $args[] = $this->safeDefault($p);
                continue;
            }

            if ($p->isOptional()) {
                continue;
            }

            throw new \Exception('Cannot resolve constructor param: ' . $p->getName());
        }

        return $ref->newInstanceArgs($args);
    }

    private function buildMethodArgs(ReflectionMethod $m, $input): array
    {
        $params = $m->getParameters();
        $out = [];
        $assoc = [];

        if (is_object($input)) {
            $input = (array) $input;
        }

        if (is_array($input) && !empty($input)) {
            $isAssoc = (array_keys($input) !== range(0, count($input) - 1));
            if ($isAssoc) {
                $assoc = $input;
            }
        }

        foreach ($params as $idx => $p) {
            $name = $p->getName();
            $hasProvided = array_key_exists($name, $assoc);
            $val = $hasProvided ? $assoc[$name] : null;

            if (!$hasProvided && is_array($input) && array_key_exists($idx, $input)) {
                $val = $input[$idx];
                $hasProvided = true;
            }

            $t = $p->hasType() ? ltrim((string) $p->getType(), '?') : null;
            $lname = strtolower($name);

            if (!$hasProvided && ($t === 'App\\Core\\Database' || $t === Database::class || in_array($lname, ['db', 'database'], true))) {
                $out[] = $this->db;
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

            throw new \Exception('Missing required argument: ' . $name);
        }

        return $out;
    }

    private function coerceType(?string $type, $val)
    {
        if ($type === null)
            return $val;

        $base = strtolower(ltrim($type, '?'));
        try {
            return match ($base) {
                'int', 'integer' => is_numeric($val) ? (int) $val : ((int) $val ?? 0),
                'float', 'double' => is_numeric($val) ? (float) $val : ((float) $val ?? 0),
                'bool', 'boolean' => is_bool($val) ? $val : in_array(strtolower((string) $val), ['1', 'true', 'yes', 'on'], true),
                'array' => is_array($val) ? $val : (is_string($val) ? json_decode($val, true, 512, JSON_THROW_ON_ERROR) : (array) $val),
                'string' => is_string($val) ? $val : json_encode($val),
                default => $val,
            };
        } catch (\Exception $e) {
            return $val;
        }
    }
}
