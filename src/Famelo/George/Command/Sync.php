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
class Sync extends Command {

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

		$this->addOption('batch', FALSE, InputOption::VALUE_OPTIONAL,
			'Amount of change-sets to process per run'
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
		$this->travis = new Travis($this->configuration['github']['repository']);
		$this->gitHub = new GitHub(
			$this->configuration['github']['username'],
			$this->configuration['github']['password'],
			$this->configuration['github']['repository']
		);
		$this->codeSniffer = new CodeSniffer();

		if (!file_exists('.comments')) {
			mkdir('.comments');
		}

		// $this->updateBaseBranches();
		$this->fetchExistingPullRequests();
		$this->createPullRequestFromChangeSets();
		$this->review();
	}

	public function updateBaseBranches() {
		$branches = $this->configuration['branches'];
		$source = $this->configuration['git']['source'];
		$target = $this->configuration['git']['target'];
		foreach ($branches as $branch) {
			$this->output->writeln('<info>Updating Branch: ' . $branch . '</info>');
			if (!Git::branchExists($branch)) {
				Git::createBranch($branch, $source . '/' . $branch);
			}
			Git::checkout($branch);
			Git::pull($source, $branch);
			Git::push($target, $branch, TRUE);
			Git::pull($target, $branch);
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

	public function fetchExistingPullRequests() {
		$pullRequests = $this->gitHub->getPullRequests();
		foreach ($pullRequests as $pullRequest) {
			preg_match('/Change-Id: (.+)/', $pullRequest['body'], $match);
			if (!isset($match[1])) {
				continue;
			}
			$changeId = $match[1];

			preg_match('/Revision-Id: (.+)/', $pullRequest['body'], $match);
			if (!isset($match[1])) {
				continue;
			}
			$revisionId = $match[1];

			$this->pullRequests[$changeId . '-' . $revisionId] = array(
				'title' => $pullRequest['title'],
				'state' => $pullRequest['state'],
				'id' => $pullRequest['id'],
				'changeId' => $changeId,
				'revisionId' => $revisionId,
				'branch' => $pullRequest['head']['ref'],
				'status' => $this->travis->getBranchStatus($pullRequest['head']['ref']),
				'review'=> $this->gerrit->getMyReview($changeId, $revisionId)
			);
		}
	}

	public function review() {
		$focus = NULL;
		if ($this->input->hasOption('change-id')) {
			$focus = $this->input->getOption('change-id');
		}
		foreach ($this->pullRequests as $pullRequest) {
			if ($focus !== NULL && $pullRequest['changeId'] !== $focus) {
				continue;
			}

			if ($pullRequest['review'] !== NULL
				&& $pullRequest['review']['review'] !== 0
				&& $pullRequest['review']['verified'] !== 0) {
				$this->output->writeln('<comment>Skipping already reviewed: ' . $pullRequest['title'] . '</comment>');
				continue;
			}

			$this->output->writeln('<info>Reviewing: : ' . $pullRequest['title'] . '</info>');

			$message = '';
			$verified = 0;
			switch ($pullRequest['status']->branch->state) {
				case 'passed':
					$message .= '[Travis] Revision passed Unit and Functional Tests on: PHP ' . implode(', ', $pullRequest['status']->branch->config->php) . chr(10);
					$message .= 'https://travis-ci.org/mneuhaus/TYPO3.Flow/builds/' . $pullRequest['status']->branch->id;
					$verified = 1;
					break;

				case 'failed':
					$message .= '[Travis] Build failed to pass tests' . "\n";
					$message .= 'https://travis-ci.org/mneuhaus/TYPO3.Flow/builds/' . $pullRequest['status']->branch->id;
					$verified = -1;
					break;

				default:
					# code...
					break;
			}

			$codeReview = 1;
			$comments = array();
			$commentsFile = '.comments/' . $pullRequest['branch'];
			if (file_exists($commentsFile)) {
				$comments = json_decode(file_get_contents($commentsFile));
				$codeReview = -1;
				$message .= "\n\n" . 'Found ' . count($comments) . ' CGL problems';
			}
			$message = nl2br($message);
			$this->gerrit->review($pullRequest['changeId'], $pullRequest['revisionId'], $message, $codeReview, $verified, $comments);
		}
	}

	public function getConfiguration() {
		$defaults = array(
			'gerrit' => array(
  				'baseUrl' => 'https://review.typo3.org/'
  			),
  			'git' => array(
				'source' => 'origin',
				'target' => 'travis',
  			),
			'branches' => array(
				'master'
			),
			'codeSniffer' => array(
				'standard' => 'TYPO3Flow'
			)
		);
		$configuration = Yaml::parse('.george.yml');
		$branches = $configuration['branches'];
		$configuration = array_merge_recursive($defaults, $configuration);
		$configuration['branches'] = $branches;
		return $configuration;
	}

	public function getMessage($revision) {
		$message = $revision->commit->message;
		$messageLines = explode(chr(10), $revision->commit->message);
		array_shift($messageLines);
		$message = trim(implode(chr(10), $messageLines)) . chr(10);
		$message.= 'Revision-Id: ' . $revision->revision_id;
		return $message;
	}
}

?>