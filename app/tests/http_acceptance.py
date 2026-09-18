#!/usr/bin/env python3
"""Real HTTP regression suite. Run against only the named disposable Compose app-test service."""
import urllib.request, urllib.error, http.cookiejar, urllib.parse, json, re, concurrent.futures, time
import ssl
from pathlib import Path
from html.parser import HTMLParser

BASE = "https://127.0.0.1:8444"
TLS = ssl.create_default_context(
    cafile=str(Path(__file__).resolve().parents[2] / "docker/rootfs/etc/nginx/ssl/ca.pem")
)


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPSHandler(context=TLS), urllib.request.HTTPCookieProcessor(self.jar)
        )

    def request(self, path, method="GET", data=None, headers=None):
        headers = headers or {}
        if isinstance(data, dict):
            data = json.dumps(data).encode()
            headers = {"Content-Type": "application/json", "Accept": "application/json", **headers}
        req = urllib.request.Request(BASE + path, data=data, method=method, headers=headers)
        try:
            r = self.opener.open(req, timeout=20)
        except urllib.error.HTTPError as e:
            r = e
        return r.status, r.read(), r.headers

    def page(self, path):
        status, body, _ = self.request(path)
        assert status == 200, (path, status, body[:500])
        return body.decode()

    def csrf(self, path="/projects/1"):
        body = self.page(path)
        return re.search(r'name="_csrf" value="([^"]+)"', body)[1]

    def login(self, email):
        token = self.csrf("/login")
        data = urllib.parse.urlencode(
            {"_csrf": token, "email": email, "password": "Nafinity-Demo-2026!"}
        ).encode()
        status, body, _ = self.request(
            "/login", "POST", data, {"Content-Type": "application/x-www-form-urlencoded"}
        )
        assert status == 200, (status, body[:300])
        return body.decode()

    def post(self, path, data, token=None):
        return self.request(path, "POST", data, {"X-CSRF-Token": token or self.csrf()})


def ok(condition, message):
    assert condition, message
    results.append(message)


results = []
alice = Client()
bob = Client()
viewer = Client()
alice2 = Client()
assert "Nafinity" in alice.login("alice@example.test")
ok(any(cookie.secure for cookie in alice.jar), "HTTPS session uses a Secure cookie")
assert "Studio Nord" in bob.login("bob@example.test")
viewer.login("viewer@example.test")
alice2.login("alice@example.test")
ok("/projects/2" not in alice.page("/projects"), "Alice list excludes Bob project")
for path in [
    "/projects/1",
    "/projects/1/settings",
    "/projects/1/activity",
    "/projects/1/state",
    "/projects/1/tickets/NAF-1",
]:
    ok(bob.request(path)[0] == 404, "Bob denied " + path)
# Stable CSRF across two tabs; fake Bearer and malformed CSRF cannot bypass checks.
token = alice.csrf()
ok(token == alice.csrf("/projects/1/tickets/NAF-1"), "CSRF stable across tabs")
for method, headers, data in [
    ("POST", {}, {}),
    ("PATCH", {"Authorization": "Bearer invented"}, {}),
    ("POST", {}, {"_csrf": [token]}),
]:
    status, _, _ = alice.request("/projects/1/tickets/NAF-1", method, data, headers)
    ok(
        status == 400,
        "CSRF rejects " + method + " " + ("Bearer" if headers else "malformed/missing token"),
    )
board = alice.page("/projects/1")
column = int(re.search(r'data-column="(\d+)"', board)[1])
lane = int(re.search(r'data-lane="(\d+)"', board)[1])
rev = json.loads(alice.request("/projects/1/state")[1])["revision"]
payload = {
    "title": "HTTP acceptance <script>alert(1)</script>",
    "description": "HTTP roundtrip sunflower",
    "priority": "normal",
    "column_id": column,
    "swimlane_id": lane,
    "board_revision": rev,
    "assignee_ids": [1],
    "label_ids": [1],
}
status, body, _ = alice.post("/projects/1/tickets", payload, token)
ok(status == 200, "JSON ticket creation")
ticket = json.loads(body)["id"]
url = "/projects/1/tickets/" + ticket
page = alice.page(url)
ok(
    "&lt;script&gt;alert(1)&lt;/script&gt;" in page and "<script>alert(1)</script>" not in page,
    "stored title safely escaped",
)
# Simultaneous requests from two independent authenticated sessions with the same revisions.
version = int(re.search(r'name="version" value="(\d+)"', page)[1])
rev = int(re.search(r'name="board_revision" value="(\d+)"', page)[1])
move = {
    "version": version,
    "board_revision": rev,
    "column_id": column,
    "swimlane_id": lane,
    "placement": "append",
}
token2 = alice2.csrf()
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    futures = [
        pool.submit(client.post, url + "/move", move, t)
        for client, t in [(alice, token), (alice2, token2)]
    ]
    statuses = sorted(f.result()[0] for f in futures)
