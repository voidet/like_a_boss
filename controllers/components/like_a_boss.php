<?php

class LikeABossComponent extends Object {

	public $components = array('Session');

	public function initialize(&$controller) {
		$this->controller = $controller;
		$this->LikeABoss = ConnectionManager::getDataSource('likeABoss');
	}

	public function requestToken($callbackUrl = '') {
		$this->controller->autoRender = false;
		$response = $this->LikeABoss->requestToken($callbackUrl);
		$this->controller->Session->write('LikeABoss.response', $response);
		$this->controller->redirect('https://api.login.yahoo.com/oauth/v2/request_auth?oauth_token=' . $response->key);
	}

	public function requestAccessToken() {
		$this->controller->autoRender = false;
		$requestToken = $this->Session->read('LikeABoss.response');
		$accessToken = $this->LikeABoss->requestAccessToken($requestToken);
		return $accessToken;
	}

}