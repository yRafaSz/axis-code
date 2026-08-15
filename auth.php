<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || getenv('RENDER') === 'true';
}

session_name('axis_session');
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path' => '/',
    'secure' => is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function json_response(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(function (Throwable $error): void {
    error_log('Axis authentication error: ' . $error->getMessage());
    json_response(500, ['error' => 'O serviço de autenticação encontrou um erro interno.']);
});

function env_value(string $name, string $fallback = ''): string {
    $value = getenv($name);
    return $value === false ? $fallback : trim((string)$value);
}

function text_length(string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function text_slice(string $value, int $length): string {
    return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
}

function auth_configured(): bool {
    return env_value('DB_HOST') !== ''
        && env_value('DB_NAME') !== ''
        && env_value('DB_USER') !== '';
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!auth_configured()) json_response(503, ['error' => 'Banco MySQL ainda não configurado no Render.', 'code' => 'DB_NOT_CONFIGURED']);

    $host = env_value('DB_HOST');
    $port = env_value('DB_PORT', '3306');
    $name = env_value('DB_NAME');
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $sslCa = env_value('DB_SSL_CA');
    if ($sslCa !== '' && defined('PDO::MYSQL_ATTR_SSL_CA')) $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;

    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            env_value('DB_USER'),
            env_value('DB_PASSWORD'),
            $options
        );
        ensure_schema($pdo);
        return $pdo;
    } catch (PDOException $error) {
        error_log('Axis database connection failed: ' . $error->getMessage());
        json_response(503, ['error' => 'Não foi possível conectar ao MySQL. Verifique as variáveis DB_* no Render.', 'code' => 'DB_CONNECTION_FAILED']);
    }
}

function ensure_schema(PDO $pdo): void {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS axis_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(320) NOT NULL,
    password_hash VARCHAR(255) NULL,
    google_sub VARCHAR(255) NULL,
    display_name VARCHAR(80) NOT NULL,
    avatar_mime VARCHAR(50) NULL,
    avatar_data MEDIUMBLOB NULL,
    avatar_external_url VARCHAR(2048) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_axis_users_email (email),
    UNIQUE KEY uq_axis_users_google_sub (google_sub)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL);
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return (string)$_SESSION['csrf'];
}

function require_csrf(array $body = []): void {
    $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['csrf'] ?? ''));
    if ($provided === '' || !hash_equals(csrf_token(), $provided)) {
        json_response(419, ['error' => 'Sessão expirada. Atualize a página e tente novamente.']);
    }
}

function request_body(): array {
    $raw = file_get_contents('php://input');
    $body = json_decode((string)$raw, true);
    return is_array($body) ? $body : [];
}

function current_user_id(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function require_user(): int {
    $id = current_user_id();
    if ($id < 1) json_response(401, ['error' => 'Faça login para continuar.']);
    return $id;
}

function avatar_url(array $user): string {
    if (!empty($user['has_avatar'])) return 'auth.php?action=avatar&id=' . (int)$user['id'] . '&v=' . urlencode((string)($user['updated_at'] ?? time()));
    return (string)($user['avatar_external_url'] ?? '');
}

function user_payload(int $id): ?array {
    $statement = db()->prepare('SELECT id, email, display_name, avatar_external_url, avatar_data IS NOT NULL AS has_avatar, password_hash IS NOT NULL AS has_password, updated_at FROM axis_users WHERE id = ? LIMIT 1');
    $statement->execute([$id]);
    $user = $statement->fetch();
    if (!$user) return null;
    return [
        'id' => (int)$user['id'],
        'email' => (string)$user['email'],
        'displayName' => (string)$user['display_name'],
        'avatarUrl' => avatar_url($user),
        'hasPassword' => (bool)$user['has_password'],
    ];
}

function rate_limit(string $bucket, int $max = 12, int $window = 60): void {
    $now = time();
    $record = $_SESSION['rate_limits'][$bucket] ?? ['start' => $now, 'count' => 0];
    if ($now - (int)$record['start'] >= $window) $record = ['start' => $now, 'count' => 0];
    $record['count']++;
    $_SESSION['rate_limits'][$bucket] = $record;
    if ($record['count'] > $max) json_response(429, ['error' => 'Muitas tentativas. Aguarde um minuto e tente novamente.']);
}

function verify_recaptcha(string $token): void {
    $secret = env_value('RECAPTCHA_SECRET_KEY');
    if ($secret === '') return;
    if ($token === '') json_response(422, ['error' => 'Confirme o reCAPTCHA.']);
    $handle = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => http_build_query([
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]),
    ]);
    $raw = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    $result = json_decode(is_string($raw) ? $raw : '', true);
    if ($status !== 200 || empty($result['success'])) json_response(422, ['error' => 'Não foi possível validar o reCAPTCHA. Tente novamente.']);
}

function login_user(int $id): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $id;
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