ok(statuses == [200, 409], "simultaneous moves accept one and conflict one")
for path, data in [
    (url + "/move", move),
    (url + "/state", {"version": version, "action": "archive"}),
    (url + "/comments", {"body": "forbidden"}),
    ("/projects/1/structure", {"kind": "label", "name": "no"}),
    ("/projects/1/members", {"email": "bob@example.test", "role": "owner"}),
]:
    ok(viewer.post(path, data, viewer.csrf())[0] == 403, "Viewer write denied " + path)
# Multipart upload followed by streamed download and unauthorized direct access.
boundary = "nafinity-test-boundary"
content = b"Private upload bytes over HTTP.\n"
multipart = (
    (
        f'--{boundary}\r\nContent-Disposition: form-data; name="_csrf"\r\n\r\n{token}\r\n--{boundary}\r\nContent-Disposition: form-data; name="attachment"; filename="acceptance.txt"\r\nContent-Type: text/plain\r\n\r\n'
    ).encode()
    + content
    + f"\r\n--{boundary}--\r\n".encode()
)
status, body, _ = alice.request(
    url + "/attachments",
    "POST",
    multipart,
    {"Content-Type": "multipart/form-data; boundary=" + boundary, "Accept": "application/json"},
)
ok(status == 200, "multipart upload accepted")
page = alice.page(url)
download = re.search(r'href="([^"]+/attachments/\d+)"', page)[1]
status, body, headers = alice.request(download)
ok(
    status == 200
    and body == content
    and headers.get("Content-Disposition", "").startswith("attachment;"),
    "private streamed download exact bytes and headers",
)
ok(bob.request(download)[0] == 404, "Bob direct download denied")
ok(
    bob.post(download + "/delete", {}, bob.csrf("/projects/2"))[0] == 404,
    "Bob attachment deletion denied",
)
status, _, _ = alice.post(download + "/delete", {}, token)
ok(status == 200 and alice.request(download)[0] == 404, "authorized attachment deletion completed")
# Per-account limiter remains effective across separate cookie jars (fresh synthetic email).
for attempt in range(11):
    status, _, headers = alice.request(
        "/login",
        "POST",
        urllib.parse.urlencode(
            {"_csrf": token, "email": "rate-limit@example.test", "password": "wrong-password"}
        ).encode(),
        {"Content-Type": "application/x-www-form-urlencoded"},
    )
ok(status == 429 and int(headers["Retry-After"]) > 0, "login limit returns 429 and Retry-After")
# Private resources have no web route.
for path in ["/storage/attachments/test", "/.env", "/composer.json", "/vendor/autoload.php"]:
    ok(alice.request(path)[0] in [403, 404], "webroot protects " + path)
