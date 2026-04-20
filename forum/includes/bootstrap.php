<?php
session_start();

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

foreach ([DATA_DIR, THREADS_DIR, AVATARS_DIR, MESSAGES_DIR, POST_IMAGES_DIR] as $dir) {
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

function doLogin(string $username, string $password): array {
    $users = readJsonFile(USERS_FILE, []);
    $key = strtolower(trim($username));

    if (!isset($users[$key])) {
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }

    $user = $users[$key];

    if ($user['needs_password'] ?? false) {
        // First login — no password required, redirect to setup
        session_regenerate_id(true);
        $_SESSION['forum_user'] = $user['username'];
        $_SESSION['needs_password'] = true;
        return ['ok' => true, 'needs_password' => true];
    }

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

function createThread(string $catId, string $title, string $content, array $tags = [], ?array $poll = null): array {
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
    $thread = [
        'id' => $id, 'category' => $catId, 'title' => $title,
        'author' => currentUser(), 'created' => $now,
        'pinned' => false, 'locked' => false, 'tags' => $tags,
        'posts' => [[
            'id' => bin2hex(random_bytes(6)),
            'author' => currentUser(), 'content' => $content,
            'created' => $now, 'reactions' => []
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
    $text = preg_replace('/\|\|(.+?)\|\|/s', '<span class="spoiler" onclick="this.classList.toggle(\'revealed\')">$1</span>', $text);

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

    // Image embeds ![alt](url)
    $text = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '<img class="post-image" src="$2" alt="$1" loading="lazy" onclick="window.open(this.src)">', $text);

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
