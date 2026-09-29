import re, time, sys
sys.stdout.reconfigure(encoding='utf-8', errors='replace')
from playwright.sync_api import sync_playwright

BASE = r"C:\Users\fs2\CascadeProjects\salad\AgentExchange\tmp"
SHOTS = BASE + r"\shots"

REPO_URL = "https://github.com/asktheswarm-dev/asktheswarm"
NAME = "AskTheSwarm"
SUBJECT = "New remote server listing: AskTheSwarm"
DESC = ("Please list AskTheSwarm — an agent-native Q&A network (Stack Overflow for AI agents).\n\n"
        "Type: Remote Server\n"
        "Repository: https://github.com/asktheswarm-dev/asktheswarm\n"
        "MCP endpoint: https://asktheswarm.io/mcp (streamable HTTP)\n"
        "Official MCP registry: io.asktheswarm/asktheswarm v1.0.1\n\n"
        "Agents self-register via the swarm_register tool, then ask/answer/vote/comment and earn "
        "reputation. Humans browse and upvote at https://asktheswarm.io")

def set_if_needed(el, val, label):
    cur = el.input_value()
    if cur.strip() == val:
        print(f"[skip] {label} already correct", flush=True); return
    el.click(); el.fill(val)   # fill() replaces — never appends
    print(f"[set] {label}: {val[:50]}", flush=True)

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

    # main form: URL field (type=url) + name field — ONLY these two, by type
    url_in = pg.locator("input[type='url']:visible").first
    if url_in.count(): set_if_needed(url_in, REPO_URL, "repo-url")
    name_in = pg.locator("form input:not([type='url']):visible, [class*='submit'] input:not([type='url']):visible").first
    if name_in.count(): set_if_needed(name_in, NAME, "project-name")
    pg.screenshot(path=SHOTS + r"\m1.png")

    # ticket dialog
    pg.get_by_role("button", name=re.compile(r"submit a ticket", re.I)).first.click()
    time.sleep(2.5)
    dlg = pg.locator("[role='dialog']").first
    subj = dlg.locator("input:visible").first
    desc = dlg.locator("textarea:visible").first
    set_if_needed(subj, SUBJECT, "subject")
    set_if_needed(desc, DESC, "description")
    pg.screenshot(path=SHOTS + r"\m2.png")
    dlg.get_by_role("button", name=re.compile(r"^\s*submit\s*$", re.I)).first.click()
    print("[step] submitted", flush=True)
    time.sleep(5)
    pg.screenshot(path=SHOTS + r"\m3.png")
    print("[final]", pg.url, flush=True)
    ctx.storage_state(path=BASE + r"\gh_state.json")
    time.sleep(10)
    browser.close()
