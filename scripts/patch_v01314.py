from pathlib import Path

root = Path("build/jk-social-hub")
main = root / "jk-social-hub.php"
sandbox = root / "includes/class-jksh-pinterest-sandbox-v0138.php"
accounts = root / "includes/class-jksh-accounts-ui-v0133.php"

s = main.read_text()
pairs = [
    (" * Version:     0.13.13", " * Version:     0.13.14"),
    ("define( 'JKSH_VERSION', '0.13.13' );", "define( 'JKSH_VERSION', '0.13.14' );"),
    (
        " * Description: Internal social-media operating system for JustKalinga. v0.13.13 renders Pinterest Sandbox directly from the Accounts renderer, eliminating footer-hook timing entirely.",
        " * Description: Internal social-media operating system for JustKalinga. v0.13.14 force-renders the Pinterest Sandbox card visibly inside the Pinterest tab with deterministic fallback placement."
    ),
]
for old,new in pairs:
    if old not in s:
        raise SystemExit(f"Missing main pattern: {old}")
    s=s.replace(old,new,1)
main.write_text(s)

s = sandbox.read_text()
old = '<section id="jksh-pinterest-sandbox-v0138" class="jksh-card">'
new = '<section id="jksh-pinterest-sandbox-v0138" class="jksh-card jksh-account-card jksh-account-card-wide" data-jksh-sandbox-ui="0.13.14" style="display:block!important;width:100%!important;max-width:100%!important;min-width:0!important;box-sizing:border-box!important;visibility:visible!important;opacity:1!important;">'
if old not in s:
    raise SystemExit("Missing Sandbox section tag")
s=s.replace(old,new,1)

old = '<h2>Pinterest Sandbox <span class="jksh-badge'
new = '<h2>Pinterest Sandbox <small style="font-size:11px;font-weight:700;color:#667085;margin-left:8px;">Sandbox UI v0.13.14</small> <span class="jksh-badge'
if old not in s:
    raise SystemExit("Missing Sandbox heading")
s=s.replace(old,new,1)
sandbox.write_text(s)

s = accounts.read_text()
old = """            const sandboxCard = document.getElementById('jksh-pinterest-sandbox-v0138');
            if (sandboxCard) {
                sandboxCard.classList.add('jksh-account-card','jksh-account-card-wide');
                sandboxCard.style.display = '';
                sandboxCard.style.width = '100%';
                sandboxCard.style.maxWidth = '100%';
                sandboxCard.style.minWidth = '0';
                panelGrid('pinterest').appendChild(sandboxCard);
            }"""
new = """            const sandboxCard = document.getElementById('jksh-pinterest-sandbox-v0138');
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
if old not in s:
    raise SystemExit("Missing Sandbox adoption block")
s=s.replace(old,new,1)
accounts.write_text(s)
