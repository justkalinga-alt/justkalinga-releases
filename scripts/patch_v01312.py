from pathlib import Path

root = Path("build/jk-social-hub")
main = root / "jk-social-hub.php"
sandbox = root / "includes/class-jksh-pinterest-sandbox-v0138.php"
accounts = root / "includes/class-jksh-accounts-ui-v0133.php"

s = main.read_text()
pairs = [
    (" * Version:     0.13.11", " * Version:     0.13.12"),
    ("define( 'JKSH_VERSION', '0.13.11' );", "define( 'JKSH_VERSION', '0.13.12' );"),
    (
        " * Description: Internal social-media operating system for JustKalinga. v0.13.11 fixes Pinterest Sandbox render order so the card mounts after the Accounts shell is built.",
        " * Description: Internal social-media operating system for JustKalinga. v0.13.12 makes the Accounts renderer deterministically adopt the Pinterest Sandbox card into the Pinterest tab."
    ),
]
for old,new in pairs:
    if old not in s:
        raise SystemExit(f"Missing main pattern: {old}")
    s = s.replace(old,new,1)
main.write_text(s)

s = sandbox.read_text()
old = "add_action( 'admin_footer', [ $this, 'admin_footer' ], 1310 );"
new = "add_action( 'admin_footer', [ $this, 'admin_footer' ], 900 );"
if old not in s:
    raise SystemExit("Missing Sandbox footer priority 1310")
s = s.replace(old,new,1)

# Remove the late self-mount script, leaving responsive CSS in place.
start = s.find("        <script>\n        (() => {\n            const mountSandbox = () => {")
if start == -1:
    raise SystemExit("Missing Sandbox self-mount script start")
end_marker = "        </script>\n"
end = s.find(end_marker, start)
if end == -1:
    raise SystemExit("Missing Sandbox self-mount script end")
end += len(end_marker)
s = s[:start] + s[end:]
sandbox.write_text(s)

s = accounts.read_text()
old = """            cards.forEach(card => {
                const heading = (card.querySelector('h2')?.textContent || '').replace(/\\s+/g,' ').trim();
                if (/^Pinterest\\s*\\/\\s*Threads$/i.test(heading)) { card.remove(); return; }
                const bucket = bucketFor(card);
                if (!bucket) return;
                card.classList.add('jksh-account-card');
                if (/^Connected accounts$|^Connected YouTube channel$|^Publishing readiness$/i.test(heading)) card.classList.add('jksh-account-card-wide');
                card.style.display = '';
                panelGrid(bucket).appendChild(card);
            });"""

new = """            cards.forEach(card => {
                const heading = (card.querySelector('h2')?.textContent || '').replace(/\\s+/g,' ').trim();
                if (/^Pinterest\\s*\\/\\s*Threads$/i.test(heading)) { card.remove(); return; }
                const bucket = bucketFor(card);
                if (!bucket) return;
                card.classList.add('jksh-account-card');
                if (/^Connected accounts$|^Connected YouTube channel$|^Publishing readiness$/i.test(heading)) card.classList.add('jksh-account-card-wide');
                card.style.display = '';
                panelGrid(bucket).appendChild(card);
            });

            // Pinterest Sandbox is intentionally emitted from admin_footer outside .wrap.
            // Adopt it explicitly into the Pinterest tab so its placement does not depend
            // on footer timing or DOM ancestry.
            const sandboxCard = document.getElementById('jksh-pinterest-sandbox-v0138');
            if (sandboxCard) {
                sandboxCard.classList.add('jksh-account-card','jksh-account-card-wide');
                sandboxCard.style.display = '';
                sandboxCard.style.width = '100%';
                sandboxCard.style.maxWidth = '100%';
                sandboxCard.style.minWidth = '0';
                panelGrid('pinterest').appendChild(sandboxCard);
            }"""

if old not in s:
    raise SystemExit("Missing Accounts cards loop")
s = s.replace(old,new,1)
accounts.write_text(s)
