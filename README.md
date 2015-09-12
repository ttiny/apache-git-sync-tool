&nbsp;  
&nbsp;  
&nbsp;  
&nbsp;  
# DEPRECATED in favour of <https://github.com/Perennials/deploy>.
&nbsp;  
&nbsp;  
&nbsp;  
&nbsp;  
&nbsp;  
&nbsp;  
&nbsp;  
&nbsp;  
&nbsp;  
apache-git-sync-tool
====================

This is a git sync (deploy) tool written in PHP that is able to receive GitHub
and BitBucket notifications (WebHook) and update the local git repository.
Also you can make your own requests with GET paramteres to specify project and
branch. It includes a php script file - sync.php and configuration file -
config.json. The tool supports unlimited number of projects which should be
described in the configuration file. The local repositories are separated in
project's branches. The tool creates different local repositories for every
specified branch. It clones only latest revision of a branch but keeps
previous synchronized revisions.

__Warning:__ The tool performs hard reset of working tree to discard local
changes. Also, if there are any untracked files that prevent the pull from
happening, these will be deleted. In other words the tool is only meant for
mirroring repositories and not for use with real working copy.

##### All you need to do to setup it: 
1. Setup your apache user to access GitHub or BitBucket.
2. Copy sync.php and config.json into some folder, accessible on your web
   server where you want to deploy projects. Edit configuration and add
   projects to `config.json` (or `./config/local.json`).
3. Test.
4. Setup WebHook notifications.

__Note:__ Tested with _git 1.7.10.4_.


Changelog
---------

### 0.8
- Add BitBucket support.
- Prefer to save logs to `./log`, if it exists.
- Prefer to load config from `./config/local.json`, if it exists.

### 0.7
- Ignore GitHub notifications for tag creation.
- Handle GitHub notifications for branch deletion.
- Add `delete` GET parameter to delete branch(es).

### 0.6
- Started keeping changelog and versions.
- Added support for regexes in project/branch name: names starting with `~` are
  considered regex (after the tilde).
- Added support for variables in the config values:
  - `{payload.repository.ssh_url}`
  - `{$project.N}`, `{$branch.N}` where `N` is number of the regex
    capture group.
- Added GET paremeter `test`, to not execute any commands but just print them.


Setup ssh access to github for the apache user
----------------------------------------------

1. Let say the apache user is 'www-data' ('__www' on MAC). You need to add his
   ssh public key to github. If you don't have generated key you need to do it
   with following command: `sudo -Hu www-data ssh-keygen -t rsa` Thus will
   generate id_rsa key for www-data user normally in /var/www/.ssh/ . You will
   see the location in the output. Leave passphrase blank. For successful
   generation /var/www/ must be with write access for www-data user.

2. Next copy content of id_rsa.pub (/var/www/.ssh/id_rsa.pub). You can do it
   using following command (or use a text editor): `pbcopy <
   /var/www/.ssh/id_rsa.pub` Thus will copy the content to clipboard. Add the
   public key to github. You can find detailed description here:
   https://help.github.com/articles/generating-ssh-keys You can also add the
   key to specified project in github in project settings->'Deploy Keys'.

3. Finally add github.com to the list of known_hosts for the apache user using
   command: `sudo -Hu www-data ssh-keyscan github.com >>
   /var/www/.ssh/known_hosts`

You can test ssh access to github: `sudo -Hu www-data ssh -T git@github.com`


Configuration file config.json
--------------------

sync.php supports many projects in the configuration file specified in
the 'projects' section. Each member of 'projects' is an object with same name as
the project name and has 'remote' element which specify the remote git
repository of the project (SSH url). You need to add configuration for each
project branch that you want to be able to sync. At least one branch should be
added to the configuration.

There is a special branch name called `*`. Branch with this name will pull
all branches under the specified directory and update to any branch will
trigger its update.

Top level configuration:
* `projects` - Object with per-project configuration. Only projects listed
  here will be synced.
* `supportEmail` – Global email address to notify on script initialization
  error. Could be array of addresses.
