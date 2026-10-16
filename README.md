# Silverstripe Deploy (experimental v0.1.0)

A Composer-distributed command-line frontend to Deployer 7. One shared recipe and a bounded worker queue for one or many sites. **Developer preview: not verified against production AWS/SSM.**

## Install

Install in each project. Until v0.1.0 is tagged, use the development branch:

```shell
composer require --dev dorsetdigital/silverstripe-deploy:dev-feature/initial-ssdeploy-preview
```

After a v0.1.0 release is published, use a tagged constraint (for example `^0.1`).

Create a `deploy.yml` in your project root (see the configuration reference below). The package supplies the executable (`vendor/bin/ssdeploy`), recipe, and worker queue; no PHP deploy script is needed in project repositories.

```shell
vendor/bin/ssdeploy init
vendor/bin/ssdeploy sites
vendor/bin/ssdeploy check
vendor/bin/ssdeploy test-connection
vendor/bin/ssdeploy deploy logixs --dry-run
vendor/bin/ssdeploy deploy logixs
vendor/bin/ssdeploy deploy logixs test-site --workers=2
vendor/bin/ssdeploy deploy --all --workers=2
vendor/bin/ssdeploy deploy --stage=prod
vendor/bin/ssdeploy rollback logixs
vendor/bin/ssdeploy unlock logixs
```

A bare `deploy` selects every configured site; `--all` remains available as an explicit alias. Every process selects exactly one site and deploys to its already-provisioned release root. Queue stops accepting new sites only after the list is empty; individual failures do **not** undo successful deployments. Confirm once before spawning workers. Do not run two deployment batches against the same site simultaneously.

## Project setup

Run `vendor/bin/ssdeploy init` once in the project root. This creates three files **only if absent**: `deploy` (an executable Bash wrapper), `deploy.yml` (a documented SSH starter configuration with one host), and `.deployignore` (rsync exclusions). Existing files are skipped without modification. Review the generated YAML and supply the real server, AWS account, and deployment paths before deploying. Running `init` again is safe and reports which files already exist.

The generated wrapper is deployment-only: `./deploy` (all sites), `./deploy website` (one site), `./deploy website another-site --workers=2` (selected sites), or `./deploy --stage=prod` (all sites at their production paths). For administrative commands such as `sites`, `check`, `test-connection`, `rollback` and `unlock`, use `vendor/bin/ssdeploy`. Commit the three scaffolding files to your project repository.

## deploy.yml configuration reference

Run `ssdeploy` from the project directory containing `deploy.yml`. **Every project must define at least one entry in `hosts`**; a single-site deployment simply has one entry and is auto-selected when no site name is supplied. There is no separate mode switch. The same project files are uploaded to each selected site, while each target has its own release directory and shared environment file.

### Single-site example (separate staging and production paths)

```yaml
project:
  name: Example CMS

server:
  connection: ssh
  host: deploy
  user: ubuntu
  port: 22

hosts:
  website:
    staging_deploy_path: /var/www/sites/example-staging
    prod_deploy_path: /var/www/sites/example-production

aws:
  aws_account_id: "123456789012"
  aws_region: eu-west-2

php:
  version: "8.3"

composer:
  install: true
  install_dev: false

silverstripe:
  dev_build: true

shared_dirs:
  - public/assets
shared_files:
  - .env
```

```shell
vendor/bin/ssdeploy deploy                 # staging (default)
vendor/bin/ssdeploy deploy --stage=prod    # production
```

With a single entry in `hosts`, the CLI automatically selects it for `deploy` and `rollback`, so naming the site is optional. With multiple hosts, bare `deploy` selects them all, but `rollback` requires a named site; batch rollback is unsupported. `project.name` is only a display/application name and can include spaces.

For a single destination instead of separate stages, configure `hosts.website.deploy_path` and omit the stage-specific paths.

### Multi-site example (one destination per site)

