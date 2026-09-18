#!/usr/bin/env python3
from pathlib import Path
import sys

if len(sys.argv) != 2:
    raise SystemExit("usage: build_jkssb_084_community_oneclick.py <plugin-root>")

p = Path(sys.argv[1]) / "jk-social-supabase-bridge.php"
s = p.read_text()

def one(old, new):
    global s
    if s.count(old) != 1:
        raise SystemExit("target count mismatch: " + old[:100])
    s = s.replace(old, new, 1)

one(" * Version: 0.8.3", " * Version: 0.8.4")
one("const VERSION  = '0.8.3';", "const VERSION  = '0.8.4';")
one(
    '">Prepare + open YouTube</button>',
    '">Prepare media + open YouTube</button>'
)
one(
    "document.querySelectorAll('.jkssb-open-community').forEach(b=>b.onclick=async()=>{await copy(b.dataset.job);window.open(b.dataset.url,'_blank','noopener');b.textContent='Text copied · YouTube opened ✓';});",
    "document.querySelectorAll('.jkssb-open-community').forEach(b=>b.onclick=async()=>{const w=window.open('about:blank','_blank');await copy(b.dataset.job);b.textContent='Preparing media…';await download(b.dataset.job);if(w){w.opener=null;w.location=b.dataset.url;}else{window.open(b.dataset.url,'_blank','noopener');}b.textContent='Text + media ready · YouTube opened ✓';});"
)
p.write_text(s)
print("Bridge 0.8.4 one-click Community handoff patch applied")
