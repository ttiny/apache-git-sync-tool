<?php
/*
 * Free to use, copy and modify.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 */
?>
<!DOCTYPE HTML>
<html lang="en-US">
<head>
<meta charset="UTF-8">
<title>git sync tool</title>
</head>
<body style="background-color: #404040; color: #FFFFFF; padding: 0 10px;">

<?php

    ////////////////////////////////
    // Init variables             //
    
    // Log file name.
    $logfn = '/sync_log_' . str_replace( '.', '', (string)microtime( true ) );

    error_reporting( E_ALL ^ E_NOTICE );
    ini_set( 'error_log', dirname( __FILE__ ) . $logfn . '_errors.log' );
    ini_set( 'display_errors', 'On' );
    ini_set( 'log_errors_max_len', 0 );
    ini_set( 'log_errors', 'On' );
    
    set_time_limit(3600); // 1 hour
    
    // Read configuration file
    $config = json_decode(file_get_contents("config.json"));
    $hadErrors = false;

    $branch = null;
    $project = null;
    
    // Payload data from github
    if($_POST['payload']) {
        // php < 5.4 retardness
        if ( function_exists( 'get_magic_quotes_gpc' ) && get_magic_quotes_gpc() ) {
            $_POST['payload'] = stripslashes( $_POST['payload'] );
        }
        $push = json_decode($_POST['payload']);
        $project = $push->repository->name;
        $refPath = explode("/", $push->ref);
        $branch = $refPath[count($refPath) - 1];
    }

    register_shutdown_function( '_atexit' );
    
    //Project
    if($_GET['project']) {
        $project = $_GET['project'];
    }
    if(!$project) {
       _output( 'No project specified.' );
       exit( 1 );
        
    }
    
    //Branch
    if($_GET['branch']) {
        $branch = $_GET['branch'];
    }

    //Check for given project in configuration
    if ( !empty( $project ) && !property_exists( $config->projects, $project ) ) {
        _output( 'Nothing to do for project ' . $project . '.' );
        exit( 1 );
    }

    //Check for given branch in configuration
    if ( !empty( $branch ) && !property_exists( $config->projects->$project->branches, $branch ) ) {
        _output( 'Nothing to do for branch ' . $branch . '.' );
        exit( 1 );
    }
    
    $clean = $_GET['clean'];
    $forcesync = $_GET['forcesync'];
    
    ////////////////////////////////
    // Display useful information //
    
    // Check max execution time
    _output( 'Info - PHP max execution time: '.ini_get('max_execution_time').'sec<br/>' );

    if ( !property_exists( $config, 'logs' ) ) {
        $config->logs = true;
    }
    if ( !property_exists( $config, 'debug' ) ) {
        $config->debug = false;
    }
    if ( !property_exists( $config, 'supportEmail' ) ) {
        $config->supportEmail = null;
    }
    if ( !property_exists( $config, 'supportEmailFrom' ) ) {
        $config->supportEmailFrom = null;
    }
    
    // Check user name and access to github
    _executeCommand('Running the script as user', 'whoami');
    if(_executeCommand('Testing ssh access to github', 'ssh -T git@github.com') == 255) {
        // Host key verification failed.
        _emailSupport($config->supportEmail);
        exit( 1 );
    }
    
    _output( '<br/>Project: '.$project );
    _output( '<br/>Branch: '.$branch );
    
    ////////////////////////////////////////////
    // Process synchronization of the project //
    
    $projectConfig = $config->projects->$project;
    if ( !property_exists( $projectConfig, 'supportEmail' ) ) {
        $projectConfig->supportEmail = $config->supportEmail;
    }

    _output( '<br/><br/>##############################<br/>' );
    _output( '## Processing project: <b>'.$project.'</b> ##' );
    
    $branches = get_object_vars($projectConfig->branches);
    foreach($branches as $branchName => $branchConfig) {
        // If there is a given branch we will update only it.
        // If there isn't a given branch we will update all branches
        if($branch && $branch != $branchName) {
            continue;
        }

        if ( !property_exists( $branchConfig, 'autosync' ) ) {
            $branchConfig->autosync = true;
        }
        if ( !property_exists( $branchConfig, 'syncSubmodules' ) ) {
            $branchConfig->syncSubmodules = true;
        }
        if ( !property_exists( $branchConfig, 'commandOnFinish' ) ) {
            $branchConfig->commandOnFinish = null;
        }
        if ( !property_exists( $branchConfig, 'urlOnFinish' ) ) {
            $branchConfig->urlOnFinish = null;
        }
        
        _output( '<br/><br/>'.'## Processing branch: <b>'.$branchName.'</b> ##<br/>' );
        // Check autosync option
        if(!$branchConfig->autosync && !$forcesync) {
            // Autosync is disabled.
            _output( '<br/>Autosync is disabled for this branch in the configuration file. Skipping.' );
            continue;
        }
        
        $projectExistsLocaly = file_exists($branchConfig->local);
        
        // Check config to full clean local direcotry.
        if($clean && is_dir( $branchConfig->local )) {
            if(_executeCommand('Deleting project directory: '.$branchConfig->local, 'rm -rf '.$branchConfig->local)) {
                // Error (permission)
                _emailSupport($projectConfig->supportEmail);
                break;
            }
            $projectExistsLocaly = false;
        }
        
        
        if(!$projectExistsLocaly) {
            // recursive option to clone submodules
            $cloneRecursive = $branchConfig->syncSubmodules ? ' --recursive ' : '';
            //Clone only latest version of the given branch
            $command = 'git clone --depth 1 --branch '.$branchName.$cloneRecursive.' '.$projectConfig->remote.' '.$branchConfig->local;
            $returnCode = _executeCommand("Local doesn't exist. Will clone remote.", $command, $config->retryOnErrorCount);
            if($returnCode) {
                // Error, stop execution
                _emailSupport($projectConfig->supportEmail);
                break;
            }
        }
        
        // Change directory to project location
        if(!chdir($branchConfig->local)) {
            _output( "<br/>Error: cant change directory to ".$branchConfig->local );
            // Error, stop execution
            _emailSupport($projectConfig->supportEmail);
            break;
        }
        
        // Sync sorce tree
        if($projectExistsLocaly) {
            // Reset. This will reset changed files to the last commit
            $command = 'git reset --hard';
            $returnCode = _executeCommand('Reseting', $command);
            if($returnCode) {
                // Error, stop execution
                _emailSupport($projectConfig->supportEmail);
                break;
            }
            
            // Pull
            $command = 'git pull origin' . ' ' . $branchName;
            $returnCode = _executeCommand('Pulling', $command, $config->retryOnErrorCount);
            if($returnCode) {
                // Error, stop execution
                _emailSupport($projectConfig->supportEmail);
                break;
            }
            
            if($branchConfig->syncSubmodules) {
                //Submodules
                $command = 'git submodule update --init --recursive';
                $returnCode = _executeCommand('Update submodules', $command, $config->retryOnErrorCount);
                if($returnCode) {
                    // Error, stop execution
                    _emailSupport($projectConfig->supportEmail);
                    break;
                }
            }
        }
        
        _executeCommand( 'Granting group write permission.', 'chmod -R g+w ' . $branchConfig->local );
        if ( $returnCode ) {
            // Error, stop execution
            emailSupport( $projectConfig->supportEmail );
            break;
        }
        
        if($branchConfig->commandOnFinish) {
            $returnCode = _executeCommand('Executing command on finish.', $branchConfig->commandOnFinish);
            if($returnCode) {
                // Error executing given command
                _emailSupport($projectConfig->supportEmail, "Error executing given command on finish");
            }
        }
        if($branchConfig->urlOnFinish) {
            _output( '<br/> Loading url on finish: '.$branchConfig->urlOnFinish );
            if(file_get_contents($branchConfig->urlOnFinish) === false) {
                // Error loading given url
                _output( '<br/> Error on loading the url: ' );
                _emailSupport($projectConfig->supportEmail, "Error loading given url on finish");
            }
        }

    }
    
    ////////////////////////////////
    // Used functions             //
    
    /**
     *  Writes given string to the output.
     */
    function _output( $str, $last = false ) {
        global $output, $log;
        $output .= $str;
        echo $str;
        if ( !$last ) {
            echo '<script type="text/javascript">window.scrollTo(0,document.body.offsetHeight);</script>';
        }
        flush();
    }
    
    /**
     *  This function executes at the end of php script.
     *  Writes end html tags and logs to file if enabled in configuration.
     */
    function _atexit () {
        _output( '<br/><br/>##############################' );
        _output( '</body></html>', true );
        _saveLogs();
    }

    function _saveLogs () {
        global $output, $config, $logfn, $hadErrors;
        if ( empty( $config->logs ) || $config->logs === false ) {
            return;
        }

        // don't save logs if we don't have errors and we didn't request it explicitly
        if ( $hadErrors === false && $config->debug !== true ) {
            return;
        }
        
        if ( $config->logs === true ) {
            $config->logs = dirname( __FILE__ );
        }
        
        if ( is_string( $config->logs ) ) {
            $config->logs = realpath( $config->logs );
            if ( is_dir( $config->logs ) ) {
                $fn = $config->logs . $logfn;
                @file_put_contents( $fn . '.txt', strip_tags( str_replace( '&nbsp;', ' ', str_replace( '<br/>', "\n", $output ) ) ) );
                if ( !empty( $_POST['payload'] ) ) {
                    @file_put_contents( $fn . '_payload.json', $_POST['payload'] );
                }
            }
        }
    }
    
    /**
     *  Executes given command and writes it to to output.
     */
    function _executeCommand($description, $command, $retryOnErrorCount = 0) {
        global $config;
        // Execute command. Append 2>&1 to show errors.
        exec($command.' 2>&1', $result, $returnCode);
        // Output
        _output( '<br/>' );
        _output( '<span style="font-size: smaller; color: #888888;">' . @date( 'c' ) . '</span><br/>' );
        if($description) {
            _output( $description.'<br/>' );
        }
        _output(
            '<div style="font-family: monospace;"><span style="color: #6BE234;">$</span> <span style="color: #729FCF;">'.$command.'</span><br/>' .
            '<div style="padding-left: 15px;">' . implode('<br/> ', $result) . '</div></div><br/>'
        );
        //_output( ' resultCode: '.$returnCode.'<br/>' );
        // Check for error
        if($returnCode && $retryOnErrorCount) {
            // Sleep some time.
            sleep(5);
            // Retry
            return _executeCommand('', $command, --$retryOnErrorCount);
        }
        
        return $returnCode;
    }
    
    /**
     *  Sends text/html email.
     */
    function _emailSupport($toEmail, $subject = "Error processing git pull.") {
        global $output, $config, $hadErrors;
        $hadErrors = true;
        if($toEmail) {
            _output( '<br/>Send email to support: '.$toEmail );
            $from = $config->supportEmailFrom;
            if ( empty( $from ) ) {
                $from = $toEmail;
            }
            $headers  = "From: $from\r\n";
            $headers .= "Content-type: text/html\r\n";
            mail($toEmail, $subject, $output, $headers);
        }
    }
    
    ?>
