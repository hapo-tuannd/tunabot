<?php

namespace BotMan\Drivers\Line;

use BotMan\BotMan\Users\User;
use Illuminate\Support\Collection;
use BotMan\Drivers\Line\Events\Join;
use BotMan\BotMan\Drivers\HttpDriver;
use BotMan\Drivers\Line\Events\Leave;
use BotMan\Drivers\Line\Events\Follow;
use BotMan\Drivers\Line\Events\Postback;
use BotMan\Drivers\Line\Events\Unfollow;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Attachments\Audio;
use BotMan\BotMan\Messages\Attachments\Image;
use BotMan\BotMan\Messages\Attachments\Video;
use BotMan\BotMan\Messages\Outgoing\Question;
use Symfony\Component\HttpFoundation\Request;
use BotMan\BotMan\Messages\Attachments\Location;
use Symfony\Component\HttpFoundation\ParameterBag;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;

class LineDriver extends HttpDriver
{
    const DRIVER_NAME = 'Line';

    const API_URL_BASE = 'https://api.line.me/v2/bot';

    protected $messages = [];

    /**
     * @param Request $request
     */
    public function buildPayload(Request $request)
    {   
        $this->payload = new ParameterBag((array) json_decode($this->content, true));
        $this->event = Collection::make($this->payload->get('events') ? $this->payload->get('events')[0] : null);
        $this->signature = $request->headers->get('X_LINE_SIGNATURE', '');
        $this->config = Collection::make($this->config->get('line'));
    }

    /**
     * Low-level method to perform driver specific API requests.
     *
     * @param string $endpoint
     * @param array $parameters
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
     * @return void
     */
    public function sendRequest($endpoint, array $parameters, IncomingMessage $matchingMessage)
    {
        $parameters = [
            'replyToken' => $this->event->get('replyToken'),
            'messages' => [$parameters],
        ];

        return $this->http->post($this->getApiUrl($endpoint), [], $parameters, [
            'Authorization: Bearer '.$this->config->get('channel_access_token'),
            'Content-Type: application/json',
        ], true);
    }

    /**
     * @param IncomingMessage $matchingMessage
     * @return \BotMan\BotMan\Users\User
     */
    public function getUser(IncomingMessage $matchingMessage)
    {
        $userId = $this->event->get('source')['userId'];

        $userInfoData = $this->http->get($this->getApiUrl("/profile/{$userId}"), [], [
            'Authorization: Bearer '.$this->config->get('channel_access_token'),
            'Content-Type: application/json',
        ], true);

        $userInfo = json_decode($userInfoData->getContent(), true);

        info($userInfoData->getContent());

        return new User($userId, $userInfo['displayName'], null, null, $userInfo);
    }

    /**
     * Determine if the request is for this driver.
     *
     * @return bool
     */
    public function matchesRequest()
    {
        // info('matching Request', $this->event);
        // dd($this->event[0]);
        if ($this->validateSignature()
        && in_array($this->event->get('type'), [
            'follow',
            'unfollow',
            'join',
            'leave',
            'message',
            'postback',
        ], true)
        && $this->event->has('replyToken')
        && $this->event->has('timestamp')
        && $this->event->has('source')) {
            info('matchesRequest done');
        }

        return $this->validateSignature()
            && in_array($this->event->get('type'), [
                'follow',
                'unfollow',
                'join',
                'leave',
                'message',
                'postback',
            ], true)
            && $this->event->has('replyToken')
            && $this->event->has('timestamp')
            && $this->event->has('source');
    }

    /**
     * @param  IncomingMessage $message
     * @return \BotMan\BotMan\Messages\Incoming\Answer
     */
    public function getConversationAnswer(IncomingMessage $message)
    {
        return Answer::create($message->getText())->setMessage($message);
    }

    /**
     * @return bool|DriverEventInterface
     */
    public function hasMatchingEvent()
    {
        switch ($this->event->get('type')) {
            case 'follow':
                return new Follow($this->event->get('source'));
                break;

            case 'unfollow':
                return new Unfollow($this->event->get('source'));
                break;

            case 'join':
                return new Join($this->event->get('source'));
                break;

            case 'leave':
                return new Leave($this->event->get('source'));
                break;

            case 'postback':
                return new Postback($this->event->get('postback'));
                break;

            default:
                return false;
                break;
        }

        return false;
    }

