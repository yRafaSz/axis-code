<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

session_name('axis_session');
session_start();

set_exception_handler(function (Throwable $error): void {
    respond(500, [
        'error' => 'Falha interna do PHP.',
        'details' => $error->getMessage(),
        'file' => basename($error->getFile()),
        'line' => $error->getLine(),
    ]);
});

const OPENROUTER_ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';
const CONFIG_FILE = __DIR__ . '/providers.local.php';

function respond(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function parse_model_link(string $value): string {
    $input = trim($value);
    if ($input === '') return '';
    if (!preg_match('~^https?://~i', $input)) {
        return preg_match('~^[\w.-]+/[\w.:+\-]+$~', $input) ? $input : '';
    }
    $parts = parse_url($input);
    $host = strtolower((string)($parts['host'] ?? ''));
    if ($host !== 'openrouter.ai' && $host !== 'www.openrouter.ai') return '';
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
        if (!empty($query['model']) && preg_match('~^[\w.-]+/[\w.:+\-]+$~', (string)$query['model'])) return (string)$query['model'];
    }
    $segments = array_values(array_filter(explode('/', trim((string)($parts['path'] ?? ''), '/')), 'strlen'));
    if (($segments[0] ?? '') === 'models') array_shift($segments);
    if (($segments[0] ?? '') === 'api' || count($segments) < 2) return '';
    return $segments[0] . '/' . $segments[1];
}

function load_agents(): array {
    $config = null;
    $environmentConfig = trim((string)(getenv('AXIS_AGENTS_JSON') ?: ''));
    if ($environmentConfig !== '') {
        $config = json_decode($environmentConfig, true);
        if (!is_array($config)) {
            respond(500, ['error' => 'A variável AXIS_AGENTS_JSON não contém JSON válido.']);
        }
    } elseif (is_file(CONFIG_FILE)) {
        try {
            $config = require CONFIG_FILE;
        } catch (Throwable $error) {
            respond(500, [
                'error' => 'Erro no arquivo providers.local.php.',
                'details' => $error->getMessage(),
                'line' => $error->getLine(),
            ]);
        }
    }
    if (!is_array($config)) return [];
    $source = isset($config['agents']) && is_array($config['agents']) ? $config['agents'] : $config;
    $agents = [];
    foreach ($source as $item) {
        if (!is_array($item)) continue;
        $model = parse_model_link((string)($item['modelLink'] ?? ''));
        $key = trim((string)($item['apiKey'] ?? ''));
        $placeholder = preg_match('~(COLOQUE|SUA[-_ ]?NOVA|SUA[-_ ]?CHAVE|EXEMPLO|YOUR[-_ ]?KEY)~i', $key) === 1;
        if ($model !== '' && $key !== '' && !$placeholder) $agents[] = ['model' => $model, 'apiKey' => $key];
    }
    return array_slice($agents, 0, 10);
}

function check_agents_health(array $agents): array {
    if (!$agents || !function_exists('curl_init') || !function_exists('curl_exec')) return ['online' => 0, 'indexes' => []];
    $fingerprintParts = [];
    foreach ($agents as $agent) $fingerprintParts[] = hash('sha256', $agent['apiKey']) . ':' . $agent['model'];
    $cacheFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'axis-health-' . hash('sha256', implode('|', $fingerprintParts)) . '.json';
    if (is_file($cacheFile) && filemtime($cacheFile) > time() - 300) {
        $cached = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['online'], $cached['indexes']) && is_array($cached['indexes'])) return $cached;
    }

    $online = 0;
    $onlineIndexes = [];
    foreach ($agents as $index => $agent) {
        $handle = curl_init('https://openrouter.ai/api/v1/key');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $agent['apiKey']]
        ]);
        $raw = curl_exec($handle);
        $httpStatus = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $data = json_decode(is_string($raw) ? $raw : '', true);
        if ($httpStatus === 200 && isset($data['data'])) { $online++; $onlineIndexes[] = $index; }
        curl_close($handle);
    }
    $health = ['online' => $online, 'indexes' => $onlineIndexes, 'checkedAt' => time()];
    @file_put_contents($cacheFile, json_encode($health));
    return $health;
}

