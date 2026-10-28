pipeline {
    agent any
    environment {
        HOME = "${env.WORKSPACE}/.ci-home"
        COMPOSER_HOME = "${env.WORKSPACE}/.ci-home/composer"
        COMPOSER_CACHE_DIR = "${env.WORKSPACE}/.ci-cache/composer"
        NPM_CONFIG_CACHE = "${env.WORKSPACE}/.ci-cache/npm"
    }
    options {
        disableConcurrentBuilds()
        skipDefaultCheckout(true)
        timestamps()
        timeout(time: 60, unit: 'MINUTES')
        buildDiscarder(logRotator(numToKeepStr: '10'))
    }
    triggers { pollSCM('H/5 * * * *') }
    stages {
        stage('Checkout fork') {
            steps {
                deleteDir()
                checkout scm
                script {
                    env.RELEASE_REVISION = sh(script: 'git rev-parse HEAD', returnStdout: true).trim()
                    env.PHP_IMAGE = "sonar-fms-php85:${env.BUILD_NUMBER}"
                    docker.build(env.PHP_IMAGE, '-f Dockerfile.ci .')
                }
            }
        }
        stage('Dependencies and assets') {
            steps {
                script {
                    docker.image(env.PHP_IMAGE).inside {
                        sh 'mkdir -p "$HOME" "$COMPOSER_HOME" "$COMPOSER_CACHE_DIR" "$NPM_CONFIG_CACHE"'
                        sh 'composer validate --strict'
                        sh 'composer install --no-interaction --prefer-dist --no-scripts --optimize-autoloader'
                        sh 'composer check-platform-reqs'
                        sh 'npm ci --no-audit --no-fund && npm run build'
                    }
                }
            }
        }
        stage('PHP 8.5 tests') {
            steps {
                script {
                    docker.image('postgres:17-alpine').withRun('-e POSTGRES_USER=sail -e POSTGRES_PASSWORD=testing -e POSTGRES_DB=fms_test') { db ->
                        docker.image(env.PHP_IMAGE).inside("--link ${db.id}:pgsql") {
                            withEnv(['DB_HOST=pgsql', 'DB_PORT=5432', 'DB_USERNAME=sail', 'DB_PASSWORD=testing']) {
                                sh 'cp .env.testing.example .env.testing'
                                sh 'bash tests/DeploymentRollbackTest.sh'
                                sh 'php scripts/wait-for-test-db.php'
                                sh 'vendor/bin/phpunit --log-junit test-results.xml'
                            }
                        }
                    }
                }
            }
            post { always { junit allowEmptyResults: true, testResults: 'test-results.xml' } }
        }
        stage('Package production release') {
            steps {
                script {
                    docker.image(env.PHP_IMAGE).inside {
                        sh 'composer install --no-dev --no-interaction --prefer-dist --no-scripts --optimize-autoloader'
                        sh 'composer check-platform-reqs --no-dev'
                        sh 'rm -f bootstrap/cache/*.php public/hot'
                        sh 'printf "%s\\n" "$RELEASE_REVISION" > public/release-revision.txt'
                    }
                }
                tar file: 'site.tar.gz', overwrite: true, glob: 'app/**,artisan,bootstrap/**,composer.json,composer.lock,config/**,database/**,lang/**,public/**,resources/views/**,routes/**,vendor/**'
                archiveArtifacts artifacts: 'site.tar.gz', fingerprint: true
            }
        }
        stage('Deploy production') {
            steps {
                script {
                    writeFile file: 'sonar-known-hosts', text: '10.100.1.145 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILhNjX84joFqcZMjHx0II8n9YgcCtfQVftu83l54NzqW\n'
                    def remote = [name: 'invoices', host: '10.100.1.145', knownHosts: "${pwd()}/sonar-known-hosts"]
                    sh 'cp scripts/deploy.sh sonar-deploy.sh'
                    withCredentials([sshUserPrivateKey(credentialsId: 'gholle', keyFileVariable: 'identity', usernameVariable: 'deployUser')]) {
                        remote.user = deployUser
                        remote.identityFile = identity
                        sshPut remote: remote, from: 'site.tar.gz', into: '.'
                        sshPut remote: remote, from: 'sonar-deploy.sh', into: '.'
                        sshCommand remote: remote, command: "bash ./sonar-deploy.sh ${env.RELEASE_REVISION}"
                    }
                }
            }
        }
    }
}
