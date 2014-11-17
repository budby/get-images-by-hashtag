<?php
/**
 * @author Budnitsky Dmitry (dmitry.budnitski@gmail.com)
 * @version 0.1 (beta)
 */

require('libs/TwitterAPIExchange.php');

class imagesClass
{	
	
	/* Instagram */
	private $instagramAccessToken = 'ACCESS_TOKEN';
	private $instagramCounter = 0;
	public $instagramImages = array();
	public $instagramLimit = 50;

	/* Vk */
	public $vkImages = array();
	public $vkLimit = 50;

	/* Twitter */
	private $twitterOauthAccessToken = 'ACCESS_TOKEN';
	private $twitterOauthAccessTokenSecret = 'ACCESS_TOKEN_SEKRET';
	private $twitterConsumerKey = 'CONSUMER_KEY';
	private $twitterConsumerSecret = 'CONSUMER_KEY_SECRET';
	public $twitterImages = array();
	public $twitterLimit = 50;

	/* Facebook */
	private $facebookCounter = 0;
	private $facebookIdUser = ''; // user-id or group-id

	// generate long access_token
	// https://graph.facebook.com/oauth/access_token?client_id=CLIENT_ID&client_secret=CLIENT_SECRET&grant_type=fb_exchange_token&fb_exchange_token=ACCESS_TOKEN
	private $facebookAccessToken = 'LONG_ACCESS_TOKEN'; // 60 days
	public $facebookImages = array();
	public $facebookLimit = 50;

	/* Other */
	public $allImages = array();
	public $social_networks = array('Vk', 'Instagram', 'Twitter', 'Facebook');
	public $hashTag = 'hashtag';
	
	private $globalCounter = 0;
	private $maxThreshold = 200; // Maximum limit to avoid infinite loop

	/* --- -- - Instagram - -- --- */
	public function getInstagramImages()
	{
		$limit_str = ($this->instagramLimit > 20)? '':'&count='.$this->instagramLimit;
		$url = 'https://api.instagram.com/v1/tags/'.$this->hashTag.'/media/recent?access_token='.$this->instagramAccessToken.$limit_str;
		$this->getInstagramImagesRecursively($url);

		return $this->instagramImages;
	}

	private function getInstagramImagesRecursively($url)
	{
		$instagramObj = json_decode(file_get_contents($url));

		foreach($instagramObj->data as $data)
		{
			if($this->instagramCounter < $this->instagramLimit)
			{
				$this->instagramCounter++;

				$data = $this->unificationData(
					array(
						$data->user->id,
						$data->user->username,
						$data->images->low_resolution->url,
						$data->caption->text,
						date('Y-m-d H:i:s', $data->created_time),
						md5($data->images->low_resolution->url),
						'instagram'
					)
				);

				$this->instagramImages[] = $data;
			}
			else
			{
				break;
			}
		}
		
		if(
			isset($instagramObj->pagination->next_url) 
			&& $this->instagramCounter < $this->instagramLimit
		)
		{
			$this->getInstagramImagesRecursively($instagramObj->pagination->next_url);
		}
	}

	/* Vk */
	public function getVkImages()
	{
		$limit = 100;

		$vkObj = json_decode(file_get_contents('http://api.vkontakte.ru/method/newsfeed.search?limit='.$this->vkLimit.'&q=%23'.$this->hashTag));

		if(count($vkObj->response) > 1) 
		{
			foreach ($vkObj->response as $data)
			{
				if(
					isset($data->attachment) 
					&& $data->attachment->type == 'photo' 
					&& $data->owner_id > 0
					&& isset($data->attachment->photo->src_xbig)
				)
				{
					$username = $this->getVkUsername($data->owner_id);
					$data = $this->unificationData(
						array(
							$data->owner_id,
							$username,
							$data->attachment->photo->src_xbig,
							$data->text,
							date('Y-m-d H:i:s', $data->date),
							md5($data->attachment->photo->src_xbig),
							'vk'
						)
					);

					$this->vkImages[] = $data;
				}
			}
		}

		return $this->vkImages;
	}

