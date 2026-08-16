<?php
// Takedown / removal-request templates. Ready-to-send GDPR, CCPA/CPRA, and generic
// broker letters with the user's own identifiers pre-filled, plus Google removal tools.
// Copy is client-side (takedowns.js). Editable so the user can add name/address.
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
$u = osint_current_user();
$p = scan_profile_get((int) $u['id']);

$emails = $p['emails']    ? implode(', ', $p['emails'])    : '[your email address]';
$phones = $p['phones']    ? implode(', ', $p['phones'])    : '[your phone number]';
$users  = $p['usernames'] ? implode(', ', $p['usernames']) : '[your username(s)]';

$gdpr = <<<TXT
Subject: Request for Erasure of Personal Data (GDPR Article 17)

To whom it may concern,

Under Article 17 of the General Data Protection Regulation (GDPR), I request that you erase all personal data you hold about me and cease any further processing of it.

The data to be erased includes, but is not limited to, records associated with:
- Email: {$emails}
- Phone: {$phones}
- Username(s): {$users}
- My full name and current/previous addresses.

Under Article 19, please also notify any third parties with whom you have shared this data. I expect confirmation within one month (Article 12(3)). If you consider an exemption to apply, please cite the specific legal basis.

Regards,
[YOUR FULL NAME]
[YOUR POSTAL ADDRESS]
[DATE]
TXT;

$ccpa = <<<TXT
Subject: CCPA/CPRA Request to Delete and to Opt Out of Sale/Sharing

To whom it may concern,

Under the California Consumer Privacy Act, as amended by the CPRA, I exercise my rights to (1) know, (2) delete all personal information you have collected about me, and (3) opt out of the sale or sharing of my personal information.

My identifiers:
- Email: {$emails}
- Phone: {$phones}
- Username(s): {$users}
- My full name and current/previous addresses.

Please confirm completion within 45 days. Do not require me to create an account to process this request, and apply the deletion to any service providers you have shared my data with.

Regards,
[YOUR FULL NAME]
[VERIFICATION DETAILS IF REQUESTED]
[DATE]
TXT;

$broker = <<<TXT
Subject: Opt-Out / Personal Information Removal Request

Hello,

Please remove my personal information from your website and databases and suppress it from future publication. This concerns any listing associated with:
- Email: {$emails}
- Phone: {$phones}
- My full name and current/previous addresses.

Please confirm once removal is complete. If you need me to identify the exact record, reply and I will send the listing URL. I am making this request as the data subject.

Thank you,
[YOUR FULL NAME]
TXT;

$templates = [
    ['gdpr', 'GDPR — Right to Erasure (Article 17)', 'For any company processing data on people in the EU or UK. The strongest deletion right.', $gdpr],
    ['ccpa', 'CCPA / CPRA — Delete + Do Not Sell', 'For California residents (and useful leverage elsewhere in the US).', $ccpa],
    ['broker', 'Generic broker removal (email)', 'For data brokers or sites with no self-serve form — send to their privacy/abuse address.', $broker],
];
osint_head('Takedowns · m190 finder', 'takedowns', ['narrow' => true]);
?>
  <div class="os-panel">
    <h2>Removal request templates</h2>
    <p>Some sites have no opt-out form and only respond to a written request citing your legal rights. These are ready to send, with your identifiers already filled in — add your name and address, then copy and email them. Keep a dated copy of every request.</p>
  </div>

  <?php foreach ($templates as [$id, $title, $when, $body]): ?>
    <div class="os-panel">
      <h3 class="os-h3"><?= ose($title) ?></h3>
      <p class="os-dim os-mb"><?= ose($when) ?></p>
      <textarea class="os-ta" id="tpl-<?= ose($id) ?>" spellcheck="false"><?= ose($body) ?></textarea>
      <div class="os-copyrow">
        <span class="os-dim" style="font-size:.76rem">Editable — adjust before sending.</span>
        <button type="button" class="os-btn os-btn-sm" data-copy="tpl-<?= ose($id) ?>">Copy to clipboard</button>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="os-panel">
    <h3 class="os-h3">Google Search removal tools</h3>
    <p class="os-dim os-mb">Getting a page deleted at the source doesn't clear it from Google immediately — and some info can be removed from Search directly.</p>
    <div class="os-list">
      <div class="os-row">
        <div class="os-row-main">
          <div class="os-row-t"><a href="https://support.google.com/websearch/answer/12719076" target="_blank" rel="noopener nofollow">Results about you</a></div>
          <div class="os-row-d">Request removal of pages exposing your phone, home address, or email from Google Search results.</div>
        </div>
      </div>
      <div class="os-row">
        <div class="os-row-main">
          <div class="os-row-t"><a href="https://search.google.com/search-console/remove-outdated-content" target="_blank" rel="noopener nofollow">Remove outdated content</a></div>
          <div class="os-row-d">Use once the source page is deleted or changed, to flush Google's cached copy.</div>
        </div>
      </div>
    </div>
  </div>

  <p class="os-fineprint">Tip: send from the email address on the listing where possible, and give them a firm but polite deadline (30 days for GDPR, 45 for CCPA). If ignored, you can escalate to your data-protection authority (EU/UK) or the California AG.</p>
<?php
osint_foot(['takedowns.js']);