    /**
     * Retrieve the chat message.
     *
     * @return array
     */
    public function getMessages()
    {   
        if (empty($this->messages)) {
            $message = new IncomingMessage(
                '',
                $this->getMessageSender($this->event->get('source')),
                '',
                $this->payload
            );

            if (isset($this->event->get('message')['text'])) {
                info('mess content: ' . $this->event->get('message')['text']);
                $message->setText($this->event->get('message')['text']);
            } elseif (isset($this->event->get('postback')['data'])) {
                $message->setText($this->event->get('postback')['data']);
            }

            $this->messages = [$message];
        }
       
        return $this->messages;
    }

    /**
     * @param array $source
     * @return string
     */
    protected function getMessageSender(array $source)
    {
        if (! in_array($source['type'], ['user', 'group', 'room'], true)) {
            return '';
        }

        return $source[$source['type'].'Id'];
    }

    /**
     * @param Question|OutgoingMessage $message
     * @param IncomingMessage $matchingMessage
     * @param array $additionalParameters
     * @return array
     */
    public function buildServicePayload($message, $matchingMessage, $additionalParameters = [])
    {
        $parameters = [
            'replyToken' => $this->event->get('replyToken'),
            'messages' => [],
        ];
        info($parameters); // we added this line

        if ($message instanceof OutgoingMessage) {
            $parameters['messages'][] = [
                'type' => 'text',
                'text' => $message->getText() . "xxxxxxxxxxx",
            ];

            $attachment = $message->getAttachment();

            if ($attachment !== null) {
                if ($attachment instanceof Image) {
                    $parameters['messages'][] = [
                        'type' => 'image',
                        'originalContentUrl' => $attachment->getUrl(),
                        'previewImageUrl' => $attachment->getUrl(),
                    ];
                } elseif ($attachment instanceof Video) {
                    $parameters['messages'][] = [
                        'type' => 'video',
                        'originalContentUrl' => $attachment->getUrl(),
                        'previewImageUrl' => $attachment->getExtras('thumbnail'),
                    ];
                } elseif ($attachment instanceof Audio) {
                    $parameters['messages'][] = [
                        'type' => 'audio',
                        'originalContentUrl' => $attachment->getUrl(),
                        'duration' => $attachment->getExtras('duration'),
                    ];
                } elseif ($attachment instanceof Location) {
                    $parameters['messages'][] = [
                        'type' => 'location',
                        'title' => $attachment->getExtras('title'),
                        'address' => $attachment->getExtras('address'),
                        'latitude' => $attachment->getLatitude(),
                        'longitude' => $attachment->getLongitude(),
                    ];
                }
            }
        } else {
            $parameters['messages'][] = $message;
        }

        return $parameters;
    }

    /**
     * @param array $payload
     * @return Response
     */
    public function sendPayload($payload)
    {
        info('line payload' . $payload); // we added this line
        return $this->http->post($this->getApiUrl('/message/reply'), [], $payload, [
            'Authorization: Bearer '.$this->config->get('channel_access_token'),
            'Content-Type: application/json',
        ], true);
    }

    /**
     * @return bool
     */
    public function isConfigured()
    {
        return ! empty($this->config->get('channel_access_token'))
            && ! empty($this->config->get('channel_secret'));
    }

    /**
     * @return bool
     */
    protected function validateSignature()
    {
        return hash_equals(
            base64_encode(
                hash_hmac('sha256', $this->content, $this->config->get('channel_secret'), true)
            ),
            $this->signature
        );
    }

    /**
     * Generate the LINE messaging API URL for the given endpoint.
     *
     * @param string $endpoint
     * @return string
     */
    protected function getApiUrl($endpoint)
    {
        return static::API_URL_BASE.$endpoint;
    }
    
    /**
     * @param string|Question $message
     * @param Message $matchingMessage
     * @param array $additionalParameters
     * @return $this
     */
    public function reply($message, $matchingMessage, $additionalParameters = [])
    {   
        info('message outgoing: ' . $message); // we added this line
        return $this->sendPayload($this->buildServicePayload($message, $matchingMessage, $additionalParameters));
    }

    /**
     * Return the driver name.
     *
     * @return string
     */
    public function getName()
    {
        return 'LineDriver';
    }
}
