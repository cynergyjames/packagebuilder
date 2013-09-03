<?php
ini_set('error_reporting', E_ALL & ~E_NOTICE & ~E_WARNING);

require_once 'pb_strings.php';

class PackageBuilder {
	public $app_path = '';
	public $repo_path = '';
	public $release_branch = '';
	public $tag = '';
	public $tag_list = array();
	public $current_version = '';
	public $release_version = '';

	public function __construct() {
		global $pb_strings;

		$this->message($pb_strings['WELCOME_MSG'], 2);
		$this->app_path = getcwd();
	}

	public function build() {
		global $pb_strings;

		$this->setRepoPath();

		do {
			$option = $this->selectOption();

			switch($option) {
				case '1':
					$valid_option = true;
					$this->gitConfigCheck();
					$this->gitCheckoutMaster();
					$this->gitSetReleaseBranch();
					$this->gitMergeReleaseBranch();
					$this->getTagList();
					$this->selectVersions();
					$this->createBuild();
					$this->createPackage();
					break;

				case '2':
					$valid_option = true;
					$this->gitCheckoutMaster();
					$this->getTagList();
					$this->selectVersions();
					$this->createBuild();
					$this->createPackage();
					break;

				case '3':
					$valid_option = true;
					exit($this->message($pb_strings['EXIT_MSG']));
					break;

				default:
					$this->message($pb_strings['INVALID_OPTION_MSG'], 2);
			}
		} while($valid_option == false);
	}

	public function setRepoPath() {
		global $pb_strings;

		$cnt = 0;

		//Set the git repository path
		do {
			$is_repo = false;
			$this->message($pb_strings['PATH_TO_REPO_MSG'], 0);
			$path = $this->getInput();

			if($path[strlen($path) - 1] == '/') {
				$path[strlen($path) - 1] = '';
			}

			if(is_dir($path) && chdir($path)) {
				if(file_exists('.git')) {
					$is_repo = true;
					$this->repo_path = $path;
					$this->message("\n" . $pb_strings['PATH_TO_REPO_SUCCESS'], 2);
					return;
				}
			}

			$this->message($pb_strings['PATH_TO_REPO_INVALID'], 2);
			$cnt++;

		} while($is_repo == false && $cnt < 3);

		exit($pb_strings['PATH_TO_REPO_ERROR'] . "\n");
	}

	public function selectOption() {
		global $pb_strings;

		$this->message($pb_strings['OPTION_MSG']);
		$this->message($pb_strings['OPTION_1_MSG']);
		$this->message($pb_strings['OPTION_2_MSG']);
		$this->message($pb_strings['OPTION_3_MSG'], 2);
		$this->message($pb_strings['OPTION_SELECT_MSG'], 0);

		return $this->getInput();
	}

	public function gitConfigCheck() {
		global $pb_strings;

		if(!$this->_gitConfigParamSet('user.email')) {
			$this->message($pb_strings['GIT_CONFIG_SET_EMAIL_ERROR'], 2);
			exit($this->message($pb_strings['EXIT_MSG']));
		}

		if(!$this->_gitConfigParamSet('user.name')) {
			$this->message($pb_strings['GIT_CONFIG_SET_NAME_ERROR'], 2);
			exit($this->message($pb_strings['EXIT_MSG']));
		}
	}

	private function _gitConfigParamSet($param) {

		exec('git config --global ' . $param, $value);

		if(empty($value)) {
			exec('git config ' . $param, $value);
			
			if(empty($value)) return false;
		}

		return true;
	}

	public function gitCheckoutMaster() {
		global $pb_strings;

		$head_array = file('.git/HEAD');
        $current_branch_ref = $head_array[0];
        $current_branch_array = explode('/', $current_branch_ref);
        $current_branch = trim($current_branch_array[2]);

        if($current_branch != 'master'){
            exec('git checkout master', $output);
        } else {
            exec('git status', $output);
        }

        if(!empty($output) && $output[1] != "nothing to commit, working directory clean") {
            $this->message($pb_strings['GIT_MASTER_OUT_OF_SYNC_ERROR'], 2);
            exit($this->message($pb_strings['EXIT_MSG']));
        }
	}