```yaml
project:
  name: Example Network

server:
  connection: ssm
  instance_id: i-0123456789abcdef0
  region: eu-west-2
  user: ubuntu
  port: 22

hosts:
  test-site:
    name: Test Site
    deploy_path: /var/www/sites/test-site
  customer-one:
    name: Customer One
    deploy_path: /var/www/sites/customer-one

aws:
  aws_account_id: "123456789012"
  aws_region: eu-west-2

php:
  version: "8.3"

shared_dirs:
  - public/assets
shared_files:
  - .env

deployment:
  workers: 2
  keep_releases: 2
```

```shell
vendor/bin/ssdeploy sites
vendor/bin/ssdeploy deploy test-site
vendor/bin/ssdeploy deploy --workers=2
vendor/bin/ssdeploy deploy test-site customer-one --workers=2
```

The `hosts` keys (`test-site`, `customer-one`) are the CLI site selectors. The optional `name` field is descriptive; selection uses the alias, not the display name. A bare `deploy` command deploys **every** configured host; `--all` does the same. List one or more site aliases to deploy only those sites, in the order supplied. Duplicate or unknown aliases cause an error. Use named targets while trialling a new configuration.

All hosts currently share the `server` connection settings. A `hosts` entry changes the destination path, not the AWS instance, SSH user, or transport. Deployment destinations must be specified under `hosts`; `server.deploy_path`, `server.staging_deploy_path` and `server.prod_deploy_path` are not supported.

### Multi-site example with staging and production

```yaml
hosts:
  test-site:
    staging_deploy_path: /var/www/sites/test-site-staging
    prod_deploy_path: /var/www/sites/test-site
  customer-one:
    staging_deploy_path: /var/www/sites/customer-one-staging
    prod_deploy_path: /var/www/sites/customer-one
```

```shell
vendor/bin/ssdeploy deploy test-site                  # staging
vendor/bin/ssdeploy deploy test-site --stage=prod     # production
vendor/bin/ssdeploy deploy --stage=prod         # all production destinations
```

For each target, `deploy_path` takes precedence over `staging_deploy_path` or `prod_deploy_path`. Consequently, **do not combine a generic `deploy_path` with stage-specific paths** in one site entry unless you intentionally want the generic path to override the stage. You can mix single-path and stage-specific hosts within a project; for single-path hosts `--stage` does not change their destination. The CLI displays the stage only when at least one selected host uses stage-specific paths.

The default stage is `staging`; only `staging` and `prod` are accepted. Deployment paths must be absolute, pre-existing and writable; the recipe deliberately does not create a new site root.

### Available configuration keys

| Section | Key | Behaviour |
| --- | --- | --- |
| `project` | `name` | Required project/application display name. |
| `server` | `connection` | `ssh` (default) or `ssm`. |
| `server` | `host` | SSH destination hostname; required with `connection: ssh`. |
| `server` | `instance_id`, `region` | Required for SSM transport. |
| `server` | `user`, `port` | Remote username (required); SSH port defaults to 22. |
| `server` | `availability_zone` | Optional EC2 Instance Connect availability zone. |
| `server` | `host_key_checking`, `known_hosts_file` | SSM host-key trust; default `accept-new` with temporary known-hosts, or `yes` with pre-provisioned file. |
| `hosts.<alias>` | `deploy_path` | Fixed destination path, independent of stage. |
| `hosts.<alias>` | `staging_deploy_path`, `prod_deploy_path` | Stage-specific destination paths (when generic `deploy_path` is absent). |
| `hosts` | `<alias>` | Required, non-empty map of sites. A single site is auto-selected. |
| `deployment` | `workers` | Maximum concurrent Deployer processes, default 2; allowed range 1–10. |
| `deployment` | `keep_releases` | Number of releases retained, default 2. |
| `aws` | `aws_account_id`, `aws_region` | AWS ECR account and region used to form the PHP image URI; distinct from the SSM transport region. |
| `php` | `version` | PHP ECR image suffix, default `8.3`. |
| `composer` | `install`, `install_dev`, `show_output` | Defaults: `true`, `false`, `false`. |
| `silverstripe` | `dev_build`, `show_output` | Defaults: `false`, `false`. |
| `shared_dirs`, `shared_files`, `writable` | arrays | Deployer shared directories, shared files and writable directories. |
| `exclude` | array | Additional rsync exclusions, combined with `.deployignore`; `.git`, `vendor` and `node_modules` are excluded automatically. |
| `build.local` | array of shell commands | Run locally **once** before launching deployment workers. A failing command aborts the batch. |
| `assets` | `enabled`, `image`, `command`, `output` | Per-site server-side Webpack build; defaults: `false`, `node:14`, `yarn webpack --config webpack.config.js`, `public/build`. |
| `deploy` | `before_symlink`, `after_symlink` | Arrays of remote shell commands executed relative to the release path. |