# Settings and local AI share the native session, CSRF and project policy.
settings = alice.page("/projects/1/settings")
ok(
    all(
        f'data-settings-open="{card}"' in settings
        for card in ["personal", "ai", "general", "roles", "users", "column", "swimlane", "label"]
    ),
    "Owner settings expose all eight cards",
)
viewer_settings = viewer.page("/projects/1/settings")
ok(
    'data-settings-open="roles"' in viewer_settings
    and 'data-settings-open="users"' not in viewer_settings,
    "Viewer settings show role information without member administration",
)
ok("Lokale AI" in alice.page("/preferences"), "Personal settings include local AI")
ok(Client().request("/ai/tools?project=1")[0] == 401, "AI requires a session")
ok(bob.request("/ai/tools?project=1")[0] == 404, "AI tools do not leak foreign projects")
ok(
    alice.request(
        "/ai/tools/call", "POST", {"project": 1, "name": "nafinity_board", "arguments": {}}
    )[0]
    == 400,
    "AI calls require CSRF",
)
status, body, _ = alice.post(
    "/projects/1/roles",
    {"name": "HTTP Redaktion", "description": "Only comments", "permissions": ["comment"]},
)
ok(status == 200, "Custom role can be created through HTTP")
settings = alice.page("/projects/1/settings")
role_section = settings.split('data-settings-content="roles"', 1)[1]
role_id = int(re.search(r'name="id" value="([0-9]+)"', role_section)[1])
ok(
    alice.post(
        "/projects/1/members", {"email": "viewer@example.test", "role": f"custom:{role_id}"}
    )[0]
    == 200,
    "Custom role can be assigned through HTTP",
)
status, body, _ = viewer.request("/ai/tools?project=1")
names = [tool["name"] for tool in json.loads(body)["tools"]]
ok(
    "nafinity_comment" in names and "nafinity_ticket_create" not in names,
    "AI catalog follows custom role permissions",
)
ok(
    alice.post(
        "/projects/1/roles",
        {
            "id": role_id,
            "version": 1,
            "name": "HTTP Redaktion",
            "description": "Read only",
            "permissions": [],
        },
    )[0]
    == 200,
    "Role permissions can be revoked through HTTP",
)
status, body, _ = viewer.request("/ai/tools?project=1")
ok(
    "nafinity_comment" not in [tool["name"] for tool in json.loads(body)["tools"]],
    "AI rechecks revoked rights without logging in again",
)
ok(
    alice.post("/projects/1/members", {"email": "viewer@example.test", "role": "viewer"})[0] == 200,
    "Member can return to a standard role",
)
ok(
    alice.post("/projects/1/roles", {"id": role_id, "version": 2, "action": "delete"})[0] == 200,
    "Unassigned role can be deleted through HTTP",
)

# Ticket details: exercise the new flow through real, CA-verified HTTP requests.
page = alice.page(url)


def current_ticket():
    page = alice.page(url)
    return {
        "version": int(re.search(r'data-version="(\d+)"', page)[1]),
        "board_revision": int(re.search(r'data-revision="(\d+)"', page)[1]),
    }


old_revision = current_ticket()
status, body, _ = alice.post(url, {"title": "Inline HTTPS title", **old_revision}, token)
ok(status == 200 and "Inline HTTPS title" in alice.page(url), "Inline title persists over HTTPS")
ok(
    alice.post(url, {"title": "Stale title", **old_revision}, token)[0] == 409,
    "Inline HTTP conflicts preserve current title",
)
rich = '<h2>Plan</h2><p><strong>Bold</strong> and <em>italic</em></p><script>alert(1)</script><a href="javascript:alert(2)">No</a>'
ok(
    alice.post(url, {"description_html": rich, **current_ticket()}, token)[0] == 200,
    "Rich description accepted over HTTPS",
)
page = alice.page(url)
ok(
    "<h2>Plan</h2>" in page
    and "<strong>Bold</strong>" in page
    and "<script>alert(1)</script>" not in page
    and "javascript:alert(2)" not in page,
    "Description formatting survives and unsafe HTML is absent",
)
ok(
    alice.post(
        url,
        {
            "start_date": "2026-09-16",
            "due_date": "2026-09-22",
            "estimate_minutes": 180,
            "spent_minutes": 45,
            **current_ticket(),
        },
        token,
    )[0]
    == 200,
    "Planning and tracked minutes persist over HTTPS",
)
ok(
    alice.post(url, {"spent_minutes": -1, **current_ticket()}, token)[0] == 422,
    "Invalid tracked time rejected over HTTPS",
)
ok(
    alice.post(url, {"priority": "urgent", **current_ticket()}, token)[0] == 200
    and "<h2>Plan</h2>" in alice.page(url),
    "Metadata edit preserves rich description",
)
ok(
    viewer.post(url, {"title": "Forbidden", **current_ticket()}, viewer.csrf())[0] == 403,
    "Viewer cannot edit inline fields",
)
ok(
    bob.post(url + "/links", {"number": 1, "version": 1}, bob.csrf("/projects/2"))[0] == 404,
    "Links do not expose foreign projects",
)
new_payload = {
    **payload,
    "title": "Linked HTTP ticket",
    "board_revision": current_ticket()["board_revision"],
}
status, body, _ = alice.post("/projects/1/tickets", new_payload, token)
ok(status == 200, "Related HTTP fixture created")
linked_id = json.loads(body)["id"]
linked_page = alice.page("/projects/1/tickets/" + linked_id)
linked_number = int(re.search(r'class="ticket-key">NAF-(\d+)', linked_page)[1])
ok(
    alice.post(url + "/links", {"number": linked_number, **current_ticket()}, token)[0] == 200,
    "Ticket link added over HTTPS",
)
ok(
    "Linked HTTP ticket" in alice.page(url)
    and "Inline HTTPS title" in alice.page("/projects/1/tickets/" + linked_id),
    "Ticket link shown in both directions",
)
ok(
    alice.post(url + "/comments", {"body": "Root for threaded HTTP reply"}, token)[0] == 200,
    "Root comment created over HTTPS",
)
page = alice.page(url)
# Select the exact article containing the parent text.
root_id = None
for match in re.finditer(r'<article\b[^>]*id="comment-(\d+)".*?</article>', page, re.S):
    if "Root for threaded HTTP reply" in match[0]:
        root_id = int(match[1])
