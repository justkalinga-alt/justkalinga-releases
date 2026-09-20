from pathlib import Path

root = Path("build/jk-social-hub")
main = root / "jk-social-hub.php"
sandbox = root / "includes/class-jksh-pinterest-sandbox-v0138.php"
accounts = root / "includes/class-jksh-accounts-ui-v0133.php"

s = main.read_text()
pairs = [
    (" * Version:     0.13.14", " * Version:     0.13.15"),
    ("define( 'JKSH_VERSION', '0.13.14' );", "define( 'JKSH_VERSION', '0.13.15' );"),
    (
        " * Description: Internal social-media operating system for JustKalinga. v0.13.14 force-renders the Pinterest Sandbox card visibly inside the Pinterest tab with deterministic fallback placement.",
        " * Description: Internal social-media operating system for JustKalinga. v0.13.15 embeds Pinterest Sandbox proof controls inside the existing Pinterest connector card so the connector cannot remove them."
    ),
]
for old,new in pairs:
    if old not in s:
        raise SystemExit(f"Missing main pattern: {old}")
    s=s.replace(old,new,1)
main.write_text(s)

s = sandbox.read_text()
old = '<section id="jksh-pinterest-sandbox-v0138" class="jksh-card jksh-account-card jksh-account-card-wide" data-jksh-sandbox-ui="0.13.14" style="display:block!important;width:100%!important;max-width:100%!important;min-width:0!important;box-sizing:border-box!important;visibility:visible!important;opacity:1!important;">'
new = '<div id="jksh-pinterest-sandbox-inline-v01315" class="jksh-pinterest-sandbox-inline" data-jksh-sandbox-ui="0.13.15" style="display:block!important;width:100%!important;max-width:100%!important;min-width:0!important;box-sizing:border-box!important;visibility:visible!important;opacity:1!important;border-top:1px solid #e4e7ec;margin-top:24px;padding-top:20px;">'
if old not in s:
    raise SystemExit("Missing v0.13.14 Sandbox section tag")
s=s.replace(old,new,1)

old = '<h2>Pinterest Sandbox <small style="font-size:11px;font-weight:700;color:#667085;margin-left:8px;">Sandbox UI v0.13.14</small> <span class="jksh-badge'
new = '<h3 style="margin-top:0;">Pinterest Sandbox <small style="font-size:11px;font-weight:700;color:#667085;margin-left:8px;">Proof Mode</small> <span class="jksh-badge'
if old not in s:
    raise SystemExit("Missing Sandbox heading")
s=s.replace(old,new,1)

# render_card contains a single closing section for this UI block.
if '        </section>' not in s:
    raise SystemExit("Missing Sandbox closing section")
s=s.replace('        </section>','        </div>',1)

s=s.replace('#jksh-pinterest-sandbox-v0138','#jksh-pinterest-sandbox-inline-v01315')
sandbox.write_text(s)

s = accounts.read_text()

# Keep server-side render call but update its intent comment.
s=s.replace(
"""        // Render Pinterest Sandbox from the Accounts renderer itself so the
        // card is guaranteed to exist before the tab shell adopts cards.""",
"""        // Emit the Sandbox proof controls once; the Accounts script mounts
        // them inside the existing Pinterest connector card after that card mounts."""
)

old = """            // Pinterest Sandbox is intentionally emitted from admin_footer outside .wrap.
            // Adopt it explicitly into the Pinterest tab so its placement does not depend
            // on footer timing or DOM ancestry.
            const sandboxCard = document.getElementById('jksh-pinterest-sandbox-v0138');
            if (sandboxCard) {
                sandboxCard.classList.add('jksh-account-card','jksh-account-card-wide');
                sandboxCard.style.setProperty('display','block','important');
                sandboxCard.style.setProperty('visibility','visible','important');
                sandboxCard.style.setProperty('opacity','1','important');
                sandboxCard.style.setProperty('width','100%','important');
                sandboxCard.style.setProperty('max-width','100%','important');
                sandboxCard.style.setProperty('min-width','0','important');

                const pinterestGrid = panelGrid('pinterest');
                const pinterestPanel = shell.querySelector('[data-account-panel="pinterest"]');
                const target = pinterestGrid || pinterestPanel || wrap;
                target.appendChild(sandboxCard);
            }"""

new = """            // The Pinterest connector deliberately removes competing .jksh-card nodes
            // from its tab. Mount Sandbox as a CHILD of the real Pinterest card instead.
            const mountPinterestSandboxInline = () => {
                const sandbox = document.getElementById('jksh-pinterest-sandbox-inline-v01315');
                const pinterestCard = document.getElementById('jksh-pinterest-foundation-v010');
                if (!sandbox || !pinterestCard) return false;

                sandbox.style.setProperty('display','block','important');
                sandbox.style.setProperty('visibility','visible','important');
                sandbox.style.setProperty('opacity','1','important');
                sandbox.style.setProperty('width','100%','important');
                sandbox.style.setProperty('max-width','100%','important');
                sandbox.style.setProperty('min-width','0','important');

                if (sandbox.parentElement !== pinterestCard) pinterestCard.appendChild(sandbox);
                return true;
            };

            if (!mountPinterestSandboxInline()) {
                let sandboxTries = 0;
                const sandboxTimer = setInterval(() => {
                    sandboxTries++;
                    if (mountPinterestSandboxInline() || sandboxTries > 30) clearInterval(sandboxTimer);
                }, 100);
            }"""

if old not in s:
    raise SystemExit("Missing v0.13.14 Sandbox adoption block")
s=s.replace(old,new,1)
accounts.write_text(s)
