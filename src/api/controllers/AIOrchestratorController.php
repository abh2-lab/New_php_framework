<?php

namespace App\Controllers;

use App\Core\BaseController;
use Exception;

class AIOrchestratorController extends BaseController
{
    private string $groqApiKey;
    private string $groqModel = 'llama-3.3-70b-versatile';
    private string $baseUrl;
    private mixed  $router = null;

    public function __construct()
    {
        parent::__construct();
        $this->groqApiKey = $_ENV['GROQ_API_KEY'] ?? getenv('GROQ_API_KEY') ?? '';
        $scheme           = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host             = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $this->baseUrl    = $scheme . '://' . $host;
    }

    public function setRouter(mixed $router): void { $this->router = $router; }

    // ─── ENTRY POINTS ────────────────────────────────────────────────────────────

    public function index(): void
    {
        if (!$this->isDevAllowed()) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['error' => 'Disabled outside development environment']);
            exit;
        }
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/html; charset=UTF-8');
        echo $this->renderHtml();
        exit;
    }

    public function run(): void
    {
        if (!$this->isDevAllowed()) {
            $this->sendError('AI Orchestrator disabled outside dev environment', 403);
            return;
        }

        $data             = $this->getRequestData();
        $messages         = $data['messages']         ?? [];
        $queue            = $data['queue']             ?? [];
        $persistedHeaders = $data['persisted_headers'] ?? [];
        $mode             = $data['mode']              ?? 'test';
        $testType         = $data['test_type']         ?? 'api';

        if (empty($messages)) {
            $this->sendError('Missing messages', 400);
            return;
        }

        try {
            $routes   = $this->fetchRouteCatalog();
            $services = $this->fetchServiceCatalog();
            $repos    = $this->fetchRepositoryCatalog();

            $action     = $this->askLlmForAction($messages, $queue, $persistedHeaders, $mode, $testType, $routes, $services, $repos);
            $newHeaders = array_merge($persistedHeaders, (array)($action['set_headers'] ?? []));
            $results    = [];

            if ($action['action'] === 'run_queue') {
                foreach ((array)($action['tests_to_run'] ?? []) as $test) {
                    $type  = $test['type']  ?? $testType;
                    $input = $test['input'] ?? '';

                    // Dynamically resolve db:table.column placeholders
                    $url   = $this->resolveDynamicString($test['url'] ?? $input);
                    $body  = $this->resolveDynamicValues($test['body']  ?? []);
                    $hdrs  = $this->resolveDynamicValues(array_merge($newHeaders, (array)($test['headers'] ?? [])));

                    switch ($type) {
                        case 'api':
                            $method = strtoupper($test['method'] ?? 'GET');
                            $res    = $this->executeHttpCall($method, $url, $body, $hdrs);
                            $results[] = [
                                'label'           => "{$method} {$url}",
                                'type'            => 'api',
                                'backendResponse' => $res,
                                'context'         => $this->buildApiContext($method, $url, $body, $hdrs, $res),
                            ];
                            break;

                        case 'service':
                            [$sc, $mth] = $this->parseClassMethod($input);
                            $entry   = $this->findInCatalog($this->fetchServiceCatalog(), $sc, $mth);
                            $rawArgs = $test['args'] ?? $this->askLlmForArgs($entry, $mth, 'service');
                            $args    = $this->resolveDynamicValues((array)$rawArgs);
                            $fqcn    = $entry['class'] ?? 'App\\Services\\' . $sc;
                            $res     = $this->executeServiceCall($fqcn, $mth, $args);
                            $results[] = [
                                'label'           => "{$sc}.{$mth}()",
                                'type'            => 'service',
                                'backendResponse' => $res,
                                'context'         => $this->buildLayerContext('Service', $sc, $mth, $args, $res),
                            ];
                            break;

                        case 'repo':
                            [$sc, $mth] = $this->parseClassMethod($input);
                            $entry   = $this->findInCatalog($this->fetchRepositoryCatalog(), $sc, $mth);
                            $rawArgs = $test['args'] ?? $this->askLlmForArgs($entry, $mth, 'repository');
                            $args    = $this->resolveDynamicValues((array)$rawArgs);
                            $fqcn    = $entry['class'] ?? 'App\\Repositories\\' . $sc;
                            $res     = $this->executeRepositoryCall($fqcn, $mth, $args);
                            $results[] = [
                                'label'           => "{$sc}.{$mth}()",
                                'type'            => 'repo',
                                'backendResponse' => $res,
                                'context'         => $this->buildLayerContext('Repository', $sc, $mth, $args, $res),
                            ];
                            break;
                    }
                }
            }

            $this->sendSuccess('OK', [
                'action'       => $action['action'],
                'message'      => $action['message']      ?? '',
                'set_headers'  => $newHeaders,
                'add_to_queue' => $action['add_to_queue'] ?? [],
                'results'      => $results,
            ]);

        } catch (Exception $e) {
            $this->sendError('Orchestrator error: ' . $e->getMessage(), 500);
        }
    }

    public function routesCatalog(): void
    {
        if (!$this->isDevAllowed()) { $this->sendError('Disabled outside dev', 403); return; }
        $routes = $this->router ? ($this->router->getRoutes() ?? []) : [];
        $this->sendSuccess('Routes', $routes);
    }

    // ─── LLM ─────────────────────────────────────────────────────────────────────

    private function askLlmForAction(
        array $messages, array $queue, array $persistedHeaders,
        string $mode, string $testType,
        array $routes, array $services, array $repos
    ): array {
        $routeSummary   = $this->summarizeRoutes($routes);
        $serviceSummary = $this->summarizeClasses($services, 'service');
        $repoSummary    = $this->summarizeClasses($repos, 'repository');
        $queueStr       = empty($queue)            ? 'empty'     : implode(', ', $queue);
        $headersStr     = empty($persistedHeaders) ? 'none'      : json_encode($persistedHeaders);

        $system = <<<SYS
You are an AI test orchestrator for a PHP API backend. Analyse the conversation and decide what action to take.

Current queue: {$queueStr}
Persisted headers: {$headersStr}
Mode: {$mode} | Default test type: {$testType}

Available API routes (with full request body schemas):
{$routeSummary}

Available services:
{$serviceSummary}

Available repositories:
{$repoSummary}

Return ONLY a raw JSON object (no markdown, no explanation) with exactly this structure:
{
  "action": "run_queue|chat|set_header|add_to_queue",
  "message": "friendly reply to show in chat",
  "set_headers": {},
  "add_to_queue": [],
  "tests_to_run": [
    {
      "type": "api|service|repo",
      "input": "original input string",
      "method": "GET|POST|PUT|DELETE",
      "url": "/api/...",
      "body": {},
      "headers": {},
      "args": {}
    }
  ]
}

Decision rules:
- User says "run", "go", "execute", "test it", "fire" → action = "run_queue", build tests_to_run from queue
- User sets a token/bearer/header → action = "set_header", populate set_headers
- User wants to add something to queue → action = "add_to_queue", populate add_to_queue
- Otherwise → action = "chat", reply helpfully in message
- Always inject persisted_headers into each test's headers field

CRITICAL — data generation rules:
1. EXACT KEYS: For JSON body, form, or query params, use the EXACT field names from the schema.
2. DB VALUES: If a field description contains `db:table.column`, you MUST output the exact string "db:table.column" as its value (e.g. "db:users.id"). The backend will dynamically resolve it to a real ID.
3. ENUMS: If a description contains `enum:x,y,z`, pick one of those exact values.
4. DEFAULTS: If a description contains `default:val`, use that value.
5. TYPES: Otherwise, generate realistic dummy data matching the type (string → word, int → number, email → user@domain.com).
SYS;

        $conversation = '';
        foreach ($messages as $msg) {
            $role         = strtoupper($msg['role']    ?? 'user');
            $content      = $msg['content'] ?? '';
            $conversation .= "{$role}: {$content}\n";
        }

        $raw    = $this->callGroq($system, $conversation);
        $parsed = $this->parseJsonResponse($raw);
        if (!isset($parsed['action'])) { $parsed['action'] = 'chat'; $parsed['message'] = $raw; }
        return $parsed;
    }

    private function askLlmForArgs(array $entry, string $method, string $type): array
    {
        $paramJson = '';
        foreach ($entry['methods'] ?? [] as $m) {
            if ($m['name'] === $method) { $paramJson = json_encode($m['params'] ?? [], JSON_PRETTY_PRINT); break; }
        }
        $system = <<<SYS
You are a PHP test assistant. Return ONLY a raw JSON object of method arguments.
Method reflection: {$paramJson}

CRITICAL:
- If a parameter suggests a DB dependency (e.g. user_id), you can use the format "db:table.column" (e.g. "db:users.id") and the backend will inject a real ID.
- Example: {"id":"db:users.id","email":"test@example.com"}
SYS;
        $raw    = $this->callGroq($system, "Generate args for {$type} method: {$method}");
        return $this->parseJsonResponse($raw);
    }

    private function callGroq(string $system, string $user): string
    {
        if (!$this->groqApiKey) throw new Exception('GROQ_API_KEY is not set in environment');
        $payload = json_encode([
            'model'       => $this->groqModel,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'temperature' => 0.2,
            'max_tokens'  => 1500,
        ]);
        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->groqApiKey,
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
        if ($curlErr) throw new Exception('cURL error: ' . $curlErr);
        $decoded = json_decode($response, true);
        if ($httpCode !== 200 || !isset($decoded['choices'][0]['message']['content'])) {
            throw new Exception('Groq API error: ' . ($decoded['error']['message'] ?? "HTTP {$httpCode}"));
        }
        return $decoded['choices'][0]['message']['content'];
    }

    // ─── EXECUTION ───────────────────────────────────────────────────────────────

    private function executeHttpCall(string $method, string $url, array $body, array $headers): array
    {
        if (!str_starts_with($url, 'http')) $url = $this->baseUrl . '/' . ltrim($url, '/');
        $curlHeaders = ['Content-Type: application/json'];
        foreach ($headers as $k => $v) {
            if (strtolower($k) !== 'content-type') $curlHeaders[] = "{$k}: {$v}";
        }
        $ch   = curl_init();
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ];
        if ($method === 'GET') {
            $opts[CURLOPT_URL] = !empty($body) ? $url . '?' . http_build_query($body) : $url;
        } else {
            $opts[CURLOPT_URL]           = $url;
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            $opts[CURLOPT_POSTFIELDS]    = json_encode($body);
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
        if ($curlErr) return ['status_code' => 0, 'body' => null, 'raw' => 'cURL error: ' . $curlErr];
        return ['status_code' => $httpCode, 'body' => json_decode($response, true) ?? $response, 'raw' => $response];
    }

    private function executeServiceCall(string $fqcn, string $method, array $args): array
    {
        return $this->executeHttpCall('POST', 'service-test/call', [
            'service' => $fqcn, 'method' => $method, 'args' => $args,
        ], []);
    }

    private function executeRepositoryCall(string $fqcn, string $method, array $args): array
    {
        return $this->executeHttpCall('POST', 'repository-test/call', [
            'repository' => $fqcn, 'method' => $method, 'args' => $args,
        ], []);
    }

    // ─── DB & DYNAMIC VALUE HELPERS ──────────────────────────────────────────────

    private function resolveDynamicValues(array $data): array
    {
        foreach ($data as $k => &$v) {
            if (is_array($v)) {
                $v = $this->resolveDynamicValues($v);
            } elseif (is_string($v)) {
                $v = $this->resolveDynamicString($v);
            }
        }
        return $data;
    }

    /**
     * Replaces "db:table.column" with an actual DB value.
     */
    private function resolveDynamicString(string $str)
    {
        // Full match check (useful for JSON payload to preserve INT type)
        if (preg_match('/^db:([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)$/i', trim($str), $m)) {
            $val = $this->fetchRandomDbValue($m[1], $m[2]);
            if ($val !== null) {
                return is_numeric($val) ? (int)$val : $val;
            }
            return 1; // Fallback
        }

        // Inline match (useful for URLs like /api/users/db:users.id)
        return preg_replace_callback('/db:([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)/i', function($m) {
            $val = $this->fetchRandomDbValue($m[1], $m[2]);
            return $val !== null ? (string)$val : '1';
        }, $str);
    }

    private function fetchRandomDbValue(string $table, string $column)
    {
        try {
            if (class_exists('\App\Core\DatabaseConnection')) {
                $pdo = \App\Core\DatabaseConnection::pdo();
                $stmt = $pdo->query("SELECT {$column} FROM {$table} ORDER BY RAND() LIMIT 1");
                $val = $stmt->fetchColumn();
                return $val !== false ? $val : null;
            }
        } catch (Exception $e) {
            // Silently fail, returns null
        }
        return null;
    }

    // ─── CATALOGS ────────────────────────────────────────────────────────────────

    private function fetchRouteCatalog(): array
    {
        if ($this->router && method_exists($this->router, 'getRoutes')) return $this->router->getRoutes() ?? [];
        $res = $this->executeHttpCall('GET', 'ai-orchestrator/routes-catalog', [], []);
        return $res['body']['data'] ?? [];
    }

    private function fetchServiceCatalog(): array
    {
        $res = $this->executeHttpCall('GET', 'service-test/services', [], []);
        return $res['body']['data']['services'] ?? [];
    }

    private function fetchRepositoryCatalog(): array
    {
        $res = $this->executeHttpCall('GET', 'repository-test/repositories', [], []);
        return $res['body']['data']['repositories'] ?? [];
    }

    // ─── HELPERS ─────────────────────────────────────────────────────────────────

    private function parseClassMethod(string $input): array
    {
        $parts  = preg_split('/[.:]/', $input, 2);
        $class  = trim($parts[0] ?? '');
        $method = trim($parts[1] ?? '');
        if (!$class || !$method) throw new Exception("Could not parse Class.method from: {$input}");
        return [$class, $method];
    }

    private function findInCatalog(array $catalog, string $shortClass, string $method): array
    {
        foreach ($catalog as $entry) {
            $s = $entry['short'] ?? ''; $f = $entry['class'] ?? '';
            if (stripos($s, $shortClass) !== false || stripos($f, $shortClass) !== false) return $entry;
        }
        return ['class' => $shortClass, 'short' => $shortClass, 'methods' => []];
    }

    /**
     * Build a detailed route summary that includes the FULL params_json schema
     * so the LLM can generate correct request bodies with exact field names.
     */
    private function summarizeRoutes(array $routes): string
    {
        if (empty($routes)) return 'No route catalog available.';
        $lines = [];
        foreach (array_slice($routes, 0, 50) as $r) {
            $m    = $r['method']  ?? 'GET';
            $url  = $r['url']     ?? $r['pattern'] ?? '';
            $desc = $r['desc']    ?? $r['description'] ?? '';

            $line = "{$m} /{$url}";
            if ($desc) $line .= " — {$desc}";

            // Full JSON body schema: field name => description (type, required, etc.)
            $jsonParams = $r['params_json'] ?? [];
            if (!empty($jsonParams)) {
                $line .= "\n  JSON body fields: " . json_encode($jsonParams, JSON_UNESCAPED_SLASHES);
            }

            // Form params
            $formParams = $r['params_form'] ?? [];
            if (!empty($formParams)) {
                $line .= "\n  Form fields: " . json_encode($formParams, JSON_UNESCAPED_SLASHES);
            }

            // GET / query params
            $getParams = $r['params_get'] ?? [];
            if (!empty($getParams)) {
                $line .= "\n  Query params: " . implode(', ', array_keys($getParams));
            }

            // URL path params
            $urlParams = $r['params_url'] ?? [];
            if (!empty($urlParams)) {
                $line .= "\n  URL params: " . implode(', ', array_keys($urlParams));
            }

            $lines[] = $line;
        }
        return implode("\n\n", $lines);
    }

    private function summarizeClasses(array $catalog, string $type): string
    {
        if (empty($catalog)) return "No {$type} catalog available.";
        $lines = [];
        foreach (array_slice($catalog, 0, 30) as $entry) {
            $short   = $entry['short'] ?? $entry['class'] ?? '';
            $methods = array_column($entry['methods'] ?? [], 'name');
            $lines[] = $short . ': ' . implode(', ', array_slice($methods, 0, 8));
        }
        return implode("\n", $lines);
    }

    private function parseJsonResponse(string $raw): array
    {
        $raw   = preg_replace('/```json|```/im', '', $raw);
        $raw   = preg_replace('/<\/?think>/im', '', $raw);
        $raw   = trim($raw);
        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
            if (is_array($decoded)) return $decoded;
        }
        throw new Exception('LLM did not return valid JSON. Got: ' . substr($raw, 0, 300));
    }

    // ─── CONTEXT BUILDERS ────────────────────────────────────────────────────────

    private function buildApiContext(string $method, string $url, array $body, array $headers, array $resp): string
    {
        $lines = ["{$method} {$url}"];
        $lines[] = '';

        if (!empty($body)) {
            $lines[] = 'request:';
            $lines[] = '';
            $lines[] = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $lines[] = '';
        }

        $lines[] = 'response:';
        $lines[] = '';
        $rb      = $resp['body'] ?? $resp['raw'] ?? '';
        $lines[] = is_array($rb) ? json_encode($rb, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : (string)$rb;

        return implode("\n", $lines);
    }

    private function buildLayerContext(string $type, string $short, string $method, array $args, array $resp): string
    {
        $lines = ["{$type}: {$short} → {$method}()"];
        $lines[] = '';

        if (!empty($args)) {
            $lines[] = 'args:';
            $lines[] = '';
            $lines[] = json_encode($args, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $lines[] = '';
        }

        $lines[] = 'response:';
        $lines[] = '';
        $rb      = $resp['body']['data']['result'] ?? $resp['body'] ?? $resp['raw'] ?? '';
        $lines[] = is_array($rb) ? json_encode($rb, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : (string)$rb;

        return implode("\n", $lines);
    }

    private function isDevAllowed(): bool
    {
        $env   = $_ENV['APP_ENV']    ?? getenv('APP_ENV')    ?? 'production';
        $debug = $_ENV['DEBUG_MODE'] ?? getenv('DEBUG_MODE') ?? false;
        return (bool)$debug || in_array(strtolower((string)$env), ['local', 'development', 'dev']);
    }

    // ─── HTML RENDERER ───────────────────────────────────────────────────────────

    private function renderHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AI Orchestrator</title>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<style>
:root {
  --bg:       #0d1117;
  --panel:    #161b22;
  --panel2:   #0f1419;
  --border:   #30363d;
  --text:     #e6edf3;
  --muted:    #8b949e;
  --brand:    #6366f1;
  --brand-dk: #4f46e5;
  --ok:       #3fb950;
  --ok-bg:    rgba(46,160,67,.15);
  --err:      #f85149;
  --err-bg:   rgba(248,81,73,.15);
  --warn:     #d29922;
  --warn-bg:  rgba(210,153,34,.15);
  --api:      #38bdf8;
  --api-bg:   rgba(56,189,248,.1);
  --svc:      #a78bfa;
  --svc-bg:   rgba(167,139,250,.1);
  --repo:     #fb923c;
  --repo-bg:  rgba(251,146,60,.1);
  --ai-bubble:#161b22;
}
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html,body {
  height:100%; overflow:hidden;
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
  font-size:15px; background:var(--bg); color:var(--text);
}

/* ── LAYOUT ── */
.app { display:flex; flex-direction:column; height:100vh; padding:10px; gap:10px; }
.header {
  flex:0 0 auto; display:flex; align-items:center; justify-content:space-between;
  background:linear-gradient(135deg,#1e1b4b 0,#312e81 100%);
  padding:9px 16px; border-radius:8px; box-shadow:0 4px 16px rgba(99,102,241,.25);
}
.header h1 { font-size:17px; color:#fff; display:flex; align-items:center; gap:8px; }
.env-badge { font-size:12px; background:rgba(99,102,241,.25); color:#a5b4fc; padding:2px 10px; border-radius:12px; border:1px solid rgba(99,102,241,.4); }
.columns { flex:1; display:grid; grid-template-columns:1fr 1fr; gap:10px; min-height:0; }
.panel { background:var(--panel); border:1px solid var(--border); border-radius:8px; display:flex; flex-direction:column; min-height:0; overflow:hidden; }

/* ── LEFT: CHAT ── */
.chat-thread { flex:1; overflow-y:auto; padding:16px; display:flex; flex-direction:column; gap:10px; scroll-behavior:smooth; }
.bubble-wrap { display:flex; flex-direction:column; gap:3px; }
.bubble-wrap.user { align-items:flex-end; }
.bubble-wrap.ai   { align-items:flex-start; }
.bubble-label { font-size:11px; color:var(--muted); padding:0 4px; }
.bubble { max-width:85%; padding:10px 14px; border-radius:12px; font-size:14px; line-height:1.6; white-space:pre-wrap; word-break:break-word; }
.bubble.user  { background:var(--brand); color:#fff; border-bottom-right-radius:3px; }
.bubble.ai    { background:var(--ai-bubble); border:1px solid var(--border); color:var(--text); border-bottom-left-radius:3px; }
.bubble.thinking   { color:var(--muted); font-style:italic; display:flex; align-items:center; gap:8px; }
.bubble.error-bubble { background:var(--err-bg); border-color:var(--err); color:var(--err); }
.chat-empty { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; color:var(--muted); text-align:center; }
.chat-empty svg { width:40px; height:40px; opacity:.3; }
.chat-empty p { font-size:14px; }
.chat-empty .hint { font-size:12px; max-width:260px; line-height:1.6; }

/* ── QUEUE BAR ── */
.queue-bar {
  flex:0 0 auto; padding:8px 12px; border-top:1px solid var(--border);
  display:flex; flex-direction:column; gap:7px;
  background:var(--panel2);
}
.queue-pills-row { display:flex; align-items:center; gap:6px; flex-wrap:wrap; min-height:26px; }
.queue-empty-hint { font-size:12px; color:var(--muted); font-style:italic; }
.q-pill { display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:600; padding:3px 9px; border-radius:20px; border:1px solid var(--border); background:rgba(255,255,255,.05); color:var(--text); }
.q-pill.api-pill  { border-color:rgba(56,189,248,.4);  color:var(--api);  background:var(--api-bg);  }
.q-pill.svc-pill  { border-color:rgba(167,139,250,.4); color:var(--svc);  background:var(--svc-bg);  }
.q-pill.repo-pill { border-color:rgba(251,146,60,.4);  color:var(--repo); background:var(--repo-bg); }
.q-pill .remove { cursor:pointer; opacity:.6; font-size:14px; line-height:1; padding:0 2px; }
.q-pill .remove:hover { opacity:1; color:var(--err); }

/* ── QUEUE INPUT ROW ── */
.queue-input-row { display:flex; align-items:center; gap:6px; }
#queueInput {
  flex:1; background:var(--bg); border:1px solid var(--border);
  color:var(--text); border-radius:6px; padding:6px 11px;
  font-size:13px; font-family:inherit; outline:none;
  transition:border-color .15s;
}
#queueInput:focus { border-color:var(--brand); }
#queueInput::placeholder { color:var(--muted); }
.q-btn { font-size:12px; font-weight:600; padding:5px 11px; border-radius:6px; border:none; cursor:pointer; transition:background .15s; white-space:nowrap; }
.q-btn-add { background:rgba(255,255,255,.07); color:var(--muted); }
.q-btn-add:hover { background:rgba(255,255,255,.12); color:var(--text); }
.q-btn-run { background:var(--brand); color:#fff; }
.q-btn-run:hover { background:var(--brand-dk); }
.q-btn-run:disabled { opacity:.45; cursor:not-allowed; }

/* ── CHAT INPUT ── */
.chat-input-row { flex:0 0 auto; display:flex; gap:8px; padding:10px 12px; border-top:1px solid var(--border); background:var(--panel2); align-items:flex-end; }
#chatInput { flex:1; background:var(--bg); border:1px solid var(--border); color:var(--text); border-radius:8px; padding:10px 13px; font-size:14px; font-family:inherit; resize:none; outline:none; line-height:1.5; max-height:120px; overflow-y:auto; transition:border-color .15s; }
#chatInput:focus { border-color:var(--brand); }
#chatInput::placeholder { color:var(--muted); }
.send-btn { width:38px; height:38px; border-radius:8px; background:var(--brand); border:none; cursor:pointer; color:#fff; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:background .15s; align-self:flex-end; }
.send-btn:hover { background:var(--brand-dk); }
.send-btn:disabled { opacity:.45; cursor:not-allowed; }

/* ── RIGHT: TOOLBAR ── */
.right-toolbar { flex:0 0 auto; padding:8px 12px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:8px; flex-wrap:wrap; background:var(--panel2); }
.toolbar-group { display:flex; align-items:center; gap:4px; }
.toolbar-label { font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); margin-right:2px; }
.pill-toggle { font-size:12px; font-weight:600; padding:3px 11px; border-radius:20px; border:1px solid var(--border); background:transparent; color:var(--muted); cursor:pointer; transition:all .15s; }
.pill-toggle:hover { color:var(--text); border-color:var(--text); }
.pill-toggle.active-mode { background:rgba(99,102,241,.15); color:var(--brand);  border-color:rgba(99,102,241,.5); }
.pill-toggle.active-api  { background:var(--api-bg);         color:var(--api);   border-color:rgba(56,189,248,.5); }
.pill-toggle.active-svc  { background:var(--svc-bg);         color:var(--svc);   border-color:rgba(167,139,250,.5); }
.pill-toggle.active-repo { background:var(--repo-bg);        color:var(--repo);  border-color:rgba(251,146,60,.5); }
.toolbar-sep { width:1px; height:16px; background:var(--border); }

/* ── CONTEXT BOX ── */
.context-header {
  flex:0 0 auto; display:flex; align-items:center; gap:8px;
  padding:8px 12px; border-bottom:1px solid var(--border);
  background:var(--panel2);
}
.context-title { font-size:12px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); flex:1; }
.copy-ctx-btn {
  font-size:12px; font-weight:600; padding:4px 12px; border-radius:6px;
  border:1px solid var(--border); background:transparent; color:var(--text);
  cursor:pointer; transition:all .15s;
}
.copy-ctx-btn:hover { background:rgba(255,255,255,.07); }
.copy-ctx-btn.copied { color:var(--ok); border-color:var(--ok); }
.clear-ctx-btn {
  font-size:12px; background:transparent; border:1px solid var(--border);
  color:var(--muted); padding:4px 10px; border-radius:6px; cursor:pointer;
  transition:all .15s;
}
.clear-ctx-btn:hover { color:var(--text); border-color:var(--text); }
.context-box-wrap { flex:1; overflow:hidden; padding:12px; display:flex; }
.context-box {
  width:100%; background:var(--bg); border:1px solid var(--border);
  border-radius:6px; padding:14px; font-family:'Monaco','Menlo',monospace;
  font-size:13px; color:var(--text); white-space:pre-wrap; word-break:break-word;
  line-height:1.75; overflow-y:auto; margin:0;
}
.ctx-empty-hint { color:var(--muted); font-style:italic; font-size:13px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; }

/* ── SCROLLBARS ── */
::-webkit-scrollbar { width:5px; height:5px; }
::-webkit-scrollbar-track { background:transparent; }
::-webkit-scrollbar-thumb { background:var(--border); border-radius:3px; }
::-webkit-scrollbar-thumb:hover { background:var(--muted); }

/* ── SPINNER ── */
@keyframes spin { to { transform:rotate(360deg); } }
.spinner { width:13px; height:13px; border:2px solid var(--border); border-top-color:var(--brand); border-radius:50%; animation:spin .7s linear infinite; display:inline-block; flex-shrink:0; }
</style>
</head>
<body>
<div class="app">

  <!-- HEADER -->
  <div class="header">
    <h1>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="18" height="18">
        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
      </svg>
      AI Orchestrator
    </h1>
    <span class="env-badge">dev mode</span>
  </div>

  <!-- COLUMNS -->
  <div class="columns">

    <!-- LEFT: CHAT -->
    <div class="panel">
      <div class="chat-thread" id="chatThread">
        <div class="chat-empty" id="chatEmpty">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
          </svg>
          <p>Start by typing a message…</p>
          <p class="hint">Try: <em>"use Bearer eyJ…"</em>, <em>"add POST /api/auth/login"</em>, or just <em>"run"</em></p>
        </div>
      </div>

      <!-- Queue pill bar -->
      <div class="queue-bar" id="queueBar">
        <div class="queue-pills-row" id="queuePillsRow">
          <span class="queue-empty-hint" id="queueHint">Queue is empty — add items below</span>
        </div>
        <div class="queue-input-row">
          <input
            type="text"
            id="queueInput"
            placeholder="e.g.  POST /api/auth/login  or  AuthService.login"
            autocomplete="off"
          />
          <button class="q-btn q-btn-add" id="addToQueueBtn">+ Add</button>
          <button class="q-btn q-btn-run" id="runAllBtn" disabled>▶ Run All</button>
        </div>
      </div>

      <!-- Chat input -->
      <div class="chat-input-row">
        <textarea id="chatInput" rows="1" placeholder="Message Orchestrator… (Enter to send, Shift+Enter for newline)"></textarea>
        <button class="send-btn" id="sendBtn">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
            <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
          </svg>
        </button>
      </div>
    </div>

    <!-- RIGHT: RESULTS -->
    <div class="panel">

      <!-- Compact toolbar -->
      <div class="right-toolbar">
        <div class="toolbar-group">
          <span class="toolbar-label">Mode</span>
          <button class="pill-toggle active-mode" data-mode="test">Test</button>
          <button class="pill-toggle" data-mode="context">Context</button>
        </div>
        <div class="toolbar-sep"></div>
        <div class="toolbar-group" id="testTypePills">
          <span class="toolbar-label">Type</span>
          <button class="pill-toggle active-api"  data-type="api">API</button>
          <button class="pill-toggle"              data-type="service">Service</button>
          <button class="pill-toggle"              data-type="repo">Repo</button>
        </div>
      </div>

      <!-- Context box header -->
      <div class="context-header">
        <span class="context-title">Test Results</span>
        <button class="copy-ctx-btn" id="copyCtxBtn">Copy</button>
        <button class="clear-ctx-btn" id="clearCtxBtn">Clear</button>
      </div>

      <!-- Single context text box -->
      <div class="context-box-wrap">
        <pre class="context-box" id="contextBox"><span class="ctx-empty-hint">Results will appear here after running tests…</span></pre>
      </div>

    </div>
  </div>
</div>

<script>
// ── STATE ─────────────────────────────────────────────────────────────────────
const state = {
  messages:         [],
  queue:            [],
  persistedHeaders: {},
  mode:             'test',
  testType:         'api',
  busy:             false,
};

let contextContent = '';

// ── DOM ───────────────────────────────────────────────────────────────────────
const $thread      = $('#chatThread');
const $chatEmpty   = $('#chatEmpty');
const $input       = $('#chatInput');
const $sendBtn     = $('#sendBtn');
const $runAllBtn   = $('#runAllBtn');
const $queueInput  = $('#queueInput');
const $pillsRow    = $('#queuePillsRow');
const $queueHint   = $('#queueHint');
const $contextBox  = $('#contextBox');

// ── TOOLBAR ───────────────────────────────────────────────────────────────────
$('[data-mode]').on('click', function () {
  state.mode = $(this).data('mode');
  $('[data-mode]').removeClass('active-mode');
  $(this).addClass('active-mode');
  const isTest = state.mode === 'test';
  $('#testTypePills').css('opacity', isTest ? 1 : .4).css('pointer-events', isTest ? '' : 'none');
});

$('[data-type]').on('click', function () {
  const t = $(this).data('type');
  state.testType = t;
  $('[data-type]').removeClass('active-api active-svc active-repo');
  const cls = { api: 'active-api', service: 'active-svc', repo: 'active-repo' }[t] || 'active-api';
  $(this).addClass(cls);
});

// ── CONTEXT BOX CONTROLS ──────────────────────────────────────────────────────
$('#copyCtxBtn').on('click', function () {
  if (!contextContent) return;
  navigator.clipboard.writeText(contextContent).then(() => {
    const $btn = $(this);
    $btn.text('Copied!').addClass('copied');
    setTimeout(() => { $btn.text('Copy').removeClass('copied'); }, 2000);
  }).catch(() => {
    const ta = document.createElement('textarea');
    ta.value = contextContent;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  });
});

$('#clearCtxBtn').on('click', () => {
  contextContent = '';
  $contextBox.html('<span class="ctx-empty-hint">Results will appear here after running tests…</span>');
});

// ── CONTEXT BOX APPEND ────────────────────────────────────────────────────────
const SEPARATOR = '\n' + '─'.repeat(65) + '\n';

function appendToContextBox(result) {
  const ctx = (result.context ?? '').trim();
  if (!ctx) return;

  if (contextContent) {
    contextContent += '\n' + SEPARATOR + '\n';
  }
  contextContent += ctx;

  $contextBox.text(contextContent);
  $contextBox[0].scrollTop = $contextBox[0].scrollHeight;
}

// ── QUEUE ─────────────────────────────────────────────────────────────────────
function pillClass(item) {
  if (/^(GET|POST|PUT|PATCH|DELETE)\s/i.test(item)) return 'api-pill';
  if (/repository|repo/i.test(item))                 return 'repo-pill';
  if (/service/i.test(item))                         return 'svc-pill';
  return 'api-pill';
}

function renderQueue() {
  $pillsRow.find('.q-pill').remove();
  if (state.queue.length === 0) {
    $queueHint.show();
    $runAllBtn.prop('disabled', true);
  } else {
    $queueHint.hide();
    $runAllBtn.prop('disabled', false);
    state.queue.forEach((item, idx) => {
      const $pill = $(`<span class="q-pill ${pillClass(item)}">${escHtml(item)}<span class="remove" data-idx="${idx}">×</span></span>`);
      $pill.find('.remove').on('click', () => { state.queue.splice(idx, 1); renderQueue(); });
      $pillsRow.append($pill);
    });
  }
}

function addToQueue(item) {
  if (!item || state.queue.includes(item)) return;
  state.queue.push(item);
  renderQueue();
}

function doAddFromInput() {
  const val = $queueInput.val().trim();
  if (val) { addToQueue(val); $queueInput.val('').focus(); }
}

$('#addToQueueBtn').on('click', doAddFromInput);
$queueInput.on('keydown', function (e) {
  if (e.key === 'Enter') { e.preventDefault(); doAddFromInput(); }
});

$runAllBtn.on('click', () => {
  if (state.queue.length === 0 || state.busy) return;
  sendMessage('run all');
});

// ── CHAT BUBBLES ──────────────────────────────────────────────────────────────
function escHtml(str) {
  return String(str)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function appendBubble(role, text, extra = '') {
  $chatEmpty.hide();
  const label = role === 'user' ? 'You' : 'Orchestrator';
  const $wrap = $(`<div class="bubble-wrap ${role}"></div>`);
  $wrap.append(`<span class="bubble-label">${label}</span>`);
  $wrap.append(`<div class="bubble ${role} ${extra}">${escHtml(text)}</div>`);
  $thread.append($wrap);
  $thread[0].scrollTop = $thread[0].scrollHeight;
}

function appendThinkingBubble() {
  $chatEmpty.hide();
  const $wrap = $('<div class="bubble-wrap ai"></div>');
  $wrap.append('<span class="bubble-label">Orchestrator</span>');
  $wrap.append('<div class="bubble ai thinking"><span class="spinner"></span> Thinking…</div>');
  $thread.append($wrap);
  $thread[0].scrollTop = $thread[0].scrollHeight;
  return $wrap;
}

// ── SEND ──────────────────────────────────────────────────────────────────────
function setBusy(busy) {
  state.busy = busy;
  $sendBtn.prop('disabled', busy);
  $input.prop('disabled', busy);
  $runAllBtn.prop('disabled', busy || state.queue.length === 0);
}

function sendMessage(text) {
  text = (text || $input.val()).trim();
  if (!text || state.busy) return;
  $input.val('').css('height', '');

  state.messages.push({ role: 'user', content: text });
  appendBubble('user', text);

  const $thinking = appendThinkingBubble();
  setBusy(true);

  $.ajax({
    url:         'ai-orchestrator/run',
    method:      'POST',
    contentType: 'application/json',
    data: JSON.stringify({
      messages:          state.messages,
      queue:             state.queue,
      persisted_headers: state.persistedHeaders,
      mode:              state.mode,
      test_type:         state.testType,
    }),
    success(res) {
      $thinking.remove();
      const d = res.data ?? res;

      if (d.set_headers && Object.keys(d.set_headers).length > 0) {
        state.persistedHeaders = d.set_headers;
      }
      if (Array.isArray(d.add_to_queue)) {
        d.add_to_queue.forEach(item => addToQueue(item));
      }

      const msg = d.message || '';
      if (msg) {
        state.messages.push({ role: 'assistant', content: msg });
        appendBubble('ai', msg);
      }

      if (Array.isArray(d.results) && d.results.length > 0) {
        d.results.forEach(r => appendToContextBox(r));
        if (!msg) {
          const summary = `Ran ${d.results.length} test${d.results.length > 1 ? 's' : ''}. Results on the right →`;
          state.messages.push({ role: 'assistant', content: summary });
          appendBubble('ai', summary);
        }
        if (d.action === 'run_queue') { state.queue = []; renderQueue(); }
      }
    },
    error(xhr) {
      $thinking.remove();
      const msg = safeJson(xhr.responseText)?.message ?? xhr.statusText ?? 'Request failed';
      appendBubble('ai', msg, 'error-bubble');
    },
    complete() { setBusy(false); },
  });
}

// ── INPUT AUTO-RESIZE ─────────────────────────────────────────────────────────
$input.on('input', function () {
  this.style.height = 'auto';
  this.style.height = Math.min(this.scrollHeight, 120) + 'px';
});
$input.on('keydown', function (e) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});
$sendBtn.on('click', () => sendMessage());

// ── UTILS ─────────────────────────────────────────────────────────────────────
function safeJson(str) { try { return JSON.parse(str); } catch { return null; } }

// ── INIT ──────────────────────────────────────────────────────────────────────
renderQueue();
$input.trigger('focus');
</script>
</body>
</html>
HTML;
    }
}
