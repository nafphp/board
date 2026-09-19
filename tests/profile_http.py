#!/usr/bin/env python3
"""Account security acceptance against disposable app-test and local Mailpit only."""

import concurrent.futures
import http.cookiejar
import json
import os
import re
import ssl
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(os.environ.get("NAF_HOST_ROOT", Path(__file__).resolve().parents[1] / "../nafinity"))

# The fixture script lives with these tests, in the package, while the container
# belongs to the host that installs it. NAF_BOARD_IN_CONTAINER is where the host
# mounts the package; NAF_HOST is the installation the fixture must boot.
BOARD_IN_CONTAINER = os.environ.get("NAF_BOARD_IN_CONTAINER", "/workspace/board")
FIXTURE = [
    "docker",
    "compose",
    "exec",
    "-T",
    "-e",
    "NAF_HOST=" + os.environ.get("NAF_HOST_IN_CONTAINER", "/workspace/app"),
    "app-test",
    "php",
    BOARD_IN_CONTAINER + "/tests/profile_fixture.php",
]
BASE = "https://127.0.0.1:8444"
TLS = ssl.create_default_context(cafile=str(ROOT / "docker/rootfs/etc/nginx/ssl/ca.pem"))
ORIGINAL = "Profile test original password!"
CHANGED = "Profile test changed password!"
MAILPIT = "http://127.0.0.1:" + os.environ.get("NAFINITY_MAILPIT_PORT", "8025")
results = []


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPSHandler(context=TLS), urllib.request.HTTPCookieProcessor(self.jar)
        )
        self.token = ""

    def request(self, path, data=None, csrf=True):
        headers = {"Accept": "application/json"}
        if data is not None:
            headers["Content-Type"] = "application/json"
            if csrf:
                headers["X-CSRF-Token"] = self.token
            data = json.dumps(data).encode()
        request = urllib.request.Request(BASE + path, data=data, headers=headers)
        try:
            response = self.opener.open(request, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        return response.status, response.read(), response.headers

    def login(self, email, password=ORIGINAL):
        status, body, _ = self.request("/login")
        assert status == 200
        self.token = re.search(rb'name="_csrf" value="([^"]+)"', body)[1].decode()
        status, body, _ = self.request("/login", {"email": email, "password": password})
        if status == 200:
            self.token = re.search(rb'name="_csrf" value="([^"]+)"', body)[1].decode()
        return status

    def profile(self):
        status, body, _ = self.request("/profile")
        assert status == 200, (status, body[:150])
        return json.loads(body)["profile"]


def check(condition, description):
    assert condition, description
    results.append(description)


def mail_code(email):
    for _ in range(15):
        query = urllib.parse.urlencode({"query": "to:" + email})
        with urllib.request.urlopen(MAILPIT + "/api/v1/search?" + query, timeout=5) as response:
            messages = json.load(response).get("messages", [])
        for message in messages:
            with urllib.request.urlopen(
                MAILPIT + "/api/v1/message/" + message["ID"], timeout=5
            ) as response:
                body = json.load(response).get("Text", "")
            code = re.search(r"Bestätigungscode: ([A-Z2-9-]+)", body)
            if code:
                return code[1]
        time.sleep(0.2)
    raise AssertionError("Verification message did not reach local Mailpit")


fixtures = json.loads(
    subprocess.check_output(
        FIXTURE,
        cwd=ROOT,
    )
)
guest = Client()
check(guest.request("/profile")[0] == 401, "Unauthenticated profile access denied")
first, second = Client(), Client()
assert (
    first.login(fixtures["password"]["email"]) == second.login(fixtures["password"]["email"]) == 200
)
old_session_ids = [cookie.value for cookie in first.jar if cookie.name == "PHPSESSID"]
check(
    first.profile()["email"] == fixtures["password"]["email"],
    "Only the signed-in profile is returned",
)
check(
    any("no-store" in value for value in first.request("/profile")[2].get_all("Cache-Control", [])),
    "Profile response cannot be cached",
)
payload = {"current_password": ORIGINAL, "password": CHANGED, "password_confirmation": CHANGED}
check(
    first.request("/profile/password", payload, csrf=False)[0] == 400,
    "Password change rejects missing CSRF",
)
check(
    first.request("/profile/password", {**payload, "current_password": "wrong"})[0] == 403,
    "Password change rejects incorrect current password",
)
status, body, _ = first.request("/profile/password", payload)
check(
    status == 200 and json.loads(body).get("url") == "/login",
    "Password change requests fresh login",
)
check(
    first.request("/profile")[0] == second.request("/profile")[0] == 401,
    "Password change revokes both independent sessions",
)
check(Client().login(fixtures["password"]["email"]) == 401, "Old password no longer authenticates")
assert first.login(fixtures["password"]["email"], CHANGED) == 200
check(
    old_session_ids != [cookie.value for cookie in first.jar if cookie.name == "PHPSESSID"],
    "Fresh authentication rotates the session cookie",
)

owner, owner_second, other = Client(), Client(), Client()
assert (
    owner.login(fixtures["email"]["email"])
    == owner_second.login(fixtures["email"]["email"])
    == other.login(fixtures["other"]["email"])
    == 200
)
email = fixtures["email"]["email"].replace("-email@", "-verified@")
request = {"email": email, "current_password": ORIGINAL}
for endpoint, payload in [
    ("/profile/email", request),
    ("/profile/email/confirm", {"request_id": "0" * 32, "code": "AAAAAAAAAAAA"}),
    ("/profile/email/cancel", {}),
]:
    check(owner.request(endpoint, payload, csrf=False)[0] == 400, "CSRF protects " + endpoint)
status, body, _ = owner.request("/profile/email", request)
assert status == 200, (status, body[:200])
pending = json.loads(body)["pending"]
code = mail_code(email)
check(len(code) == 14, "Native NAF mail transport delivers a real verification code to Mailpit")
check(
    owner.profile()["email"] == fixtures["email"]["email"],
    "Pending address does not replace current login",
)
check(
    owner_second.profile()["pending"]["request_id"] == pending["request_id"],
    "Pending verification survives another browser session",
)
verification = {"request_id": pending["request_id"], "code": code}
check(
    other.request("/profile/email/confirm", {**verification, "user_id": fixtures["email"]["id"]})[0]
    == 422,
    "Another account cannot confirm the code",
)
check(
    owner.request("/profile/email/confirm", {**verification, "code": "AAAAAAAAAAAA"})[0] == 422,
    "Incorrect verification code is rejected",
)
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    statuses = sorted(
        pool.map(
            lambda client: client.request("/profile/email/confirm", verification)[0],
            [owner, owner_second],
        )
    )
check(statuses == [200, 401], "Concurrent confirmation accepts the code exactly once")
check(
    owner.request("/profile")[0] == owner_second.request("/profile")[0] == 401,
    "Email confirmation revokes both sessions",
)
check(
    Client().login(fixtures["email"]["email"]) == 401,
    "Old email cannot authenticate after confirmation",
)
assert owner.login(email) == 200
check(
    owner.profile()["email_verified"] is True,
    "New verified email authenticates with unchanged password",
)
check(
    owner.request("/profile/email/confirm", verification)[0] == 422,
    "Used verification code cannot be replayed",
)
check(
    other.profile()["email"] == fixtures["other"]["email"],
    "Other accounts and sessions remain unchanged",
)

subprocess.run(
    FIXTURE + ["deliver"],
    cwd=ROOT,
    check=True,
    stdout=subprocess.DEVNULL,
)
for kind, expected in [("password", 1), ("email", 2)]:
    query = urllib.parse.urlencode({"query": "to:" + fixtures[kind]["email"]})
    with urllib.request.urlopen(MAILPIT + "/api/v1/search?" + query, timeout=5) as response:
        messages = json.load(response).get("messages", [])
    check(
        len(messages) == expected,
        "Native queue delivers security notices to original " + kind + " address",
    )

print(json.dumps({"passed": len(results), "tests": results}, indent=2))