	public function gitSetReleaseBranch() {
		global $pb_strings;

		$prefix = $pb_strings['GIT_RELEASE_PREFIX'];

		exec('git branch -a', $output);

		foreach($output as $branch) {
            if(substr(trim($branch), 0, 15) == 'remotes/origin/' && substr(trim($branch), 15, 4) != 'HEAD') {
                if(substr(trim($branch), 15, strlen($prefix)) == $prefix) {
                    $release_branches[] = substr(trim($branch), 15);
                }
            }
        }

        if(count($release_branches) >= 1) {
        	$this->message(PHP_EOL . $pb_strings['GIT_SELECT_RELEASE_MSG']);
        	$i = 1;

        	foreach($release_branches as $branch) {
        		$this->message("  [" . $i . "] " . $branch);
        		$i++;
        	}

        	$this->message(PHP_EOL . $pb_strings['OPTION_SELECT_MSG'], 0);
        	$option = $this->getInput();
        	$this->release_branch = $release_branches[$option - 1];
        } else {
        	$this->message(PHP_EOL . $pb_strings['GIT_NO_RELEASE_ERROR']);
        	exit($this->message($pb_strings['EXIT_MSG']));
        }
	}

	public function gitMergeReleaseBranch() {
		global $pb_strings;

		if(!empty($this->release_branch)) {
			$this->message(PHP_EOL . $pb_strings['GIT_MERGE_NOW_MSG'], 0);
			$merge = $this->getInput();

			if(strlen($merge) == 0 || strtolower($merge) == 'y') {
				exec('git merge ' . $this->release_branch);
				exec('git status', $output);
				
				if(substr(trim($output[1]), 0, 41) == "# Your branch is ahead of 'origin/master'") {
					$this->gitTagMasterRelease();
					$this->gitPushMaster();
					$this->gitDeleteReleaseBranch();
				}
			}
		}
	}

	public function gitTagMasterRelease() {
		global $pb_strings;

		$tag = substr($this->release_branch, 8);

		if(strlen($tag) > 0) {
			$this->tag = 'v' . $tag;
			$this->message(PHP_EOL . $pb_strings['GIT_TAG_NOTIFY_MSG'] . $tag, 2);
			exec('git tag -a v' . $tag . ' -m "Version ' . $tag . '"');
		}
	}

	public function gitPushMaster() {
		global $pb_strings;

		$this->message($pb_strings['GIT_PUSH_NOW_MSG'], 0);
		$push = $this->getInput();

		if(strlen($push) == 0 || strtolower($push) == 'y') {
			exec('git push origin : ' . $this->tag);
		}
	}

	public function gitDeleteReleaseBranch() {
		global $pb_strings;

		$this->message(PHP_EOL . $pb_strings['GIT_DELETE_REL_BRANCH_NOW_MSG'], 0);
		$delete = $this->getInput();

		if(strlen($delete) == 0 || strtolower($delete) == 'y') {
			exec('git branch -D ' . $this->release_branch);
			exec('git push origin :' . $this->release_branch);
		}
	}

