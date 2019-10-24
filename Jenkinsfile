def notifyBuild(String buildStatus = 'STARTED') {
    // build status of null means successful
    buildStatus =  buildStatus ?: 'SUCCESS'

    // Default values
    def colorName = 'RED'
    def colorCode = '#FF0000'
    def subject = "${buildStatus}: Job '${env.JOB_NAME} [${env.BUILD_NUMBER}]'"
    def summary = "${subject} (${env.BUILD_URL})"

    // Override default values based on build status
    if (buildStatus == 'STARTED') {
      color = 'YELLOW'
      colorCode = '#FFFF00'
    } else if (buildStatus == 'SUCCESS') {
      color = 'GREEN'
      colorCode = '#00FF00'
    } else {
      color = 'RED'
      colorCode = '#FF0000'
    }

    // Send notifications
    slackSend (color: colorCode, message: summary)
}

pipeline {
    agent {
        node {
            label 'jslave'
        }
    }
    environment {
        serviceName = "${JOB_NAME}".split('/').first()
        uriBlueocean= ""
        serverEnv = sh (
            script: '''
                    if [[ \${GIT_BRANCH} == *'feature'* ]] || [[ \${GIT_BRANCH} == *'hotfix'* ]] || [[ \${GIT_BRANCH} == *'bugfix'* ]]; then
                        echo 'feature'
                    elif [[ \${GIT_BRANCH} == *'master' ]]; then
                        echo 'alpha'
                    else
                        echo 'build not allowed in this branch'
                    fi
                    ''',
            returnStdout: true
        ).trim()
        ecrUri = '597163606641.dkr.ecr.ap-southeast-1.amazonaws.com'
        ecrCred = 'ecr:ap-southeast-1:poseidon-machine'
        dirEnvFile = "/root/env/${serviceName}"
        gitRepo = "github.com/triplogicdev/${serviceName}.git"
        domain = "triplogic.io"
        zoneIdAlb = "Z1LMS91P8CMLE5"
        zoneIdRoute53 = "Z161R4C4SZIMVZ"
        elbHostname = "dualstack.a42d18d9dd0ba11e9a94b06789d85fef-566693310.ap-southeast-1.elb.amazonaws.com"
        branchName = sh(returnStdout: true, script: "echo -e \"\$GIT_BRANCH\" | sed 's|/|-|g' | tr -d '[:space:]'").trim()
        gitCommitHash = sh(returnStdout: true, script: 'git rev-parse HEAD').trim()
        shortCommitHash = gitCommitHash.take(7)
    }
    stages {
        stage ('Build preparations') {
            parallel {
                stage ('Env') {
                    steps {
                        script {
                          notifyBuild('STARTED')

                          if (env.serverEnv == "feature") {
                              buildEnv = "snapshot"
                              withAWS(credentials:'poseidon-machine'){
                                  script {
                                      def login = ecrLogin()
                                      sh '''
                                      \${login}
                                            getHost=$(aws route53 list-resource-record-sets --hosted-zone-id \$zoneIdRoute53 | jq -r \'.[][] | select(.Name=="'"${serviceName}-${branchName}.${domain}."'") | .Name\')
                                            if [[ "\$getHost" == "\${serviceName}-\${branchName}.\${domain}." ]]; then
                                                echo "hostname up to date"
                                            else
                                                aws route53 change-resource-record-sets --hosted-zone-id \$zoneIdRoute53 --change-batch \'{ "Comment": "", "Changes": [{ "Action": "CREATE", "ResourceRecordSet": { "Name": "'"${serviceName}-${branchName}.${domain}"'", "Type": "A", "AliasTarget":{ "HostedZoneId": "'"${zoneIdAlb}"'", "DNSName": "'"${elbHostname}"'", "EvaluateTargetHealth": true }}}]}\'
                                            fi
                                        '''
                                  }
                              }
                          } else if (env.serverEnv == "alpha") {
                              buildEnv = "rc"
                              branchName = "alpha"
                          } else {
                              sh 'echo \"environment server not match\" && exit 1'
                          }
                          tagVersion = "${buildEnv}-${shortCommitHash}"
                          subdomain = "${serviceName}-${branchName}"
                        }
                    }
                }
                stage ('ECR Checking') {
                    steps {
                        withAWS(credentials:'poseidon-machine') {
                            script {
                                def login = ecrLogin()
                                sh ''' \${login}
                                  getRepoName=\$(aws --region ap-southeast-1 --output json ecr describe-repositories --repository-names \${serviceName} | jq -r '.repositories[] | .repositoryName')
                                  if [[ "\${serviceName}" != "\$getRepoName" ]]; then
                                      aws --region ap-southeast-1 --output json ecr create-repository --repository-name \${serviceName}
                                  fi
                                '''
                            }
                        }
                    }
                }
            }
        }
        stage ('Build Docker') {
            steps {
                script {
                    try {
                        //sh 'cp -f \$dirEnvFile/.env.alpha .env'
                        sh "sed -i \"s|FALSE|${tagVersion}|g\" .env"
                        docker.build("${serviceName}")
                        currentBuild.result = 'SUCCESS'
                    } catch(e) {
                        currentBuild.result = 'FAILURE'
                        throw e
                    } finally {
                        if (currentBuild.result == "FAILURE") {
                          notifyBuild(currentBuild.result)
                        }
                    }
                }
            }
        }
        stage ('Push to registry') {
            when {
                expression {
                    currentBuild.result == 'SUCCESS'
                }
            }
            steps {
                script {
                    try {
                        docker.withRegistry("https://${ecrUri}", "${ecrCred}") {
                            docker.image("${serviceName}").push("${tagVersion}")
                        }
                        currentBuild.result = 'SUCCESS'
                    } catch(e) {
                        currentBuild.result = 'FAILURE'
                        throw e
                    } finally {
                        if (currentBuild.result == "FAILURE") {
                          notifyBuild(currentBuild.result)
                        }
                    }
                }
            }
        }
        stage ('Deployment') {
            when {
                expression {
                    currentBuild.result == 'SUCCESS'
                }
            }
            steps {
                script {
                    try {
                        withKubeConfig(caCertificate: '',
                                       clusterName: 'triplogic.io',
                                       contextName: '',
                                       credentialsId: 'k8s-auth',
                                       namespace: '',
                                       serverUrl: 'https://api.triplogic.io') {


                            sh "./modification-yaml.sh ${serviceName}-${branchName} ${subdomain} ${ecrUri}/${serviceName}:${tagVersion} alpha"
                            sh '''
                              kubectl apply -f kube-yaml/deployment.yaml -n alpha
                              kubectl apply -f kube-yaml/svc.yaml -n alpha
                              kubectl apply -f kube-yaml/v-svc.yaml
                            '''
                            sh "docker rmi -f \$(docker images -q ${ecrUri}/${serviceName}:${tagVersion} | uniq) | true"
                        }
                        if (env.serverEnv == 'alpha') {
                            try {
                                timeout(time: 1, unit: 'DAYS') {
                                    env.userChoice = input message: 'Do you want to Release this build?',
                                    parameters: [choice(name: 'Versioning Service', choices: 'no\nyes', description: 'Choose "yes" if you want to release this build')]
                                }
                                if (userChoice == 'no') {
                                    echo "User refuse to release this build, stopping...."
                                }
                            } catch(Exception err) {
                                def user = err.getCauses()[0].getUser()
                                if('SYSTEM' == user.toString()) {
                                    echo "timeout reason"
                                } else {
                                    echo "Aborted by: [${user}]"
                                }
                            }
                        }
                        currentBuild.result == "SUCCESS"
                    } catch(e) {
                        currentBuild.result == "FAILURE"
                        throw e
                    } finally {
                        if (currentBuild.result == "FAILURE") {
                          notifyBuild(currentBuild.result)
                        }
                    }
                }
            }
        }
        stage ('Release') {
            when {
                environment name: 'userChoice', value: 'yes'
            }
            steps {
                script {
                    try {
                        timeout(time: 1, unit: 'DAYS') {
                            env.releaseVersion = input (
                                 id: 'version', message: 'Input version name, example 1.0.0', parameters: [
                                    [$class: 'StringParameterDefinition', description: 'Whatever you type here will be your version', name: 'Version']
                                ]
                            )
                        }
                        releaseTag = sh (
                            script: "echo \"$tagVersion\" | sed 's|${buildEnv}|${releaseVersion}|g'",
                            returnStdout: true
                        ).trim()

                        //sh 'cp -f \$dirEnvFile/.env.release .env'
                        sh "sed -i \"s|FALSE|${releaseTag}|g\" .env"
                        docker.build("${serviceName}")
                        docker.withRegistry("https://${ecrUri}", "${ecrCred}") {
                            docker.image("${serviceName}").push("${releaseTag}")
                            docker.image("${serviceName}").push("latest")
                        }

                        withCredentials([string(credentialsId: 'blueoceanTokenGithub', variable: 'tokenGit')]) {
                            sh("git tag -a ${releaseTag} -m 'Release ${releaseTag}'")
                            sh("git push https://${tokenGit}@${gitRepo} --tags")
                        }

                        withKubeConfig(caCertificate: '',
                                       clusterName: 'triplogic.io',
                                       contextName: '',
                                       credentialsId: 'k8s-auth',
                                       namespace: '',
                                       serverUrl: 'https://api.triplogic.io') {

                            sh "./modification-yaml.sh ${serviceName} ${serviceName} ${ecrUri}/${serviceName}:${releaseTag} release"
                            sh '''
                            kubectl apply -f kube-yaml/deployment.yaml -n release
                            kubectl apply -f kube-yaml/svc.yaml -n release
                            kubectl apply -f kube-yaml/v-svc.yaml
                            '''
                            sh "docker rmi -f \$(docker images -q ${ecrUri}/${serviceName}:${releaseTag} | uniq) | true"
                        }
                        currentBuild.result == "SUCCESS"
                    } catch (e) {
                        currentBuild.result == "FAILURE"
                        throw e
                    } finally {
                        if (currentBuild.result == "FAILURE") {
                          notifyBuild(currentBuild.result)
                        }
                    }
                }
            }
        }
    }
    post
      {
          always
          {
              withAWS(credentials:'poseidon-machine') {
                  script {
                      def login = ecrLogin()
                      sh ''' \${login}
                        imageToDelete=\$(aws --region ap-southeast-1 --output json ecr list-images --repository-name \${serviceName} --filter \"tagStatus=UNTAGGED\" --query 'imageIds[*]' --output json )
                        if [[ \${imageToDelete[@]} ]]; then
                            if [[ \${imageToDelete} != [] ]]; then
                                aws --region ap-southeast-1 --output json ecr batch-delete-image --repository-name \${serviceName} --image-ids \"\$imageToDelete\" || true
                            fi
                        fi
                      '''
                  }
              }
              sh "docker rmi -f \$(docker images -q ${serviceName} | uniq) | true"
              sh "docker rmi \$(docker images -f \"dangling=true\" -q)"
          }
      }
}
