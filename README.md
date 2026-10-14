# Silverstripe Deploy (experimental v0.1.0)

A Composer-distributed command-line frontend to Deployer 7. One shared recipe for single-site and multi-site deployments, plus a bounded worker queue. **Developer preview: not verified against production AWS/SSM.**

## Install

Publish this repository under `dorsetdigital/silverstripe-deploy`, then install in each project:

```shell
composer require --dev dorsetdigital/silverstripe-deploy:^0.1
```

Copy and adapt one of the examples to your project root as `deploy.yml`. The package supplies the executable (`vendor/bin/ssdeploy`), recipe, and worker queue; no PHP deploy script is needed in project repositories.

```shell
vendor/bin/ssdeploy sites
vendor/bin/ssdeploy check
vendor/bin/ssdeploy deploy logixs --dry-run
vendor/bin/ssdeploy deploy logixs
vendor/bin/ssdeploy deploy --all --workers=3
vendor/bin/ssdeploy deploy --stage=staging
vendor/bin/ssdeploy rollback logixs
```

Only `deploy --all` spawns the worker queue. Every process selects exactly one site and deploys to its already-provisioned release root. Queue stops accepting new sites only after the list is empty; individual failures do **not** undo successful deployments. Confirm once before spawning workers. Do not run two deployment batches against the same site simultaneously.

## Transports

- `connection: ssh`: connects to `server.host` using standard SSH configuration.
- `connection: ssm`: connects through AWS Systems Manager `AWS-StartSSHSession` and pushes a freshly generated temporary EC2 Instance Connect public key for **each SSH connection**, so later rsync connections don't rely on the initial 60-second key window. Requires AWS CLI, Session Manager Plugin, `ssh-keygen`, SSM Agent, SSH daemon and EC2 Instance Connect support configured on the instance, and IAM permissions including `ssm:StartSession` and `ec2-instance-connect:SendSSHPublicKey`. SSM SSH sessions still use SSH encryption and authentication. The instance can be private.

**Host key trust:** strict SSH host-key checking is enabled. Provision a trusted instance SSH host key for `ssdeploy-target` in the developer's `known_hosts_file` before using SSM. Do not use `StrictHostKeyChecking=no`. EC2 Instance Connect is not necessarily configured on a generic Ubuntu AMI; confirm it works independently first.

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
