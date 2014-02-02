apache-git-sync-tool
====================

This is a git sync(deploy) tool written in PHP that is able to receive github notifications (WebHook) and update the local git repository. Also you can make your own requests with GET paramteres to specify project and branch.  
It includes a php script file - sync.php and configuration file - config.json.  
The tool supports unlimited number of projects which should be described in the configuration file.
The local repositories are separated in project's branches. The tool creates different local repositories for every specified branch. It clones only latest revision of a branch but keeps previous synchronized revisions.  

#####All you need to do to setup it: 
1. Setup your apache user to access github.  
2. Copy sync.php and config.json into some folder, accessible on your web server where you want to deploy projects. Edit configuration and add projects to config.json.  
3. Test.  
4. Setup WebHook notifications.  

Setup ssh access to github for the apache user
----------------

1. Let say the apache user is 'www-data' ('__www' on MAC). You need to add his ssh public key to github. If you don't have generated key you need to do it with following command:  
`sudo -Hu www-data ssh-keygen -t rsa`  
Thus will generate id_rsa key for www-data user normally in /var/www/.ssh/ . You will see the location in the output. Leave passphrase blank. For successful generation /var/www/ must be with write access for www-data user.

2. Next copy content of id_rsa.pub (/var/www/.ssh/id_rsa.pub). You can do it using following command (or use a text editor):  
`pbcopy < /var/www/.ssh/id_rsa.pub`   
Thus will copy the content to clipboard.
Add the public key to github. You can find detailed description here: https://help.github.com/articles/generating-ssh-keys  
You can also add the key to specified project in github in project settings->'Deploy Keys'.

3. Finally add github.com to the list of known_hosts for the apache user using command:  
`sudo -Hu www-data ssh-keyscan github.com >> /var/www/.ssh/known_hosts`

You can test ssh access to github:  
`sudo -Hu www-data ssh -T git@github.com`


Configuration file config.json
--------------------

sync.php supports many projects in the configuration file specified in 'projects' section.
Each member of 'projects' is an object with same name as the project name and has 'remote' element which specify the remote git repository of the project (ssh url).  
You need to add configuration for each project branch that you want to be able to sync. At least one branch should be added to the configuration.

Every project has these configuration elements:  
* `supportEmail` – Email address to notify on error occurred when updating this project.  
* `branches` - Object with per-branch configuration. Only branches listed here will be synced.

Every branch has these configuration elements:
* `local` – local location of the project(repository and working tree). Must end with  '/'. Apache user 'www-data' must have write access to the parent directory.  
* `autosync` (__true__/false) – Set it to false to disable updating of the project from remote.  
* `commandOnFinish` – Which command to execute on successful update. Leave empty if you don't want to execute anything  
* `urlOnFinish` – Which url to load on successful update. Leave blank if you don't want.   
* `syncSubmodules` (__true__/false) – Tells sync.php to git update submodules.   

Other global configurable:  
* `supportEmail` – Global email address to notify on script initialization error.  
* `supportEmailFrom` – From email address to send notify on error.  
* `logs` – (true/__false__/"/path/to/specified/direcotry") - Enable writing to a log file. By default file (sync_log_miliseconds.txt) is generated in the same directory where is sync.php. Instead you are able to specify different directory.  
* `retryOnErrorCount` – How many times to retry a git clone/pull command on error.  


sync.php - parameters and examples
-------------------

Possible parameters are:
* `project` - Specify project name. Required. sync.php will try to read it from POST 'payload' json data that is send from github.com when notifying your script using web hook url. If specified as GET parameter it overrides the project name from the POST 'payload' data.   
* `branch` – Specify branch. sync.php will try to read it from POST 'payload' json data that is send from github.com when notifying your script using web hook url. If specified as GET parameter it overrides the project branch from the POST 'payload' data. If not specified sync.php will update all project branches in configuration file.
* `clean` (1/0) –  Setting this to 1 will delete local branch directory and then will make a git pull of latest revision.
* `forcesync` (1/0) –  Setting this to 1 will ignore 'autosync'=false option from the configuration file.  

##### Examples:
`sync.php?project=test` – Updates every branch of project 'test' from the configuration file config.json.  
`sync.php?project=test&branch=master` – Updates branch 'master' of project 'test'.  
`sync.php?project=test&branch=master&clean=1` – Deletes local directory for branch 'master' of project 'test' and then recreates it by cloning latest revision.

`sync.php` performs hard reset of working tree to discard local changes.

Setup WebHook notifications
-------------------
##### On github.com
Go to project *Settings -> Service Hooks -> WebHook URLs* and add your url to the sync.php script

Authors
---------
Krum Stoilov  
Borislav Peev (ideas and testing)

####Sources
https://gist.github.com/oodavid/1809044


