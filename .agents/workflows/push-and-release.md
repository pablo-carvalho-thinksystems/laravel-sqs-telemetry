---
description: How to push changes and create a new tag for the laravel-sqs-telemetry package
---

# Push & Release Workflow

Whenever changes are ready to be pushed to production, **always** create a new tag after pushing. This ensures Packagist/Composer picks up the new version.

## Steps

1. Stage and commit changes:
```bash
git add <files> && git commit -m "<commit message>"
```

2. Push to main:
```bash
git push origin main
```

// turbo
3. Get the latest tag to determine the next version:
```bash
git tag --sort=-v:refname | head -1
```

4. Create and push the new tag (increment patch version from step 3):
```bash
git tag v<next_version> && git push origin v<next_version>
```

> **IMPORTANT**: Never skip the tag creation step. Packagist only publishes versions that have a corresponding git tag.
