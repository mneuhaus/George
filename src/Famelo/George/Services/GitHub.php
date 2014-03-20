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

	public function __construct($username, $password, $repository) {
		parent::__construct();
		$this->client = new \Github\Client();
		$this->username = $username;
		$this->password = $password;
		$this->repository = $repository;
		$this->client->authenticate($username, $password, \Github\Client::AUTH_HTTP_PASSWORD);
	}

	public function getPullRequests($state = null, $page = 1, $perPage = 300) {
		$parts = explode('/', $this->repository);
		return $this->client->api('pull_request')->all($parts[0], $parts[1], $state, $page, $perPage);
	}

	public function createPullRequest($parameters) {
		$parts = explode('/', $this->repository);
		return $this->client->api('pull_request')->create($parts[0], $parts[1], $parameters);
	}
}

?>