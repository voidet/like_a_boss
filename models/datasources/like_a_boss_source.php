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
		$this->oauth->enableDebug();
	}

	public function renewAccessToken() {
		$accessTokens = Cache::read('LikeABoss.access_tokens', 'like_a_boss');
		if (!empty($accessTokens['oauth_token'])) {
			$tokenRemaining = ($accessTokens['access_token_received'] + $accessTokens['oauth_expires_in']) - time();
			$tokenRemaining = 0;
			if ($tokenRemaining < 60) {
				$this->requestAccessToken($accessTokens);
				Cache::write('LikeABoss.access_tokens', $accessTokens, 'like_a_boss');
			}
			$this->oauth->setToken($accessTokens['oauth_token'], $accessTokens['oauth_token_secret']);
		}
	}

	public function requestToken($callbackUrl) {
		if (!is_object($this->oauth)) {
			$this->buildOAuth();
		}
		return $this->oauth->getRequestToken('https://api.login.yahoo.com/oauth/v2/get_request_token', Router::url($callbackUrl, true));
	}

	public function requestAccessToken($requestTokens) {
		if (!is_object($this->oauth)) {
			$this->buildOAuth();
		}
		$this->oauth->setToken($requestTokens['oauth_token'], $requestTokens['oauth_token_secret']);
		return $this->oauth->getAccessToken('https://api.login.yahoo.com/oauth/v2/get_token');
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
		unset($query['fields'], $query['page'], $query['callbacks']);

		if (!isset($this->response)) {
			$this->buildOAuth();

			try {
				$this->renewAccessToken();
				$this->response = $this->oauth->fetch('http://yboss.yahooapis.com/ysearch/'.$services, $this->encodeParams($query), OAUTH_HTTP_METHOD_GET);
			} catch (OAuthException $error) {
				debug($error->getMessage());
				debug($this->oauth->debugInfo);
			}
			exit;
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
		}

		if (Set::extract($queryData, 'fields') == '__yahoo_count' ) {
			return array(
				array(
					$Model->alias => array('count' => $this->response->bossresponse->web->totalresults),
				),
			);
		}

		return $results;
	}

	public function calculate(&$model, $func, $params = array()) {
		return '__yahoo_count';
	}

}