* `supportEmailFrom` – From email address to send notify on error.
* `logs` – (__`true`__/`false`/`"/path/to/specified/directory"`) - Enable
  writing to a log file. By default (`true`) file "sync_log_timestamp.txt" is
  generated in the same directory where is sync.php or "./log/timestamp.txt"
  if there is directory called `log`. Instead you are able to specify
  different directory. Logs are generated only in case of errors. `false` will
  disable logs explicitly in all cases.
* `debug` - (`true`/__`false`__) - Enable logs to be saved even when there is
  no error condition.
* `debugAll` - (`true`/__`false`__) - Write even more logs, the PHP request and
  server environment variables.
* `retryOnErrorCount` – How many times to retry a git clone/pull command on
  error.
* `commandOnFinish` – Optional command to execute on successful update. Could
  be array of commands.
* `urlOnFinish` – Optional URL to load on successful update. Could be array of
  URLs.

Every project has these configuration elements:
* `initial` - this is only used with projects which has regex as name. It is
  array of strings listing the allowed project names. This is necessary if
  you want to sync multiple projects manually, otherwise `project=*` will not
  know which projects to sync. But GitHub hooks will still work without this.
* `remote` - SSH URL of git repository.
* `branches` - Object with per-branch configuration. Only branches listed here
  will be synced.
* `supportEmail` – Email address to notify on error occurred when updating
  this project. Could be array of addresses.
* `commandOnFinish` – Optional command to execute on successful update. Could
  be array of commands.
* `urlOnFinish` – Optional URL to load on successful update. Could be array of
  URLs.

Every branch has these configuration elements:
* `local` – local location of the project (repository and working tree).
  Apache user 'www-data' must have write access to the parent directory.
* `autosync` (__`true`__/`false`) – Set it to false to disable updating of the
  project from remote.
* `supportEmail` – Email address to notify on branch update error. Could be
  array of addresses.
* `commandOnFinish` – Optional command to execute on successful update. Could
  be array of commands.
* `urlOnFinish` – Optional URL to load on successful update. Could be array of
  URLs.
* `syncSubmodules` (__`true`__/`false`) – Tells sync.php to git update
  submodules.
* `bare` (`true`/__`false`__) - Will make the repository bare.
* `deep` (`true`/__`false`__) - `false` will make the repository shallow.


sync.php - parameters and examples
-------------------

Possible parameters are:
* `project` - Specify project name. Required. sync.php will try to read it
  from POST 'payload' json data that is send from github.com when notifying
  your script using web hook url. If specified as GET parameter it overrides
  the project name from the POST 'payload' data. Setting this to `*` will sync
  all projects.
* `branch` – Specify branch. sync.php will try to read it from POST 'payload'
  json data that is send from GitHub.com when notifying your script using web
  hook URL. If specified as GET parameter it overrides the project branch from
  the POST 'payload' data. Setting this to `*` will sync all branches.
* `clean` (`1`/__`0`__) –  Setting this to `1` will delete the local branch
  directory and then will clone.
* `delete` (`1`/__`0`__) –  Setting this to `1` will delete the local branch
  directory.
* `forcesync` (`1`/__`0`__) – Setting this to `1` will ignore `"autosync":
  false` option from the configuration file.
* `noemail` (`1`/__`0`__) – Setting this to `1` will cause no emails to be
  sent.
* `noonfinish` (`1`/__`0`__) – Setting this to `1` will cause no commands
  to be performed or URLs to be loaded on finish.
* `test` (`1`/__`0`__) – Setting this to `1` will cause no git commands
  to be performed, but just to print them.

##### Examples:
- `sync.php?project=test&branch=*` – Updates every branch of project 'test' from
the configuration file config.json.
- `sync.php?project=test&branch=master` –
Updates branch 'master' of project 'test'.
- `sync.php?project=test&branch=master&clean=1` – Deletes local directory for
branch 'master' of project 'test' and then recreates it by cloning latest
revision.


Setup WebHook notifications
-------------------
##### On github.com
Go to project *Settings -> Service Hooks -> WebHook URLs* and add your url to the sync.php script

Authors
---------
Krum Stoilov - original implementation  
Borislav Peev (borislav.asdf at gmail dot com) - only bug fixes and adding new features, this is not my code

#### Credits
https://gist.github.com/oodavid/1809044