### Local and server-side builds

To build once on the developer machine before any site deploys:

```yaml
build:
  local:
    - ddev exec npm run build:prod
```

To compile assets separately for every site using its own environment file:

```yaml
assets:
  enabled: true
  image: node:20
  command: yarn webpack --config webpack.config.js
  output: public/build
```

The `assets` step is optional and runs **after Composer, before Silverstripe dev/build and before activating the release**. It requires `package.json`, `yarn.lock` and `<deploy_path>/shared/.env`. The server-side task installs dependencies in a shared, locked Node cache under `/home/ubuntu/.build-cache/<project>/node`, creates an isolated temporary workspace per site on the EC2 host, copies the site's `.env` into that workspace, and copies only the compiled output directory back to its NFS release. The output path is relative to the project root. Choose an image supported by the actual project; `node:14` is only the legacy default and is end-of-life.

These two kinds of build can coexist. To avoid unnecessary work, only enable the build stages your project needs. Server-side assets are currently designed for Yarn with a lockfile, not npm-only installations.

### Commands and safeguards

```shell
vendor/bin/ssdeploy check
vendor/bin/ssdeploy test-connection
vendor/bin/ssdeploy deploy test-site --dry-run
vendor/bin/ssdeploy deploy test-site
vendor/bin/ssdeploy deploy --all --workers=2
vendor/bin/ssdeploy deploy test-site --stage=prod
vendor/bin/ssdeploy rollback test-site --stage=prod
vendor/bin/ssdeploy unlock test-site customer-one
```

`--dry-run` only shows the selection summary: it does not connect or simulate remote tasks. `--yes` suppresses the confirmation prompt. With a Git repository at the project root, deployments check that the working tree is clean before local builds. An individual failed deployment does not stop other queued sites; completed releases are not automatically rolled back. Batch rollback is unsupported.

## Recovering failed deployments (unlock)

A deployment interrupted by an instance failure, out-of-memory condition or terminated process may leave a Deployer lock behind. Once you've checked that no deployment is **still running** for the selected sites, unlock them without connecting manually to the host:

```shell
vendor/bin/ssdeploy unlock test-site
vendor/bin/ssdeploy unlock test-site customer-one
vendor/bin/ssdeploy unlock test-site --stage=prod
```

The command uses the project's configured SSH/SSM transport and invokes Deployer's built-in `deploy:unlock` task against each selected deployment path. It does not run Composer, rsync, asset builds, release switching or rollback. The selected destinations and confirmation prompt appear before unlocking, unless `--yes` is specified. Use `--dry-run` to review the destinations without connecting. With multiple hosts, aliases are required; `unlock --all` is deliberately unsupported to avoid removing active locks across an entire fleet. When a host has stage-specific paths, `--stage=prod` unlocks production instead of the default staging destination. This only removes deployment locks; it does not repair a partially created release.

## Transports

