<?php

namespace Genj\SocialFeedBundle\Api;

use Genj\SocialFeedBundle\Entity\Post;
use Symfony\Component\Config\Definition\Exception\Exception;
use Facebook\FacebookSession;
use Facebook\Entities\AccessToken;
use Facebook\FacebookRequest;

/**
 * Class FacebookApi
 *
 * @package Genj\SocialFeedBundle\Api
 */
class FacebookApi extends SocialApi
{
    protected $providerName = 'facebook';

    /**
     * @param array $oAuthConfig
     */
    public function __construct($oAuthConfig)
    {
        FacebookSession::setDefaultApplication($oAuthConfig['facebook']['app_id'], $oAuthConfig['facebook']['app_secret']);

        $accessToken = AccessToken::requestAccessToken(array('grant_type' => 'client_credentials'));
        $this->api = new FacebookSession($accessToken);
    }

    /**
     * @param string $username
     *
     * @return array
     */
    public function getUserPosts($username)
    {
        try {
            $data = $this->requestGet('/'. $username .'/posts');
        } catch (\Exception $ex) {
            echo $ex->getMessage();

            return array();
        }

        return $data->asArray()['data'];
    }

    /**
     * @param \stdClass|array $socialPost
     *
     * @return Post
     */
    protected function getMappedPostObject($socialPost)
    {
        $post = new Post();

        if (!isset($socialPost->message)) {
            return false;
        }

        $post->setProvider($this->providerName);
        $post->setPostId($socialPost->id);

        $userDetails = json_decode(file_get_contents("https://graph.facebook.com/". $socialPost->from->id.'?access_token='.$this->api->getAccessToken()));

        $post->setAuthorUsername($userDetails->username);
        $post->setAuthorName($socialPost->from->name);
        $post->setAuthorFile('https://graph.facebook.com/'. $socialPost->from->id .'/picture'.'?access_token='.$this->api->getAccessToken());
        $post->setHeadline(strip_tags($socialPost->message));

        $message = $this->getFormattedTextFromPost($socialPost);
        $post->setBody($message);

        if (isset($socialPost->picture) && !empty($socialPost->picture)) {
            // A picture is set, use the original url as a backup
            $post->setFile($socialPost->picture);

            // If there is an object_id, then the original file may be available, so check for that one
            if (isset($socialPost->object_id)) {
                $imageDetails = json_decode(file_get_contents("https://graph.facebook.com/". $socialPost->object_id.'?access_token='.$this->api->getAccessToken()));
                if (isset($imageDetails->images[0]->source)) {
                    $post->setFile($imageDetails->images[0]->source);
                }
            } else {
                // Check if it is an external image, if so, use the original one.
                $pictureUrlData = parse_url($socialPost->picture);
                if (preg_match('#^fbexternal#', $pictureUrlData['host']) === 1) {
                    parse_str($pictureUrlData['query'], $pictureUrlQueryData);
                    if (isset($pictureUrlQueryData['url'])) {
                        $post->setFile($pictureUrlQueryData['url']);
                    }
                }
            }
        }

        $post->setLink('https://www.facebook.com/'. $socialPost->id);

        $post->setPublishAt(new \DateTime($socialPost->created_time));
        $post->setIsActive(true);

        return $post;
    }

    protected function getFormattedTextFromPost($socialPost)
    {
        $text = $socialPost->message;

        // Add href for links prefixed with ***:// (*** is most likely to be http(s) or ftp
        $text = preg_replace("#(^|[\n ])([\w]+?://[\w]+[^ \"\n\r\t< ]*)#", "\\1<a href=\"\\2\" target=\"_blank\">\\2</a>", $text);
        // Add href for links starting with www or ftp
        $text = preg_replace("#(^|[\n ])((www|ftp)\.[^ \"\t\n\r< ]*)#", "\\1<a href=\"http://\\2\" target=\"_blank\">\\2</a>", $text);
        // Add link to hashtags
        $text = preg_replace("/#(\w+)/", "<a href=\"https://www.facebook.com/hashtag/\\1\" target=\"_blank\">#\\1</a>", $text);

        return $text;
    }

    protected function requestGet($method, $parameters = array())
    {
        try {
            $response = (new FacebookRequest($this->api, 'GET', $method, $parameters))->execute();

            return $response->getGraphObject();
        } catch (\Exception $ex) {
            throw $ex;
        }
    }

}
