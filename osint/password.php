<?php
// Pwned-password check. All the work happens in the browser (see assets/password.js):
// the password is hashed locally and only a 5-char hash prefix is sent to Have I Been
// Pwned's k-anonymity API. This server never receives the password, its hash, or the
// result — there is no form submit here.
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
osint_head('Passwords · m190 finder', 'password', ['narrow' => true]);
?>
  <div class="os-panel">
    <h2>Is your password in a breach?</h2>
    <p>Type a password to check it against billions of credentials exposed in known breaches. If it appears even once, attackers already have it on their lists.</p>

    <div class="os-inrow" style="margin-top:14px">
      <input type="password" id="os-pw" class="os-input" placeholder="Type a password to check" autocomplete="new-password" autocapitalize="off" autocorrect="off" spellcheck="false">
      <button type="button" id="os-pw-toggle" class="os-btn os-btn-sm">Show</button>
      <button type="button" id="os-pw-check" class="os-btn os-btn-accent">Check</button>
    </div>

    <div id="os-pw-meter" hidden>
      <div class="os-pwbar">
        <div class="os-mini"><div class="os-mini-fill" id="os-pw-mfill"></div></div>
        <span class="os-clprog-lbl" id="os-pw-mlbl"></span>
      </div>
    </div>

    <div id="os-pw-out" class="os-pwres" hidden></div>

    <p class="os-note" style="margin-top:16px"><b>How this stays private:</b> your password is hashed with SHA-1 inside your browser. Only the first <b>5 characters</b> of that hash are sent to the Have I Been Pwned API, which returns every breached hash sharing that prefix; the match is then made <b>on this page</b>. The password, its full hash, and the result never touch this server — there's no submit button that sends anything here.</p>
  </div>

  <div class="os-panel">
    <h3 class="os-h3">If a password shows up</h3>
    <p class="os-dim">Change it everywhere you used it, switch to a unique random password per site (a password manager makes this painless), and turn on two-factor auth. The <a href="/osint/harden.php">hardening checklist</a> walks through it.</p>
    <p class="os-fineprint" style="margin-top:8px">Powered by Have I Been Pwned's Pwned Passwords range API. Requires a modern browser (uses the Web Crypto API over HTTPS).</p>
  </div>
<?php
osint_foot(['password.js']);
