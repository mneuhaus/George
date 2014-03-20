<?php
namespace Famelo\George\Services;

use Buzz\Browser;

/**
 *
 */
class Travis extends Browser {

	/**
	 * @var string
	 */
	protected $repository;

	/**
	 * @var string
	 */
	protected $baseUrl = 'https://api.travis-ci.org';

	/**
	 * @var string
	 */
	protected $token;

	public function __construct($token, $repository, $output) {
		parent::__construct();
		$this->repository = $repository;
		$this->output = $output;
		$this->token = $token;
	}

	public function post($uri, $headers = array(), $content = '') {
		$headers[] = 'Authorization:token ' . $this->token;
		return parent::post($uri, $headers, $content);
	}

	public function getBranchStatus($branch) {
		$uri = $this->baseUrl . '/repos/' . $this->repository . '/branches/' . $branch . '.json';
		$response = $this->get($uri);
		$response = json_decode($response->getContent());

		if ($response->branch->state === 'failed') {
			foreach ($response->branch->job_ids as $jobId) {
				$job = $this->getJob($jobId);
				if ($job->state = 'failed' && stristr($job->log, 'cURL reported error code 28')) {
					$this->restartJob($jobId);
					$response->branch->state = 'restarted';
				}
			}
		}
		return $response;
	}

	public function getJob($id) {
		$uri = $this->baseUrl . '/jobs/' . $id . '.json';
		$response = $this->get($uri);
		$response = json_decode($response->getContent());
		return $response;
	}

	public function restartJob($id) {
		$uri = $this->baseUrl . '/jobs/' . $id . '/restart';
		$response = $this->post($uri);
		$response = json_decode($response->getContent());
		return $response;
	}
}

?>