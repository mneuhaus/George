<?php
namespace Famelo\George\Services;

use Buzz\Browser;
use Buzz\Listener\BasicAuthListener;


/**
 *
 */
class Gerrit extends Browser {

	/**
	 * @var string
	 */
	protected $baseUrl;

	/**
	 * @var string
	 */
	protected $project;

	public function __construct($baseUrl, $project, $username, $password) {
		parent::__construct();
		$this->baseUrl = $baseUrl;
		$this->project = $project;
		$this->username = $username;
		$this->password = $password;
		$this->addListener(new BasicAuthListener($username, $password));
		$this->codeSniffer = new CodeSniffer();
	}

	public function getChangeSets($count = 1) {
		$uri = $this->baseUrl . 'changes/?q=is:open+project:' . $this->project . '&o=ALL_REVISIONS&o=CURRENT_COMMIT&o=ALL_FILES&n=' . $count;
		$response = $this->get($uri);
		$gerritChangeSets = $response->getContent();
		$gerritChangeSets = str_replace(')]}\'', '', $gerritChangeSets);
		return json_decode(trim($gerritChangeSets));
	}

	public function getChangeSetDetail($id) {
		$detail = $this->curlGet('https://review.typo3.org/changes/' . $id . '/detail');
		$detail = str_replace(')]}\'', '', $detail);
		return json_decode(trim($detail));
	}

	public function getReviews($changeId, $revision) {
		$uri = $this->baseUrl . 'changes/' . $changeId . '/revisions/' . $revision . '/review';
		$content = $this->get($uri)->getContent();
		$content = str_replace(')]}\'', '', $content);
		$rawReviews = json_decode(trim($content));

		if ($rawReviews === NULL) {
			return array();
		}

		$labels = get_object_vars($rawReviews->labels);

		$reviews = array();
		if (isset($labels['Verified']) && is_array($labels['Verified']->all)) {
			foreach ($labels['Verified']->all as $review) {
				if (!isset($reviews[$review->_account_id])) {
					$reviews[$review->_account_id] = array(
						'name' => $review->name,
						'_account_id' => $review->_account_id,
						'verified' => 0,
						'review' => 0
					);
				}
				$reviews[$review->_account_id]['verified'] = $review->value;
			}
		}

		if (isset($labels['Code-Review']) && is_array($labels['Code-Review']->all)) {
			foreach ($labels['Code-Review']->all as $review) {
				if (!isset($reviews[$review->_account_id])) {
					$reviews[$review->_account_id] = array(
						'name' => $review->name,
						'_account_id' => $review->_account_id,
						'verified' => 0,
						'review' => 0
					);
				}
				$reviews[$review->_account_id]['review'] = $review->value;
			}
		}

		return $reviews;
	}

	public function getReview($changeId, $revision, $userId = NULL) {
		$reviews = $this->getReviews($changeId, $revision);
		if (isset($reviews[$userId])) {
			return $reviews[$userId];
		}
	}

	public function getMyReview($changeId, $revision) {
		return $this->getReview($changeId, $revision, $this->getUserId());
	}

	public function getUserId() {
		$uri = $this->baseUrl . 'accounts/' . $this->username;
		$response = $this->get($uri);
		$content = $response->getContent();
		$content = str_replace(')]}\'', '', $content);
		$user = json_decode(trim($content));
		return $user->_account_id;
	}

	public function review($changeId, $revision, $message, $codeOk = 0, $verified = 0, $comments = array()) {
		$uri = $this->baseUrl . 'a/changes/' . $changeId . '/revisions/' . $revision . '/review';
		$payload = array(
			'message' => $message,
			'labels' => array(
				'Code-Review' => $codeOk,
				'Verified' => $verified
			)
		);
		if (count($comments) > 0) {
			$payload['comments'] = $comments;
		}
		return $this->post($uri, array(
			'Content-Type: application/json; charset=UTF-8'
			),
			json_encode($payload)
		);
	}

	public function reviewFiles($changeSet) {
		$revisions = get_object_vars($changeSet->revisions);
		$revisionId = key($revisions);
		$revision = current($revisions);
		$files = array_keys(get_object_vars($revision->files));

		$comments = array();
		foreach ($files as $file) {
			if (!file_exists($file) || substr($file, -4) !== '.php') {
				continue;
			}
			$result = $this->codeSniffer->sniff($file);
			if ($result->totals->errors == 0 && $result->totals->warnings == 0) {
				continue;
			}
			$comments[$file] = array();
			foreach ($result->files->{$file} as $messages) {
				foreach ($messages as $message) {
					$comments[$file][] = array(
						'line' => $message->line,
						'message' => $message->type . ': ' . $message->message
					);
				}
			}
		}
		return $comments;
	}

	public function isAlreadyReviewed($changeId, $revisionId) {
		$review = $this->getMyReview($changeId, $revisionId);
		if ($review == NULL) {
			return FALSE;
		}
		if ($review['review'] == 0 && $review['verified'] == 0) {
			return FALSE;
		}
		return TRUE;
	}
}

?>