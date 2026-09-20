from pathlib import Path

root = Path("build/jk-social-hub")
main = root / "jk-social-hub.php"
sandbox = root / "includes/class-jksh-pinterest-sandbox-v0138.php"

s = main.read_text()
pairs = [
    (" * Version:     0.13.9", " * Version:     0.13.10"),
    ("define( 'JKSH_VERSION', '0.13.9' );", "define( 'JKSH_VERSION', '0.13.10' );"),
    (
        " * Description: Internal social-media operating system for JustKalinga. v0.13.9 fixes Pinterest Sandbox REST permission callbacks while preserving the encrypted proof lane.",
        " * Description: Internal social-media operating system for JustKalinga. v0.13.10 mounts Pinterest Sandbox cleanly inside the Accounts Pinterest tab with responsive enterprise layout."
    ),
]
for old, new in pairs:
    if old not in s:
        raise SystemExit(f"Missing main pattern: {old}")
    s = s.replace(old, new, 1)
main.write_text(s)

s = sandbox.read_text()
old = """        </section>
        <?php
    }"""

new = r"""        </section>
        <style>
        #jksh-pinterest-sandbox-v0138{
            width:100%;
            max-width:100%;
            min-width:0;
            box-sizing:border-box;
            overflow:visible;
        }
        #jksh-pinterest-sandbox-v0138 *{box-sizing:border-box}
        #jksh-pinterest-sandbox-v0138 p,
        #jksh-pinterest-sandbox-v0138 td,
        #jksh-pinterest-sandbox-v0138 th{overflow-wrap:anywhere;word-break:normal}
        #jksh-pinterest-sandbox-v0138 .jksh-form{
            display:grid;
            gap:12px;
            width:100%;
            max-width:760px;
            min-width:0;
        }
        #jksh-pinterest-sandbox-v0138 .jksh-form label{display:grid;gap:6px;min-width:0}
        #jksh-pinterest-sandbox-v0138 input[type=password],
        #jksh-pinterest-sandbox-v0138 input[type=text],
        #jksh-pinterest-sandbox-v0138 select,
        #jksh-pinterest-sandbox-v0138 textarea{
            width:100%;
            max-width:100%;
            min-width:0;
        }
        #jksh-pinterest-sandbox-v0138 table{
            width:100%;
            max-width:100%;
            table-layout:fixed;
        }
        .jksh-account-panel[data-account-panel="pinterest"],
        .jksh-account-panel[data-account-panel="pinterest"] .jksh-account-panel-grid{
            min-width:0;
            width:100%;
            max-width:100%;
        }
        @media(max-width:782px){
            #jksh-pinterest-sandbox-v0138{padding:16px!important}
            #jksh-pinterest-sandbox-v0138 .button{width:100%;justify-content:center}
        }
        </style>
        <script>
        (() => {
            const mountSandbox = () => {
                const card = document.getElementById('jksh-pinterest-sandbox-v0138');
                const grid = document.querySelector('.jksh-account-panel[data-account-panel="pinterest"] .jksh-account-panel-grid');
                if (!card || !grid) return false;
                if (card.parentElement !== grid) grid.appendChild(card);
                card.classList.add('jksh-account-card-wide');
                card.style.display = 'block';
                card.style.width = '100%';
                card.style.maxWidth = '100%';
                card.style.minWidth = '0';
                return true;
            };
            document.addEventListener('DOMContentLoaded', mountSandbox, {once:true});
            setTimeout(mountSandbox, 0);
            setTimeout(mountSandbox, 120);
        })();
        </script>
        <?php
    }"""

if old not in s:
    raise SystemExit("Missing Sandbox admin_footer closing block")
s = s.replace(old, new, 1)
sandbox.write_text(s)