function latest_user_content(array $messages): string {
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if (($messages[$i]['role'] ?? '') === 'user') return (string)($messages[$i]['content'] ?? '');
    }
    return '';
}

function request_content_for_classification(array $messages): string {
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if (($messages[$i]['role'] ?? '') !== 'user') continue;
        $content = (string)($messages[$i]['content'] ?? '');
        if (str_starts_with(ltrim($content), 'CONTINUAÇÃO_INTERNA_DE_PROJETO_GRANDE:')) continue;
        return $content;
    }
    return latest_user_content($messages);
}

function visible_request(string $content): string {
    $parts = preg_split('~\n\n(?:ARQUIVOS ANEXADOS|PROFUNDIDADE DEFINIDA AUTOMATICAMENTE|MODO AUTO ZIP ATIVO|PRIMEIRA_RESPOSTA):~iu', $content, 2);
    return trim((string)($parts[0] ?? $content));
}

function enforce_direct_answer_style(array $messages): array {
    $instruction = 'REGRA DE ESTILO OBRIGATÓRIA: responda diretamente ao pedido. Não repita, parafraseie, resuma ou transforme a pergunta do usuário em título ou introdução. Não comece com frases como "Você perguntou", "Seu pedido" ou com um cabeçalho que apenas reproduza o comando. Use títulos somente quando forem úteis para organizar uma resposta extensa, e nunca use a pergunta como título.';
    foreach ($messages as &$message) {
        if (($message['role'] ?? '') !== 'system') continue;
        $message['content'] = rtrim((string)($message['content'] ?? '')) . "\n\n" . $instruction;
        unset($message);
        return $messages;
    }
    unset($message);
    array_unshift($messages, ['role' => 'system', 'content' => $instruction]);
    return $messages;
}

function is_simple_greeting(string $content): bool {
    return preg_match('~^(?:ol[aá]+|oi+|opa+|e+a[ií]+|hey+|hello+|hi+|bom\s+dia|boa\s+tarde|boa\s+noite)[\s!,.?]*$~iu', visible_request($content)) === 1;
}

function order_agents_for_request(array $agents, string $complexity, bool $sharedHosting): array {
    $weights = $complexity === 'light'
        ? ['north-mini-code' => 120, 'nemotron-3-super' => 112, 'gemma-4-31b' => 100, 'gemma-4-26b' => 95, 'nemotron-3-ultra' => 70]
        : ($complexity === 'heavy'
            ? ['nemotron-3-super' => 125, 'north-mini-code' => 120, 'gemma-4-31b' => 110, 'nemotron-3-ultra' => 100, 'gemma-4-26b' => 95]
            : ['north-mini-code' => 122, 'nemotron-3-super' => 118, 'gemma-4-31b' => 108, 'gemma-4-26b' => 100, 'nemotron-3-ultra' => 85]);
    foreach ($agents as $index => &$agent) {
        $agent['_order'] = $index;
        $agent['_score'] = 80;
        foreach ($weights as $needle => $score) if (stripos($agent['model'], $needle) !== false) { $agent['_score'] = $score; break; }
    }
    unset($agent);
    usort($agents, function ($a, $b) {
        return $b['_score'] <=> $a['_score'] ?: $a['_order'] <=> $b['_order'];
    });
    foreach ($agents as &$agent) unset($agent['_score'], $agent['_order']);
    unset($agent);
    return $agents;
}

