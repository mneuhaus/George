<?php
namespace Famelo\George\Services;

use Buzz\Browser;

/**
 *
 */
class Travis extends Browser {

	/**
	 * @var \Travis\Client
	 */
	protected $client;

	/**
	 * @var string
	 */
	protected $repository;

	public function __construct($repository) {
		parent::__construct();
		$this->repository = $repository;
		$this->client = new \Travis\Client();
	}

	public function getBranchStatus($branch) {
		$uri = 'https://api.travis-ci.org/repos/' . $this->repository . '/branches/' . $branch . '.json';
		$response = $this->get($uri);
		return json_decode($response->getContent());
	}
}

?>