- `connection: ssh`: connects to `server.host` using standard SSH configuration.
- `connection: ssm`: connects through AWS Systems Manager `AWS-StartSSHSession` and pushes a freshly generated temporary EC2 Instance Connect public key for **each SSH connection**, so later rsync connections don't rely on the initial 60-second key window. Requires AWS CLI, Session Manager Plugin, `ssh-keygen`, SSM Agent, SSH daemon and EC2 Instance Connect support configured on the instance, and IAM permissions including `ssm:StartSession` and `ec2-instance-connect:SendSSHPublicKey`. SSM SSH sessions still use SSH encryption and authentication. The instance can be private.

**Host key trust:** by default SSM uses `StrictHostKeyChecking accept-new` with an invocation-scoped temporary `known_hosts` file, matching the established EC2 deployment recipe. This removes the need for a pre-existing known-host record but does **not** authenticate a previously unknown SSH server key against a trusted external source; a substituted host key during first contact would be accepted. For stronger protection, use `server.host_key_checking: yes` and `server.known_hosts_file` containing a previously verified host key indexed by the EC2 instance ID. Do not use `StrictHostKeyChecking=no`. `server.availability_zone` is optional and forwarded to EC2 Instance Connect if supplied.

## Files and build process

- `rsync` copies local files into the new release; `deploy.yml`, `.deployignore`, `vendor` and `node_modules` should not be uploaded.
- Composer runs in the platform PHP Docker image, with local Composer cache.
- If `assets.enabled: true`, Node dependencies are cached under `/home/ubuntu/.build-cache/<project>/node` on the deployment EC2 host, protected by a lock. Per-site build workspace on local EC2 disk gets `.env`; only compiled `assets.output` is copied back to the NFS release. `package.json` and `yarn.lock` must exist.
- Silverstripe `dev/build` mounts release at `/app` and `<deploy_path>/shared` at `/shared` to resolve the release's shared symlinks.

Production compatibility with the PHP image, shell utilities, file permissions and Webpack config must be tested against an individual **non-production** site before broad rollout.

## Safety and known limitations

- `check` validates YAML shape and availability of local tools; it **does not** connect to AWS or SSH or deploy files. `--dry-run` displays target selection but does not validate remote access.
- Only basic SSH/SSM connection modes are implemented. EC2 Instance Connect, host trust, IAM and SSM prerequisites require one-time platform configuration.
- No automatic retries, batch rollback or external concurrency lock across separately invoked `ssdeploy` processes. Deployment success doesn't mean application health checks passed.
- Existing `deploy.before_symlink` and `deploy.after_symlink` commands execute as configured; only use trusted project configuration.
- `assets` assumes site-specific `.env` variables are read via `dotenv` and writes the configured output directory.
- Currently uses `node:14` by default for compatibility with the original project; this is an **end-of-life Node version** and should be upgraded as a separate compatibility project.
- First version requires further integration testing, particularly SSH host key treatment and how Deployer forwards SSH options to all connections.

## Deployment confirmation and defaults

The default deployment stage is **staging**, matching the original single-site recipe. Production deployments require `--stage=prod`. Before any deployment or rollback, the CLI displays the project name, current Git branch, stage, connection method, destination server, user, and each target's deployment path. Multi-site confirmations also show the worker limit. `--dry-run` prints the same summary without running build or deployment commands.

Single-site project names may contain spaces (e.g. `Deployment Test`); the site alias is declared under `hosts` and auto-selected when it is the only entry.

## Read-only connection test

With `server.connection: ssh` or `ssm` configured in `deploy.yml`, run:

```shell
vendor/bin/ssdeploy test-connection
```

This checks local tools, prepares the configured transport, and uses SSH to execute only `id -un` and `hostname` remotely. It does not invoke Deployer, rsync, Docker, build tasks or deployment directories. For SSM, EC2 Instance Connect temporarily registers a public key and Session Manager opens the tunnel, so AWS session activity still occurs. The command follows `server.host_key_checking`: by default it accepts a new key into temporary known-hosts storage, while strict mode (`yes`) requires pre-established host identity trust. Exit status is nonzero on failure. Temporary key material is removed after the test.
