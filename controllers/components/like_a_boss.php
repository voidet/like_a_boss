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
		if (!empty($response['xoauth_request_auth_url'])) {
			$this->controller->Session->write('LikeABoss.response', $response);
			$this->controller->redirect($response['xoauth_request_auth_url']);
		}
	}

	public function requestAccessToken() {
		$this->controller->autoRender = false;
		$requestToken = $this->Session->read('LikeABoss.response');
		$accessToken = $this->LikeABoss->requestAccessToken($requestToken);
		$accessToken['access_token_received'] = time();
		return Cache::write('LikeABoss.access_tokens', $accessToken, 'like_a_boss');
	}

}