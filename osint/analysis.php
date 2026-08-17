<?php
// Analysis tab: makes a scan's raw findings legible.
//   - Correlations (SpiderFoot-style): rules that link findings into insights.
//   - Entity graph (Maltego-style): your usernames/emails/domains/phones wired to the
//     accounts and breaches they connect to, drawn as an interactive force graph.
// Everything is derived from the signed-in user's own latest scan — no new lookups.
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
$u = osint_current_user();

$scan = isset($_GET['scan']) ? scan_get((int) $u['id'], (int) $_GET['scan']) : scan_latest((int) $u['id']);
$findings = $scan ? scan_findings((int) $u['id'], (int) $scan['id']) : [];
$profile  = scan_profile_get((int) $u['id']);
$correlations = $findings ? scan_correlations($findings) : [];
$graph = $findings ? scan_graph_data($findings, $profile) : ['nodes' => [], 'edges' => []];

osint_head('Analysis · m190 finder', 'analysis');
?>
  <?php if (!$scan): ?>
    <div class="os-panel"><h2>No scans yet</h2><p>Run a footprint scan from the <a href="/osint/">dashboard</a> — this page then correlates the findings and maps how they connect.</p></div>
  <?php else: ?>
    <div class="os-panel">
      <h2>Analysis</h2>
      <p class="os-dim">Derived from your scan of <?= ose(date('Y-m-d H:i', (int) $scan['started_at'])) ?>. Correlations link related findings into insights; the graph shows how your identifiers connect to what was found.</p>
    </div>

    <div class="os-panel">
      <h3 class="os-h3">Correlations <span class="os-dim">(<?= count($correlations) ?>)</span></h3>
      <p class="os-dim os-mb">Patterns across your findings — the kind of links an investigator (or attacker) would draw. Ranked by how much they matter.</p>
      <?php if (!$correlations): ?>
        <p class="os-dim">No notable correlations. That usually means few reused handles and no password-bearing or recent breaches — good.</p>
      <?php else: ?>
        <div class="os-corr-list">
          <?php foreach ($correlations as $c): ?>
            <div class="os-corr os-corr-<?= ose($c['severity']) ?>">
              <div class="os-corr-h">
                <span class="os-corr-sev"><?= $c['severity'] === 'high' ? 'High' : ($c['severity'] === 'med' ? 'Medium' : 'Low') ?></span>
                <b><?= ose($c['title']) ?></b>
              </div>
              <p class="os-corr-d"><?= ose($c['detail']) ?></p>
              <?php if (!empty($c['items'])): ?>
                <div class="os-taglist">
                  <?php foreach ($c['items'] as $it): ?><span class="os-tag"><?= ose($it) ?></span><?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="os-panel">
      <div class="os-sec-head">
        <h3 class="os-h3">Entity graph <span class="os-dim">(<?= count($graph['nodes']) ?> nodes · <?= count($graph['edges']) ?> links)</span></h3>
        <button type="button" class="os-btn os-btn-sm" id="os-graph-reset">Re-layout</button>
      </div>
      <p class="os-dim os-mb">Drag nodes to explore. Click a node to focus its connections; click an account or breach to open it. Your identifiers are the hubs — the more a node connects, the more it ties your footprint together.</p>
      <div class="os-graph-legend">
        <span><i class="os-gl" data-t="email"></i>Email</span>
        <span><i class="os-gl" data-t="username"></i>Username</span>
        <span><i class="os-gl" data-t="domain"></i>Domain</span>
        <span><i class="os-gl" data-t="phone"></i>Phone</span>
        <span><i class="os-gl" data-t="account"></i>Account</span>
        <span><i class="os-gl" data-t="breach"></i>Breach</span>
      </div>
      <?php if (count($graph['nodes']) < 2): ?>
        <p class="os-dim">Not enough connected findings to graph yet — run a scan with usernames and emails on your profile.</p>
      <?php else: ?>
        <div class="os-graph-wrap">
          <canvas id="os-graph" class="os-graph"></canvas>
          <div id="os-graph-tip" class="os-graph-tip" hidden></div>
        </div>
        <script type="application/json" id="os-graph-data"><?= json_encode($graph, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php
osint_foot(['analysis.js']);