function app_url(): string {
    $renderUrl = rtrim(env_value('RENDER_EXTERNAL_URL'), '/');
    if ($renderUrl !== '') return $renderUrl;
    $scheme = is_https() ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function google_redirect_uri(): string {
    return env_value('GOOGLE_REDIRECT_URI', app_url() . '/auth.php?action=google-callback');
}

function curl_form(string $url, array $fields): array {
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query($fields),
    ]);
    $raw = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    return [$status, json_decode(is_string($raw) ? $raw : '', true)];
}

function google_callback(): never {
    $state = (string)($_GET['state'] ?? '');
    $expected = (string)($_SESSION['google_oauth_state'] ?? '');
    unset($_SESSION['google_oauth_state']);
    if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
        header('Location: ' . app_url() . '/?auth_error=state');
        exit;
    }
    $code = (string)($_GET['code'] ?? '');
    if ($code === '') {
        header('Location: ' . app_url() . '/?auth_error=google');
        exit;
    }
    [$status, $token] = curl_form('https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => env_value('GOOGLE_CLIENT_ID'),
        'client_secret' => env_value('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => google_redirect_uri(),
        'grant_type' => 'authorization_code',
    ]);
    if ($status !== 200 || empty($token['access_token'])) {
        header('Location: ' . app_url() . '/?auth_error=token');
        exit;
    }
    $handle = curl_init('https://openidconnect.googleapis.com/v1/userinfo');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token['access_token']],
    ]);
    $raw = curl_exec($handle);
    $userStatus = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    $google = json_decode(is_string($raw) ? $raw : '', true);
    $email = strtolower(trim((string)($google['email'] ?? '')));
    $sub = trim((string)($google['sub'] ?? ''));
    if ($userStatus !== 200 || $sub === '' || empty($google['email_verified']) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header('Location: ' . app_url() . '/?auth_error=profile');
        exit;
    }

    $pdo = db();
    $statement = $pdo->prepare('SELECT id, google_sub FROM axis_users WHERE email = ? OR google_sub = ? LIMIT 1');
    $statement->execute([$email, $sub]);
    $existing = $statement->fetch();
    if ($existing) {
        if (!empty($existing['google_sub']) && !hash_equals((string)$existing['google_sub'], $sub)) {
            header('Location: ' . app_url() . '/?auth_error=account');
            exit;
        }
        $update = $pdo->prepare("UPDATE axis_users SET google_sub = ?, avatar_external_url = COALESCE(NULLIF(avatar_external_url, ''), ?) WHERE id = ?");
        $update->execute([$sub, (string)($google['picture'] ?? ''), (int)$existing['id']]);
        $id = (int)$existing['id'];
    } else {
        $name = trim((string)($google['name'] ?? strtok($email, '@')));
        $insert = $pdo->prepare('INSERT INTO axis_users (email, google_sub, display_name, avatar_external_url) VALUES (?, ?, ?, ?)');
        $insert->execute([$email, $sub, text_slice($name ?: 'Usuário Axis', 80), (string)($google['picture'] ?? '')]);
        $id = (int)$pdo->lastInsertId();
    }
    login_user($id);
    header('Location: ' . app_url() . '/?auth=google');
    exit;
}

$action = (string)($_GET['action'] ?? 'config');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($action === 'google-callback') google_callback();

if ($action === 'avatar' && $method === 'GET') {
    $id = max(0, (int)($_GET['id'] ?? 0));
    $statement = db()->prepare('SELECT avatar_mime, avatar_data FROM axis_users WHERE id = ? AND avatar_data IS NOT NULL LIMIT 1');
    $statement->execute([$id]);
    $avatar = $statement->fetch();
    if (!$avatar) { http_response_code(404); exit; }
    header('Content-Type: ' . $avatar['avatar_mime']);
    header('Cache-Control: public, max-age=86400');
    echo $avatar['avatar_data'];
    exit;
}

if ($action === 'config' && $method === 'GET') {
    json_response(200, [
        'configured' => auth_configured(),
        'csrf' => csrf_token(),
        'recaptchaSiteKey' => env_value('RECAPTCHA_SITE_KEY'),
        'googleEnabled' => env_value('GOOGLE_CLIENT_ID') !== '' && env_value('GOOGLE_CLIENT_SECRET') !== '',
    ]);
}

if ($action === 'me' && $method === 'GET') {
    $id = current_user_id();
    $user = $id ? user_payload($id) : null;
    if ($id && !$user) { session_destroy(); $id = 0; }
    json_response(200, ['authenticated' => (bool)$user, 'user' => $user, 'csrf' => csrf_token()]);
}

if ($action === 'register' && $method === 'POST') {
    rate_limit('register', 8);
    $body = request_body();
    require_csrf($body);
    verify_recaptcha(trim((string)($body['recaptchaToken'] ?? '')));
    $name = trim((string)($body['displayName'] ?? ''));
    $email = strtolower(trim((string)($body['email'] ?? '')));
    $password = (string)($body['password'] ?? '');
    if (text_length($name) < 2 || text_length($name) > 80) json_response(422, ['error' => 'O nome deve ter entre 2 e 80 caracteres.']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 320) json_response(422, ['error' => 'Informe um e-mail válido.']);
    if (strlen($password) < 10 || strlen($password) > 128) json_response(422, ['error' => 'A senha deve ter entre 10 e 128 caracteres.']);
    try {
        $statement = db()->prepare('INSERT INTO axis_users (email, password_hash, display_name) VALUES (?, ?, ?)');
        $statement->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name]);
        login_user((int)db()->lastInsertId());
        json_response(201, ['ok' => true, 'user' => user_payload(current_user_id()), 'csrf' => csrf_token()]);
    } catch (PDOException $error) {
        if ((string)$error->getCode() === '23000') json_response(409, ['error' => 'Já existe uma conta com este e-mail.']);
        throw $error;
    }
}

