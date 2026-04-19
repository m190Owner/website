<?php
session_start();

// ---- Paths ----
define('FORUM_ROOT', dirname(__DIR__));
define('DATA_DIR', FORUM_ROOT . '/data');
define('THREADS_DIR', DATA_DIR . '/threads');
define('USERS_FILE', DATA_DIR . '/users.json');
define('CATEGORIES_FILE', DATA_DIR . '/categories.json');
define('INVITES_FILE', DATA_DIR . '/invites.json');

require_once dirname(FORUM_ROOT) . '/config.php';

foreach ([DATA_DIR, THREADS_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

initializeForumData();

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

function doLogin(string $username, string $password): array {
    $users = readJsonFile(USERS_FILE, []);
    $key = strtolower(trim($username));

    if (!isset($users[$key])) {
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }

    $user = $users[$key];
    if (!password_verify($password, $user['password_hash'])) {
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }

    if ($user['banned'] ?? false) {
        return ['ok' => false, 'error' => 'This account has been banned.'];
    }

    session_regenerate_id(true);
    $_SESSION['forum_user'] = $user['username'];
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
    if (strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    }

    $invites = readJsonFile(INVITES_FILE, []);
    $inviteCode = strtoupper(trim($inviteCode));
    $validInvite = false;
    $inviteIndex = -1;

    foreach ($invites as $i => $inv) {
        if ($inv['code'] === $inviteCode && !$inv['used']) {
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

function createThread(string $catId, string $title, string $content): array {
    if (!isLoggedIn()) return ['ok' => false, 'error' => 'Not logged in.'];
    $title = trim($title);
    $content = trim($content);
    if (strlen($title) < 3 || strlen($title) > 120) {
        return ['ok' => false, 'error' => 'Title must be 3-120 characters.'];
    }
    if (strlen($content) < 1 || strlen($content) > 10000) {
        return ['ok' => false, 'error' => 'Content must be 1-10000 characters.'];
    }
    if (!getCategoryById($catId)) {
        return ['ok' => false, 'error' => 'Invalid category.'];
    }

    $id = bin2hex(random_bytes(8));
    $now = date('c');
    $thread = [
        'id' => $id,
        'category' => $catId,
        'title' => $title,
        'author' => currentUser(),
        'created' => $now,
        'pinned' => false,
        'locked' => false,
        'posts' => [
            [
                'id' => bin2hex(random_bytes(6)),
                'author' => currentUser(),
                'content' => $content,
                'created' => $now
            ]
        ]
    ];
    saveThread($thread);
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

    $thread['posts'][] = [
        'id' => bin2hex(random_bytes(6)),
        'author' => currentUser(),
        'content' => $content,
        'created' => date('c')
    ];
    saveThread($thread);
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

function addCategory(string $name, string $description): bool {
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
    $cats[] = [
        'id' => $id,
        'name' => trim($name),
        'description' => trim($description),
        'order' => $maxOrder + 1
    ];
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
// FORMATTING
// ==============================================
function formatContent(string $text): string {
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // Code blocks ``` ... ```
    $text = preg_replace('/```(.*?)```/s', '<pre class="code-block">$1</pre>', $text);
    // Inline code
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    // Bold
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    // Italic
    $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
    // URLs
    $text = preg_replace('/(https?:\/\/[^\s<]+)/', '<a href="$1" target="_blank" rel="noopener">$1</a>', $text);
    // Line breaks
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

function avatarHtml(string $username, int $size = 36): string {
    $color = avatarColor($username);
    $letter = strtoupper($username[0]);
    return '<div class="avatar" style="width:' . $size . 'px;height:' . $size . 'px;background:' . $color . '20;color:' . $color . ';font-size:' . ($size * 0.45) . 'px;line-height:' . $size . 'px;">' . $letter . '</div>';
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
        $password = bin2hex(random_bytes(8));
        $users = [
            'logansandivar' => [
                'username' => 'LoganSandivar',
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'admin',
                'created' => date('c'),
                'banned' => false,
                'bio' => 'Developer, Creator, ServerLagger'
            ]
        ];
        writeJsonFile(USERS_FILE, $users);
        file_put_contents(
            DATA_DIR . '/.admin_credentials',
            "=== FORUM ADMIN CREDENTIALS ===\n" .
            "Username: LoganSandivar\n" .
            "Password: $password\n" .
            "DELETE THIS FILE AFTER READING.\n",
            LOCK_EX
        );
    }

    if (!file_exists(INVITES_FILE)) {
        writeJsonFile(INVITES_FILE, []);
    }
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
