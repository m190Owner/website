<?php
// ---- Session Hardening ----
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '3600');
session_start();

// ---- Security Headers ----
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; frame-src https://www.youtube-nocookie.com; connect-src 'self'; font-src 'self'; base-uri 'self'; form-action 'self'");

// ---- Paths ----
define('FORUM_ROOT', dirname(__DIR__));
define('DATA_DIR', FORUM_ROOT . '/data');
define('THREADS_DIR', DATA_DIR . '/threads');
define('USERS_FILE', DATA_DIR . '/users.json');
define('CATEGORIES_FILE', DATA_DIR . '/categories.json');
define('INVITES_FILE', DATA_DIR . '/invites.json');
define('AVATARS_DIR', FORUM_ROOT . '/uploads/avatars');
define('MESSAGES_DIR', DATA_DIR . '/messages');
define('REPORTS_FILE', DATA_DIR . '/reports.json');
define('POST_IMAGES_DIR', FORUM_ROOT . '/uploads/posts');

require_once dirname(FORUM_ROOT) . '/config.php';

foreach ([DATA_DIR, THREADS_DIR, AVATARS_DIR, MESSAGES_DIR, POST_IMAGES_DIR, DATA_DIR . '/notifications'] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

initializeForumData();
trackOnline();

// Force password setup before accessing any other page
$_currentScript = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
if (isset($_SESSION['needs_password']) && $_SESSION['needs_password'] && !in_array($_currentScript, ['setup.php', 'logout.php'])) {
    header('Location: /forum/setup.php');
    exit;
}

// ==============================================
// CSRF
// ==============================================
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf(): bool {
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
}

// ==============================================
// AUTH
// ==============================================
function isLoggedIn(): bool {
    return isset($_SESSION['forum_user']);
}

function currentUser(): ?string {
    return $_SESSION['forum_user'] ?? null;
}

function currentUserData(): ?array {
    if (!isLoggedIn()) return null;
    $users = readJsonFile(USERS_FILE, []);
    return $users[strtolower(currentUser())] ?? null;
}

function isAdmin(): bool {
    $data = currentUserData();
    return $data && ($data['role'] ?? '') === 'admin';
}

function getLoginAttemptsFile(string $key): string {
    return DATA_DIR . '/login_attempts_' . md5($key) . '.json';
}

function isAccountLocked(string $key): bool {
    $file = getLoginAttemptsFile($key);
    $data = readJsonFile($file, ['attempts' => 0, 'last_attempt' => 0, 'locked_until' => 0]);
    if ($data['locked_until'] > time()) return true;
    return false;
}

function recordFailedLogin(string $key): void {
    $file = getLoginAttemptsFile($key);
    $data = readJsonFile($file, ['attempts' => 0, 'last_attempt' => 0, 'locked_until' => 0]);
    // Reset if last attempt was > 15 minutes ago
    if (time() - $data['last_attempt'] > 900) $data['attempts'] = 0;
    $data['attempts']++;
    $data['last_attempt'] = time();
    // Progressive lockout: 5 fails = 30s, 10 = 2min, 15 = 5min, 20+ = 15min
    if ($data['attempts'] >= 20) $data['locked_until'] = time() + 900;
    elseif ($data['attempts'] >= 15) $data['locked_until'] = time() + 300;
    elseif ($data['attempts'] >= 10) $data['locked_until'] = time() + 120;
    elseif ($data['attempts'] >= 5) $data['locked_until'] = time() + 30;
    writeJsonFile($file, $data);
}

function clearLoginAttempts(string $key): void {
    $file = getLoginAttemptsFile($key);
    if (file_exists($file)) @unlink($file);
}

function doLogin(string $username, string $password): array {
    $users = readJsonFile(USERS_FILE, []);
    $key = strtolower(trim($username));

    // Check account lockout (use both IP and username)
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $lockKey = $key ?: $ip;
    if (isAccountLocked($lockKey)) {
        return ['ok' => false, 'error' => 'Too many failed attempts. Please try again later.'];
    }
    if (isAccountLocked('ip_' . md5($ip))) {
        return ['ok' => false, 'error' => 'Too many failed attempts. Please try again later.'];
    }

    if (!isset($users[$key])) {
        // Constant-time: always hash something to prevent timing attacks
        password_verify($password, '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ012');
        recordFailedLogin($lockKey);
        recordFailedLogin('ip_' . md5($ip));
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }

    $user = $users[$key];

    if ($user['needs_password'] ?? false) {
        session_regenerate_id(true);
        $_SESSION['forum_user'] = $user['username'];
        $_SESSION['needs_password'] = true;
        clearLoginAttempts($lockKey);
        return ['ok' => true, 'needs_password' => true];
    }

    if (!password_verify($password, $user['password_hash'])) {
        recordFailedLogin($lockKey);
        recordFailedLogin('ip_' . md5($ip));
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }

    if ($user['banned'] ?? false) {
        return ['ok' => false, 'error' => 'This account has been banned.'];
    }

    clearLoginAttempts($lockKey);
    clearLoginAttempts('ip_' . md5($ip));
    session_regenerate_id(true);
    $_SESSION['forum_user'] = $user['username'];
    return ['ok' => true];
}

function needsPassword(): bool {
    return $_SESSION['needs_password'] ?? false;
}

function setPassword(string $password): array {
    if (!isLoggedIn()) return ['ok' => false, 'error' => 'Not logged in.'];
    if (strlen($password) < 8) return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];

    $users = readJsonFile(USERS_FILE, []);
    $key = strtolower(currentUser());
    if (!isset($users[$key])) return ['ok' => false, 'error' => 'User not found.'];

    $users[$key]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    unset($users[$key]['needs_password']);
    writeJsonFile(USERS_FILE, $users);
    file_put_contents(DATA_DIR . '/.password_set', '1', LOCK_EX);
    unset($_SESSION['needs_password']);
    return ['ok' => true];
}

function doRegister(string $username, string $password, string $inviteCode): array {
    $username = trim($username);
    $key = strtolower($username);

    if (strlen($username) < 3 || strlen($username) > 24) {
        return ['ok' => false, 'error' => 'Username must be 3-24 characters.'];
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        return ['ok' => false, 'error' => 'Username may only contain letters, numbers, and underscores.'];
    }
    $reserved = ['admin', 'administrator', 'system', 'root', 'mod', 'moderator', 'staff', 'support', 'help', 'null', 'undefined', 'anonymous', 'bot', 'm190', 'forum'];
    if (in_array($key, $reserved)) {
        return ['ok' => false, 'error' => 'This username is reserved.'];
    }
    if (strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    }

    $invites = readJsonFile(INVITES_FILE, []);
    $inviteCode = strtoupper(trim($inviteCode));
    $validInvite = false;
    $inviteIndex = -1;

    foreach ($invites as $i => $inv) {
        if (hash_equals($inv['code'], $inviteCode) && !$inv['used']) {
            $validInvite = true;
            $inviteIndex = $i;
            break;
        }
    }

    if (!$validInvite) {
        return ['ok' => false, 'error' => 'Invalid or already used invite code.'];
    }

    $users = readJsonFile(USERS_FILE, []);
    if (isset($users[$key])) {
        return ['ok' => false, 'error' => 'Username already taken.'];
    }

    $users[$key] = [
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => 'member',
        'created' => date('c'),
        'banned' => false,
        'bio' => ''
    ];
    writeJsonFile(USERS_FILE, $users);

    $invites[$inviteIndex]['used'] = true;
    $invites[$inviteIndex]['used_by'] = $username;
    $invites[$inviteIndex]['used_at'] = date('c');
    writeJsonFile(INVITES_FILE, $invites);

    session_regenerate_id(true);
    $_SESSION['forum_user'] = $username;
    return ['ok' => true];
}

function doLogout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ==============================================
// FORUM DATA
// ==============================================
function getCategories(): array {
    $cats = readJsonFile(CATEGORIES_FILE, []);
    usort($cats, fn($a, $b) => ($a['order'] ?? 0) - ($b['order'] ?? 0));
    return $cats;
}

function getTopLevelCategories(): array {
    return array_values(array_filter(getCategories(), fn($c) => empty($c['parent'])));
}

function getSubCategories(string $parentId): array {
    return array_values(array_filter(getCategories(), fn($c) => ($c['parent'] ?? '') === $parentId));
}

function getCategoryById(string $id): ?array {
    foreach (getCategories() as $cat) {
        if ($cat['id'] === $id) return $cat;
    }
    return null;
}

