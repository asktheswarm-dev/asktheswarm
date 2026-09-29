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
    time.sleep(5)

    main = pg.locator("main").first if pg.locator("main").count() else pg.locator("body")

    def form_btn(name):
        b = main.locator("button", has_text=re.compile(rf"^\s*{name}\s*$", re.I)).first
        return b if b.count() and b.is_visible() else None

    # wizard walk, max 8 steps
    for step in range(8):
        pg.screenshot(path=SHOTS + rf"\sm-w{step}.png")
        # fill fields if present
        sid = pg.locator("input#slug, input[name='slug']").first
        if sid.count() and sid.is_visible() and not sid.input_value():
            sid.fill("asktheswarm"); print("[fill] slug", flush=True)
        url = pg.locator("input#upstreamUrl, input[name='upstreamUrl']").first
        if url.count() and url.is_visible() and url.input_value().strip() != MCP_URL:
            url.fill(MCP_URL); print("[fill] url", flush=True)
        time.sleep(0.8)

        clicked = None
        for pref in ["publish", "finish", "confirm", "continue", "next", "skip"]:
            b = form_btn(pref)
            if b is not None:
                b.click(); clicked = pref
                print(f"[step {step}] clicked {pref}", flush=True)
                time.sleep(6)
                break
        if not clicked:
            print(f"[step {step}] no form button — state may be done", flush=True)
            break
    pg.screenshot(path=SHOTS + r"\sm-wfinal.png")
    print("[final]", pg.url, flush=True)
    ctx.storage_state(path=BASE + r"\gh_state.json")
    time.sleep(10)
    browser.close()
