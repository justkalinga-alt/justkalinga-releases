from pathlib import Path

root = Path("build/jk-social-hub")
main = root / "jk-social-hub.php"
accounts = root / "includes/class-jksh-accounts-ui-v0133.php"

s = main.read_text()
pairs = [
    (" * Version:     0.13.16", " * Version:     0.13.17"),
    ("define( 'JKSH_VERSION', '0.13.16' );", "define( 'JKSH_VERSION', '0.13.17' );"),
    (
        " * Description: Internal social-media operating system for JustKalinga. v0.13.16 validates the inline Pinterest Sandbox markup inside the existing Pinterest connector card.",
        " * Description: Internal social-media operating system for JustKalinga. v0.13.17 marks Pinterest Standard access as pending and locks production publishing until Pinterest approval."
    ),
]
for old,new in pairs:
    if old not in s:
        raise SystemExit(f"Missing main pattern: {old}")
    s=s.replace(old,new,1)

needle = "define( 'JKSH_VERSION', '0.13.17' );"
insert = """define( 'JKSH_VERSION', '0.13.17' );

// JKSH_V01317_PINTEREST_STANDARD_ACCESS_PENDING
// One-time safety migration: the Pinterest app is still under Trial/Sandbox review.
// Keep production Pin publishing locked until Pinterest grants Standard API access.
add_action( 'init', static function () {
    if ( get_option( 'jksh_pinterest_standard_access_pending_v01317' ) ) {
        return;
    }

    $config = get_option( 'jksh_pinterest_connector', [] );
    if ( is_array( $config ) ) {
        $config['standard_access_confirmed'] = false;
        $config['live_publish_enabled'] = false;
        $config['standard_access_status'] = 'pending';
        update_option( 'jksh_pinterest_connector', $config, false );
    }

    update_option( 'jksh_pinterest_standard_access_pending_v01317', gmdate( 'c' ), false );
}, 5 );"""
if needle not in s:
    raise SystemExit("Missing version define after replacement")
s=s.replace(needle,insert,1)
main.write_text(s)

s = accounts.read_text()

anchor = """            if (!mountPinterestSandboxInline()) {
                let sandboxTries = 0;
                const sandboxTimer = setInterval(() => {
                    sandboxTries++;
                    if (mountPinterestSandboxInline() || sandboxTries > 30) clearInterval(sandboxTimer);
                }, 100);
            }"""

patch = """            if (!mountPinterestSandboxInline()) {
                let sandboxTries = 0;
                const sandboxTimer = setInterval(() => {
                    sandboxTries++;
                    if (mountPinterestSandboxInline() || sandboxTries > 30) clearInterval(sandboxTimer);
                }, 100);
            }

            const applyPinterestStandardPending = () => {
                const card = document.getElementById('jksh-pinterest-foundation-v010');
                if (!card) return false;

                const standardInput = card.querySelector('input[name="standard_access_confirmed"]');
                if (standardInput) {
                    standardInput.checked = false;
                    standardInput.disabled = true;
                    const label = standardInput.closest('label');
                    if (label) {
                        label.style.opacity = '0.72';
                        label.title = 'Locked until Pinterest grants Standard API access.';
                    }
                }

                const liveInput = card.querySelector('input[name="live_publish_enabled"]');
                if (liveInput) {
                    liveInput.checked = false;
                    liveInput.disabled = true;
                    const label = liveInput.closest('label');
                    if (label) label.title = 'Production Pinterest publishing is locked pending Standard API approval.';
                }

                card.querySelectorAll('p').forEach((p) => {
                    const text = (p.textContent || '').replace(/\s+/g,' ').trim();
                    if (text.includes('Standard access:')) {
                        p.innerHTML = p.innerHTML.replace(
                            /<strong>Standard access:<\/strong>\s*(Confirmed|Not confirmed)/i,
                            '<strong>Standard access:</strong> <span class="jksh-badge status-attention">Pending Pinterest Approval</span>'
                        );
                    }
                });

                if (!card.querySelector('.jksh-pinterest-standard-pending-note')) {
                    const note = document.createElement('div');
                    note.className = 'jksh-pinterest-standard-pending-note';
                    note.style.cssText = 'margin:16px 0;padding:12px 14px;border:1px solid #f5c451;background:#fff8e6;border-radius:10px;color:#7a4b00;';
                    note.innerHTML = '<strong>Standard API access: Pending Pinterest Approval</strong><br>Production Pin publishing is locked. Pinterest Sandbox image proof publishing remains available for the review/demo workflow.';
                    const sandbox = card.querySelector('#jksh-pinterest-sandbox-inline-v01315');
                    if (sandbox) card.insertBefore(note, sandbox);
                    else card.appendChild(note);
                }

                return true;
            };

            if (!applyPinterestStandardPending()) {
                let pendingTries = 0;
                const pendingTimer = setInterval(() => {
                    pendingTries++;
                    if (applyPinterestStandardPending() || pendingTries > 30) clearInterval(pendingTimer);
                }, 100);
            }"""

if anchor not in s:
    raise SystemExit("Missing Pinterest Sandbox mount anchor in Accounts UI")
s=s.replace(anchor,patch,1)
accounts.write_text(s)
