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

		$services = $queryData['services'];
		ksort($queryData['services']);

		$query = array();
		if (count($queryData['services']) > 1) {
			foreach ($queryData['services'] as $service => $values) {

				if ($service == 'spelling') {
					$query[$service.'.q'] = trim(implode(' ', array_map('strtolower', $values['terms'])));
					continue;
				}

				$query[$service.'.q'] = 'inbody:'.trim(implode(' ', array_map('strtolower', $values['terms'])));
				if (!empty($values['sites'])) {
					$query[$service.'.q'] = $query[$service.'.q'].'(site:'.implode(' OR site:', $values['sites']).')';
				}
			}
		} else {
			$service = array_shift($queryData['services']);
			$query['q'] = trim(urlencode(implode(' ', array_map('strtolower', $service['terms']))));

			if (!empty($service['site'])) {
				$query['q'] = 'inbody:'.$query['q'].'(site:'.$service['site'].')';
			}
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

		if (Set::extract($queryData, 'fields') == '__count') {
			$primaryService = array_shift(array_keys($services));
			$countQuery = $query;
			ksort($countQuery);
			$count = Cache::read('LikeABoss-count'.md5(serialize($countQuery)), 'like_a_boss');

			if (empty($count)) {
				$this->__getQuery($query, $services, true);
				if (empty($this->response['bossresponse'][$primaryService]['totalresults'])) {
					$count = 0;
				} else {
					$count = $this->response['bossresponse'][$primaryService]['totalresults'];
				}
			}

			return array(array($Model->alias => array('count' => $count)));
		} else {
			$this->__getQuery($query, $services);
		}

		$results = $this->__formatResults($Model, $this->response, $queryData);
		return $results;
	}

	private function __getQuery($query = array(), $services = '', $count = false) {
		$this->buildOAuth();
		try {
			//Taking out use of Access tokens for now as Boss v2 looks like they no longer require it.
			//$this->renewAccessToken();
			$this->oauth->fetch('http://yboss.yahooapis.com/ysearch/'.implode(',', array_keys($services)), $this->encodeParams($query), OAUTH_HTTP_METHOD_GET);
		} catch (OAuthException $error) {
			$this->log($this->oauth->debugInfo);
		}

		//Sorry but Yahoo returns invalid JSON at this point in time
		$json = str_replace(',"fingerprint":{"type":"default",}', '', $this->oauth->getLastResponse());
		$this->response = json_decode($json, true);
		if (!empty($this->response) && empty($this->response['error'])) {
			ksort($query);
			$primaryService = array_shift(array_keys($services));
			unset($query['start']);
			Cache::write('LikeABoss-count'.md5(serialize($query)), $this->response['bossresponse'][$primaryService]['totalresults'], 'like_a_boss');
		}
	}

	private function __formatResults(&$Model, $response, $queryData) {
		$results = array();
		$webResults = array();
		$pagedResults = array('web', 'limitedweb', 'images');

		foreach ($pagedResults as $service) {
			$serviceResults = array();
			if (!empty($response['bossresponse'][$service]['results'])) {
				foreach ($response['bossresponse'][$service]['results'] as $record) {
					$serviceResults[] = array($Model->alias => $record);
				}
				$results[$service] = $this->__getPage($serviceResults, $queryData);
			}
		}

		$spellingResults = array();
		if (!empty($response['bossresponse']['spelling']['results'])) {
			foreach ($response['bossresponse']['spelling']['results'] as $suggestion) {
				$spellingResults[] = $suggestion;
			}
			$results['spelling'] = $spellingResults;
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
		$offset = 0;
		return array_slice($items, $offset, $limit);
	}

	public function calculate(&$model, $func, $params = array()) {
		return '__'.$func;
	}

}