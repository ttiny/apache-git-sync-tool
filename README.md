apache-git-sync-tool
====================

This is a git sync(deploy) tool written in PHP that is able to receive github notifications and update the local git repositories.  
It includes a php script file - sync.php and configuration file - config.json.  
The local repositories are based on project's branches. The tool creates different local repositories for each specified branch.  
You will need to setup your apache user to access github.

Setup ssh access to github for the apache user
----------------

1. Let say the apache user is 'www-data'. We need to add his ssh public key to github. If we don't have generated key we need to do it with following command:  
**sudo -Hu www-data ssh-keygen -t rsa**  
Thus will generate id_rsa key for www-data user normally in /var/www/.ssh/ . You will see the location in the output. Leave passphrase blank. For successful generation /var/www/ must be with write access for www-data user.

2. Next copy content of id_rsa.pub (/var/www/.ssh/id_rsa.pub). You can do it using following command:  
**pbcopy < /var/www/.ssh/id_rsa.pub**  
Thus will copy the content to clipboard.
Add the public key to github. You can find detailed description here: https://help.github.com/articles/generating-ssh-keys
You can also add the key to specified project in github in project settings->'Deploy Keys'.

3. Finally add github.com to the list of known_hosts for the apache user using command:  
**sudo -Hu www-data ssh-keyscan github.com >> /var/www/.ssh/known_hosts**

You can test ssh access to github:  
**sudo -Hu www-data ssh -T git@github.com**


Configuration file config.json
--------------------

sync.php supports many projects in the configuration file specified in 'projects'.
Each member of 'projects' is a name of a project and has 'remote' element which specify the remote git repository of the project.  
You need to add configuration for all project branches that you want to be able to sync. At least one branch should be added to the configuration.  
Every branch has these configuration elements:    
* *local* – local location of the project(repository and working tree). Must end with  '/'. Apache user 'www-data' must have write access to the parent directory.  
* *autosync* (true/false) – Set it to false to disable updating of the project from remote.  
* *commandOnFinish* – Which command to execute on successful update. Leave empty if you don't want to execute anything  
* *urlOnFinish* – Which url to load on successful update. Leave blank if you don't want.   
* *syncSubmodules* (true/false)– Tells sync.php to git update submodules.   

Other configurable:  
* *supportEmail* – Email address to notify on error.  
* *supportEmailFrom* – From email address to send notify on error.  
* *logs* – (true/false/"path") - Enable writing to a log file. By default file name is auto generated but instead you can specify a path.  
* *retryOnErrorCount* – How many times to retry a git clone/pull command on error.  


sync.php - parameters and examples
-------------------

Possible parameters are:
* *project* - Specify project name. Required. If not specified in GET parameters, sync.php will try to read it from POST payload json data that is send from github when requesting your web hook url.
* *branch* – Specify branch. If not specified sync.php will update all project branches in configuration file.
* *clean* (1/0) –  Setting this to 1 will delete local branch directory and then will make a git pull of latest version.
* *forcesync* (1/0) –  Setting this to 1 will ignore 'autosync'=false option from the configuration file. 

Examples:  
*sync.php?project=test* – Updates all branches from the configuration file config.json of project 'test'.  
*sync.php?project=test&branch=master* – Updates branch 'master' of project 'test'.
*sync.php?project=test&branch=master&clean=1* – Deletes local directory for branch 'master' of project 'test' and then recreates it by cloning latest version.

sync.php makes hard reset of working tree to discard local changes.