function getAllThreadFiles(): array {
    $files = glob(THREADS_DIR . '/*.json');
    return $files ?: [];
}

function getThreadsByCategory(string $catId, int $page = 1, int $perPage = 20): array {
    $threads = [];
    foreach (getAllThreadFiles() as $file) {
        $thread = readJsonFile($file);
        if (($thread['category'] ?? '') !== $catId) continue;
        $posts = $thread['posts'] ?? [];
        $lastPost = end($posts);
        $thread['reply_count'] = max(0, count($posts) - 1);
        $thread['last_post_time'] = $lastPost['created'] ?? $thread['created'];
        $thread['last_post_author'] = $lastPost['author'] ?? $thread['author'];
        $threads[] = $thread;
    }

    usort($threads, function ($a, $b) {
        if (($a['pinned'] ?? false) && !($b['pinned'] ?? false)) return -1;
        if (!($a['pinned'] ?? false) && ($b['pinned'] ?? false)) return 1;
        return strtotime($b['last_post_time']) - strtotime($a['last_post_time']);
    });

    $total = count($threads);
    $threads = array_slice($threads, ($page - 1) * $perPage, $perPage);
    return ['threads' => $threads, 'total' => $total, 'pages' => max(1, (int)ceil($total / $perPage))];
}

function getThread(string $id): ?array {
    $id = preg_replace('/[^a-f0-9]/', '', $id);
    $file = THREADS_DIR . '/' . $id . '.json';
    if (!file_exists($file)) return null;
    return readJsonFile($file);
}

function saveThread(array $thread): void {
    $file = THREADS_DIR . '/' . $thread['id'] . '.json';
    writeJsonFile($file, $thread);
}

function createThread(string $catId, string $title, string $content, array $tags = [], ?array $poll = null, string $prefix = ''): array {
    if (!isLoggedIn()) return ['ok' => false, 'error' => 'Not logged in.'];
    $title = trim($title);
    $content = trim($content);
    if (strlen($title) < 3 || strlen($title) > 120)
        return ['ok' => false, 'error' => 'Title must be 3-120 characters.'];
    if (strlen($content) < 1 || strlen($content) > 10000)
        return ['ok' => false, 'error' => 'Content must be 1-10000 characters.'];
    if (!getCategoryById($catId))
        return ['ok' => false, 'error' => 'Invalid category.'];

    $validTags = array_column(getAvailableTags(), 'id');
    $tags = array_values(array_intersect($tags, $validTags));

    $id = bin2hex(random_bytes(8));
    $now = date('c');
    $firstPostId = bin2hex(random_bytes(6));
    $thread = [
        'id' => $id, 'category' => $catId, 'title' => $title,
        'author' => currentUser(), 'created' => $now,
        'pinned' => false, 'locked' => false, 'sticky_global' => false,
        'tags' => $tags, 'prefix' => $prefix ?? '',
        'posts' => [[
            'id' => $firstPostId,
            'author' => currentUser(), 'content' => $content,
            'created' => $now, 'reactions' => [], 'votes' => ['up' => [], 'down' => []]
        ]]
    ];

    if ($poll && !empty($poll['question']) && !empty($poll['options'])) {
        $opts = array_values(array_filter(array_map('trim', $poll['options']), fn($o) => $o !== ''));
        if (count($opts) >= 2 && count($opts) <= 10) {
            $thread['poll'] = [
                'question' => trim($poll['question']),
                'options' => array_map(fn($o) => ['text' => $o, 'votes' => []], $opts),
                'closed' => false
            ];
        }
    }

    saveThread($thread);
    processPostMentions($content, $id, $firstPostId, $title);
    checkAndAwardAchievements(currentUser());
    return ['ok' => true, 'id' => $id];
}

function addReply(string $threadId, string $content): array {
    if (!isLoggedIn()) return ['ok' => false, 'error' => 'Not logged in.'];
    $content = trim($content);
    if (strlen($content) < 1 || strlen($content) > 10000) {
        return ['ok' => false, 'error' => 'Reply must be 1-10000 characters.'];
    }

    $thread = getThread($threadId);
    if (!$thread) return ['ok' => false, 'error' => 'Thread not found.'];
    if ($thread['locked'] ?? false) return ['ok' => false, 'error' => 'Thread is locked.'];

    $newPostId = bin2hex(random_bytes(6));
    $thread['posts'][] = [
        'id' => $newPostId,
        'author' => currentUser(),
        'content' => $content,
        'created' => date('c'),
        'reactions' => [], 'votes' => ['up' => [], 'down' => []]
    ];
    saveThread($thread);
    processPostMentions($content, $threadId, $newPostId, $thread['title']);
    checkAndAwardAchievements(currentUser());
    return ['ok' => true];
}

function getRecentThreads(int $limit = 5): array {
    $threads = [];
    foreach (getAllThreadFiles() as $file) {
        $thread = readJsonFile($file);
        $posts = $thread['posts'] ?? [];
        $lastPost = end($posts);
        $thread['reply_count'] = max(0, count($posts) - 1);
        $thread['last_post_time'] = $lastPost['created'] ?? $thread['created'];
        $thread['last_post_author'] = $lastPost['author'] ?? $thread['author'];
        $threads[] = $thread;
    }
    usort($threads, fn($a, $b) => strtotime($b['last_post_time']) - strtotime($a['last_post_time']));
    return array_slice($threads, 0, $limit);
}

function getThreadCountForCategory(string $catId): int {
    $count = 0;
    foreach (getAllThreadFiles() as $file) {
        $thread = readJsonFile($file);
        if (($thread['category'] ?? '') === $catId) $count++;
    }
    return $count;
}

function getPostCountForCategory(string $catId): int {
    $count = 0;
    foreach (getAllThreadFiles() as $file) {
        $thread = readJsonFile($file);
        if (($thread['category'] ?? '') === $catId) {
            $count += count($thread['posts'] ?? []);
        }
    }
    return $count;
}

function getLastPostForCategory(string $catId): ?array {
    $latest = null;
    $latestTime = 0;
    foreach (getAllThreadFiles() as $file) {
        $thread = readJsonFile($file);
        if (($thread['category'] ?? '') !== $catId) continue;
        $posts = $thread['posts'] ?? [];
        $lastPost = end($posts);
        if ($lastPost) {
            $t = strtotime($lastPost['created']);
            if ($t > $latestTime) {
                $latestTime = $t;
                $latest = [
                    'thread_id' => $thread['id'],
                    'thread_title' => $thread['title'],
                    'author' => $lastPost['author'],
                    'time' => $lastPost['created']
                ];
            }
        }
    }
    return $latest;
}

// ==============================================
// USER PROFILE
// ==============================================
function getUserProfile(string $username): ?array {
    $users = readJsonFile(USERS_FILE, []);
    $key = strtolower($username);
    if (!isset($users[$key])) return null;
    $user = $users[$key];
    unset($user['password_hash']);
    $user['post_count'] = getUserPostCount($user['username']);
    $user['thread_count'] = getUserThreadCount($user['username']);
    return $user;
}

function getUserPostCount(string $username): int {
    $count = 0;
    foreach (getAllThreadFiles() as $file) {
        $thread = readJsonFile($file);
        foreach ($thread['posts'] ?? [] as $post) {
            if ($post['author'] === $username) $count++;
        }
    }
    return $count;
}

function getUserThreadCount(string $username): int {
    $count = 0;
    foreach (getAllThreadFiles() as $file) {
        $thread = readJsonFile($file);
        if (($thread['author'] ?? '') === $username) $count++;
    }
    return $count;
}

// ==============================================
// ADMIN
// ==============================================
function generateInvite(): string {
    $code = strtoupper(bin2hex(random_bytes(4)));
    $invites = readJsonFile(INVITES_FILE, []);
    $invites[] = [
        'code' => $code,
        'created_by' => currentUser(),
        'created' => date('c'),
        'used' => false,
        'used_by' => null,
        'used_at' => null
    ];
    writeJsonFile(INVITES_FILE, $invites);
    return $code;
}

function getInvites(): array {
    return readJsonFile(INVITES_FILE, []);
}

function deleteInvite(string $code): bool {
    $invites = readJsonFile(INVITES_FILE, []);
    $filtered = array_values(array_filter($invites, fn($inv) => $inv['code'] !== $code));
    if (count($filtered) === count($invites)) return false;
    writeJsonFile(INVITES_FILE, $filtered);
    return true;
}

