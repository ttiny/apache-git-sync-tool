<?php
    set_time_limit(3600); // 1 hour
    
    $config = json_decode(file_get_contents("config.json"));
    
    // Check max execution time
    $output = 'Info - PHP max execution time: '.ini_get('max_execution_time').'sec<br/>';
    
    $projectName = $_GET['project'];
    $tag = $_GET['tag'];
    $branch = $_GET['branch'];
    if(!$branch) {
        $branch = 'master';
    }
    
    // Check user name and access to github
    executeCommand('Running the script as user', 'whoami', $output);
    if(executeCommand('Testing ssh access to github', 'ssh -T git@github.com', $output) == 255) {
        // Host key verification failed.
        emailSupport($config->supportEmail, $output);
        echo $output;
        return;
    }
    
    $projects = $config->projects;
    foreach($projects as $project) {
        // If there is a given project we will update only it.
        // If there isn't a given project we will update all projects
        if($projectName && $projectName != $project->name) {
            continue;
        }
        $output .= '<br/><br/>##############################<br/>'
                    .'## Processing project: <b>'.$project->name.'</b> ##<br/>';
        
        // Check autosync option
        if(!$project->autosyncEnabled) {
            // Autosync is disabled.
            $output .= '<br/>Autosync is disabled for this project in the configuration file. Skipping.';
            continue;
        }
        
        if($project->cleanAndCloneLatestVersion && $tag) {
            $output .= "<br/>Error: Tag parameter isn't allowed with cleanAndCloneLatestVersion option. To use tags disable cleanAndCloneLatestVersion in configuration file. Skipping.";
            emailSupport($config->supportEmail, $output);
            break;
        }
        
        // Check config to full clean local files.
        if($project->cleanAndCloneLatestVersion || !file_exists($project->local.'.git')) {
            if(executeCommand('Deleting project direcory: '.$project->local, 'rm -rf '.$project->local, $output)) {
                // Error (permission)
                emailSupport($config->supportEmail, $output);
                break;
            }
        }
        
        $projectExistsLocaly = file_exists($project->local);
        if(!$projectExistsLocaly) {
            // Clone remote project
            $command = 'git clone '.$project->remote.' '.$project->local;
            if($project->cleanAndCloneLatestVersion) {
                //Clone only latest version of the given branch
                $command = 'git clone --depth 1 --branch '.$branch.' '.$project->remote.' '.$project->local;
            }
            $returnCode = executeCommand("Local doesn't exist. Will clone remote.", $command, $output, $config->retryOnErrorCount);
            if($returnCode) {
                // Error, stop execution
                emailSupport($config->supportEmail, $output);
                break;
            }
        }
        
        // Change directory to project location
        if(!chdir($project->local)) {
            $output .= "<br/>Error: cant change directory to ".$project->local;
            // Error, stop execution
            emailSupport($config->supportEmail, $output);
            break;
        }
        
        if(!$project->cleanAndCloneLatestVersion) {
            // Sync sorce tree
            
            if($projectExistsLocaly) {
                // Reset. This will reset changed files to the last commit
                $command = 'git reset --hard';
                $returnCode = executeCommand('Reseting', $command, $output);
                if($returnCode) {
                    // Error, stop execution
                    emailSupport($config->supportEmail, $output);
                    break;
                }
                // Fetch will get new branches and tags
                $command = 'git fetch origin';
                $returnCode = executeCommand('Fetching', $command, $output, $config->retryOnErrorCount);
                if($returnCode) {
                    // Error, stop execution
                    emailSupport($config->supportEmail, $output);
                    break;
                }
            }
            
            // Switch to the given tag
            if($tag) {
                $command = 'git checkout tags/'.$tag;
                if(executeCommand('Switching to tag '.$tag, $command, $output)) {
                    // Error - tag doesn't exists; no permissions
                    emailSupport($config->supportEmail, $output);
                    break;
                }
            } else {
                // Switch to the given branch
                $command = 'git checkout '.$branch;
                if(executeCommand('', $command, $output)) {
                    // Error - branch doesn't exists; no permissions
                    emailSupport($config->supportEmail, $output);
                    break;
                }
                if($projectExistsLocaly) {
                    // Pull
                    $command = 'git pull origin';
                    $returnCode = executeCommand('Pulling', $command, $output, $config->retryOnErrorCount);
                    if($returnCode) {
                        // Error, stop execution
                        emailSupport($config->supportEmail, $output);
                        break;
                    }
                }
            }
        }
        
        if($project->syncSubmodules) {
            //Submodules
            $command = 'git submodule init';
            $returnCode = executeCommand('Init submodules', $command, $output, $config->retryOnErrorCount);
            if($returnCode) {
                // Error, stop execution
                emailSupport($config->supportEmail, $output);
                break;
            }
            $command = 'git submodule update';
            $returnCode = executeCommand('Update submodules', $command, $output, $config->retryOnErrorCount);
            if($returnCode) {
                // Error, stop execution
                emailSupport($config->supportEmail, $output);
                break;
            }
        }
        
        if($project->cleanAndCloneLatestVersion) {
            // Delete .git repository
            if(executeCommand('Deleting git repository: '.$project->local.'.git', 'rm -rf '.$project->local.'.git', $output)) {
                // Error (permission)
                emailSupport($config->supportEmail, $output);
                break;
            }
            
        }
        
        if($project->commandOnFinish) {
            $returnCode = executeCommand('Executing command on finish.', $project->commandOnFinish, $output);
            if($returnCode) {
                // Error executing given command
                emailSupport($config->supportEmail, $output, "Error executing given command on finish");
            }
        }
        if($project->urlOnFinish) {
            $output .= '<br/> Loading url on finish: '.$project->urlOnFinish;
            if(file_get_contents($project->urlOnFinish) === false) {
                // Error loading given url
                $output .= '<br/> Error on loading the url: ';
                emailSupport($config->supportEmail, $output, "Error loading given url on finish");
            }
        }
    }
    
    function executeCommand($description, $command, &$output, $retryOnErrorCount = 0) {
        // Execute command. Append 2>&1 to show errors.
		exec($command.' 2>&1', $result, $returnCode);
		// Output
        $output .= '<br/> ';
        if($description) {
            $output .= $description.'<br/>';
        }
		$output .= '<font color="#6BE234">$</font> <font color="#729FCF">'.$command.'</font><br/>';
		$output .= '&nbsp;&nbsp;&nbsp;'.implode('<br/> ', $result) . '<br/>';
        //$output .= ' resultCode: '.$returnCode.'<br/>';
        // Check for error
        if($returnCode && $retryOnErrorCount) {
            // Sleep some time.
            sleep(5);
            // Retry
            return executeCommand('', $command, &$output, --$retryOnErrorCount);
        }
        
        return $returnCode;
    }
    
    function emailSupport($toEmail, $message, $subject = "Error processing git pull.") {
        if($toEmail) {
            $from = "sync.php@devs.freedom-rs.com";
            $headers  = "From: $from\r\n";
            $headers .= "Content-type: text/html\r\n";
            mail($toEmail, $subject, $message, $headers);
        }
    }
    
    ?>


<!DOCTYPE HTML>
<html lang="en-US">
<head>
<meta charset="UTF-8">
<title></title>
</head>
<body style="background-color: #404040; color: #FFFFFF; padding: 0 10px;">
<?php echo $output; ?>
</body>
</html>