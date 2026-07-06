<?php
declare(strict_types=1);

$config = [
    'mail_to' => 'a.lashchukhin@l-group.su',
    'mail_from' => 'site@lider-vino.ru',
    'telegram_bot_token' => '',
    'telegram_chat_id' => '',
    'debug' => false,
    'anti_spam' => [
        'min_form_seconds' => 3,
        'rate_limit_window' => 600,
        'rate_limit_max' => 5,
        'max_links' => 2,
        'require_form_token' => true,
        'blocked_terms' => ['1xbet', '1хбет', 'casino', 'казино', 'ставки', 'букмекер', 'viagra', 'crypto', 'крипто', 'forex', 'loan', 'займ', 'кредит'],
    ],
    'smtp' => [
        'enabled' => false,
        'host' => '',
        'port' => 465,
        'secure' => 'ssl',
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => 'Сайт Лидер',
    ],
];

$configLoadWarning = '';
$customConfig = __DIR__ . '/config.php';
if (is_file($customConfig)) {
    ob_start();
    $loaded = require $customConfig;
    $configOutput = ob_get_clean();
    if (is_string($configOutput) && trim($configOutput) !== '') {
        $configLoadWarning = 'config.php вывел лишний текст. Проверьте, что файл начинается с <?php и только возвращает массив настроек.';
    }
    if (is_array($loaded)) {
        $config = array_replace_recursive($config, $loaded);
    } else {
        $configLoadWarning = trim($configLoadWarning . ' config.php не вернул массив настроек.');
    }
}

header('Content-Type: application/json; charset=utf-8');

