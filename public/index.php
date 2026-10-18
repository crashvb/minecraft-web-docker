<?php

declare(strict_types=1);

session_set_cookie_params(['secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
require dirname(__DIR__) . '/src/bootstrap.php';

$error = null;
$requestAction = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';
if ($requestAction === 'login') {
    try {
        require_csrf();
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        if (!authenticate_user($username, (string) ($_POST['password'] ?? ''))) {
            throw new RuntimeException('Invalid credentials.');
        }
        session_regenerate_id(true);
        $_SESSION = ['authenticated_user' => $username];
        header('Location: /');
        exit;
    } catch (Throwable) {
        $error = 'Invalid username or password.';
    }
}

if (!isset($_SESSION['authenticated_user']) || !is_string($_SESSION['authenticated_user'])) {
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | Minecraft Server Manager</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<main class="login-page">
    <section class="login-card">
        <h1>Minecraft Server Manager</h1>
        <h2>Sign in</h2>
        <?php if ($error): ?><div class="notice error" role="alert"><span><?= h($error) ?></span></div><?php endif; ?>
        <form method="post" class="login-form">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="login">
            <label>Username <input type="text" name="username" autocomplete="username" required autofocus></label>
            <label>Password <input type="password" name="password" autocomplete="current-password" required></label>
            <button type="submit">Sign in</button>
        </form>
    </section>
</main>
</body>
</html>
    <?php
    exit;
}

$authenticatedUser = $_SESSION['authenticated_user'];
if ($requestAction === 'logout') {
    try {
        require_csrf();
        logout_user();
    } catch (Throwable $exception) {
        $_SESSION['error'] = $exception->getMessage();
    }
    header('Location: /');
    exit;
}

$xuids = [];
$xuidDocument = '';
$states = [];
foreach (servers() as $server) {
    $states[$server] = ['allowlist' => [], 'permissions' => [], 'version' => ''];
}
try {
    $xuidPath = env_required('MINECRAFT_CONFIG') . '/xuid.yml';
    $xuidDocument = @file_get_contents($xuidPath);
    if ($xuidDocument === false) {
        throw new RuntimeException("Unable to read {$xuidPath}");
    }
    $xuids = load_xuids();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $action = (string) ($_POST['action'] ?? 'update_player');
        if ($action === 'refresh_public_ip') {
            $refreshed = public_ipv4(true);
            if ($refreshed['refreshed']) {
                $_SESSION['message'] = 'Refreshed the public IP address.';
            } else {
                $_SESSION['error'] = 'Unable to refresh the public IP address; displaying the cached value.';
            }
            header('Location: /');
            exit;
        }
        if ($action === 'add_xuid') {
            $gamertag = trim((string) ($_POST['gamertag'] ?? ''));
            add_xuid($gamertag, (string) ($_POST['xuid'] ?? ''));
            $_SESSION['message'] = "Added {$gamertag} to xuid.yml.";
            header('Location: /');
            exit;
        }
        if ($action !== 'update_player') {
            throw new InvalidArgumentException('Unknown action.');
        }
        $server = (string) ($_POST['server'] ?? '');
        $gamertag = (string) ($_POST['gamertag'] ?? '');
        if (!array_key_exists($gamertag, $xuids)) {
            throw new InvalidArgumentException('Unknown gamer tag.');
        }
        update_player(
            $server,
            $gamertag,
            $xuids[$gamertag],
            ($_POST['allowed'] ?? '0') === '1',
            ($_POST['ignore_limit'] ?? '0') === '1',
            (string) ($_POST['permission'] ?? '')
        );
        $_SESSION['message'] = "Updated {$gamertag} on {$server}.";
        header('Location: /');
        exit;
    }

    foreach (servers() as $server) {
        $states[$server] = load_server($server);
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$message = $_SESSION['message'] ?? null;
unset($_SESSION['message']);
if (isset($_SESSION['error'])) {
    $error = (string) $_SESSION['error'];
    unset($_SESSION['error']);
}

$properties = [];
$defaults = [];
$propertyKeys = [];
foreach (servers() as $server) {
    $properties[$server] = read_properties(config_path($server, 'server.properties'));
    $defaults[$server] = read_properties(data_path($server, 'server.properties.dist'));
    $propertyKeys = array_merge($propertyKeys, array_keys($properties[$server]), array_keys($defaults[$server]));
}
$propertyKeys = array_values(array_unique($propertyKeys));
sort($propertyKeys, SORT_NATURAL | SORT_FLAG_CASE);
$showAll = ($_GET['all'] ?? '') === '1';
$publicIp = public_ipv4();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Minecraft Server Manager</title>
    <link rel="stylesheet" href="/assets/style.css">
    <script src="/assets/table.js" defer></script>
</head>
<body>
<header>
    <div class="header-heading">
        <h1>Minecraft Server Manager</h1>
        <div class="authenticated-user">
            <span>Signed in as <strong><?= h($authenticatedUser) ?></strong></span>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit">Logout</button>
            </form>
        </div>
    </div>
    <div class="public-ip">
        <p>Public IP <strong class="mono"><?= h($publicIp['address'] ?: 'Unavailable') ?></strong></p>
        <small>Last acquired: <?= h($publicIp['acquired_at'] === null ? 'Never' : gmdate('Y-m-d H:i:s \U\T\C', $publicIp['acquired_at'])) ?></small>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="refresh_public_ip">
            <button type="submit">Refresh public IP</button>
        </form>
    </div>
</header>
<main>
    <?php if ($message): ?>
        <div class="notice success" role="status"><span><?= h($message) ?></span><button type="button" class="notice-close" data-dismiss-notice aria-label="Dismiss notification">&times;</button></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="notice error" role="alert"><span><?= h($error) ?></span><button type="button" class="notice-close" data-dismiss-notice aria-label="Dismiss notification">&times;</button></div>
    <?php endif; ?>

    <section>
        <h2>Servers</h2>
        <div class="cards">
            <?php foreach (servers() as $server): ?>
                <article class="card">
                    <h3><?= h(ucfirst($server)) ?></h3>
                    <dl class="server-details">
                        <dt>Version</dt><dd><strong><?= h($states[$server]['version'] ?: 'Unavailable') ?></strong></dd>
                        <dt>Server name</dt><dd><strong><?= h(property_value($properties[$server], 'server-name')) ?></strong></dd>
                        <dt>Level name</dt><dd><strong><?= h(property_value($properties[$server], 'level-name')) ?></strong></dd>
                    </dl>
                    <div class="json-links">
                        <?php foreach (['allowlist' => 'allowlist.json', 'permissions' => 'permissions.json'] as $document => $filename):
                            $dialogId = "json-{$server}-{$document}";
                            $dialogTitleId = "{$dialogId}-title";
                        ?>
                            <a href="#<?= h($dialogId) ?>" data-dialog-open="<?= h($dialogId) ?>"><?= h($filename) ?></a>
                            <dialog class="json-dialog" id="<?= h($dialogId) ?>" aria-labelledby="<?= h($dialogTitleId) ?>">
                                <div class="dialog-heading">
                                    <h2 id="<?= h($dialogTitleId) ?>"><?= h(ucfirst($server) . ' / ' . $filename) ?></h2>
                                    <form method="dialog"><button type="submit">Close</button></form>
                                </div>
                                <pre><code data-json><?= h(json_encode($states[$server][$document], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?></code></pre>
                            </dialog>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php foreach (servers() as $server): $orphans = orphaned_entries($states[$server], $xuids); ?>
            <?php if ($orphans): ?>
                <p class="notice warning"><strong><?= h(ucfirst($server)) ?> unmapped entries:</strong> <?= h(implode(', ', $orphans)) ?></p>
            <?php endif; ?>
        <?php endforeach; ?>
    </section>

    <section>
        <div class="heading-row">
            <h2>Players</h2>
            <div class="table-actions">
                <label class="table-filter">Filter <input type="search" data-table-filter="players-table" autocomplete="off"></label>
                <a href="https://www.cxkes.me/xbox/xuid" target="_blank" rel="noopener noreferrer">XUID lookup</a>
                <a href="#xuid-yaml" data-dialog-open="xuid-yaml">xuid.yml</a>
                <dialog class="json-dialog" id="xuid-yaml" aria-labelledby="xuid-yaml-title">
                    <div class="dialog-heading">
                        <h2 id="xuid-yaml-title">xuid.yml</h2>
                        <form method="dialog"><button type="submit">Close</button></form>
                    </div>
                    <pre><code data-yaml><?= h($xuidDocument) ?></code></pre>
                    <form method="post" class="xuid-form">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add_xuid">
                        <label>Gamer tag <input type="text" name="gamertag" required></label>
                        <label>XUID <input type="text" name="xuid" inputmode="numeric" pattern="[0-9]+" required></label>
                        <button type="submit">Add</button>
                    </form>
                </dialog>
            </div>
        </div>
        <div class="table-wrap">
            <table id="players-table">
                <thead><tr><th aria-sort="ascending"><button type="button" class="sort-button">Gamer tag</button></th><th><button type="button" class="sort-button">XUID</button></th><?php foreach (servers() as $server): ?><th><button type="button" class="sort-button"><?= h(ucfirst($server)) ?></button></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($xuids as $gamertag => $xuid): ?>
                    <tr>
                        <th><?= h($gamertag) ?></th>
                        <td class="mono"><?= h($xuid) ?></td>
                        <?php foreach (servers() as $server): [$allowed, $ignoreLimit, $permission] = player_state($states[$server], $gamertag, $xuid); ?>
                            <td
                                data-sort-value="<?= h("{$permission} " . (int) $allowed . ' ' . (int) $ignoreLimit) ?>"
                                data-filter-value="<?= h(($allowed ? 'allowlisted' : 'not allowlisted') . ' ' . ($ignoreLimit ? 'ignore player limit' : 'respect player limit') . ' ' . ($permission === '' ? 'no permission' : $permission)) ?>"
                            >
                                <form method="post" class="player-form">
                                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update_player">
                                    <input type="hidden" name="server" value="<?= h($server) ?>">
                                    <input type="hidden" name="gamertag" value="<?= h($gamertag) ?>">
                                    <input type="hidden" name="allowed" value="0">
                                    <label><input type="checkbox" name="allowed" value="1" <?= $allowed ? 'checked' : '' ?>> Allowlisted</label>
                                    <input type="hidden" name="ignore_limit" value="0">
                                    <label><input type="checkbox" name="ignore_limit" value="1" <?= $ignoreLimit ? 'checked' : '' ?> <?= !$allowed ? 'disabled' : '' ?>> Ignore player limit</label>
                                    <select name="permission" aria-label="Permission" <?= !$allowed ? 'disabled' : '' ?>>
                                        <?php foreach (PERMISSIONS as $option): ?>
                                            <option value="<?= h($option) ?>" <?= $permission === $option ? 'selected' : '' ?>><?= h($option === '' ? 'No permission' : ucfirst($option)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit">Save</button>
                                </form>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section>
        <div class="heading-row">
            <h2>Server properties</h2>
            <div class="table-actions">
                <label class="table-filter">Filter <input type="search" data-table-filter="properties-table" autocomplete="off"></label>
                <a href="<?= $showAll ? '/' : '/?all=1' ?>"><?= $showAll ? 'Show differences only' : 'Show all properties' ?></a>
            </div>
        </div>
        <div class="table-wrap">
            <table class="properties" id="properties-table">
                <thead><tr><th aria-sort="ascending"><button type="button" class="sort-button">Property</button></th><th><button type="button" class="sort-button">Distribution default</button></th><?php foreach (servers() as $server): ?><th><button type="button" class="sort-button"><?= h(ucfirst($server)) ?></button></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($propertyKeys as $key):
                    $currentValues = array_map(static fn($server) => property_value($properties[$server], $key), servers());
                    $defaultValues = array_map(static fn($server) => property_value($defaults[$server], $key), servers());
                    $different = count(array_unique(array_merge($currentValues, $defaultValues))) > 1;
                    if (!$showAll && !$different) continue;
                    $defaultDisplay = count(array_unique($defaultValues)) === 1
                        ? $defaultValues[0]
                        : implode(' · ', array_map(static fn($server, $value) => ucfirst($server) . ': ' . $value, servers(), $defaultValues));
                ?>
                    <tr class="<?= $different ? 'different' : '' ?>">
                        <th><?= h($key) ?></th>
                        <td><?= h($defaultDisplay) ?></td>
                        <?php foreach ($currentValues as $value): ?><td><?= h($value) ?></td><?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
