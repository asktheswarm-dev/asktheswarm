import re, time, sys, json, urllib.request
sys.stdout.reconfigure(encoding='utf-8', errors='replace')
from playwright.sync_api import sync_playwright

BASE = r"C:\Users\fs2\CascadeProjects\salad\AgentExchange\tmp"
SHOTS = BASE + r"\shots"
MCP_URL = "https://asktheswarm.io/mcp"
MBX = "https://asktheswarm.io/mailbox?key=ats_adm_5b3f9c2e8d1a7f64&to=devin&limit=8"

def codes(min_ts=0):
    try:
        j = json.loads(urllib.request.urlopen(
            urllib.request.Request(MBX, headers={"User-Agent":"curl"}), timeout=10).read())
        out = []
        for m in j.get("messages", []):
            if "workos" in (m.get("from","")+m.get("subject","")).lower() and m.get("ts",0) >= min_ts:
                out.extend(m.get("codes", []))
        return out
    except Exception as e:
        print("[mbx]", e, flush=True); return []

with sync_playwright() as p:
    browser = p.chromium.launch(headless=False,
        executable_path=r"C:\Program Files\BraveSoftware\Brave-Browser\Application\brave.exe",
        args=["--disable-blink-features=AutomationControlled"],
        ignore_default_args=["--enable-automation"])
    ctx = browser.new_context(viewport={"width":1280,"height":900},
        storage_state=BASE + r"\gh_state.json")
    pg = ctx.new_page()
    pg.goto("https://smithery.ai/new", wait_until="load", timeout=45000)
    time.sleep(4)
    print("[url]", pg.url, flush=True)

    t_start = int(time.time())
    # Step 1: if on WorkOS auth, click "Continue with GitHub"
    if "authk.smithery" in pg.url or "email-verification" not in pg.url:
        gh = pg.get_by_role("button", name=re.compile(r"github", re.I)).or_(
             pg.get_by_role("link", name=re.compile(r"github", re.I))).first
        if gh.count() and gh.is_visible():
            gh.click(); print("[step] github clicked", flush=True)
            time.sleep(5)

    # Step 2: authorize on github if asked
    if "github.com" in pg.url:
        auth = pg.get_by_role("button", name=re.compile(r"authorize", re.I)).first
        if auth.count() and auth.is_visible():
            auth.click(); print("[step] authorized", flush=True); time.sleep(5)

    # Step 3: wait for email-verification page, then enter code
    for i in range(30):
        if "email-verification" in pg.url:
            break
        time.sleep(2)
    print("[url]", pg.url, flush=True)
    if "email-verification" in pg.url:
        # poll mailbox for a fresh workos code (arrives in seconds)
        cd = []
        for i in range(40):
            cd = codes(t_start - 120)
            if cd: break
            time.sleep(3)
        print("[mbx] codes:", cd, flush=True)
        if cd:
            code = cd[-1]
            boxes = pg.locator("input:visible")
            n = boxes.count()
            print("[info] inputs on verify page:", n, flush=True)
            if n >= 6:
                for i, ch in enumerate(code[:n if n<=8 else 6]):
                    boxes.nth(i).fill(ch)
            elif n >= 1:
                boxes.first.click(); boxes.first.fill(code)
            time.sleep(2)
            btn = pg.get_by_role("button", name=re.compile(r"verify|continue|submit", re.I)).first
            if btn.count() and btn.is_visible():
                btn.click()
            time.sleep(5)
            print("[after verify]", pg.url, flush=True)
            pg.screenshot(path=SHOTS + r"\sm-verified.png")

    # Step 4: on smithery /servers/new — enter MCP URL and publish
    for i in range(20):
        if "smithery.ai" in pg.url: break
        time.sleep(2)
    pg.screenshot(path=SHOTS + r"\sm-land.png")
    print("[landed]", pg.url, flush=True)

    url_in = pg.locator("input[type='url']:visible, input[placeholder*='http']:visible, input[placeholder*='url' i]:visible").first
    if url_in.count() and url_in.is_visible():
        url_in.fill(MCP_URL)
        print("[step] mcp url filled", flush=True)
        time.sleep(1.5)
        nxt = pg.get_by_role("button", name=re.compile(r"publish|deploy|continue|next|submit|scan", re.I)).first
        if nxt.count() and nxt.is_visible():
            nxt.click(); print("[step] publish clicked", flush=True); time.sleep(8)
    pg.screenshot(path=SHOTS + r"\sm-final.png")
    print("[final]", pg.url, flush=True)
    ctx.storage_state(path=BASE + r"\gh_state.json")
    time.sleep(10)
    browser.close()