function toggleBan(string $username): bool {
    $users = readJsonFile(USERS_FILE, []);
    $key = strtolower($username);
    if (!isset($users[$key]) || $users[$key]['role'] === 'admin') return false;
    $users[$key]['banned'] = !($users[$key]['banned'] ?? false);
    writeJsonFile(USERS_FILE, $users);
    return true;
}

function setUserRole(string $username, string $role): bool {
    if (!in_array($role, ['member', 'moderator', 'admin'])) return false;
    $users = readJsonFile(USERS_FILE, []);
    $key = strtolower($username);
    if (!isset($users[$key])) return false;
    $users[$key]['role'] = $role;
    writeJsonFile(USERS_FILE, $users);
    return true;
}

function togglePin(string $threadId): bool {
    $thread = getThread($threadId);
    if (!$thread) return false;
    $thread['pinned'] = !($thread['pinned'] ?? false);
    saveThread($thread);
    return true;
}

function toggleLock(string $threadId): bool {
    $thread = getThread($threadId);
    if (!$thread) return false;
    $thread['locked'] = !($thread['locked'] ?? false);
    saveThread($thread);
    return true;
}

function deleteThread(string $threadId): bool {
    $id = preg_replace('/[^a-f0-9]/', '', $threadId);
    $file = THREADS_DIR . '/' . $id . '.json';
    if (file_exists($file)) {
        return unlink($file);
    }
    return false;
}

function deletePost(string $threadId, string $postId): bool {
    $thread = getThread($threadId);
    if (!$thread) return false;
    $posts = $thread['posts'] ?? [];
    if (count($posts) <= 1) return deleteThread($threadId);
    $thread['posts'] = array_values(array_filter($posts, fn($p) => $p['id'] !== $postId));
    saveThread($thread);
    return true;
}

function addCategory(string $name, string $description, string $parent = ''): bool {
    $cats = readJsonFile(CATEGORIES_FILE, []);
    $id = preg_replace('/[^a-z0-9]/', '-', strtolower(trim($name)));
    $id = preg_replace('/-+/', '-', trim($id, '-'));
    if (!$id) return false;
    foreach ($cats as $c) {
        if ($c['id'] === $id) return false;
    }
    $maxOrder = 0;
    foreach ($cats as $c) {
        if (($c['order'] ?? 0) > $maxOrder) $maxOrder = $c['order'];
    }
    $cat = [
        'id' => $id, 'name' => trim($name),
        'description' => trim($description), 'order' => $maxOrder + 1
    ];
    if ($parent && getCategoryById($parent)) $cat['parent'] = $parent;
    $cats[] = $cat;
    writeJsonFile(CATEGORIES_FILE, $cats);
    return true;
}

function deleteCategory(string $id): bool {
    $cats = readJsonFile(CATEGORIES_FILE, []);
    $cats = array_values(array_filter($cats, fn($c) => $c['id'] !== $id));
    writeJsonFile(CATEGORIES_FILE, $cats);
    // Delete threads in this category
    foreach (getAllThreadFiles() as $file) {
        $thread = readJsonFile($file);
        if (($thread['category'] ?? '') === $id) {
            unlink($file);
        }
    }
    return true;
}

// ==============================================
// ONLINE TRACKING
// ==============================================
function trackOnline(): void {
    if (!isLoggedIn()) return;
    $file = DATA_DIR . '/online.json';
    $online = readJsonFile($file, []);
    $online[strtolower(currentUser())] = time();
    $online = array_filter($online, fn($t) => time() - $t < 300);
    writeJsonFile($file, $online);
}

function isUserOnline(string $username): bool {
    $online = readJsonFile(DATA_DIR . '/online.json', []);
    return (time() - ($online[strtolower($username)] ?? 0)) < 300;
}

function getOnlineUsers(): array {
    $online = readJsonFile(DATA_DIR . '/online.json', []);
    $result = [];
    $users = readJsonFile(USERS_FILE, []);
    foreach ($online as $key => $time) {
        if (time() - $time < 300 && isset($users[$key])) {
            $result[] = $users[$key]['username'];
        }
    }
    return $result;
}

function getOnlineCount(): int { return count(getOnlineUsers()); }

// ==============================================
// USER RANKS
// ==============================================
function getUserRank(int $postCount): string {
    if ($postCount >= 500) return 'Legend';
    if ($postCount >= 200) return 'Veteran';
    if ($postCount >= 100) return 'Regular';
    if ($postCount >= 50) return 'Active';
    if ($postCount >= 10) return 'Member';
    return 'Newbie';
}

function getRankColor(string $rank): string {
    return match($rank) {
        'Legend' => '#ffb86b',
        'Veteran' => '#b86bff',
        'Regular' => '#6bffb8',
        'Active' => '#6bddff',
        'Member' => '#7aa2ff',
        default => '#5a6480'
    };
}

// ==============================================
// SEARCH
// ==============================================
function searchForum(string $query, int $page = 1, int $perPage = 20): array {
    $query = trim($query);
    if (strlen($query) < 2) return ['results' => [], 'total' => 0, 'pages' => 1];
    $results = [];

    foreach (getAllThreadFiles() as $file) {
        $thread = readJsonFile($file);
        $titleMatch = stripos($thread['title'], $query) !== false;

        if ($titleMatch) {
            $results[] = [
                'type' => 'thread', 'thread_id' => $thread['id'],
                'thread_title' => $thread['title'], 'category' => $thread['category'],
                'author' => $thread['author'], 'created' => $thread['created'],
                'excerpt' => mb_strimwidth($thread['posts'][0]['content'] ?? '', 0, 150, '...')
            ];
        }

        foreach ($thread['posts'] as $post) {
            if (stripos($post['content'], $query) !== false) {
                $results[] = [
                    'type' => 'post', 'thread_id' => $thread['id'],
                    'thread_title' => $thread['title'], 'post_id' => $post['id'],
                    'category' => $thread['category'], 'author' => $post['author'],
                    'created' => $post['created'],
                    'excerpt' => getSearchExcerpt($post['content'], $query)
                ];
            }
        }
    }

    $seen = [];
    $results = array_values(array_filter($results, function($r) use (&$seen) {
        $key = $r['thread_id'] . '_' . ($r['post_id'] ?? 'title');
        if (isset($seen[$key])) return false;
        $seen[$key] = true;
        return true;
    }));
    usort($results, function($a, $b) {
        if ($a['type'] !== $b['type']) return $a['type'] === 'thread' ? -1 : 1;
        return strtotime($b['created']) - strtotime($a['created']);
    });

    $total = count($results);
    return ['results' => array_slice($results, ($page - 1) * $perPage, $perPage), 'total' => $total, 'pages' => max(1, (int)ceil($total / $perPage))];
}

function getSearchExcerpt(string $content, string $query): string {
    $pos = stripos($content, $query);
    if ($pos === false) return mb_strimwidth($content, 0, 150, '...');
    $start = max(0, $pos - 60);
    $excerpt = mb_substr($content, $start, 150);
    if ($start > 0) $excerpt = '...' . $excerpt;
    if ($start + 150 < mb_strlen($content)) $excerpt .= '...';
    return $excerpt;
}

// ==============================================
// POST EDITING
// ==============================================
function editPost(string $threadId, string $postId, string $content): array {
    if (!isLoggedIn()) return ['ok' => false, 'error' => 'Not logged in.'];
    $content = trim($content);
    if (strlen($content) < 1 || strlen($content) > 10000)
        return ['ok' => false, 'error' => 'Content must be 1-10000 characters.'];
    $thread = getThread($threadId);
    if (!$thread) return ['ok' => false, 'error' => 'Thread not found.'];
    foreach ($thread['posts'] as &$post) {
        if ($post['id'] === $postId) {
            if ($post['author'] !== currentUser() && !isAdmin())
                return ['ok' => false, 'error' => 'You can only edit your own posts.'];
            $post['content'] = $content;
            $post['edited'] = date('c');
            saveThread($thread);
            return ['ok' => true];
        }
    }
    return ['ok' => false, 'error' => 'Post not found.'];
}

// ==============================================
// REACTIONS
// ==============================================
function getReactionEmojis(): array {
    return ['👍', '❤️', '😂', '🔥', '👀', '💯'];
}

