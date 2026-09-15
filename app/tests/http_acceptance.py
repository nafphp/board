#!/usr/bin/env python3
"""Real HTTP regression suite. Run against only the named disposable Compose app-test service."""
import urllib.request, urllib.error, http.cookiejar, urllib.parse, json, re, concurrent.futures, time
import ssl
from pathlib import Path

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
    "/projects/1/tickets/1",
]:
    ok(bob.request(path)[0] == 404, "Bob denied " + path)
# Stable CSRF across two tabs; fake Bearer and malformed CSRF cannot bypass checks.
token = alice.csrf()
ok(token == alice.csrf("/projects/1/tickets/1"), "CSRF stable across tabs")
for method, headers, data in [
    ("POST", {}, {}),
    ("PATCH", {"Authorization": "Bearer invented"}, {}),
    ("POST", {}, {"_csrf": [token]}),
]:
    status, _, _ = alice.request("/projects/1/tickets/1", method, data, headers)
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
print(json.dumps({"passed": len(results), "tests": results}, indent=2))
