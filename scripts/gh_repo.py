import json, re, time, sys
from playwright.sync_api import sync_playwright

BASE = r"C:\Users\fs2\CascadeProjects\salad\AgentExchange\tmp"
CREDS = json.load(open(BASE + r"\gh_creds.json"))
REPO = "asktheswarm"
DESC = "Agent-native Q&A network — Stack Overflow for AI agents. MCP, A2A, REST. https://asktheswarm.io"

with sync_playwright() as p:
    browser = p.chromium.launch(
        headless=False,
        executable_path=r"C:\Program Files\BraveSoftware\Brave-Browser\Application\brave.exe",
        args=["--disable-blink-features=AutomationControlled"],
        ignore_default_args=["--enable-automation"],
    )
    ctx = browser.new_context(
        viewport={"width": 1280, "height": 900}, locale="en-US",
        storage_state=BASE + r"\gh_state.json",
    )
    pg = ctx.new_page()
    pg.goto("https://github.com/new", wait_until="load", timeout=45000)
    time.sleep(3)

    if "login" in pg.url:
        print("[fail] session not authenticated — url:", pg.url, flush=True)
        sys.exit(1)

    name = pg.locator("#repository-name-input, input[name='repository[name]'], input#repository_name, input[aria-label*='repository name' i]").first
    name.click(); name.press_sequentially(REPO, delay=50)
    time.sleep(1.5)

    d = pg.locator("#_r_d_, input[name='repository[description]'], input#repository_description, input[name='Description'], input[aria-label*='description' i]").first
    if d.count() and d.is_visible():
        d.click(); d.press_sequentially(DESC, delay=20)

    pub = pg.locator("input[type='radio'][value='public'], input#repository_visibility_public, [role='radio'][value='public']").first
    if pub.count():
        try:
            if not pub.is_checked():
                pub.check()
        except Exception:
            pass

    time.sleep(2)
    pg.screenshot(path=BASE + r"\shots\newrepo.png")
    btn = pg.get_by_role("button", name=re.compile(r"create repository", re.I)).first
    btn.click()
    print("[step] Create repository clicked", flush=True)
    time.sleep(6)
    print("[done] url:", pg.url, flush=True)
    pg.screenshot(path=BASE + r"\shots\repo-created.png")
    ctx.storage_state(path=BASE + r"\gh_state.json")
    time.sleep(10)
    browser.close()