function toggleReaction(string $threadId, string $postId, string $emoji): array {
    if (!isLoggedIn()) return ['ok' => false, 'error' => 'Not logged in.'];
    if (!in_array($emoji, getReactionEmojis())) return ['ok' => false, 'error' => 'Invalid reaction.'];
    $thread = getThread($threadId);
    if (!$thread) return ['ok' => false, 'error' => 'Thread not found.'];
    foreach ($thread['posts'] as &$post) {
        if ($post['id'] === $postId) {
            if (!isset($post['reactions'])) $post['reactions'] = [];
            if (!isset($post['reactions'][$emoji])) $post['reactions'][$emoji] = [];
            $user = currentUser();
            $idx = array_search($user, $post['reactions'][$emoji]);
            if ($idx !== false) {
                array_splice($post['reactions'][$emoji], $idx, 1);
                if (empty($post['reactions'][$emoji])) unset($post['reactions'][$emoji]);
            } else {
                $post['reactions'][$emoji][] = $user;
            }
            saveThread($thread);
            return ['ok' => true];
        }
    }
    return ['ok' => false, 'error' => 'Post not found.'];
}

// ==============================================
// PRIVATE MESSAGES
// ==============================================
function getConversationFile(string $user1, string $user2): string {
    $users = [strtolower($user1), strtolower($user2)];
    sort($users);
    return MESSAGES_DIR . '/' . implode('_', $users) . '.json';
}

function sendMessage(string $to, string $content): array {
    if (!isLoggedIn()) return ['ok' => false, 'error' => 'Not logged in.'];
    $content = trim($content);
    if (strlen($content) < 1 || strlen($content) > 5000)
        return ['ok' => false, 'error' => 'Message must be 1-5000 characters.'];
    $users = readJsonFile(USERS_FILE, []);
    $toKey = strtolower(trim($to));
    if (!isset($users[$toKey])) return ['ok' => false, 'error' => 'User not found.'];
    $to = $users[$toKey]['username'];
    if (strtolower($to) === strtolower(currentUser()))
        return ['ok' => false, 'error' => 'Cannot message yourself.'];
    $file = getConversationFile(currentUser(), $to);
    $convo = readJsonFile($file, ['users' => [currentUser(), $to], 'messages' => []]);
    $convo['messages'][] = [
        'id' => bin2hex(random_bytes(6)), 'from' => currentUser(),
        'content' => $content, 'created' => date('c'), 'read' => false
    ];
    writeJsonFile($file, $convo);
    return ['ok' => true];
}

function getConversations(): array {
    if (!isLoggedIn()) return [];
    $convos = [];
    $files = glob(MESSAGES_DIR . '/*.json') ?: [];
    $me = strtolower(currentUser());
    foreach ($files as $file) {
        $key = pathinfo($file, PATHINFO_FILENAME);
        if (strpos($key, $me) === false) continue;
        $convo = readJsonFile($file);
        $messages = $convo['messages'] ?? [];
        if (empty($messages)) continue;
        $lastMsg = end($messages);
        $otherUser = null;
        foreach ($convo['users'] ?? [] as $u) {
            if (strtolower($u) !== $me) { $otherUser = $u; break; }
        }
        if (!$otherUser) continue;
        $unread = 0;
        foreach ($messages as $msg) {
            if (strtolower($msg['from']) !== $me && !($msg['read'] ?? true)) $unread++;
        }
        $convos[] = ['user' => $otherUser, 'last_message' => $lastMsg, 'unread' => $unread];
    }
    usort($convos, fn($a, $b) => strtotime($b['last_message']['created']) - strtotime($a['last_message']['created']));
    return $convos;
}

function getMessages(string $otherUser): array {
    if (!isLoggedIn()) return [];
    $file = getConversationFile(currentUser(), $otherUser);
    $convo = readJsonFile($file, ['users' => [currentUser(), $otherUser], 'messages' => []]);
    $me = strtolower(currentUser());
    $changed = false;
    foreach ($convo['messages'] as &$msg) {
        if (strtolower($msg['from']) !== $me && !($msg['read'] ?? true)) {
            $msg['read'] = true;
            $changed = true;
        }
    }
    if ($changed) writeJsonFile($file, $convo);
    return $convo['messages'];
}

function getUnreadCount(): int {
    $count = 0;
    foreach (getConversations() as $c) $count += $c['unread'];
    return $count;
}

// ==============================================
// REPORTS
// ==============================================
function reportPost(string $threadId, string $postId, string $reason): array {
    if (!isLoggedIn()) return ['ok' => false, 'error' => 'Not logged in.'];
    $reason = trim($reason);
    if (strlen($reason) < 3 || strlen($reason) > 500)
        return ['ok' => false, 'error' => 'Reason must be 3-500 characters.'];
    $reports = readJsonFile(REPORTS_FILE, []);
    foreach ($reports as $r) {
        if ($r['thread_id'] === $threadId && $r['post_id'] === $postId
            && $r['reported_by'] === currentUser() && !$r['resolved'])
            return ['ok' => false, 'error' => 'You already reported this post.'];
    }
    $reports[] = [
        'id' => bin2hex(random_bytes(6)), 'thread_id' => $threadId,
        'post_id' => $postId, 'reported_by' => currentUser(),
        'reason' => $reason, 'created' => date('c'),
        'resolved' => false, 'resolved_by' => null
    ];
    writeJsonFile(REPORTS_FILE, $reports);
    return ['ok' => true];
}

function getReports(bool $includeResolved = false): array {
    $reports = readJsonFile(REPORTS_FILE, []);
    if (!$includeResolved) $reports = array_filter($reports, fn($r) => !$r['resolved']);
    usort($reports, fn($a, $b) => strtotime($b['created']) - strtotime($a['created']));
    return array_values($reports);
}

function resolveReport(string $reportId): bool {
    $reports = readJsonFile(REPORTS_FILE, []);
    foreach ($reports as &$r) {
        if ($r['id'] === $reportId) {
            $r['resolved'] = true;
            $r['resolved_by'] = currentUser();
            writeJsonFile(REPORTS_FILE, $reports);
            return true;
        }
    }
    return false;
}

function getOpenReportCount(): int { return count(getReports(false)); }

// ==============================================
// POLLS
// ==============================================
function createPoll(string $threadId, string $question, array $options): array {
    $thread = getThread($threadId);
    if (!$thread) return ['ok' => false, 'error' => 'Thread not found.'];
    if ($thread['author'] !== currentUser() && !isAdmin())
        return ['ok' => false, 'error' => 'Only the thread author or admin can add a poll.'];
    if (isset($thread['poll'])) return ['ok' => false, 'error' => 'Thread already has a poll.'];
    $options = array_values(array_filter(array_map('trim', $options), fn($o) => $o !== ''));
    if (count($options) < 2 || count($options) > 10)
        return ['ok' => false, 'error' => 'Poll needs 2-10 options.'];
    $thread['poll'] = [
        'question' => trim($question),
        'options' => array_map(fn($o) => ['text' => $o, 'votes' => []], $options),
        'closed' => false
    ];
    saveThread($thread);
    return ['ok' => true];
}

function votePoll(string $threadId, int $optionIndex): array {
    if (!isLoggedIn()) return ['ok' => false, 'error' => 'Not logged in.'];
    $thread = getThread($threadId);
    if (!$thread || !isset($thread['poll'])) return ['ok' => false, 'error' => 'Poll not found.'];
    if ($thread['poll']['closed']) return ['ok' => false, 'error' => 'Poll is closed.'];
    if ($optionIndex < 0 || $optionIndex >= count($thread['poll']['options']))
        return ['ok' => false, 'error' => 'Invalid option.'];
    $user = currentUser();
    foreach ($thread['poll']['options'] as &$opt) {
        $idx = array_search($user, $opt['votes']);
        if ($idx !== false) array_splice($opt['votes'], $idx, 1);
    }
    $thread['poll']['options'][$optionIndex]['votes'][] = $user;
    saveThread($thread);
    return ['ok' => true];
}

function closePoll(string $threadId): bool {
    $thread = getThread($threadId);
    if (!$thread || !isset($thread['poll'])) return false;
    $thread['poll']['closed'] = !$thread['poll']['closed'];
    saveThread($thread);
    return true;
}

// ==============================================
// TAGS
// ==============================================
function getAvailableTags(): array {
    return [
        ['id' => 'discussion', 'name' => 'Discussion', 'color' => '#7aa2ff'],
        ['id' => 'help', 'name' => 'Help', 'color' => '#6bffb8'],
        ['id' => 'showcase', 'name' => 'Showcase', 'color' => '#ffb86b'],
        ['id' => 'question', 'name' => 'Question', 'color' => '#b86bff'],
        ['id' => 'guide', 'name' => 'Guide', 'color' => '#6bddff'],
        ['id' => 'news', 'name' => 'News', 'color' => '#ff6b6b'],
        ['id' => 'meme', 'name' => 'Meme', 'color' => '#ff6bb8'],
        ['id' => 'bug', 'name' => 'Bug Report', 'color' => '#ff6b6b'],
    ];
}