function respond(bool $ok, string $message, array $details = [], int $status = 200): never
{
    global $config, $configLoadWarning;

    http_response_code($status);
    $payload = [
        'ok' => $ok,
        'message' => $message,
    ];
    if (!empty($config['debug'])) {
        if ($configLoadWarning !== '') {
            $details['config'] = $configLoadWarning;
        }
        $payload['details'] = $details;
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function clean_text(string $value): string
{
    $value = str_replace(["\r", "\n"], ' ', $value);
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function clean_body(string $value): string
{
    return trim(str_replace("\r", '', $value));
}

function text_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function text_contains(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return false;
    }

    return function_exists('mb_strpos')
        ? mb_strpos($haystack, $needle, 0, 'UTF-8') !== false
        : strpos($haystack, $needle) !== false;
}

function mime_header(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function count_links(string $value): int
{
    preg_match_all('~(?:https?://|www\.|t\.me/|@\w{4,}|(?:[a-z0-9-]+\.)+(?:ru|com|net|org|info|biz|online|site|xyz)\b)~iu', $value, $matches);
    return count($matches[0]);
}

function has_blocked_terms(string $value, array $terms): bool
{
    $value = text_lower($value);
    foreach ($terms as $term) {
        $term = text_lower(clean_text((string)$term));
        if (text_contains($value, $term)) {
            return true;
        }
    }

    return false;
}

function too_much_noise(string $value): bool
{
    $plain = preg_replace('/\s+/u', '', $value) ?? $value;
    $plainLength = text_length($plain);
    if ($plainLength < 12) {
        return false;
    }

    preg_match_all('/[^\p{L}\p{N}\s.,:;!?()"«»+\-\/]/u', $value, $matches);
    return count($matches[0]) > max(12, (int)floor($plainLength * 0.35));
}

function client_key(): string
{
    $ip = clean_text((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return hash('sha256', $ip . '|lider-vino');
}

function rate_limit_hit(int $windowSeconds, int $maxAttempts): bool
{
    if ($windowSeconds <= 0 || $maxAttempts <= 0) {
        return false;
    }

    $file = __DIR__ . '/.lead-rate-limit.json';
    $now = time();
    $key = client_key();
    $handle = @fopen($file, 'c+');
    if (!$handle) {
        return false;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return false;
        }

        $raw = stream_get_contents($handle);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($data)) {
            $data = [];
        }

        foreach ($data as $savedKey => $hits) {
            if (!is_array($hits)) {
                unset($data[$savedKey]);
                continue;
            }
            $data[$savedKey] = array_values(array_filter($hits, static fn($hit) => is_int($hit) && $hit > $now - $windowSeconds));
            if (!$data[$savedKey]) {
                unset($data[$savedKey]);
            }
        }

        $hits = $data[$key] ?? [];
        if (count($hits) >= $maxAttempts) {
            return true;
        }

        $hits[] = $now;
        $data[$key] = $hits;
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($data, JSON_UNESCAPED_UNICODE));

        return false;
    } finally {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function smtp_read($socket): array
{
    $message = '';
    $code = 0;

    while (($line = fgets($socket, 515)) !== false) {
        $message .= $line;
        if (preg_match('/^(\d{3})(\s|-)/', $line, $matches)) {
            $code = (int)$matches[1];
            if ($matches[2] === ' ') {
                break;
            }
        }
    }

    return [$code, trim($message)];
}

function smtp_command($socket, string $command, array $expectedCodes): array
{
    fwrite($socket, $command . "\r\n");
    [$code, $message] = smtp_read($socket);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP command failed: ' . $command . ' / ' . $message);
    }

    return [$code, $message];
}

function smtp_send(array $smtp, string $to, string $subject, string $body, string $replyTo = ''): array
{
    if (empty($smtp['enabled'])) {
        return ['ok' => null, 'message' => 'SMTP выключен в config.php.'];
    }

    $host = clean_text((string)($smtp['host'] ?? ''));
    $port = (int)($smtp['port'] ?? 465);
    $secure = clean_text((string)($smtp['secure'] ?? 'ssl'));
    $username = clean_text((string)($smtp['username'] ?? ''));
    $password = (string)($smtp['password'] ?? '');
    $fromEmail = clean_text((string)($smtp['from_email'] ?? $username));
    $fromName = clean_text((string)($smtp['from_name'] ?? 'Сайт Лидер'));
    $to = clean_text($to);

    if ($host === '' || $username === '' || $password === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'SMTP настроен не полностью или указан некорректный e-mail.'];
    }

    $target = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $socket = @stream_socket_client($target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        return ['ok' => false, 'message' => 'SMTP connect error: ' . $errstr . ' (' . $errno . ')'];
    }

    stream_set_timeout($socket, 15);

    try {
        [$code, $message] = smtp_read($socket);
        if ($code !== 220) {
            throw new RuntimeException('SMTP greeting failed: ' . $message);
        }

        $domain = clean_text((string)($_SERVER['HTTP_HOST'] ?? 'lider-vino.ru'));
        smtp_command($socket, 'EHLO ' . $domain, [250]);

        if ($secure === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP STARTTLS crypto failed.');
            }
            smtp_command($socket, 'EHLO ' . $domain, [250]);
        }

        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($username), [334]);
        smtp_command($socket, base64_encode($password), [235]);
        smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $headers = [
            'Date: ' . date('r'),
            'From: ' . mime_header($fromName) . ' <' . $fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: ' . mime_header($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        $message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace(["\r\n", "\r"], "\n", $body);
        $message = str_replace("\n", "\r\n", $message);
        $message = preg_replace('/^\./m', '..', $message) ?? $message;

        fwrite($socket, $message . "\r\n.\r\n");
        [$dataCode, $dataMessage] = smtp_read($socket);
        if ($dataCode !== 250) {
            throw new RuntimeException('SMTP DATA failed: ' . $dataMessage);
        }

        smtp_command($socket, 'QUIT', [221]);
        fclose($socket);

        return ['ok' => true, 'message' => 'Письмо отправлено через SMTP.'];
    } catch (Throwable $error) {
        if (is_resource($socket)) {
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
        }

        return ['ok' => false, 'message' => $error->getMessage()];
    }
}

function send_telegram(string $token, string $chatId, string $text): array
{
    if ($token === '' || $chatId === '') {
        return ['ok' => null, 'message' => 'Telegram не настроен: нет token/chat_id.'];
    }

    $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage';
    $payload = http_build_query([
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => '1',
    ]);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'message' => 'Telegram curl error: ' . $error];
        }

        return ['ok' => $code >= 200 && $code < 300, 'message' => 'Telegram HTTP ' . $code . ': ' . $body];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 12,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return ['ok' => false, 'message' => 'Telegram file_get_contents error.'];
    }

    return ['ok' => true, 'message' => $body];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Метод не поддерживается.', [], 405);
}

if (clean_text((string)($_POST['company'] ?? '')) !== '' || clean_text((string)($_POST['website'] ?? '')) !== '') {
    respond(true, 'Заявка отправлена.');
}

$name = clean_text((string)($_POST['name'] ?? ''));
$phone = clean_text((string)($_POST['phone'] ?? ''));
$email = clean_text((string)($_POST['email'] ?? ''));
$message = clean_body((string)($_POST['message'] ?? ''));
$source = clean_text((string)($_POST['source'] ?? 'leader-vino-site'));
$agree = clean_text((string)($_POST['agree'] ?? ''));
$elapsed = (int)clean_text((string)($_POST['form_elapsed'] ?? '0'));
$formToken = clean_text((string)($_POST['form_token'] ?? ''));

