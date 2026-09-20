from pathlib import Path

root = Path("build/jk-social-hub")
main = root / "jk-social-hub.php"
sandbox = root / "includes/class-jksh-pinterest-sandbox-v0138.php"

s = main.read_text()
pairs = [
    (" * Version:     0.13.10", " * Version:     0.13.11"),
    ("define( 'JKSH_VERSION', '0.13.10' );", "define( 'JKSH_VERSION', '0.13.11' );"),
    (
        " * Description: Internal social-media operating system for JustKalinga. v0.13.10 mounts Pinterest Sandbox cleanly inside the Accounts Pinterest tab with responsive enterprise layout.",
        " * Description: Internal social-media operating system for JustKalinga. v0.13.11 fixes Pinterest Sandbox render order so the card mounts after the Accounts shell is built."
    ),
]
for old,new in pairs:
    if old not in s:
        raise SystemExit(f"Missing main pattern: {old}")
    s = s.replace(old,new,1)
main.write_text(s)

s = sandbox.read_text()
old = "add_action( 'admin_footer', [ $this, 'admin_footer' ], 900 );"
new = "add_action( 'admin_footer', [ $this, 'admin_footer' ], 1310 );"
if old not in s:
    raise SystemExit("Missing Sandbox footer priority")
s = s.replace(old,new,1)
sandbox.write_text(s)
