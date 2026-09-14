from pathlib import Path
import json

root = Path(__file__).resolve().parents[1]
data = json.loads((root / "app/composer.json").read_text())
data["minimum-stability"] = "dev"
data["prefer-stable"] = True
data["repositories"] = []
for package, constraint in data["require"].items():
    if not package.startswith("naf/"):
        continue
    name = package.split("/")[1]
    # Explicit development aliases; these are never claimed to be published releases.
    data["repositories"].append(
        {
            "type": "path",
            "url": "../packages/" + name,
            "options": {"symlink": True, "versions": {package: constraint.lstrip("^") + "-dev"}},
        }
    )
(root / "app/composer.dev.json").write_text(json.dumps(data, indent=2) + "\n")
