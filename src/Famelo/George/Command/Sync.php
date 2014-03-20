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
	 * @var integer
	 */
	protected $pendingBuilds = 0;

	/**
	 * @var integer
	 */
	protected $maxBuilds = 10;

	/**
	 * @var string
	 */
	protected $salt = '-z8asdf';

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
		$this->travis = new Travis(
			$this->configuration['travis']['token'],
			$this->configuration['github']['repository'],
			$this->output
		);
		$this->gitHub = new GitHub(
			$this->configuration['github']['token'],
			$this->configuration['github']['repository']
		);
		$this->codeSniffer = new CodeSniffer();

		if (!file_exists('.comments')) {
			mkdir('.comments');
		}

		$this->maxBuilds = $this->configuration['travis']['maxBuilds'];

		$this->updateBaseBranches();
		$this->fetchExistingPullRequests();
		$this->review();
		if ($this->maxBuilds > $this->pendingBuilds) {
			$this->createPullRequestFromChangeSets();
		} else {
			$this->output->writeln('maximum builds reached');
		}
	}

	public function updateBaseBranches() {
		$branches = $this->configuration['branches'];
		$source = $this->configuration['git']['source'];
		$target = $this->configuration['git']['target'];
		foreach ($branches as $branch) {
			$this->output->writeln('Updating Branch: ' . $branch);
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

		$changeSets = $this->gerrit->getChangeSets(500);

		$target = $target = $this->configuration['git']['target'];
		foreach ($changeSets as $changeSet) {
			if ($this->output->isVeryVerbose()) {
				$this->output->writeln('<comment>Checking: ' . $changeSet->subject . '</comment>');
			}
			if ($this->pendingBuilds >= $this->maxBuilds) {
				$this->output->writeln('maximum builds reached');
				break;
			}

			if (stristr($changeSet->subject, '[WIP]') && $focus === NULL) {
				if ($this->output->isVerbose()) {
					$this->output->writeln('<comment>Skipping: ' . $changeSet->subject . '</comment>');
				}
				continue;
			}

			if ($focus !== NULL && $changeSet->change_id !== $focus) {
				continue;
			}

			$changeId = $changeSet->change_id;
			$fullChangeId = urlencode($this->configuration['gerrit']['project']) . '~' . $changeSet->branch . '~' . $changeId;
			$revisions = get_object_vars($changeSet->revisions);
			$revision = current($revisions);
			$revision->revision_id = key($revisions);
			$revisionId = $revision->revision_id;

			if (isset($this->pullRequests[$changeSet->change_id . '-' . $revision->revision_id . $this->salt])) {
				if ($this->output->isVerbose()) {
					$this->output->writeln('<comment>Skipping already pushed: ' . $changeSet->subject . '</comment>');
				}
				continue;
			}

			if ($this->gerrit->isAlreadyReviewed($fullChangeId, $revision->revision_id)) {
				if ($this->output->isVerbose()) {
					$this->output->writeln('<comment>Already reviewed: ' . $changeSet->subject . '</comment>');
				}
				continue;
			}

			$this->output->writeln('<info>Processing: ' . $changeSet->subject . '</info>');

			$branchName = 'change-' . substr($changeId, 0, 7) . '-' . substr($revision->revision_id, 0, 7) . $this->salt;

			if (!Git::branchExists($branchName)) {
				Git::checkout($changeSet->branch);
				Git::deleteBranch($branchName);
			}

			Git::resetHard();
			Git::createBranch($branchName, $changeSet->branch);
			$output = Git::cherryPickChangeSet($revision->fetch->http->url, $revision->fetch->http->ref);

			if (stristr($output, 'error: could not apply')) {
				$this->output->writeln('<error>Failed to cherry-pick: ' . $changeSet->subject . '</error>');
				if ($this->configuration['rebasePrompt'] === TRUE) {
					$message = 'Failed to Cherry-Pick this change onto current ' . $changeSet->branch . ' branch.';
					$message.= "\n" . 'Please rebase this change.';
					$message.= "\n\n" . $output;

					$this->gerrit->review($fullChangeId, $revisionId, $message, 0, -1);
				}

				Git::resetHard();
				Git::checkout($changeSet->branch);
				Git::deleteBranch($branchName);
				continue;
			}

			Git::push($target, $branchName);

			if ($this->configuration['codeSniffer']['active'] === TRUE) {
				$comments = $this->gerrit->reviewFiles($changeSet);
				if (count($comments) > 0) {
					file_put_contents('.comments/' . $branchName, json_encode($comments, JSON_PRETTY_PRINT));
				}
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
				$this->pendingBuilds++;
			} catch(\Exception $e) {}
		}
	}

	public function fetchExistingPullRequests() {
		$this->output->writeln('Fetching PullRequests');
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
			$status = $this->travis->getBranchStatus($pullRequest['head']['ref']);

			if (in_array($status->branch->state, array('created', 'started'))) {
				$this->pendingBuilds++;
			}
			if ($this->output->isVerbose()) {
				$this->output->writeln('Fetching: ' . $pullRequest['title']);
			}

			$fullChangeId = urlencode($this->configuration['gerrit']['project']) . '~' . $pullRequest['base']['ref'] . '~' . $changeId;
			$this->pullRequests[$changeId . '-' . $revisionId . $this->salt] = array(
				'title' => $pullRequest['title'],
				'state' => $pullRequest['state'],
				'number' => $pullRequest['number'],
				'id' => $pullRequest['id'],
				'changeId' => $fullChangeId,
				'revisionId' => $revisionId,
				'branch' => $pullRequest['head']['ref'],
				'base' => $pullRequest['base']['ref'],
				'status' => $status,
				'review'=> $this->gerrit->getMyReview($fullChangeId, $revisionId)
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

			if ($this->gerrit->isAlreadyReviewed($pullRequest['changeId'], $pullRequest['revisionId'])) {
				$this->gitHub->closePullRequest($pullRequest['number']);

				// $this->output->writeln('<comment>Skipping already reviewed: ' . $pullRequest['title'] . '</comment>');
				continue;
			}

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

			$codeReview = 0;
			if ($this->configuration['codeSniffer']['active'] === TRUE) {
				$codeReview = 1;
				$comments = array();
				$commentsFile = '.comments/' . $pullRequest['branch'];
				if (file_exists($commentsFile)) {
					$comments = json_decode(file_get_contents($commentsFile));
					if (count($comments) > 0) {
						$codeReview = -1;
						$message .= "\n\n" . 'Found some CGL problems';
					}
				}
			}

			if ($verified !== 0) {
				$this->output->writeln('<info>Reviewing: ' . $pullRequest['title'] . '</info>');
				// $this->gerrit->review($pullRequest['changeId'], $pullRequest['revisionId'], $message, $codeReview, $verified, $comments);
			}
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
				'standard' => 'TYPO3Flow',
				'active' => TRUE
			),
			'travis' => array(
				'maxBuilds' => 2
			),
			'rebasePrompt' => TRUE
		);
		$configuration = Yaml::parse('.george.yml');
		$configuration = array_replace_recursive($defaults, $configuration);
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