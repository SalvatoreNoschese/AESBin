# AESBin documentation

The root [`README.md`](../README.md) is the short overview. The pages below
cover each topic in full, one subject per page.

| Document | What's in it |
|---|---|
| [Installation](installation.md) | Requirements, the setup wizard, nginx/Caddy rules, `config.php` options |
| [Updating](updating.md) | Installing a new release: what to keep (`config.php`, the storage folder), what to replace |
| [Security model](security-model.md) | Key hierarchy, threat model, what elevation does and doesn't defend |
| [Features](features.md) | The complete feature list, with the reasoning behind the non-obvious ones |
| [Architecture](architecture.md) | Directory layout, request flow, where storage and configuration live |
| [Vendored libraries](vendored-libraries.md) | What is bundled, which versions, why no CDN, how to update one |
| [Maintaining](maintaining.md) | Schema versions, migrations, invariants, release checklist |

Two documents stay in the repository root, where GitHub looks for them:

- [`SECURITY.md`](../SECURITY.md): how to report a vulnerability, what is in and out of scope
- [`CONTRIBUTING.md`](../CONTRIBUTING.md): how to open an issue or a pull request
