<?php
namespace Famelo\George\Services;

use Symfony\Component\Process\Process;
use TYPO3\Flow\Http\Client\Browser;

/**
 *
 */
class Git {
	static public function createChangeBranch($id) {
		self::executeShellCommand('git checkout -b change-' . $id . ' master');
	}

	static public function cherryPickChangeSet($url, $ref) {
		return self::executeShellCommand('git fetch ' . $url . ' ' . $ref . ' && git cherry-pick FETCH_HEAD');
	}

	static public function resetHard() {
		// self::executeShellCommand('git fetch origin');
		self::executeShellCommand('git reset --hard');
	}

	static public function updateMaster($origin, $target) {
		self::executeShellCommand('git fetch ' . $target);
		self::executeShellCommand('git fetch ' . $origin);
		self::executeShellCommand('git pull ' . $target . ' master');
		self::executeShellCommand('git pull ' . $origin . ' master');
		self::executeShellCommand('git push ' . $target . ' master');
	}

	static public function executeShellCommand() {
		$arguments = func_get_args();
		$command = array_shift($arguments);

		if (count($arguments) > 0) {
			$command = vsprintf($command, $arguments);
		}

		$process = new Process($command);
		$process->run();
		return $process->getOutput() . $process->getErrorOutput();
		// return $process->getOutput();

		// $output = '';
		// $fp = popen($command, 'r');
		// while (($line = fgets($fp)) !== FALSE) {
		// 	$output .= $line;
		// }
		// $output .= ob_get_clean();

		// return trim($output);
	}

	static public function pull($remote, $branch) {
		self::executeShellCommand('git pull ' . $remote . ' ' . $branch);
	}

	static public function push($remote, $branch, $force = FALSE) {
		if ($force === TRUE) {
			self::executeShellCommand('git push ' . $remote . ' ' . $branch . ' --force');
		} else {
			self::executeShellCommand('git push ' . $remote . ' ' . $branch);
		}
	}

	static public function checkout($branch) {
		self::executeShellCommand('git checkout ' . $branch);
	}

	static public function deleteBranch($branch) {
		self::executeShellCommand('git branch -D ' . $branch);
	}

	static public function getBranches() {
		$branches = explode(chr(10), self::executeShellCommand('git branch'));
		array_walk($branches, function($branch) {
			return trim($branch, ' *');
		});
		return $branches;
	}

	static public function branchExists($name) {
		return in_array($name, self::getBranches());
	}

	static public function createBranch($name, $source = NULL) {
		if ($source !== NULL) {
			if (stristr($source, '/')) {
				$parts = explode('/', $source);
				$remote = array_shift($parts);
				self::executeShellCommand('git fetch ' . $remote);
			}
			self::executeShellCommand('git checkout -b %s %s', $name, $source);
		}
	}
}

?>