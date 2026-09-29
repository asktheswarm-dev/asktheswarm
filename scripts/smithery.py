import re, time, sys
sys.stdout.reconfigure(encoding='utf-8', errors='replace')
from playwright.sync_api import sync_playwright

BASE = r"C:\Users\fs2\CascadeProjects\salad\AgentExchange\tmp"
SHOTS = BASE + r"\shots"
MCP_URL = "https://asktheswarm.io/mcp"

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
    pg.screenshot(path=SHOTS + r"\sm1.png")

    # if a sign-in wall: find github login
    for i in range(pg.locator("button:visible, a:visible").count()):
        t = (pg.locator("button:visible, a:visible").nth(i).inner_text() or "").strip()[:70]
        if re.search(r"github|google|sign|continue|deploy|publish", t, re.I):
            print("[opt]", repr(t), flush=True)

    gh = pg.get_by_role("button", name=re.compile(r"github", re.I)).or_(
         pg.get_by_role("link", name=re.compile(r"github", re.I))).first
    if gh.count() and gh.is_visible():
        gh.click(); print("[step] GitHub OAuth clicked", flush=True)
        for i in range(30):
            time.sleep(2)
            if "github.com" in pg.url:
                auth = pg.get_by_role("button", name=re.compile(r"authorize", re.I)).first
                if auth.count() and auth.is_visible():
                    auth.click(); print("[step] authorized", flush=True)
            if "smithery.ai" in pg.url:
                break
        time.sleep(4)
        print("[url]", pg.url, flush=True)
        pg.screenshot(path=SHOTS + r"\sm2.png")

    # URL publish flow: find url input
    if "smithery.ai" in pg.url:
        url_in = pg.locator("input[type='url']:visible, input[placeholder*='http']:visible, input[placeholder*='url' i]:visible").first
        if url_in.count() and url_in.is_visible():
            url_in.click(); url_in.fill(MCP_URL)
            print("[step] mcp url entered", flush=True)
            time.sleep(1.5)
            nxt = pg.get_by_role("button", name=re.compile(r"publish|deploy|continue|next|submit", re.I)).first
            if nxt.count() and nxt.is_visible():
                nxt.click(); print("[step] publish clicked", flush=True); time.sleep(6)
        pg.screenshot(path=SHOTS + r"\sm3.png")
        print("[final]", pg.url, flush=True)
    ctx.storage_state(path=BASE + r"\gh_state.json")
    time.sleep(10)
    browser.close()