assert root_id
ok(
    alice.post(url + "/comments", {"body": "@alice HTTP child", "parent_id": root_id}, token)[0]
    == 200,
    "Reply parent persisted over HTTPS",
)
ok(
    "is-reply" in alice.page(url) and "@alice HTTP child" in alice.page(url),
    "Reply rendered under its parent",
)
ok(
    alice.post(
        "/projects/1/tickets/" + linked_id + "/comments",
        {"body": "Foreign parent", "parent_id": root_id},
        token,
    )[0]
    == 422,
    "Cross-ticket reply rejected over HTTPS",
)
fragment = alice.page(url + "?fragment=1")
ok(
    "ticket-workspace" in fragment
    and "<html" not in fragment
    and "data-comment-composer" in fragment,
    "Drawer fragment includes inline fields and comment composer",
)
viewer_page = viewer.page(url)
ok(
    "data-ticket-form" not in viewer_page and "data-comment-composer" not in viewer_page,
    "Read-only ticket renders no edit controls",
)
ok(
    alice.request(url + "/links", "POST", {"number": linked_number, **current_ticket()})[0] == 400,
    "Link mutations enforce native CSRF",
)
ok(
    alice.post(
        url + "/links", {"number": linked_number, "action": "delete", **current_ticket()}, token
    )[0]
    == 200,
    "Ticket link removed over HTTPS",
)


# Creation reuses the detail workspace, with one form and the same metadata controls.
class CreationForm(HTMLParser):
    def __init__(self, markup):
        super().__init__()
        self.depth = 0
        self.max_depth = 0
        self.forms = []
        self.controls = {}
        self.feed(markup)

    def handle_starttag(self, tag, attributes):
        attributes = dict(attributes)
        if tag == "form":
            self.depth += 1
            self.max_depth = max(self.max_depth, self.depth)
            self.forms.append(attributes)
        elif tag in ["input", "select", "textarea"] and "name" in attributes:
            self.controls[attributes["name"]] = attributes

    def handle_endtag(self, tag):
        if tag == "form":
            self.depth -= 1


