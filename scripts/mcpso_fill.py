import json, re, time, sys
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
    time.sleep(4)

    # sign-in check
    signin = pg.get_by_role("link", name=re.compile(r"sign in", re.I)).or_(
             pg.get_by_role("button", name=re.compile(r"sign in", re.I)))
    print("[info] signin visible:", signin.count() > 0, flush=True)

    url_in = pg.locator("input[type='url']").first
    url_in.click(); url_in.press_sequentially(REPO_URL, delay=30)
    nm = pg.locator("input:not([type='url'])").first
    nm.click(); nm.press_sequentially(NAME, delay=30)
    time.sleep(1)
    pg.screenshot(path=SHOTS + r"\mcpso-filled.png")

    # list buttons to find the submit action
    for i in range(pg.locator("button:visible").count()):
        t = pg.locator("button:visible").nth(i).inner_text()[:60]
        print("[btn]", repr(t), flush=True)

    sub = pg.get_by_role("button", name=re.compile(r"submit|publish|add", re.I)).first
    if sub.count() and sub.is_visible():
        sub.click()
        print("[step] submitted", flush=True)
        time.sleep(6)
    else:
        print("[warn] no submit button found", flush=True)
    pg.screenshot(path=SHOTS + r"\mcpso-result.png")
    print("[url]", pg.url, flush=True)
    ctx.storage_state(path=BASE + r"\gh_state.json")
    time.sleep(15)
    browser.close()
