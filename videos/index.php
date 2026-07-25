<?php
require __DIR__ . '/lib/bootstrap.php';

$q    = trim($_GET['q'] ?? '');
$sort = ($_GET['sort'] ?? '') === 'popular' ? 'popular' : 'recent';
$order = $sort === 'popular' ? 'v.views DESC, v.created_at DESC' : 'v.created_at DESC';

$db = videos_db();
if ($q !== '') {
    $like = '%' . like_escape($q) . '%';
    $st = $db->prepare(
        "SELECT v.*, u.username
           FROM videos v JOIN users u ON u.id = v.user_id
          WHERE v.status = 'live'
            AND (v.title LIKE :q ESCAPE '\\' OR v.description LIKE :q ESCAPE '\\' OR u.username LIKE :q ESCAPE '\\')
          ORDER BY $order LIMIT 60"
    );
    $st->execute([':q' => $like]);
} else {
    $st = $db->query(
        "SELECT v.*, u.username
           FROM videos v JOIN users u ON u.id = v.user_id
          WHERE v.status = 'live'
          ORDER BY $order LIMIT 60"
    );
}
$rows = $st->fetchAll();

render_header($q !== '' ? "Search: $q" : 'Home');
?>
<div class="v-feed-head">
  <h1><?= $q !== '' ? 'Results for “' . e($q) . '”' : 'Latest videos' ?></h1>
  <div class="v-sort">
    <a class="<?= $sort === 'recent' ? 'on' : '' ?>"  href="/videos/index.php?<?= http_build_query(array_filter(['q' => $q, 'sort' => 'recent'])) ?>">Newest</a>
    <a class="<?= $sort === 'popular' ? 'on' : '' ?>" href="/videos/index.php?<?= http_build_query(array_filter(['q' => $q, 'sort' => 'popular'])) ?>">Most viewed</a>
  </div>
</div>
<?php
video_grid($rows, $q !== '' ? 'No videos matched your search.' : 'No videos yet — be the first to upload!');
render_footer();