new_url = "/projects/1/tickets/new"
new_fragment = alice.page(new_url + "?fragment=1")
creation = CreationForm(new_fragment)
ok(
    "data-ticket-create-link" in alice.page("/projects/1")
    and "ticket-workspace" in new_fragment
    and "<html" not in new_fragment
    and "data-comment-composer" not in new_fragment,
    "Board opens a creation fragment using the shared ticket workspace",
)
ok(
    len(creation.forms) == 1
    and creation.max_depth == 1
    and "data-ticket-create" in creation.forms[0]
    and creation.forms[0]["action"] == "/projects/1/tickets",
    "Creation has exactly one valid native POST form",
)
ok(
    all(
        name in creation.controls
        for name in [
            "_csrf",
            "board_revision",
            "title",
            "description",
            "column_id",
            "swimlane_id",
            "assignee_ids[]",
            "label_ids[]",
            "priority",
            "color",
            "start_date",
            "due_date",
            "estimate_minutes",
            "spent_minutes",
        ]
    )
    and "hidden" not in creation.controls["description"]
    and "data-save-on-change" not in creation.controls["column_id"],
    "Creation exposes all draft fields and a plain-text fallback without auto-saving",
)
ok("<html" in alice.page(new_url), "Direct creation URL retains a full-page fallback")
ok(viewer.request(new_url + "?fragment=1")[0] == 403, "Read-only role cannot load creation form")
ok(bob.request(new_url + "?fragment=1")[0] == 404, "Creation fragment enforces project isolation")
create_data = {
    **payload,
    "title": "Created from unified modal",
    "description_html": "<h2>Modal plan</h2><p><strong>Formatted</strong> description</p>",
    "start_date": "2026-10-01",
    "due_date": "2026-10-03",
    "estimate_minutes": 90,
    "spent_minutes": 15,
    "board_revision": creation.controls["board_revision"]["value"],
}
ok(
    alice.post("/projects/1/tickets", {**create_data, "title": ""}, token)[0] == 422,
    "Creation requires a title",
)
ok(
    alice.request("/projects/1/tickets", "POST", create_data)[0] == 400,
    "Creation enforces native CSRF",
)
form_body = urllib.parse.urlencode(
    {
        **{k: v for k, v in create_data.items() if not isinstance(v, list)},
        "assignee_ids[]": 1,
        "label_ids[]": 1,
        "_csrf": creation.controls["_csrf"]["value"],
    }
).encode()
status, body, _ = alice.request(
    "/projects/1/tickets",
    "POST",
    form_body,
    {
        "Content-Type": "application/x-www-form-urlencoded",
        "Accept": "application/json",
    },
)
ok(status == 200, "Creation form fields persist through the native HTTP endpoint")
created_url = json.loads(body)["url"]
created_fragment = alice.page(created_url + "?fragment=1")
ok(
    "Created from unified modal" in created_fragment
    and "<h2>Modal plan</h2>" in created_fragment
    and "<strong>Formatted</strong>" in created_fragment
    and 'value="2026-10-01"' in created_fragment
    and 'value="1h 30m"' in created_fragment
    and 'value="15m"' in created_fragment
    and "data-ticket-create" not in created_fragment
    and "data-comment-composer" in created_fragment,
    "Created ticket returns the editable detail fragment with rich text and planning values",
)
ok(
    alice.post("/projects/1/tickets", create_data, token)[0] == 409,
    "Repeating creation with the old board revision cannot create a duplicate",
)

# Auto-save changes the editor contract while explicit creation/comments remain separate.
auto_markup = alice.page(url + "?fragment=1")
auto_forms = re.findall(r"<form[^>]*data-auto-save[^>]*>.*?</form>", auto_markup, re.S)
ok(
    len(auto_forms) == 13
    and all('name="version"' in form and 'name="board_revision"' in form for form in auto_forms)
    and all('type="submit"' not in form for form in auto_forms),
    "All thirteen inline editors use revision-protected auto-save without per-field save buttons",
)
ok(
    re.search(r'<textarea[^>]*name="title"[^>]*maxlength="200"', auto_markup) is not None
    and "data-auto-save" not in alice.page(new_url + "?fragment=1"),
    "Title editor can wrap while creation remains an explicit draft submission",
)
ok(
    all(
        "data-auto-save" not in form
        for form in re.findall(
            r"<form[^>]*data-comment-composer[^>]*>.*?</form>", auto_markup, re.S
        )
    ),
    "Typing a comment never enables automatic publication",
)

