<?php
	////////////////////////////////
	// Init variables			 //
	// Log file name.
	$logfn = '/sync_log_' . str_replace( '.', '', (string)microtime( true ) );
	$dir = dirname( __FILE__ );

	error_reporting( E_ALL );
	ini_set( 'error_log', $dir . $logfn . '_errors.log' );
	ini_set( 'display_errors', 'On' );
	ini_set( 'log_errors_max_len', 0 );
	ini_set( 'log_errors', 'On' );

	set_time_limit( 3600 ); // 1 hour
	// Read configuration file
	$config = json_decode( file_get_contents( $dir . "/config.json" ) );

	$hadErrors = false;
	$phpInput = file_get_contents( 'php://input' );

	if ( !empty( $config->debugAll ) && $config->debugAll === true ) {
		$dir = _getLogsDir() . $logfn;
		// php 5.2 compatibility
		if ( defined( 'JSON_PRETTY_PRINT' ) ) {
			file_put_contents( $dir . '_SERVER.json', json_encode( $_SERVER, JSON_PRETTY_PRINT ) );
			file_put_contents( $dir . '_POST.json', json_encode( $_POST, JSON_PRETTY_PRINT ) );
			file_put_contents( $dir . '_GET.json', json_encode( $_GET, JSON_PRETTY_PRINT ) );
		}
		else {
			file_put_contents( $dir . '_SERVER.json', json_encode( $_SERVER ) );
			file_put_contents( $dir . '_POST.json', json_encode( $_POST ) );
			file_put_contents( $dir . '_GET.json', json_encode( $_GET ) );
		}
		file_put_contents( $dir . '_php_input.txt', $phpInput );
	}

	$branch = null;
	$project = null;
	// if things come from github don't output html
	$shouldBuffer = false;
	$payload = null;
	$shouldDeleteBranch = false;

	if ( $_SERVER[ 'REQUEST_METHOD' ] == 'POST' ) {
		$shouldBuffer = true;
		header( 'Content-Type: text/plain' );
		ob_start();
	}

	InitConfig( $config );

	register_shutdown_function( '_atExit' );


///// start the output
?><!DOCTYPE HTML>
<html lang="en-US">
<head>
	<meta charset="UTF-8">
	<title>git sync tool</title>
	<script type="text/javascript">
	function $ () {
		window.scrollTo( 0, document.body.offsetHeight );
	}
	</script>