function getTagById(string $id): ?array {
    foreach (getAvailableTags() as $tag) {
        if ($tag['id'] === $id) return $tag;
    }
    return null;
}

function tagHtml(string $tagId): string {
    $tag = getTagById($tagId);
    if (!$tag) return '';
    return '<span class="thread-tag" style="background:' . $tag['color'] . '18;color:' . $tag['color'] . ';border-color:' . $tag['color'] . '30">' . e($tag['name']) . '</span>';
}

// ==============================================
// POST IMAGE UPLOADS
// ==============================================
function uploadPostImage(array $file): array {
    if (!isLoggedIn()) return ['ok' => false, 'error' => 'Not logged in.'];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'Upload failed.'];
    if ($file['size'] > 5 * 1024 * 1024) return ['ok' => false, 'error' => 'Max 5MB.'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) return ['ok' => false, 'error' => 'Use JPG, PNG, GIF, or WebP.'];
    $name = bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    $dest = POST_IMAGES_DIR . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest))
        return ['ok' => false, 'error' => 'Failed to save.'];
    return ['ok' => true, 'url' => '/forum/uploads/posts/' . $name];
}

// ==============================================
// FORUM STATS
// ==============================================
function getForumStats(): array {
    $users = readJsonFile(USERS_FILE, []);
    $threadCount = count(getAllThreadFiles());
    $postCount = 0;
    foreach (getAllThreadFiles() as $file) {
        $thread = readJsonFile($file);
        $postCount += count($thread['posts'] ?? []);
    }
    $newest = null; $newestTime = 0;
    foreach ($users as $user) {
        $t = strtotime($user['created']);
        if ($t > $newestTime) { $newestTime = $t; $newest = $user['username']; }
    }
    return [
        'members' => count($users), 'threads' => $threadCount,
        'posts' => $postCount, 'newest_member' => $newest,
        'online' => getOnlineCount()
    ];
}

// ==============================================
// REPUTATION / VOTING
// ==============================================
function votePost(string $threadId, string $postId, string $dir): array {
    if (!isLoggedIn()) return ['ok' => false, 'error' => 'Not logged in.'];
    if (!in_array($dir, ['up', 'down'])) return ['ok' => false, 'error' => 'Invalid.'];
    $thread = getThread($threadId);
    if (!$thread) return ['ok' => false, 'error' => 'Thread not found.'];
    foreach ($thread['posts'] as &$post) {
        if ($post['id'] === $postId) {
            if ($post['author'] === currentUser()) return ['ok' => false, 'error' => 'Cannot vote on your own post.'];
            if (!isset($post['votes'])) $post['votes'] = ['up' => [], 'down' => []];
            $user = currentUser();
            $other = $dir === 'up' ? 'down' : 'up';
            // Remove from other direction
            $idx = array_search($user, $post['votes'][$other]);
            if ($idx !== false) array_splice($post['votes'][$other], $idx, 1);
            // Toggle current direction
            $idx = array_search($user, $post['votes'][$dir]);
            if ($idx !== false) { array_splice($post['votes'][$dir], $idx, 1); }
            else { $post['votes'][$dir][] = $user; }
            saveThread($thread);
            checkAndAwardAchievements($post['author']);
            return ['ok' => true];
        }
    }
    return ['ok' => false, 'error' => 'Post not found.'];
}

function getPostScore(array $post): int {
    $v = $post['votes'] ?? ['up' => [], 'down' => []];
    return count($v['up'] ?? []) - count($v['down'] ?? []);
}

function getUserReputation(string $username): int {
    $rep = 0;
    foreach (getAllThreadFiles() as $file) {
        $thread = readJsonFile($file);
        foreach ($thread['posts'] ?? [] as $post) {
            if ($post['author'] === $username) $rep += getPostScore($post);
        }
    }
    $rep += getUserBountyRep($username);
    return $rep;
}

// ==============================================
// ACHIEVEMENTS
// ==============================================
function getAchievementDefs(): array {
    return [
        'first_post' => ['name' => 'First Steps', 'desc' => 'Made your first post', 'icon' => '✍️'],
        'first_thread' => ['name' => 'Starter', 'desc' => 'Created your first thread', 'icon' => '💡'],
        '10_posts' => ['name' => 'Getting Chatty', 'desc' => '10 posts', 'icon' => '💬'],
        '50_posts' => ['name' => 'Regular', 'desc' => '50 posts', 'icon' => '⭐'],
        '100_posts' => ['name' => 'Century Club', 'desc' => '100 posts', 'icon' => '💯'],
        '500_posts' => ['name' => 'Prolific', 'desc' => '500 posts', 'icon' => '🏆'],
        'popular_thread' => ['name' => 'Popular', 'desc' => 'Thread with 10+ replies', 'icon' => '🔥'],
        'viral_thread' => ['name' => 'Viral', 'desc' => 'Thread with 50+ replies', 'icon' => '🚀'],
        'rep_10' => ['name' => 'Trusted', 'desc' => '10+ reputation', 'icon' => '🛡️'],
        'rep_50' => ['name' => 'Respected', 'desc' => '50+ reputation', 'icon' => '👑'],
        'rep_100' => ['name' => 'Famous', 'desc' => '100+ reputation', 'icon' => '🌟'],
    ];
}

function checkAndAwardAchievements(string $username): void {
    $users = readJsonFile(USERS_FILE, []);
    $key = strtolower($username);
    if (!isset($users[$key])) return;
    $earned = $users[$key]['achievements'] ?? [];
    $pc = getUserPostCount($username);
    $tc = getUserThreadCount($username);
    $rep = getUserReputation($username);

    $checks = [
        'first_post' => $pc >= 1,
        'first_thread' => $tc >= 1,
        '10_posts' => $pc >= 10,
        '50_posts' => $pc >= 50,
        '100_posts' => $pc >= 100,
        '500_posts' => $pc >= 500,
        'rep_10' => $rep >= 10,
        'rep_50' => $rep >= 50,
        'rep_100' => $rep >= 100,
    ];
    // Check popular/viral threads
    foreach (getAllThreadFiles() as $file) {
        $thread = readJsonFile($file);
        if (($thread['author'] ?? '') !== $username) continue;
        $replies = max(0, count($thread['posts'] ?? []) - 1);
        if ($replies >= 10) $checks['popular_thread'] = true;
        if ($replies >= 50) $checks['viral_thread'] = true;
    }

    $changed = false;
    foreach ($checks as $id => $met) {
        if ($met && !in_array($id, $earned)) { $earned[] = $id; $changed = true; }
    }
    if ($changed) {
        $users[$key]['achievements'] = $earned;
        writeJsonFile(USERS_FILE, $users);
    }
}

function getUserAchievements(string $username): array {
    $users = readJsonFile(USERS_FILE, []);
    $key = strtolower($username);
    return $users[$key]['achievements'] ?? [];
}

// ==============================================
// SIGNATURES & CUSTOM TITLES
// ==============================================
function getUserSignature(string $username): string {
    $users = readJsonFile(USERS_FILE, []);
    return $users[strtolower($username)]['signature'] ?? '';
}

function setUserSignature(string $signature): bool {
    if (!isLoggedIn()) return false;
    $users = readJsonFile(USERS_FILE, []);
    $key = strtolower(currentUser());
    $users[$key]['signature'] = mb_substr(trim($signature), 0, 300);
    writeJsonFile(USERS_FILE, $users);
    return true;
}

function getUserTitle(string $username): string {
    $users = readJsonFile(USERS_FILE, []);
    return $users[strtolower($username)]['custom_title'] ?? '';
}

function setUserTitle(string $username, string $title): bool {
    $users = readJsonFile(USERS_FILE, []);
    $key = strtolower($username);
    if (!isset($users[$key])) return false;
    $users[$key]['custom_title'] = mb_substr(trim($title), 0, 50);
    writeJsonFile(USERS_FILE, $users);
    return true;
}

// ==============================================
// THREAD PREFIXES
// ==============================================
function getAvailablePrefixes(): array {
    return [
        ['id' => 'discussion', 'name' => 'Discussion', 'color' => '#7aa2ff'],
        ['id' => 'solved', 'name' => 'Solved', 'color' => '#6bffb8'],
        ['id' => 'wip', 'name' => 'WIP', 'color' => '#ffb86b'],
        ['id' => 'closed', 'name' => 'Closed', 'color' => '#ff6b6b'],
        ['id' => 'open', 'name' => 'Open', 'color' => '#6bddff'],
        ['id' => 'help', 'name' => 'Help Needed', 'color' => '#b86bff'],
    ];
}

