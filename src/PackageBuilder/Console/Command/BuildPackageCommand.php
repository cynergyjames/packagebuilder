<?php
namespace PackageBuilder\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use ZipArchive;

class BuildPackageCommand extends Command {
	protected $log;
	public $git_repo_path = '';
	public $git_refs = '';
	public $package_dir = '';
	public $local_branch = '';
	public $package_name = '';
	public $file_list_array = array();
	
	protected function configure() {
		$this->setName('buildpackage')
			 ->setDescription('Builds a SugarCRM installable package from a Git repository.')
			 ->addOption('git-repo-path', '-p', InputOption::VALUE_REQUIRED, 'Path to local Git repository.')
			 ->addOption('git-refs', '-r', InputOption::VALUE_REQUIRED, 'Refs separated by 3 periods (...).')
			 ->addOption('package-dir', '-d', InputOption::VALUE_OPTIONAL, 'Path to save package to. Defaults to git-repo-path.')
			 ->addOption('local-branch', '-b', InputOption::VALUE_OPTIONAL, 'Name of local branch to build package from.');
			 //->addOption('upstream-branch', '-B', InputOption::VALUE_OPTIONAL, 'Name of upstream branch to build package from.')
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->git_repo_path = $input->getOption('git-repo-path');
		$this->git_refs = $input->getOption('git-refs');
		$this->package_dir = $input->getOption('package-dir');
		$this->local_branch = $input->getOption('local-branch');

		$this->_initLogger();
		$this->_initGit($output);
		$this->_initPackageDir($output);

		$this->buildPackage($output);
	}

	private function _initLogger() {
		$this->log = new Logger('PackageBuilder');
		$this->log->pushHandler(new StreamHandler('pb.log', Logger::ERROR));
	}

	private function _initGit(OutputInterface $output) {
		if(!is_dir($this->git_repo_path) || !chdir($this->git_repo_path)) {
			$msg = sprintf("Path '%s' not found or permission denied.", $this->git_repo_path);
			$this->log->addError($msg);
			throw new \RuntimeException($msg);
		}

		if(!file_exists('.git')) {
			$msg = sprintf("Path '%s' is not a Git repository.", $this->git_repo_path);
			$this->log->addError($msg);
			throw new \RuntimeException($msg);
		}

		exec('git branch -a', $branches);
		
		$on_branch = false;
		$valid_branch = false;			

		foreach($branches as $branch) {
			if(!empty($this->local_branch)) {
				if(trim($branch) === '* ' . $this->local_branch) {
					$on_branch = true;
					$valid_branch = true;
					break;
				} else if(trim($branch) === $this->local_branch) {
					$valid_branch = true;
					break;
				}
			} else {
				if(trim($branch) === '* master') {
					$this->local_branch = 'master';
					$on_branch = true;
					$valid_branch = true;
					break;
				} else if(trim($branch) === 'master') {
					$this->local_branch = 'master';
					$valid_branch = true;
					break;
				}
			}
		}

		if(!$valid_branch) {
			$msg = sprintf("The pathspec '%s' did not match any file(s) known to git.", $branch);
			$this->log->addError($msg);
			throw new \RuntimeException($msg);
		}

		if(!$on_branch) {
			exec('git checkout ' . $this->local_branch);
		}
	}

	private function _initPackageDir(OutputInterface $ouput) {		
		if(empty($this->package_dir)) {
			if(($cwd = getcwd()) !== false) {
				$this->package_dir = $cwd . '/packages';				
			} else {
				$msg = 'Unable to create package directory.';
				$this->log->addError($msg);
				throw new \RuntimeException($msg);
			}
		}
		
		if(!file_exists($this->package_dir)) {
			if(!mkdir($this->package_dir, 0770, true)) {
				$msg = 'Unable to create package directory.';
				$this->log->addError($msg);
				throw new \RuntimeException($msg);
			}
		}
	}

	protected function buildPackage(OutputInterface $ouput) {
		$this->copyFiles();
		$this->createManifest();
		$file_array = $this->getPackageFileList($this->package_dir . '/' . $this->package_name);
		$this->createPackageArchive($file_array, $this->package_dir . '/' . $this->package_name . '.zip', true);
	}

	public function getFileList() {
		exec('git --no-pager diff --name-only --pretty=format:"" ' . $this->git_refs . ' -- custom/ modules/*_* | sort | uniq', $output);
		
		return $output;
	}

	public function copyFiles() {
		$refs = explode('...', $this->git_refs);
		$this->package_name = $refs[0] . '_' . $refs[1] . '-' . date('YmdHis');
		$this->file_list_array = $this->getFileList();

		if(is_array($this->file_list_array)) {
			foreach($this->file_list_array as $file) {
				if(!file_exists(dirname($this->package_dir . '/' . $this->package_name . '/' . $file))) {
					if(mkdir(dirname($this->package_dir . '/' . $this->package_name . '/' . $file), 0770, true)) {
						exec('cp -R ' . $this->git_repo_path . '/' . $file . ' ' . $this->package_dir . '/' . $this->package_name . '/' . $file);
					}
				} else {
					exec('cp -R ' . $this->git_repo_path . '/' . $file . ' ' . $this->package_dir . '/' . $this->package_name . '/' . $file);
				}
			}
		}
	}

	public function createManifest() {
		$manifest = array(
			'acceptable_sugar_flavors' => array(),
			'acceptable_sugar_versins' => array(),
			'key' => '',
			'is_uninstallable' => true,
			'remove_tables' => 'prompt',
			'name' => $this->package_name,
			'author' => 'PackageBuilder',
			'description' => $this->package_name,
			'published_date' => date('Y/m/d'),
			'version' => 1,
			'type' => 'module',
		);

		$installdefs = array(
			'id' => $this->package_name,
			'beans' => array(),
			'copy' => array(),
		);

		$modules = array();

		if(!empty($this->file_list_array) && is_array($this->file_list_array)) {
			foreach($this->file_list_array as $file) {
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
							'from' => '<basepath>/PackageBuilder/modules/' . $file_name_array[1],
							'to' => 'modules/' . $file_name_array[1]
						);
					}
				} else {
					$installdefs['copy'][] = array(
						'from' => '<basepath>/PackageBuilder/' . $file,
						'to' => $file
					);
				}
			}

			$content = "<?php\n\n" . '$manifest = ' . var_export($manifest, true) . ";\n\n" . '$installdefs = ' . var_export($installdefs, true) . ";\n\n?>";
			$file_path = $this->package_dir . '/manifest.php';

			if(file_exists($file_path)) {				
				unlink($file_path);
			}

			$fp = fopen($file_path, 'w');
			fwrite($fp, $content);
			fclose($fp);			
		}
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
				chdir($this->package_dir);
				$archive = new \ZipArchive();

				if($archive->open($dest, $overwrite ? ZIPARCHIVE::OVERWRITE : ZIPARCHIVE::CREATE) !== true) return false;

				foreach($valid_files as $file) {
					$archive->addFile($file, str_replace($this->package_dir . '/' . $this->package_name . '/', '', 'PackageBuilder/' . $file));
				}

				$archive->addFile($this->package_dir . '/manifest.php', 'manifest.php');

				$archive->close();
				return file_exists($dest);
			}
		} else {
			return false;
		}
	}
}