# The shared picker keeps native values and allows multiple assignees through one menu.
choice_markup = alice.page(created_url + "?fragment=1")
# Every select in the sidebar is the same reusable picker now, so the count is no
# longer two; what matters is that these two are among them and stay searchable.
creation_markup = alice.page(new_url + "?fragment=1")
ok(
    'data-choice="column_id"' in choice_markup
    and 'data-choice="assignee_ids"' in choice_markup
    and 'aria-multiselectable="true"' in choice_markup
    and choice_markup.count("data-choice=") == choice_markup.count('popover="manual"')
    and choice_markup.count("data-choice=") >= 2
    and 'data-choice="column_id"' in creation_markup
    and 'data-choice="assignee_ids"' in creation_markup,
    "Column and assignee share an accessible searchable picker in detail and creation",
)
ok(
    choice_markup.count('data-choice-search="0"') == 2,
    "Column and people stay searchable however short their lists are",
)
choice_form = next(
    form
    for form in re.findall(r"<form[^>]*data-auto-save[^>]*>.*?</form>", choice_markup, re.S)
    if 'data-choice="assignee_ids"' in form
)
choice_action = re.search(r'action="([^"]+)"', choice_form)[1]
choice_revision = {
    name: re.search(r'name="' + name + r'" value="([^"]+)"', choice_form)[1]
    for name in ["_csrf", "version", "board_revision"]
}
choice_ids = re.findall(r'name="assignee_ids\[\]" value="([^"]+)"', choice_form)[:2]
assert (
    len(choice_ids) == 2
), "Two active project members required for the multiple-assignee regression"
choice_body = urllib.parse.urlencode(
    list(choice_revision.items())
    + [("assignee_ids", "")]
    + [("assignee_ids[]", value) for value in choice_ids]
).encode()
choice_status, _, _ = alice.request(
    choice_action,
    "POST",
    choice_body,
    {"Content-Type": "application/x-www-form-urlencoded", "Accept": "application/json"},
)
choice_updated = alice.page(created_url + "?fragment=1")
ok(
    choice_status == 200
    and all(
        re.search(r'name="assignee_ids\[\]" value="' + value + r'" checked', choice_updated)
        for value in choice_ids
    )
    and 'class="choice-count"' in choice_updated,
    "Shared person picker persists multiple native checkbox values and renders a compact count",
)
choice_form = next(
    form
    for form in re.findall(r"<form[^>]*data-auto-save[^>]*>.*?</form>", choice_updated, re.S)
    if 'data-choice="assignee_ids"' in form
)
choice_revision = {
    name: re.search(r'name="' + name + r'" value="([^"]+)"', choice_form)[1]
    for name in ["_csrf", "version", "board_revision"]
}
choice_body = urllib.parse.urlencode({**choice_revision, "assignee_ids": ""}).encode()
choice_status, _, _ = alice.request(
    choice_action,
    "POST",
    choice_body,
    {"Content-Type": "application/x-www-form-urlencoded", "Accept": "application/json"},
)
choice_updated = alice.page(created_url + "?fragment=1")
ok(
    choice_status == 200
    and re.search(r'name="assignee_ids\[\]"[^>]* checked', choice_updated) is None,
    "Unassigned option clears all assignments through the existing endpoint",
)

# Estimation: one scale per project, summed over each column and kept across a switch.
project_form = {
    "name": "Nafinity",
    "description": "Ein klarer Ort für Ideen, Entscheidungen und die nächste gute Version.",
    "ticket_key": "NAF",
    "color": "#6366f1",
    "icon": "N",
}


def set_scale(scale, remap=False):
    body = {**project_form, "estimation_scale": scale}
    if remap:
        body["remap_estimates"] = "1"
    status, _, _ = alice.post("/projects/1/settings", body)
    assert status == 200, ("scale", scale, status)
    return alice.page("/projects/1")


board_page = alice.page("/projects/1")
column_sums = [
    int(value) for value in re.findall(r"<span data-points-value>(\d+)</span>", board_page)
]
card_points = [
    int(value)
    for value in re.findall(r'class="ticket-card"[^>]*data-points="(\d+)"', board_page, re.S)
]
ok(
    len(column_sums) == 4 and sum(column_sums) == sum(card_points) and sum(card_points) > 0,
    "Column sums account for exactly the estimates on the board",
)
ok("SP</small>" in board_page, "Story point unit is rendered beside the sum")

# 8 and 13 have no place on a one-to-five scale; they stay until the remap is asked for.
settings_page = set_scale("complexity") and alice.page("/projects/1/settings")
ok('class="settings-hint"' in settings_page, "Switching scale reports the values it cannot offer")
ok('name="remap_estimates"' in settings_page, "Off-scale values offer a one-off remap")
ok(
    "off-scale" in alice.page("/projects/1"),
    "Off-scale estimates stay on their cards and are marked",
)
set_scale("complexity", remap=True)
settings_page = alice.page("/projects/1/settings")
ok(
    'class="settings-hint"' not in settings_page and "off-scale" not in alice.page("/projects/1"),
    "Remap moves every off-scale estimate onto the chosen scale",
)
set_scale("none")
ok(
    "data-points-value" not in alice.page("/projects/1"),
    "Turning estimation off removes the counter without touching the numbers",
)
set_scale("points")
ok(
    sum(
        int(value)
        for value in re.findall(r"<span data-points-value>(\d+)</span>", alice.page("/projects/1"))
    )
    > 0,
    "Estimates return when the scale is switched back on",
)

