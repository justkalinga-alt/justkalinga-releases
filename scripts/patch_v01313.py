from pathlib import Path

root = Path("build/jk-social-hub")
main = root / "jk-social-hub.php"
sandbox = root / "includes/class-jksh-pinterest-sandbox-v0138.php"
accounts = root / "includes/class-jksh-accounts-ui-v0133.php"

s = main.read_text()
pairs = [
    (" * Version:     0.13.12", " * Version:     0.13.13"),
    ("define( 'JKSH_VERSION', '0.13.12' );", "define( 'JKSH_VERSION', '0.13.13' );"),
    (
        " * Description: Internal social-media operating system for JustKalinga. v0.13.12 makes the Accounts renderer deterministically adopt the Pinterest Sandbox card into the Pinterest tab.",
        " * Description: Internal social-media operating system for JustKalinga. v0.13.13 renders Pinterest Sandbox directly from the Accounts renderer, eliminating footer-hook timing entirely."
    ),
]
for old,new in pairs:
    if old not in s:
        raise SystemExit(f"Missing main pattern: {old}")
    s = s.replace(old,new,1)
main.write_text(s)

s = sandbox.read_text()

old = "        add_action( 'admin_footer', [ $this, 'admin_footer' ], 900 );\n"
if old not in s:
    raise SystemExit("Missing Sandbox admin_footer hook")
s = s.replace(old, "", 1)

old = "    public function admin_footer() {\n        if ( ! is_admin() || 'jksh-accounts' !== sanitize_key( (string) ( $_GET['page'] ?? '' ) ) ) {\n            return;\n        }\n        $ready = $this->readiness();"
new = "    public function render_card() {\n        $ready = $this->readiness();"
if old not in s:
    raise SystemExit("Missing Sandbox admin_footer method")
s = s.replace(old,new,1)
sandbox.write_text(s)

s = accounts.read_text()

needle = """        $repo = new \\JKSH\\Repositories\\AccountRepository();
        $settings = new \\JKSH\\Services\\Settings();"""
replacement = """        $repo = new \\JKSH\\Repositories\\AccountRepository();
        $settings = new \\JKSH\\Services\\Settings();

        // Render Pinterest Sandbox from the Accounts renderer itself so the
        // card is guaranteed to exist before the tab shell adopts cards.
        if ( class_exists( 'JKSH_Pinterest_Sandbox_V0138' ) ) {
            JKSH_Pinterest_Sandbox_V0138::instance()->render_card();
        }"""
if needle not in s:
    raise SystemExit("Missing Accounts renderer repo/settings block")
s = s.replace(needle,replacement,1)
accounts.write_text(s)
