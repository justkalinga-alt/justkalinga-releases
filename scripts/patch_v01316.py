from pathlib import Path

root = Path("build/jk-social-hub")
main = root / "jk-social-hub.php"
sandbox = root / "includes/class-jksh-pinterest-sandbox-v0138.php"

s = main.read_text()
pairs = [
    (" * Version:     0.13.15", " * Version:     0.13.16"),
    ("define( 'JKSH_VERSION', '0.13.15' );", "define( 'JKSH_VERSION', '0.13.16' );"),
    (
        " * Description: Internal social-media operating system for JustKalinga. v0.13.15 embeds Pinterest Sandbox proof controls inside the existing Pinterest connector card so the connector cannot remove them.",
        " * Description: Internal social-media operating system for JustKalinga. v0.13.16 validates the inline Pinterest Sandbox markup inside the existing Pinterest connector card."
    ),
]
for old,new in pairs:
    if old not in s:
        raise SystemExit(f"Missing main pattern: {old}")
    s=s.replace(old,new,1)
main.write_text(s)

s = sandbox.read_text()
old = """<h3 style="margin-top:0;">Pinterest Sandbox <small style="font-size:11px;font-weight:700;color:#667085;margin-left:8px;">Proof Mode</small> <span class="jksh-badge <?php echo ! empty( $ready['connected'] ) ? 'status-connected' : 'status-not_connected'; ?>"><?php echo ! empty( $ready['connected'] ) ? 'Connected' : 'Not connected'; ?></span></h2>"""
new = """<h3 style="margin-top:0;">Pinterest Sandbox <small style="font-size:11px;font-weight:700;color:#667085;margin-left:8px;">Proof Mode</small> <span class="jksh-badge <?php echo ! empty( $ready['connected'] ) ? 'status-connected' : 'status-not_connected'; ?>"><?php echo ! empty( $ready['connected'] ) ? 'Connected' : 'Not connected'; ?></span></h3>"""
if old not in s:
    raise SystemExit("Missing malformed Sandbox heading")
s=s.replace(old,new,1)
sandbox.write_text(s)
