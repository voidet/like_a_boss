<?php

class LikeABossSource extends DataSource {

	public $defaults = array(
		'services' => array('web'),
	);
	public $oauth;
	public $response;

	function __construct(&$config) {
		if (empty($config)) {
			die('Please specify the likeABoss configuration in app/config/database.php');
		}
		$this->config = $config;
		parent::__construct($this->config);
	}

	public function buildOAuth() {
		$this->oauth = new OAuth($this->config['consumer_key'], $this->config['consumer_secret'], OAUTH_SIG_METHOD_HMACSHA1, OAUTH_AUTH_TYPE_URI);
	}

	public function renewAccessToken() {
		$accessTokens = $this->setToken();

		if (!empty($accessTokens['oauth_token'])) {
			$tokenRemaining = ($accessTokens['access_token_received'] + $accessTokens['oauth_expires_in']) - time();

			//60 second time out on re-requesting access tokent to avoid thundering herd
			$cacheLock = true;
			if (time() - $accessTokens['cache_lock'] > 60) {
				$cacheLock = false;
			}

			if ($tokenRemaining < 60 && $cacheLock === false) {
				$cacheLock = time() + 60;
				$sessionHandle = array('oauth_session_handle' => $accessTokens['oauth_session_handle']);
				if ($this->oauth->fetch('https://api.login.yahoo.com/oauth/v2/get_token', $sessionHandle)) {
					$accessTokens = $this->oauth->getLastResponse();
					parse_str($accessTokens, $accessTokens);
					$accessTokens['access_token_received'] = time();
					$accessTokens['cache_lock'] = $cacheLock;
					Cache::write('LikeABoss', $accessTokens, 'like_a_boss');
				}
			}
		}
	}

	public function setToken() {
		$accessTokens = Cache::read('LikeABoss', 'like_a_boss');
		$this->oauth->setToken($accessTokens['oauth_token'], $accessTokens['oauth_token_secret']);
		return $accessTokens;
	}

	public function requestToken($callbackUrl) {
		if (!is_object($this->oauth)) {
			$this->buildOAuth();
		}
		try {
			return $this->oauth->getRequestToken('https://api.login.yahoo.com/oauth/v2/get_request_token', Router::url($callbackUrl, true));
		} catch (OAuthException $error) {
			$this->log($this->oauth->debugInfo);
		}
	}

	public function requestAccessToken($requestTokens) {
		$this->buildOAuth();
		$this->oauth->setToken($requestTokens['oauth_token'],  $requestTokens['oauth_token_secret']);
		try {
			return $this->oauth->getAccessToken('https://api.login.yahoo.com/oauth/v2/get_token');
		} catch (OAuthException $error) {
			$this->log($this->oauth->debugInfo);
		}
	}

	public function encodeParams($data) {
		$encoded = array();
		foreach ($data as $key => &$value) {
			$encoded[oauth_urlencode($key)] = oauth_urlencode($value);
		}
		return $encoded;
	}

	public function read(&$Model, $queryData = array()) {
		$arrayMappings = array(
			'offset' => 'start',
			'limit' => 'count',
		);

		ksort($queryData['services']);
		$services = implode(',', array_keys($queryData['services']));

		$query = array();
		if (count($queryData['services']) > 1) {
			foreach ($queryData['services'] as $service => $terms) {
				$query[$service.'.q'] = implode(' ', array_map('strtolower', $terms));
			}
		} else {
			$query['q'] = urlencode(implode(' + ', array_map('strtolower', array_shift($queryData['services']))));
		}

		unset($queryData['services']);

		foreach ($queryData as $key => $value) {

			if (is_array($value)) {
				$value = implode(',', array_map('strtolower', $value));
			} else {
				$value = $queryData[$key];
			}

			if (in_array($key, array_keys($arrayMappings)) && !empty($value)) {
				$query[$arrayMappings[$key]] = $value;
			} else {
				$query[$key] = $value;
			}
		}

		$query = array_filter($query);
		$query['format'] = 'json';
		unset($query['fields'], $query['page'], $query['callbacks']);

		if (!isset($this->response)) {
			$this->buildOAuth();
			try {
				$this->renewAccessToken();
				$this->oauth->fetch('http://yboss.yahooapis.com/ysearch/'.$services, $this->encodeParams($query), OAUTH_HTTP_METHOD_GET);
			} catch (OAuthException $error) {
				$this->log($this->oauth->debugInfo);
			}

			$this->response = $this->oauth->getLastResponse();
			$this->response = json_decode($this->response);
			if (!is_object($this->response)) {
				$this->response = array();
			}
		}

		$results = array();
		if (!empty($this->response->bossresponse->web->results)) {
			foreach ($this->response->bossresponse->web->results as $record) {
				$results[] = array($Model->alias => (array)$record);
			}
			$results['web'] = $this->__getPage($results, $queryData);
		}

		if (!empty($this->response->bossresponse->spelling->results)) {
			$spelling = array();
			foreach ($this->response->bossresponse->spelling->results as $suggestion) {
				$spelling['suggestion'] = (array)$suggestion;
			}
			$results['spelling'] = $spelling;
		}

		if (Set::extract($queryData, 'fields') == '__count' ) {
			if (empty($this->response->bossresponse->web->totalresults)) {
				$count = 0;
			} else {
				$count = $this->response->bossresponse->web->totalresults;
			}

			return array(array($Model->alias => array('count' => $count)));
		}

		return $results;
	}

	//Credit to Richard Willis Owen on his guide to pagination
	private function __getPage($items = null, $queryData = array()) {
		if (empty($queryData['limit'])) {
			return $items;
		}
		$limit = $queryData['limit'];
		$page = $queryData['page'];
		$offset = $limit * ($page - 1);
		return array_slice($items, $offset, $limit);
	}

	public function calculate(&$model, $func, $params = array()) {
		return '__'.$func;
	}

}