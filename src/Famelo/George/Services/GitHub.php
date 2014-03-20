<?php
namespace Famelo\George\Services;

use Buzz\Browser;


/**
 *
 */
class GitHub extends Browser {

	/**
	 * @var \Github\Client
	 */
	protected $client;

	/**
	 * @var string
	 */
	protected $repository;

	public function __construct($token, $repository) {
		parent::__construct();
		$this->client = new \Github\Client();
		$this->repository = $repository;
		$this->client->authenticate($token, NULL, \Github\Client::AUTH_HTTP_TOKEN);
	}

	public function getPullRequests($state = null, $page = 1, $perPage = 300) {
		$parts = explode('/', $this->repository);
		return $this->client->api('pull_request')->all($parts[0], $parts[1], $state, $page, $perPage);
	}

	public function createPullRequest($parameters) {
		$parts = explode('/', $this->repository);
		return $this->client->api('pull_request')->create($parts[0], $parts[1], $parameters);
	}

	public function closePullRequest($pullRequest) {
		$parts = explode('/', $this->repository);
		return $this->client->api('pull_request')->update($parts[0], $parts[1], $pullRequest, array('state' => 'closed'));
	}
}

?>