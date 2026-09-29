import json, re, time, sys
from playwright.sync_api import sync_playwright

BASE = r"C:\Users\fs2\CascadeProjects\salad\AgentExchange\tmp"
CREDS = json.load(open(BASE + r"\gh_creds.json"))

with sync_playwright() as p:
    browser = p.chromium.launch(headless=False,
        executable_path=r"C:\Program Files\BraveSoftware\Brave-Browser\Application\brave.exe",
        args=["--disable-blink-features=AutomationControlled"],
        ignore_default_args=["--enable-automation"])
    ctx = browser.new_context(viewport={"width":1280,"height":900},
        storage_state=BASE + r"\gh_state.json")
    pg = ctx.new_page()
    pg.goto("https://github.com/settings/tokens/new", wait_until="load", timeout=45000)
    time.sleep(3)

    # sudo-mode password confirmation may appear
    pw = pg.locator("input[type='password']").first
    if pw.count() and pw.is_visible():
        pw.click(); pw.press_sequentially(CREDS["password"], delay=45)
        pg.locator("button[type='submit'], input[type='submit']").first.click()
        print("[step] sudo password confirmed", flush=True)
        time.sleep(4)

    if "tokens/new" not in pg.url:
        pg.goto("https://github.com/settings/tokens/new", wait_until="load", timeout=45000)
        time.sleep(3)

    note = pg.locator("input#oauth_access_description, input[name='oauth_access[description]'], input[aria-label*='note' i]").first
    note.click(); note.press_sequentially("devin-push", delay=40)

    # scope: repo (top checkbox group)
    repo = pg.locator("input#oauth_access_scopes_repo, input[value='repo'], input[name='oauth_access[scopes][]'][value='repo']").first
    if repo.count() and not repo.is_checked():
        repo.check()
    time.sleep(1)
    pg.screenshot(path=BASE + r"\shots\pat-form.png")

    gen = pg.get_by_role("button", name=re.compile(r"generate token", re.I)).first
    gen.click()
    print("[step] generate clicked", flush=True)
    time.sleep(5)

    # token appears in .token / code element / input#new-oauth-token
    token = None
    for sel in ["input#new-oauth-token", ".token code", "code.token", "[id*='new-oauth-token']", "input[value^='ghp_']", "td.token code"]:
        el = pg.locator(sel).first
        if el.count():
            try:
                token = el.input_value() if el.get_attribute("value") else el.inner_text()
            except Exception:
                token = el.inner_text()
            if token:
                break
    if not token:
        # fallback: regex the page for ghp_
        m = re.search(r"(ghp_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,})", pg.content())
        if m: token = m.group(1)
    pg.screenshot(path=BASE + r"\shots\pat-result.png")
    if token:
        open(BASE + r"\gh_pat.txt", "w").write(token.strip())
        print("[ok] PAT captured (len %d)" % len(token.strip()), flush=True)
    else:
        print("[fail] token not found on page — see pat-result.png", flush=True)
        print("[url]", pg.url, flush=True)
    ctx.storage_state(path=BASE + r"\gh_state.json")
    time.sleep(8)
    browser.close()