	public function getVkUsername($uid)
	{
		$url = 'http://api.vkontakte.ru/method/users.get?uid='.$uid.'&fields=uid,first_name,last_name';
		$vkObj = json_decode(file_get_contents($url));

		$username = $vkObj->response[0]->first_name.' '.$vkObj->response[0]->last_name;

		return $username;
	}

	/* Twitter */
	public function getTwitterImages()
	{
		$url = 'https://api.twitter.com/1.1/search/tweets.json';
		$getfield = '?q=%23'.$this->hashTag.'&count='.$this->twitterLimit;
		$requestMethod = 'GET';

		$settings = array(
		    'oauth_access_token' => $this->twitterOauthAccessToken,
		    'oauth_access_token_secret' => $this->twitterOauthAccessTokenSecret,
		    'consumer_key' => $this->twitterConsumerKey,
		    'consumer_secret' => $this->twitterConsumerSecret
		);

		$twitter = new TwitterAPIExchange($settings);
		$response = $twitter->setGetfield($getfield)
			->buildOauth($url, $requestMethod)
		    ->performRequest();

		$arData = json_decode($response);

		foreach ($arData->statuses as $data) {
			if(
				isset($data->entities)
				&& isset($data->entities->media)
				&& $data->entities->media[0]->type == 'photo'
			)
			{
				$data = $this->unificationData(
					array(
						$data->user->id,
						$data->user->name,
						$data->entities->media[0]->media_url,
						$data->text,
						date('Y-m-d H:i:s', strtotime($data->created_at)),
						md5($data->entities->media[0]->media_url),
						'twitter'
					)
				);
				
				$this->twitterImages[] = $data;
			}
		}

		return $this->twitterImages;
	}

	/* Facebook */
	public function getFacebookImages()
	{
		$this->globalCounter = 0;

		$limit_str = ($this->facebookLimit > 20)? '':'&limit='.$this->facebookLimit;
		$url = 'https://graph.facebook.com/v2.2/'.$this->facebookIdUser.'/feed?access_token='.$this->facebookAccessToken.'&expires=5184000'.$limit_str;
		$this->getFacebookImagesRecursively($url);

		d(count($this->facebookImages));
		return $this->facebookImages;
	}

	private function getFacebookImagesRecursively($url)
	{
		$facebookObj = json_decode(file_get_contents($url));

		foreach ($facebookObj->data as $data) {
			$this->globalCounter++;

			if($this->facebookCounter < $this->facebookLimit)
			{
				if(
					isset($data->message) 
					&& strpos($data->message, '#'.$this->hashTag) !== false
					&& isset($data->picture)
				)
				{
					$this->facebookCounter++;
					$this->globalCounter--;

					$data = $this->unificationData(
						array(
							$data->from->id,
							$data->from->name,
							$data->picture,
							$data->message,
							date('Y-m-d H:i:s', strtotime($data->created_time)),
							md5($data->picture),
							'facebook'
						)
					);

					$this->facebookImages[] = $data;
				}
			}
			else
			{
				break;
			}
		}

		if(
			isset($facebookObj->paging->next)
			&& $this->facebookCounter < $this->facebookLimit
			&& $this->globalCounter < $this->maxThreshold
		)
		{
			$this->getFacebookImagesRecursively($facebookObj->paging->next);
		}
	}

	public function getAllImages()
	{
		foreach($this->social_networks as $social_network)
		{
			$method_name = 'get'.$social_network.'Images';

			if(method_exists($this, $method_name))
			{
				$this->allImages[$social_network] = call_user_func(array($this, $method_name));
			}
			else
			{
				trigger_error('getAllImages: Unknown social network "'.$social_network.'"', E_USER_WARNING);
			}
		}

		return $this->allImages;
	}


	public function unificationData($array)
	{
		if(!$array) return false;

		$array = array_values($array);
		$pattern = array(
			'id_social_profile' => $array[0],
			'username' => $array[1],
			'file_url_source' => $array[2],
			'text' => $array[3],
			'cdate' => $array[4],
			'hash' => $array[5],
			'method' => $array[6]
		);

		return $pattern;
	}
}