function getPrefixById(string $id): ?array {
    foreach (getAvailablePrefixes() as $p) { if ($p['id'] === $id) return $p; }
    return null;
}

function prefixHtml(string $prefixId): string {
    $p = getPrefixById($prefixId);
    if (!$p) return '';
    return '<span class="thread-prefix" style="background:' . $p['color'] . '18;color:' . $p['color'] . ';border-color:' . $p['color'] . '30">' . e($p['name']) . '</span>';
}

function setThreadPrefix(string $threadId, string $prefix): bool {
    $thread = getThread($threadId);
    if (!$thread) return false;
    $thread['prefix'] = $prefix;
    saveThread($thread);
    return true;
}

// ==============================================
// GLOBAL STICKIES & THREAD MOVE
// ==============================================
function getGlobalStickies(): array {
    $stickies = [];
    foreach (getAllThreadFiles() as $file) {
        $thread = readJsonFile($file);
        if ($thread['sticky_global'] ?? false) {
            $posts = $thread['posts'] ?? [];
            $lastPost = end($posts);
            $thread['reply_count'] = max(0, count($posts) - 1);
            $thread['last_post_time'] = $lastPost['created'] ?? $thread['created'];
            $thread['last_post_author'] = $lastPost['author'] ?? $thread['author'];
            $stickies[] = $thread;
        }
    }
    return $stickies;
}

function toggleGlobalSticky(string $threadId): bool {
    $thread = getThread($threadId);
    if (!$thread) return false;
    $thread['sticky_global'] = !($thread['sticky_global'] ?? false);
    saveThread($thread);
    return true;
}

function moveThread(string $threadId, string $newCatId): bool {
    if (!getCategoryById($newCatId)) return false;
    $thread = getThread($threadId);
    if (!$thread) return false;
    $oldCat = $thread['category'];
    $thread['category'] = $newCatId;
    saveThread($thread);
    logModAction('move_thread', "Moved \"{$thread['title']}\" from $oldCat to $newCatId");
    return true;
}

// ==============================================
// MOD LOG
// ==============================================
define('MODLOG_FILE', DATA_DIR . '/modlog.json');

function logModAction(string $action, string $details): void {
    $log = readJsonFile(MODLOG_FILE, []);
    $log[] = [
        'id' => bin2hex(random_bytes(6)),
        'action' => $action, 'details' => $details,
        'actor' => currentUser() ?? 'system',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'created' => date('c')
    ];
    if (count($log) > 500) $log = array_slice($log, -500);
    writeJsonFile(MODLOG_FILE, $log);
}

function getModLog(int $limit = 50): array {
    $log = readJsonFile(MODLOG_FILE, []);
    usort($log, fn($a, $b) => strtotime($b['created']) - strtotime($a['created']));
    return array_slice($log, 0, $limit);
}

// ==============================================
// @MENTIONS & NOTIFICATIONS
// ==============================================
define('NOTIFICATIONS_DIR', DATA_DIR . '/notifications');

function processPostMentions(string $content, string $threadId, string $postId, string $threadTitle): void {
    if (!isLoggedIn()) return;
    preg_match_all('/@([a-zA-Z0-9_]+)/', $content, $matches);
    $mentioned = array_unique($matches[1]);
    $users = readJsonFile(USERS_FILE, []);
    foreach ($mentioned as $uname) {
        $key = strtolower($uname);
        if (isset($users[$key]) && strtolower($users[$key]['username']) !== strtolower(currentUser())) {
            addNotification($users[$key]['username'], 'mention', [
                'from' => currentUser(), 'thread_id' => $threadId,
                'post_id' => $postId, 'thread_title' => $threadTitle
            ]);
        }
    }
}

function addNotification(string $username, string $type, array $data): void {
    if (!is_dir(NOTIFICATIONS_DIR)) mkdir(NOTIFICATIONS_DIR, 0755, true);
    $file = NOTIFICATIONS_DIR . '/' . strtolower($username) . '.json';
    $notifs = readJsonFile($file, []);
    $notifs[] = array_merge([
        'id' => bin2hex(random_bytes(6)), 'type' => $type,
        'created' => date('c'), 'read' => false
    ], $data);
    if (count($notifs) > 100) $notifs = array_slice($notifs, -100);
    writeJsonFile($file, $notifs);
}

function getNotifications(int $limit = 20): array {
    if (!isLoggedIn()) return [];
    $file = NOTIFICATIONS_DIR . '/' . strtolower(currentUser()) . '.json';
    $notifs = readJsonFile($file, []);
    usort($notifs, fn($a, $b) => strtotime($b['created']) - strtotime($a['created']));
    return array_slice($notifs, 0, $limit);
}

function getUnreadNotificationCount(): int {
    if (!isLoggedIn()) return 0;
    $file = NOTIFICATIONS_DIR . '/' . strtolower(currentUser()) . '.json';
    $notifs = readJsonFile($file, []);
    return count(array_filter($notifs, fn($n) => !$n['read']));
}

function markNotificationsRead(): void {
    if (!isLoggedIn()) return;
    $file = NOTIFICATIONS_DIR . '/' . strtolower(currentUser()) . '.json';
    $notifs = readJsonFile($file, []);
    $changed = false;
    foreach ($notifs as &$n) {
        if (!$n['read']) { $n['read'] = true; $changed = true; }
    }
    if ($changed) writeJsonFile($file, $notifs);
}

// ==============================================
// LEADERBOARD
// ==============================================
function getLeaderboard(): array {
    $users = readJsonFile(USERS_FILE, []);
    $board = [];
    foreach ($users as $key => $user) {
        $pc = getUserPostCount($user['username']);
        $rep = getUserReputation($user['username']);
        $board[] = [
            'username' => $user['username'], 'role' => $user['role'],
            'post_count' => $pc, 'reputation' => $rep,
            'achievements' => count($user['achievements'] ?? [])
        ];
    }
    return $board;
}

// ==============================================
// SHOUTBOX
// ==============================================
define('SHOUTBOX_FILE', DATA_DIR . '/shoutbox.json');

function getShoutboxMessages(int $limit = 30): array {
    $msgs = readJsonFile(SHOUTBOX_FILE, []);
    return array_slice($msgs, -$limit);
}

function addShoutboxMessage(string $content): array {
    if (!isLoggedIn()) return ['ok' => false, 'error' => 'Not logged in.'];
    $content = trim($content);
    if (strlen($content) < 1 || strlen($content) > 300)
        return ['ok' => false, 'error' => 'Message must be 1-300 characters.'];
    $msgs = readJsonFile(SHOUTBOX_FILE, []);
    $msgs[] = [
        'id' => bin2hex(random_bytes(4)), 'author' => currentUser(),
        'content' => $content, 'created' => date('c')
    ];
    if (count($msgs) > 100) $msgs = array_slice($msgs, -100);
    writeJsonFile(SHOUTBOX_FILE, $msgs);
    return ['ok' => true];
}

// ==============================================
// BOUNTY BOARD
// ==============================================
define('BOUNTIES_FILE', DATA_DIR . '/bounties.json');

function getBountyCategories(): array {
    return [
        ['id' => 'code', 'name' => 'Code', 'icon' => '&#60;/&#62;', 'color' => '#7aa2ff'],
        ['id' => 'crypto', 'name' => 'Crypto/CTF', 'icon' => '&#128274;', 'color' => '#6bffb8'],
        ['id' => 'bug', 'name' => 'Bug Hunt', 'icon' => '&#128027;', 'color' => '#ff6b6b'],
        ['id' => 'design', 'name' => 'Design', 'icon' => '&#127912;', 'color' => '#b86bff'],
        ['id' => 'research', 'name' => 'Research', 'icon' => '&#128269;', 'color' => '#6bddff'],
        ['id' => 'general', 'name' => 'General', 'icon' => '&#9889;', 'color' => '#ffb86b'],
    ];
}

function getBountyCategoryById(string $id): ?array {
    foreach (getBountyCategories() as $c) { if ($c['id'] === $id) return $c; }
    return null;
}

