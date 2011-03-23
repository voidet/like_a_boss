<?php

class LikeABossSource extends DataSource {

	public static $response;
	public $defaults = array(
		'services' => array('web'),
	);

	function __construct(&$config) {
		if (empty($config)) {
			die('Please specify the likeABoss configuration in app/config/database.php');
		}
		$this->config = $config;
		App::import('Vendor', 'Oauth.oauth', array('file' => 'oauth'.DS.'oauth_consumer.php'));
		$this->oauth = new OAuth_Consumer($this->config['consumer_key'], $this->config['consumer_secret']);
		parent::__construct($this->config);
	}

	public function requestToken($callbackUrl) {
		return $this->oauth->getRequestToken('https://api.login.yahoo.com/oauth/v2/get_request_token', Router::url($callbackUrl, true));
	}

	public function requestAccessToken($requestTokens) {
		return $this->oauth->getAccessToken('https://api.login.yahoo.com/oauth/v2/get_token', $requestTokens);
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
				$query[$service.'.q'] = urlencode(implode(' ', array_map('strtolower', $terms)));
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

		if (!isset($this->reponse)) {
			$this->reponse = $this->oauth->get($this->config['access_token'], $this->config['access_secret'], 'http://yboss.yahooapis.com/ysearch/'.$services, $query);
			$this->reponse = json_decode($this->reponse);
		}

		$results = array();
		if (!empty($this->reponse->bossresponse->web->results)) {
			foreach ($this->reponse->bossresponse->web->results as $record) {
				$results[] = array($Model->alias => (array)$record);
			}
		}

		if (Set::extract($queryData, 'fields') == '__yahoo_count' ) {
			return array(
				array(
					$Model->alias => array('count' => $this->reponse->bossresponse->web->totalresults),
				),
			);
		}

		return $results;
	}

	public function calculate(&$model, $func, $params = array()) {
		return '__'.$func;
	}

}