# Time tracking: the server holds the run, and a person counts one thing at a time.
timer_status, timer_body, _ = alice.post("/projects/1/tickets/NAF-1/timer", {"action": "start"})
ok(
    timer_status == 200 and json.loads(timer_body)["state"] == "running",
    "Starting a timer reports a running run",
)
board_page = alice.page("/projects/1")
ok(
    "data-running-timer" in board_page and 'class="timer-pulse"' in board_page,
    "A running timer is visible on the board and in the bar without opening the ticket",
)
alice.post("/projects/1/tickets/NAF-2/timer", {"action": "start"})
ok(
    'data-state="paused"' in alice.page("/projects/1/tickets/NAF-1"),
    "Starting elsewhere settles the run that was counting",
)
timer_status, timer_body, _ = alice.post("/projects/1/tickets/NAF-2/timer", {"action": "stop"})
ok(
    timer_status == 200 and json.loads(timer_body)["state"] == "stopped",
    "Stopping closes the run and reports the ticket total",
)
ok(
    "data-running-timer" not in alice.page("/projects/1"),
    "The bar drops its marker once nothing is counting",
)
ok(
    alice.post("/projects/1/tickets/NAF-1/timer", {"action": "rewind"})[0] == 422,
    "Unknown timer actions are refused",
)
ok(
    viewer.post("/projects/1/tickets/NAF-1/timer", {"action": "start"}, viewer.csrf())[0] == 403,
    "Read access is not permission to book time",
)
ok(
    bob.post("/projects/1/tickets/NAF-1/timer", {"action": "start"}, bob.csrf("/projects/2"))[0]
    == 404,
    "A stranger is not told the ticket exists",
)

# Moving a ticket to another project: it answers at a new address, and what only meant
# something in the project it left stays behind.
token = alice.csrf()
away = json.loads(
    alice.post(
        "/projects",
        {
            "name": "Ablage",
            "description": "Wohin verschobene Tickets gehen",
            "color": "#10b981",
            "icon": "A",
        },
        token,
    )[1]
)["url"]
move_payload = {
    **payload,
    "title": "Zieht um",
    "board_revision": json.loads(alice.request("/projects/1/state")[1])["revision"],
}
moving = json.loads(alice.post("/projects/1/tickets", move_payload, token)[1])["id"]
moving_url = "/projects/1/tickets/" + moving
moving_page = alice.page(moving_url)
ok(
    'name="project_id"' in moving_page and "Ablage" in moving_page,
    "The ticket menu offers the projects a move could go to",
)
ok(
    'name="project_id"' not in alice.page("/projects/1/tickets/new"),
    "A ticket that does not exist yet is offered nowhere to move to",
)
status, body, _ = alice.post(
    moving_url + "/transfer",
    {
        "project_id": away.rsplit("/", 1)[1],
        "version": re.search(r'data-version="(\d+)"', moving_page)[1],
    },
    token,
)
moved_url = json.loads(body)["url"]
ok(
    status == 200 and moved_url.startswith(away + "/tickets/"),
    "A ticket moves to another project and is answered for at a new address",
)
ok(alice.request(moving_url)[0] == 404, "The ticket no longer answers where it used to be")
moved_page = alice.page(moved_url)
ok(
    "Zieht um" in moved_page and "label-tag" not in moved_page,
    "The moved ticket opens under its new reference without the labels it left behind",
)
ok(
    alice.post(moved_url + "/transfer", {"project_id": "1", "version": "1"}, token)[0] == 409,
    "A move carrying a stale version is refused",
)
ok(
    bob.post(moving_url + "/transfer", {"project_id": "2"}, bob.csrf("/projects/2"))[0] == 404,
    "A stranger cannot move a ticket out of a project they cannot see",
)

print(json.dumps({"passed": len(results), "tests": results}, indent=2))
