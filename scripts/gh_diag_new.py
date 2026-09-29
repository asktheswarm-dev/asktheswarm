import time
from playwright.sync_api import sync_playwright

BASE = r"C:\Users\fs2\CascadeProjects\salad\AgentExchange\tmp"
with sync_playwright() as p:
    browser = p.chromium.launch(headless=False,
        executable_path=r"C:\Program Files\BraveSoftware\Brave-Browser\Application\brave.exe",
        args=["--disable-blink-features=AutomationControlled"],
        ignore_default_args=["--enable-automation"])
    ctx = browser.new_context(viewport={"width":1280,"height":900}, storage_state=BASE + r"\gh_state.json")
    pg = ctx.new_page()
    r = pg.goto("https://github.com/new", wait_until="load", timeout=45000)
    time.sleep(4)
    print("status:", r.status, "| url:", pg.url, "| title:", pg.title())
    ins = pg.locator("input:visible")
    print("visible inputs:", ins.count())
    for i in range(min(ins.count(), 15)):
        el = ins.nth(i)
        print(" -", el.get_attribute("name"), "|", el.get_attribute("id"), "|", el.get_attribute("aria-label"))
    pg.screenshot(path=BASE + r"\shots\new-diag.png")
    time.sleep(3)
    browser.close()
