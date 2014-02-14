<?php

	namespace phpish\shopify;
	use phpish\http;


	function install_url($shop, $api_key)
	{
		return "http://$shop/admin/api/auth?api_key=$api_key";
	}


	function is_valid_request($query_params, $shared_secret)
	{
		$seconds_in_a_day = 24 * 60 * 60;
		$older_than_a_day = $query_params['timestamp'] < (time() - $seconds_in_a_day);
		if ($older_than_a_day) return false;

		$signature = $query_params['signature'];
		unset($query_params['signature']);

		foreach ($query_params as $key=>$val) $params[] = "$key=$val";
		sort($params);

		return (md5($shared_secret.implode('', $params)) === $signature);
	}


	function authorization_url($shop, $api_key, $scopes=array(), $redirect_uri='')
	{
		$scopes = empty($scopes) ? '' : '&scope='.implode(',', $scopes);
		$redirect_uri = empty($redirect_uri) ? '' : '&redirect_uri='.urlencode($redirect_uri);
		return "https://$shop/admin/oauth/authorize?client_id=$api_key$scopes$redirect_uri";
	}


	function access_token($shop, $api_key, $shared_secret, $code)
	{
		return http\request("POST https://$shop/admin/oauth/access_token", array(), array('client_id'=>$api_key, 'client_secret'=>$shared_secret, 'code'=>$code));
	}


	function client($shop, $shops_token, $api_key, $shared_secret, $private_app=false, $legacy=false)
	{
		$shops_token = $legacy ? legacy_token_to_oauth_token($shops_token, $shared_secret, $private_app) : $shops_token;
		$base_uri = $legacy ? legacy_baseurl($shop, $api_key, $shops_token) : "https://$shop";

		return function ($method_uri, $query='', $payload='', &$response_headers=array(), $request_headers=array(), $curl_opts=array()) use ($base_uri, $shops_token)
		{
			$request_headers['X-Shopify-Access-Token'] = $shops_token;
			$request_headers['content-type'] = 'application/json; charset=utf-8';
			$http_client = http\client($base_uri, $request_headers);

			try
			{
				$response = $http_client($method_uri, $query, $payload, $response_headers, $request_headers, $curl_opts);
			}
			catch (http\CurlException $e) { throw new CurlException($e->getMessage(), $e->getCode(), $e->getRequest()); }
			catch (http\ResponseException $e) { throw new ApiException($e->getMessage(), $e->getCode(), $e->getRequest(), $e->getResponse()); }
			if (isset($response['errors']))
			{
				list($method, $uri) = explode(' ', $method_uri, 2);
				$uri = rtrim($base_uri).'/'.ltrim($uri, '/');
				$headers = $request_headers;
				$request = compact('method', 'uri', 'query', 'headers', 'payload');
				$response = array('headers'=>$response_headers, 'body'=>$response);
				throw new ApiException($response_headers['http_status_message'].": $uri", $response_headers['http_status_code'], $request, $response);
			}

			return (is_array($response) and !empty($response)) ? array_shift($response) : $response;

		};
	}


	function calls_made($response_headers)
	{
		return _shop_api_call_limit_param(0, $response_headers);
	}


	function call_limit($response_headers)
	{
		return _shop_api_call_limit_param(1, $response_headers);
	}


	function calls_left($response_headers)
	{
		return call_limit($response_headers) - calls_made($response_headers);
	}


		function _shop_api_call_limit_param($index, $response_headers)
		{
			$params = explode('/', $response_headers['http_x_shopify_shop_api_call_limit']);
			return (int) $params[$index];
		}


	class Exception extends http\Exception { }
	class CurlException extends Exception { }
	class ApiException extends Exception { }


	function legacy_token_to_oauth_token($shops_token, $shared_secret, $private_app=false)
	{
		return $private_app ? $secret : md5($shared_secret.$shops_token);
	}


	function legacy_baseurl($shop, $api_key, $password)
	{
		return "https://$api_key:$password@$shop/";

	}

?>