	public function selectVersions() {
		global $pb_strings;

		$cur_ver_selected = $rel_ver_selected = false;

		do {
			$this->message(PHP_EOL . $pb_strings['GIT_SELECT_CUR_VERSION']);
			$i = 1;

			foreach($this->tag_list as $tag) {
				$this->message('  [' . $i . '] ' . $tag);
				$i++;
			}

			$this->message(PHP_EOL . $pb_strings['OPTION_SELECT_MSG'], 0);
			$cur_selected = $this->getInput();

			if(!empty($this->tag_list[$cur_selected - 1])) {
				$cur_ver_selected = true;
				$this->current_version = $this->tag_list[$cur_selected - 1];
			}
		} while($cur_ver_selected == false);

		do {
			$this->message(PHP_EOL . $pb_strings['GIT_SELECT_REL_VERSION']);
			$i = 1;

			foreach($this->tag_list as $tag) {
				$this->message('  [' . $i . '] ' . $tag);
				$i++;
			}

			$this->message(PHP_EOL . $pb_strings['OPTION_SELECT_MSG'], 0);
			$rel_selected = $this->getInput();

			if(!empty($this->tag_list[$cur_selected - 1])) {
				$rel_ver_selected = true;
				$this->release_version = $this->tag_list[$rel_selected - 1];
			}
		} while($rel_ver_selected == false);

		$this->message(PHP_EOL . $pb_strings['CURRENT_VERSION_LABEL'] . $this->current_version);
		$this->message($pb_strings['RELEASE_VERSION_LABEL'] . $this->release_version, 2);
	}

	public function getTagList() {
		exec('git for-each-ref --format="%(refname)" --sort=-taggerdate --count=10 refs/tags', $output);

		foreach($output as $tag) {
			$this->tag_list[] = substr($tag, 10);
		}
	}

	public function createBuild() {
		global $pb_strings;

		$this->message($pb_strings['CREATE_NEW_BUILD_MSG'], 0);
		$create = $this->getInput();

		if(strlen($create) == 0 || strtolower($create) == 'y') {
			if(file_exists($this->app_path . '/builds')) {
				if(file_exists($this->app_path . '/builds/'. $this->release_version)) {
					exec('rm -Rf ' . $this->app_path . '/builds/'. $this->release_version);
				}

				if(mkdir($this->app_path . '/builds/'. $this->release_version . '/')) {
					chmod($this->app_path . '/builds/'. $this->release_version, 0774);
					$file_list_array = $this->getFileList();
					$this->buildSrc($file_list_array);
					$this->buildManifest($file_list_array);
				}
			}
		} else {
			$this->selectVersions();
		}
	}

	public function getFileList() {
		exec('git --no-pager diff --name-only --pretty=format:"" ' . $this->current_version . '...' . $this->release_version . ' -- custom/ modules/*_* | sort | uniq', $output);
		return $output;
	}

	public function buildSrc(Array $file_list_array) {
		foreach($file_list_array as $file) {
			if(!file_exists(dirname($this->app_path . '/builds/'. $this->release_version . '/' . $file))) {
                if(mkdir(dirname($this->app_path . '/builds/'. $this->release_version . '/' . $file), 0774, true)) {
                    chmod(dirname($this->app_path . '/builds/'. $this->release_version . '/' . $file), 0774);
                    exec('cp -R ' . $this->repo_path . '/' . $file . ' ' . $this->app_path . '/builds/'. $this->release_version . '/' . $file);
                    chmod($this->app_path . '/builds/'. $this->release_version . '/' . $file, 0774);
                }
            } else {
                exec('cp -R ' . $this->repo_path . '/' . $file. ' ' . $this->app_path . '/builds/'. $this->release_version . '/' . $file);
                chmod($this->app_path . '/builds/'. $this->release_version . '/' . $file, 0774);
            }
		}
	}