if ($name === '' || $phone === '' || $message === '' || $agree !== 'yes') {
    respond(false, 'Заполните обязательные поля.', [], 422);
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Проверьте e-mail.', [], 422);
}

$antiSpam = is_array($config['anti_spam'] ?? null) ? $config['anti_spam'] : [];
$minSeconds = (int)($antiSpam['min_form_seconds'] ?? 3);
$maxLinks = (int)($antiSpam['max_links'] ?? 2);
$requireFormToken = (bool)($antiSpam['require_form_token'] ?? true);
$blockedTerms = is_array($antiSpam['blocked_terms'] ?? null) ? $antiSpam['blocked_terms'] : [];
$combinedText = implode("\n", [$name, $phone, $email, $message, $source]);

if (($requireFormToken && !preg_match('/^lv-[a-z0-9]+-[a-z0-9]{6,}$/i', $formToken))
    || ($minSeconds > 0 && $elapsed < $minSeconds)
    || count_links($combinedText) > $maxLinks
    || has_blocked_terms($combinedText, $blockedTerms)
    || too_much_noise($combinedText)
    || text_length($name) > 80
    || text_length($phone) > 40
    || text_length($email) > 120
    || text_length($message) > 2200
) {
    respond(true, 'Заявка отправлена.');
}

if (rate_limit_hit((int)($antiSpam['rate_limit_window'] ?? 600), (int)($antiSpam['rate_limit_max'] ?? 5))) {
    respond(false, 'Слишком много заявок подряд. Попробуйте чуть позже.', [], 429);
}

$ip = clean_text((string)($_SERVER['REMOTE_ADDR'] ?? 'не определён'));
$date = date('d.m.Y H:i:s');

$plainText = implode("\n", [
    'Новая заявка с сайта lider-vino.ru',
    '',
    'Имя: ' . $name,
    'Телефон: ' . $phone,
    'E-mail: ' . ($email !== '' ? $email : 'не указан'),
    'Источник: ' . $source,
    '',
    'Что нужно привезти:',
    $message,
    '',
    'Дата: ' . $date,
    'IP: ' . $ip,
]);

@file_put_contents(__DIR__ . '/leads.log', "\n---\n" . $plainText . "\n", FILE_APPEND | LOCK_EX);

$subjectText = 'Новая заявка с сайта Лидер';
$subject = mime_header($subjectText);
$fromName = mime_header('Сайт Лидер');
$headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'From: ' . $fromName . ' <' . clean_text((string)$config['mail_from']) . '>',
];
if ($email !== '') {
    $headers[] = 'Reply-To: ' . $email;
}

$smtpResult = smtp_send(
    is_array($config['smtp'] ?? null) ? $config['smtp'] : [],
    clean_text((string)$config['mail_to']),
    $subjectText,
    $plainText,
    $email
);

$mailOk = false;
$mailMessage = $smtpResult['message'];

if ($smtpResult['ok'] === true) {
    $mailOk = true;
} elseif ($smtpResult['ok'] === null) {
    $mailOk = @mail(
        clean_text((string)$config['mail_to']),
        $subject,
        $plainText,
        implode("\r\n", $headers)
    );
    $mailMessage = $mailOk ? 'Письмо отправлено через mail().' : 'mail() вернул ошибку.';
}

$telegramText = '<b>Новая заявка с lider-vino.ru</b>' . "\n\n"
    . '<b>Имя:</b> ' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n"
    . '<b>Телефон:</b> ' . htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n"
    . '<b>E-mail:</b> ' . htmlspecialchars($email !== '' ? $email : 'не указан', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n"
    . '<b>Источник:</b> ' . htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n\n"
    . '<b>Что нужно привезти:</b>' . "\n" . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n\n"
    . '<b>Дата:</b> ' . htmlspecialchars($date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$telegramResult = send_telegram(
    clean_text((string)$config['telegram_bot_token']),
    clean_text((string)$config['telegram_chat_id']),
    $telegramText
);

$details = [
    'email' => $mailMessage,
    'telegram' => $telegramResult['message'],
    'log' => 'Запись добавлена в leads.log',
];

if ($mailOk || $telegramResult['ok'] === true) {
    respond(true, 'Заявка отправлена.', $details);
}

respond(false, 'Не удалось отправить заявку. Попробуйте позже.', $details, 500);
