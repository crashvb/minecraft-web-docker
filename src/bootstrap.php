<?php

declare(strict_types=1);

const PERMISSIONS = ['', 'visitor', 'member', 'operator'];
const STUN_CACHE_TTL = 3600;

function env_required(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException("Missing environment variable: {$name}");
    }
    return $value;
}

function authenticate_user(string $username, string $password): bool
{
    if ($username === '' || $password === '') {
        return false;
    }

    $path = env_required('MINECRAFT_CONFIG') . '/.htpasswd';
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException("Unable to read {$path}");
    }
    foreach ($lines as $line) {
        [$candidate, $hash] = array_pad(explode(':', $line, 2), 2, '');
        if (hash_equals($candidate, $username)) {
            return password_verify($password, $hash);
        }
    }
    return false;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $parameters = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $parameters['path'],
            'domain' => $parameters['domain'],
            'secure' => $parameters['secure'],
            'httponly' => $parameters['httponly'],
            'samesite' => $parameters['samesite'] ?? 'Strict',
        ]);
    }
    session_destroy();
}

function servers(): array
{
    static $servers;
    if ($servers === null) {
        $servers = [];
        foreach (glob(env_required('MINECRAFT_SHARE') . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            $server = basename($path);
            if (preg_match('/^[a-z0-9-]+$/', $server)) {
                $servers[] = $server;
            }
        }
        sort($servers, SORT_NATURAL | SORT_FLAG_CASE);
        if ($servers === []) {
            throw new RuntimeException('No Minecraft configuration volumes found.');
        }
    }
    return $servers;
}

function config_path(string $server, string $file): string
{
    if (!in_array($server, servers(), true)) {
        throw new InvalidArgumentException('Unknown server.');
    }
    return env_required('MINECRAFT_SHARE') . "/{$server}/{$file}";
}

function data_path(string $server, string $file): string
{
    if (!in_array($server, servers(), true)) {
        throw new InvalidArgumentException('Unknown server.');
    }
    return env_required('MINECRAFT_DATA') . "/{$server}/{$file}";
}

function backup_path(string $server): string
{
    if (!in_array($server, servers(), true)) {
        throw new InvalidArgumentException('Unknown server.');
    }
    return env_required('MINECRAFT_BACKUP') . "/{$server}";
}

function read_json(string $path): array
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$path}");
    }
    $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) {
        throw new RuntimeException("Expected an array in {$path}");
    }
    return $value;
}

function sort_json_keys(array $value): array
{
    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $value[$key] = sort_json_keys($item);
        }
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }
    return $value;
}

function backup_file(string $path, string $directory): void
{
    if (!is_dir($directory)) {
        throw new RuntimeException("Missing backup directory: {$directory}");
    }

    $backup = $directory . '/' . basename($path) . '.' . date('Y-m-d_H:i:s');
    $pipes = [];
    $process = @proc_open(
        ['/usr/bin/cp', '--preserve=all', '--', $path, $backup],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException("Unable to create backup: {$backup}");
    }

    stream_get_contents($pipes[1]);
    $error = trim(stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException("Unable to create backup: {$backup}" . ($error === '' ? '' : ": {$error}"));
    }
}