function getBounties(string $filter = 'open'): array {
    $bounties = readJsonFile(BOUNTIES_FILE, []);
    // Expire overdue bounties
    $changed = false;
    foreach ($bounties as &$b) {
        if ($b['status'] === 'open' && !empty($b['deadline']) && strtotime($b['deadline']) < time()) {
            $b['status'] = 'expired';
            $changed = true;
        }
    }
    unset($b);
    if ($changed) writeJsonFile(BOUNTIES_FILE, $bounties);
    if ($filter !== 'all') {
        $bounties = array_filter($bounties, fn($b) => $b['status'] === $filter);
    }
    usort($bounties, fn($a, $b) => strtotime($b['created']) - strtotime($a['created']));
    return array_values($bounties);
}

function getBountyById(string $id): ?array {
    foreach (readJsonFile(BOUNTIES_FILE, []) as $b) {
        if ($b['id'] === $id) return $b;
    }
    return null;
}

function createBounty(string $title, string $description, int $repReward, string $category, string $deadline = ''): array {
    if (!isLoggedIn()) return ['ok' => false, 'error' => 'Not logged in.'];
    $title = trim($title);
    $description = trim($description);
    if (strlen($title) < 5 || strlen($title) > 120) return ['ok' => false, 'error' => 'Title must be 5-120 characters.'];
    if (strlen($description) < 10 || strlen($description) > 5000) return ['ok' => false, 'error' => 'Description must be 10-5000 characters.'];
    if ($repReward < 1 || $repReward > 500) return ['ok' => false, 'error' => 'Reward must be 1-500 rep.'];
    if (!getBountyCategoryById($category)) return ['ok' => false, 'error' => 'Invalid category.'];
    $userRep = getUserReputation(currentUser());
    if ($repReward > $userRep + 50) return ['ok' => false, 'error' => 'Reward exceeds your available reputation.'];

    $bounties = readJsonFile(BOUNTIES_FILE, []);
    $id = bin2hex(random_bytes(8));
    $bounties[] = [
        'id' => $id, 'title' => $title, 'description' => $description,
        'reward' => $repReward, 'category' => $category,
        'author' => currentUser(), 'created' => date('c'),
        'deadline' => $deadline ?: null,
        'status' => 'open', // open, completed, expired, cancelled
        'submissions' => [], 'winner' => null
    ];
    writeJsonFile(BOUNTIES_FILE, $bounties);
    checkAndAwardAchievements(currentUser());
    return ['ok' => true, 'id' => $id];
}

function submitBountySolution(string $bountyId, string $content): array {
    if (!isLoggedIn()) return ['ok' => false, 'error' => 'Not logged in.'];
    $content = trim($content);
    if (strlen($content) < 5 || strlen($content) > 5000) return ['ok' => false, 'error' => 'Solution must be 5-5000 characters.'];
    $bounties = readJsonFile(BOUNTIES_FILE, []);
    foreach ($bounties as &$b) {
        if ($b['id'] === $bountyId) {
            if ($b['status'] !== 'open') return ['ok' => false, 'error' => 'Bounty is no longer open.'];
            if ($b['author'] === currentUser()) return ['ok' => false, 'error' => 'Cannot submit to your own bounty.'];
            foreach ($b['submissions'] as $s) {
                if ($s['author'] === currentUser()) return ['ok' => false, 'error' => 'You already submitted a solution.'];
            }
            $b['submissions'][] = [
                'id' => bin2hex(random_bytes(6)),
                'author' => currentUser(),
                'content' => $content,
                'created' => date('c')
            ];
            writeJsonFile(BOUNTIES_FILE, $bounties);
            addNotification($b['author'], 'bounty_submission', [
                'from' => currentUser(), 'bounty_id' => $bountyId, 'bounty_title' => $b['title']
            ]);
            return ['ok' => true];
        }
    }
    return ['ok' => false, 'error' => 'Bounty not found.'];
}

function awardBounty(string $bountyId, string $submissionId): array {
    if (!isLoggedIn()) return ['ok' => false, 'error' => 'Not logged in.'];
    $bounties = readJsonFile(BOUNTIES_FILE, []);
    foreach ($bounties as &$b) {
        if ($b['id'] === $bountyId) {
            if ($b['author'] !== currentUser() && !isAdmin()) return ['ok' => false, 'error' => 'Only the bounty author can award.'];
            if ($b['status'] !== 'open') return ['ok' => false, 'error' => 'Bounty is no longer open.'];
            $winner = null;
            foreach ($b['submissions'] as $s) {
                if ($s['id'] === $submissionId) { $winner = $s['author']; break; }
            }
            if (!$winner) return ['ok' => false, 'error' => 'Submission not found.'];
            $b['status'] = 'completed';
            $b['winner'] = $winner;
            $b['completed'] = date('c');
            writeJsonFile(BOUNTIES_FILE, $bounties);
            // Award rep to winner by giving upvotes conceptually -- store as bounty rep
            $users = readJsonFile(USERS_FILE, []);
            $wKey = strtolower($winner);
            if (isset($users[$wKey])) {
                $users[$wKey]['bounty_rep'] = ($users[$wKey]['bounty_rep'] ?? 0) + $b['reward'];
                writeJsonFile(USERS_FILE, $users);
            }
            addNotification($winner, 'bounty_awarded', [
                'from' => currentUser(), 'bounty_id' => $bountyId,
                'bounty_title' => $b['title'], 'reward' => $b['reward']
            ]);
            logModAction('bounty', "Awarded bounty \"{$b['title']}\" ({$b['reward']} rep) to $winner");
            checkAndAwardAchievements($winner);
            return ['ok' => true];
        }
    }
    return ['ok' => false, 'error' => 'Bounty not found.'];
}

function cancelBounty(string $bountyId): array {
    if (!isLoggedIn()) return ['ok' => false, 'error' => 'Not logged in.'];
    $bounties = readJsonFile(BOUNTIES_FILE, []);
    foreach ($bounties as &$b) {
        if ($b['id'] === $bountyId) {
            if ($b['author'] !== currentUser() && !isAdmin()) return ['ok' => false, 'error' => 'Permission denied.'];
            if ($b['status'] !== 'open') return ['ok' => false, 'error' => 'Cannot cancel.'];
            $b['status'] = 'cancelled';
            writeJsonFile(BOUNTIES_FILE, $bounties);
            return ['ok' => true];
        }
    }
    return ['ok' => false, 'error' => 'Not found.'];
}

function getUserBountyRep(string $username): int {
    $users = readJsonFile(USERS_FILE, []);
    return $users[strtolower($username)]['bounty_rep'] ?? 0;
}

// ==============================================
// DEAD DROPS
// ==============================================
define('DEADDROPS_FILE', DATA_DIR . '/deaddrops.json');

function createDeadDrop(string $recipient, string $encryptedContent, string $publicNonce, bool $isPublic = false, ?string $expiresHours = null): array {
    if (!isLoggedIn()) return ['ok' => false, 'error' => 'Not logged in.'];
    if (strlen($encryptedContent) < 1 || strlen($encryptedContent) > 20000) return ['ok' => false, 'error' => 'Content too long.'];
    if (!$isPublic) {
        $users = readJsonFile(USERS_FILE, []);
        if (!isset($users[strtolower($recipient)])) return ['ok' => false, 'error' => 'Recipient not found.'];
    }

    $drops = readJsonFile(DEADDROPS_FILE, []);
    $id = bin2hex(random_bytes(8));
    $drop = [
        'id' => $id,
        'encrypted_content' => $encryptedContent,
        'nonce' => $publicNonce,
        'recipient' => $isPublic ? '*' : $recipient,
        'is_public' => $isPublic,
        'created' => date('c'),
        'expires' => $expiresHours ? date('c', time() + ((int)$expiresHours * 3600)) : null,
        'read' => false,
        'read_at' => null,
        'burned' => false // self-destruct after read
    ];
    $drops[] = $drop;
    // Cap at 500 drops
    if (count($drops) > 500) $drops = array_slice($drops, -500);
    writeJsonFile(DEADDROPS_FILE, $drops);

    if (!$isPublic) {
        addNotification($recipient, 'dead_drop', [
            'drop_id' => $id
        ]);
    }
    return ['ok' => true, 'id' => $id];
}

function getDeadDropsForUser(): array {
    if (!isLoggedIn()) return [];
    $drops = readJsonFile(DEADDROPS_FILE, []);
    $me = currentUser();
    $now = time();
    $result = [];
    foreach ($drops as $d) {
        if ($d['burned'] ?? false) continue;
        if ($d['expires'] && strtotime($d['expires']) < $now) continue;
        if ($d['recipient'] === $me || $d['is_public']) {
            $result[] = $d;
        }
    }
    usort($result, fn($a, $b) => strtotime($b['created']) - strtotime($a['created']));
    return $result;
}