	public function buildManifest(Array $file_list_array) {
		$manifest = array(
			'acceptable_sugar_flavors' => array(),
			'acceptable_sugar_versins' => array(),
			'key' => 'li',
			'is_uninstallable' => true,
			'remove_tables' => 'prompt',
			'name' => $this->release_version,
			'author' => 'Franz',
			'description' => $this->release_version,
			'published_date' => date('Y/m/d'),
			'version' => $this->release_version,
			'type' => 'module',
		);

		$installdefs = array(
			'id' => $this->release_version,
			'beans' => array(),
			'copy' => array(),
		);

		$modules = array();

		foreach($file_list_array as $file) {
			$file_name_array = explode('/', $file);			

			if($file_name_array[0] == 'modules') {

				if(!in_array($file_name_array[1], $modules)) {
					$modules[] = $file_name_array[1];

					$installdefs['beans'][] = array(
						'module' => $file_name_array[1],
						'class' => $file_name_array[1],
						'path' => 'modules/' . $file_name_array[1] . '/' . $file_name_array[1] . '.php',
						'tab' => true,
					);

					$installdefs['copy'][] = array(
						'from' => '<basepath>/' . $this->release_version . '/modules/' . $file_name_array[1],
						'to' => 'modules/' . $file_name_array[1]
					);
				}
			} else {
				$installdefs['copy'][] = array(
					'from' => '<basepath>/' . $this->release_version . '/' . $file,
					'to' => $file
				);
			}
		}

		$content = "<?php\n\n" . '$manifest = ' . var_export($manifest, true) . ";\n\n" . '$installdefs = ' . var_export($installdefs, true) . ";\n\n?>";
		$file_path = $this->app_path . '/packages/release-' . $this->release_version . '/manifest.php';

		if(!file_exists(dirname($file_path))) {
			mkdir(dirname($file_path));
		} else {
			unlink($file_path);
			rmdir(dirname($file_path));
		}

		$fp = fopen($file_path, 'w');
		fwrite($fp, $content);
		fclose($fp);
	}

	public function createPackage() {
		$package_path = $this->app_path . '/packages/release-' . $this->release_version . '/' . $this->release_version;

		if(!file_exists($package_path)) {
			mkdir($package_path);
			exec('cp -R ' . $this->app_path . '/builds/' . $this->release_version . '/ ' . $this->app_path . '/packages/release-' . $this->release_version . '/');
		} else {
			exec('rm -Rf ' . $package_path);
		}
		
		$dir = $this->app_path . '/packages/release-' . $this->release_version;
		$file_array = $this->getPackageFileList($dir);
		$this->createPackageArchive($file_array, $this->app_path . '/packages/release-' . $this->release_version . '.zip', true);
	}

	public function getPackageFileList($dir) {
		$file_array = array();
		$root = scandir($dir); 

	    foreach($root as $value) { 
	        if($value === '.' || $value === '..') { 
	        	continue; 
	        }

	        if(is_file("$dir/$value")) { 
	        	$file_array[] = "$dir/$value";
	        	continue; 
	        } 

	        foreach($this->getPackageFileList("$dir/$value") as $value) { 
	            $file_array[] = $value; 
	        } 
	    }

	    return $file_array;
	}

	public function createPackageArchive($files = array(), $dest = '', $overwrite = false) {
		if(file_exists($dest) && !$overwrite) 
		{ 
			return false; 
		}

		$valid_files = array();

		if(is_array($files)) {
			foreach($files as $file) {
				if(file_exists($file)) {
					$valid_files[] = $file;
				}
			}

			if(count($valid_files) > 0) {
				chdir($this->app_path . '/packages');
				$archive = new ZipArchive();

				if($archive->open($dest, $overwrite ? ZIPARCHIVE::OVERWRITE : ZIPARCHIVE::CREATE) !== true) return false;

				foreach($valid_files as $file) {
					$archive->addFile($file, str_replace($this->app_path . '/packages/release-' . $this->release_version . '/', '', $file));
				}

				$archive->close();
				return file_exists($dest);
			}
		} else {
			return false;
		}
	}

	public function message($msg, $new_line_cnt = 1) {
		$x = 1;

		while ($x <= $new_line_cnt) { 
			$msg = $msg . PHP_EOL;
			$x++;
		}

		echo $msg;
	}

	public function getInput() {
		$stdin = fopen("php://stdin", "r");
		$input = trim(fgets($stdin));
		fclose($stdin);

		return $input;
	} 
}
?>