function write_json_atomic(string $path, array $value, ?string $backupDirectory = null): bool
{
    $directory = dirname($path);
    $temporary = tempnam($directory, '.minecraft-web.');
    if ($temporary === false) {
        throw new RuntimeException("Unable to create a temporary file in {$directory}");
    }

    try {
        $json = json_encode(sort_json_keys($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (@file_get_contents($path) === $json) {
            return false;
        }
        if ($backupDirectory !== null && is_file($path)) {
            backup_file($path, $backupDirectory);
        }
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write {$temporary}");
        }
        chmod($temporary, file_exists($path) ? (fileperms($path) & 0777) : 0660);
        if (!rename($temporary, $path)) {
            throw new RuntimeException("Unable to replace {$path}");
        }
        return true;
    } finally {
        if (file_exists($temporary)) {
            unlink($temporary);
        }
    }
}

function load_xuids(): array
{
    $path = env_required('MINECRAFT_CONFIG') . '/xuid.yml';
    if (!is_file($path)) {
        throw new RuntimeException("Missing {$path}");
    }

    $result = [];
    if (function_exists('yaml_parse_file')) {
        $yaml = yaml_parse_file($path);
        foreach (($yaml['gamertag_xuid'] ?? []) as $mapping) {
            if (is_array($mapping)) {
                foreach ($mapping as $gamertag => $xuid) {
                    $result[(string) $gamertag] = (string) $xuid;
                }
            }
        }
    } else {
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*-\s+([^:#][^:]*):\s*["\']?([0-9]+)["\']?\s*$/', $line, $matches)) {
                $result[trim($matches[1], " \t\"'")] = $matches[2];
            }
        }
    }

    if ($result === []) {
        throw new RuntimeException("No XUID mappings found in {$path}");
    }
    uksort($result, 'strnatcasecmp');
    return $result;
}

function add_xuid(string $gamertag, string $xuid): void
{
    $gamertag = trim($gamertag);
    $xuid = trim($xuid);
    if ($gamertag === '' || preg_match('/[\x00-\x1f\x7f:]/u', $gamertag)) {
        throw new InvalidArgumentException('Invalid gamer tag.');
    }
    if (!preg_match('/^[0-9]+$/', $xuid)) {
        throw new InvalidArgumentException('XUID must contain only digits.');
    }

    $lock = fopen(env_required('MINECRAFT_LOCKS') . '/xuid.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Unable to lock xuid.yml.');
    }

    try {
        $mappings = load_xuids();
        foreach ($mappings as $existingGamertag => $existingXuid) {
            if (strcasecmp($existingGamertag, $gamertag) === 0) {
                throw new InvalidArgumentException("Gamer tag {$gamertag} already exists.");
            }
            if ($existingXuid === $xuid) {
                throw new InvalidArgumentException("XUID {$xuid} already belongs to {$existingGamertag}.");
            }
        }
        $mappings[$gamertag] = $xuid;
        uksort($mappings, 'strnatcasecmp');

        $path = env_required('MINECRAFT_CONFIG') . '/xuid.yml';
        $document = @file_get_contents($path);
        if ($document === false || !preg_match('/\A(.*?^gamertag_xuid:\h*$)/ms', $document, $matches)) {
            throw new RuntimeException("Unable to locate gamertag_xuid in {$path}");
        }

        $yaml = rtrim($matches[1]) . "\n";
        foreach ($mappings as $name => $mappedXuid) {
            $yamlName = "'" . str_replace("'", "''", $name) . "'";
            $yaml .= "- {$yamlName}: {$mappedXuid}\n";
        }

        backup_file($path, env_required('MINECRAFT_BACKUP'));
        if (file_put_contents($path, $yaml, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write {$path}");
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function load_server(string $server): array
{
    return [
        'allowlist' => read_json(config_path($server, 'allowlist.json')),
        'permissions' => read_json(config_path($server, 'permissions.json')),
        'version' => trim((string) @file_get_contents(data_path($server, 'version'))),
    ];
}

function player_state(array $server, string $gamertag, string $xuid): array
{
    $allowed = false;
    $ignoreLimit = false;
    foreach ($server['allowlist'] as $entry) {
        if (isset($entry['name']) && strcasecmp((string) $entry['name'], $gamertag) === 0) {
            $allowed = true;
            $ignoreLimit = (bool) ($entry['ignoresPlayerLimit'] ?? false);
            break;
        }
    }

    $permission = '';
    foreach ($server['permissions'] as $entry) {
        if ((string) ($entry['xuid'] ?? '') === $xuid) {
            $permission = (string) ($entry['permission'] ?? '');
            break;
        }
    }
    return [$allowed, $ignoreLimit, $permission];
}

function update_player(string $server, string $gamertag, string $xuid, bool $allowed, bool $ignoreLimit, string $permission): void
{
    if (!$allowed) {
        $ignoreLimit = false;
        $permission = '';
    }
    if (!in_array($permission, PERMISSIONS, true)) {
        throw new InvalidArgumentException('Invalid permission.');
    }

    $lockPath = env_required('MINECRAFT_LOCKS') . "/{$server}.lock";
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException("Unable to lock {$server}");
    }

    try {
        $allowPath = config_path($server, 'allowlist.json');
        $permissionPath = config_path($server, 'permissions.json');
        $allowlist = read_json($allowPath);
        $permissions = read_json($permissionPath);

        $existing = null;
        $allowlist = array_values(array_filter($allowlist, static function ($entry) use ($gamertag, &$existing): bool {
            if (isset($entry['name']) && strcasecmp((string) $entry['name'], $gamertag) === 0) {
                $existing ??= $entry;
                return false;
            }
            return true;
        }));
        if ($allowed) {
            $entry = is_array($existing) ? $existing : [];
            $entry['name'] = $gamertag;
            $entry['ignoresPlayerLimit'] = $ignoreLimit;
            $allowlist[] = $entry;
        }

        $permissions = array_values(array_filter(
            $permissions,
            static fn($entry): bool => (string) ($entry['xuid'] ?? '') !== $xuid
        ));
        if ($permission !== '') {
            $permissions[] = ['permission' => $permission, 'xuid' => $xuid];
        }

        $backupPath = backup_path($server);
        write_json_atomic($allowPath, $allowlist, $backupPath);
        write_json_atomic($permissionPath, $permissions, $backupPath);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function read_properties(string $path): array
{
    $properties = [];
    foreach (@file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $properties[trim($key)][] = trim($value);
    }
    return $properties;
}

function orphaned_entries(array $server, array $xuids): array
{
    $knownNames = array_map('strtolower', array_keys($xuids));
    $knownXuids = array_values($xuids);
    $orphans = [];
    foreach ($server['allowlist'] as $entry) {
        $name = (string) ($entry['name'] ?? '');
        if ($name !== '' && !in_array(strtolower($name), $knownNames, true)) {
            $orphans[] = "Allowlist: {$name}";
        }
    }
    foreach ($server['permissions'] as $entry) {
        $xuid = (string) ($entry['xuid'] ?? '');
        if ($xuid !== '' && !in_array($xuid, $knownXuids, true)) {
            $orphans[] = 'Permission: ' . $xuid . ' (' . (string) ($entry['permission'] ?? 'unknown') . ')';
        }
    }
    return $orphans;
}

function property_value(array $properties, string $key): string
{
    return isset($properties[$key]) ? implode(' | ', $properties[$key]) : '—';
}

function read_public_ipv4_cache(): array
{
    $contents = @file_get_contents(env_required('MINECRAFT_LOCKS') . '/public-ip.json');
    if ($contents === false) {
        return [];
    }

    try {
        $cache = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }
    if (!is_array($cache)
        || filter_var($cache['address'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        || !is_int($cache['acquired_at'] ?? null)) {
        return [];
    }
    return $cache;
}

function query_public_ipv4(): string
{
    $stun = env_required('MINECRAFT_STUN');
    if (!preg_match('/^([^:]+):([0-9]+)$/', $stun, $matches)) {
        return '';
    }

    $pipes = [];
    $process = @proc_open(
        ['/usr/bin/turnutils_stunclient', '-p', $matches[2], $matches[1]],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        return '';
    }

    $output = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        return '';
    }
    if (!preg_match('/IPv4\. UDP reflexive addr:\s*((?:[0-9]{1,3}\.){3}[0-9]{1,3}):[0-9]+/', $output, $matches)) {
        return '';
    }

    return filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $matches[1] : '';
}

function public_ipv4(bool $force = false): array
{
    $lockPath = env_required('MINECRAFT_LOCKS') . '/public-ip.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Unable to lock the public IP cache.');
    }

    try {
        $cached = read_public_ipv4_cache();
        if (!$force && $cached !== [] && time() - $cached['acquired_at'] < STUN_CACHE_TTL) {
            return $cached + ['refreshed' => false];
        }

        $address = query_public_ipv4();
        if ($address === '') {
            return $cached + ['address' => '', 'acquired_at' => null, 'refreshed' => false];
        }

        $result = ['address' => $address, 'acquired_at' => time()];
        write_json_atomic(env_required('MINECRAFT_LOCKS') . '/public-ip.json', $result);
        return $result + ['refreshed' => true];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function require_csrf(): void
{
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        throw new RuntimeException('Invalid CSRF token.');
    }
}
