import json, re, time, sys
from playwright.sync_api import sync_playwright

BASE = r"C:\Users\fs2\CascadeProjects\salad\AgentExchange\tmp"
SHOTS = BASE + r"\shots"
REPO_URL = "https://github.com/asktheswarm-dev/asktheswarm"

with sync_playwright() as p:
    browser = p.chromium.launch(headless=False,
        executable_path=r"C:\Program Files\BraveSoftware\Brave-Browser\Application\brave.exe",
        args=["--disable-blink-features=AutomationControlled"],
        ignore_default_args=["--enable-automation"])
    ctx = browser.new_context(viewport={"width":1280,"height":900},
        storage_state=BASE + r"\gh_state.json")
    pg = ctx.new_page()
    pg.goto("https://mcp.so/submit", wait_until="load", timeout=45000)
    time.sleep(4)
    pg.screenshot(path=SHOTS + r"\mcpso-1.png")
    print("[url]", pg.url, "| title:", pg.title(), flush=True)
    print("[inputs]")
    for i in range(min(pg.locator("input:visible").count(), 12)):
        el = pg.locator("input:visible").nth(i)
        print("  -", el.get_attribute("name"), el.get_attribute("placeholder"), el.get_attribute("type"))
    print("[buttons]")
    for i in range(min(pg.locator("button:visible").count(), 12)):
        print("  -", pg.locator("button:visible").nth(i).inner_text()[:50])
    ctx.storage_state(path=BASE + r"\gh_state.json")
    time.sleep(20)  # keep window open for inspection
    browser.close()