function getSentDeadDrops(): array {
    if (!isLoggedIn()) return [];
    // We don't store sender - drops are anonymous. Return nothing.
    return [];
}

function readDeadDrop(string $dropId): ?array {
    if (!isLoggedIn()) return null;
    $drops = readJsonFile(DEADDROPS_FILE, []);
    $changed = false;
    $result = null;
    foreach ($drops as &$d) {
        if ($d['id'] === $dropId) {
            if ($d['burned'] ?? false) return null;
            if (!$d['is_public'] && $d['recipient'] !== currentUser()) return null;
            if ($d['expires'] && strtotime($d['expires']) < time()) return null;
            $result = $d;
            if (!$d['read']) {
                $d['read'] = true;
                $d['read_at'] = date('c');
                $changed = true;
            }
            break;
        }
    }
    unset($d);
    if ($changed) writeJsonFile(DEADDROPS_FILE, $drops);
    return $result;
}

function burnDeadDrop(string $dropId): bool {
    $drops = readJsonFile(DEADDROPS_FILE, []);
    foreach ($drops as &$d) {
        if ($d['id'] === $dropId) {
            if (!$d['is_public'] && $d['recipient'] !== currentUser() && !isAdmin()) return false;
            $d['burned'] = true;
            writeJsonFile(DEADDROPS_FILE, $drops);
            return true;
        }
    }
    return false;
}

function registerPublicKey(string $publicKeyJwk): bool {
    if (!isLoggedIn()) return false;
    $users = readJsonFile(USERS_FILE, []);
    $key = strtolower(currentUser());
    if (!isset($users[$key])) return false;
    $users[$key]['public_key'] = $publicKeyJwk;
    writeJsonFile(USERS_FILE, $users);
    return true;
}

function getUserPublicKey(string $username): ?string {
    $users = readJsonFile(USERS_FILE, []);
    return $users[strtolower($username)]['public_key'] ?? null;
}

// ==============================================
// FORMATTING
// ==============================================
function formatContent(string $text): string {
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // Code blocks
    $text = preg_replace('/```(.*?)```/s', '<pre class="code-block">$1</pre>', $text);
    // Inline code
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    // Bold
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    // Italic
    $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
    // Spoilers
    $text = preg_replace('/\|\|(.+?)\|\|/s', '<span class="spoiler" data-spoiler>$1</span>', $text);

    // YouTube embeds (before general URL linking)
    $text = preg_replace(
        '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/watch\?v=([\w-]{11})(?:[^\s<]*)/',
        '<div class="yt-embed"><iframe src="https://www.youtube-nocookie.com/embed/$1" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe></div>',
        $text
    );
    $text = preg_replace(
        '/(?:https?:\/\/)?youtu\.be\/([\w-]{11})(?:[^\s<]*)/',
        '<div class="yt-embed"><iframe src="https://www.youtube-nocookie.com/embed/$1" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe></div>',
        $text
    );

    // Image embeds ![alt](url) — only allow http/https and relative paths, block data:/javascript:
    $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)]+)\)/', function($m) {
        $alt = $m[1];
        $url = trim($m[2]);
        if (preg_match('/^(https?:\/\/|\/forum\/uploads\/)/', $url)) {
            return '<img class="post-image" src="' . $url . '" alt="' . $alt . '" loading="lazy" data-expandable>';
        }
        return $m[0]; // Leave as-is if URL is suspicious
    }, $text);

    // URLs (not already in href/src)
    $text = preg_replace('/(?<!["\'=>\/])(https?:\/\/[^\s<]+)/', '<a href="$1" target="_blank" rel="noopener">$1</a>', $text);

    // Block quotes (lines starting with >)
    $lines = explode("\n", $text);
    $inQuote = false;
    $out = [];
    foreach ($lines as $line) {
        if (preg_match('/^&gt;\s?(.*)$/', $line, $m)) {
            if (!$inQuote) { $out[] = '<blockquote class="quote-block">'; $inQuote = true; }
            $out[] = $m[1];
        } else {
            if ($inQuote) { $out[] = '</blockquote>'; $inQuote = false; }
            $out[] = $line;
        }
    }
    if ($inQuote) $out[] = '</blockquote>';
    $text = implode("\n", $out);

    // @mentions
    $text = preg_replace('/@([a-zA-Z0-9_]+)/', '<a href="/forum/profile.php?user=$1" class="mention">@$1</a>', $text);

    $text = nl2br($text);
    return $text;
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    if ($diff < 2592000) return floor($diff / 604800) . 'w ago';
    return date('M j, Y', strtotime($datetime));
}

function avatarColor(string $username): string {
    $colors = ['#7aa2ff','#ff6b6b','#6bffb8','#ffb86b','#b86bff','#6bddff','#ff6bb8','#b8ff6b'];
    $hash = crc32(strtolower($username));
    return $colors[abs($hash) % count($colors)];
}

function getAvatarPath(string $username): ?string {
    $key = strtolower($username);
    foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
        $file = AVATARS_DIR . '/' . $key . '.' . $ext;
        if (file_exists($file)) return $key . '.' . $ext;
    }
    return null;
}

function avatarHtml(string $username, int $size = 36): string {
    $avatarFile = getAvatarPath($username);
    if ($avatarFile) {
        return '<img class="avatar" src="/forum/uploads/avatars/' . e($avatarFile) . '?' . filemtime(AVATARS_DIR . '/' . $avatarFile) . '" style="width:' . $size . 'px;height:' . $size . 'px;object-fit:cover;" alt="' . e($username) . '">';
    }
    $color = avatarColor($username);
    $letter = strtoupper($username[0]);
    return '<div class="avatar" style="width:' . $size . 'px;height:' . $size . 'px;background:' . $color . '20;color:' . $color . ';font-size:' . ($size * 0.45) . 'px;line-height:' . $size . 'px;">' . $letter . '</div>';
}

function uploadAvatar(array $file): array {
    if (!isLoggedIn()) return ['ok' => false, 'error' => 'Not logged in.'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed.'];
    }

    if ($file['size'] > 2 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'File too large. Max 2MB.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp'
    ];

    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Invalid file type. Use JPG, PNG, GIF, or WebP.'];
    }

    $ext = $allowed[$mime];
    $key = strtolower(currentUser());

    // Delete old avatars
    foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $oldExt) {
        $old = AVATARS_DIR . '/' . $key . '.' . $oldExt;
        if (file_exists($old)) unlink($old);
    }

    $dest = AVATARS_DIR . '/' . $key . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Failed to save file.'];
    }

    return ['ok' => true];
}

function roleBadge(string $role): string {
    $cls = match($role) {
        'admin' => 'badge-admin',
        'moderator' => 'badge-mod',
        default => ''
    };
    if (!$cls) return '';
    return ' <span class="badge ' . $cls . '">' . $role . '</span>';
}

// ==============================================
// INIT
// ==============================================
function initializeForumData(): void {
    if (!file_exists(CATEGORIES_FILE)) {
        writeJsonFile(CATEGORIES_FILE, [
            ['id' => 'general', 'name' => 'General Discussion', 'description' => 'Talk about anything and everything', 'order' => 0],
            ['id' => 'projects', 'name' => 'Projects & Builds', 'description' => 'Share what you\'re working on', 'order' => 1],
            ['id' => 'gaming', 'name' => 'Gaming', 'description' => 'Gaming discussion, LFG, and clips', 'order' => 2],
            ['id' => 'off-topic', 'name' => 'Off Topic', 'description' => 'Random stuff that doesn\'t fit anywhere else', 'order' => 3]
        ]);
    }

    if (!file_exists(USERS_FILE)) {
        $users = [
            'logansandivar' => [
                'username' => 'LoganSandivar',
                'password_hash' => '',
                'role' => 'admin',
                'created' => date('c'),
                'banned' => false,
                'bio' => 'Developer, Creator, ServerLagger',
                'needs_password' => true
            ]
        ];
        writeJsonFile(USERS_FILE, $users);
    } else {
        // Migrate: if admin exists without needs_password flag and no password was set yet, reset it
        $users = readJsonFile(USERS_FILE, []);
        if (isset($users['logansandivar']) && !isset($users['logansandivar']['needs_password']) && !file_exists(DATA_DIR . '/.password_set')) {
            $users['logansandivar']['password_hash'] = '';
            $users['logansandivar']['needs_password'] = true;
            writeJsonFile(USERS_FILE, $users);
        }
    }

    if (!file_exists(INVITES_FILE)) {
        writeJsonFile(INVITES_FILE, []);
    }
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