function classify_request(array $messages): array {
    $latest = request_content_for_classification($messages);
    $lower = function_exists('mb_strtolower') ? mb_strtolower($latest, 'UTF-8') : strtolower($latest);
    $score = 0;
    if (strlen($latest) > 3000) $score += 2;
    if (strlen($latest) > 12000) $score += 2;
    $files = substr_count($latest, '--- INÍCIO DO ARQUIVO:');
    if ($files >= 2) $score += 2;
    if ($files >= 6) $score += 2;
    $patterns = [
        'projeto completo|aplica.{0,3}o completa|sistema completo|arquitetura|microsservi',
        'refator|migra.{0,3}o|seguran.{0,3}a|vulnerabilidade|auditoria',
        'concorr.{0,3}ncia|race condition|debug profundo|causa raiz|stack trace',
        'testes?.*(integra.{0,3}o|complet|cobertura)|ci/cd|docker|kubernetes',
        'spring boot.*(security|jpa|postgres|jwt)|maven.*(package|test|compile)'
    ];
    foreach ($patterns as $pattern) if (preg_match('~' . $pattern . '~iu', $lower)) $score += 2;
    if (preg_match('~autentica.{0,3}o|autoriza.{0,3}o|pagamento|criptograf|corrup.{0,3}o de dados|perda de dados|opera.{0,3}(?:o|es) destrutiva|c.{0,3}digo nativo|memory leak|race condition~iu', $lower)) $score += 3;
    if (preg_match('~analise|revise|compare|melhor solu.{0,3}o|produ.{0,3}o|profissional~iu', $lower)) $score += 1;
    if (count($messages) >= 8) $score += 1;
    if (preg_match('~(?:crie|criar|desenvolva|desenvolver|gere|gerar|construa|implemente).{0,120}(?:plugin|projeto|sistema|aplica.{0,3}o|api|site|bot).{0,160}(?:complet|todas?\s+as\s+classes|todos?\s+os\s+arquivos|execut[aá]vel)|entreg[aá]vel\s*:?.{0,180}(?:classes|arquivos|plugin\.yml|config\.yml)~isu', $lower)) $score += 5;
    $complexity = $score >= 5 ? 'heavy' : ($score >= 2 ? 'medium' : 'light');
    return ['complexity' => $complexity, 'score' => $score, 'agents' => $complexity === 'heavy' ? 5 : ($complexity === 'medium' ? 2 : 1)];
}

function curl_for_agent(array $agent, array $payload, int $timeoutSeconds = 45) {
    $payload['model'] = $agent['model'];
    $ch = curl_init(OPENROUTER_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $agent['apiKey'],
            'HTTP-Referer: https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'X-Title: Axis Code'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ]);
    return $ch;
}

function parse_openrouter_result($ch, string $raw): array {
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = json_decode($raw, true);
    if ($status < 200 || $status >= 300) return ['ok' => false, 'status' => $status, 'error' => (string)($data['error']['message'] ?? ('OpenRouter HTTP ' . $status))];
    $content = (string)($data['choices'][0]['message']['content'] ?? '');
    $finishReason = (string)($data['choices'][0]['finish_reason'] ?? '');
    return $content !== ''
        ? ['ok' => true, 'status' => $status, 'content' => $content, 'partial' => in_array($finishReason, ['length', 'max_tokens'], true)]
        : ['ok' => false, 'status' => $status, 'error' => 'O modelo retornou uma resposta vazia.'];
}

function supports_parallel_curl(): bool {
    return function_exists('curl_multi_init')
        && function_exists('curl_multi_add_handle')
        && function_exists('curl_multi_exec')
        && function_exists('curl_multi_select')
        && function_exists('curl_multi_getcontent')
        && function_exists('curl_multi_remove_handle')
        && function_exists('curl_multi_close');
}

function call_parallel(array $agents, array $payload): array {
    if (!supports_parallel_curl()) return [];
    $multi = curl_multi_init();
    $handles = [];
    foreach ($agents as $index => $agent) {
        $handles[$index] = curl_for_agent($agent, $payload, 30);
        curl_multi_add_handle($multi, $handles[$index]);
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) curl_multi_select($multi, 1.0);
    } while ($running && $status === CURLM_OK);
    $results = [];
    foreach ($handles as $handle) {
        $result = parse_openrouter_result($handle, (string)curl_multi_getcontent($handle));
        if ($result['ok']) $results[] = $result['content'];
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);
    return $results;
}

function call_single(array $agent, array $payload, int $timeoutSeconds = 45): array {
    $ch = curl_for_agent($agent, $payload, $timeoutSeconds);
    $raw = curl_exec($ch);
    if ($raw === false) { $error = curl_error($ch); curl_close($ch); return ['ok' => false, 'error' => $error]; }
    $result = parse_openrouter_result($ch, (string)$raw);
    curl_close($ch);
    return $result;
}

