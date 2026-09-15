import urllib.request, urllib.error, urllib.parse, http.cookiejar, re, json, hashlib
import ssl
from pathlib import Path

base = "https://127.0.0.1:8445"
TLS = ssl.create_default_context(
    cafile=str(Path(__file__).resolve().parents[2] / "docker/rootfs/etc/nginx/ssl/ca.pem")
)

client = urllib.request.build_opener(
    urllib.request.HTTPSHandler(context=TLS),
    urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),
)


def request(path, data=None):
    try:
        with client.open(base + path, data=data, timeout=20) as response:
            return response.status, response.read(), dict(response.headers)
    except urllib.error.HTTPError as error:
        return error.code, error.read(), dict(error.headers)


status, body, _ = request("/health/ready")
assert status == 200 and json.loads(body)["schema"] == "202609140003", ("ready", status)
status, body, _ = request("/login")
assert status == 200, ("login GET", status, body[:150])
token = re.search(r'name="_csrf" value="([^"]+)"', body.decode())[1]
status, body, _ = request(
    "/login",
    urllib.parse.urlencode(
        {"_csrf": token, "email": "alice@example.test", "password": "Nafinity-Demo-2026!"}
    ).encode(),
)
assert status == 200 and b"Ein guter Start" in body, ("authenticated board", status, body[:150])
checked = []
for path in [
    "/projects/1",
    "/projects/1/tickets/9",
    "/projects/1/settings",
    "/notifications",
    "/preferences",
]:
    status, body, _ = request(path)
    assert status == 200, (path, status, body[:150])
    checked.append(path)
assert request("/projects/2")[0] == 404, "foreign project exposed"
status, body, headers = request("/projects/1/tickets/9/attachments/1")
assert status == 200 and headers["Content-Disposition"].startswith(
    "attachment;"
), "private download failed"
print(
    json.dumps(
        {
            "mode": "unreleased-source-snapshot",
            "schema": "202609140003",
            "login": "passed",
            "authenticated_pages": checked,
            "foreign_project_status": 404,
            "private_download_bytes": len(body),
            "private_download_sha256": hashlib.sha256(body).hexdigest(),
        },
        indent=2,
    )
)
