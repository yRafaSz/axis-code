<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$result = [
    'php' => PHP_VERSION,
    'configExists' => is_file(__DIR__ . '/providers.local.php') || trim((string)(getenv('AXIS_AGENTS_JSON') ?: '')) !== '',
    'configSource' => trim((string)(getenv('AXIS_AGENTS_JSON') ?: '')) !== '' ? 'environment' : 'file',
    'curl' => function_exists('curl_init') && function_exists('curl_exec'),
    'curlMulti' => function_exists('curl_multi_init') && function_exists('curl_multi_exec'),
    'pdoMysql' => extension_loaded('pdo_mysql'),
    'authenticationConfigured' => trim((string)(getenv('DB_HOST') ?: '')) !== '' && trim((string)(getenv('DB_NAME') ?: '')) !== '' && trim((string)(getenv('DB_USER') ?: '')) !== '',
    'recaptchaConfigured' => trim((string)(getenv('RECAPTCHA_SITE_KEY') ?: '')) !== '' && trim((string)(getenv('RECAPTCHA_SECRET_KEY') ?: '')) !== '',
    'databaseSslCa' => trim((string)(getenv('DB_SSL_CA') ?: '')) !== '' ? 'custom' : 'automatic',
];

if ($result['configExists'] && $result['configSource'] === 'file') {
    try {
        $config = require __DIR__ . '/providers.local.php';
        $agents = is_array($config) && isset($config['agents']) && is_array($config['agents']) ? $config['agents'] : [];
        $result['configValid'] = is_array($config);
        $result['agentsDeclared'] = count($agents);
        $result['agentFields'] = array_map(function ($agent) {
            return [
                'hasModelLink' => is_array($agent) && isset($agent['modelLink']) && trim((string)$agent['modelLink']) !== '',
                'hasApiKey' => is_array($agent) && isset($agent['apiKey']) && trim((string)$agent['apiKey']) !== '',
            ];
        }, $agents);
    } catch (Throwable $error) {
        $result['configValid'] = false;
        $result['configError'] = $error->getMessage();
        $result['configErrorLine'] = $error->getLine();
    }
}

if ($result['configExists'] && $result['configSource'] === 'environment') {
    $config = json_decode((string)getenv('AXIS_AGENTS_JSON'), true);
    $agents = is_array($config) && isset($config['agents']) && is_array($config['agents']) ? $config['agents'] : [];
    $result['configValid'] = is_array($config);
    $result['agentsDeclared'] = count($agents);
    $result['agentFields'] = array_map(function ($agent) {
        return [
            'hasModelLink' => is_array($agent) && isset($agent['modelLink']) && trim((string)$agent['modelLink']) !== '',
            'hasApiKey' => is_array($agent) && isset($agent['apiKey']) && trim((string)$agent['apiKey']) !== '',
        ];
    }, $agents);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
