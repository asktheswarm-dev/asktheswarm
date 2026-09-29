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
    pg.goto("https://smithery.ai/servers/new", wait_until="load", timeout=45000)
    time.sleep(4)
    print("[url]", pg.url, flush=True)

    # inventory visible inputs
    ins = pg.locator("input:visible")
    for i in range(ins.count()):
        el = ins.nth(i)
        print("  -", "name:", el.get_attribute("name"), "| id:", el.get_attribute("id"),
              "| ph:", el.get_attribute("placeholder"), "| val:", (el.input_value() or "")[:40], flush=True)

    # server-id field = the one with placeholder 'server-id' or empty beside namespace
    sid = pg.locator("input[placeholder*='server' i], input[placeholder*='id' i]").first
    if sid.count() and sid.is_visible():
        sid.fill("asktheswarm")
        print("[step] server-id filled", flush=True)

    # ensure url filled (replace if wrong)
    url_in = pg.locator("input[type='url']:visible, input[placeholder*='http']:visible").first
    if url_in.count() and url_in.is_visible():
        if url_in.input_value().strip() != MCP_URL:
            url_in.fill(MCP_URL)
        print("[ok] url:", url_in.input_value(), flush=True)

    time.sleep(1)
    pg.get_by_role("button", name=re.compile(r"^\s*continue\s*$", re.I)).first.click()
    print("[step] continue clicked", flush=True)
    time.sleep(8)
    pg.screenshot(path=SHOTS + r"\sm-pub1.png")
    print("[url]", pg.url, flush=True)

    # next step page — scan results / confirm / publish
    for i in range(12):
        b = pg.get_by_role("button", name=re.compile(r"publish|deploy|confirm|finish|save", re.I)).first
        if b.count() and b.is_visible():
            b.click(); print("[step]", "clicked", flush=True)
            time.sleep(6)
            break
        time.sleep(3)
    pg.screenshot(path=SHOTS + r"\sm-pub2.png")
    print("[final]", pg.url, flush=True)
    ctx.storage_state(path=BASE + r"\gh_state.json")
    time.sleep(8)
    browser.close()
