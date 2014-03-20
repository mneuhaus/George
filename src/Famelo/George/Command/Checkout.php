<?php

namespace Famelo\George\Command;

use Famelo\George\Services\CodeSniffer;
use Famelo\George\Services\Gerrit;
use Famelo\George\Services\Git;
use Famelo\George\Services\GitHub;
use Famelo\George\Services\Travis;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Patch command.
 *
 */
class Checkout extends Command {

	/**
	 * The output handler.
	 *
	 * @var OutputInterface
	 */
	private $output;

	/**
	 * @var string
	 */
	protected $baseDir;

	/**
	 * @var array
	 */
	protected $pullRequests = array();

	/**
	 * @override
	 */
	protected function configure() {
		parent::configure();
		$this->setName('sync');
		$this->setDescription('Sync Gerrit to GitHub');

		$this->addOption('change-id', FALSE, InputOption::VALUE_OPTIONAL,
			'Focus on one specific changeset'
        );
	}

	/**
	 * @override
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->output = $output;
		$this->input = $input;

		$this->configuration = $this->getConfiguration();
		$this->gerrit = new Gerrit(
			$this->configuration['gerrit']['baseUrl'],
			$this->configuration['gerrit']['project'],
			$this->configuration['gerrit']['username'],
			$this->configuration['gerrit']['password']
		);

		if ($this->input->hasOption('change-id')) {
			$focus = $this->input->getOption('change-id');
		}

	}

	public function createPullRequestFromChangeSets() {
		$focus = NULL;
		if ($this->input->hasOption('change-id')) {
			$focus = $this->input->getOption('change-id');
		}

		$batch = 10;
		if ($this->input->hasOption('batch')) {
			$batch = intval($this->input->getOption('batch'));
		}

		$changeSets = $this->gerrit->getChangeSets(300);

		$target = $target = $this->configuration['git']['target'];
		$counter = 0;
		foreach ($changeSets as $changeSet) {
			if ($counter === $batch) {
				break;
			}

			if (stristr($changeSet->subject, '[WIP]') && $focus === NULL) {
				$this->output->writeln('<comment>Skipping: ' . $changeSet->subject . '</comment>');
				continue;
			}

			if ($focus !== NULL && $changeSet->change_id !== $focus) {
				continue;
			}

			$changeId = $changeSet->change_id;
			$revisions = get_object_vars($changeSet->revisions);
			$revision = current($revisions);
			$revision->revision_id = key($revisions);

			if (isset($this->pullRequests[$changeSet->change_id . '-' . $revision->revision_id])) {
				$this->output->writeln('<comment>Skipping already pushed: ' . $changeSet->subject . '</comment>');
				continue;
			}

			$this->output->writeln('<info>Processing: ' . $changeSet->subject . '</info>');
			$counter++;

			$branchName = 'change-' . substr($changeId, 0, 7) . '-' . substr($revision->revision_id, 0, 7);

			if (!Git::branchExists($branchName)) {
				Git::checkout($changeSet->branch);
				Git::deleteBranch($branchName);
			}

			Git::createBranch($branchName, $changeSet->branch);
			Git::cherryPickChangeSet($revision->fetch->http->url, $revision->fetch->http->ref);
			Git::push($target, $branchName);

			$comments = $this->gerrit->reviewFiles($changeSet);
			if (count($comments) > 0) {
				file_put_contents('.comments/' . $branchName, json_encode($comments, JSON_PRETTY_PRINT));
			}

			Git::checkout($changeSet->branch);
			Git::resetHard();
			Git::deleteBranch($branchName);

			try {
				$pullRequest = $this->gitHub->createPullRequest(array(
					'base'  => $changeSet->branch,
					'head'  => $branchName,
					'title' => $changeSet->subject . ' (Revision ' . $revision->_number . ')',
					'body'  => $this->getMessage($revision)
				));
			} catch(\Exception $e) {}
		}
	}
}

?>