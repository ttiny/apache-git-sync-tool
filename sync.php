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
<body style="background-color: #404040; color: #FFFFFF;">

<?php

	////////////////////////////////
	// Init variables			 //
	
	// Log file name.
	$logfn = '/sync_log_' . str_replace( '.', '', (string)microtime( true ) );

	error_reporting( E_ALL );
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
	if ( !empty( $_POST['payload'] ) ) {
		// php < 5.4 retardness
		if ( function_exists( 'get_magic_quotes_gpc' ) && get_magic_quotes_gpc() ) {
			$_POST['payload'] = stripslashes( $_POST['payload'] );
		}
		$push = json_decode($_POST['payload']);
		$project = $push->repository->name;
		$refPath = explode("/", $push->ref);
		$branch = $refPath[count($refPath) - 1];
	}

	// some defaults
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
	if ( !property_exists( $config, 'commandOnFinish' ) ) {
		$config->commandOnFinish = null;
	}
	if ( !property_exists( $config, 'urlOnFinish' ) ) {
		$config->urlOnFinish = null;
	}

	register_shutdown_function( '_atexit' );

	_output( '<div style="box-sizing: border-box; padding: 15px; width: 100%; height: 100%; background-color: #404040; color: #FFFFFF;">' );
	
	//Project
	if ( !empty( $_GET['project'] ) ) {
		$project = $_GET['project'];
	}
	if ( empty( $project ) ) {
	   _output( 'No project specified.' );
	   exit( 1 );
	}
	
	//Branch
	if ( !empty( $_GET['branch'] ) ) {
		$branch = $_GET['branch'];
	}
	if ( empty( $branch ) ) {
	   _output( 'No branch specified.' );
	   exit( 1 );
	}

	//Check for given project in configuration
	if ( $project != '*' && !property_exists( $config->projects, $project ) ) {
		
		_output( 'Unknown project ' . $project . '.' );
		exit( 1 );
		
		//Check for given branch in configuration
		if ( $branch != '*' &&
			!property_exists( $config->projects->$project->branches, $branch ) &&
			!property_exists( $config->projects, '*' )
		) {	

			_output( 'Unknown branch ' . $branch . '.' );
			exit( 1 );
		}
	}

	
	$clean = array_key_exists( 'clean', $_GET ) ? $_GET['clean'] : null;
	$forcesync = array_key_exists( 'forcesync', $_GET ) ? $_GET['forcesync'] : null;
	$noemail = array_key_exists( 'noemail', $_GET ) ? $_GET['noemail'] : null;
	$noonfinish = array_key_exists( 'noonfinish', $_GET ) ? $_GET['noonfinish'] : null;
	
	////////////////////////////////
	// Display useful information //

	_output( 'Running GitHub synchronization...<br/>' );

	_output( '<br/>Project: '.$project );
	_output( '<br/>Branch: '.$branch );
	if ( $config->logs !== false ) {
		_output( '<br/>Logs: ...' . $logfn . '...<br/>' );
	}
	
	// Check max execution time
	//_output( 'Info - PHP max execution time: '.ini_get('max_execution_time').'sec<br/>' );
	
	// Check user name and access to github
	_executeCommand('Running the script as user', 'whoami');
	if(_executeCommand('Testing ssh access to github', 'ssh -T git@github.com') == 255) {
		// Host key verification failed.
		_emailSupport($config->supportEmail);
		exit( 1 );
	}
	
	////////////////////////////////////////////
	// Process synchronization of the project //
	
	foreach ( $config->projects as $projectName => $projectConfig ) {

		$finishedSomethingPrj = false;

		if ( $project != '*' && $project != $projectName ) {
			continue;
		}

		_output( '<br/><br/>####<br/>#### Processing project: <b>'.$projectName.'</b><br/>####<br/>' );
		
		if ( 
			 property_exists( $projectConfig, 'autosync' ) && 
		     $projectConfig->autosync === false &&
		     !$forcesync ) {
			
			_output( '<br/>Autosync is disabled for this projects in the configuration file. Skipping.' );
			continue;
		}

		if ( !property_exists( $projectConfig, 'supportEmail' ) ) {
			$projectConfig->supportEmail = $config->supportEmail;
		}
		if ( !property_exists( $projectConfig, 'commandOnFinish' ) ) {
			$projectConfig->commandOnFinish = null;
		}
		if ( !property_exists( $projectConfig, 'urlOnFinish' ) ) {
			$projectConfig->urlOnFinish = null;
		}
		
		$finishedSomething = false;
		
		foreach ( $projectConfig->branches as $branchName => $branchConfig ) {
			// If there is a given branch we will update only it.
			// If there isn't a given branch we will update all branches
			if ( $branch != '*' && $branch != $branchName && $branchName != '*' ) {
				continue;
			}

			if ( !property_exists( $branchConfig, 'autosync' ) ) {
				$branchConfig->autosync = true;
			}
			if ( !property_exists( $branchConfig, 'bare' ) ) {
				$branchConfig->bare = false;
			}
			if ( !property_exists( $branchConfig, 'deep' ) ) {
				$branchConfig->deep = false;
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
			if ( !property_exists( $branchConfig, 'supportEmail' ) ) {
				$branchConfig->supportEmail = null;
			}
			
			_output( '<br/><br/>### Processing branch: <b>'.$branchName.'</b><br/>' );
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
				// options
				$bare = $branchConfig->bare ? ' --bare ' : '';
				$branch = $branchName != '*' ? ' --branch '.$branchName.' ' : '';
				$deep = $branchConfig->deep ? '' : ' --depth 1 ';
				//Clone only latest version of the given branch
				$command = 'git clone '.$deep.$branch.$cloneRecursive.$bare.' '.$projectConfig->remote.' '.$branchConfig->local;
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

				if ( !$branchConfig->bare ) {
					$command = 'git reset --hard';
					$returnCode = _executeCommand('Reseting.', $command);
					if($returnCode) {
						// Error, stop execution
						_emailSupport($projectConfig->supportEmail);
						break;
					}

					$command = 'git submodule foreach --recursive git reset --hard';
					$returnCode = _executeCommand('Reseting submodules.', $command);
					if($returnCode) {
						// Error, stop execution
						_emailSupport($projectConfig->supportEmail);
						break;
					}
				}
				
				// Pull
				$branchcmd = $branchName != '*' ? 'origin '.$branchName : '"+refs/heads/*:refs/heads/*"';
				$pull = $branchConfig->bare ? 'fetch' : 'pull -s recursive -X theirs';
				$command = 'git '.$pull.' '.$branchcmd;
				$returnCode = _executeCommand('Pulling', $command, $config->retryOnErrorCount, '_cleanUntrackedStuff', $branchConfig );
				if($returnCode) {
					// Error, stop execution
					_emailSupport($projectConfig->supportEmail);
					break;
				}
				
				if ( $branchConfig->syncSubmodules && !$branchConfig->bare ) {
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

			_postFinish( $branchConfig, $branchConfig, $projectConfig );

			$finishedSomething = true;
			$finishedSomethingPrj = true;
		}

		if ( $finishedSomethingPrj ) {
			_postFinish( $projectConfig, $projectConfig );
		}
	}

	if ( $finishedSomething ) {
		_postFinish( $config, $config );
	}
	
	////////////////////////////////
	// Used functions			 //

	function _postFinish ( $config, $emailConfig, $emailConfig2 = null ) {
		
		global $noonfinish;

		if ( $noonfinish ) {
			return;
		}

		$gconfig = $GLOBALS['config'];

		$email = null;
		if ( $emailConfig instanceof Object && !empty( $emailConfig->supportEmail ) ) {
			$email = $emailConfig->supportEmail;
		}
		else if ( $emailConfig2 instanceof Object && !empty( $emailConfig2->supportEmail ) ) {
			$email = $emailConfig2->supportEmail;
		}
		else if ( $gconfig instanceof Object && !empty( $gconfig->supportEmail ) ) {
			$email = $gconfig->supportEmail;
		}

		if ( $config->commandOnFinish ) {
			$cmds = is_array( $config->commandOnFinish ) ? $config->commandOnFinish : array( $config->commandOnFinish );
			foreach ( $cmds as $cmd ) {
				$returnCode = _executeCommand( 'Executing command on finish.', $cmd );
				if ( $returnCode ) {
					// Error executing given command
					_emailSupport( $emailConfig->supportEmail );
					exit( 1 );
				}
			}
		}
		
		if ( $config->urlOnFinish ) {
			$urls = is_array( $config->urlOnFinish ) ? $config->urlOnFinish : array( $config->urlOnFinish );
			foreach ( $urls as $url ) {
				_output( '<br/> Loading url on finish: ' . $url );
				if ( file_get_contents( $url ) === false ) {
					// Error loading given url
					_output( '<br/> Error on loading the url.' );
					_emailSupport( $emailConfig->supportEmail );
					exit( 1 );
				}
			}
		}
	}
	
	/**
	 *  Writes given string to the output.
	 */
	function _output ( $str, $last = false ) {
		global $output, $log;
		$output .= $str;
		echo $str;
		if ( !$last ) {
			echo '<script type="text/javascript">window.scrollTo( 0, document.body.offsetHeight );</script>';
		}
		flush();
	}
	
	/**
	 *  This function executes at the end of php script.
	 *  Writes end html tags and logs to file if enabled in configuration.
	 */
	function _atexit () {
		_output( '<br/><br/>##########' );
		_output( '</div></body></html>', true );
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
	function _executeCommand($description, $command, $retryOnErrorCount = 0, $customRetry = null, $customArg = null ) {
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
			'<div style="font-family: monospace; width: 100%; box-sizing: border-box; overflow-x: auto;"><span style="color: #6BE234;">$</span> <span style="color: #729FCF;">'.$command.'</span><br/>' .
			'<pre style="padding-left: 15px;">' . implode('<br/> ', $result) . '</pre></div><br/>'
		);
		//_output( ' resultCode: '.$returnCode.'<br/>' );
		// Check for error
		if ( $returnCode ) {
			if ( is_callable( $customRetry ) ) {
				$returnCode = $customRetry( $command, implode( "\n", $result ), $customArg );
			}
			// Sleep some time.
			if ( $returnCode && $retryOnErrorCount ) {
				sleep(5);
				// Retry
				return _executeCommand('', $command, --$retryOnErrorCount);
			}
		}
		
		return $returnCode;
	}

	function _cleanUntrackedStuff ( $command, $result, $branchConfig ) {
		$ret = preg_match( "/error: The following untracked working tree files would be overwritten by merge:\n([\s\S]*)\nPlease move or remove them before you can merge\./m", $result, $matches );
		if ( $ret === 1 ) {
			$torm = array();
			foreach ( explode( "\n", $matches[1] ) as $fn ) {
				$fn = trim( $fn );
				if ( !empty( $fn ) ) {
					$torm[] = escapeshellarg( $branchConfig->local . '/' . $fn );
				}
			}
			if ( !empty( $torm ) ) {
				$torm = 'rm -rf ' . implode( ' ', $torm );
				if ( !_executeCommand( 'Cleaning conflicting untracked files.', $torm ) ) {
					return _executeCommand( 'Retrying pull.', $command );
				}
			}
		}
		return 1;
	}
	
	
	/**
	 *  Sends text/html email.
	 */
	function _emailSupport ( $toEmails, $subject = "GitHub synchronization failed" ) {
		global $output, $config, $hadErrors, $noemail;
		$hadErrors = true;
		
		if ( $noemail || empty( $toEmails ) ) {
			_output( '<br/>An error occured. ' );
			return;
		}
		
		if ( !is_array( $toEmails ) ) {
			$toEmails = array( $toEmails );
		}
		_output( '<br/>Send email to support: ' . implode( ', ', $toEmails ) );
		
		$from = $config->supportEmailFrom;
		$headers  = "From: $from\r\n";
		$headers .= "Content-type: text/html\r\n";
		foreach ( $toEmails as $toEmail ) {
			if ( empty( $from ) ) {
				$from = $toEmail;
			}
			mail( $toEmail, $subject, $output, $headers );
		}
	}
	
?>