</head>
<body style="background-color: #404040; color: #FFFFFF;">
<div style="box-sizing: border-box; padding: 15px; width: 100%; height: 100%; background-color: #404040; color: #FFFFFF;">
<?php

	if ( $_SERVER[ 'REQUEST_METHOD' ] == 'POST' ) {
		$headers = getallheaders();
		if ( is_array( $headers ) && !empty( $headers[ 'X-GitHub-Event' ] ) && $headers[ 'X-GitHub-Event' ] !== 'push' ) {
			_output( 'Don\'t know how to handle GitHub event ' . $headers[ 'X-GitHub-Event' ] . ' .' );
			exit( 1 );
		}
		
		// Payload data from github
		if ( !empty( $_POST[ 'payload' ] ) ) {
			// php < 5.4 retardness
			if ( function_exists( 'get_magic_quotes_gpc' ) && get_magic_quotes_gpc() ) {
				$_POST[ 'payload' ] = stripslashes( $_POST[ 'payload' ] );
			}
			$payload = json_decode( $_POST[ 'payload' ] );
		}
		else {
			$payload = json_decode( $phpInput );
		}

		if ( is_object( $payload ) ) {

			if ( !empty( $payload->repository->name ) ) {
				$project = $payload->repository->name;
			}
			if ( !empty( $payload->ref ) ) {
				$refPath = explode( '/', $payload->ref );
				$refType = $refPath[ count( $refPath ) - 2 ];
				if ( $refType !== 'heads' ) {
					_output( 'Don\'t know how to handle ' . $payload->ref . ' (because of ' . $refType . ', was expecting heads).' );
					exit( 1 );
				}
				$branch = $refPath[ count( $refPath ) - 1 ];
			}
			if ( !empty( $payload->deleted ) && $payload->deleted === true ) {
				$shouldDeleteBranch = true;
			}
		}
	}

	//Project
	if ( !empty( $_GET[ 'project' ] ) ) {
		$project = $_GET[ 'project' ];
	}
	if ( empty( $project ) ) {
		_output( 'No project specified.' );
		exit( 1 );
	}

	//Branch
	if ( !empty( $_GET[ 'branch' ] ) ) {
		$branch = $_GET[ 'branch' ];
	}
	if ( empty( $branch ) ) {
		_output( 'No branch specified.' );
		exit( 1 );
	}


	$shouldDeleteBranch = array_key_exists( 'delete', $_GET ) ? $_GET[ 'delete' ] : $shouldDeleteBranch;
	$clean = array_key_exists( 'clean', $_GET ) ? $_GET[ 'clean' ] : null;
	$forcesync = array_key_exists( 'forcesync', $_GET ) ? $_GET[ 'forcesync' ] : null;
	$noemail = array_key_exists( 'noemail', $_GET ) ? $_GET[ 'noemail' ] : null;
	$noonfinish = array_key_exists( 'noonfinish', $_GET ) ? $_GET[ 'noonfinish' ] : null;
	$testMode = array_key_exists( 'test', $_GET ) ? $_GET[ 'test' ] : null;

	////////////////////////////////
	// Display useful information //

	_output( 'Running GitHub synchronization...<br/>' );

	_output( '<br/>Project: ' . $project );
	_output( '<br/>Branch: ' . $branch );
	if ( $config->logs !== false ) {
		_output( '<br/>Logs: ...' . $logfn . '...<br/>' );
	}

	// Check max execution time
	//_output( 'Info - PHP max execution time: '.ini_get('max_execution_time').'sec<br/>' );
	// Check user name and access to github
	_executeCommandReal( 'Running the script as user', 'whoami' );
	if ( _executeCommandReal( 'Testing ssh access to github', 'ssh -T git@github.com' ) == 255 ) {
		// Host key verification failed.
		_emailSupport( $config->supportEmail );
		exit( 1 );
	}

	////////////////////////////////////////////
	// Process synchronization of the project //

	$projectOg = $project;
	$branchOg = $branch;

	$projects = array( $projectOg );
	if ( $projectOg === '*' ) {
		$projects = array();
		foreach ( $config->projects as $name => $value ) {
			if ( $name[ 0 ] === '~' ) {
				$projects = array_merge( $projects, $value->initial );
			}
			else {
				$projects[] = $name;
			}
		}
	}
	else if ( $projectOg[ 0 ] === '~' ) {
		$pattern = '/^' . substr( $projectOg, 1 ) . '$/';
		$projects = array();
		foreach ( $config->projects as $name => $value ) {
			if ( $name[ 0 ] === '~' ) {
				foreach ( $value->initial as $name ) {
					if ( preg_match( $pattern, $name ) > 0 ) {
						$projects[] = $name;
					}
				}
			}
			else {
				if ( preg_match( $pattern, $name ) > 0 ) {
					$projects[] = $name;
				}
			}
		}
	}

	$finishedSomething = false;

	foreach ( $projects as $project ) {

		foreach ( $config->projects as $projectName => $projectConfigOg ) {

			$finishedSomethingPrj = false;

			$matchesProject = null;
			if ( !MatchName( $project, $projectName, $matchesProject, $projectConfigOg ) ) {
				continue;
			}
			
			$projectName = $matchesProject[ 0 ];
			$projectConfig = InitProjectConfig( $config, $projectConfigOg, $matchesProject );

			_output( '<br/><br/>####<br/>#### Processing project: <b>' . $projectName . '</b><br/>####<br/>' );

			$branches = array( $branchOg );
			// loop all branches
			if ( $branchOg === '*' || $branchOg[ 0 ] === '~' ) {
				$branches = array();
				_executeCommandReal( 'Listing remote branches.', 'git ls-remote --heads ' . $projectConfig->remote, 0, null, null, function ( $command, $returnCode, $result ) use ( &$branches ) {
					if ( $returnCode ) {
						return;
					}
					preg_match_all( '/refs\\/heads\\/(.+)/', $result, $matches );
					$branches = $matches[ 1 ];
				} );
			}

			if ( $branchOg[ 0 ] === '~' ) {
				$pattern = '/^' . substr( $branchOg, 1 ) . '$/';
				$branches = array_filter( $branches, function ( $name ) use ( $pattern ) {
					return preg_match( $pattern, $name ) > 0;
				} );
			}

			if ( empty( $branches ) ) {
				continue;
			}

			if ( property_exists( $projectConfig, 'autosync' ) &&
			     $projectConfig->autosync === false &&
			     !$forcesync
			) {

				_output( '<br/>Autosync is disabled for this projects in the configuration file. Skipping.' );
				continue;
			}


			$branchesDone = array();
			foreach ( $branches as $branch ) {

				foreach ( $projectConfig->branches as $branchName => $branchConfigOg ) {
					// If there is a given branch we will update only it.
					// If there isn't a given branch we will update all branches

					$matchesBranch = null;
					if ( !MatchName( $branch, $branchName, $matchesBranch ) ) {
						continue;
					}

					if ( $branchName != '*' ) {
						$branchName = $matchesBranch[ 0 ];
					}
					if ( !empty( $branchesDone[ $branchName ] ) ) {
						continue;
					}

					$branchesDone[ $branchName ] = true;
					$branchConfig = InitBranchConfig( $config, $projectConfig, $branchConfigOg, $matchesProject, $matchesBranch );

					_output( '<br/><br/>### Processing branch: <b>' . $branchName . '</b><br/>' );
					// Check autosync option
					if ( !$branchConfig->autosync && !$forcesync ) {
						// Autosync is disabled.
						_output( '<br/>Autosync is disabled for this branch in the configuration file. Skipping.' );
						continue;
					}

					$projectExistsLocaly = file_exists( $branchConfig->local );

					// Check config to full clean local direcotry.
					if ( ($clean || ($shouldDeleteBranch && $branchName != '*')) && is_dir( $branchConfig->local ) ) {
						if ( _executeCommand( 'Deleting project directory: ' . $branchConfig->local, 'rm -rf ' . $branchConfig->local ) ) {
							// Error (permission)
							_emailSupport( $projectConfig->supportEmail );
							continue;
						}
						$projectExistsLocaly = false;
					}

					if ( $shouldDeleteBranch && $branchName != '*' ) {
						continue;
					}


					if ( !$projectExistsLocaly ) {
						// recursive option to clone submodules
						$cloneRecursive = $branchConfig->syncSubmodules ? ' --recursive ' : '';
						// options
						$bare = $branchConfig->bare ? ' --bare ' : '';
						$branchcmd = $branchName != '*' ? ' --branch ' . $branchName . ' ' : '';
						$deep = $branchConfig->deep ? '' : ' --depth 1 ';
						//Clone only latest version of the given branch
						$command = 'git clone ' . $deep . $branchcmd . $cloneRecursive . $bare . ' ' . $projectConfig->remote . ' ' . $branchConfig->local;
						$returnCode = _executeCommand( "Local doesn't exist. Will clone remote.", $command, $config->retryOnErrorCount );
						if ( $returnCode ) {
							// Error, stop execution
							_emailSupport( $projectConfig->supportEmail );
							continue;
						}
					}

					// Change directory to project location
					if ( !@chdir( $branchConfig->local ) ) {
						_output( '<br/>Error: cant change directory to ' . $branchConfig->local );
						// Error, stop execution
						_emailSupport( $projectConfig->supportEmail );
						continue;
					}

					// Sync source tree
					if ( $projectExistsLocaly ) {
						// Reset. This will reset changed files to the last commit

						if ( !$branchConfig->bare ) {
							$command = 'git reset --hard';
							$returnCode = _executeCommand( 'Reseting.', $command );
							if ( $returnCode ) {
								// Error, stop execution
								_emailSupport( $projectConfig->supportEmail );
								continue;
							}

							$command = 'git submodule foreach --recursive git reset --hard';
							$returnCode = _executeCommand( 'Reseting submodules.', $command );
							if ( $returnCode ) {
								// Error, stop execution
								_emailSupport( $projectConfig->supportEmail );
								continue;
							}
						}

						// Pull
						$branchcmd = $branchName != '*' ? 'origin ' . $branchName : 'origin "+refs/heads/*:refs/heads/*"';
						$pull = $branchConfig->bare ? 'fetch' : 'pull -s recursive -X theirs';
						$command = 'git ' . $pull . ' ' . $branchcmd;
						$returnCode = _executeCommand( 'Pulling.', $command, $config->retryOnErrorCount, '_cleanUntrackedStuff', $branchConfig );
						if ( $returnCode ) {
							// Error, stop execution
							_emailSupport( $projectConfig->supportEmail );
							continue;
						}

						if ( $branchConfig->syncSubmodules && !$branchConfig->bare ) {
							//Submodules
							$command = 'git submodule update --init --recursive';
							$returnCode = _executeCommand( 'Update submodules', $command, $config->retryOnErrorCount );
							if ( $returnCode ) {
								// Error, stop execution
								_emailSupport( $projectConfig->supportEmail );
								continue;
							}
						}
					}

					_postFinish( $branchConfig, $branchConfig, $projectConfig );

					$finishedSomething = true;
					$finishedSomethingPrj = true;
				}
			}

			if ( $finishedSomethingPrj ) {
				_postFinish( $projectConfig, $projectConfig );
			}
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

		$gconfig = $GLOBALS[ 'config' ];

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
		global $output, $log, $shouldBuffer;
		$output .= $str;
		echo str_replace( '<br/>', "<br/>\n", $str );
		if ( !$last ) {
			echo "\n",'<script>$()</script>',"\n";
		}
		else if ( $shouldBuffer && $last ) {
			ob_end_clean();
			echo _makeTxt( $output );
		}
		flush();
	}

	/**
	 *  This function executes at the end of php script.
	 *  Writes end html tags and logs to file if enabled in configuration.
	 */
	function _atExit () {
		_output( '<br/><br/>##########' );
		_output( '</div></body></html>', true );
		_saveLogs();
	}

	function _getLogsDir () {
		global $config;
		if ( !empty( $config->logs ) && is_string( $config->logs ) ) {
			$config->logs = realpath( $config->logs );
			if ( is_dir( $config->logs ) ) {
				return $config->logs;
			}
		}
		return dirname( __FILE__ );
	}

	function _makeTxt ( $output ) {
		return strip_tags( str_replace( '&nbsp;', ' ', str_replace( '<br/>', "\n", $output ) ) );
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

		$fn = _getLogsDir() . $logfn;
		@file_put_contents( $fn . '.txt', _makeTxt( $output ) );
		if ( !empty( $_POST[ 'payload' ] ) ) {
			@file_put_contents( $fn . '_payload.json', $_POST[ 'payload' ] );
		}
	}

	function _executeCommandReal () {
		global $testMode;
		$args = func_get_args();
		$t = $testMode;
		$testMode = false;
		$ret = call_user_func_array( '_executeCommand', $args );
		$testMode = $t;
		return $ret;
	}

	/**
	 *  Executes given command and writes it to to output.
	 */
	function _executeCommand ( $description, $command, $retryOnErrorCount = 0, $customRetry = null, $customArg = null, $resultCallback = null ) {
		global $config, $testMode;
		// Execute command. Append 2>&1 to show errors.
		if ( $testMode ) {
			$returnCode = 0;
			$result = array();
		}
		else {
			exec( $command . ' 2>&1', $result, $returnCode );
		}
		// Output
		_output( '<br/>' );
		_output( '<span style="font-size: smaller; color: #888888;">' . @date( 'c' ) . '</span><br/>' );
		if ( $description ) {
			_output( $description . '<br/>' );
		}
		_output( '<div style="font-family: monospace; width: 100%; box-sizing: border-box; overflow-x: auto;"><span style="color: #6BE234;">$</span> <span style="color: #729FCF;">' . $command . '</span><br/>' );
		if ( !empty( $result ) ) {
			_output( '<pre style="padding-left: 15px;">' . implode( '<br/> ', $result ) . '</pre></div><br/>' );
		}
		//_output( ' resultCode: '.$returnCode.'<br/>' );
		// Check for error
		if ( $returnCode ) {
			if ( is_callable( $customRetry ) ) {
				$returnCode = $customRetry( $command, implode( "\n", $result ), $customArg );
			}
			// Sleep some time.
			if ( $returnCode && $retryOnErrorCount ) {
				sleep( 5 );
				// Retry
				return _executeCommand( '', $command, --$retryOnErrorCount );
			}
		}

		if ( is_callable( $resultCallback ) ) {
			$resultCallback( $command, $returnCode, implode( "\n", $result ) );
		}

		return $returnCode;
	}

	function _cleanUntrackedStuff ( $command, $result, $branchConfig ) {
		$ret = preg_match( "/error: The following untracked working tree files would be overwritten by merge:\n((?:\t[^\n]+\n)*)(?:Aborting|Please move or remove them before you can merge\.)/m", $result, $matches );
		if ( $ret === 1 ) {
			$torm = array();
			foreach ( explode( "\n", $matches[ 1 ] ) as $fn ) {
				$fn = trim( $fn );
				if ( !empty( $fn ) ) {
					$torm[] = escapeshellarg( $branchConfig->local . '/' . $fn );
				}
			}
			if ( !empty( $torm ) ) {
				$torm = 'rm -rf ' . implode( ' ', $torm );
				if ( !_executeCommand( 'Cleaning conflicting untracked files.', $torm ) ) {
					return _executeCommand( 'Retrying pull.', $command, 10, '_cleanUntrackedStuff', $branchConfig );
				}
			}
		}
		return 1;
	}

	/**
	 *  Sends text/html email.
	 */
	function _emailSupport ( $toEmails, $subject = "GitHub synchronization failed" ) {
		global $output, $config, $hadErrors, $noemail, $testMode;
		$hadErrors = true;

		if ( $noemail || empty( $toEmails ) ) {
			_output( '<br/>An error occured. ' );
			return;
		}

		if ( !is_array( $toEmails ) ) {
			$toEmails = array( $toEmails );
		}
		_output( '<br/>Send email to support: ' . implode( ', ', $toEmails ) );

		if ( $testMode ) {
			return;
		}

		$from = $config->supportEmailFrom;
		$headers = "From: $from\r\n";
		$headers .= "Content-Type: text/html\r\n";
		foreach ( $toEmails as $toEmail ) {
			if ( empty( $from ) ) {
				$from = $toEmail;
			}
			mail( $toEmail, $subject, $output, $headers );
		}
	}

	function MatchName ( $nameA, $nameB, &$matches, $config = null ) {

		$matches = array( $nameA );

		if ( $nameA === '*' || $nameB === '*' || $nameA === $nameB ) {
			return true;
		}
		if ( $nameB[ 0 ] === '~' ) {
			$pattern = '/^' . substr( $nameB, 1 ) . '$/';
			return preg_match( $pattern, $nameA, $matches ) > 0;
		}

		$matches = null;
		return false;
	}

	function RenderVars ( $value, $matchesProject = null, $matchesBranch = null ) {
		global $payload;

		return preg_replace_callback( 
		
			'/\\{([^\\.\\}]+(\\.[^\\.\\}]+)*)\\}/',
		
			function ( $matches ) use ( $value, $matchesProject, $matchesBranch ) {
				$var = $matches[ 1 ];
				if ( $var === 'payload.repository.ssh_url' ) {
					return $payload->repository->ssh_url;
				}
				if ( substr( $var, 0, 9 ) == '$project.' ) {
					$var = (int)substr( $var, 9 );
					return array_key_exists( $var, $matchesProject ) ? $matchesProject[ $var ] : '';
				}
				if ( substr( $var, 0, 8 ) == '$branch.' ) {
					$var = (int)substr( $var, 8 );
					return array_key_exists( $var, $matchesBranch ) ? $matchesBranch[ $var ] : '';
				}
				return $matches[ 0 ];
			},
		
			$value
		);
	}

	function InitConfig ( $config ) {
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

		return $config;
	}

	function InitProjectConfig ( $config, $projectConfig, $matchesProject ) {
		$projectConfig = clone $projectConfig;

		if ( !property_exists( $projectConfig, 'supportEmail' ) ) {
			$projectConfig->supportEmail = $config->supportEmail;
		}
		if ( !property_exists( $projectConfig, 'commandOnFinish' ) ) {
			$projectConfig->commandOnFinish = null;
		}
		if ( !property_exists( $projectConfig, 'urlOnFinish' ) ) {
			$projectConfig->urlOnFinish = null;
		}

		foreach ( $projectConfig as $key => $value ) {
			if ( is_string( $value ) ) {
				$projectConfig->$key = RenderVars( $value, $matchesProject );
			}
		}

		return $projectConfig;
	}

	function InitBranchConfig ( $config, $projectConfig, $branchConfig, $matchesProject, $matchesBranch ) {
		$branchConfig = clone $branchConfig;

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

		foreach ( $branchConfig as $key => $value ) {
			if ( is_string( $value ) ) {
				$branchConfig->$key = RenderVars( $value, $matchesProject, $matchesBranch );
			}
		}

		return $branchConfig;
	}
?>