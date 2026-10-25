# Stack Toast Installer

The command line installer for [Stack Toast](https://stacktoast.com), the Laravel SaaS starter kit.

```bash
composer global require stacktoast/installer
stacktoast check      # will this machine run it? No purchase needed
stacktoast new my-app
```

## What is Stack Toast?

A SaaS starter kit for Laravel. You begin with the parts every subscription business needs already built, tested and wired together, and write your own product on top.

**Billing across four processors.** Stripe, Paddle, Lemon Squeezy and Coinbase Commerce, interchangeable behind one resolver — including crypto, which most starter kits do not offer. Subscriptions, one-off payments, trials, coupons, invoice PDFs, VAT capture, dunning, and webhook handling with signature verification and an idempotency ledger. Coupon and seat support differs per processor, and the code says which rather than pretending otherwise.

**Accounts and teams.** Login, registration, password reset, magic links, email PIN, social sign-in through Socialite, and two-factor by authenticator app, email or SMS with hashed recovery codes. Teams are the billing subject, so one user is a team of one and the same code bills both: seats, roles, invitations and owner transfer.

**An admin panel in every edition.** Eleven areas — users, subscriptions, plans, coupons, transactions, teams, roles, permissions, payment processors, notifications, waiting list — and twenty-two metrics on the dashboard: MRR with period deltas, ARR, ARPU, LTV, churn, revenue before and after discounts, and attribution for coupons, refunds and crypto, all date-ranged and multi-currency. It is Blade and Alpine in every edition, which is why an API-only install still gets the whole panel.

**Hardened before you ship.** A configurable Content Security Policy, Cloudflare visitor data, three captcha providers, a bot challenge, and session fingerprinting that deliberately does not bind a session to a client IP — because that signs mobile users out when they move onto cellular.

**Operations that usually get written twice.** A scheduler already configured, a `stacktoast:health` doctor, user data export, admin impersonation with a time limit, `noindex` by default on app routes with a sitemap built from your own route config, and per-edition documentation for Claude Code, Cursor and Codex generated from the same source as the code it describes.

Backed by 1,168 backend tests, 244 frontend tests and PHPStan level 6.

### Editions, from one codebase

The business logic lives in services that know nothing about HTTP, so a frontend is a presentation layer rather than a fork. Every edition is the same version with the same features.

| Edition    | Frontend                                            |
| ---------- | --------------------------------------------------- |
| `livewire` | Blade, Alpine, Livewire and Tailwind — plus the API |
| `api`      | Sanctum endpoints and the admin panel, no app pages |
| `react`    | Inertia pages in React — plus the API               |
| `vue`      | Inertia pages in Vue — plus the API                 |

`stacktoast stacks` lists the editions, and `stacktoast whoami` the ones your own credentials can reach. Pricing and the full feature list are at [stacktoast.com](https://stacktoast.com).

---

## Check a machine before you buy

`check` needs no purchase and no access to anything private. That is the point of it: find out whether a machine can run Stack Toast before you spend anything.

```bash
stacktoast check
```

```
  ok  PHP 8.4.0 or newer  8.5.5
  ok  PHP extensions  14 present
  ok  A PDO driver  mysql, pgsql, sqlite
  ok  Composer  2.9.5
  ok  git  2.49.0
  ok  Node ^20.19.0 || >=22.12.0  22.17.0
  ok  npm  11.12.1
  ok  A writable working directory  /srv
  -   Database reachable  no --db given
```

It exits non-zero when a requirement is unmet, so it can gate a CI job, and `--json` gives machine-readable output. Add a database:

```bash
stacktoast check --db="mysql:host=127.0.0.1;dbname=stacktoast" --db-username=root --db-password=secret
```

## Start a project

```bash
stacktoast new my-app                                   # asks which edition
stacktoast new my-app --stack=api --no-interaction
stacktoast new my-app --origin=git@github.com:me/my-app.git
```

`new` clones the edition at its newest tag, keeps the full history, and sets the remotes up so upgrades work:

| Remote       | Points at              | Used by                                                        |
| ------------ | ---------------------- | -------------------------------------------------------------- |
| `stacktoast` | the edition you bought | `php artisan stacktoast:upgrade`, which fetches tags from here |
| `origin`     | your own repository    | you                                                            |

That split is why one purchase covers many projects: each clone has its own `origin`, and they all take releases from the same upstream. Upgrading is `php artisan stacktoast:upgrade`, which reports what the upgrade involves and changes nothing, then `--merge` when the report finds nothing needing a decision.

`new` then hands off to `configure-stacktoast.php`, the setup wizard: it installs dependencies, writes `.env`, migrates, seeds and builds assets. Skip it with `--no-configure`.

## Which editions can I reach?

```bash
stacktoast stacks        # the editions
stacktoast whoami        # the ones this machine's git credentials can clone
```

There is no licence key and no activation server. Editions are private repositories and customers are added as collaborators, so `git clone` succeeds for a customer and fails for everyone else. `whoami` asks git that question and reports the answer — nothing to mislay, and nothing to be down.

## Requirements

PHP 8.4, the same floor as Stack Toast itself. On anything older this says so and exits rather than failing to parse. `git` is needed to clone; `check` will tell you what else is missing.

## Licence

This installer is MIT. What it installs is licensed separately — see [stacktoast.com](https://stacktoast.com).
