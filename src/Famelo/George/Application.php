<?php

namespace Famelo\George;

use KevinGH\Amend;
use Famelo\George\Command;
use Famelo\George\Helper;
use Symfony\Component\Console\Application as Base;

/**
 * Sets up the application.
 *
 * @author Kevin Herrera <kevin@herrera.io>
 */
class Application extends Base {

	/**
	 * @override
	 */
	public function __construct($name = 'George', $version = '@git_tag@') {
		parent::__construct($name, $version);
	}

	/**
	 * @override
	 */
	protected function getDefaultCommands() {
		$commands = parent::getDefaultCommands();
		$commands[] = new Command\Sync();

		if (('@' . 'git_tag@') !== $this->getVersion()) {
			$command = new Amend\Command('update');
			$command->setManifestUri('@manifest_url@');

			$commands[] = $command;
		}

		return $commands;
	}

	/**
	 * @override
	 */
	protected function getDefaultHelperSet() {
		$helperSet = parent::getDefaultHelperSet();

		if (('@' . 'git_tag@') !== $this->getVersion()) {
			$helperSet->set(new Amend\Helper());
		}

		return $helperSet;
	}
}

?>