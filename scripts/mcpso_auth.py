import re, time, sys
sys.stdout.reconfigure(encoding='utf-8', errors='replace')
from playwright.sync_api import sync_playwright

BASE = r"C:\Users\fs2\CascadeProjects\salad\AgentExchange\tmp"
SHOTS = BASE + r"\shots"
REPO_URL = "https://github.com/asktheswarm-dev/asktheswarm"
NAME = "AskTheSwarm"

with sync_playwright() as p:
    browser = p.chromium.launch(headless=False,
        executable_path=r"C:\Program Files\BraveSoftware\Brave-Browser\Application\brave.exe",
        args=["--disable-blink-features=AutomationControlled"],
        ignore_default_args=["--enable-automation"])
    ctx = browser.new_context(viewport={"width":1280,"height":900},
        storage_state=BASE + r"\gh_state.json")
    pg = ctx.new_page()
    pg.goto("https://mcp.so/submit?type=server", wait_until="load", timeout=45000)
    time.sleep(3)

    # open sign-in
    si = pg.get_by_role("link", name=re.compile(r"sign in", re.I)).first
    if not (si.count() and si.is_visible()):
        si = pg.get_by_role("button", name=re.compile(r"sign in", re.I)).first
    si.click()
    time.sleep(3)
    pg.screenshot(path=SHOTS + r"\mcpso-signin.png")
    print("[url]", pg.url, flush=True)
    # find OAuth options on page or in dialog
    for i in range(pg.locator("button:visible, a:visible").count()):
        el = pg.locator("button:visible, a:visible").nth(i)
        t = (el.inner_text() or "")[:60].strip()
        if re.search(r"github|google|sign|oauth|continue", t, re.I):
            print("[opt]", repr(t), flush=True)

    # click GitHub option if present
    gh = pg.get_by_role("button", name=re.compile(r"github", re.I)).or_(
         pg.get_by_role("link", name=re.compile(r"github", re.I))).first
    if gh.count() and gh.is_visible():
        gh.click()
        print("[step] clicked GitHub OAuth", flush=True)
        time.sleep(5)
        # GitHub authorize page?
        if "github.com" in pg.url:
            print("[info] on github:", pg.url, flush=True)
            for i in range(30):
                auth = pg.get_by_role("button", name=re.compile(r"authorize", re.I)).first
                if auth.count() and auth.is_visible():
                    auth.click(); print("[step] authorized", flush=True); break
                if "mcp.so" in pg.url:
                    print("[ok] redirected back to mcp.so", flush=True); break
                time.sleep(2)
            time.sleep(5)
        pg.screenshot(path=SHOTS + r"\mcpso-afterauth.png")
        print("[url]", pg.url, flush=True)

    # if back on mcp.so signed in: fill and submit the form
    if "mcp.so" in pg.url:
        pg.goto("https://mcp.so/submit?type=server", wait_until="load", timeout=45000)
        time.sleep(3)
        url_in = pg.locator("input[type='url']").first
        if url_in.count() and url_in.is_visible():
            url_in.click(); url_in.press_sequentially(REPO_URL, delay=30)
            time.sleep(1)
            sub = pg.get_by_role("button", name=re.compile(r"submit a ticket", re.I)).first
            if sub.count() and sub.is_visible():
                sub.click(); print("[step] ticket submitted", flush=True); time.sleep(5)
                # ticket dialog may need title/desc
                dlg = pg.locator("[role='dialog'] textarea, [role='dialog'] input").first
                if dlg.count() and dlg.is_visible():
                    dlg.press_sequentially(
                        "AskTheSwarm — agent-native Q&A network (Stack Overflow for AI agents). "
                        "Remote MCP server: https://asktheswarm.io/mcp — repo: " + REPO_URL, delay=10)
                    snd = pg.get_by_role("button", name=re.compile(r"send|submit|create", re.I)).first
                    if snd.count(): snd.click()
                time.sleep(4)
        pg.screenshot(path=SHOTS + r"\mcpso-done.png")
        print("[final]", pg.url, flush=True)
    ctx.storage_state(path=BASE + r"\gh_state.json")
    time.sleep(10)
    browser.close()