function call_single_streamed(array $agent, array $payload, int $timeoutSeconds = 45): array {
    $payload['stream'] = true;
    $content = '';
    $rawBody = '';
    $lineBuffer = '';
    $streamError = '';
    $streamDone = false;
    $finishReason = '';
    $streamStatus = 0;
    $consumeLine = function (string $line) use (&$content, &$streamError, &$streamDone, &$finishReason, &$streamStatus): void {
        $line = trim($line);
        if (!str_starts_with($line, 'data:')) return;
        $json = trim(substr($line, 5));
        if ($json === '[DONE]') { $streamDone = true; return; }
        if ($json === '') return;
        $data = json_decode($json, true);
        if (!is_array($data)) return;
        if (isset($data['error'])) {
            $streamError = (string)($data['error']['message'] ?? 'O provedor interrompeu a resposta.');
            $streamStatus = (int)($data['error']['code'] ?? 0);
            return;
        }
        $delta = $data['choices'][0]['delta']['content'] ?? '';
        if (is_string($delta)) $content .= $delta;
        $reason = $data['choices'][0]['finish_reason'] ?? null;
        if (is_string($reason) && $reason !== '') $finishReason = $reason;
    };

    $ch = curl_for_agent($agent, $payload, $timeoutSeconds);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($handle, string $chunk) use (&$rawBody, &$lineBuffer, $consumeLine): int {
        $rawBody .= $chunk;
        $lineBuffer .= $chunk;
        while (($newline = strpos($lineBuffer, "\n")) !== false) {
            $line = substr($lineBuffer, 0, $newline);
            $lineBuffer = substr($lineBuffer, $newline + 1);
            $consumeLine($line);
        }
        return strlen($chunk);
    });

    $executed = curl_exec($ch);
    if ($lineBuffer !== '') $consumeLine($lineBuffer);
    $curlError = $executed === false ? curl_error($ch) : '';
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (trim($content) !== '') {
        $partial = ($executed === false && !$streamDone) || in_array($finishReason, ['length', 'max_tokens'], true);
        curl_close($ch);
        return ['ok' => true, 'status' => $status, 'content' => $content, 'partial' => $partial];
    }

    // Alguns provedores ignoram stream=true e retornam o JSON convencional.
    if ($status >= 200 && $status < 300 && $rawBody !== '') {
        $regular = parse_openrouter_result($ch, $rawBody);
        curl_close($ch);
        return $regular;
    }
    curl_close($ch);
    return ['ok' => false, 'status' => $streamStatus ?: $status, 'error' => $streamError ?: ($curlError ?: ('OpenRouter HTTP ' . $status))];
}

function call_first_available(array $agents, array $payload, int $totalSeconds = 48, int $perAttemptSeconds = 30): array {
    $startedAt = microtime(true);
    $errors = [];
    $rateLimited = 0;
    $otherFailures = 0;
    foreach ($agents as $agent) {
        $remaining = $totalSeconds - (int)(microtime(true) - $startedAt);
        if ($remaining < 7) break;
        $timeout = min($perAttemptSeconds, max(7, $remaining - 2));
        $result = call_single_streamed($agent, $payload, $timeout);
        if ($result['ok']) return $result;
        if ((int)($result['status'] ?? 0) === 429) $rateLimited++; else $otherFailures++;
        $errors[] = $agent['model'] . ': ' . $result['error'];
    }

    // Última alternativa para modelos gratuitos temporariamente lotados: o
    // roteador oficial escolhe outro modelo free compatível. Mantemos no máximo
    // duas chaves distintas para não prolongar indefinidamente uma cota global.
    if ($rateLimited > 0 && getenv('AXIS_DISABLE_FREE_ROUTER') !== 'true') {
        $seenKeys = [];
        foreach ($agents as $agent) {
            $fingerprint = hash('sha256', $agent['apiKey']);
            if (isset($seenKeys[$fingerprint])) continue;
            $seenKeys[$fingerprint] = true;
            $remaining = $totalSeconds - (int)(microtime(true) - $startedAt);
            if ($remaining < 10) break;
            $routerAgent = ['model' => 'openrouter/free', 'apiKey' => $agent['apiKey']];
            $result = call_single_streamed($routerAgent, $payload, min(45, max(9, $remaining - 2)));
            if ($result['ok']) return $result;
            if ((int)($result['status'] ?? 0) === 429) $rateLimited++; else $otherFailures++;
            $errors[] = 'roteador gratuito: ' . $result['error'];
            if (count($seenKeys) >= 2) break;
        }
    }
    $details = $errors ? implode(' | ', array_slice($errors, 0, 3)) : 'tempo máximo da hospedagem atingido';
    if ($rateLimited > 0 && $otherFailures === 0) {
        return ['ok' => false, 'status' => 429, 'error' => 'O limite temporário dos modelos gratuitos do OpenRouter foi atingido. Aguarde a renovação da cota ou adicione créditos à conta OpenRouter. Chaves criadas na mesma conta compartilham o mesmo limite.'];
    }
    return ['ok' => false, 'error' => 'Nenhum modelo respondeu no modo compatível: ' . $details];
}

