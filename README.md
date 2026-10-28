# Sonar FMS

PHP 8.5.12 or newer in the 8.5 series is used for local development, Jenkins, web requests, Horizon, and scheduled commands. Node.js 22 is used to build assets.

## Local verification

Start the managed workspace with `dev-workspace up sonar-fms`. Its PHP 8.5 image includes PostgreSQL, Redis, Intl, spreadsheet extensions, Chromium, and Puppeteer support. Use the manager's Composer, npm, artisan, and exec wrappers.

Copy `.env.testing.example` to `.env.testing` for disposable tests. The test database must be named `fms_test`; the suite refreshes it. Run dependency installation, `composer check-platform-reqs`, `npm ci`, `npm run build`, and `vendor/bin/phpunit` inside the workspace. Use test credentials and mocked integrations only.

## PDF rendering

The managed local image and CI image install Node.js 22, Puppeteer 25.10, and Chrome/Chromium for Browsershot. Production needs the same Node/Puppeteer setup and a browser executable accessible to `www-data`. Keep browser dependencies in the image or server provisioning; do not install them through application requests.

For a dedicated production PDF runtime, set `NODE_BINARY`, `NPM_BINARY`, `BROWSERSHOT_NODE_MODULE_PATH`, and `BROWSERSHOT_CHROME_PATH` to its absolute paths before caching configuration. Both invoice and receipt rendering use the same factory and configuration.

## Deployment

The Jenkins job `invoices.lifepluslearning.com` loads `Jenkinsfile` from `https://github.com/cars1n/sonar-fms.git`, branch `main`. It polls that branch every five minutes and also supports manual builds. Concurrent deployments are disabled.

The pipeline builds `Dockerfile.ci`, installs locked dependencies without ignoring platform requirements, builds assets with Node 22, and runs the complete PHP suite against a disposable PostgreSQL database. It then removes development dependencies, archives the release with its Git revision, and deploys to `invoices` (`10.100.1.145`) using the existing `gholle` Jenkins SSH credential. The SSH host key is pinned to the key verified through the managed production connection.

`scripts/deploy.sh` prepares a release under `/var/www/invoices.ldiglobal.org/releases`, links persistent environment and storage paths, and verifies Composer's platform check before atomically updating `live`. It reloads PHP 8.5 FPM, restarts Horizon gracefully, and checks the login route and release revision. A failure after switching restores the previous release. Previous releases are retained. Database migrations require a separate, reviewed deployment change.

Production Nginx must use `/run/php/php8.5-fpm.sock`. Supervisor runs `/usr/bin/php8.5 /var/www/invoices.ldiglobal.org/live/artisan horizon` as `www-data`; the `www-data` scheduler uses `/usr/bin/php8.5 artisan schedule:run`. Keep the CLI alternative on `/usr/bin/php8.5`.

To roll back application code, atomically repoint `live` to a retained, PHP 8.5 compatible release, reload `php8.5-fpm`, and restart `sonar-fms-worker`. Server configuration backups are kept outside the application tree.