if ($action === 'login' && $method === 'POST') {
    rate_limit('login', 12);
    $body = request_body();
    require_csrf($body);
    verify_recaptcha(trim((string)($body['recaptchaToken'] ?? '')));
    $email = strtolower(trim((string)($body['email'] ?? '')));
    $password = (string)($body['password'] ?? '');
    $statement = db()->prepare('SELECT id, password_hash FROM axis_users WHERE email = ? LIMIT 1');
    $statement->execute([$email]);
    $user = $statement->fetch();
    if (!$user || empty($user['password_hash']) || !password_verify($password, (string)$user['password_hash'])) {
        json_response(401, ['error' => 'E-mail ou senha inválidos.']);
    }
    if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
        $update = db()->prepare('UPDATE axis_users SET password_hash = ? WHERE id = ?');
        $update->execute([password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]);
    }
    login_user((int)$user['id']);
    json_response(200, ['ok' => true, 'user' => user_payload(current_user_id()), 'csrf' => csrf_token()]);
}

if ($action === 'google-start' && $method === 'GET') {
    if (env_value('GOOGLE_CLIENT_ID') === '' || env_value('GOOGLE_CLIENT_SECRET') === '') {
        json_response(503, ['error' => 'Login com Google ainda não configurado.']);
    }
    $state = bin2hex(random_bytes(24));
    $_SESSION['google_oauth_state'] = $state;
    $query = http_build_query([
        'client_id' => env_value('GOOGLE_CLIENT_ID'),
        'redirect_uri' => google_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid profile email',
        'state' => $state,
        'prompt' => 'select_account',
    ]);
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $query);
    exit;
}

if ($action === 'logout' && $method === 'POST') {
    $body = request_body();
    require_csrf($body);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    json_response(200, ['ok' => true]);
}

if ($action === 'profile' && $method === 'POST') {
    $id = require_user();
    $body = request_body();
    require_csrf($body);
    $name = trim((string)($body['displayName'] ?? ''));
    if (text_length($name) < 2 || text_length($name) > 80) json_response(422, ['error' => 'O nome deve ter entre 2 e 80 caracteres.']);
    $currentPassword = (string)($body['currentPassword'] ?? '');
    $newPassword = (string)($body['newPassword'] ?? '');
    $statement = db()->prepare('SELECT password_hash FROM axis_users WHERE id = ? LIMIT 1');
    $statement->execute([$id]);
    $account = $statement->fetch();
    if (!$account) json_response(404, ['error' => 'Conta não encontrada.']);
    if ($newPassword !== '') {
        if (strlen($newPassword) < 10 || strlen($newPassword) > 128) json_response(422, ['error' => 'A nova senha deve ter entre 10 e 128 caracteres.']);
        if (!empty($account['password_hash']) && !password_verify($currentPassword, (string)$account['password_hash'])) {
            json_response(422, ['error' => 'A senha atual está incorreta.']);
        }
        $update = db()->prepare('UPDATE axis_users SET display_name = ?, password_hash = ? WHERE id = ?');
        $update->execute([$name, password_hash($newPassword, PASSWORD_DEFAULT), $id]);
    } else {
        $update = db()->prepare('UPDATE axis_users SET display_name = ? WHERE id = ?');
        $update->execute([$name, $id]);
    }
    json_response(200, ['ok' => true, 'user' => user_payload($id)]);
}

if ($action === 'avatar' && $method === 'POST') {
    $id = require_user();
    require_csrf($_POST);
    if (empty($_FILES['avatar']) || !is_uploaded_file($_FILES['avatar']['tmp_name'])) json_response(422, ['error' => 'Selecione uma imagem.']);
    $upload = $_FILES['avatar'];
    if ((int)$upload['size'] < 1 || (int)$upload['size'] > 2_000_000) json_response(422, ['error' => 'A imagem deve ter no máximo 2 MB.']);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) json_response(422, ['error' => 'Use uma imagem JPG, PNG, WEBP ou GIF.']);
    $data = file_get_contents($upload['tmp_name']);
    $statement = db()->prepare('UPDATE axis_users SET avatar_mime = ?, avatar_data = ? WHERE id = ?');
    $statement->bindValue(1, $mime);
    $statement->bindValue(2, $data, PDO::PARAM_LOB);
    $statement->bindValue(3, $id, PDO::PARAM_INT);
    $statement->execute();
    json_response(200, ['ok' => true, 'user' => user_payload($id)]);
}

json_response(404, ['error' => 'Rota de autenticação não encontrada.']);
