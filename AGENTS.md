# AGENTS.md

Rules for working in this plugin (`fastmon/shopware-collector`, Shopware 6.6 / 6.7). Read
[`README.md`](README.md) for what the plugin does and why; this file is about how to change it.

## Quality gates

Every change passes `composer ci` before it is committed. No exceptions, no `--no-verify`.

| Command | What it runs | When |
|---|---|---|
| `composer ci` | `cs-check`, `phpstan` (level max), `phpmd`, `test` | before every commit |
| `composer test` | unit suite, no shop, no database | while working |
| `composer test-integration` | integration suite, boots Shopware, needs a project (see README, *Tests*) | before opening a PR that touches anything Shopware-facing |
| `composer cs-fix` | apply the style fixes `cs-check` reported | as needed |
| `shopware-cli extension validate --full .` | the store validator | before a release |

CI (`.github/workflows/ci.yml`) runs the same gates on PHP 8.2 to 8.5 against Shopware 6.6 and 6.7. A
red leg is a bug in the change, not in the matrix.

## Rules

1. **`final` by default, `readonly` for values, attributes for wiring.** Every class is `final` unless
   it is a documented extension point (`LayerMetricsProviderInterface`); value objects are
   `final readonly class`; DI is autowired, so new services need no YAML, only a constructor with
   typed parameters and, where a type cannot say it, an attribute (`#[Autowire]`, `#[AutowireIterator]`,
   `#[WithMonologChannel]`).
2. **Nothing on the storefront path talks to fastmon.** `StorefrontPathIsIsolatedTest` enforces it
   structurally. A subscriber, resolver or Twig extension may depend on config and the request, never on
   `FastmonClient` or an HTTP client.
3. **A monitoring header is never worth a broken response.** Everything the storefront path does is
   wrapped; a failure is logged and swallowed.
4. **Merchant-facing refusals are typed.** Throw `FastmonCollectorException` (or a `FastmonApiException`
   subtype) for what the merchant should read; let everything else propagate. The controller catches only
   those two.
5. **Prove before you switch.** A collection mode that depends on the merchant's server is applied only
   after the probe passed on every origin. Do not add a way around that.
6. **Comments say why.** The code says what. A rule that looks odd carries its reason where it lives,
   including every `@SuppressWarnings` and every rule turned off in `phpmd.xml`.
7. **Snippets follow the code.** Reason codes (`DomainCheckResult::REASON_*`) and provider states are
   asserted against both snippet files; add the text in `de-DE` and `en-GB` with the constant.
8. **Administration uses Meteor (`mt-*`) components.** No raw form controls, no hex colours. Layout
   SCSS only, colours from `~scss/variables`. Error flags from the admin API are mapped in one place,
   the `fastmon-collector-error` mixin.
9. **Tests are the contract.** Shopware-facing behaviour (queries, ACL, templates, the header on a real
   page) gets an integration test on Shopware's test behaviours; logic gets a unit test. A change that
   removes an assertion explains why in the commit.
10. **Do not raise the PHP floor.** `php >=8.2` follows Shopware's supported range; write code that runs on
    8.2 and stays valid on 8.5.

## Conventions

- **Writing rules are in [`CLAUDE.md`](CLAUDE.md)**: no en dash or em dash anywhere, and
  German that was written rather than translated. They apply to comments, snippets, docs
  and commit messages alike.
- Conventional Commits, English, body explains the why.
- Feature branches, one topic per PR, `composer ci` green and the integration suite run locally
  when the change is Shopware-facing.
- `docs/` holds backend specs only; user documentation lives in the README until it outgrows it.