function chat(): void {
    if (!function_exists('curl_init')) respond(500, ['error' => 'A extensão cURL do PHP não está disponível.']);
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 4000000) respond(413, ['error' => 'Pedido grande demais.']);
    $body = json_decode($raw, true);
    if (!is_array($body) || !isset($body['messages']) || !is_array($body['messages'])) respond(400, ['error' => 'Mensagens inválidas.']);
    $body['messages'] = enforce_direct_answer_style($body['messages']);
    $agents = load_agents();
    if (!$agents) respond(503, ['error' => 'Configure providers.local.php com links e chaves OpenRouter.']);
    $latestRequest = latest_user_content($body['messages']);
    if (is_simple_greeting($latestRequest)) {
        respond(200, [
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Olá! Sou o Axis. Posso ajudar a criar, corrigir ou revisar seu código. O que vamos desenvolver hoje? [[CONVERSATION_TITLE:Boas-vindas ao Axis]]']]],
            'axis' => ['strategy' => 'instant-greeting', 'candidates' => 0, 'complexity' => 'light']
        ]);
    }
    $classification = classify_request($body['messages']);
    $sharedHosting = !supports_parallel_curl();
    $agents = order_agents_for_request($agents, $classification['complexity'], $sharedHosting);
    $selected = array_slice($agents, 0, min($classification['agents'], count($agents)));
    // Blocos maiores reduzem drasticamente a quantidade de continuações de um
    // projeto. O streaming preserva o que já foi recebido caso um provedor pare.
    $profile = $classification['complexity'] === 'heavy'
        ? [0.10, 8192, 'low', 155, 140]
        : ($classification['complexity'] === 'medium' ? [0.18, 5600, 'low', 95, 84] : [0.2, 2400, 'none', 60, 52]);
    $payload = [
        'messages' => $body['messages'],
        'temperature' => $profile[0],
        'max_tokens' => $profile[1],
        'reasoning' => ['effort' => $profile[2], 'exclude' => true],
        'provider' => ['sort' => 'throughput', 'allow_fallbacks' => true],
        'stream' => false
    ];

    // O InfinityFree remove curl_multi_exec. Nesse ambiente, uma tentativa longa e
    // estável funciona melhor que várias tentativas de 16 segundos que acabam em 502.
    // Para gerar projetos extensos, uma resposta principal contínua é mais
    // confiável que dividir o próprio código entre vários candidatos. As demais
    // chaves permanecem como failover automático, e o navegador continua a
    // resposta enquanto ela vier marcada como parcial.
    if ($classification['complexity'] === 'heavy' || (count($selected) > 1 && $sharedHosting)) {
        $result = call_first_available($agents, $payload, $profile[3], $profile[4]);
        if (!$result['ok']) respond(502, ['error' => $result['error']]);
        respond(200, [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $result['content']]]],
            'axis' => [
                'strategy' => $classification['complexity'] === 'heavy' ? 'long-project-continuation' : 'shared-hosting-fallback',
                'candidates' => 1,
                'requestedAgents' => $classification['agents'],
                'complexity' => $classification['complexity'],
                'partial' => !empty($result['partial'])
            ]
        ]);
    }

    if (count($selected) === 1) {
        $result = call_first_available($agents, $payload, $profile[3], $profile[4]);
        if (!$result['ok']) respond(502, ['error' => $result['error']]);
        respond(200, ['choices' => [['message' => ['role' => 'assistant', 'content' => $result['content']]]], 'axis' => ['strategy' => 'single', 'candidates' => 1, 'complexity' => $classification['complexity'], 'partial' => !empty($result['partial'])]]);
    }

    $candidates = call_parallel($selected, $payload);
    // Nem todo provedor/modelo gratuito aceita bem chamadas simultâneas.  Em vez
    // de encerrar o pedido médio/grande, faça a mesma tentativa sequencial e
    // com streaming que já é usada como modo compatível em hospedagens simples.
    // Assim, um agente indisponível não derruba o chat inteiro.
    if (!$candidates) {
        $fallback = call_first_available($agents, $payload, $profile[3], $profile[4]);
        if (!$fallback['ok']) respond(502, ['error' => $fallback['error']]);
        respond(200, [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $fallback['content']]]],
            'axis' => [
                'strategy' => 'parallel-fallback',
                'candidates' => 1,
                'requestedAgents' => $classification['agents'],
                'complexity' => $classification['complexity'],
                'partial' => !empty($fallback['partial'])
            ]
        ]);
    }
    if (count($candidates) === 1) respond(200, ['choices' => [['message' => ['role' => 'assistant', 'content' => $candidates[0]]]], 'axis' => ['strategy' => 'single-fallback', 'candidates' => 1, 'complexity' => $classification['complexity']]]);

    $latest = '';
    for ($i = count($body['messages']) - 1; $i >= 0; $i--) if (($body['messages'][$i]['role'] ?? '') === 'user') { $latest = (string)$body['messages'][$i]['content']; break; }
    $candidateText = '';
    foreach ($candidates as $index => $candidate) $candidateText .= "RESPOSTA " . ($index + 1) . ":\n" . $candidate . "\n\n---\n\n";
    $judgePayload = [
        'messages' => [
            ['role' => 'system', 'content' => 'Você é o revisor final do Axis Code. Consolide uma única resposta correta, segura, completa e proporcional à complexidade. Compare tecnicamente: não vote, não escolha a resposta mais longa, descarte APIs inventadas, redundância e incompatibilidades, resolva divergências por evidências e preserve os melhores pontos. Mantenha códigos, caminhos e uma única marca [[CONVERSATION_TITLE:...]] quando solicitada. Não mencione candidatos, agentes ou o processo interno. Responda diretamente: não repita, reformule ou use o pedido original como título ou introdução.'],
            ['role' => 'user', 'content' => "PEDIDO ORIGINAL:\n" . $latest . "\n\nCANDIDATOS:\n" . $candidateText]
        ],
        'temperature' => 0.1,
        'max_tokens' => $profile[1],
        'reasoning' => ['effort' => 'low', 'exclude' => true],
        'provider' => ['sort' => 'throughput', 'allow_fallbacks' => true],
        'stream' => false
    ];
    $synthesis = call_single($selected[count($selected) - 1], $judgePayload, 22);
    $final = $synthesis['ok'] ? $synthesis['content'] : $candidates[0];
    respond(200, ['choices' => [['message' => ['role' => 'assistant', 'content' => $final]]], 'axis' => ['strategy' => 'parallel-synthesis', 'candidates' => count($candidates), 'complexity' => $classification['complexity']]]);
}

$action = (string)($_GET['action'] ?? '');
if ($action === '') {
    respond(200, ['ok' => true, 'message' => 'Axis API PHP ativa.', 'next' => 'Use ?action=ai-status']);
}
if (in_array($action, ['ai-status', 'ai-chat', 'maven-run'], true) && empty($_SESSION['user_id'])) {
    respond(401, ['error' => 'Faça login para acessar o RafTech_EcoSystem.']);
}
if ($action === 'ai-status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $agents = load_agents();
    $health = check_agents_health($agents);
    respond(200, ['available' => $health['online'] > 0, 'agents' => count($agents), 'online' => $health['online'], 'synthesis' => $health['online'] > 1]);
}
if ($action === 'ai-chat' && $_SERVER['REQUEST_METHOD'] === 'POST') chat();
if ($action === 'maven-run') respond(501, ['error' => 'A hospedagem PHP não permite executar Maven.']);
respond(404, ['error' => 'Rota não encontrada.']);
